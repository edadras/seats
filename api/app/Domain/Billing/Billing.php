<?php

namespace App\Domain\Billing;

use App\Domain\Notifications\Notifier;
use App\Domain\Settlement\Settlement;
use App\Exceptions\ApiException;
use App\Models\BillingMethod;
use App\Models\PlatformInvoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The platform billing an organiser.
 *
 * Everything about money in this codebase was the organiser's: their gateways, their refunds, their
 * settlement, their payouts. The platform's own side was a price list and a `subscriptions` row
 * with a `current_period_end` that nothing ever looked at. An account signed up, a period was
 * written down, the period passed, and not one thing happened. The plans were a marketing table.
 *
 * An invoice is what the platform says is owed for one period, frozen when it is raised. Two lines,
 * either of which may be nothing:
 *
 *   - the **plan fee** for the period, at the price the plan carried on the day;
 *   - the **commission** the platform earned on what the organiser sold, taken from the same
 *     settlement arithmetic the organiser reads, so the two can be compared line by line.
 *
 * Nothing is recomputed afterwards. A refund lands in a month already invoiced and the settlement
 * report changes; the invoice does not, because an invoice that changes after it was sent is not an
 * invoice. The correction is a void and a replacement, which leaves two findable rows.
 *
 * One per account per period, held by an exclusion constraint rather than a check, for the same
 * reason a payout is: a billing run that fires twice arrives as two inserts and neither can see the
 * other.
 */
