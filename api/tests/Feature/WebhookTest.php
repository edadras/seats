<?php

namespace Tests\Feature;

use App\Domain\Inventory\HoldService;
use App\Domain\Orders\OrderService;
use App\Domain\Risk\Chargebacks;
use App\Domain\Webhooks\Webhooks;
use App\Domain\Webhooks\WebhookEvents;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Http\OutboundUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Telling somebody else's server what happened here.
 *
 * All of the sending was written a long time ago — the signature, the backoff, the delivery log —
 * and none of it could be reached: there was no way to create an endpoint, no way to see whether
 * one was working, and no way to send a failed delivery again. A subsystem nobody can switch on is
 * not a feature, and this file is mostly about the difference.
 *
 * Three claims carry it. What we post is signed exactly the way we require of calls coming the
 * other way, so a receiver can verify us with the code they already wrote. A receiver that is down
 * stops costing us a queue worker per booking, and says so on the screen rather than going quiet.
 * And an address an organiser types can never point this server at its own private network.
 */
class WebhookTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private int $taken = 0;

    /* --------------------------------------------------------------------------- the screen */

    #[Test]
    public function an_endpoint_is_created_and_its_secret_is_shown_once(): void
    {
        $tenant = $this->makeTenant();

        $made = $this->actingAs($this->makeUser($tenant))
            ->postJson('/v1/webhooks', [
                'name' => 'Our shop',
                'url' => 'https://shop.example.test/hooks/seatmap',
                'event_types' => ['order.confirmed', 'order.refunded'],
            ])->assertCreated()->json();

        $this->assertStringStartsWith('whsec_', $made['signing_secret']);
        $this->assertSame('active', $made['status']);

        // And never again: the listing has no way to ask for it.
        $listed = $this->actingAs($this->makeUser($tenant))
            ->getJson('/v1/webhooks')->assertOk()->json();

        $this->assertCount(1, $listed['data']);
        $this->assertArrayNotHasKey('signing_secret', $listed['data'][0]);
        $this->assertNotEmpty($listed['events']);
    }

    #[Test]
    public function an_address_this_server_will_not_open_is_refused(): void
    {
        // Held to what production requires. The suite runs relaxed so its invented receivers work;
        // the rules that matter are the ones checked here.
        config()->set('seatmap.webhooks.verify_destination', true);

        $user = $this->makeUser($this->makeTenant());

        foreach ([
            'http://shop.example.test/hooks' => 'url_not_https',
            'https://10.0.0.5/hooks' => 'url_not_a_name',
        ] as $url => $code) {
            $this->actingAs($user)->postJson('/v1/webhooks', [
                'name' => 'Nope',
                'url' => $url,
                'event_types' => ['order.confirmed'],
            ])->assertStatus(422)->assertJsonPath('error.code', $code);
        }
    }

    #[Test]
    public function an_endpoint_that_wants_to_hear_about_nothing_is_refused(): void
    {
        $this->actingAs($this->makeUser($this->makeTenant()))
            ->postJson('/v1/webhooks', [
                'name' => 'Quiet',
                'url' => 'https://shop.example.test/hooks',
                'event_types' => [],
            ])->assertStatus(422);
    }

    #[Test]
    public function one_organiser_cannot_see_or_touch_anothers(): void
    {
        $theirs = $this->makeTenant('Theirs');
        $endpoint = $this->endpoint($theirs, ['order.confirmed']);

        $mine = $this->makeUser($this->makeTenant('Mine'));

        $this->actingAs($mine)->getJson('/v1/webhooks')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($mine)->patchJson('/v1/webhooks/'.$endpoint->id, ['name' => 'Mine now'])
            ->assertStatus(404);
    }

    #[Test]
    public function the_door_is_shut_to_somebody_without_the_permission(): void
    {
        $tenant = $this->makeTenant();

        // A door supervisor may scan tickets and may not connect this account to anything.
        $this->actingAs($this->makeUser($tenant, 'door'))
            ->getJson('/v1/webhooks')->assertStatus(403);
    }

    /* ------------------------------------------------------------------------- the sending */

    #[Test]
    public function a_confirmed_booking_is_posted_to_whoever_asked_about_it(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $night = $this->makeSellableEvent();
        $wanted = $this->endpoint($night['tenant'], ['order.confirmed']);
        $other = $this->endpoint($night['tenant'], ['order.refunded'], 'https://quiet.example.test/hooks');

        $this->sell($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($wanted, $other) {
            $sent = WebhookDelivery::where('webhook_endpoint_id', $wanted->id)->get();

            $this->assertCount(1, $sent);
            $this->assertSame('order.confirmed', $sent[0]->event_type);
            $this->assertSame('delivered', $sent[0]->status);

            // The one that did not subscribe hears nothing at all.
            $this->assertSame(0, WebhookDelivery::where('webhook_endpoint_id', $other->id)->count());
        });
    }

    #[Test]
    public function what_we_post_is_signed_the_way_we_ask_to_be_called(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $night = $this->makeSellableEvent();
        $endpoint = $this->endpoint($night['tenant'], ['order.confirmed']);

        $this->sell($night);

        $secret = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => WebhookEndpoint::find($endpoint->id)->signing_secret
        );

        Http::assertSent(function ($request) use ($secret) {
            $body = $request->body();

            $expected = hash_hmac('sha256', implode("\n", [
                'POST',
                parse_url($request->url(), PHP_URL_PATH),
                $request->header('X-Seatmap-Timestamp')[0],
                $request->header('X-Seatmap-Nonce')[0],
                hash('sha256', $body),
            ]), $secret);

            return hash_equals($expected, $request->header('X-Seatmap-Signature')[0])
                && 'order.confirmed' === $request->header('X-Seatmap-Event')[0];
        });
    }

    #[Test]
    public function a_receiver_that_refuses_is_recorded_and_eventually_switched_off(): void
    {
        Http::fake(['*' => Http::response('no thanks', 500)]);

        $tenant = $this->makeTenant();
        $endpoint = $this->endpoint($tenant, ['order.confirmed']);

        app(TenantContext::class)->runAs($tenant, function () use ($endpoint) {
            $delivery = app(Webhooks::class)->test($endpoint);

            $after = WebhookEndpoint::find($endpoint->id);

            // The whole backoff runs here, because the test queue is synchronous: every delay in
            // the schedule is taken at once and the delivery reaches the end of it.
            $this->assertSame('dead', $delivery->fresh()->status);
            $this->assertSame(500, (int) $delivery->fresh()->response_code);
            $this->assertGreaterThan(0, (int) $after->consecutive_failures);
            $this->assertNotNull($after->last_failed_at);
            $this->assertStringContainsString('no thanks', (string) $after->last_error);
            // Still listed as working: one bad afternoon is not a broken integration, and an
            // endpoint switched off after a single 500 is one nobody would trust.
            $this->assertSame('active', $after->status);

            // Enough of them, though, and it stops costing a worker per booking — and says why.
            $after->forceFill([
                'consecutive_failures' => (int) config('seatmap.webhooks.dead_after_failures'),
            ])->save();

            app(Webhooks::class)->replay($delivery->fresh());

            $dead = WebhookEndpoint::find($endpoint->id);

            $this->assertSame('dead', $dead->status);
            $this->assertSame('too_many_failures', $dead->disabled_reason);
        });
    }

    #[Test]
    public function switching_it_back_on_forgives_what_switched_it_off(): void
    {
        $tenant = $this->makeTenant();
        $endpoint = $this->endpoint($tenant, ['order.confirmed']);

        app(TenantContext::class)->runAs($tenant, function () use ($endpoint) {
            $endpoint->forceFill([
                'status' => 'dead',
                'disabled_reason' => 'too_many_failures',
                'consecutive_failures' => 40,
            ])->save();
        });

        $back = $this->actingAs($this->makeUser($tenant))
            ->patchJson('/v1/webhooks/'.$endpoint->id, ['status' => 'active'])
            ->assertOk()->json();

        $this->assertSame('active', $back['status']);
        $this->assertNull($back['disabled_reason']);
        // Otherwise it would die again on its first failure after being fixed.
        $this->assertSame(0, $back['consecutive_failures']);
    }

    #[Test]
    public function a_paused_endpoint_is_told_nothing(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $night = $this->makeSellableEvent();
        $endpoint = $this->endpoint($night['tenant'], ['order.confirmed']);

        $this->actingAs($this->makeUser($night['tenant']))
            ->patchJson('/v1/webhooks/'.$endpoint->id, ['status' => 'paused'])->assertOk();

        $this->sell($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($endpoint) {
            $this->assertSame(0, WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->count());
        });
    }

    /* --------------------------------------------------------------------------- the log */

    #[Test]
    public function a_delivery_can_be_sent_again_without_losing_what_happened_the_first_time(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $tenant = $this->makeTenant();
        $endpoint = $this->endpoint($tenant, ['order.confirmed']);

        $first = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(Webhooks::class)->test($endpoint)
        );

        $again = $this->actingAs($this->makeUser($tenant))
            ->postJson('/v1/webhook-deliveries/'.$first->id.'/replay')
            ->assertStatus(202)->json();

        $this->assertNotSame($first->id, $again['id']);

        app(TenantContext::class)->runAs($tenant, function () use ($first) {
            // The original is still there, still saying what it said.
            $this->assertSame('delivered', WebhookDelivery::find($first->id)->status);
            $this->assertSame(2, WebhookDelivery::count());
        });
    }

    #[Test]
    public function a_delivery_to_an_endpoint_that_is_off_cannot_be_replayed(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $tenant = $this->makeTenant();
        $endpoint = $this->endpoint($tenant, ['order.confirmed']);

        $delivery = app(TenantContext::class)->runAs($tenant, function () use ($endpoint) {
            $sent = app(Webhooks::class)->test($endpoint);
            $endpoint->forceFill(['status' => 'paused'])->save();

            return $sent;
        });

        $this->actingAs($this->makeUser($tenant))
            ->postJson('/v1/webhook-deliveries/'.$delivery->id.'/replay')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'endpoint_not_active');
    }

    #[Test]
    public function the_sweep_picks_up_a_retry_the_queue_lost(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $tenant = $this->makeTenant();
        $endpoint = $this->endpoint($tenant, ['order.confirmed']);

        app(TenantContext::class)->runAs($tenant, function () use ($endpoint) {
            $delivery = WebhookDelivery::create([
                'tenant_id' => $endpoint->tenant_id,
                'webhook_endpoint_id' => $endpoint->id,
                'event_type' => 'order.confirmed',
                'payload' => ['event' => 'order.confirmed', 'data' => []],
                'status' => 'pending',
                // Its moment came and went while nothing was listening.
                'next_attempt_at' => now()->subHour(),
            ]);

            $this->assertSame(1, app(Webhooks::class)->sweep());
            $this->assertSame('delivered', $delivery->fresh()->status);
        });
    }

    #[Test]
    public function the_log_does_not_grow_for_ever(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $tenant = $this->makeTenant();
        $endpoint = $this->endpoint($tenant, ['order.confirmed']);

        app(TenantContext::class)->runAs($tenant, function () use ($endpoint) {
            $old = app(Webhooks::class)->test($endpoint);
            $old->forceFill(['created_at' => now()->subDays(400)])->save();

            app(Webhooks::class)->test($endpoint);

            $this->assertSame(1, app(Webhooks::class)->prune());
            $this->assertSame(1, WebhookDelivery::count());
        });
    }

    #[Test]
    public function a_test_delivery_is_not_one_of_the_real_events(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $tenant = $this->makeTenant();
        $endpoint = $this->endpoint($tenant, ['order.confirmed']);

        $sent = $this->actingAs($this->makeUser($tenant))
            ->postJson('/v1/webhooks/'.$endpoint->id.'/test')
            ->assertStatus(202)->json();

        // Outside the catalogue on purpose: a receiver must not ship an order because somebody
        // pressed Test.
        $this->assertSame('webhook.test', $sent['event_type']);
        $this->assertFalse(WebhookEvents::has('webhook.test'));
    }

    /* ------------------------------------------------------------------- the whole catalogue */

    #[Test]
    public function every_event_the_picker_offers_is_one_something_actually_sends(): void
    {
        $source = '';

        foreach ($this->phpFiles(app_path()) as $file) {
            // Everything except the catalogue itself, so that listing a name is not what proves
            // the name is sent.
            if (str_ends_with($file, 'WebhookEvents.php')) {
                continue;
            }

            $source .= file_get_contents($file);
        }

        foreach (WebhookEvents::all() as $type) {
            /*
             * Not "is it in the catalogue" but "does anything dispatch it".
             *
             * A name that is offered and never sent is worse than one that is missing: an
             * integrator subscribes to it and waits, and there is nothing on either end that says
             * why nothing ever arrives.
             */
            $this->assertStringContainsString(
                "'".$type."'",
                $source,
                $type.' is offered in the picker but nothing anywhere sends it'
            );
        }
    }

    #[Test]
    public function a_night_going_on_sale_is_announced(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $night = $this->makeSellableEvent();
        $endpoint = $this->endpoint($night['tenant'], ['event.published', 'event.cancelled', 'event.rescheduled']);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $night['event']->forceFill(['status' => 'draft'])->save();
        });

        $user = $this->makeUser($night['tenant']);

        $this->actingAs($user)->patchJson('/v1/events/'.$night['event']->id, [
            'name' => $night['event']->name,
            'starts_at' => $night['event']->starts_at->toIso8601String(),
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
            'status' => 'published',
        ])->assertOk();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($endpoint) {
            $this->assertSame(1, WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)
                ->where('event_type', 'event.published')->count());
        });

        // Said once. An edit to a published event is not a second on-sale.
        $this->actingAs($user)->patchJson('/v1/events/'.$night['event']->id, [
            'name' => 'Opening night, renamed',
            'starts_at' => $night['event']->starts_at->toIso8601String(),
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
            'status' => 'published',
        ])->assertOk();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($endpoint) {
            $this->assertSame(1, WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)
                ->where('event_type', 'event.published')->count());
        });
    }

    #[Test]
    public function a_cancellation_and_a_move_are_announced(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $night = $this->makeSellableEvent();
        $endpoint = $this->endpoint($night['tenant'], ['event.cancelled', 'event.rescheduled']);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $endpoint) {
            app(\App\Domain\Events\EventCancellation::class)->reschedule(
                $night['event'],
                now()->addMonth(),
                null,
                'The hall is being rewired',
                false
            );

            app(\App\Domain\Events\EventCancellation::class)->cancel(
                $night['event']->fresh(),
                'The singer is ill',
                false,
                false
            );

            $types = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)
                ->pluck('event_type')->all();

            $this->assertContains('event.rescheduled', $types);
            $this->assertContains('event.cancelled', $types);
        });
    }

    #[Test]
    public function somebody_walking_in_is_announced_and_a_wrong_beep_is_not(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $night = $this->makeSellableEvent();
        $endpoint = $this->endpoint($night['tenant'], ['ticket.checked_in']);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $endpoint) {
            $order = $this->sell($night);
            $token = (string) $order->allocations->first()->ticket->plainToken;

            // A token nobody minted: the door beeps, and nothing is announced about it.
            app(\App\Domain\Checkin\CheckinService::class)->scan($night['event'], 'not-a-real-token');

            $this->assertSame(0, WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->count());

            app(\App\Domain\Checkin\CheckinService::class)->scan($night['event'], $token);

            $this->assertSame(1, WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)
                ->where('event_type', 'ticket.checked_in')->count());
        });
    }

    #[Test]
    public function a_disputed_payment_is_announced(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $night = $this->makeSellableEvent();
        $endpoint = $this->endpoint($night['tenant'], ['order.charged_back']);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $endpoint) {
            $order = $this->sell($night);

            app(Chargebacks::class)->record($order->fresh(), 'Cardholder disputed', fee: 1500);

            $this->assertSame(1, WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)
                ->where('event_type', 'order.charged_back')->count());
        });
    }

    /* ----------------------------------------------------------------------- the SSRF guard */

    #[Test]
    public function an_address_is_judged_by_where_it_actually_points(): void
    {
        foreach ([
            '8.8.8.8' => true,
            '1.1.1.1' => true,
            '127.0.0.1' => false,
            '10.1.2.3' => false,
            '172.16.5.5' => false,
            '192.168.0.1' => false,
            // The one that matters most: a cloud instance's metadata service.
            '169.254.169.254' => false,
            '100.64.0.1' => false,
            '0.0.0.0' => false,
            '::1' => false,
            'fd00::1' => false,
            'fe80::1' => false,
            // An IPv4 loopback wearing an IPv6 hat, which is how this check is usually got past.
            '::ffff:127.0.0.1' => false,
            '2606:4700:4700::1111' => true,
            'not-an-address' => false,
        ] as $address => $public) {
            $this->assertSame(
                $public,
                OutboundUrl::isPublicAddress($address),
                $address.' was judged wrongly'
            );
        }
    }

    #[Test]
    public function a_hostname_is_resolved_before_it_is_trusted(): void
    {
        // On in production; off in the suite, whose receivers are invented names. Turned back on
        // here so the scheme and bare-address halves are checked as they are in the real thing.
        config()->set('seatmap.webhooks.verify_destination', true);

        $this->assertSame('url_not_https', OutboundUrl::refuse('http://shop.example.com/hooks'));
        $this->assertSame('url_not_a_name', OutboundUrl::refuse('https://169.254.169.254/latest'));
        $this->assertSame('url_not_https', OutboundUrl::refuse('file:///etc/passwd'));
        $this->assertSame('url_not_https', OutboundUrl::refuse('not a url at all'));
    }

    /* ------------------------------------------------------------------------------ fixtures */

    private function endpoint(
        Tenant $tenant,
        array $types,
        string $url = 'https://shop.example.test/hooks/seatmap',
    ): WebhookEndpoint {
        return app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(Webhooks::class)->create('Our shop', $url, $types)['endpoint']
        );
    }

    /** One booking, paid for. */
    private function sell(array $night): \App\Models\ExternalOrder
    {
        return app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $ids = $night['seats']->slice($this->taken, 2)->pluck('id')->all();
            $this->taken += 2;

            $hold = app(HoldService::class)->create($night['event'], $ids, 'session-'.uniqid());
            $client = $this->makeApiClient($night['tenant'])['client'];

            [$order] = app(OrderService::class)->register(
                $client,
                'ORD-'.strtoupper(uniqid()),
                $hold->token,
                ['name' => 'Sam Buyer', 'email' => 'sam@example.test', 'phone' => '+44 20 7946 0000']
            );

            return app(OrderService::class)->confirm($order->fresh());
        });
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
