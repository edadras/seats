<?php

namespace App\Domain\Access;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Seat;

/**
 * Who may buy a wheelchair space, when, and with what beside it.
 *
 * Two rules, both of them things venues currently do by hand and therefore sometimes do not do.
 *
 * **Held back until the hour arrives.** Accessible seats are usually kept off general sale so the
 * people who need them are not racing everybody else for them. That is a deadline, and deadlines
 * here are derived: `heldFromPublic()` is asked on every read, so the seats go on sale because the
 * time came, not because something ran. The counter can sell them throughout — a house that holds
 * seats back and cannot then sell them to the person on the telephone has achieved nothing.
 *
 * **A companion seat is not sold alone.** The chair beside a wheelchair space exists so that
 * somebody can sit next to the person using it. Sold on its own it is a stranger in that chair and
 * a booking that no longer works, which is why venues block it by hand today.
 */
class AccessibleSeats
{
    /** Are this event's accessible seats off general sale at this moment? */
    public function heldFromPublic(Event $event): bool
    {
        return match ($event->accessible_sale ?? 'always') {
            'counter' => true,
            'until' => ! $event->starts_at || now()->lessThan(
                $event->starts_at->copy()->subHours(max(0, (int) $event->accessible_release_hours))
            ),
            default => false,
        };
    }

    /** When they go on general sale, for a screen that has to say so. */
    public function releasesAt(Event $event): ?\Illuminate\Support\Carbon
    {
        if ('until' !== ($event->accessible_sale ?? 'always') || ! $event->starts_at) {
            return null;
        }

        return $event->starts_at->copy()->subHours(max(0, (int) $event->accessible_release_hours));
    }

    /**
     * A companion seat has to arrive with the space it belongs to.
     *
     * "Belongs to" means the same row: a companion chair is beside a wheelchair space by
     * construction, and a row is the smallest thing the chart guarantees they share. Checked over
     * the whole basket rather than one seat at a time, because somebody choosing the space and the
     * chair beside it does so in two clicks and must not be refused between them.
     *
     * @param  list<Seat>  $seats
     */
    public function refuseLonelyCompanions(array $seats): void
    {
        $rowsWithSpace = [];
        $lonely = [];

        foreach ($seats as $seat) {
            if ($seat->accessible) {
                $rowsWithSpace[$seat->seat_row_id] = true;
            }
        }

        foreach ($seats as $seat) {
            if ($seat->companion && ! isset($rowsWithSpace[$seat->seat_row_id])) {
                $lonely[] = $seat->label;
            }
        }

        if ([] !== $lonely) {
            throw ApiException::conflict(
                'companion_needs_accessible',
                'That seat is kept for whoever comes with a wheelchair user, so it is sold with the space beside it.',
                ['seats' => $lonely],
            );
        }
    }
}
