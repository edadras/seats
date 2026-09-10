<?php

namespace Tests\Feature;

use App\Domain\Messaging\Announcements\AnnouncementSender;
use App\Models\Announcement;
use App\Models\MessageDelivery;
use App\Models\Notification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Saying something to everybody who bought.
 *
 * The things worth pinning are the ones that make an announcement different from a confirmation:
 * that it goes to each person once rather than once per order, that it goes only to people who
 * actually paid, that an event's audience is that event's, and that every message it sends is an
 * ordinary delivery — so "did it arrive" is answered where that is always answered.
 */
class AnnouncementTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function one_person_with_four_orders_gets_one_message(): void
    {
        $fixture = $this->makeSellableEvent(perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Dana', 'email' => 'DANA@example.test'], [1]);
        $this->sell($fixture, ['name' => 'Amir', 'email' => 'amir@example.test'], [2]);
        $this->agreed($fixture, 'dana@example.test', 'amir@example.test');

        $reach = $this->actingAs($owner)
            ->getJson('/v1/messaging/announcements/audience?channels[]=email')
            ->assertOk()->json();

        $this->assertSame(2, $reach['people']);
        $this->assertSame(2, $reach['messages'], 'Two addresses, not three orders.');

        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'channels' => ['email'],
            'subject' => 'The doors move',
            'body' => 'Hello {buyer}, use the side door tonight.',
        ])->assertCreated();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $deliveries = MessageDelivery::where('kind', 'announcement')->get();

            $this->assertCount(2, $deliveries);
            $this->assertSame(['amir@example.test', 'dana@example.test'],
                $deliveries->pluck('recipient')->sort()->values()->all());
            // Sent inline, because two messages do not need a scheduler.
            $this->assertSame(['sent', 'sent'], $deliveries->pluck('status')->all());
            // The organiser's own words, with the placeholder filled in per person.
            $this->assertStringContainsString('Hello Amir', $deliveries->firstWhere('recipient', 'amir@example.test')->preview);
        });
    }

    #[Test]
    public function only_people_who_paid_are_written_to(): void
    {
        $fixture = $this->makeSellableEvent(perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Paid', 'email' => 'paid@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Waiting', 'email' => 'waiting@example.test'], [1], confirm: false);
        $this->agreed($fixture, 'paid@example.test', 'waiting@example.test');

        $reach = $this->actingAs($owner)
            ->getJson('/v1/messaging/announcements/audience?channels[]=email')
            ->assertOk()->json();

        // Somebody who has not paid has not bought: an announcement about tonight is not for them.
        $this->assertSame(1, $reach['people']);
    }

    #[Test]
    public function an_events_audience_is_that_events(): void
    {
        $fixture = $this->makeSellableEvent(perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);
        $other = $this->makeSellableEvent($fixture['tenant'], perRow: 8);

        $this->sell($fixture, ['name' => 'First', 'email' => 'first@example.test'], [0]);
        $this->sell($other, ['name' => 'Second', 'email' => 'second@example.test'], [0]);
        $this->agreed($fixture, 'first@example.test', 'second@example.test');

        $everyone = $this->actingAs($owner)
            ->getJson('/v1/messaging/announcements/audience?channels[]=email')->json();
        $this->assertSame(2, $everyone['people']);

        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'event_id' => $fixture['event']->id,
            'channels' => ['email'],
            'body' => 'Tonight only.',
        ])->assertCreated();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $recipients = MessageDelivery::where('kind', 'announcement')->pluck('recipient')->all();

            $this->assertSame(['first@example.test'], $recipients);
        });
    }

    #[Test]
    public function a_long_announcement_is_finished_by_the_scheduled_command(): void
    {
        // Thirty purchases in one test minute trips the storefront's own rate limit, which is
        // working as intended and is not what this test is about.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $fixture = $this->makeSellableEvent(rows: 6, perRow: 10);
        $owner = $this->makeUser($fixture['tenant']);

        // More buyers than one batch sends inline, all of whom agreed to hear from this venue.
        for ($i = 0; $i < 30; $i++) {
            $this->sell($fixture, ['name' => 'Buyer '.$i, 'email' => 'buyer'.$i.'@example.test'], [$i]);
            $this->agreed($fixture, 'buyer'.$i.'@example.test');
        }

        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'channels' => ['email'],
            'body' => 'See you tonight.',
        ])->assertCreated();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $announcement = Announcement::firstOrFail();

            $this->assertSame(30, $announcement->recipients);
            $this->assertSame('sending', $announcement->status, 'The first batch went; the rest is queued.');
            $this->assertSame(AnnouncementSender::BATCH,
                MessageDelivery::where('kind', 'announcement')->where('status', 'sent')->count());
        });

        $this->artisan('messages:announce')->assertSuccessful();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame(0, MessageDelivery::where('kind', 'announcement')
                ->where('status', 'queued')->count());
            $this->assertSame('sent', Announcement::firstOrFail()->status);

            // And the organiser is told it finished, without having to watch the screen.
            $this->assertTrue(Notification::where('kind', 'announcement.finished')->exists());
        });
    }

    #[Test]
    public function the_box_office_cannot_write_to_everybody(): void
    {
        $fixture = $this->makeSellableEvent();

        // Finding a booking and refunding it is one job; writing to every customer is another.
        $this->actingAs($this->makeUser($fixture['tenant'], 'box_office'))
            ->getJson('/v1/messaging/announcements')->assertForbidden();

        $this->actingAs($this->makeUser($fixture['tenant'], 'manager'))
            ->getJson('/v1/messaging/announcements')->assertOk();
    }

    #[Test]
    public function a_channel_this_account_does_not_have_is_not_a_channel(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'channels' => ['sms.invented'],
            'body' => 'Anybody there?',
        ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_channel');
    }

    /* --------------------------------------------------------------------------- helpers */

    private array $clients = [];

    /** @param  list<int>  $seats */
    /**
     * These people agreed to hear about things they have not bought.
     *
     * Said explicitly in every test that broadcasts, because that is now the difference between a
     * message being sent and not: an announcement to everybody is marketing, and silence is not
     * consent. A test that did not have to say it would be a test of the old behaviour.
     */
    private function agreed(array $fixture, string ...$emails): void
    {
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($emails) {
            foreach ($emails as $email) {
                app(\App\Domain\Privacy\Consents::class)->record($email, 'in', 'checkout');
            }
        });
    }

    private function sell(array $fixture, array $buyer, array $seats, bool $confirm = true): void
    {
        $event = $fixture['event'];
        $reference = 'wc_'.Str::lower(Str::random(10));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => array_map(fn (int $index) => $fixture['seats'][$index]->id, $seats),
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $api = $this->clients[$fixture['tenant']->id] ??= $this->makeApiClient($fixture['tenant']);
        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $hold['hold_token']]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated();

        if (! $confirm) {
            return;
        }

        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $payload = json_encode(['buyer' => $buyer]);

        $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $payload)),
            $payload,
        )->assertOk();
    }
}
