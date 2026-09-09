<?php

namespace App\Console\Commands;

use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\OrderMessages;
use App\Models\ExternalOrder;
use App\Models\MessageDelivery;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Send again the messages that could not be sent.
 *
 * Only `unavailable` ones — a provider that said "that is not a mobile number" is not going to
 * change its mind, and asking it again is how a platform gets rate-limited for nothing. A refusal
 * stays refused and stays visible in the log.
 *
 * Attempts are capped. A message that has failed four times is not four times more likely to go on
 * the fifth; at that point somebody has to look at the account.
 */
class RetryMessages extends Command
{
    protected $signature = 'messages:retry {--hours=24 : How far back to look}';

    protected $description = 'Retry messages whose provider could not be reached';

    public function handle(
        TenantContext $tenants,
        MessageDispatcher $dispatcher,
        OrderMessages $orders,
    ): int {
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        $sent = 0;
        $given = 0;

        foreach (Tenant::all() as $tenant) {
            $tenants->runAs($tenant, function () use ($since, $dispatcher, $orders, &$sent, &$given) {
                $stuck = MessageDelivery::where('status', 'unavailable')
                    ->where('created_at', '>=', $since)
                    ->where('attempts', '<', MessageDispatcher::MAX_ATTEMPTS)
                    ->get();

                foreach ($stuck as $delivery) {
                    // Rendered from the order again rather than from the stored preview: the
                    // preview is 200 characters for a human, not a copy of the message.
                    $variables = [];
                    $order = $delivery->external_order_row_id
                        ? ExternalOrder::find($delivery->external_order_row_id)
                        : null;

                    if ($order) {
                        $variables = $orders->variables($order, (string) $delivery->locale);
                    }

                    $after = $dispatcher->retry($delivery, $variables);

                    'sent' === $after->status ? $sent++ : $given++;
                }
            });
        }

        $this->info(sprintf('Retried: %d went, %d still waiting.', $sent, $given));

        return self::SUCCESS;
    }
}
