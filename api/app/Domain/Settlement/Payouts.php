<?php

namespace App\Domain\Settlement;

use App\Exceptions\ApiException;
use App\Models\Payout;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Paying an organiser what the settlement says they are owed.
 *
 * The report was only ever half of this. It would tell anybody who asked what a window was worth,
 * and told nobody whether that window had been paid — so the same month could go out twice, a
 * fortnight could fall between two payouts nobody lined up, and a refund in March quietly rewrote
 * what February had appeared to be worth weeks after the money left.
 *
 * A payout closes a period. Three rules make that mean something:
 *
 * 1. **Once.** Periods for one account and currency may not overlap. That is an exclusion
 *    constraint in the database rather than a check here, because two operators clicking at the
 *    same moment arrive as two inserts and a check cannot see the other one.
 * 2. **Frozen.** The figures are written down as they were and never recomputed. A statement that
 *    changes after it was sent is not a statement.
 * 3. **Undone, never edited.** A mistake is voided with a reason and the period becomes free
 *    again. What was sent and what is true stay as two findable rows.
 *
 * Days, not instants. A payout is agreed in days — "March" — and both ends are inclusive, so the
 * first of April carries on exactly where the thirty-first of March stopped.
 */
class Payouts
{
    public function __construct(
        private readonly Settlement $settlement,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * What settling this window would pay, per currency, and what stands in the way.
     *
     * Read before anything is written, and the same call the screen makes: an operator about to
     * send money should be looking at the figures that are about to be frozen, not at a report
     * that might have been filtered differently.
     *
     * @return array{
     *     period: array{from: string, to: string},
     *     currencies: list<array<string, mixed>>,
     *     clashes: list<array<string, mixed>>,
     *     suggested_from: ?string
     * }
     */
    public function preview(Tenant $tenant, string $from, string $to): array
    {
        [$from, $to] = $this->days($from, $to);

        $settlement = $this->tenants->runAs($tenant, fn () => $this->settlement->forPeriod([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            // Always the bank's view of a period. Settling on an event basis would let two payouts
            // that do not overlap by date overlap by money — the takings of a night in June, paid
            // in May and again in June — which is the one thing this is here to stop.
            'basis' => 'paid',
        ]));

        $rows = collect($settlement['rows']);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currencies' => collect($settlement['totals'])
                ->map(fn (array $total) => [
                    'currency' => $total['currency'],
                    'events' => $total['events'],
                    'orders' => $total['orders'],
                    'charged' => $total['charged'],
                    'refunded' => $total['refunded'],
                    'kept' => $total['kept'],
                    'tax_kept' => $total['tax_kept'],
                    'commission' => $total['commission'],
                    'payable' => $total['payable'],
                    'already_paid' => $this->alreadyPaid($tenant, $total['currency'], $from, $to),
                ])
                ->values()
                ->all(),
            // Named rather than counted: "this overlaps something" is not actionable, and
            // "it overlaps the payout for 1–31 March" is.
            'clashes' => $this->clashing($tenant, $from, $to)
                ->map(fn (Payout $payout) => $this->present($payout))
                ->values()
                ->all(),
            // Where the next period starts if nobody argues: the day after the last one ended.
            'suggested_from' => $this->nextFrom($tenant),
            'commission_rate' => $settlement['commission_rate'],
            'events' => $rows->count(),
        ];
    }

