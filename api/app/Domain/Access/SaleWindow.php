<?php

namespace App\Domain\Access;

use App\Models\Event;

/**
 * Whether an event may be bought from right now, and by whom.
 *
 * Three instants rather than a status, because a status has to be flipped by somebody at midnight
 * and nobody is awake at midnight. An event that has never heard of a presale leaves both null and
 * behaves exactly as it did before this file existed: on sale as soon as it is published.
 *
 * The states are ordered, and the order is the whole logic:
 *
 *   `closed`   the event is not published, cancelled, or has no map to sell from
 *   `waiting`  published, but the presale has not opened — nobody, however many codes they hold,
 *              except a code marked `always`, which is the one an organiser gives their own staff
 *   `presale`  only somebody holding a code that opens this event
 *   `open`     everybody
 */
final class SaleWindow
{
    public const CLOSED = 'closed';
    public const WAITING = 'waiting';
    public const PRESALE = 'presale';
    public const OPEN = 'open';

    public static function state(Event $event, ?\DateTimeInterface $at = null): string
    {
        if (! $event->isSellable()) {
            return self::CLOSED;
        }

        $at = $at ?: now();

        // No presale configured is the ordinary case, and it means what it always meant.
        if (null === $event->presale_starts_at && null === $event->on_sale_at) {
            return self::OPEN;
        }

        if ($event->on_sale_at && $event->on_sale_at->lessThanOrEqualTo($at)) {
            return self::OPEN;
        }

        // A presale start with no general-sale date is a presale that has not been given an end;
        // treat it as still a presale rather than quietly opening the doors.
        if ($event->presale_starts_at && $event->presale_starts_at->lessThanOrEqualTo($at)) {
            return self::PRESALE;
        }

        // Either the presale has not opened yet, or there is only a general-sale date and it has
        // not arrived. Both are "not yet", and neither is a presale.
        return self::WAITING;
    }

    /** Whether anybody at all may buy without producing a code. */
    public static function isOpenToAll(Event $event, ?\DateTimeInterface $at = null): bool
    {
        return self::OPEN === self::state($event, $at);
    }

    /** Whether a code could get somebody in right now, if they had the right one. */
    public static function takesCodes(Event $event, ?\DateTimeInterface $at = null): bool
    {
        return in_array(self::state($event, $at), [self::PRESALE, self::WAITING], true);
    }

    /** When the general sale opens, for the sentence that tells somebody to come back. */
    public static function opensAt(Event $event): ?\DateTimeInterface
    {
        return $event->on_sale_at;
    }
}
