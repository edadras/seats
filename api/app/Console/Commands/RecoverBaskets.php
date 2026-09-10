<?php

namespace App\Console\Commands;

use App\Domain\Baskets\Baskets;
use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\OrderMessages;
use App\Models\BasketRecovery;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Write once to the buyers who got as far as their own name and then stopped.
 *
 * Deliberately *after* `payments:reconcile`, and by a longer delay than that job's interval: a
 * buyer who paid and closed the tab has not abandoned anything, and telling them their booking is
 * unfinished when their card has already been charged would be the worst message this platform
 * could send. By the time an order is an hour old the gateway has been asked several times, so a
 * purchase still sitting at `pending` really is one nobody completed.
 *
 * One message per basket, for ever, enforced by a unique index rather than by this loop
 * remembering. Being written to twice about the same abandoned cart is how a buyer unsubscribes.
 */
class RecoverBaskets extends Command
{
    protected $signature = 'baskets:recover
        {--after=60 : Minutes an order must have sat unpaid before we write}
        {--limit=200 : Most baskets to take in one pass, per account}';

    protected $description = 'Write once to buyers whose payment never finished';

    public function handle(
        TenantContext $tenants,
        Baskets $baskets,
        OrderMessages $messages,
        MessageDispatcher $dispatcher,
    ): int {
        $after = max(0, (int) $this->option('after'));
        $limit = max(1, (int) $this->option('limit'));
        $written = 0;
        $closed = 0;

        foreach (Tenant::all() as $tenant) {
            $tenants->runAs($tenant, function () use (
                $tenant, $after, $limit, $baskets, $messages, $dispatcher, &$written, &$closed
            ) {
                $closed += $baskets->expire();

                /*
                 * Whether this account wants the message at all.
                 *
                 * The kind is optional and optional means off until somebody says otherwise, so an
                 * account that has not asked for it gets its baskets *noticed* — they appear on the
                 * screen, and can be written to by hand — and nothing sent. Writing to an
                 * organiser's buyers on their behalf without being asked would be spending their
                 * reputation rather than this platform's.
                 */
                $on = [] !== $dispatcher->enabledChannels('order.unfinished');

                foreach ($baskets->abandoned($after, $limit) as $order) {
                    try {
                        $written += $this->write($baskets, $messages, $order, $on) ? 1 : 0;
                    } catch (Throwable $e) {
                        // One unsendable message must not stop the rest. The recovery row is
                        // already written, so this basket will not be picked up again — which is
                        // right: a send that failed is a send, and retrying is `messages:retry`.
                        $this->warn(sprintf(
                            '  %s %s could not be written to: %s',
                            $tenant->slug,
                            $order->external_order_id,
                            $e->getMessage(),
                        ));
                    }
                }
            });
        }

        $this->info(sprintf('%d written to, %d closed.', $written, $closed));

        return self::SUCCESS;
    }

    private function write(
        Baskets $baskets,
        OrderMessages $messages,
        ExternalOrder $order,
        bool $on,
    ): bool {
        $recovery = $baskets->open($order);

        if (! $recovery) {
            return false;
        }

        if (! $on) {
            // Noticed and left waiting, which is exactly what the screen should show: a basket the
            // organiser can see and can still write to by hand.
            return false;
        }

        $site = $recovery->site_id ? Site::find($recovery->site_id) : null;

        if (! $site) {
            // Nowhere to send them back to. The row stays, marked, so the organiser can see the
            // basket was noticed and why nothing was sent.
            $recovery->forceFill(['status' => 'expired'])->save();

            return false;
        }

        $messages->unfinished(
            $order,
            $site->url('/basket/'.$recovery->token),
            $site->url('/basket/'.$recovery->token.'/no-thanks'),
        );

        $recovery->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        $this->line(sprintf('  %s → %s', $order->external_order_id, $recovery->email));

        return true;
    }
}
