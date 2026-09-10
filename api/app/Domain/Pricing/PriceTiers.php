<?php

namespace App\Domain\Pricing;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\EventPriceTier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the room costs at this moment.
 *
 * The whole of this feature is one expression pushed into four queries. Prices are read in four
 * places — the buyer's availability, the hold's price snapshot, the same two again for standing
 * room — and if the tier were applied in three of them the fourth would sell a seat at a price
 * nobody quoted. So the adjustment is written once here, as SQL, and the callers ask for it.
 *
 * It is applied to the *zone* price and not to an explicit per-seat amount. A house that has typed
 * an exact number against seat A1 has said what that seat costs; moving it twenty per cent because
 * it is March would be overriding the override.
 */
class PriceTiers
{
    /** The tier in force now, or none — in which case the zone prices stand as written. */
    public function active(Event $event, ?Carbon $at = null): ?EventPriceTier
    {
        $moment = $at ?: now();

        return $this->forEvent($event)
            ->first(fn (EventPriceTier $tier) => $tier->coversNow($moment));
    }

    /** @return \Illuminate\Support\Collection<int, EventPriceTier> */
    public function forEvent(Event $event)
    {
        return EventPriceTier::where('event_id', $event->id)
            ->orderBy('sort_order')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * The SQL that turns a zone price into today's price.
     *
     * The two numbers folded into the string are integers cast from the database, not anything a
     * request supplied — there is nothing here for a request to reach. They are inlined rather than
     * bound because this fragment is spliced into four different queries with four different
     * binding sets, and a binding that has to be remembered in four places is a binding that will
     * be forgotten in one.
     */
    public function express(?EventPriceTier $tier, string $base): string
    {
        if (! $tier || 0 === (int) $tier->value) {
            return $base;
        }

        $value = (int) $tier->value;

        if ('amount' === $tier->kind) {
            // Never below nothing: an organiser taking €5 off a €3 seat means it is free, not that
            // the booking owes them two euros.
            return "GREATEST(0, ({$base}) + ({$value}))";
        }

        return "GREATEST(0, ROUND((({$base}) * (100 + {$value})) / 100.0))::int";
    }

    /** The same arithmetic in PHP, for the places that have a number rather than a query. */
    public function apply(?EventPriceTier $tier, ?int $amount): ?int
    {
        if (null === $amount || ! $tier || 0 === (int) $tier->value) {
            return $amount;
        }

        return 'amount' === $tier->kind
            ? max(0, $amount + (int) $tier->value)
            : max(0, (int) round(($amount * (100 + (int) $tier->value)) / 100));
    }

    /**
     * Replace an event's tiers with this list.
     *
     * Whole-list rather than one at a time, like the price zones beside them: an organiser moves a
     * deadline by dragging one date and expects the tier after it to have moved too, and saving
     * that as three separate edits is three chances to leave a gap or an overlap on somebody's
     * screen.
     *
     * @param  list<array{name: string, starts_at: ?string, ends_at: ?string, kind: string, value: int}>  $tiers
     * @return \Illuminate\Support\Collection<int, EventPriceTier>
     */
    public function replace(Event $event, array $tiers)
    {
        $windows = [];

        foreach ($tiers as $index => $tier) {
            $starts = ($tier['starts_at'] ?? null) ? Carbon::parse($tier['starts_at']) : null;
            $ends = ($tier['ends_at'] ?? null) ? Carbon::parse($tier['ends_at']) : null;

            if ($starts && $ends && $starts->greaterThanOrEqualTo($ends)) {
                throw ApiException::unprocessable(
                    'tier_window_backwards',
                    'A price tier cannot end before it begins.',
                    ['tier' => $tier['name'] ?? (string) $index],
                );
            }

            $windows[] = ['name' => $tier['name'] ?? '', 'starts' => $starts, 'ends' => $ends];
        }

        foreach ($windows as $i => $one) {
            foreach (array_slice($windows, $i + 1) as $other) {
                if ($this->overlap($one, $other)) {
                    // Two prices for one moment is not a preference to resolve, it is a mistake to
                    // report — and this is the only place where the person who made it is looking.
                    throw ApiException::unprocessable(
                        'tier_windows_overlap',
                        'Two price tiers cover the same moment.',
                        ['tiers' => [$one['name'], $other['name']]],
                    );
                }
            }
        }

        return DB::transaction(function () use ($event, $tiers) {
            EventPriceTier::where('event_id', $event->id)->delete();

            $saved = collect();

            foreach (array_values($tiers) as $index => $tier) {
                $saved->push(EventPriceTier::create([
                    'event_id' => $event->id,
                    'name' => $tier['name'],
                    'starts_at' => ($tier['starts_at'] ?? null) ? Carbon::parse($tier['starts_at']) : null,
                    'ends_at' => ($tier['ends_at'] ?? null) ? Carbon::parse($tier['ends_at']) : null,
                    'kind' => $tier['kind'] ?? 'percent',
                    'value' => (int) ($tier['value'] ?? 0),
                    'sort_order' => $index,
                ]));
            }

            $event->bumpAvailabilityVersion();

            return $saved;
        });
    }

    /** Two open-ended windows share a moment unless one ends before the other begins. */
    private function overlap(array $a, array $b): bool
    {
        if ($a['ends'] && $b['starts'] && $a['ends']->lessThanOrEqualTo($b['starts'])) {
            return false;
        }

        return ! ($b['ends'] && $a['starts'] && $b['ends']->lessThanOrEqualTo($a['starts']));
    }

    /**
     * What to tell a buyer: the tier they are inside and when it stops.
     *
     * The deadline is the part that sells a ticket. "Early bird" alone is decoration; "Early bird
     * until Friday" is a reason to buy today, and it is true because it is read from the same row
     * the price came from.
     *
     * @return array{name: string, ends_at: ?string, kind: string, value: int}|null
     */
    public function describe(Event $event, ?Carbon $at = null): ?array
    {
        $tier = $this->active($event, $at);

        return $tier ? [
            'name' => $tier->name,
            'ends_at' => $tier->ends_at?->toIso8601String(),
            'kind' => $tier->kind,
            'value' => (int) $tier->value,
        ] : null;
    }
}
