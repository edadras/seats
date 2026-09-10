<?php

namespace App\Jobs;

use App\Domain\Messaging\OrderMessages;
use App\Domain\Orders\OrderService;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The long half of calling a night off: give the money back, and tell everybody.
 *
 * A job rather than part of the request, because an arena is twenty thousand bookings and a
 * cancellation that times out halfway through the refunds is worse than one that has not started.
 * The request has already stopped the event selling, which is the part that could not wait.
 *
 * Chunked, and each booking is refunded on its own. One order that cannot be refunded — a gateway
 * that will not answer, a row somebody has since edited — must not stop the nineteen thousand
 * behind it, so a failure is logged against that order and the run carries on. The audit entry at
 * the end says how many of each there were, which is the number an organiser has to act on.
 */
class SettleCancelledEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $eventId,
        private readonly string $tenantId,
        private readonly string $reason,
        private readonly bool $refund,
        private readonly bool $notify,
    ) {}

    public function handle(
        TenantContext $tenants,
        OrderService $orders,
        OrderMessages $messages,
        AuditLogger $audit,
    ): void {
        $tenant = $tenants->runUnscoped(fn () => Tenant::find($this->tenantId));

        if (! $tenant) {
            return;
        }

        $tenants->runAs($tenant, function () use ($orders, $messages, $audit) {
            $event = Event::find($this->eventId);

            if (! $event) {
                return;
            }

            $refunded = 0;
            $told = 0;
            $failed = 0;

            ExternalOrder::where('event_id', $event->id)
                ->whereIn('status', ['confirmed', 'partially_refunded'])
                ->orderBy('id')
                ->chunkById(100, function ($batch) use (
                    $orders, $messages, $event, &$refunded, &$told, &$failed
                ) {
                    foreach ($batch as $order) {
                        try {
                            if ($this->refund) {
                                $orders->refund($order, null, 'event_cancelled');
                                $refunded++;
                            }

                            if ($this->notify) {
                                $messages->eventCancelled(
                                    $order->fresh(['event.venue', 'allocations', 'apiClient']),
                                    $this->reason,
                                );
                                $told++;
                            }
                        } catch (\Throwable $e) {
                            // One booking that will not settle must not stop the rest. It is
                            // counted, named in the log, and left for somebody to look at.
                            $failed++;

                            Log::warning('A booking could not be settled on a cancelled event.', [
                                'event' => $event->id,
                                'order' => $order->external_order_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });

            $audit->record('event.cancellation_settled', $event, [
                'event' => $event->name,
                'refunded' => $refunded,
                'told' => $told,
                'failed' => $failed,
            ]);
        });
    }
}
