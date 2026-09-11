<?php

namespace App\Domain\Audience;

use App\Domain\Rehearsals\Live;
use App\Models\Event;
use App\Models\ExternalOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who to write to, described in seven clauses.
 *
 * The audience an organiser actually wants is almost never "everybody": it is "the people who came
 * last season and have not booked this one", which is two clauses — bought one of these, bought
 * none of those — and is the reason this exists.
 *
 * Everything here resolves to the same two columns the announcement sender already speaks in, an
 * address and a phone number per person, deduplicated the way the customer directory does it:
 * two orders typed with different capitals are one person.
 *
 * **The vocabulary is closed.** Adding a clause means adding a case here, a rule in the validator,
 * a sentence in six catalogues and a control on the screen — which sounds like friction and is the
 * point. A saved query language over buyer data is a way to write, by accident, both the query
 * that takes an hour and the one that reaches somewhere nobody meant to expose.
 *
 * **A segment holds no people.** It is resolved every time it is used. A stored list goes stale
 * the moment somebody buys, and a stale audience is a mailing about a show the reader already has
 * tickets for.
 */
class Segments
{
    /** Statuses that mean somebody actually bought — the same set the sender uses. */
    private const PAID = ['confirmed', 'partially_refunded'];

    /**
     * Every clause this platform understands, and nothing else.
     *
     * - `bought_events`      bought a ticket for any of these nights
     * - `not_bought_events`  and none of these — the second half of "has not booked this season"
     * - `categories`         bought anything in these categories, whichever night it was
     * - `since` / `until`    bought within these dates, by when the booking was paid for
     * - `min_orders`         has bought at least this many times: an account's regulars
     * - `min_spend`          has paid at least this much, in one named currency
     * - `attended`           actually turned up and was scanned in, which is not the same as bought
     */
    public const CLAUSES = [
        'bought_events', 'not_bought_events', 'categories', 'since', 'until',
        'min_orders', 'min_spend', 'currency', 'attended',
    ];

    /**
     * The people a set of rules describes.
     *
     * @param  array<string, mixed>  $rules
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function resolve(array $rules)
    {
        return $this->query($rules)
            ->orderByRaw("lower(btrim(external_orders.buyer->>'email'))")
            ->get();
    }

    /** How many people, without listing them. */
    public function count(array $rules): int
    {
        return DB::query()->fromSub($this->query($rules), 'people')->count();
    }

    /**
     * The rules as a query over buyers.
     *
     * One grouped query rather than a chain of intersections in PHP: an account with forty
     * thousand buyers is a set operation the database is built for and a memory problem this
     * process is not.
     */
    private function query(array $rules): Builder
    {
        $query = ExternalOrder::query()
            ->toBase()
            ->selectRaw(
                "lower(btrim(external_orders.buyer->>'email')) as email, ".
                "(array_agg(nullif(btrim(coalesce(external_orders.buyer->>'phone', '')), '') ".
                    'order by external_orders.created_at desc) '.
                    "filter (where nullif(btrim(coalesce(external_orders.buyer->>'phone', '')), '') is not null))[1] as phone"
            )
            ->whereIn('external_orders.status', self::PAID)
            ->whereRaw("nullif(btrim(coalesce(external_orders.buyer->>'email', '')), '') is not null")
            ->groupByRaw("lower(btrim(external_orders.buyer->>'email'))");

        // Nobody bought anything on a night being rehearsed, so nobody here is a person to write to.
        Live::only($query, 'external_orders.event_id');

        $this->narrowOrders($query, $rules);
        $this->narrowPeople($query, $rules);

        return $query;
    }

    /**
     * Clauses about the orders themselves, applied before the grouping.
     *
     * @param  array<string, mixed>  $rules
     */
    private function narrowOrders(Builder $query, array $rules): void
    {
        if ($events = $this->ids($rules, 'bought_events')) {
            $query->whereIn('external_orders.event_id', $events);
        }

        if ($categories = $this->strings($rules, 'categories')) {
            $query->whereIn('external_orders.event_id', function ($sub) use ($categories) {
                // Scoped to the same account as the order it is narrowing, which the order's own
                // tenant scope already guarantees — said here as well, because a subquery that
                // reaches across accounts is the kind of thing worth being obvious about.
                $sub->from('events')
                    ->select('events.id')
                    ->whereIn('events.category', $categories)
                    ->whereColumn('events.tenant_id', 'external_orders.tenant_id');
            });
        }

        // By when the booking was paid for, not when the basket was started: an audience of people
        // who bought in November should not include somebody whose card failed in October.
        if ($since = $this->date($rules, 'since')) {
            $query->where('external_orders.confirmed_at', '>=', $since);
        }

        if ($until = $this->date($rules, 'until')) {
            $query->where('external_orders.confirmed_at', '<=', $until);
        }

        if (! empty($rules['currency'])) {
            $query->where('external_orders.currency', mb_strtoupper((string) $rules['currency']));
        }
    }

