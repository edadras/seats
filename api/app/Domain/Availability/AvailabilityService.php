<?php

namespace App\Domain\Availability;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Availability is computed, never stored (ADR-0002).
 *
 * One SQL statement per event: start from the seats placed in the event's published map version,
 * left-join the three things that can take a seat away, and let precedence decide.
 *
 * Precedence, highest first: allocated > blocked > held > available. Allocated beats blocked so an
 * organiser who blocks a seat after it was sold does not make a sold seat look free again.
 */
class AvailabilityService
{
    /**
     * @return list<array{seat_id: string, state: string, amount: int|null, zone_key: string|null}>
     */
    public function forEvent(Event $event): array
    {
        return array_map(fn (array $seat) => [
            'seat_id' => $seat['seat_id'],
            'state' => $seat['state'],
            'amount' => $seat['amount'],
            'zone_key' => $seat['zone_key'],
        ], $this->placedSeats($event));
    }

    /**
     * The same seats, with where they sit and what they are called.
     *
     * One definition of "available" for the picker and for whoever is choosing seats on a buyer's
     * behalf: the state, the price and the precedence are computed once, here, and the extra
     * columns are the ones you need to answer "four together" — which row, and where along it.
     *
     * @return list<array{seat_id: string, state: string, amount: int|null, zone_key: string|null,
     *                    section_id: string, section_key: string, section_name: string,
     *                    row_id: string, row_name: string, label: string, accessible: bool,
     *                    x: float, y: float, floor_key: string|null}>
     */
    public function placedSeats(Event $event): array
    {
        if (! $event->seat_map_version_id) {
            return [];
        }

        $rows = DB::select($this->sql(), [
            'version_id' => $event->seat_map_version_id,
            'event_id' => $event->id,
            'tenant_id' => $event->tenant_id,
        ]);

        return array_map(fn ($row) => [
            'seat_id' => $row->seat_id,
            'state' => $row->state,
            'amount' => $row->amount === null ? null : (int) $row->amount,
            'zone_key' => $row->zone_key,
            'section_id' => $row->section_id,
            'section_key' => $row->section_key,
            'section_name' => $row->section_name,
            'row_id' => $row->row_id,
            'row_name' => $row->row_name,
            'label' => $row->label,
            'accessible' => (bool) $row->accessible,
            'x' => (float) $row->x,
            'y' => (float) $row->y,
            'floor_key' => $row->floor_key,
        ], $rows);
    }

    /**
     * Capacity objects, reported as a remaining count rather than a state.
     *
     * A general admission area is never simply "held" or "free" — it is partly taken, and what a
     * buyer needs to know is how many places are left. Blocking one, or reducing the house for a
     * night, shows up as zero remaining.
     *
     * @return list<array{capacity_object_id: string, label: string, kind: string, capacity_type: string,
     *                    places: int, taken: int, held: int, allocated: int, remaining: int,
     *                    amount: int|null, zone_key: string|null, blocked: bool}>
     */
    public function capacityForEvent(Event $event): array
    {
        if (! $event->seat_map_version_id) {
            return [];
        }

        $rows = DB::select($this->capacitySql(), [
            'version_id' => $event->seat_map_version_id,
            'event_id' => $event->id,
            'tenant_id' => $event->tenant_id,
        ]);

        return array_map(function ($row) {
            $places = (int) ($row->places ?? 0);
            $taken = (int) $row->taken;
            $blocked = (bool) $row->blocked;

            return [
                'capacity_object_id' => $row->capacity_object_id,
                'label' => $row->label,
                'kind' => $row->kind,
                'capacity_type' => $row->capacity_type,
                'places' => $places,
                'taken' => $taken,
                'held' => (int) $row->held,
                'allocated' => (int) $row->allocated,
                'remaining' => $blocked ? 0 : max(0, $places - $taken),
                'amount' => $row->amount === null ? null : (int) $row->amount,
                'zone_key' => $row->zone_key,
                'blocked' => $blocked,
            ];
        }, $rows);
    }

    /**
     * Counters for the dashboard and the door, from the same definitions as the seat and capacity
     * lists — so the numbers on the two screens can never disagree.
     */
    public function summaryForEvent(Event $event): array
    {
        $summary = ['seats_total' => 0, 'available' => 0, 'held' => 0, 'allocated' => 0, 'blocked' => 0];

        foreach ($this->forEvent($event) as $seat) {
            $summary['seats_total']++;
            $summary[$seat['state']]++;
        }

        // Standing room counts towards the house too, as places rather than as seats.
        foreach ($this->capacityForEvent($event) as $area) {
            $summary['seats_total'] += $area['places'];

            if ($area['blocked']) {
                $summary['blocked'] += $area['places'];

                continue;
            }

            $summary['held'] += $area['held'];
            $summary['allocated'] += $area['allocated'];
            $summary['available'] += $area['remaining'];
        }

        return $summary;
    }

