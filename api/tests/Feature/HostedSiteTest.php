<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Allocation;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Hosted event sites (ADR-0003).
 *
 * The two things worth guarding hardest are that a request picks its site by Host and by nothing
 * else, and that a hosted sale produces exactly the same allocations and tickets a WooCommerce sale
 * does — because the whole design rests on there being one order path, not two.
 */
class HostedSiteTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_request_is_routed_to_a_site_by_its_host(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant'], 'northgate.test');

        $this->get('http://northgate.test/')
            ->assertOk()
            ->assertSee($site->name, escape: false);
    }

    #[Test]
    public function an_unknown_host_is_not_a_site(): void
    {
        config()->set('seatmap.sites.panel_hosts', ['panel.test']);

        $this->makeSite($this->makeTenant(), 'northgate.test');

        $this->get('http://nobody.test/')->assertNotFound();
    }

    #[Test]
    public function an_unverified_domain_serves_nothing(): void
    {
        config()->set('seatmap.sites.panel_hosts', ['panel.test']);

        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant'], 'live.test');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($site) {
            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'unproven.test',
                'verification_token' => SiteDomain::newToken(),
            ]);
        });

        // DNS pointing at us proves nothing about who owns the name.
        $this->get('http://unproven.test/')->assertNotFound();
        $this->get('http://live.test/')->assertOk();
    }

    #[Test]
    public function a_suspended_organiser_takes_their_site_down(): void
    {
        config()->set('seatmap.sites.panel_hosts', ['panel.test']);

        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $this->get('http://northgate.test/')->assertOk();

        $fixture['tenant']->forceFill(['status' => 'suspended'])->save();

        // Half-working would be worse: a visitor who could still reach checkout would be buying
        // from an account that cannot fulfil.
        $this->get('http://northgate.test/')->assertNotFound();
    }

    #[Test]
    public function one_site_cannot_show_another_organisers_event(): void
    {
        $a = $this->makeSellableEvent($this->makeTenant('Alpha'));
        $b = $this->makeSellableEvent($this->makeTenant('Beta'));

        $this->makeSite($a['tenant'], 'alpha.test');

        $this->get('http://alpha.test/events/'.$b['event']->public_id)->assertNotFound();
        $this->get('http://alpha.test/events/'.$a['event']->public_id)->assertOk();
    }

    #[Test]
    public function a_hosted_purchase_allocates_seats_and_issues_tickets(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $seatIds = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\Seat::where('seat_map_id', $fixture['map']->id)->limit(2)->pluck('id')->all()
        );

        $hold = $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => $seatIds,
        ])->assertCreated()->json();

        $this->assertSame('/checkout', $hold['cart_url']);
        $this->assertSame(5000, $hold['total_amount'], 'The price comes from the server, not the browser.');

        $this->get('http://northgate.test/checkout')->assertOk()->assertSee('Checkout');

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $allocations = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Allocation::whereIn('seat_id', $seatIds)->where('status', 'active')->get()
        );

        $this->assertCount(2, $allocations, 'Both seats are sold.');

        $tickets = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Ticket::whereIn('allocation_id', $allocations->pluck('id'))->get()
        );

        $this->assertCount(2, $tickets, 'One ticket per seat, issued exactly once.');
    }

    #[Test]
    public function checkout_prices_the_hold_the_server_made(): void
    {
        $fixture = $this->makeSellableEvent(amount: 4200);
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $seatId = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\Seat::where('seat_map_id', $fixture['map']->id)->value('id')
        );

        // A browser naming its own price has nowhere to put it: the hold endpoint takes seat ids
        // and nothing else, and the checkout reads the amount from the hold it made.
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$seatId],
            'total_amount' => 1,
            'amount' => 1,
        ])->assertCreated();

        $this->get('http://northgate.test/checkout')->assertOk()->assertSee('42.00');
    }

    #[Test]
    public function the_confirmation_page_belongs_to_the_session_that_bought_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $seatId = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\Seat::where('seat_map_id', $fixture['map']->id)->value('id')
        );

        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$seatId],
        ])->assertCreated();

        $redirect = $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $reference = basename((string) $redirect->headers->get('Location'));

        // A ticket token is a credential for getting into a building, and an order reference in a
        // URL is a guessable thing. Somebody else's session must not be able to read it.
        $this->flushSession();
        $this->get('http://northgate.test/order/'.$reference)->assertNotFound();
    }

    private function makeSite($tenant, string $hostname): Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $hostname) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
