<?php

namespace App\Console\Commands;

use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\OrderMessages;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\MessageDelivery;
use App\Models\Tenant;
use App\Support\Locale\Locales;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The reminder the night before.
 *
 * Sent once per order, ever, and that is enforced by looking for an existing delivery rather than
 * by a flag on the order: a reminder that goes twice is the message that makes people turn
 * messages off.
 *
 * Off unless the organiser turned it on — connecting an SMS account is not the same as asking to
 * text every buyer the night before.
 */
class SendEventReminders extends Command
{
    protected $signature = 'messages:remind {--within=36 : Hours ahead to look}';

    protected $description = 'Remind ticket holders about events that are about to happen';

    public function handle(
        TenantContext $tenants,
        MessageDispatcher $dispatcher,
        OrderMessages $orders,
    ): int {
        $until = now()->addHours(max(1, (int) $this->option('within')));
        $sent = 0;

        foreach (Tenant::all() as $tenant) {
            $tenants->runAs($tenant, function () use ($until, $dispatcher, $orders, &$sent) {
                if ([] === $dispatcher->enabledChannels('event.reminder')) {
                    return; // Nobody asked for these.
                }

                $events = Event::where('status', 'published')
                    ->whereBetween('starts_at', [now(), $until])
                    ->get();

                foreach ($events as $event) {
                    $confirmed = ExternalOrder::where('event_id', $event->id)
                        ->where('status', 'confirmed')
                        ->with(['allocations', 'event.venue', 'apiClient'])
                        ->get();

                    foreach ($confirmed as $order) {
                        $already = MessageDelivery::where('kind', 'event.reminder')
                            ->where('external_order_row_id', $order->id)
                            ->exists();

                        if ($already) {
                            continue;
                        }

                        $locale = Locales::normalise($order->buyer['locale'] ?? $tenant->locale ?? 'en');

                        $deliveries = $dispatcher->announce(
                            'event.reminder',
                            array_filter([
                                'email' => $order->buyer['email'] ?? null,
                                'phone' => $order->buyer['phone'] ?? null,
                                'handle' => $order->buyer['handle'] ?? null,
                            ]),
                            $orders->variables($order, $locale),
                            $locale,
                            ['event_id' => $event->id, 'order_id' => $order->id],
                        );

                        $sent += count($deliveries);
                    }
                }
            });
        }

        $this->info(sprintf('Reminders sent: %d.', $sent));

        return self::SUCCESS;
    }
}
