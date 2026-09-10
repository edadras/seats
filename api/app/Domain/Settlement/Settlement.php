<?php

namespace App\Domain\Settlement;

use App\Models\Event;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * What the organiser is owed, and what the platform kept.
 *
 * Every other money screen answers "what did we take". This one answers the question that gets
 * asked at the end of a run and settled with a venue: of everything that came in, how much was
 * discount, how much went back out as refunds, how much of it is tax that belongs to somebody
 * else, what the platform charged, and what is left.
 *
 * One figure here is not money that moved in this period: `voucher` is the part of `charged` that
 * was settled out of a gift voucher or an account credit. That money was taken when the voucher was
 * bought, so it belongs in what the organiser is owed — but it will not appear on a bank statement
 * for these dates, and a settlement that could not say which part was which is one nobody can
 * reconcile. It is inside `charged` and must never be added to it.
 *
 * Nothing here is recomputed from a live event. The arithmetic of a booking was frozen onto the
 * order by {@see \App\Domain\Orders\OrderTotals} at the moment it was paid, and this reads that
 * back: an organiser who raises their booking fee in March must not find that February settled
 * differently than it did in February.
 *
 * Grouped by currency as well as by event, because events carry their own currency and a column
 * that added rial to euro would be a number nobody could bank.
 */
class Settlement
{
    /** Orders that took money. A pending order took none and a cancelled one never charged. */
    private const PAID = ['confirmed', 'partially_refunded', 'refunded'];

    /** Seats that came back. A hold that simply expired never belonged to an order. */
    private const RETURNED = ['released', 'void'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The platform's percentage, in basis points, from the plan the account is on.
     *
     * Read live rather than frozen per order: the platform bills the organiser, and a change of
     * plan is a change to the bill, not a rewrite of what a buyer was charged.
     */
    public function rate(): int
    {
        $subscription = Subscription::with('plan')->latest('created_at')->first();

        return max(0, (int) ($subscription?->plan?->commission_rate ?? 0));
    }

    /**
     * @param  array{from?: ?string, to?: ?string, event_id?: ?string, basis?: ?string}  $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: list<array<string, mixed>>,
     *     period: array{from: ?string, to: ?string, basis: string},
     *     commission_rate: int
     * }
     */
    public function forPeriod(array $filters = []): array
    {
        $rate = $this->rate();
        $basis = 'event' === ($filters['basis'] ?? null) ? 'event' : 'paid';
        [$from, $to] = $this->window($filters);

        $taken = $this->taken($filters, $basis, $from, $to);
        $sold = $this->sold($filters, $basis, $from, $to);
        $returned = $this->returned($filters, $basis, $from, $to);

        $rows = [];

        foreach ($taken as $event) {
            $rows[] = $this->line(
                $event,
                (int) ($sold[$event->event_id] ?? 0),
                $returned[$event->event_id] ?? null,
                $rate,
            );
        }

        // Biggest first: a settlement is read to find the money, not to find an event.
        usort($rows, fn (array $a, array $b) => $b['payable'] <=> $a['payable']);

        return [
            'rows' => $rows,
            'totals' => $this->perCurrency($rows),
            'period' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'basis' => $basis,
            ],
            'commission_rate' => $rate,
        ];
    }

    /** One event's settlement, for the statement a venue is sent. */
    public function forEvent(Event $event): array
    {
        $result = $this->forPeriod(['event_id' => $event->id]);

        return $result['rows'][0] ?? $this->line((object) [
            'event_id' => $event->id,
            'name' => $event->name,
            'starts_at' => $event->starts_at,
            'currency' => $event->currency,
            'orders' => 0, 'tickets' => 0,
            'discount' => 0, 'fee' => 0, 'tax' => 0, 'charged' => 0, 'voucher' => 0,
        ], 0, null, $this->rate());
    }

    /* ------------------------------------------------------------------------- the arithmetic */

