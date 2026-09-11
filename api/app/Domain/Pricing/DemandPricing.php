<?php

namespace App\Domain\Pricing;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\EventDemandStep;
use Illuminate\Support\Facades\DB;

/**
 * What the room costs because of how much of it is gone.
 *
 * Timed tiers already answered "what does this cost this week". They could not answer the question
 * a box office actually asks, which is how the night is going: a show that sold out in a morning
 * went at a price somebody guessed at in January, and a show with three hundred seats left on the
 * day had no way of saying so.
 *
 * A ladder of rungs keyed on the percentage sold, and the rung in force is the highest the night
 * has reached. That is deliberately a floor rather than a band — bands have to meet exactly at
 * every edge, and an edge typed one out is a percentage with no price at all.
 *
 * Two things this counts and two it does not:
 *
 *   - **Sold, not held.** A burst of holds that expire would ratchet the price up and drop it again
 *     an hour later, which is a price nobody can explain and a screen that argues with itself.
 *   - **Blocked places are not capacity.** A house that has held back forty seats for its own use
 *     has a smaller room, not a room that is forty seats short of selling out; counting them would
 *     mean a night could never reach the top rung.
 */
class DemandPricing
{
    /**
     * The rung in force, or none.
     *
     * None when demand pricing is off, when no ladder is set, or when the night has not reached the
     * lowest rung — in all three the zone prices stand as written.
     */
    public function step(Event $event, ?int $sold = null): ?EventDemandStep
    {
        if (! $event->demand_pricing) {
            return null;
        }

        $sold ??= $this->soldPercent($event);

        return EventDemandStep::where('event_id', $event->id)
            ->where('sold_from', '<=', $sold)
            ->orderByDesc('sold_from')
            ->first();
    }

    /**
     * How much of the house has gone, as a whole percentage.
     *
     * Its own two aggregates rather than a walk through the availability service: that service asks
     * *this* class what a seat costs, and a price that had to know the availability to be computed
     * and an availability that had to know the price would be a loop with a seating plan in it.
     */
    public function soldPercent(Event $event): int
    {
        $capacity = $this->capacity($event);

        if ($capacity < 1) {
            return 0;
        }

        $sold = (int) DB::table('allocations')
            ->where('event_id', $event->id)
            ->where('status', 'active')
            ->sum(DB::raw('coalesce(quantity, 1)'));

        return (int) min(100, floor(($sold * 100) / $capacity));
    }

    /**
     * How many places this night actually has to sell.
     *
     * Seats on its published plan plus standing places, less anything the house has blocked. A
     * venue holding forty back for its own use has a smaller room, not a room that is forty short
     * of selling out — counting them would mean the top rung could never be reached.
     */
    public function capacity(Event $event): int
    {
        if (! $event->seat_map_version_id) {
            return 0;
        }

        $seats = (int) DB::table('seat_placements as sp')
            ->where('sp.seat_map_version_id', $event->seat_map_version_id)
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('event_seat_overrides as o')
                ->whereColumn('o.seat_id', 'sp.seat_id')
                ->where('o.event_id', $event->id)
                ->where('o.blocked', true))
            ->count();

        $places = (int) DB::table('capacity_placements as cp')
            ->join('capacity_objects as c', 'c.id', '=', 'cp.capacity_object_id')
            ->leftJoin('event_capacity_overrides as o', function ($join) use ($event) {
                $join->on('o.capacity_object_id', '=', 'cp.capacity_object_id')
                    ->where('o.event_id', '=', $event->id);
            })
            ->where('cp.seat_map_version_id', $event->seat_map_version_id)
            ->where(fn ($query) => $query->whereNull('o.blocked')->orWhere('o.blocked', false))
            ->sum(DB::raw('COALESCE(o.places, c.places)'));

        return $seats + $places;
    }

    /**
     * The whole adjustment, as SQL, wrapped around whatever the tier already did.
     *
     * The same shape as {@see PriceTiers::express()} and for the same reason: the price is read in
     * four places, and an adjustment applied in three of them would sell a seat at a price nobody
     * quoted. The integer folded into the string is cast from the database and is not anything a
     * request supplied.
     */
    public function express(?EventDemandStep $step, string $base): string
    {
        if (! $step || 0 === (int) $step->value) {
            return $base;
        }

        $value = (int) $step->value;

        if ('amount' === $step->kind) {
            return "GREATEST(0, ({$base}) + ({$value}))";
        }

        return "GREATEST(0, ROUND((({$base}) * (100 + {$value})) / 100.0))::int";
    }

    /** The same arithmetic in PHP, for the places that have a number rather than a query. */
    public function apply(?EventDemandStep $step, ?int $amount): ?int
    {
        if (null === $amount || ! $step || 0 === (int) $step->value) {
            return $amount;
        }

        return 'amount' === $step->kind
            ? max(0, $amount + (int) $step->value)
            : max(0, (int) round(($amount * (100 + (int) $step->value)) / 100));
    }

    /** @return \Illuminate\Support\Collection<int, EventDemandStep> */
    public function forEvent(Event $event)
    {
        return EventDemandStep::where('event_id', $event->id)
            ->orderBy('sold_from')
            ->get();
    }

    /**
     * Replace an event's ladder with this list.
     *
     * Whole-list, like the timed tiers and the price zones beside them: an organiser moving a
     * threshold expects the rungs either side of it to have moved too, and saving that as three
     * separate edits is three chances to leave a duplicate on somebody's screen.
     *
     * @param  list<array{name?: ?string, sold_from: int, kind: string, value: int}>  $steps
     * @return \Illuminate\Support\Collection<int, EventDemandStep>
     */
    public function replace(Event $event, array $steps)
    {
        $seen = [];

        foreach ($steps as $step) {
            $from = (int) ($step['sold_from'] ?? 0);

            if ($from < 0 || $from > 100) {
                throw ApiException::unprocessable(
                    'demand_step_out_of_range',
                    'A demand step starts somewhere between nought and a hundred per cent sold.',
                    ['sold_from' => $from],
                );
            }

            if (in_array($from, $seen, true)) {
                // Two prices at ninety per cent sold is not a preference to resolve.
                throw ApiException::unprocessable(
                    'demand_steps_collide',
                    'Two demand steps start at the same percentage.',
                    ['sold_from' => $from],
                );
            }

            $seen[] = $from;
        }

        return DB::transaction(function () use ($event, $steps) {
            EventDemandStep::where('event_id', $event->id)->delete();

            $sorted = collect($steps)->sortBy(fn (array $step) => (int) ($step['sold_from'] ?? 0))->values();
            $saved = collect();

            foreach ($sorted as $index => $step) {
                $saved->push(EventDemandStep::create([
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'name' => $step['name'] ?? null,
                    'sold_from' => (int) ($step['sold_from'] ?? 0),
                    'kind' => in_array($step['kind'] ?? 'percent', EventDemandStep::KINDS, true)
                        ? $step['kind']
                        : 'percent',
                    'value' => (int) ($step['value'] ?? 0),
                    'sort_order' => $index,
                ]));
            }

            $event->bumpAvailabilityVersion();

            return $saved;
        });
    }
}
