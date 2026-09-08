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
        ], $rows);
    }

    /** Counters for the dashboard and the door, from the same definition as the seat list. */
    public function summaryForEvent(Event $event): array
    {
        $summary = ['seats_total' => 0, 'available' => 0, 'held' => 0, 'allocated' => 0, 'blocked' => 0];

        foreach ($this->forEvent($event) as $seat) {
            $summary['seats_total']++;
            $summary[$seat['state']]++;
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
                COALESCE(o.zone_key, sp.zone_key) AS zone_key
            FROM seat_placements sp
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