    /**
     * Settle it: freeze the figures and write a payout per currency.
     *
     * One call, one period, and as many rows as there are currencies in it — an account selling in
     * euro and in rial is owed two different amounts of two different things, and a single row
     * holding their sum would be a number nobody could pay.
     *
     * @param  array{reference?: ?string, method?: ?string, note?: ?string, paid_at?: ?string, currency?: ?string}  $details
     * @return list<Payout>
     */
    public function settle(Tenant $tenant, string $from, string $to, array $details = [], ?PlatformAdmin $by = null): array
    {
        [$from, $to] = $this->days($from, $to);

        $settlement = $this->tenants->runAs($tenant, fn () => $this->settlement->forPeriod([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'basis' => 'paid',
        ]));

        $totals = collect($settlement['totals']);

        if ($only = ($details['currency'] ?? null)) {
            $totals = $totals->where('currency', $only)->values();
        }

        if ($totals->isEmpty()) {
            /*
             * Nothing was taken in this window.
             *
             * Refused rather than recorded as a zero: a payout for a period with no orders in it is
             * almost always a period typed wrongly, and writing it would block the right one behind
             * the overlap rule until somebody worked out why.
             */
            throw ApiException::unprocessable(
                'nothing_to_settle',
                'Nothing was taken in that period, so there is nothing to settle.',
                ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            );
        }

        /*
         * Asked before it is attempted, so the ordinary case — somebody settling a month that was
         * already settled — comes back as a refusal that names the payout in the way, rather than
         * as a database error turned into one. The constraint below is still the authority; this
         * only makes the common answer a good one.
         */
        foreach ($totals->pluck('currency') as $currency) {
            $clashes = $this->clashing($tenant, $from, $to, $currency);

            if ($clashes->isNotEmpty()) {
                throw $this->alreadySettled($currency, $clashes);
            }
        }

        $paidAt = ($details['paid_at'] ?? null) ? Carbon::parse($details['paid_at']) : null;

        /*
         * All the currencies or none of them.
         *
         * Two rows, the second refused by the constraint, would leave a period half settled: the
         * euro side paid and the rial side not, with no record anywhere saying which. One
         * transaction over the loop makes the refusal undo the row that had already gone in.
         */
        return DB::transaction(function () use ($tenant, $from, $to, $totals, $settlement, $details, $paidAt, $by) {
            $made = [];

            foreach ($totals as $total) {
                $made[] = $this->write($tenant, $from, $to, $total, $settlement, $details, $paidAt, $by);
            }

            return $made;
        });
    }

