<?php

namespace App\Domain\Billing;

use App\Domain\Notifications\Notifier;
use App\Models\BillingMethod;
use App\Models\PlatformInvoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Trying again, and knowing when to stop.
 *
 * A card fails for a dozen reasons and most of them fix themselves: a balance that arrives on
 * payday, a bank that was down, a card replaced last week. So an invoice is retried on a ladder
 * rather than once, and the organiser is told each time with a link to the screen that fixes it.
 *
 * What this deliberately does *not* do is cut anybody off. An account whose ladder runs out is
 * marked past due — loudly, on every screen, and by email to whoever pays for the account — and
 * that is where billing stops. Taking a venue's box office down on the night of a show over an
 * unpaid invoice is a decision with a full house on the other end of it, and it belongs to a person
 * in the console, not to a scheduled command. A deployment that disagrees can set
 * `billing.suspend_after_days`; the default is null, which means never.
 *
 * An account that pays by transfer is never dunned for a refusal, because there was no refusal:
 * the invoice is simply owed, and it goes overdue rather than failing. Sending a venue four
 * "payment failed" emails for an invoice nobody tried to charge would be the platform lying about
 * its own behaviour.
 */
class Dunning
{
    public function __construct(
        private readonly Billing $billing,
        private readonly Chargers $chargers,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Try to collect one invoice.
     *
     * Safe to call twice: a charger's own idempotency stops the money moving twice, and an invoice
     * already paid is returned untouched.
     */
    public function collect(PlatformInvoice $invoice): PlatformInvoice
    {
        if (! $invoice->isOpen()) {
            return $invoice;
        }

        $method = $this->methodFor($invoice->tenant_id);
        $charger = $this->chargers->current();

        $outcome = $this->attempt($invoice, $method, $charger);

        $invoice->forceFill([
            'attempts' => $invoice->attempts + 1,
            'last_attempt_at' => now(),
        ])->save();

        if ($outcome->wasPaid()) {
            return $this->billing->markPaid($invoice, [
                'method' => $charger->key(),
                'reference' => $outcome->reference,
            ]);
        }

        /*
         * Nothing to charge.
         *
         * An account paying by transfer, or one with no card yet. The invoice is owed and will go
         * overdue on its own; there is nothing to retry and nothing went wrong, so no ladder, no
         * failure, and no email saying a payment failed that was never attempted.
         */
        if (! $outcome->hasFailed()) {
            $invoice->forceFill(['next_attempt_at' => null])->save();

            return $invoice->fresh();
        }

        return $this->refused($invoice, $outcome->message ?: __('billing.errors.card_refused', ['code' => '—']));
    }

    /**
     * Every invoice that is due another attempt, across every account.
     *
     * `waiting` is not `failed`, and keeping them apart matters: an account that pays by transfer
     * is counted as waiting for somebody to pay, not as a refusal. A run that reported every
     * transfer-paying account as a failure would make its own output useless.
     *
     * @return array{tried: int, paid: int, waiting: int, failed: int}
     */
    public function run(?Carbon $at = null): array
    {
        $at = $at ?: now();
        $counts = ['tried' => 0, 'paid' => 0, 'waiting' => 0, 'failed' => 0];

        PlatformInvoice::where('status', 'open')
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $at)
            ->orderBy('next_attempt_at')
            ->chunkById(100, function ($batch) use (&$counts) {
                foreach ($batch as $invoice) {
                    try {
                        $counts['tried']++;

                        $before = $invoice->attempts;
                        $after = $this->collect($invoice);

                        if ('paid' === $after->status) {
                            $counts['paid']++;
                        } elseif (null === $after->last_error || $after->attempts === $before) {
                            // Nothing was charged and nothing refused: it is owed and waiting.
                            $counts['waiting']++;
                        } else {
                            $counts['failed']++;
                        }
                    } catch (Throwable $e) {
                        // One account that will not settle must not stop the rest. Written down
                        // against that invoice and left for somebody to look at.
                        report($e);

                        $counts['failed']++;

                        $invoice->forceFill([
                            'last_error' => __('billing.errors.unreachable'),
                            'next_attempt_at' => $this->nextAttempt($invoice->attempts + 1),
                        ])->save();
                    }
                }
            });

        return $counts;
    }