    /**
     * Clauses about the person, applied to the group.
     *
     * `not_bought_events` and `attended` are stated as EXISTS/NOT EXISTS against the same buyer's
     * address rather than as joins: a join would multiply the rows being counted and turn
     * "bought at least three times" into "bought at least three times, times however many seats".
     *
     * @param  array<string, mixed>  $rules
     */
    private function narrowPeople(Builder $query, array $rules): void
    {
        if ($excluded = $this->ids($rules, 'not_bought_events')) {
            $query->whereRaw(
                'not exists ('.
                    'select 1 from external_orders o2 '.
                    "where lower(btrim(o2.buyer->>'email')) = lower(btrim(external_orders.buyer->>'email')) ".
                    'and o2.tenant_id = external_orders.tenant_id '.
                    "and o2.status in ('confirmed', 'partially_refunded') ".
                    'and o2.event_id in ('.$this->placeholders($excluded).')'.
                ')',
                $excluded,
            );
        }

        // Turned up, which is not the same as bought: the people who actually come are the ones
        // worth telling about the next thing.
        if (! empty($rules['attended'])) {
            $query->whereRaw(
                'exists ('.
                    'select 1 from tickets t '.
                    // A ticket belongs to an allocation, and the allocation to the order: there is
                    // no shortcut from a ticket to a buyer, because a ticket is about a seat.
                    'join allocations al on al.id = t.allocation_id '.
                    'join external_orders o3 on o3.id = al.external_order_row_id '.
                    "where lower(btrim(o3.buyer->>'email')) = lower(btrim(external_orders.buyer->>'email')) ".
                    'and o3.tenant_id = external_orders.tenant_id '.
                    "and t.status = 'used'".
                ')'
            );
        }

        if (($least = (int) ($rules['min_orders'] ?? 0)) > 1) {
            $query->havingRaw('count(distinct external_orders.id) >= ?', [$least]);
        }

        /*
         * Spend is counted per currency, and the clause is refused without one.
         *
         * An account selling in euros and rials has no single number for "spent more than a
         * hundred", and adding the two would produce a segment whose whole membership is an
         * arithmetic mistake. The validator makes `currency` required beside `min_spend`; this is
         * the second door on the same room.
         */
        if (($spend = (int) ($rules['min_spend'] ?? 0)) > 0 && ! empty($rules['currency'])) {
            $query->havingRaw('coalesce(sum(external_orders.total_amount), 0) >= ?', [$spend]);
        }
    }

    /* ------------------------------------------------------------------------------ reading */

    /**
     * A plain-language description of a rule set, for the audit log and the screen's summary.
     *
     * Names, not identifiers: "Twelfth Night, Hamlet" is what somebody checking an audience needs
     * to read, and a list of uuids is how a mailing goes to the wrong people twice.
     *
     * @return array<string, mixed>
     */
    public function explain(array $rules): array
    {
        $names = fn (array $ids) => Event::whereIn('id', $ids)->orderBy('starts_at')
            ->pluck('name')->all();

        return array_filter([
            'bought_events' => ($ids = $this->ids($rules, 'bought_events')) ? $names($ids) : null,
            'not_bought_events' => ($out = $this->ids($rules, 'not_bought_events')) ? $names($out) : null,
            'categories' => $this->strings($rules, 'categories') ?: null,
            'since' => $rules['since'] ?? null,
            'until' => $rules['until'] ?? null,
            'min_orders' => ($least = (int) ($rules['min_orders'] ?? 0)) > 1 ? $least : null,
            'min_spend' => ($spend = (int) ($rules['min_spend'] ?? 0)) > 0 ? $spend : null,
            'currency' => $rules['currency'] ?? null,
            'attended' => ! empty($rules['attended']) ?: null,
        ], fn ($value) => null !== $value);
    }

    /**
     * Keep only what the vocabulary knows, and drop the rest.
     *
     * Called on the way in, so a rule nobody can read never reaches the table. Dropping rather
     * than refusing, because the clause a client sent and this version has never heard of is one
     * an older panel would otherwise be unable to save at all — and a stored rule that nothing
     * applies is a segment that quietly means something wider than it says.
     *
     * @return array<string, mixed>
     */
    public function clean(array $rules): array
    {
        return array_intersect_key($rules, array_flip(self::CLAUSES));
    }

    /* ---------------------------------------------------------------------------- internals */

    /** @return list<string> */
    private function ids(array $rules, string $key): array
    {
        return array_values(array_filter(
            array_map('strval', (array) ($rules[$key] ?? [])),
            fn (string $id) => (bool) preg_match('/^[0-9a-f-]{36}$/i', $id),
        ));
    }

    /** @return list<string> */
    private function strings(array $rules, string $key): array
    {
        return array_values(array_filter(
            array_map(fn ($value) => mb_substr(trim((string) $value), 0, 60), (array) ($rules[$key] ?? [])),
            fn (string $value) => '' !== $value,
        ));
    }

    private function date(array $rules, string $key): ?string
    {
        $value = trim((string) ($rules[$key] ?? ''));

        if ('' === $value) {
            return null;
        }

        try {
            $date = \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // A day, read as the whole of that day: "until the 30th" that stopped at midnight would
        // silently drop everybody who bought on the 30th.
        return 'until' === $key
            ? $date->endOfDay()->toDateTimeString()
            : $date->startOfDay()->toDateTimeString();
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
