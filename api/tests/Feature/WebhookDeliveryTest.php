<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function confirming_an_order_queues_a_signed_delivery(): void
    {
        $ctx = $this->sellableOrder('wc_7001');
        $endpoint = $this->subscribe($ctx['tenant']);

        Http::fake(['*' => Http::response('', 200)]);

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_7001/confirm')->assertOk();

        $delivery = $this->asTenant($ctx['tenant'], fn () => WebhookDelivery::first());

        $this->assertNotNull($delivery);
        $this->assertSame('order.confirmed', $delivery->event_type);
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame('wc_7001', $delivery->payload['data']['external_order_id']);

        Http::assertSent(function ($request) use ($endpoint) {
            // Signed exactly as we require of callers, so a receiver can verify us the same way.
            $expected = hash_hmac('sha256', implode("\n", [
                'POST',
                parse_url($endpoint->url, PHP_URL_PATH),
                $request->header('X-Seatmap-Timestamp')[0],
                $request->header('X-Seatmap-Nonce')[0],
                hash('sha256', $request->body()),
            ]), 'endpoint-secret');

            return hash_equals($expected, $request->header('X-Seatmap-Signature')[0]);
        });
    }

    #[Test]
    public function a_failing_endpoint_is_retried_on_the_documented_schedule(): void
    {
        $ctx = $this->sellableOrder('wc_7002');
        $endpoint = $this->subscribe($ctx['tenant']);

        Http::fake(['*' => Http::response('upstream is down', 503)]);

        $delivery = $this->asTenant($ctx['tenant'], fn () => WebhookDelivery::create([
            'tenant_id' => $ctx['tenant']->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => 'order.confirmed',
            'payload' => ['event' => 'order.confirmed', 'data' => []],
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]));

        // Faked only now, and the job is invoked directly: on the sync queue the follow-up dispatch
        // would run inline and burn the whole retry schedule in one call, which is not what a real
        // queue does and not what this test is about.
        Queue::fake();

        (new DeliverWebhook($delivery->id))->handle(app(\App\Support\Tenancy\TenantContext::class));

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status, 'One failure is not the end of the road.');
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(503, $delivery->response_code);
        $this->assertStringContainsString('upstream is down', $delivery->response_body);

        $delays = config('seatmap.webhooks.retry_delays');
        $this->assertTrue($delivery->next_attempt_at->isFuture());
        $this->assertEqualsWithDelta(
            $delays[0],
            now()->diffInSeconds($delivery->next_attempt_at),
            2,
            'The first retry should follow the documented backoff.'
        );

        Queue::assertPushed(DeliverWebhook::class);

        $endpoint->refresh();
        $this->assertSame(1, $endpoint->consecutive_failures);
    }

    #[Test]
    public function a_delivery_goes_dead_once_the_schedule_is_exhausted(): void
    {
        $ctx = $this->sellableOrder('wc_7003');
        $endpoint = $this->subscribe($ctx['tenant']);

        Http::fake(['*' => Http::response('nope', 500)]);

        $delivery = $this->asTenant($ctx['tenant'], fn () => WebhookDelivery::create([
            'tenant_id' => $ctx['tenant']->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => 'order.confirmed',
            'payload' => ['event' => 'order.confirmed', 'data' => []],
            'status' => 'pending',
            // One short of the end of the schedule.
            'attempts' => count(config('seatmap.webhooks.retry_delays')) - 1,
            'next_attempt_at' => now(),
        ]));

        (new DeliverWebhook($delivery->id))->handle(app(\App\Support\Tenancy\TenantContext::class));

        $delivery->refresh();

        $this->assertSame('dead', $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        // Kept, not deleted: the tenant needs to see what failed and be able to replay it.
        $this->assertSame('nope', $delivery->response_body);
    }

    #[Test]
    public function a_delivered_webhook_is_never_sent_twice(): void
    {
        $ctx = $this->sellableOrder('wc_7004');
        $endpoint = $this->subscribe($ctx['tenant']);

        Http::fake(['*' => Http::response('', 200)]);

        $delivery = $this->asTenant($ctx['tenant'], fn () => WebhookDelivery::create([
            'tenant_id' => $ctx['tenant']->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => 'order.confirmed',
            'payload' => ['event' => 'order.confirmed', 'data' => []],
            'status' => 'delivered',
            'delivered_at' => now(),
        ]));

        // A queue redelivering a job it already ran must not fire the customer's webhook again.
        (new DeliverWebhook($delivery->id))->handle(app(\App\Support\Tenancy\TenantContext::class));

        Http::assertNothingSent();
    }

    #[Test]
    public function an_endpoint_only_receives_the_event_types_it_subscribed_to(): void
    {
        $ctx = $this->sellableOrder('wc_7005');
        $this->subscribe($ctx['tenant'], ['order.refunded']);

        Http::fake(['*' => Http::response('', 200)]);

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_7005/confirm')->assertOk();

        $this->asTenant($ctx['tenant'], fn () => $this->assertSame(0, WebhookDelivery::count()));

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_7005/refund')->assertOk();

        $this->asTenant($ctx['tenant'], function () {
            $this->assertSame(1, WebhookDelivery::count());
            $this->assertSame('order.refunded', WebhookDelivery::first()->event_type);
        });
    }

    #[Test]
    public function one_tenants_endpoint_never_receives_another_tenants_events(): void
    {
        $listener = $this->makeTenant('Listener');
        $this->subscribe($listener);

        $ctx = $this->sellableOrder('wc_7006');

        Http::fake(['*' => Http::response('', 200)]);

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_7006/confirm')->assertOk();

        $this->asTenant($listener, fn () => $this->assertSame(
            0, WebhookDelivery::count(), 'A tenant must not be told about another tenant\'s sales.'
        ));
    }

    private function subscribe($tenant, array $types = []): WebhookEndpoint
    {
        return $this->asTenant($tenant, fn () => WebhookEndpoint::create([
            'tenant_id' => $tenant->id,
            'url' => 'https://shop.example.test/wp-json/seatmap/v1/webhook',
            'signing_secret' => 'endpoint-secret',
            'event_types' => $types,
            'status' => 'active',
        ]));
    }
}
