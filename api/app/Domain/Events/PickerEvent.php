<?php

namespace App\Domain\Events;

use App\Models\Event;
use App\Support\Locale\Money;

/**
 * One event, described the way the seat picker needs it.
 *
 * The picker is one implementation used by two very different callers — a buyer on somebody's
 * website, and a clerk at the box office — and the thing that makes that work is that both are
 * handed the *same* event. Two presenters that drifted would be two halls: a price zone missing
 * from one, a ticket type present in the other, and a window that quietly disagrees with the
 * website about what a seat costs.
 *
 * So there is one, here, and both sides read from it.
 */
class PickerEvent
{
    public function __construct(private readonly \App\Domain\Availability\AvailabilityService $availability) {}

    /** @return array<string, mixed> */
    public function forEvent(Event $event): array
    {
        return [
            'public_id' => $event->public_id,
            // Read in whatever language the caller asked for, so the event's own words follow the
            // same rule as everything else on the page they are on.
            'name' => $event->nameFor(),
            'description' => $event->descriptionFor(),
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            // How many decimal places that currency has. Sent because the picker formats money in
            // the browser, and a table of currency exponents copied into JavaScript is a table that
            // goes out of date somewhere nobody is looking.
            'currency_decimals' => Money::exponent((string) $event->currency),
            'status' => $event->status,
            'venue' => [
                'name' => $event->venue?->name,
                'city' => $event->venue?->city,
            ],
            'seat_map_version_id' => $event->seat_map_version_id,
            'hold_ttl_seconds' => $event->hold_ttl_seconds,
            'max_seats_per_order' => $event->max_seats_per_order,
            'areas' => $this->availability->capacityForEvent($event),
            'zones' => $event->priceZones->map(fn ($zone) => [
                'key' => $zone->key,
                'name' => $zone->name,
                'amount' => $zone->amount,
                'color' => $zone->color,
            ])->values(),
            'ticket_types' => TicketTypes::forEvent($event),
        ];
    }
}
