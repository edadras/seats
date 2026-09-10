<?php

namespace App\Domain\Inventory;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Hold;
use App\Models\HoldItem;
use App\Models\CapacityObject;
use App\Models\Seat;
use App\Models\TicketType;
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
        private readonly \App\Domain\Events\EntrySlots $slots,
        private readonly \App\Domain\Availability\BestAvailable $bestAvailable,
    ) {}

    /**
     * "Four together, please" — chosen and held in one movement.
     *
     * Choosing has to happen outside the transaction, because it reads the whole house, and the
     * house can change between reading it and locking four chairs in it. So this is a retry rather
     * than a lock: pick, try, and if somebody took one of them in between, pick again from what is
     * left. Three attempts, because a fourth failure is not bad luck — it is an on-sale where every
     * request is fighting for the same seats, and queueing there is better than thrashing.
     *
     * @param  array{max_amount?: ?int, zone_key?: ?string, section_key?: ?string, prefer?: ?string}  $filters
     */
    public function createBestAvailable(
        Event $event,
        int $quantity,
        string $sessionId,
        ?string $apiClientId = null,
        ?string $ip = null,
        array $filters = [],
        array $seatTypes = [],
        ?string $entrySlotId = null,
    ): Hold {
        $lastFailure = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $seats = $this->bestAvailable->findOrFail($event, $quantity, $filters);
            $seatIds = array_column($seats, 'seat_id');

            // One ticket type for the whole group: nobody says "four together, and make the third
            // one a concession" — they say it afterwards, on the seats they can now see.
            $types = [];

            foreach ($seatIds as $seatId) {
                if ($seatTypes['all'] ?? null) {
                    $types[$seatId] = $seatTypes['all'];
                }
            }

            try {
                return $this->create(
                    $event, $seatIds, $sessionId, $apiClientId, $ip, [], $types, [], $entrySlotId
                );
            } catch (ApiException $e) {
                if ('seat_unavailable' !== $e->errorCode()) {
                    throw $e;
                }

                $lastFailure = $e;
            }
        }

        throw $lastFailure;
    }

    /**
     * @param  list<string>  $seatIds     Named seats.
     * @param  array<string, int>  $capacity  Capacity object id => quantity, for standing areas
     *                                        and whole tables.
     * @param  array<string, string>  $seatTypes  Seat id => ticket type id. Seats not named here
     *                                            take the event's default type, which is how a
     *                                            caller that knows nothing about types still sells.
     * @param  array<string, array<string, int>>  $areaTypes  Capacity object id => (ticket type id
     *                                            => quantity), splitting one area's places between
     *                                            types. Where present it replaces `$capacity`'s
     *                                            plain number for that object.
     * @param  ?string  $entrySlotId  Which arrival window, on an event that sells timed entry.
     *                                Required there and refused everywhere else: a slot silently
     *                                dropped would print a ticket with no arrival time on it.
     */
    public function create(
        Event $event,
        array $seatIds,
        string $sessionId,
        ?string $apiClientId = null,
        ?string $ip = null,
        array $capacity = [],
        array $seatTypes = [],
        array $areaTypes = [],
        ?string $entrySlotId = null,
    ): Hold {
        if (! $event->isSellable()) {
            throw ApiException::conflict('event_not_sellable', 'This event is not currently on sale.');
        }

        $seatIds = array_values(array_unique($seatIds));
        $capacity = array_filter($capacity, fn ($quantity) => (int) $quantity > 0);

        // A split area's total is the sum of its parts, so everything downstream — the limit
        // check, the availability check, the advisory lock — sees one number per object.
        $areaTypes = array_map(
            fn ($split) => array_filter((array) $split, fn ($quantity) => (int) $quantity > 0),
            $areaTypes,
        );
        $areaTypes = array_filter($areaTypes);

        foreach ($areaTypes as $objectId => $split) {
            $capacity[$objectId] = array_sum(array_map('intval', $split));
        }

        $types = $this->resolveTypes($event, $seatIds, $seatTypes, $areaTypes);

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

        // Settled before anything is locked, so a buyer whose window filled while they were
        // choosing is told about the window rather than watching a booking fail.
        $slot = $this->slots->resolve($event, $entrySlotId, $requested);

        // Deterministic ordering, decided before the transaction opens.
        sort($seatIds);

        try {
            return DB::transaction(function () use (
                $event, $seatIds, $capacity, $sessionId, $apiClientId, $ip, $areaTypes, $types,
                $slot, $requested
            ) {
                /*
                 * The window, before the seats.
                 *
                 * Taken first and always in this order: a request that locks a window and then
                 * seats can never deadlock against one doing the same, and the recheck under the
                 * lock is the answer that actually decides — the one above it was advice.
                 */
                if ($slot && null !== $slot->capacity) {
                    $this->slots->lock($event, $slot);

                    if ($this->slots->remaining($event, $slot) < $requested) {
                        throw ApiException::conflict('entry_slot_full', 'That arrival time is full.');
                    }
                }

                $seats = $seatIds === [] ? collect() : $this->lockSeats($event, $seatIds);

                if ($seatIds !== []) {
                    $this->reclaimExpiredItems($event, $seatIds);
                }

                $prices = $seatIds === [] ? [] : $this->priceSeats($event, $seatIds);
                $unavailable = $seatIds === [] ? [] : $this->findUnavailable($event, $seatIds, $prices);

                if ($unavailable !== []) {
                    throw ApiException::seatsUnavailable($unavailable);
                }

                $capacityLines = $this->reserveCapacity($event, $capacity, $areaTypes, $types);

                $hold = $this->insertHold(
                    $event, $seats, $prices, $sessionId, $apiClientId, $ip, $capacityLines, $types,
                    $slot
                );

                $event->bumpAvailabilityVersion();

                return $hold;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Lost the final race to another transaction. This is a legitimate outcome, not a bug:
            // report which seats went, so the widget can grey them out and let the buyer re-pick.
            throw ApiException::seatsUnavailable($this->stillUnavailable($event, $seatIds));
        }
    }

    /**
     * Which ticket type every requested place is being bought at.
     *
     * Everything about types is settled here, before a row is locked: an unknown type, a hidden
     * one, one belonging to another event, or a count outside what the organiser allows. Doing it
     * before the transaction means a mistyped request fails without ever holding a seat, and doing
     * it in one place means the seat path and the standing path cannot disagree about what is
     * allowed.
     *
     * An event with no types at all returns an empty map and nothing downstream changes.
     *
     * @return array{by_id: array<string, TicketType>, seats: array<string, ?TicketType>, default: ?TicketType}
     */
    private function resolveTypes(Event $event, array $seatIds, array $seatTypes, array $areaTypes): array
    {
        $all = TicketType::where('event_id', $event->id)->orderBy('position')->get();

        if ($all->isEmpty()) {
            // No concessions on this event. Nothing is named, nothing is checked, nothing is
            // written — the same rows this made before types existed.
            foreach (array_merge(array_values($seatTypes), ...array_map('array_keys', array_values($areaTypes))) as $named) {
                if ($named) {
                    throw ApiException::unprocessable(
                        'unknown_ticket_type',
                        'This event does not sell ticket types.',
                    );
                }
            }

            return ['by_id' => [], 'seats' => [], 'default' => null];
        }

        $byId = $all->keyBy('id');
        $default = $all->firstWhere('is_default', true) ?? $all->first();

        $take = function (?string $id) use ($byId): TicketType {
            $type = $id ? ($byId[$id] ?? null) : null;

            if (! $type) {
                throw ApiException::unprocessable(
                    'unknown_ticket_type',
                    'One of the ticket types requested is not sold for this event.',
                    ['ticket_type_id' => $id],
                );
            }

            if (! $type->isSellable()) {
                throw ApiException::conflict(
                    'ticket_type_not_on_sale',
                    'One of the ticket types requested is not on sale.',
                );
            }

            return $type;
        };

        $seats = [];
        $counts = [];

        foreach ($seatIds as $seatId) {
            $type = isset($seatTypes[$seatId]) ? $take((string) $seatTypes[$seatId]) : $default;
            $seats[$seatId] = $type;
            $counts[$type->id] = ($counts[$type->id] ?? 0) + 1;
        }

        foreach ($areaTypes as $split) {
            foreach ($split as $typeId => $quantity) {
                $type = $take((string) $typeId);
                $counts[$type->id] = ($counts[$type->id] ?? 0) + (int) $quantity;
            }
        }

        foreach ($counts as $typeId => $count) {
            $type = $byId[$typeId];

            // "At least two" and "at most four" are the organiser's rules about their own house.
            // Refused here rather than at the checkout, so nobody holds seats they cannot buy.
            if ($type->min_per_order && $count < $type->min_per_order) {
                throw ApiException::unprocessable(
                    'ticket_type_min',
                    sprintf('At least %d "%s" tickets must be bought together.', $type->min_per_order, $type->name),
                    ['ticket_type_id' => $type->id, 'min_per_order' => $type->min_per_order],
                    ['count' => $type->min_per_order, 'type' => $type->name],
                );
            }

            if ($type->max_per_order && $count > $type->max_per_order) {
                throw ApiException::unprocessable(
                    'ticket_type_max',
                    sprintf('At most %d "%s" tickets may be bought at once.', $type->max_per_order, $type->name),
                    ['ticket_type_id' => $type->id, 'max_per_order' => $type->max_per_order],
                    ['count' => $type->max_per_order, 'type' => $type->name],
                );
            }
        }

        return ['by_id' => $byId->all(), 'seats' => $seats, 'default' => $default];
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
                throw ApiException::conflict(
                    'extend_limit_reached',
                    sprintf('This hold has already been extended %d times.', $fresh->extends_used),
                    [],
                    ['count' => $fresh->extends_used],
                );
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
    private function reserveCapacity(
        Event $event,
        array $requested,
        array $areaTypes = [],
        array $types = [],
    ): array {
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

            // One line per ticket type, because two children and one adult standing in the same
            // area are three places at two prices — and a single line could only carry one.
            $split = $areaTypes[$id] ?? null;

            if ($split && ! $object->isSoldWhole()) {
                foreach ($split as $typeId => $part) {
                    $lines[] = [
                        'object' => $object,
                        'quantity' => (int) $part,
                        'amount' => $this->typedAmount($types, (string) $typeId, (int) $state['amount']),
                        'zone_key' => $state['zone_key'],
                        'type' => $types['by_id'][$typeId] ?? null,
                    ];
                }

                continue;
            }

            $lines[] = [
                'object' => $object,
                'quantity' => $quantity,
                'amount' => $this->typedAmount(
                    $types,
                    $split ? (string) array_key_first($split) : null,
                    (int) $state['amount'],
                ),
                'zone_key' => $state['zone_key'],
                'type' => $split
                    ? ($types['by_id'][array_key_first($split)] ?? null)
                    : ($types['default'] ?? null),
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

    /** What one place costs at a given type, from the seat's or area's own price. */
    private function typedAmount(array $types, ?string $typeId, int $base): int
    {
        $type = $typeId
            ? ($types['by_id'][$typeId] ?? null)
            : ($types['default'] ?? null);

        return $type ? $type->priceFrom($base) : $base;
    }

    private function insertHold(
        Event $event,
        $seats,
        array $prices,
        string $sessionId,
        ?string $apiClientId,
        ?string $ip,
        array $capacityLines = [],
        array $types = [],
        ?\App\Models\EntrySlot $slot = null,
    ): Hold {
        $total = 0;
        $seatAmounts = [];

        foreach ($seats as $seat) {
            $type = $types['seats'][$seat->id] ?? null;
            $seatAmounts[$seat->id] = $type
                ? $type->priceFrom((int) $prices[$seat->id]['amount'])
                : (int) $prices[$seat->id]['amount'];

            $total += $seatAmounts[$seat->id];
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
            // On the hold rather than on each item: a booking is one arrival, and a family that
            // buys four places comes through the door together.
            'entry_slot_id' => $slot?->id,
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
                'ticket_type_id' => ($types['seats'][$seat->id] ?? null)?->id,
                'quantity' => 1,
                'amount' => $seatAmounts[$seat->id],
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
                'ticket_type_id' => ($line['type'] ?? null)?->id,
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

        $hold->loadMissing(['items.capacityObject', 'items.ticketType', 'entrySlot']);

        $seats = $hold->items->reject(fn (HoldItem $item) => $item->isCapacity())
            ->map(fn (HoldItem $item) => [
                'seat_id' => $item->seat_id,
                'section' => $item->seat?->section?->name ?? '',
                'row' => $item->seat?->row?->name ?? '',
                'label' => $item->seat?->label ?? '',
                'amount' => $item->amount,
                'zone_key' => $item->zone_key,
                'ticket_type_id' => $item->ticket_type_id,
                // The name travels with the price. A checkout that had to look it up again could
                // print a type that was renamed between the hold and the payment.
                'ticket_type' => $item->ticketType?->name,
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
                'ticket_type_id' => $item->ticket_type_id,
                'ticket_type' => $item->ticketType?->name,
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
            // Signed with the rest of the cart: the arrival time is part of what was bought, and
            // a checkout page that had to look it up again could show a window that has since
            // been renamed or withdrawn.
            'entry' => $hold->entrySlot ? [
                'id' => $hold->entrySlot->id,
                'label' => $hold->entrySlot->label,
                'starts_at' => $hold->entrySlot->starts_at?->toIso8601String(),
                'ends_at' => $hold->entrySlot->ends_at?->toIso8601String(),
            ] : null,
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
            throw ApiException::conflict(
                'too_many_active_holds',
                sprintf('This session already holds seats in %d carts. Complete or release one first.', $active),
                [],
                ['count' => $active],
            );
        }
    }
}
