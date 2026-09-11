<?php

namespace App\Domain\Pricing;

use App\Models\Event;

/**
 * What the room costs right now: the whole of it, in one place.
 *
 * There are now two reasons a price moves — when it is, and how much is left — and a third thing
 * that stops it moving too far. Keeping them in one expression is the point: the price is read in
 * four places (the buyer's plan, the hold's snapshot, and the same two again for standing room),
 * and an adjustment applied in three of them would sell a seat at a price nobody quoted.
 *
 * The order is stated and it matters:
 *
 *   1. **The timed tier**, because that is the published price for this window — "early bird until
 *      Friday" is a promise, and it is the thing being adjusted rather than an adjustment itself.
 *   2. **The demand step**, on top of that. A night that is ninety per cent gone is ninety per cent
 *      gone whether or not it is still early bird week.
 *   3. **The floor and the ceiling**, last, over whatever the first two produced. They are rails
 *      rather than another adjustment: an organiser saying "never below twenty, never above sixty"
 *      is saying it about the number a buyer is charged, not about one of the steps on the way.
 *
 * None of it touches an explicit per-seat amount. A house that has typed an exact number against
 * seat A1 has said what that seat costs, and moving it because it is March or because the balcony
 * filled up would be overriding the override.
 */
class Prices
{
    public function __construct(
        private readonly PriceTiers $tiers,
        private readonly DemandPricing $demand,
    ) {}

    /** The adjustment as SQL, around a base expression the caller supplies. */
    public function express(Event $event, string $base): string
    {
        $withTier = $this->tiers->express($this->tiers->active($event), $base);
        $withDemand = $this->demand->express($this->demand->step($event), $withTier);

        return $this->rails($event, $withDemand);
    }

    /** The same arithmetic in PHP, for the places that have a number rather than a query. */
    public function apply(Event $event, ?int $amount): ?int
    {
        if (null === $amount) {
            return null;
        }

        $amount = $this->tiers->apply($this->tiers->active($event), $amount);
        $amount = $this->demand->apply($this->demand->step($event), $amount);

        return $this->clamp($event, $amount);
    }

    /**
     * What to tell a buyer and a box office about why the price is what it is.
     *
     * The tier half is what sells a ticket — "early bird until Friday" is a reason to buy today.
     * The demand half is deliberately *not* dressed up as one: "prices rise as seats go" is a
     * pressure tactic when it is a slogan and a fact when it is a percentage, so what comes back is
     * the percentage, and the screen decides whether a buyer is shown it at all.
     *
     * @return array{tier: ?array<string, mixed>, sold_percent: ?int, demand: ?array<string, mixed>, floor: ?int, ceiling: ?int}
     */
    public function describe(Event $event): array
    {
        $sold = $event->demand_pricing ? $this->demand->soldPercent($event) : null;
        $step = null === $sold ? null : $this->demand->step($event, $sold);

        return [
            'tier' => $this->tiers->describe($event),
            'sold_percent' => $sold,
            'demand' => $step ? [
                'name' => $step->name,
                'sold_from' => $step->sold_from,
                'kind' => $step->kind,
                'value' => $step->value,
            ] : null,
            'floor' => $event->price_floor,
            'ceiling' => $event->price_ceiling,
        ];
    }

    /* --------------------------------------------------------------------------- internals */

    private function rails(Event $event, string $expression): string
    {
        $floor = $event->price_floor;
        $ceiling = $event->price_ceiling;

        if (null !== $ceiling) {
            $expression = 'LEAST('.$expression.', '.(int) $ceiling.')';
        }

        /*
         * The floor last, so it wins.
         *
         * A house that has set both and then typed them the wrong way round has said two things
         * that cannot both be true; of the two, "never sell below this" is the one with money on
         * the other end of it, and the one they will notice if it is ignored.
         */
        if (null !== $floor) {
            $expression = 'GREATEST('.$expression.', '.(int) $floor.')';
        }

        return $expression;
    }

    private function clamp(Event $event, ?int $amount): ?int
    {
        if (null === $amount) {
            return null;
        }

        if (null !== $event->price_ceiling) {
            $amount = min($amount, (int) $event->price_ceiling);
        }

        if (null !== $event->price_floor) {
            $amount = max($amount, (int) $event->price_floor);
        }

        return max(0, $amount);
    }
}