    /**
     * Price for a single seat: an explicit per-seat override wins, then the seat's zone, then the
     * zone named on the placement. A seat with no price at all is not sellable.
     */
    public function priceFor(Event $event, string $seatId): ?array
    {
        foreach ($this->forEvent($event) as $seat) {
            if ($seat['seat_id'] === $seatId) {
                return $seat['amount'] === null ? null : ['amount' => $seat['amount'], 'zone_key' => $seat['zone_key']];
            }
        }

        return null;
    }

    /**
     * Taken is holds plus allocations. Held and sold are reported separately as well, because an
     * organiser watching a fast on-sale needs to tell "in carts" from "paid for".
     */
    private function capacitySql(): string
    {
        return <<<'SQL'
            SELECT
                cp.capacity_object_id,
                c.label,
                c.kind,
                c.capacity_type,
                COALESCE(o.places, c.places) AS places,
                COALESCE(o.blocked, false) AS blocked,
                COALESCE(o.amount, zone_override.amount, zone_placement.amount) AS amount,
                COALESCE(o.zone_key, (cp.geometry->>'zone_key')) AS zone_key,
                COALESCE(h.held, 0) + COALESCE(a.allocated, 0) AS taken,
                COALESCE(h.held, 0) AS held,
                COALESCE(a.allocated, 0) AS allocated
            FROM capacity_placements cp
            JOIN capacity_objects c ON c.id = cp.capacity_object_id
            LEFT JOIN event_capacity_overrides o
                ON o.event_id = :event_id AND o.capacity_object_id = cp.capacity_object_id
            LEFT JOIN (
                SELECT hi.capacity_object_id, SUM(hi.quantity) AS held
                FROM hold_items hi
                JOIN holds hd ON hd.id = hi.hold_id
                WHERE hi.event_id = :event_id
                  AND hi.released_at IS NULL
                  AND hi.capacity_object_id IS NOT NULL
                  AND hd.status = 'active'
                  AND hd.expires_at > NOW()
                GROUP BY hi.capacity_object_id
            ) h ON h.capacity_object_id = cp.capacity_object_id
            LEFT JOIN (
                SELECT al.capacity_object_id, SUM(al.quantity) AS allocated
                FROM allocations al
                WHERE al.event_id = :event_id
                  AND al.status = 'active'
                  AND al.capacity_object_id IS NOT NULL
                GROUP BY al.capacity_object_id
            ) a ON a.capacity_object_id = cp.capacity_object_id
            LEFT JOIN event_price_zones zone_override
                ON zone_override.event_id = :event_id AND zone_override.key = o.zone_key
            LEFT JOIN event_price_zones zone_placement
                ON zone_placement.event_id = :event_id AND zone_placement.key = (cp.geometry->>'zone_key')
            WHERE cp.seat_map_version_id = :version_id
              AND cp.tenant_id = :tenant_id
            ORDER BY c.label
        SQL;
    }

    private function sql(): string
    {
        return <<<'SQL'
            SELECT
                sp.seat_id,
                CASE
                    WHEN a.id IS NOT NULL THEN 'allocated'
                    WHEN o.blocked THEN 'blocked'
                    WHEN h.id IS NOT NULL THEN 'held'
                    ELSE 'available'
                END AS state,
                COALESCE(o.amount, zone_override.amount, zone_placement.amount) AS amount,
                COALESCE(o.zone_key, sp.zone_key) AS zone_key,
                sp.x, sp.y, sp.floor_key,
                s.label, s.accessible,
                sec.id AS section_id, sec.key AS section_key, sec.name AS section_name,
                r.id AS row_id, r.name AS row_name
            FROM seat_placements sp
            JOIN seats s ON s.id = sp.seat_id
            JOIN sections sec ON sec.id = s.section_id
            JOIN seat_rows r ON r.id = s.seat_row_id
            LEFT JOIN event_seat_overrides o
                ON o.event_id = :event_id AND o.seat_id = sp.seat_id
            LEFT JOIN allocations a
                ON a.event_id = :event_id AND a.seat_id = sp.seat_id AND a.status = 'active'
            LEFT JOIN (
                SELECT hi.seat_id, hi.id
                FROM hold_items hi
                JOIN holds hd ON hd.id = hi.hold_id
                WHERE hi.event_id = :event_id
                  AND hi.released_at IS NULL
                  AND hd.status = 'active'
                  AND hd.expires_at > NOW()
            ) h ON h.seat_id = sp.seat_id
            LEFT JOIN event_price_zones zone_override
                ON zone_override.event_id = :event_id AND zone_override.key = o.zone_key
            LEFT JOIN event_price_zones zone_placement
                ON zone_placement.event_id = :event_id AND zone_placement.key = sp.zone_key
            WHERE sp.seat_map_version_id = :version_id
              AND sp.tenant_id = :tenant_id
            ORDER BY sp.seat_id
        SQL;
    }
}
