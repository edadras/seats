<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeatOverride;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Prices for individual seats.
 *
 * A price zone covers the ordinary case: everything in the balcony costs the same. It does not
 * cover the real one. A section called VIP is a name, not a price — the two seats behind the
 * pillar are not worth what the front row is, the four in the middle of row A are worth more, and
 * the house seats are worth nothing because they are never sold. So a seat may carry its own
 * amount, and the zone is what it falls back to.
 *
 * The resolution order is decided in one place, the availability SQL: seat override, then the
 * zone the override names, then the zone the chart put the seat in. This controller only writes
 * the first of those. Nothing here recomputes a price — a screen that did its own arithmetic
 * would be a second opinion about what a seat costs, and buyers only ever see one of them.
 *
 * Unlike `PUT /events/{id}/pricing`, this endpoint is not wholesale: it changes the seats it is
 * given and leaves every other seat alone. A hall has twenty thousand seats and a repricing
 * usually touches eight; sending all twenty thousand to move eight would be a way to lose the
 * other 19,992 to a dropped connection.
 */
class SeatPriceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every seat in the event's chart, with what it currently costs and why.
     *
     * Grouped by section and row, because that is how a hall is spoken about: "row H, seats 12 to
     * 18", never "these eighteen uuids".
     */
    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        if (! $event->seat_map_version_id) {
            throw ApiException::conflict('map_not_published', 'This event has no published seat map.');
        }

        $rows = DB::select($this->sql(), [
            'event_id' => $event->id,
            'version_id' => $event->seat_map_version_id,
            'tenant_id' => $this->tenantContext->id(),
        ]);

        $sections = [];

        foreach ($rows as $row) {
            $sections[$row->section_key] ??= [
                'key' => $row->section_key,
                'name' => $row->section_name,
                'color' => $row->section_color,
                'rows' => [],
            ];

            $sections[$row->section_key]['rows'][$row->row_key] ??= [
                'key' => $row->row_key,
                'name' => $row->row_name,
                'seats' => [],
            ];

            $sections[$row->section_key]['rows'][$row->row_key]['seats'][] = [
                'id' => $row->seat_id,
                'label' => $row->label,
                'accessible' => (bool) $row->accessible,
                'zone_key' => $row->override_zone ?? $row->placement_zone,
                'chart_zone_key' => $row->placement_zone,
                // What a buyer would be charged right now, however that came about.
                'amount' => null === $row->amount ? null : (int) $row->amount,
                // Set only when this seat carries its own price rather than its zone's.
                'own_amount' => null === $row->override_amount ? null : (int) $row->override_amount,
                'blocked' => (bool) $row->blocked,
                // Who a blocked seat is being kept for. A blocked seat with a label is a house
                // seat: off public sale, and sellable at the counter on the night.
                'held_for' => $row->held_for,
                'note' => $row->note,
                // A sold seat keeps the price it was sold at; changing it changes what the *next*
                // buyer pays, and the screen says so rather than letting somebody assume a refund.
                'sold' => (bool) $row->sold,
            ];
        }

        return response()->json([
            'currency' => $event->currency,
            'zones' => $event->priceZones->map(fn ($zone) => [
                'key' => $zone->key,
                'name' => $zone->name,
                'amount' => $zone->amount,
                'color' => $zone->color,
            ])->values(),
            'sections' => array_values(array_map(
                fn (array $section) => array_merge($section, ['rows' => array_values($section['rows'])]),
                $sections
            )),
        ]);
    }

    /**
     * Change the seats named, and only those.
     *
     * A seat with no price of its own, in no zone of its own, not blocked and with nothing noted
     * is a seat with nothing to say — its override row is deleted rather than kept as a row of
     * nulls, so "does this seat differ from its zone" stays answerable by looking.
     */
    public function update(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'seats' => ['required', 'array', 'min:1', 'max:5000'],
            'seats.*.seat_id' => ['required', 'uuid'],
            'seats.*.amount' => ['present', 'nullable', 'integer', 'min:0'],
            'seats.*.zone_key' => ['present', 'nullable', 'string', 'max:60'],
            'seats.*.blocked' => ['sometimes', 'boolean'],
            'seats.*.held_for' => ['sometimes', 'nullable', 'string', 'max:60'],
            'seats.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $seatIds = array_column($data['seats'], 'seat_id');

        // Seats are checked against this event's own map before anything is written, so a stray
        // id cannot half-apply a change — and cannot be used to probe another account's seats.
        $known = DB::table('seat_placements')
            ->where('seat_map_version_id', $event->seat_map_version_id)
            ->where('tenant_id', $this->tenantContext->id())
            ->whereIn('seat_id', $seatIds)
            ->pluck('seat_id')
            ->all();

        $unknown = array_values(array_unique(array_diff($seatIds, $known)));

        if ($unknown !== []) {
            throw ApiException::unprocessable(
                'unknown_seats',
                'Some of those seats are not in this event’s seat map.',
                ['unknown_seat_ids' => $unknown],
            );
        }

        $zoneKeys = array_values(array_filter(array_column($data['seats'], 'zone_key')));

        if ($zoneKeys !== []) {
            $priced = $event->priceZones()->whereIn('key', $zoneKeys)->pluck('key')->all();
            $missing = array_values(array_unique(array_diff($zoneKeys, $priced)));

            if ($missing !== []) {
                throw ApiException::unprocessable(
                    'unknown_zone',
                    'A seat was put in a price zone this event does not have.',
                    ['unknown_zone_keys' => $missing],
                );
            }
        }

        $changed = 0;
        $cleared = 0;

        DB::transaction(function () use ($event, $data, &$changed, &$cleared) {
            foreach ($data['seats'] as $seat) {
                $amount = $seat['amount'] ?? null;
                $zoneKey = $seat['zone_key'] ?? null;
                $blocked = (bool) ($seat['blocked'] ?? false);
                $note = $seat['note'] ?? null;
                // A label only means anything on a blocked seat, which is what the database's own
                // CHECK says too — said here as well so the answer is a saved row and not an error.
                $heldFor = $blocked ? ($seat['held_for'] ?? null) : null;

                if (null === $amount && null === $zoneKey && ! $blocked && ! $note && ! $heldFor) {
                    $cleared += EventSeatOverride::where('event_id', $event->id)
                        ->where('seat_id', $seat['seat_id'])
                        ->delete();

                    continue;
                }

                EventSeatOverride::updateOrCreate(
                    ['event_id' => $event->id, 'seat_id' => $seat['seat_id']],
                    [
                        'amount' => $amount,
                        'zone_key' => $zoneKey,
                        'blocked' => $blocked,
                        'held_for' => $heldFor,
                        'note' => $note,
                    ],
                );

                $changed++;
            }

            // Every open browser is holding a picture of this chart. Bumping the version is what
            // tells them the picture is stale on their next poll.
            $event->bumpAvailabilityVersion();
        });

        $this->audit->record('event.seat_prices_updated', $event, [
            'seats_changed' => $changed,
            'seats_cleared' => $cleared,
        ]);

        return response()->json(['changed' => $changed, 'cleared' => $cleared]);
    }

    private function sql(): string
    {
        return <<<'SQL'
            SELECT
                s.id AS seat_id,
                s.label,
                s.accessible,
                sec.key AS section_key,
                sec.name AS section_name,
                sec.color AS section_color,
                r.key AS row_key,
                r.name AS row_name,
                sp.zone_key AS placement_zone,
                o.amount AS override_amount,
                o.zone_key AS override_zone,
                COALESCE(o.blocked, false) AS blocked,
                o.held_for,
                o.note,
                COALESCE(o.amount, zone_override.amount, zone_placement.amount) AS amount,
                (a.id IS NOT NULL) AS sold
            FROM seat_placements sp
            JOIN seats s ON s.id = sp.seat_id
            JOIN sections sec ON sec.id = s.section_id
            JOIN seat_rows r ON r.id = s.seat_row_id
            LEFT JOIN event_seat_overrides o
                ON o.event_id = :event_id AND o.seat_id = sp.seat_id
            LEFT JOIN event_price_zones zone_override
                ON zone_override.event_id = :event_id AND zone_override.key = o.zone_key
            LEFT JOIN event_price_zones zone_placement
                ON zone_placement.event_id = :event_id AND zone_placement.key = sp.zone_key
            LEFT JOIN allocations a
                ON a.event_id = :event_id AND a.seat_id = sp.seat_id AND a.status = 'active'
            WHERE sp.seat_map_version_id = :version_id
              AND sp.tenant_id = :tenant_id
            -- Seats come back the way a row is walked, not the way a string sorts: 2 before 10,
            -- and "1, 10, 11, 12, 2" is how somebody reprices the wrong four chairs.
            ORDER BY sec.sort_order, sec.name, r.sort_order, r.name,
                     NULLIF(regexp_replace(s.label, '\D', '', 'g'), '')::bigint NULLS LAST,
                     s.label
        SQL;
    }
}
