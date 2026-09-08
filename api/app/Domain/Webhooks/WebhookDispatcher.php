<?php

namespace App\Domain\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/**
 * Queues an outbound event to every endpoint a tenant has subscribed.
 *
 * Deliveries are rows first and HTTP second. Writing the attempt down before trying it is what
 * makes "did the customer's site ever hear about this refund?" an answerable question, and what
 * lets a failed delivery be retried later rather than lost with the request that produced it.
 */
class WebhookDispatcher
{
    public function dispatch(string $tenantId, string $eventType, array $payload): void
    {
        $endpoints = WebhookEndpoint::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($eventType));

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create([
                'tenant_id' => $tenantId,
                'webhook_endpoint_id' => $endpoint->id,
                'event_type' => $eventType,
                'payload' => [
                    'event' => $eventType,
                    'occurred_at' => now()->toIso8601String(),
                    'data' => $payload,
                ],
                'status' => 'pending',
                'next_attempt_at' => now(),
            ]);

            DeliverWebhook::dispatch($delivery->id);
        }
    }
}
