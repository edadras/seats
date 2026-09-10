<?php

namespace Tests\Feature;

use App\Domain\Privacy\Consents;
use App\Domain\Privacy\PersonalData;
use App\Domain\Sites\SiteProvisioner;
use App\Models\ConsentEvent;
use App\Models\MarketingConsent;
use App\Models\MessageDelivery;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * May we write to this person about something they have not bought?
 *
 * Saved audiences made it possible to write to four thousand strangers in one press, and nothing
 * recorded whether any of them had agreed. These tests pin the answer to that, and the line the
 * whole feature rests on: a message about a booking somebody holds is service and needs no
 * permission, and a message about something they have not bought is news and needs their yes.
 *
 * The most important test here is the first one, and it is about what happens when nobody has been
 * asked at all.
 */
class MarketingConsentTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* --------------------------------------------------------------------- silence is not yes */

    #[Test]
    public function nobody_who_has_not_been_asked_is_written_to(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->buy($fixture, [0], 'dana@example.test', news: false);

        $reach = $this->actingAs($owner)
            ->getJson('/v1/messaging/announcements/audience?channels[]=email')
            ->assertOk()->json();

        // The absence of a row is not a no — it is nobody having asked — and it is emphatically
        // not a yes.
        $this->assertSame(0, $reach['people']);
        $this->assertSame(1, $reach['unreachable']);

        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'channels' => ['email'],
            'body' => 'Our new season is out.',
        ])->assertCreated();

        $this->inTenant($fixture, fn () => $this->assertSame(
            0, MessageDelivery::where('kind', 'announcement')->count()
        ));
    }

    #[Test]
    public function a_tick_at_the_checkout_is_an_answer_and_an_empty_box_is_not(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, [0], 'yes@example.test', news: true);
        $this->newBrowser();
        $this->buy($fixture, [1], 'quiet@example.test', news: false);

        $this->inTenant($fixture, function () {
            $consents = app(Consents::class);

            $this->assertTrue($consents->allows('yes@example.test'));
            $this->assertFalse($consents->allows('quiet@example.test'));

            // And no row at all for the person who left the box alone: recording a `no` on their
            // behalf would make "nobody asked" indistinguishable from "they refused".
            $this->assertSame('unasked', $consents->forEmail('quiet@example.test')['state']);
            $this->assertSame(1, MarketingConsent::count());
        });
    }

    #[Test]
    public function what_they_were_shown_is_written_down_beside_the_answer(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, [0], 'dana@example.test', news: true);

        $this->inTenant($fixture, function () {
            $event = ConsentEvent::firstOrFail();

            $this->assertSame('in', $event->action);
            $this->assertSame('checkout', $event->source);
            // The sentence they agreed to, not merely that they agreed — which is the question an
            // audit asks a year later and a boolean cannot answer.
            $this->assertStringContainsString('future events', (string) $event->note);
            $this->assertNotNull($event->ip);
        });
    }

    /* ------------------------------------------------------------------------ service vs news */

    #[Test]
    public function a_message_about_a_booking_they_hold_needs_no_permission(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->buy($fixture, [0], 'quiet@example.test', news: false);

        // "The doors have moved, bring a coat" is part of having sold them the ticket.
        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'event_id' => $fixture['event']->id,
            'channels' => ['email'],
            'body' => 'Tonight the side door is open.',
        ])->assertCreated();

        $this->inTenant($fixture, function () {
            $deliveries = MessageDelivery::where('kind', 'announcement')->get();

            $this->assertSame(['quiet@example.test'], $deliveries->pluck('recipient')->all());
            // And no "tell us to stop" line on it: an offer to stop sending somebody news about
            // the night they are coming to is an offer this platform cannot honour.
            $this->assertStringNotContainsString('preferences/', (string) $deliveries[0]->preview);
        });
    }

    #[Test]
    public function a_marketing_message_carries_a_way_out_of_it(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->buy($fixture, [0], 'dana@example.test', news: true);

        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'channels' => ['email'],
            'body' => 'Our new season is out.',
        ])->assertCreated();

        $this->inTenant($fixture, function () {
            $delivery = MessageDelivery::where('kind', 'announcement')->firstOrFail();

            $this->assertStringContainsString('preferences/', (string) $delivery->preview);
        });
    }

    /* ------------------------------------------------------------------------ changing an answer */

    #[Test]
    public function somebody_can_leave_without_signing_in_to_anything(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $site = $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0], 'dana@example.test', news: true);

        $token = $this->inTenant($fixture, fn () => app(Consents::class)
            ->tokenFor($fixture['tenant']->id, 'dana@example.test'));

        $path = '/preferences/'.urlencode('dana@example.test').'/'.$token;

        $this->get('http://northgate.test'.$path)->assertOk()->assertSee('dana@example.test');

        // Leaving is one press, with no password to remember at eleven at night.
        $this->post('http://northgate.test'.$path, [])->assertOk();

        $this->inTenant($fixture, function () {
            $this->assertFalse(app(Consents::class)->allows('dana@example.test'));
            // And the log kept both answers, which is what makes the current one provable.
            $this->assertSame(2, ConsentEvent::count());
        });

        // And back again from the same page: somebody who unsubscribes by mistake should not have
        // to write to the box office to undo it.
        $this->post('http://northgate.test'.$path, ['news' => '1'])->assertOk();

        $this->inTenant($fixture, fn () => $this->assertTrue(
            app(Consents::class)->allows('dana@example.test')
        ));
    }

    #[Test]
    public function a_link_with_a_wrong_signature_says_nothing_at_all(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0], 'dana@example.test', news: true);

        /*
         * A 404, not a 403.
         *
         * "Wrong token" would tell somebody guessing that the address is one this organiser knows —
         * which is the question this page must never answer about anybody.
         */
        $this->get('http://northgate.test/preferences/'.urlencode('dana@example.test').'/nonsense')
            ->assertStatus(404);

        // Including for an address nobody has ever heard of, which must look identical.
        $this->get('http://northgate.test/preferences/'.urlencode('nobody@example.test').'/nonsense')
            ->assertStatus(404);
    }

    #[Test]
    public function another_organisers_yes_is_not_this_organisers_yes(): void
    {
        $mine = $this->makeSellableEvent($this->makeTenant('Mine'));
        $theirs = $this->makeSellableEvent($this->makeTenant('Theirs'));

        $this->inTenant($theirs, fn () => app(Consents::class)
            ->record('dana@example.test', 'in', 'checkout'));

        // Agreeing to hear from a theatre in Berlin is not agreeing to hear from a promoter in
        // Tehran who happens to use the same software.
        $this->inTenant($mine, fn () => $this->assertFalse(
            app(Consents::class)->allows('dana@example.test')
        ));
    }

    /* ----------------------------------------------------------------------------- the record */

    #[Test]
    public function the_stored_answer_is_the_log_folded_and_can_be_proved_so(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->inTenant($fixture, function () {
            $consents = app(Consents::class);

            $consents->record('dana@example.test', 'in', 'checkout');
            $consents->record('dana@example.test', 'out', 'link');
            $consents->record('dana@example.test', 'in', 'panel', null, 'Signed the sheet at the door');

            // Somebody edits the row directly, which is what a stored fold is exposed to.
            MarketingConsent::where('email', 'dana@example.test')->update(['state' => 'out']);

            $this->assertSame(1, $consents->rebuild(), 'One row disagreed with the log.');
            $this->assertTrue($consents->allows('dana@example.test'));
            // Nothing was rewritten to make it agree: the log still has all three answers.
            $this->assertSame(3, ConsentEvent::count());
        });
    }

    #[Test]
    public function being_forgotten_takes_the_consent_and_its_log_with_it(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0], 'dana@example.test', news: true);

        $copy = $this->inTenant($fixture, fn () => app(PersonalData::class)->export('dana@example.test'));

        // What they were asked and when is part of the copy they are entitled to.
        $this->assertSame('in', $copy['marketing']['state']);
        $this->assertNotEmpty($copy['marketing']['history']);

        $this->inTenant($fixture, fn () => app(PersonalData::class)->erase('dana@example.test'));

        $this->inTenant($fixture, function () {
            // The one thing an erasure deletes outright rather than redacts: keeping "this person
            // once said no" after they asked to be forgotten would be keeping a record of them in
            // order to honour their wish not to be on record.
            $this->assertSame(0, MarketingConsent::count());
            $this->assertSame(0, ConsentEvent::count());
        });
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function buy(array $fixture, array $seats, string $email, bool $news): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->get('http://northgate.test/checkout')->assertOk();

        $this->post('http://northgate.test/checkout', array_filter([
            'name' => 'A buyer',
            'email' => $email,
            'gateway' => 'offline',
            'news' => $news ? '1' : null,
        ]))->assertRedirect();
    }

    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
    }

    private function inTenant(array $fixture, callable $work)
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], $work);
    }

    private function makeSite($tenant): Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.test',
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