    /**
     * @param  int  $seats  seats still sold
     * @param  ?object  $refund  the money that went back out, or null when none did
     */
    private function line(object $event, int $seats, ?object $refund, int $rate): array
    {
        $charged = (int) $event->charged;
        $tax = (int) $event->tax;
        $refunded = (int) ($refund->whole ?? 0) + (int) ($refund->part ?? 0);

        // A refund can never exceed what was taken. It should not be able to; clamping means a
        // rounding difference in an imported order cannot produce a negative payable.
        $refunded = max(0, min($charged, $refunded));
        $kept = $charged - $refunded;

        /*
         * The tax on the money that stayed.
         *
         * A part refund returns seats, and the tax on those seats goes back with them. Nothing
         * records that split — the order froze one tax figure for the whole booking — so it is
         * apportioned to what is left, rounded half up. On a full refund it is zero, and on no
         * refund at all it is exactly the tax that was charged.
         */
        $taxKept = 0 === $charged ? 0 : intdiv($tax * $kept + intdiv($charged, 2), $charged);

        // Commission is charged on what the organiser keeps, never on the tax: that money is
        // collected on behalf of a tax authority and is not the organiser's to share.
        $basis = max(0, $kept - $taxKept);
        $commission = 0 === $rate ? 0 : intdiv($basis * $rate + 5000, 10000);

        return [
            'event' => [
                'id' => $event->event_id,
                'name' => $event->name,
                'starts_at' => $event->starts_at
                    ? Carbon::parse($event->starts_at)->toIso8601String()
                    : null,
            ],
            'currency' => $event->currency,
            'orders' => (int) $event->orders,
            'seats' => $seats,
            'seats_refunded' => (int) ($refund->seats ?? 0),
            'tickets' => (int) $event->tickets,
            'discount' => (int) $event->discount,
            'fee' => (int) $event->fee,
            'tax' => $tax,
            'charged' => $charged,
            // Inside `charged`, not beside it: the two must never be added together.
            'voucher' => (int) ($event->voucher ?? 0),
            'refunded' => $refunded,
            'kept' => $kept,
            'tax_kept' => $taxKept,
            'commission' => $commission,
            'payable' => $kept - $commission,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function perCurrency(array $rows): array
    {
        $fields = ['orders', 'seats', 'seats_refunded', 'tickets', 'discount', 'fee', 'tax',
            'charged', 'voucher', 'refunded', 'kept', 'tax_kept', 'commission', 'payable'];

        $totals = [];

        foreach ($rows as $row) {
            $currency = $row['currency'];

            if (! isset($totals[$currency])) {
                $totals[$currency] = ['currency' => $currency, 'events' => 0]
                    + array_fill_keys($fields, 0);
            }

            $totals[$currency]['events']++;

            foreach ($fields as $field) {
                $totals[$currency][$field] += $row[$field];
            }
        }

        return array_values($totals);
    }

    /* ----------------------------------------------------------------------------- the queries */

    /** Money in, per event, read back from what each booking froze. */
    private function taken(array $filters, string $basis, ?Carbon $from, ?Carbon $to)
    {
        $query = $this->base($filters, $basis, $from, $to)
            ->whereIn('o.status', self::PAID)
            ->select([
                'e.id as event_id', 'e.name', 'e.starts_at', 'e.currency',
                DB::raw('count(*) as orders'),
                // An order placed over the integration API froze no totals — the shop that took
                // the money owns that arithmetic — so its charged total stands as its ticket
                // money, with nothing claimed about a fee or a tax that was never recorded here.
                DB::raw("sum(coalesce((o.metadata->'totals'->>'tickets')::bigint, o.total_amount)) as tickets"),
                DB::raw("sum(coalesce((o.metadata->'totals'->>'discount')::bigint, 0)) as discount"),
                DB::raw("sum(coalesce((o.metadata->'totals'->>'fee')::bigint, 0)) as fee"),
                DB::raw("sum(coalesce((o.metadata->'totals'->>'tax')::bigint, 0)) as tax"),
                DB::raw('sum(o.total_amount) as charged'),
                // Of that, the part settled out of vouchers rather than through a gateway. It is
                // money the organiser took when the voucher was bought, so it is theirs and it is
                // inside `charged` — but it never reached a bank statement in this period, and a
                // settlement that could not say so is a settlement nobody can reconcile.
                DB::raw('sum(coalesce(o.voucher_amount, 0)) as voucher'),
            ])
            ->groupBy('e.id', 'e.name', 'e.starts_at', 'e.currency');

        return $query->get();
    }

    /**
     * Seats still sold, per event.
     *
     * Its own query rather than a count beside the money: joining allocations to orders would
     * multiply every order row by its seats and quietly treble the takings of a family booking.
     *
     * @return array<string, int>
     */
    private function sold(array $filters, string $basis, ?Carbon $from, ?Carbon $to): array
    {
        return $this->base($filters, $basis, $from, $to)
            ->join('allocations as a', 'a.external_order_row_id', '=', 'o.id')
            ->whereIn('o.status', self::PAID)
            ->where('a.status', 'active')
            ->groupBy('e.id')
            ->selectRaw('e.id as event_id, sum(coalesce(a.quantity, 1)) as value')
            ->pluck('value', 'event_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Money back out, per event.
     *
     * A fully refunded order returns everything the buyer paid — the fee and the tax with it — so
     * the whole charged total comes back. A part refund returns the seats named and nothing else:
     * whether a booking fee survives a partial refund is the organiser's policy, written in their
     * refund terms, and the platform does not invent an answer to it.
     *
     * Three figures from three queries, because a whole refund is counted per order and a partial
     * one per seat, and one query trying to do both is one query getting one of them wrong.
     *
     * @return array<string, object>
     */
    private function returned(array $filters, string $basis, ?Carbon $from, ?Carbon $to): array
    {
        $whole = $this->base($filters, $basis, $from, $to)
            ->where('o.status', 'refunded')
            ->groupBy('e.id')
            ->selectRaw('e.id as event_id, sum(o.total_amount) as value')
            ->pluck('value', 'event_id');

        $part = $this->base($filters, $basis, $from, $to)
            ->join('allocations as a', 'a.external_order_row_id', '=', 'o.id')
            ->where('o.status', 'partially_refunded')
            ->whereIn('a.status', self::RETURNED)
            ->groupBy('e.id')
            ->selectRaw('e.id as event_id, sum(a.amount) as value')
            ->pluck('value', 'event_id');

        $seats = $this->base($filters, $basis, $from, $to)
            ->join('allocations as a', 'a.external_order_row_id', '=', 'o.id')
            ->whereIn('o.status', ['refunded', 'partially_refunded'])
            ->whereIn('a.status', self::RETURNED)
            ->groupBy('e.id')
            ->selectRaw('e.id as event_id, sum(coalesce(a.quantity, 1)) as value')
            ->pluck('value', 'event_id');

        $out = [];

        foreach (array_unique(array_merge($whole->keys()->all(), $part->keys()->all())) as $id) {
            $out[$id] = (object) [
                'whole' => (int) ($whole[$id] ?? 0),
                'part' => (int) ($part[$id] ?? 0),
                'seats' => (int) ($seats[$id] ?? 0),
            ];
        }

        return $out;
    }

    /** Orders joined to their events, cut to the account, the event and the period asked for. */
    private function base(array $filters, string $basis, ?Carbon $from, ?Carbon $to)
    {
        return $this->scope(
            DB::table('external_orders as o')->join('events as e', 'e.id', '=', 'o.event_id'),
            $filters,
            $basis,
            $from,
            $to,
        );
    }

    /**
     * The filters every part of the report shares.
     *
     * `tenant_id` is stated rather than assumed: these are raw queries, outside the global scope
     * that normally makes a cross-tenant read impossible, and money is the last place to rely on
     * something being applied elsewhere.
     */
    private function scope($query, array $filters, string $basis, ?Carbon $from, ?Carbon $to)
    {
        $query->where('o.tenant_id', $this->tenants->idOrFail());

        if ($filters['event_id'] ?? null) {
            $query->where('e.id', $filters['event_id']);
        }

        // Two ways to cut a period, and they answer different questions. "Paid" is the bank's
        // view — when the money moved — and settles a payout. "Event" is the venue's view — which
        // nights these takings belong to — and settles a run.
        $column = 'paid' === $basis ? 'o.confirmed_at' : 'e.starts_at';

        if ($from) {
            $query->where($column, '>=', $from);
        }

        if ($to) {
            $query->where($column, '<=', $to);
        }

        return $query;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function window(array $filters): array
    {
        $from = ($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = ($filters['to'] ?? null) ? Carbon::parse($filters['to'])->endOfDay() : null;

        return [$from, $to];
    }
}
