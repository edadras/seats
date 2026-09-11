<?php

namespace App\Domain\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Exceptions\ApiException;
use App\Support\Http\OutboundUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * The organiser's side of the webhook subsystem.
 *
 * Everything about *sending* one was already here — the signature, the backoff, the delivery log —
 * and none of it could be reached: there was no way to create an endpoint, see whether it was
 * working, or send a failed delivery again. This is that half.
 *
 * A secret is shown exactly once, the same discipline API keys already have, because the only
 * alternative is a readable store of credentials for other people's servers.
 */
class Webhooks
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public function create(string $name, string $url, array $eventTypes): array
    {
        $secret = self::newSecret();

        $endpoint = WebhookEndpoint::create([
            'tenant_id' => $this->tenants->idOrFail(),
            'name' => $name,
            'url' => self::checkUrl($url),
            'signing_secret' => $secret,
            'event_types' => self::checkTypes($eventTypes),
            'status' => 'active',
        ]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public function update(WebhookEndpoint $endpoint, array $changes): WebhookEndpoint
    {
        $fields = [];

        if (array_key_exists('name', $changes)) {
            $fields['name'] = (string) $changes['name'];
        }

        if (array_key_exists('url', $changes)) {
            $fields['url'] = self::checkUrl((string) $changes['url']);
        }

        if (array_key_exists('event_types', $changes)) {
            $fields['event_types'] = self::checkTypes((array) $changes['event_types']);
        }

        if (array_key_exists('status', $changes)) {
            /*
             * Switching an endpoint back on clears the count that switched it off.
             *
             * Without this an endpoint that died at twenty failures would die again on its first
             * failure after being fixed, and an organiser would conclude the feature does not work
             * — which, from where they are standing, it would not.
             */
            $fields['status'] = 'active' === $changes['status'] ? 'active' : 'paused';
            $fields['consecutive_failures'] = 0;
            $fields['disabled_reason'] = null;
        }

        $endpoint->forceFill($fields)->save();

        return $endpoint->fresh();
    }

    /** A new secret, with the old one dead the moment this returns. */
    public function rotate(WebhookEndpoint $endpoint): string
    {
        $secret = self::newSecret();

        $endpoint->forceFill(['signing_secret' => $secret])->save();

        return $secret;
    }

    /**
     * Send something, now, so an integrator can see a request arrive while they are looking at
     * their own logs.
     *
     * Its type is outside the catalogue on purpose: a receiver switching on a real handler because
     * somebody pressed Test would be a booking that never happened turning into a shipped order.
     */
    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return $this->queue($endpoint, 'webhook.test', [
            'note' => 'This is a test delivery. Nothing happened.',
        ]);
    }

    /**
     * Send a delivery again, as a new one.
     *
     * A new row rather than a reset of the old: what was tried, when, and what came back is the
     * record somebody reads to settle an argument with their own developer, and a replay that
     * overwrote it would destroy the evidence it exists to produce.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        $endpoint = $delivery->endpoint;

        if (! $endpoint) {
            throw ApiException::notFound('That endpoint no longer exists.');
        }

        if ('active' !== $endpoint->status) {
            throw ApiException::conflict('endpoint_not_active', 'Switch the endpoint back on first.');
        }

        return $this->queue($endpoint, $delivery->event_type, $delivery->payload['data'] ?? []);
    }

    /**
     * Pick up deliveries whose moment came and went.
     *
     * Every retry is a delayed job, and a delayed job lives in the queue rather than in the
     * database: a worker restarted at the wrong moment, a Redis that was flushed, and the delivery
     * sits `pending` with a `next_attempt_at` in the past for ever. This is the sweep that makes
     * the table rather than the queue the record of what is still owed.
     */
    public function sweep(int $limit = 500): int
    {
        return $this->tenants->runUnscoped(function () use ($limit) {
            $due = WebhookDelivery::where('status', 'pending')
                ->whereNotNull('next_attempt_at')
                // A minute of slack, so this never races the delayed job it is insuring against.
                ->where('next_attempt_at', '<=', now()->subMinute())
                ->orderBy('next_attempt_at')
                ->limit($limit)
                ->get();

            foreach ($due as $delivery) {
                // Pushed out of the way first, so a sweep that runs twice does not send twice.
                $delivery->forceFill(['next_attempt_at' => now()->addMinutes(10)])->save();

                DeliverWebhook::dispatch($delivery->id);
            }

            return $due->count();
        });
    }

    /** Old deliveries, gone. The endpoint's own last-result columns are what a screen reads. */
    public function prune(): int
    {
        $days = max(1, (int) config('seatmap.webhooks.log_days', 30));

        return $this->tenants->runUnscoped(
            fn () => WebhookDelivery::where('created_at', '<', now()->subDays($days))->delete()
        );
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    private function queue(WebhookEndpoint $endpoint, string $type, array $data): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => $type,
            'payload' => [
                'event' => $type,
                'occurred_at' => now()->toIso8601String(),
                'data' => $data,
            ],
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);

        DeliverWebhook::dispatch($delivery->id);

        return $delivery->fresh();
    }

    private static function checkUrl(string $url): string
    {
        $url = trim($url);

        // Plain http where the destination guard is relaxed, which is development and nowhere
        // else: the receiver on a developer's own machine is not going to have a certificate.
        OutboundUrl::assert($url, ! config('seatmap.webhooks.verify_destination', true));

        return $url;
    }

    /** @return list<string> */
    private static function checkTypes(array $types): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map(fn ($type) => is_string($type) ? $type : '', $types),
            fn (string $type) => WebhookEvents::has($type),
        )));

        if ([] === $clean) {
            throw ApiException::unprocessable(
                'no_events_chosen',
                'Choose at least one event to be told about.'
            );
        }

        return $clean;
    }
}