    /**
     * Accounts past due for longer than a deployment is willing to carry.
     *
     * Returns them rather than suspending them, unless the deployment has asked for suspension
     * outright. Whoever runs the platform decides what to do with the list; the list itself is the
     * thing that was missing.
     *
     * @return list<Tenant>
     */
    public function overdueAccounts(): array
    {
        $days = config('seatmap.billing.suspend_after_days');

        $cutoff = null === $days ? null : now()->subDays(max(0, (int) $days));

        return Subscription::withoutGlobalScopes()
            ->whereNotNull('past_due_since')
            ->when($cutoff, fn ($query) => $query->where('past_due_since', '<=', $cutoff))
            ->get()
            ->map(fn (Subscription $subscription) => Tenant::find($subscription->tenant_id))
            ->filter()
            ->values()
            ->all();
    }

    /* --------------------------------------------------------------------------- internals */

    private function attempt(PlatformInvoice $invoice, ?BillingMethod $method, PlatformCharger $charger): ChargeOutcome
    {
        if (! $method || ! $method->isCard()) {
            return ChargeOutcome::unsupported(__('billing.errors.no_card_on_file'));
        }

        try {
            return $charger->charge($invoice, $method);
        } catch (Throwable $e) {
            /*
             * A gateway that threw rather than answered.
             *
             * Treated as a refusal so the ladder carries it, because the one thing that must not
             * happen is marking an invoice paid on the strength of a request that may never have
             * arrived.
             */
            report($e);

            return ChargeOutcome::failed(__('billing.errors.unreachable'));
        }
    }

    private function refused(PlatformInvoice $invoice, string $message): PlatformInvoice
    {
        $attempts = $invoice->attempts;
        $next = $this->nextAttempt($attempts);

        $invoice->forceFill([
            'last_error' => $message,
            'next_attempt_at' => $next,
            'status' => $next ? 'open' : 'uncollectible',
        ])->save();

        $tenant = Tenant::find($invoice->tenant_id);

        if ($tenant) {
            $this->tenants->runAs($tenant, function () use ($invoice, $message, $next) {
                $this->notifier->raise($next ? 'billing.payment_failed' : 'billing.past_due', [
                    'number' => $invoice->number,
                    'reason' => $message,
                ]);
            });
        }

        if (! $next) {
            $this->markPastDue($invoice->tenant_id);
        }

        return $invoice->fresh();
    }

    /**
     * When to try again, or null when the ladder is finished.
     *
     * Days rather than minutes, and spread: a card refused for no money at nine in the morning is
     * refused for no money at nine fifteen, and four attempts inside an hour is how a platform gets
     * its merchant account reviewed.
     */
    private function nextAttempt(int $attemptsMade): ?Carbon
    {
        $ladder = (array) config('seatmap.billing.retry_days', [0, 3, 7, 14]);

        // The first entry is the attempt already made when the invoice was raised.
        $nextIndex = $attemptsMade;

        if (! isset($ladder[$nextIndex])) {
            return null;
        }

        $previous = (int) ($ladder[$nextIndex - 1] ?? 0);

        return now()->addDays(max(1, (int) $ladder[$nextIndex] - $previous))->startOfHour();
    }

    private function markPastDue(string $tenantId): void
    {
        Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('past_due_since')
            ->update(['past_due_since' => now(), 'status' => 'past_due', 'updated_at' => now()]);
    }

    private function methodFor(string $tenantId): ?BillingMethod
    {
        return BillingMethod::where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->first();
    }
}
