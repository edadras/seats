<?php

namespace App\Domain\Events;

use App\Domain\Availability\AvailabilityService;
use App\Models\Event;

/**
 * How an event is selling, and how many of the people who bought have arrived.
 *
 * A service rather than a controller method because two very different callers need the same
 * numbers — the panel, authorised by a person's permissions, and the door scanner, authorised by a
 * device token. It used to live on EventController, which meant the check-in controller resolved a
 * *controller* out of the container to call it. Two authorisation stories meeting inside one class
 * is exactly where a permission check gets skipped by accident.
 */
class EventStats
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param bool $withMoney Include what the event has taken.
     *
     * Money is separated from attendance because the permissions are, and a screen that returned
     * both would quietly hand the takings to a door volunteer who only needed the head count. It
     * is a parameter rather than two methods so a caller cannot forget there is a second question.
     */
    public function for(Event $event, bool $withMoney = true): array
    {
        $summary = $this->availability->summaryForEvent($event);

        $checkedIn = $event->tickets()->where('status', 'used')->count();
        $issued = $event->tickets()->whereIn('status', ['issued', 'used'])->count();

        $stats = $summary + [
            'tickets_issued' => $issued,
            'checked_in' => $checkedIn,
            'checkin_rate' => $issued > 0 ? round($checkedIn / $issued, 4) : 0.0,
        ];

        if (! $withMoney) {
            return $stats;
        }

        return $stats + [
            // Indicative only: coupons and tax live in the shop, not here (ADR-0001).
            'gross_amount' => (int) $event->allocations()->where('status', 'active')->sum('amount'),
            'currency' => $event->currency,
        ];
    }
}
