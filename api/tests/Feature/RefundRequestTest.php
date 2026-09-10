<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Notification;
use App\Models\RefundRequest;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * "Can I have my money back?"
 *
 * The two endings are the point. Inside the organiser's own terms it is granted at once, because
 * they already said yes when they wrote them. Outside them it becomes a request somebody has to
 * answer — and a refusal without a reason is the thing that generates the telephone call, so the
 * reason is required rather than optional.
 */
class RefundRequestTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function inside_the_terms_it_is_granted_at_once(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['refunds' => 'always']);
        $this->buy($fixture, [0]);

        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/refund', [
            'reason' => 'Cannot come.',
        ])->assertRedirect('/account');

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('refunded', ExternalOrder::firstOrFail()->status);
            $this->assertSame(0, Ticket::where('status', 'issued')->count());
            $this->assertSame('approved', RefundRequest::firstOrFail()->status);
        });
    }

    #[Test]
    public function outside_the_terms_somebody_has_to_answer(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        // The doors open next week and the window closed a month ago.
        $this->terms($fixture, ['refunds' => 'until', 'refund_window_hours' => 8760]);
        $this->buy($fixture, [0]);

        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/refund', [
            'reason' => 'I am in hospital.',
        ])->assertRedirect('/account');

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('confirmed', ExternalOrder::firstOrFail()->status);
            $this->assertSame('pending', RefundRequest::firstOrFail()->status);

            // And the box office is told, rather than the buyer telephoning instead.
            $this->assertSame(1, Notification::where('kind', 'refund.requested')->count());
        });
    }

    #[Test]
    public function a_night_that_offers_none_says_so_rather_than_taking_the_request(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        // `never` is the default: it is the answer an organiser has to opt out of.
        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/refund', [])
            ->assertRedirect('/account');

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('confirmed', ExternalOrder::firstOrFail()->status);
            $this->assertSame('pending', RefundRequest::firstOrFail()->status);
        });

        // The page says the terms before anybody asks, which is the point of writing them.
        $this->get('http://northgate.test/account')
            ->assertOk()
            ->assertSee('cannot be refunded', false);
    }

    #[Test]
    public function asking_twice_is_asking_once(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);
        $this->signIn();

        foreach (range(1, 3) as $ignored) {
            $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/refund', [])
                ->assertRedirect('/account');
        }

        // Two rows would put the same booking in front of the box office twice and invite two
        // different answers.
        $this->assertSame(1, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => RefundRequest::count()
        ));
    }

    #[Test]
    public function the_box_office_can_say_yes(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/refund', [])
            ->assertRedirect('/account');

        $owner = $this->makeUser($fixture['tenant']);
        $id = app(TenantContext::class)->runAs($fixture['tenant'], fn () => RefundRequest::firstOrFail()->id);

        $this->actingAs($owner)
            ->postJson("/v1/refund-requests/{$id}/grant", ['reason' => 'Goodwill.'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertSame('refunded', app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::firstOrFail()->status
        ));
    }

    #[Test]
    public function saying_no_takes_a_reason(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/refund', [])
            ->assertRedirect('/account');

        $owner = $this->makeUser($fixture['tenant']);
        $id = app(TenantContext::class)->runAs($fixture['tenant'], fn () => RefundRequest::firstOrFail()->id);

        // "Declined" with nothing after it is the sentence that generates the telephone call.
        $this->actingAs($owner)
            ->postJson("/v1/refund-requests/{$id}/decline", [])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->postJson("/v1/refund-requests/{$id}/decline", ['reason' => 'Within a week of the show.'])
            ->assertOk()
            ->assertJsonPath('status', 'declined');

        $this->assertSame('confirmed', app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::firstOrFail()->status
        ));
    }

    #[Test]
    public function the_queue_is_behind_the_permission_that_hands_money_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)->getJson('/v1/refund-requests')->assertForbidden();
    }

    #[Test]
    public function the_booking_fee_is_kept_or_returned_as_the_terms_say(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, [
            'refunds' => 'always',
            'refund_keeps_fee' => true,
            'booking_fee_kind' => 'per_order',
            'booking_fee_amount' => 500,
        ]);
        $this->buy($fixture, [0]);

        $order = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::firstOrFail()
        );

        $policy = app(\App\Domain\Refunds\RefundPolicy::class);

        // €105 was charged and €5 of it paid a card fee the organiser does not get back.
        $this->assertSame(10500, (int) $order->total_amount);
        $this->assertSame(10000, $policy->amount($order));

        $this->terms($fixture, ['refund_keeps_fee' => false]);
        $order->setRelation('event', app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::findOrFail($fixture['event']->id)
        ));

        $this->assertSame(10500, $policy->amount($order));
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function terms(array $fixture, array $attributes): void
    {
        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::whereKey($fixture['event']->id)->update($attributes)
        );
    }

    private function reference(array $fixture): string
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::orderByDesc('created_at')->firstOrFail()->external_order_id
        );
    }

    /** The buyer, signed in on their own account page. */
    private function signIn(): void
    {
        $this->withSession(['seatmap_buyer' => [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
        ]]);
    }

    /** @param  list<int>  $seats */
    private function buy(array $fixture, array $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
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
