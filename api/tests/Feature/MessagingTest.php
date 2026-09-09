<?php

namespace Tests\Feature;

use App\Domain\Messaging\ChannelRegistry;
use App\Domain\Messaging\MessageDispatcher;
use App\Models\MessageDelivery;
use App\Models\MessageTemplate;
use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What a buyer is told, and whether it went.
 *
 * The behaviours worth pinning are the ones an organiser would otherwise discover from a customer:
 * that a confirmation goes out at all, that it goes out in the buyer's language, that a channel
 * which fails does not take the sale down with it, and that a refusal is never retried.
 */
class MessagingTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_confirmed_order_tells_the_buyer(): void
    {
        Mail::fake();

        $fixture = $this->sell();

        $delivery = $this->inTenant($fixture, fn () => MessageDelivery::where('kind', 'order.confirmed')->first());

        $this->assertNotNull($delivery, 'A buyer who paid is told so.');
        $this->assertSame('email', $delivery->channel);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('dana@example.test', $delivery->recipient);
        $this->assertStringContainsString('Opening night', (string) $delivery->preview);
    }

    #[Test]
    public function the_message_is_in_the_buyers_language_not_ours(): void
    {
        Mail::fake();

        $fixture = $this->sell(['name' => 'Dana', 'email' => 'dana@example.test', 'locale' => 'fa']);

        $delivery = $this->inTenant($fixture, fn () => MessageDelivery::latest('created_at')->first());

        $this->assertSame('fa', $delivery->locale);
        $this->assertStringContainsString('بلیت', (string) $delivery->preview);
    }

    #[Test]
    public function an_organisers_wording_replaces_ours_and_can_be_put_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->putJson('/v1/messaging/order.confirmed/email/en', [
            'subject' => 'See you at {event}',
            'body' => 'Hello {buyer} — {seats}.',
        ])->assertOk();

        $this->actingAs($owner)->getJson('/v1/messaging/order.confirmed/email/en')
            ->assertOk()
            ->assertJsonPath('is_default', false)
            ->assertJsonPath('subject', 'See you at {event}');

        $this->actingAs($owner)->deleteJson('/v1/messaging/order.confirmed/email/en')->assertOk();

        $this->actingAs($owner)->getJson('/v1/messaging/order.confirmed/email/en')
            ->assertOk()
            ->assertJsonPath('is_default', true);
    }

    #[Test]
    public function a_confirmation_cannot_be_switched_off_entirely(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // An organiser may choose *how* a buyer is told, not whether.
        $this->actingAs($owner)->putJson('/v1/messaging/order.confirmed/channels/email', ['enabled' => false])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'channel_required');

        // A reminder is a different matter: it is theirs to turn on and off.
        $this->actingAs($owner)->putJson('/v1/messaging/event.reminder/channels/email', ['enabled' => true])
            ->assertOk();
    }

    #[Test]
    public function a_reminder_is_off_until_somebody_asks_for_it(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->inTenant($fixture, function () {
            $dispatcher = app(MessageDispatcher::class);

            $this->assertSame([], $dispatcher->enabledChannels('event.reminder'));
            $this->assertSame(['email'], $dispatcher->enabledChannels('order.confirmed'));
        });
    }

    #[Test]
    public function a_channel_that_breaks_does_not_break_the_sale(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->inTenant($fixture, function () {
            $this->registerChannel(new class implements MessageChannel
            {
                public function key(): string
                {
                    return 'sms.broken';
                }

                public function labelKey(): string
                {
                    return 'messaging.channels.email';
                }

                public function addressKind(): string
                {
                    return 'phone';
                }

                public function send(string $to, string $body, array $options = []): DeliveryResult
                {
                    throw new \RuntimeException('the provider exploded');
                }
            });

            $delivery = app(MessageDispatcher::class)
                ->send('order.confirmed', 'sms.broken', '+989121234567', ['buyer' => 'Dana']);

            // Recorded as retryable rather than thrown: a channel throwing is the channel being
            // broken, and the sale it was announcing is already made.
            $this->assertSame('unavailable', $delivery->status);
            $this->assertStringContainsString('exploded', (string) $delivery->reason);
        });
    }

    #[Test]
    public function a_refusal_is_never_retried_and_an_outage_is(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->inTenant($fixture, function () {
            $channel = new class implements MessageChannel
            {
                public int $calls = 0;

                public function key(): string
                {
                    return 'sms.counting';
                }

                public function labelKey(): string
                {
                    return 'messaging.channels.email';
                }

                public function addressKind(): string
                {
                    return 'phone';
                }

                public function send(string $to, string $body, array $options = []): DeliveryResult
                {
                    $this->calls++;

                    return 1 === $this->calls
                        ? DeliveryResult::unavailable('down')
                        : DeliveryResult::sent('ref-1');
                }
            };

            $this->registerChannel($channel);

            $dispatcher = app(MessageDispatcher::class);
            $delivery = $dispatcher->send('order.confirmed', 'sms.counting', '+989121234567', []);

            $this->assertSame('unavailable', $delivery->status);

            $retried = $dispatcher->retry($delivery->fresh(), []);
            $this->assertSame('sent', $retried->status);
            $this->assertSame(2, $retried->attempts);

            // A refusal is left alone, however many times the job runs.
            $refused = MessageDelivery::create([
                'tenant_id' => app(TenantContext::class)->id(),
                'kind' => 'order.confirmed',
                'channel' => 'sms.counting',
                'recipient' => '+989121234567',
                'locale' => 'en',
                'status' => 'refused',
                'attempts' => 1,
            ]);

            $dispatcher->retry($refused, []);

            $this->assertSame(1, $refused->fresh()->attempts, 'Nobody asked the provider again.');
        });
    }

    #[Test]
    public function the_retry_command_gives_up_rather_than_trying_forever(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();

        $this->inTenant($fixture, function () {
            MessageDelivery::create([
                'tenant_id' => app(TenantContext::class)->id(),
                'kind' => 'order.confirmed',
                'channel' => 'email',
                'recipient' => 'dana@example.test',
                'locale' => 'en',
                'status' => 'unavailable',
                'attempts' => MessageDispatcher::MAX_ATTEMPTS,
            ]);
        });

        $this->artisan('messages:retry')->assertSuccessful();

        $this->inTenant($fixture, function () {
            $this->assertSame(
                MessageDispatcher::MAX_ATTEMPTS,
                MessageDelivery::first()->attempts,
                'A message that has failed four times is not tried a fifth.'
            );
        });
    }

    #[Test]
    public function a_reminder_goes_once_per_order_however_often_the_job_runs(): void
    {
        Mail::fake();

        $fixture = $this->sell();

        $this->inTenant($fixture, function () use ($fixture) {
            app(MessageDispatcher::class); // resolve before the settings row is written

            \App\Models\MessageChannelSetting::create([
                'tenant_id' => app(TenantContext::class)->id(),
                'kind' => 'event.reminder',
                'channel' => 'email',
                'enabled' => true,
            ]);

            // The seeded event is a week out; bring it inside the reminder window.
            \App\Models\Event::whereKey($fixture['event']->id)->update(['starts_at' => now()->addHours(12)]);
        });

        $this->artisan('messages:remind')->assertSuccessful();
        $this->artisan('messages:remind')->assertSuccessful();

        $this->inTenant($fixture, function () {
            $this->assertSame(1, MessageDelivery::where('kind', 'event.reminder')->count());
        });
    }

    #[Test]
    public function one_organisers_wording_and_log_are_not_anothers(): void
    {
        Mail::fake();

        $mine = $this->sell();
        $theirs = $this->makeSellableEvent($this->makeTenant('Rival'));
        $them = $this->makeUser($theirs['tenant']);

        $this->inTenant($mine, fn () => MessageTemplate::create([
            'tenant_id' => app(TenantContext::class)->id(),
            'kind' => 'order.confirmed',
            'channel' => 'email',
            'locale' => 'en',
            'body' => 'Mine',
        ]));

        $body = $this->actingAs($them)->getJson('/v1/messaging')->assertOk()->json();

        $this->assertSame([], $body['templates']);

        $this->actingAs($them)->getJson('/v1/messaging/log')->assertOk()->assertJsonCount(0, 'data');
    }

    /* --------------------------------------------------------------------------- helpers */

    private function registerChannel(MessageChannel $channel): void
    {
        // The registry builds from modules; a test channel is registered by hand so the dispatcher
        // can be exercised without a module on disk.
        $registry = app(ChannelRegistry::class);
        $reflection = new \ReflectionProperty($registry, 'channels');
        $existing = $registry->all();
        $reflection->setValue($registry, $existing + [$channel->key() => $channel]);
    }

    private function inTenant(array $fixture, callable $work)
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], $work);
    }

    /** A confirmed sale, which is what causes a message. */
    private function sell(array $buyer = ['name' => 'Dana Scully', 'email' => 'dana@example.test']): array
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $reference = 'wc_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id, $fixture['seats'][1]->id],
            'session_id' => 'sess_'.\Illuminate\Support\Str::random(8),
        ])->assertCreated()->json();

        $api = $this->makeApiClient($fixture['tenant']);
        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $hold['hold_token']]);

        $this->call('POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )), $body)->assertCreated();

        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $confirmBody = json_encode(['buyer' => $buyer]);

        $this->call('POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $confirmBody)),
            $confirmBody)->assertOk();

        return $fixture;
    }
}
