<?php

namespace App\Domain\Seasons;

use App\Domain\Inventory\Basket;
use App\Domain\Inventory\HoldService;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Hold;
use App\Models\SeasonPass;
use Illuminate\Support\Collection;

/**
 * The run, the nights in it, and the same seats held across all of them.
 *
 * Everything here is built on the ordinary hold. A season basket is not a new kind of reservation:
 * it is N ordinary holds, one per night, made in one go and released together if any of them
 * cannot be made. That is what keeps the seat-integrity guarantees — the advisory lock, the partial
 * unique index, the expiry sweep — working unchanged for a subscriber, and it is why nothing in
 * this file touches inventory directly.
 *
 * The apportionment at the bottom is the other half of the same principle. A season's saving is
 * spread across the nights rather than kept on the group, so every night's order still adds up on
 * its own and per-event revenue stays true. A discount that lived only on the group would make
 * every per-event figure in the system wrong by an amount nobody could reconstruct afterwards.
 */
class Seasons
{
    public function __construct(private readonly HoldService $holds) {}

    /**
     * The passes on offer for one run.
     *
     * @return Collection<int, SeasonPass>
     */
    public function passesFor(?string $seriesId): Collection
    {
        if (! $seriesId) {
            return collect();
        }

        return SeasonPass::where('series_id', $seriesId)
            ->where('status', 'active')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->filter(fn (SeasonPass $pass) => $pass->isLive())
            ->values();
    }