class Billing
{
    public function __construct(
        private readonly Settlement $settlement,
        private readonly TenantContext $tenants,
        private readonly Chargers $chargers,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The period an account is next owed for, or null when nothing is due yet.
     *
     * Billed in arrears, and that is a choice worth stating: a plan fee taken in advance for a
     * month the organiser might spend suspended is money that has to be given back, and the
     * commission half cannot be known in advance at all. Both halves on one invoice, for one
     * period, after it has happened.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function periodDue(Subscription $subscription, ?Carbon $at = null): ?array
    {
        $at = $at ?: now();

        // Where the last invoice stopped, or where the subscription started.
        $from = $subscription->last_invoiced_to
            ? $subscription->last_invoiced_to->copy()->addDay()->startOfDay()
            : ($subscription->current_period_start ?: $subscription->created_at)?->copy()->startOfDay();

        if (! $from) {
            return null;
        }

        $to = $this->endOfPeriod($from, $subscription);

        // Not over yet. A period is invoiced once it has finished, never partway through.
        return $to->endOfDay()->greaterThan($at) ? null : [$from, $to];
    }

    /**
     * Raise the invoice for one period.
     *
     * @throws ApiException when those days are already on an invoice
     */
    public function raise(Tenant $tenant, Carbon $from, Carbon $to): PlatformInvoice
    {
        $subscription = $this->tenants->runAs($tenant, fn () => Subscription::with('plan')
            ->latest('created_at')
            ->first());

        $plan = $subscription?->plan;
        $currency = (string) config('seatmap.billing.currency', 'EUR');
        $vatRate = max(0, (int) config('seatmap.billing.vat_rate', 0));

        $fee = $this->planFee($plan, $from, $to);
        $commission = $this->commissionFor($tenant, $from, $to, $currency);

        $lines = [];

        if ($fee > 0) {
            $lines[] = [
                'kind' => 'subscription',
                'label' => __('billing.lines.plan', ['plan' => $plan?->name ?? '—']),
                'amount' => $fee,
            ];
        }

        if ($commission['amount'] > 0) {
            $lines[] = [
                'kind' => 'commission',
                'label' => __('billing.lines.commission', [
                    'rate' => number_format($commission['rate'] / 100, 2),
                ]),
                'amount' => $commission['amount'],
                // What the percentage was taken of, so an organiser can check it against their own
                // settlement screen rather than taking the total on trust.
                'basis' => $commission['basis'],
            ];
        }

        $net = $fee + $commission['amount'];
        $tax = 0 === $vatRate ? 0 : intdiv($net * $vatRate + 5000, 10000);

        try {
            $invoice = DB::transaction(fn () => PlatformInvoice::create([
                'tenant_id' => $tenant->id,
                'number' => $this->nextNumber($to),
                'currency' => $currency,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'subscription_amount' => $fee,
                'commission_amount' => $commission['amount'],
                'tax_amount' => $tax,
                'total' => $net + $tax,
                'vat_rate' => $vatRate,
                'lines' => $lines,
                'status' => 'open',
                'due_on' => now()->addDays(max(0, (int) config('seatmap.billing.terms_days', 14)))
                    ->toDateString(),
                'issued_at' => now(),
                // The first collection attempt is made the moment it is raised: an account with a
                // card on file should not wait a fortnight to be charged for something it agreed
                // to pay for.
                'next_attempt_at' => now(),
            ]));

            // Told, not just filed. An organiser who finds out what they owe by opening a screen
            // they had no reason to open finds out late.
            $this->tenants->runAs($tenant, fn () => $this->notifier->raise('billing.invoiced', [
                'number' => $invoice->number,
                'due' => $invoice->due_on?->toDateString(),
            ]));

            return $invoice;
        } catch (QueryException $e) {
            if (! str_contains((string) $e->getMessage(), 'platform_invoices_one_per_period')) {
                throw $e;
            }

            throw ApiException::conflict(
                'period_already_invoiced',
                'Some of those days are already on an invoice.',
                ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            );
        }
    }

    /**
     * Raise whatever this account owes, and mark the subscription up to date.
     *
     * Loops, because an account that nobody billed for four months owes four invoices and not one
     * big one: each month was a different plan price and a different commission, and rolling them
     * together would produce a figure that cannot be checked against anything.
     *
     * @return list<PlatformInvoice>
     */
    public function catchUp(Tenant $tenant, int $limit = 24): array
    {
        $made = [];

        for ($round = 0; $round < $limit; $round++) {
            $subscription = $this->tenants->runAs($tenant, fn () => Subscription::with('plan')
                ->latest('created_at')
                ->first());

            if (! $subscription || ! in_array($subscription->status, ['active', 'trialing', 'past_due'], true)) {
                break;
            }

            // Nothing is billed while an account is still in its trial: that is what a trial is.
            if ($subscription->trial_ends_at && $subscription->trial_ends_at->greaterThan(now())) {
                break;
            }

            $period = $this->periodDue($subscription);

            if (! $period) {
                break;
            }

            [$from, $to] = $period;

            $made[] = $this->raise($tenant, $from, $to);

            $this->tenants->runAs($tenant, fn () => $subscription->forceFill([
                'last_invoiced_to' => $to->copy()->endOfDay(),
                'current_period_start' => $to->copy()->addDay()->startOfDay(),
                'current_period_end' => $this->endOfPeriod($to->copy()->addDay(), $subscription)->endOfDay(),
            ])->save());
        }

        return $made;
    }

    /** Somebody paid it, however they paid it. */
    public function markPaid(PlatformInvoice $invoice, array $details = []): PlatformInvoice
    {
        if ('void' === $invoice->status) {
            throw ApiException::conflict('invoice_is_void', 'A voided invoice cannot be paid.');
        }

        $invoice->forceFill(array_filter([
            'status' => 'paid',
            'paid_at' => ($details['paid_at'] ?? null) ? Carbon::parse($details['paid_at']) : now(),
            'method' => $details['method'] ?? $invoice->method ?? 'transfer',
            'reference' => $details['reference'] ?? $invoice->reference,
            'settled_by' => $details['settled_by'] ?? $invoice->settled_by,
            'next_attempt_at' => null,
            'last_error' => null,
        ], fn ($value) => null !== $value))->save();

        $this->settleOwing($invoice->tenant_id);

        return $invoice->fresh();
    }

    /**
     * Raised in error, so it never counted.
     *
     * Not a delete: an invoice that was sent to somebody is a thing that happened, and its number
     * has been quoted. Voiding frees the days — the constraint ignores void rows — so the right
     * invoice can be raised over the same period.
     */
    public function void(PlatformInvoice $invoice, string $reason): PlatformInvoice
    {
        if ('void' === $invoice->status) {
            throw ApiException::conflict('invoice_already_void', 'That invoice has already been voided.');
        }

        $invoice->forceFill([
            'status' => 'void',
            'void_reason' => $reason,
            'next_attempt_at' => null,
        ])->save();

        $this->settleOwing($invoice->tenant_id);

        return $invoice->fresh();
    }

    /** What one account owes right now, in minor units. */
    public function owed(Tenant|string $tenant): int
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return (int) PlatformInvoice::where('tenant_id', $id)
            ->whereIn('status', ['open', 'uncollectible'])
            ->sum('total');
    }

