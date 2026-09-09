<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Hold;
use App\Models\Seat;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Selling from somebody's own website, with no shop and no server of their own.
 *
 * The public embed API already held seats. What is new is where the buyer goes next: the embed
 * hold now answers with the organiser's own checkout, and that checkout adopts a hold made
 * anywhere. So the things worth pinning are that the handoff carries nothing but a token this
 * application issued, that another organiser's token is not adoptable, and that an expired one is
 * not either — because the seats behind it are already back on sale.
 */
class EmbedOnAnySiteTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('seatmap.sites.panel_hosts', ['panel.test']);
        config()->set('seatmap.sites.scheme', 'http');
    }

    #[Test]
    public function a_hold_made_from_anywhere_says_where_to_go_and_pay(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'session_id' => 'embed-abc',
        ])->assertCreated()->json();

        $this->assertSame(
            'http://northgate.test/checkout/resume?hold='.urlencode($hold['hold_token']),
            $hold['cart_url'],
            'The organiser\'s own checkout, and a token in the URL — never a price.'
        );

        // Everything the widget needs to run on a page with no server, from public reads alone.
        $event = $this->getJson("/v1/embed/events/{$fixture['event']->public_id}")->assertOk()->json();

        $this->assertSame(2, $event['currency_decimals']);
        $this->assertNotEmpty($event['zones']);
    }

    #[Test]
    public function an_organiser_with_no_site_holds_seats_and_offers_nowhere_to_pay(): void
    {
        $fixture = $this->makeSellableEvent();

        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'session_id' => 'embed-abc',
        ])->assertCreated()->json();

        // Null rather than a guess: the seats really are held, and the widget says so instead of
        // navigating somewhere that does not exist.
        $this->assertNull($hold['cart_url']);
    }

    #[Test]
    public function the_checkout_adopts_that_hold_and_prices_it_itself(): void
    {
        $fixture = $this->makeSellableEvent(amount: 4200);
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id, $fixture['seats'][1]->id],
            'session_id' => 'embed-abc',
        ])->assertCreated()->json();

        $this->get('http://northgate.test/checkout/resume?hold='.$hold['hold_token'])
            ->assertRedirect('/checkout');

        // The price on the screen came from the hold this server made, not from anything the
        // embedding page could have said.
        $this->get('http://northgate.test/checkout')->assertOk()->assertSee('84.00');
    }

    #[Test]
    public function another_organisers_hold_is_not_adoptable(): void
    {
        $ours = $this->makeSellableEvent();
        $theirs = $this->makeSellableEvent($this->makeTenant('Rival Halls'));

        $this->makeSite($ours['tenant'], 'northgate.test');

        $hold = $this->postJson("/v1/embed/events/{$theirs['event']->public_id}/holds", [
            'seat_ids' => [$theirs['seats'][0]->id],
            'session_id' => 'embed-abc',
        ])->assertCreated()->json();

        // The token is real; it is simply not this site's to spend. `Hold` is tenant-scoped and
        // this request's tenant came from the Host.
        $this->get('http://northgate.test/checkout/resume?hold='.$hold['hold_token'])
            ->assertRedirect('/');

        $this->get('http://northgate.test/checkout')->assertRedirect('/');
    }

    #[Test]
    public function an_expired_hold_is_not_adopted(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'session_id' => 'embed-abc',
        ])->assertCreated()->json();

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Hold::where('token', $hold['hold_token'])
            ->update(['expires_at' => now()->subMinute()]));

        $this->get('http://northgate.test/checkout/resume?hold='.$hold['hold_token'])
            ->assertRedirect('/');
    }

    #[Test]
    public function the_script_a_page_pastes_in_is_served_and_needs_no_key(): void
    {
        $script = file_get_contents(public_path('embed/v1/seatmap.js'));

        $this->assertStringContainsString('data-seatmap-event', $script);
        $this->assertStringContainsString('/v1/embed/events/', $script);
        // Nothing in it authenticates, because there is nothing for a page to authenticate with.
        $this->assertStringNotContainsString('Authorization', $script);
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

            return $site->fresh('primaryDomain');
        });
    }
}