    /**
     * The nights a pass can actually be sold for.
     *
     * Published, mapped, and not over. A pass that offered a night nobody can buy would be a page
     * telling a subscriber to pay for something they cannot have — and the sum shown to them has
     * to be the sum of nights that exist.
     *
     * @return Collection<int, Event>
     */
    public function nightsIn(SeasonPass $pass): Collection
    {
        return Event::where('series_id', $pass->series_id)
            ->where('status', 'published')
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * What a basket is made of.
     *
     * Read off the signed snapshot, by the one class that knows how — the recovery link reads a
     * basket the same way, and two readings of the same snapshot would be one too many. Ticket
     * types travel with it, so a concession chosen on the first night is a concession on every
     * night of the run.
     */
    public function basketFrom(Hold $hold): Basket
    {
        return Basket::of($hold);
    }

    /**
     * How many places a basket is for. The same arithmetic the checkout uses for a per-ticket fee.
     */
    public function placesIn(Hold $hold): int
    {
        return Basket::of($hold)->places();
    }

    /**
     * Hold the same seats on every night the buyer asked for, or hold none of them.
     *
     * All-or-nothing on purpose. A subscription that got eleven nights out of twelve is not a
     * subscription and is not what anybody agreed to buy — and leaving the eleven held while the
     * buyer works out what to do would take those seats off sale for everyone else meanwhile. So a
     * night that cannot be matched releases everything this call made and says which night it was.
     *
     * The first night's hold is not made here. It already exists: the buyer made it in the picker,
     * the ordinary way, and everything below simply repeats it.
     *
     * @param  list<string>  $eventIds  the nights wanted, the first night's included
     * @return list<Hold>  ordered by night, the buyer's original hold first
     *
     * @throws ApiException naming the night that could not be matched
     */
    public function spread(SeasonPass $pass, Hold $first, array $eventIds, string $sessionId, ?string $ip = null): array
    {
        $nights = $this->nightsIn($pass)->keyBy('id');
        $wanted = $this->wantedNights($pass, $first, $eventIds, $nights);
        $basket = $this->basketFrom($first);
        $made = [];

        foreach ($wanted as $night) {
            if ($night->id === $first->event_id) {
                continue;
            }

            /*
             * A run whose nights are drawn on different maps cannot be subscribed to seat by seat:
             * "the same seat" means nothing across two plans of the building. Refused rather than
             * guessed at, because the guess would put somebody in a different chair without saying.
             */
            if ($night->seat_map_id !== $first->event?->seat_map_id) {
                $this->releaseAll($made);

                throw ApiException::unprocessable(
                    'season_map_differs',
                    sprintf('"%s" is laid out differently, so the same seats cannot be held for it.', $night->name),
                    ['event_id' => $night->id],
                    ['name' => $night->name],
                );
            }

            try {
                $made[] = $this->holds->create(
                    $night,
                    $basket->seats,
                    $sessionId,
                    null,
                    $ip,
                    $basket->capacity,
                    $basket->seatTypes,
                    $basket->areaTypes,
                );
            } catch (ApiException $e) {
                $this->releaseAll($made);

                throw ApiException::conflict(
                    'season_night_unavailable',
                    sprintf('Those seats are already taken for "%s", so the whole run cannot be held.', $night->name),
                    ['event_id' => $night->id, 'because' => $e->errorCode()],
                    ['name' => $night->name],
                );
            }
        }

        // The buyer's own hold first, then the rest in the order the run runs in.
        return array_values(collect([$first, ...$made])
            ->sortBy(fn (Hold $hold) => $nights[$hold->event_id]?->starts_at?->getTimestamp() ?? 0)
            ->all());
    }

    /**
     * Which nights this purchase is for, checked against what the pass allows.
     *
     * @param  Collection<int, Event>  $nights
     * @return list<Event>
     */
    private function wantedNights(SeasonPass $pass, Hold $first, array $eventIds, Collection $nights): array
    {
        if (! $nights->has($first->event_id)) {
            throw ApiException::unprocessable(
                'not_in_this_run',
                'That night is not part of this season ticket.',
            );
        }

        // An inflexible pass is the whole run, whatever arrived in the request: a subscription the
        // buyer could trim in a form field is not the thing the organiser priced.
        if (! $pass->isFlexible()) {
            return $nights->values()->all();
        }

        $chosen = $nights->only(array_unique([...$eventIds, $first->event_id]))->values();

        if ($chosen->count() < (int) $pass->nights) {
            throw ApiException::unprocessable(
                'season_too_few_nights',
                sprintf('This pass is for at least %d nights.', (int) $pass->nights),
                ['nights' => (int) $pass->nights],
                ['count' => (int) $pass->nights],
            );
        }

        return $chosen->all();
    }

    /** @param  list<Hold>  $holds */
    private function releaseAll(array $holds): void
    {
        foreach ($holds as $hold) {
            try {
                $this->holds->release($hold);
            } catch (\Throwable) {
                // Best effort. A hold that could not be released will expire on its own within
                // minutes, and failing here would replace a clear refusal with a confusing one.
            }
        }
    }

    /**
     * Split one saving across the nights, exactly.
     *
     * Proportional to what each night costs, floored, and then the leftover minor units handed out
     * one at a time to the nights with the largest remainders — the largest-remainder method, so
     * the shares sum to exactly the saving and no night is systematically shortchanged.
     *
     * Exactness is the whole point. If the shares summed to a penny less than the discount, the
     * nights' orders would sum to a penny more than the card was charged, and a settlement nobody
     * could reconcile would be the only sign of it.
     *
     * @param  list<int>  $values  what each night costs
     * @return list<int>  what comes off each, in the same order
     */
    public function apportion(int $off, array $values): array
    {
        $total = array_sum($values);
        $count = count($values);

        if ($off < 1 || $total < 1 || 0 === $count) {
            return array_fill(0, max(0, $count), 0);
        }

        $off = min($off, $total);
        $shares = [];
        $remainders = [];

        foreach ($values as $index => $value) {
            $exact = $off * $value;
            $shares[$index] = intdiv($exact, $total);
            $remainders[$index] = $exact % $total;
        }

        $left = $off - array_sum($shares);

        // Biggest remainder first, and ties broken by the earlier night, so the same input always
        // produces the same split — a total that moved between two renders would be unforgivable.
        $order = range(0, $count - 1);
        usort($order, fn (int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach ($order as $index) {
            if ($left < 1) {
                break;
            }

            $shares[$index]++;
            $left--;
        }

        ksort($shares);

        return array_values($shares);
    }
}
