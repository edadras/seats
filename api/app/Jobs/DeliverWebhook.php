<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Delivers one webhook, signed the same way the API requires of inbound calls, so a receiver can
 * verify us exactly as we verify them.
 *
 * Backoff is explicit rather than delegated to the queue's own retry: the schedule is part of the
 * contract with the receiving site — documented, and visible in the delivery log — not an
 * infrastructure detail that changes with the queue driver.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $deliveryId) {}

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->runUnscoped(function () {
            $delivery = WebhookDelivery::find($this->deliveryId);

            if (! $delivery || in_array($delivery->status, ['delivered', 'dead'], true)) {
                return;
            }

            $endpoint = WebhookEndpoint::find($delivery->webhook_endpoint_id);

            if (! $endpoint || $endpoint->status !== 'active') {
                $delivery->forceFill(['status' => 'dead', 'response_body' => 'Endpoint is not active.'])->save();

                return;
            }

            $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $nonce = (string) Str::uuid();

            $signature = hash_hmac('sha256', implode("\n", [
                'POST',
                parse_url($endpoint->url, PHP_URL_PATH) ?: '/',
                $timestamp,
                $nonce,
                hash('sha256', $body),
            ]), $endpoint->signing_secret);

            $delivery->increment('attempts');
            $delivery->refresh();

            try {
                $response = Http::timeout((int) config('seatmap.webhooks.timeout_seconds'))
                    ->withHeaders([
                        'X-Seatmap-Event' => $delivery->event_type,
                        'X-Seatmap-Delivery' => $delivery->id,
                        'X-Seatmap-Timestamp' => $timestamp,
                        'X-Seatmap-Nonce' => $nonce,
                        'X-Seatmap-Signature' => $signature,
                    ])
                    ->withBody($body, 'application/json')
                    ->post($endpoint->url);

                if ($response->successful()) {
                    $delivery->forceFill([
                        'status' => 'delivered',
                        'response_code' => $response->status(),
                        // Bounded: a receiver returning a megabyte of HTML must not fill the table.
                        'response_body' => Str::limit($response->body(), 2000),
                        'delivered_at' => now(),
                        'next_attempt_at' => null,
                    ])->save();

                    $endpoint->forceFill(['consecutive_failures' => 0])->save();

                    return;
                }

                $this->scheduleRetry($delivery, $endpoint, $response->status(), Str::limit($response->body(), 2000));
            } catch (\Throwable $e) {
                $this->scheduleRetry($delivery, $endpoint, null, Str::limit($e->getMessage(), 2000));
            }
        });
    }

    private function scheduleRetry(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        ?int $status,
        string $body,
    ): void {
        $delays = config('seatmap.webhooks.retry_delays');
        $attempt = (int) $delivery->attempts;

        $endpoint->increment('consecutive_failures');
        $endpoint->refresh();

        // Past the end of the schedule, stop. A dead delivery stays in the log for the tenant to
        // see and replay by hand; dropping it silently would be worse than never sending it.
        if ($attempt >= count($delays)) {
            $delivery->forceFill([
                'status' => 'dead',
                'response_code' => $status,
                'response_body' => $body,
                'next_attempt_at' => null,
            ])->save();

            if ($endpoint->consecutive_failures >= (int) config('seatmap.webhooks.dead_after_failures')) {
                // A site that has been down for hours should stop costing a queue worker per event.
                // The tenant re-enables it once their end is fixed.
                $endpoint->forceFill(['status' => 'dead'])->save();
            }

            return;
        }

        $delay = $delays[$attempt - 1] ?? end($delays);

        $delivery->forceFill([
            'status' => 'pending',
            'response_code' => $status,
            'response_body' => $body,
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        self::dispatch($delivery->id)->delay(now()->addSeconds($delay));
    }
}
