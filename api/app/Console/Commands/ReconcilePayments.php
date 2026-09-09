<?php

namespace App\Console\Commands;

use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\StorefrontCheckout;
use App\Models\ExternalOrder;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the gateways about orders that are still waiting.
 *
 * A buyer who pays and then closes the tab never comes back through the return URL, and without
 * this their money has moved and their order has not. The seats are protected either way — the
 * hold expires and they go back on sale — but the buyer has paid for nothing, and finding that out
 * from a support email is not good enough.
 *
 * So every pending hosted order gets asked about, through the gateway that started it, using the
 * reference written down when it began. `settle()` is idempotent by contract, so asking twice is
 * safe; that is the same property the return URL relies on.
 *
 * Orders older than the window are left alone: a gateway that has not settled in a day is not
 * about to, and re-asking forever is how a cron job becomes a load problem.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--hours=24 : How far back to look}';

    protected $description = 'Settle hosted orders whose buyer never came back from the gateway';

    public function handle(
        TenantContext $tenants,
        StorefrontCheckout $checkout,
        GatewayRegistry $gateways,
    ): int {
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        $settled = 0;
        $failed = 0;

        foreach (Tenant::all() as $tenant) {
            $tenants->runAs($tenant, function () use (
                $tenant, $since, $checkout, $gateways, &$settled, &$failed
            ) {
                $pending = ExternalOrder::where('status', 'pending')
                    ->where('created_at', '>=', $since)
                    ->get()
                    ->filter(fn (ExternalOrder $order) => ! empty($order->metadata['payment_reference']));

                foreach ($pending as $order) {
                    $gatewayKey = (string) ($order->metadata['gateway'] ?? '');

                    if ('' === $gatewayKey || ! $gateways->has($gatewayKey)) {
                        continue;
                    }

                    try {
                        $before = $order->status;
                        $order = $checkout->settle($order, $gatewayKey, []);

                        if ($order->status !== $before) {
                            'confirmed' === $order->status ? $settled++ : $failed++;

                            $this->line(sprintf(
                                '  %s %s → %s',
                                $tenant->slug,
                                $order->external_order_id,
                                $order->status
                            ));
                        }
                    } catch (Throwable $e) {
                        // One unreachable gateway must not stop the rest: the next run will try
                        // this order again, which is the whole point of the job being idempotent.
                        $this->warn(sprintf(
                            '  %s %s could not be reconciled: %s',
                            $tenant->slug,
                            $order->external_order_id,
                            $e->getMessage()
                        ));
                    }
                }
            });
        }

        $this->info(sprintf('Reconciled: %d confirmed, %d closed as unpaid.', $settled, $failed));

        return self::SUCCESS;
    }
}
