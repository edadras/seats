<?php

namespace Tests\Feature;

use App\Domain\Notifications\Notifier;
use App\Models\MessageDelivery;
use App\Models\Notification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What the platform tells an organiser, and who hears it.
 *
 * The rule worth pinning is that a notification is governed by the permission that governs the
 * thing it is about, checked when it is read rather than when it is raised: a refund notice is
 * `orders.view`, so the door staff never see it, and somebody promoted tomorrow sees today's.
 */
class NotificationTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_refund_is_news_for_the_box_office_and_not_for_the_door(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(Notifier::class)->raise(
            'order.refunded',
            ['reference' => 'wc_1234', 'event' => $fixture['event']->name, 'seats' => 2],
        ));

        $boxOffice = $this->actingAs($this->makeUser($fixture['tenant'], 'box_office'))
            ->getJson('/v1/notifications')->assertOk()->json();

        $this->assertCount(1, $boxOffice['data']);
        $this->assertSame(1, $boxOffice['unread']);
        // Composed in the reader's language from facts, not stored as a sentence.
        $this->assertStringContainsString('wc_1234', $boxOffice['data'][0]['body']);
        $this->assertFalse($boxOffice['data'][0]['read']);

        $door = $this->actingAs($this->makeUser($fixture['tenant'], 'door'))
            ->getJson('/v1/notifications')->assertOk()->json();

        $this->assertCount(0, $door['data']);
        $this->assertSame(0, $door['unread']);
    }

    #[Test]
    public function reading_them_is_per_person(): void
    {
        $fixture = $this->makeSellableEvent();
        $first = $this->makeUser($fixture['tenant'], 'admin');
        $second = $this->makeUser($fixture['tenant'], 'admin');

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(Notifier::class)->raise(
            'domain.verified',
            ['hostname' => 'tickets.example', 'site' => 'Northgate'],
        ));

        $this->actingAs($first)->postJson('/v1/notifications/read', [])->assertOk();

        $this->assertSame(0, $this->actingAs($first)->getJson('/v1/notifications')->json('unread'));
        $this->assertSame(1, $this->actingAs($second)->getJson('/v1/notifications')->json('unread'),
            'One person reading it does not read it for everybody.');
    }

    #[Test]
    public function another_organisers_news_is_not_yours(): void
    {
        $ours = $this->makeSellableEvent();
        $theirs = $this->makeSellableEvent($this->makeTenant('Rival Halls'));

        app(TenantContext::class)->runAs($theirs['tenant'], fn () => app(Notifier::class)->raise(
            'order.refunded',
            ['reference' => 'wc_theirs', 'event' => 'Their night', 'seats' => 1],
        ));

        $body = $this->actingAs($this->makeUser($ours['tenant']))
            ->getJson('/v1/notifications')->assertOk()->json();

        $this->assertCount(0, $body['data']);
    }

    #[Test]
    public function a_danger_notice_is_emailed_as_well_as_shown(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(Notifier::class)->raise(
            'message.refused',
            ['channel' => 'email', 'reason' => 'mailbox_full'],
        ));

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($owner) {
            $notice = MessageDelivery::where('kind', 'system.notice')->first();

            // A buyer who was not told something is not a thing to find out next time somebody
            // opens the panel.
            $this->assertNotNull($notice);
            $this->assertSame($owner->email, $notice->recipient);
        });

        $this->assertSame(1, $this->actingAs($owner)->getJson('/v1/notifications')->json('unread'));
    }

    #[Test]
    public function the_same_failure_is_raised_once_an_hour_not_once_a_message(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $notifier = app(Notifier::class);

            for ($i = 0; $i < 5; $i++) {
                $notifier->raiseOnce('message.refused', 'email|no_credentials', ['channel' => 'email', 'reason' => 'x']);
            }

            // A channel that is refusing is refusing everything it is handed; forty notices about
            // it is a reason to stop reading notices.
            $this->assertSame(1, Notification::where('kind', 'message.refused')->count());
        });
    }
}
