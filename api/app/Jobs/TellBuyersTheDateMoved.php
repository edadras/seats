<?php

namespace App\Jobs;

use App\Domain\Messaging\OrderMessages;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A date moved: everybody holding a ticket is told, and told that their ticket still works.
 *
 * Nothing is refunded and nothing is voided — that is the whole difference between a move and a
 * cancellation, and it is why this is a job of its own rather than a flag on that one.
 */
class TellBuyersTheDateMoved implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $eventId,
        private readonly string $tenantId,
        private readonly string $was,
        private readonly string $reason,
    ) {}

    public function handle(TenantContext $tenants, OrderMessages $messages, AuditLogger $audit): void
    {
        $tenant = $tenants->runUnscoped(fn () => Tenant::find($this->tenantId));

        if (! $tenant) {
            return;
        }

        $tenants->runAs($tenant, function () use ($messages, $audit) {
            $event = Event::find($this->eventId);

            if (! $event) {
                return;
            }

            $was = Carbon::parse($this->was);
            $told = 0;

            ExternalOrder::where('event_id', $event->id)
                ->whereIn('status', ['confirmed', 'partially_refunded'])
                ->orderBy('id')
                ->chunkById(100, function ($batch) use ($messages, $was, $event, &$told) {
                    foreach ($batch as $order) {
                        try {
                            $messages->eventMoved(
                                $order->fresh(['event.venue', 'allocations', 'apiClient']),
                                $was,
                                $this->reason,
                            );
                            $told++;
                        } catch (\Throwable $e) {
                            Log::warning('A buyer could not be told the date moved.', [
                                'event' => $event->id,
                                'order' => $order->external_order_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });

            $audit->record('event.move_announced', $event, [
                'event' => $event->name,
                'told' => $told,
            ]);
        });
    }
}
