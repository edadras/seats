<?php

namespace App\Domain\Inventory;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Hold;
use App\Models\HoldItem;
use App\Models\CapacityObject;
use App\Models\Seat;
use App\Support\Signing\PriceSigner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating, extending and releasing holds.
 *
 * This is the file the "exactly one winner out of 100 concurrent requests" requirement rests on.
 * The defence is layered on purpose:
 *
 *   1. Lock the seat rows `FOR UPDATE`, **in sorted id order** — deterministic ordering means two
 *      overlapping multi-seat requests can never deadlock by grabbing the same pair in opposite
 *      order.
 *   2. Reclaim expired-but-unswept hold items for exactly these seats, so a seat comes back the
 *      instant its TTL passes rather than when a sweeper next runs.
 *   3. Re-check allocations, blocks and live holds.
 *   4. Insert. If a racer slipped between 3 and 4, the partial unique index rejects us and we
 *      translate that into a clean 409 rather than a 500.
 *
 * Step 4 is what actually guarantees correctness; steps 1–3 exist so the common case gives a
 * helpful answer instead of an index violation.
 */
class HoldService
{
    public function __construct(
        private readonly PriceSigner $signer,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  list<string>  $seatIds     Named seats.
     * @param  array<string, int>  $capacity  Capacity object id => quantity, for standing areas
     *                                        and whole tables.
     */
    public function create(
        Event $event,
        array $seatIds,
        string $sessionId,
        ?string $apiClientId = null,
        ?string $ip = null,
        array $capacity = [],
    ): Hold {
        if (! $event->isSellable()) {
            throw ApiException::conflict('event_not_sellable', 'This event is not currently on sale.');
        }

        $seatIds = array_values(array_unique($seatIds));
        $capacity = array_filter($capacity, fn ($quantity) => (int) $quantity > 0);

        if ($seatIds === [] && $capacity === []) {
            throw ApiException::unprocessable('no_seats', 'At least one seat or place must be requested.');
        }

        $maxSeats = min((int) config('seatmap.hold.max_seats'), $event->max_seats_per_order);
        $requested = count($seatIds) + array_sum(array_map('intval', $capacity));

        if ($requested > $maxSeats) {
            throw ApiException::unprocessable('too_many_seats', sprintf(
                'At most %d seats may be held at once.', $maxSeats
            ), ['max_seats' => $maxSeats]);
        }

        $this->assertSessionWithinLimit($event, $sessionId);

        // Deterministic ordering, decided before the transaction opens.
        sort($seatIds);

        try {
            return DB::transaction(function () use ($event, $seatIds, $capacity, $sessionId, $apiClientId, $ip) {
                $seats = $seatIds === [] ? collect() : $this->lockSeats($event, $seatIds);

                if ($seatIds !== []) {
                    $this->reclaimExpiredItems($event, $seatIds);
                }

                $prices = $seatIds === [] ? [] : $this->priceSeats($event, $seatIds);
                $unavailable = $seatIds === [] ? [] : $this->findUnavailable($event, $seatIds, $prices);

                if ($unavailable !== []) {
                    throw ApiException::seatsUnavailable($unavailable);
                }

                $capacityLines = $this->reserveCapacity($event, $capacity);

                $hold = $this->insertHold($event, $seats, $prices, $sessionId, $apiClientId, $ip, $capacityLines);

                $event->bumpAvailabilityVersion();

                return $hold;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Lost the final race to another transaction. This is a legitimate outcome, not a bug:
            // report which seats went, so the widget can grey them out and let the buyer re-pick.
            throw ApiException::seatsUnavailable($this->stillUnavailable($event, $seatIds));
        }
    }

    public function extend(Hold $hold): Hold
    {
        return DB::transaction(function () use ($hold) {
            /** @var Hold $fresh */
            $fresh = Hold::whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isActive()) {
                throw ApiException::conflict('hold_'.$fresh->currentState(), sprintf(
                    'This hold is %s and can no longer be extended.', $fresh->currentState()
                ));
            }

            $event = $fresh->event;

            if ($fresh->extends_used >= $event->max_extends) {
                throw ApiException::conflict('extend_limit_reached', sprintf(
                    'This hold has already been extended %d times.', $fresh->extends_used
                ));
            }

            $fresh->forceFill([
                'expires_at' => now()->addSeconds($event->hold_ttl_seconds),
                'extends_used' => $fresh->extends_used + 1,
            ])->save();

            // The snapshot carries expires_at, so it must be re-signed to stay consistent.
            $fresh->forceFill(['price_snapshot' => $this->buildSnapshot($fresh)])->save();

            return $fresh;
        });
    }

    public function release(Hold $hold, string $reason = 'released'): Hold
    {
        return DB::transaction(function () use ($hold, $reason) {
            /** @var Hold $fresh */
            $fresh = Hold::whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'active') {
                return $fresh; // Already released, expired or converted: releasing again is a no-op.
            }

            HoldItem::where('hold_id', $fresh->id)->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            $fresh->forceFill([
                'status' => $reason === 'expired' ? 'expired' : 'released',
                'released_at' => now(),
            ])->save();

            $fresh->event?->bumpAvailabilityVersion();

            return $fresh;
        });
    }

    /**
     * Locks the seat rows themselves. Seats are immutable reference data, so this is purely a
     * mutex: it gives concurrent requests for the same seats a single point to serialise on,
     * which is not possible by locking hold rows that may not exist yet (the legacy bug,
     * `docs/LEGACY_AUDIT.md` §2.7).
     *
     * @return \Illuminate\Support\Collection<string, Seat>
     */
    private function lockSeats(Event $event, array $seatIds)
    {
        $seats = Seat::whereIn('id', $seatIds)
            ->where('seat_map_id', $event->seat_map_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($seats->count() !== count($seatIds)) {
            $missing = array_values(array_diff($seatIds, $seats->keys()->all()));

            throw ApiException::unprocessable('unknown_seats', 'One or more seats do not belong to this event.', [
                'unknown_seat_ids' => $missing,
            ]);
        }

        return $seats;
    }

    /**
     * Hands back seats whose hold has expired but whose sweeper pass has not run yet.
     *
     * Scoped to the seats being requested, so it is O(seats in this request) and cannot be turned
     * into an expensive operation by an attacker. This is why a seat is re-bookable the moment its
     * TTL elapses instead of on the sweeper's schedule.
     */
    private function reclaimExpiredItems(Event $event, array $seatIds): void
    {
        DB::update(<<<'SQL'
            UPDATE hold_items hi
            SET released_at = NOW(), updated_at = NOW()
            FROM holds h
            WHERE hi.hold_id = h.id
              AND hi.event_id = ?
              AND hi.seat_id = ANY(?)
              AND hi.released_at IS NULL
              AND (h.status <> 'active' OR h.expires_at <= NOW())
        SQL, [$event->id, '{'.implode(',', $seatIds).'}']);
    }

    /**
     * Reserve a quantity of standing room or whole tables.
     *
     * Capacity cannot be guarded by a unique index the way a named seat is — the invariant is a sum
     * against a limit, not one row per chair. So each object is serialised with a transaction-scoped
     * advisory lock keyed on (event, object): concurrent buyers queue behind each other for the
     * same area, the total is recomputed under the lock, and the lock is released when the
     * transaction ends whether it commits or not.
     *
     * Locking per object rather than per event means a rush on the standing pit never blocks
     * someone buying a booth.
     *
     * @param  array<string, int>  $requested  capacity object id => quantity
     * @return list<array{object: CapacityObject, quantity: int, amount: int, zone_key: ?string}>
     */
    private function reserveCapacity(Event $event, array $requested): array
    {
        if ($requested === []) {
            return [];
        }

        $objects = CapacityObject::whereIn('id', array_keys($requested))
            ->where('seat_map_id', $event->seat_map_id)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $unknown = array_values(array_diff(array_keys($requested), $objects->keys()->all()));

        if ($unknown !== []) {
            throw ApiException::unprocessable(
                'unknown_capacity_objects',
                'One or more of the requested areas do not belong to this event.',
                ['unknown_capacity_object_ids' => $unknown],
            );
        }

        $lines = [];
        $unavailable = [];

        // Sorted ids give every transaction the same lock order, so two overlapping multi-area
        // requests queue instead of deadlocking.
        foreach ($objects as $id => $object) {
            $quantity = (int) $requested[$id];

            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$event->id.':'.$id],
            );

            $state = $this->capacityState($event, $object);

            if ($state['blocked']) {
                $unavailable[] = $id;

                continue;
            }

            // A fixed-occupancy object — a booth, a table sold whole — goes in one piece or not
            // at all; asking for part of it is meaningless.
            if ($object->isSoldWhole() && $quantity !== $state['places']) {
                $quantity = $state['places'];
            }

            if ($state['remaining'] < $quantity) {
                $unavailable[] = $id;

                continue;
            }

            $lines[] = [
                'object' => $object,
                'quantity' => $quantity,
                'amount' => $state['amount'],
                'zone_key' => $state['zone_key'],
            ];
        }

        if ($unavailable !== []) {
            throw ApiException::conflict(
                'capacity_unavailable',
                'There are not enough places left in one of the areas you selected.',
                ['unavailable_capacity_object_ids' => $unavailable],
            );
        }

        foreach ($lines as $line) {
            if ($line['amount'] === null) {
                throw ApiException::unprocessable(
                    'area_not_priced',
                    'One or more areas have no price for this event and cannot be sold.',
                    ['unpriced_capacity_object_ids' => [$line['object']->id]],
                );
            }
        }

        return $lines;
    }

