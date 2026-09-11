<?php

namespace App\Domain\Rehearsals;

/**
 * "And not a night being rehearsed", written once.
 *
 * Every account-wide figure on this platform has to ask the same question, and the whole value of
 * keeping the flag on the event is that there is exactly one question to ask. Spelling it out at
 * each of the dozen call sites would be a dozen chances to spell it differently.
 *
 * A `whereNotExists` rather than a join, because the queries this is added to are already joining
 * what they need and a second join to `events` would change their grain. It is also the safe way
 * round: an order whose event row has been soft-deleted still matches the subquery and stays out of
 * the figures, where a `whereHas` would quietly let it back in.
 */
final class Live
{
    /**
     * Narrow a query to nights that are actually selling.
     *
     * @param  string  $eventColumn  the qualified column holding the event id — `o.event_id`,
     *                               `external_orders.event_id`, `allocations.event_id`
     */
    public static function only($query, string $eventColumn = 'event_id'): void
    {
        $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')
            ->from('events')
            ->whereColumn('events.id', $eventColumn)
            ->where('events.is_rehearsal', true));
    }
}