    /** @return list<array<string, mixed>> */
    public function invoicesFor(Tenant|string $tenant, int $limit = 60): array
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return PlatformInvoice::where('tenant_id', $id)
            ->orderByDesc('period_from')
            ->limit($limit)
            ->get()
            ->map(fn (PlatformInvoice $invoice) => $this->present($invoice))
            ->values()
            ->all();
    }

    public function present(PlatformInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'currency' => $invoice->currency,
            'from' => $invoice->period_from?->toDateString(),
            'to' => $invoice->period_to?->toDateString(),
            'subscription_amount' => $invoice->subscription_amount,
            'commission_amount' => $invoice->commission_amount,
            'tax_amount' => $invoice->tax_amount,
            'vat_rate' => $invoice->vat_rate,
            'total' => $invoice->total,
            'lines' => $invoice->lines ?: [],
            'status' => $invoice->status,
            'overdue' => $invoice->isOverdue(),
            'due_on' => $invoice->due_on?->toDateString(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'method' => $invoice->method,
            'reference' => $invoice->reference,
            'attempts' => $invoice->attempts,
            'last_error' => $invoice->last_error,
            'void_reason' => $invoice->void_reason,
        ];
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * What the plan costs for these days.
     *
     * Whole periods only, at the plan's own price. A part period is not pro-rated, because the only
     * way to bill a part period arises when somebody changes plan mid-month, and guessing which
     * price applied to which week is how an invoice becomes an argument. A plan change closes the
     * period and starts a new one — see {@see catchUp()}.
     */
    private function planFee($plan, Carbon $from, Carbon $to): int
    {
        return max(0, (int) ($plan?->price_amount ?? 0));
    }

    /**
     * The platform's share of what the organiser sold in the period.
     *
     * Read out of the same settlement the organiser reads, so the number on this invoice and the
     * number on their own screen come from one piece of arithmetic and cannot drift apart. Only the
     * billing currency counts: an organiser selling in rial and invoiced in euro would otherwise
     * have two currencies added together, which is not a number anybody can pay.
     *
     * @return array{amount: int, basis: int, rate: int}
     */
    private function commissionFor(Tenant $tenant, Carbon $from, Carbon $to, string $currency): array
    {
        $settlement = $this->tenants->runAs($tenant, fn () => $this->settlement->forPeriod([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'basis' => 'paid',
        ]));

        $total = collect($settlement['totals'])->firstWhere('currency', $currency);

        return [
            'amount' => (int) ($total['commission'] ?? 0),
            'basis' => max(0, (int) ($total['kept'] ?? 0) - (int) ($total['tax_kept'] ?? 0)),
            'rate' => (int) $settlement['commission_rate'],
        ];
    }

    /** The last day of the period that starts on this day, by the plan's interval. */
    private function endOfPeriod(Carbon $from, ?Subscription $subscription): Carbon
    {
        $interval = (string) ($subscription?->plan?->interval ?? 'month');

        return 'year' === $interval
            ? $from->copy()->addYear()->subDay()
            : $from->copy()->addMonth()->subDay();
    }

    /**
     * The next number in the book.
     *
     * Per year and sequential, which is what an accountant expects and what several tax
     * authorities require. Taken under a lock so two runs at the same moment cannot both read
     * "0114" and both write it — the unique index would catch that, but as an error rather than as
     * the next number.
     */
    private function nextNumber(Carbon $to): string
    {
        $year = $to->format('Y');

        return DB::transaction(function () use ($year) {
            $last = PlatformInvoice::where('number', 'like', $year.'-%')
                ->lockForUpdate()
                ->orderByDesc('number')
                ->value('number');

            $next = $last ? ((int) substr((string) $last, 5)) + 1 : 1;

            return $year.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        });
    }

    /**
     * An account with nothing outstanding is not past due any more.
     *
     * Called after every payment and every void, because both are ways an account can come back
     * into good standing, and an account left flagged after it has paid is a support call.
     */
    private function settleOwing(string $tenantId): void
    {
        if (PlatformInvoice::where('tenant_id', $tenantId)->where('status', 'open')->exists()) {
            return;
        }

        Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('past_due_since')
            ->update(['past_due_since' => null, 'status' => 'active', 'updated_at' => now()]);
    }
}