    /**
     * Current state of one capacity object for one event, read under the advisory lock.
     *
     * @return array{places: int, remaining: int, blocked: bool, amount: ?int, zone_key: ?string}
     */
    private function capacityState(Event $event, CapacityObject $object): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
                COALESCE(o.places, c.places) AS places,
                COALESCE(o.blocked, false) AS blocked,
                COALESCE(o.amount, zone_override.amount, zone_placement.amount) AS amount,
                COALESCE(o.zone_key, (cp.geometry->>'zone_key')) AS zone_key,
                COALESCE((
                    SELECT SUM(hi.quantity) FROM hold_items hi
                    JOIN holds hd ON hd.id = hi.hold_id
                    WHERE hi.event_id = :event_id AND hi.capacity_object_id = c.id
                      AND hi.released_at IS NULL AND hd.status = 'active' AND hd.expires_at > NOW()
                ), 0) + COALESCE((
                    SELECT SUM(al.quantity) FROM allocations al
                    WHERE al.event_id = :event_id AND al.capacity_object_id = c.id AND al.status = 'active'
                ), 0) AS taken
            FROM capacity_objects c
            JOIN capacity_placements cp
                ON cp.capacity_object_id = c.id AND cp.seat_map_version_id = :version_id
            LEFT JOIN event_capacity_overrides o
                ON o.event_id = :event_id AND o.capacity_object_id = c.id
            LEFT JOIN event_price_zones zone_override
                ON zone_override.event_id = :event_id AND zone_override.key = o.zone_key
            LEFT JOIN event_price_zones zone_placement
                ON zone_placement.event_id = :event_id AND zone_placement.key = (cp.geometry->>'zone_key')
            WHERE c.id = :object_id
        SQL, [
            'event_id' => $event->id,
            'object_id' => $object->id,
            'version_id' => $event->seat_map_version_id,
        ]);

        if (! $row) {
            // Present in the map but not placed in the version this event sells against.
            return ['places' => 0, 'remaining' => 0, 'blocked' => true, 'amount' => null, 'zone_key' => null];
        }

        $places = (int) $row->places;

        return [
            'places' => $places,
            'remaining' => max(0, $places - (int) $row->taken),
            'blocked' => (bool) $row->blocked,
            'amount' => $row->amount === null ? null : (int) $row->amount,
            'zone_key' => $row->zone_key,
        ];
    }

    /** @return array<string, array{amount: int, zone_key: ?string, state: string}> */
    private function priceSeats(Event $event, array $seatIds): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                sp.seat_id,
                COALESCE(o.amount, zone_override.amount, zone_placement.amount) AS amount,
                COALESCE(o.zone_key, sp.zone_key) AS zone_key,
                COALESCE(o.blocked, false) AS blocked,
                (SELECT 1 FROM allocations a
                  WHERE a.event_id = :event_id AND a.seat_id = sp.seat_id AND a.status = 'active'
                  LIMIT 1) AS allocated,
                (SELECT 1 FROM hold_items hi
                  JOIN holds hd ON hd.id = hi.hold_id
                  WHERE hi.event_id = :event_id AND hi.seat_id = sp.seat_id
                    AND hi.released_at IS NULL AND hd.status = 'active' AND hd.expires_at > NOW()
                  LIMIT 1) AS held
            FROM seat_placements sp
            LEFT JOIN event_seat_overrides o ON o.event_id = :event_id AND o.seat_id = sp.seat_id
            LEFT JOIN event_price_zones zone_override
                ON zone_override.event_id = :event_id AND zone_override.key = o.zone_key
            LEFT JOIN event_price_zones zone_placement
                ON zone_placement.event_id = :event_id AND zone_placement.key = sp.zone_key
            WHERE sp.seat_map_version_id = :version_id
              AND sp.seat_id = ANY(:seat_ids)
        SQL, [
            'event_id' => $event->id,
            'version_id' => $event->seat_map_version_id,
            'seat_ids' => '{'.implode(',', $seatIds).'}',
        ]);

        $prices = [];

        foreach ($rows as $row) {
            $prices[$row->seat_id] = [
                'amount' => $row->amount === null ? null : (int) $row->amount,
                'zone_key' => $row->zone_key,
                'blocked' => (bool) $row->blocked,
                'allocated' => (bool) $row->allocated,
                'held' => (bool) $row->held,
            ];
        }

        return $prices;
    }

    /** @return list<string> */
    private function findUnavailable(Event $event, array $seatIds, array $prices): array
    {
        $unavailable = [];
        $unpriced = [];

        foreach ($seatIds as $seatId) {
            $seat = $prices[$seatId] ?? null;

            if ($seat === null) {
                // Placed in no version of this event's map: not orderable at all.
                $unavailable[] = $seatId;
                continue;
            }

            if ($seat['allocated'] || $seat['blocked'] || $seat['held']) {
                $unavailable[] = $seatId;
                continue;
            }

            if ($seat['amount'] === null) {
                $unpriced[] = $seatId;
            }
        }

        if ($unpriced !== []) {
            throw ApiException::unprocessable(
                'seat_not_priced',
                'One or more seats have no price for this event and cannot be sold.',
                ['unpriced_seat_ids' => $unpriced],
            );
        }

        return $unavailable;
    }

    private function insertHold(
        Event $event,
        $seats,
        array $prices,
        string $sessionId,
        ?string $apiClientId,
        ?string $ip,
        array $capacityLines = [],
    ): Hold {
        $total = 0;

        foreach ($seats as $seat) {
            $total += $prices[$seat->id]['amount'];
        }

        foreach ($capacityLines as $line) {
            $total += $line['amount'] * $line['quantity'];
        }

        $hold = Hold::create([
            'event_id' => $event->id,
            'seat_map_version_id' => $event->seat_map_version_id,
            'token' => 'hold_'.Str::random(40),
            'session_id' => $sessionId,
            'source' => $apiClientId ? 'api' : 'embed',
            'api_client_id' => $apiClientId,
            'status' => 'active',
            'expires_at' => now()->addSeconds($event->hold_ttl_seconds),
            'currency' => $event->currency,
            'total_amount' => $total,
            'price_snapshot' => [],
            'ip' => $ip,
        ]);

        $now = now();
        $items = [];

        foreach ($seats as $seat) {
            $items[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantContext->idOrFail(),
                'hold_id' => $hold->id,
                'event_id' => $event->id,
                'seat_id' => $seat->id,
                'capacity_object_id' => null,
                'quantity' => 1,
                'amount' => $prices[$seat->id]['amount'],
                'zone_key' => $prices[$seat->id]['zone_key'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($capacityLines as $line) {
            $items[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantContext->idOrFail(),
                'hold_id' => $hold->id,
                'event_id' => $event->id,
                'seat_id' => null,
                'capacity_object_id' => $line['object']->id,
                'quantity' => $line['quantity'],
                'amount' => $line['amount'] * $line['quantity'],
                'zone_key' => $line['zone_key'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        HoldItem::insert($items);

        $hold->setRelation('items', HoldItem::where('hold_id', $hold->id)->get());
        $hold->forceFill(['price_snapshot' => $this->buildSnapshot($hold)])->save();

        return $hold;
    }

    /**
     * The payload a storefront prices its cart from. Signed so a buyer cannot edit an amount in
     * the browser (threat T3); the server still treats its own stored copy as authoritative.
     */
    public function buildSnapshot(Hold $hold): array
    {
        $hold->loadMissing(['items.seat.section', 'items.seat.row']);

        $hold->loadMissing('items.capacityObject');

        $seats = $hold->items->reject(fn (HoldItem $item) => $item->isCapacity())
            ->map(fn (HoldItem $item) => [
                'seat_id' => $item->seat_id,
                'section' => $item->seat?->section?->name ?? '',
                'row' => $item->seat?->row?->name ?? '',
                'label' => $item->seat?->label ?? '',
                'amount' => $item->amount,
                'zone_key' => $item->zone_key,
            ])->values()->all();

        // Standing room is described by how many places were taken, not by which ones.
        $areas = $hold->items->filter(fn (HoldItem $item) => $item->isCapacity())
            ->map(fn (HoldItem $item) => [
                'capacity_object_id' => $item->capacity_object_id,
                'label' => $item->capacityObject?->label ?? '',
                'kind' => $item->capacityObject?->kind ?? 'area',
                'quantity' => $item->quantity,
                'amount' => $item->amount,
                'zone_key' => $item->zone_key,
            ])->values()->all();

        $payload = [
            'hold_token' => $hold->token,
            'event_id' => $hold->event_id,
            'seat_map_version_id' => $hold->seat_map_version_id,
            'currency' => $hold->currency,
            'total_amount' => $hold->total_amount,
            'expires_at' => $hold->expires_at->toIso8601String(),
            'seats' => $seats,
            'areas' => $areas,
        ];

        return $this->signer->sign($payload) + ['decoded' => $payload];
    }

    /** Best-effort detail for the 409 raised after a lost insert race. */
    private function stillUnavailable(Event $event, array $seatIds): array
    {
        $prices = $this->priceSeats($event, $seatIds);
        $unavailable = [];

        foreach ($seatIds as $seatId) {
            $seat = $prices[$seatId] ?? null;

            if ($seat === null || $seat['allocated'] || $seat['blocked'] || $seat['held']) {
                $unavailable[] = $seatId;
            }
        }

        // If the winner's transaction has not landed in our snapshot yet, still name the seats we
        // asked for — an empty list would tell the widget nothing.
        return $unavailable ?: $seatIds;
    }

    private function assertSessionWithinLimit(Event $event, string $sessionId): void
    {
        $limit = (int) config('seatmap.hold.max_active_per_session');

        $active = Hold::where('event_id', $event->id)
            ->where('session_id', $sessionId)
            ->active()
            ->count();

        if ($active >= $limit) {
            throw ApiException::conflict('too_many_active_holds', sprintf(
                'This session already holds seats in %d carts. Complete or release one first.', $active
            ));
        }
    }
}