    /**
     * Undo one.
     *
     * Not a delete: a payout that was sent to somebody is a thing that happened, and a period that
     * was closed and reopened is worth being able to see. Voiding frees the dates — the exclusion
     * constraint ignores void rows — so the right payout can then be made over the same window.
     */
    public function void(Payout $payout, string $reason, ?PlatformAdmin $by = null): Payout
    {
        if ($payout->isVoid()) {
            throw ApiException::conflict('payout_already_void', 'That payout has already been voided.');
        }

        $payout->forceFill([
            'status' => 'void',
            'voided_by' => $by?->id,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        return $payout;
    }

    /** Mark the money as actually gone, with the bank's own reference on it. */
    public function markPaid(Payout $payout, array $details = []): Payout
    {
        if ($payout->isVoid()) {
            throw ApiException::conflict('payout_is_void', 'A voided payout cannot be paid.');
        }

        $payout->forceFill(array_filter([
            'status' => 'paid',
            'paid_at' => ($details['paid_at'] ?? null) ? Carbon::parse($details['paid_at']) : now(),
            'reference' => $details['reference'] ?? $payout->reference,
            'method' => $details['method'] ?? $payout->method,
        ], fn ($value) => null !== $value))->save();

        return $payout;
    }

    /**
     * One account's payouts, newest period first.
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(Tenant|string $tenant, int $limit = 60): array
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return Payout::where('tenant_id', $id)
            ->orderByDesc('period_from')
            ->orderBy('currency')
            ->limit($limit)
            ->get()
            ->map(fn (Payout $payout) => $this->present($payout))
            ->values()
            ->all();
    }

    /** The day after the last period that was settled, or null when none has been. */
    public function nextFrom(Tenant|string $tenant): ?string
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        $last = Payout::where('tenant_id', $id)
            ->where('status', '!=', 'void')
            ->orderByDesc('period_to')
            ->first();

        return $last?->period_to?->copy()->addDay()->toDateString();
    }

    public function present(Payout $payout): array
    {
        return [
            'id' => $payout->id,
            'currency' => $payout->currency,
            'from' => $payout->period_from?->toDateString(),
            'to' => $payout->period_to?->toDateString(),
            'charged' => $payout->charged,
            'refunded' => $payout->refunded,
            'kept' => $payout->kept,
            'tax_kept' => $payout->tax_kept,
            'commission' => $payout->commission,
            'commission_rate' => $payout->commission_rate,
            'payable' => $payout->payable,
            'orders' => $payout->orders,
            'events' => $payout->events ?: [],
            'status' => $payout->status,
            'reference' => $payout->reference,
            'method' => $payout->method,
            'note' => $payout->note,
            'paid_at' => $payout->paid_at?->toIso8601String(),
            'voided_at' => $payout->voided_at?->toIso8601String(),
            'void_reason' => $payout->void_reason,
            'created_at' => $payout->created_at?->toIso8601String(),
        ];
    }

    /* --------------------------------------------------------------------------- internals */

    private function write(
        Tenant $tenant,
        Carbon $from,
        Carbon $to,
        array $total,
        array $settlement,
        array $details,
        ?Carbon $paidAt,
        ?PlatformAdmin $by,
    ): Payout {
        $currency = $total['currency'];

        try {
            /*
             * Its own transaction, which inside one is a savepoint.
             *
             * The exclusion constraint refuses at insert time, and in Postgres a failed statement
             * poisons the whole transaction it is in — every query after it answers "in failed sql
             * transaction" until somebody rolls back. A savepoint keeps the refusal to this one
             * insert, so the refusal can be *described*: the clashing payouts are looked up after
             * it and named in the error.
             */
            return DB::transaction(fn () => Payout::create([
                'tenant_id' => $tenant->id,
                'currency' => $currency,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'charged' => $total['charged'],
                'refunded' => $total['refunded'],
                'kept' => $total['kept'],
                'tax_kept' => $total['tax_kept'],
                'commission' => $total['commission'],
                'payable' => $total['payable'],
                'commission_rate' => $settlement['commission_rate'],
                'orders' => $total['orders'],
                // Only this currency's events, so each row's breakdown adds up to its own total.
                'events' => collect($settlement['rows'])
                    ->where('currency', $currency)
                    ->map(fn (array $row) => [
                        'id' => $row['event']['id'],
                        'name' => $row['event']['name'],
                        'starts_at' => $row['event']['starts_at'],
                        'orders' => $row['orders'],
                        'seats' => $row['seats'],
                        'charged' => $row['charged'],
                        'refunded' => $row['refunded'],
                        'commission' => $row['commission'],
                        'payable' => $row['payable'],
                    ])
                    ->values()
                    ->all(),
                'status' => $paidAt ? 'paid' : 'recorded',
                'reference' => $details['reference'] ?? null,
                'method' => $details['method'] ?? null,
                'note' => $details['note'] ?? null,
                'paid_at' => $paidAt,
                'created_by' => $by?->id,
            ]));
        } catch (QueryException $e) {
            /*
             * The database refused the overlap.
             *
             * Turned into the same refusal the preview would have given, with the payouts that got
             * in the way named. Reaching here rather than the check above means somebody else was
             * writing at the same moment, which is exactly the case a check cannot cover.
             */
            if (! str_contains((string) $e->getMessage(), 'payouts_no_overlapping_period')) {
                throw $e;
            }

            throw $this->alreadySettled($currency, $this->clashing($tenant, $from, $to, $currency));
        }
    }

    /** The one refusal, wherever it was noticed — with the payouts in the way named. */
    private function alreadySettled(string $currency, $clashes): ApiException
    {
        return ApiException::conflict(
            'period_already_settled',
            'Some of those days have already been settled.',
            [
                'currency' => $currency,
                'clashes' => $clashes->map(fn (Payout $payout) => [
                    'id' => $payout->id,
                    'from' => $payout->period_from?->toDateString(),
                    'to' => $payout->period_to?->toDateString(),
                ])->values()->all(),
            ],
        );
    }

    /** Live payouts whose days touch this window. */
    private function clashing(Tenant $tenant, Carbon $from, Carbon $to, ?string $currency = null)
    {
        return Payout::where('tenant_id', $tenant->id)
            ->where('status', '!=', 'void')
            ->when($currency, fn ($query) => $query->where('currency', $currency))
            // Inclusive at both ends, the same as the constraint: two periods touch when each
            // starts before the other ends.
            ->where('period_from', '<=', $to->toDateString())
            ->where('period_to', '>=', $from->toDateString())
            ->orderBy('period_from')
            ->get();
    }

    /** What has already gone out for these days in this currency, however much of them it covers. */
    private function alreadyPaid(Tenant $tenant, string $currency, Carbon $from, Carbon $to): int
    {
        return (int) $this->clashing($tenant, $from, $to, $currency)->sum('payable');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function days(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            throw ApiException::unprocessable(
                'period_runs_backwards',
                'A payout period has to end on or after the day it starts.'
            );
        }

        return [$start, $end];
    }
}
