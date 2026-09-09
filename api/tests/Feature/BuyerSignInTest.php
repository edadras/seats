<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Buyers signing in with Google, on an organiser's own domain.
 *
 * The shape being tested is the one the custom domains force: the round trip to Google happens on
 * the platform's host, because that is the only redirect URI Google can be told about, and the
 * buyer comes back to their own site with a one-time token. So the tests that matter are about
 * what is *not* believed on the way round — a state nobody issued, a token for another site, an
 * address Google has not verified — and about the address being the only thing that decides which
 * orders somebody sees.
 */
class BuyerSignInTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('seatmap.sites.panel_hosts', ['panel.test']);
        config()->set('seatmap.sites.scheme', 'http');
        config()->set('seatmap.signin.google.client_id', 'client-id.apps.googleusercontent.com');
        config()->set('seatmap.signin.google.client_secret', 'a-secret');
        config()->set('app.url', 'http://panel.test');
    }

    #[Test]
    public function a_site_offers_the_button_only_when_its_organiser_turned_it_on(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant'], 'northgate.test');

        $this->get('http://northgate.test/account')
            ->assertOk()
            ->assertDontSee('Sign in with Google');

        // And the pages behind it do not exist either, rather than explaining what they will not do.
        $this->get('http://northgate.test/account/google')->assertNotFound();

        $this->enableSignIn($site);

        $this->get('http://northgate.test/account')->assertOk()->assertSee('Sign in with Google');
    }

    #[Test]
    public function a_platform_with_no_google_credentials_offers_nothing(): void
    {
        config()->set('seatmap.signin.google.client_id', '');

        $fixture = $this->makeSellableEvent();
        $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));

        $this->get('http://northgate.test/account')->assertOk()->assertDontSee('Sign in with Google');
        $this->get('http://northgate.test/account/google')->assertNotFound();
    }

    #[Test]
    public function signing_in_shows_the_orders_that_address_bought(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));

        $this->buy($fixture, 'Amina Farsi', 'amina@example.test');

        $this->signIn($site, 'Amina Farsi', 'AMINA@example.test');

        $page = $this->get('http://northgate.test/account')->assertOk();

        $page->assertSee('amina@example.test');
        $page->assertSee($fixture['event']->name);
        // The buyer's own tickets, not somebody's idea of "recent orders".
        $page->assertSee('Send me new codes');
    }

    #[Test]
    public function an_address_google_has_not_verified_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));

        $state = $this->beginAt($site);

        $this->fakeGoogle('someone@example.test', verified: false);

        $this->get('http://panel.test/auth/google/callback?code=abc&state='.$state)
            ->assertRedirect('http://northgate.test/account?signin=failed');
    }

    #[Test]
    public function a_state_nobody_issued_goes_nowhere(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));

        $this->fakeGoogle('someone@example.test');

        // There is nowhere safe to send somebody whose state cannot be read: the site to return to
        // is precisely what the state was carrying.
        $this->get('http://panel.test/auth/google/callback?code=abc&state=invented')
            ->assertNotFound();
    }

    #[Test]
    public function a_handoff_token_works_once_and_only_for_its_own_site(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));
        $other = $this->enableSignIn($this->makeSite($this->makeTenant('Rival'), 'rival.test'));

        $token = $this->handoffToken($site, 'amina@example.test');

        // Another organiser's site cannot spend it, even knowing the token.
        $this->get('http://rival.test/account/google/finish?token='.$token)
            ->assertRedirect('/account?signin=expired');

        $this->get('http://northgate.test/account/google/finish?token='.$token)
            ->assertRedirect('/account');

        // And it is gone: replaying it signs nobody in.
        $this->get('http://northgate.test/account/google/finish?token='.$token)
            ->assertRedirect('/account?signin=expired');
    }

    #[Test]
    public function somebody_elses_order_is_not_theirs_to_reissue(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));

        $reference = $this->buy($fixture, 'Amina Farsi', 'amina@example.test');

        $this->signIn($site, 'Someone Else', 'someone@example.test');

        // A reference is short and printed on a confirmation page. What makes an order theirs is
        // the address it was bought with.
        $this->post('http://northgate.test/account/orders/'.$reference.'/tickets')
            ->assertNotFound();
    }

    #[Test]
    public function reissuing_gives_new_codes_and_kills_the_old_ones(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));

        $reference = $this->buy($fixture, 'Amina Farsi', 'amina@example.test');

        $before = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Ticket::orderBy('created_at')->pluck('token_hash')->all()
        );

        $this->signIn($site, 'Amina Farsi', 'amina@example.test');

        $response = $this->post('http://northgate.test/account/orders/'.$reference.'/tickets');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $after = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Ticket::orderBy('created_at')->pluck('token_hash')->all()
        );

        $this->assertNotEquals($before, $after, 'Every code on that order is a new one.');
        $this->assertSame([], array_intersect($before, $after), 'And none of the old ones survived.');
    }

    #[Test]
    public function signing_out_leaves_nothing_behind(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->enableSignIn($this->makeSite($fixture['tenant'], 'northgate.test'));

        $this->buy($fixture, 'Amina Farsi', 'amina@example.test');
        $this->signIn($site, 'Amina Farsi', 'amina@example.test');

        $this->get('http://northgate.test/account')->assertOk()->assertSee('amina@example.test');

        $this->post('http://northgate.test/account/sign-out')->assertRedirect('/');

        $this->get('http://northgate.test/account')->assertOk()->assertDontSee('amina@example.test');
    }

    /* --------------------------------------------------------------------------- helpers */

    /** The whole round trip, ending with a session that is signed in. */
    private function signIn(Site $site, string $name, string $email): void
    {
        $state = $this->beginAt($site);

        $this->fakeGoogle($email, name: $name);

        $back = $this->get('http://panel.test/auth/google/callback?code=abc&state='.$state);
        $back->assertRedirectContains('/account/google/finish');

        $this->get($back->headers->get('Location'))->assertRedirect('/account');
    }

    /** Press the button, and read the nonce back out of where Google would have been sent. */
    private function beginAt(Site $site): string
    {
        $start = $this->get('http://'.$site->primaryDomain->hostname.'/account/google');

        $start->assertRedirectContains('accounts.google.com');

        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('http://panel.test/auth/google/callback', $query['redirect_uri']);

        return $query['state'];
    }

    private function handoffToken(Site $site, string $email): string
    {
        $state = $this->beginAt($site);

        $this->fakeGoogle($email);

        $back = $this->get('http://panel.test/auth/google/callback?code=abc&state='.$state);

        parse_str(parse_url($back->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        return $query['token'];
    }

    private function fakeGoogle(string $email, bool $verified = true, ?string $name = null): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at_'.$email]),
            'openidconnect.googleapis.com/*' => Http::response([
                'sub' => '1234',
                'email' => $email,
                'email_verified' => $verified,
                'name' => $name ?? 'A Buyer',
            ]),
        ]);
    }

    /** A real purchase through the site, so the orders being listed are orders. */
    private function buy(array $fixture, string $name, string $email): string
    {
        $seatIds = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\Seat::where('seat_map_id', $fixture['map']->id)->limit(2)->pluck('id')->all()
        );

        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => $seatIds,
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => $name,
            'email' => $email,
            'gateway' => 'offline',
        ])->assertRedirect();

        // The session that bought is not the session that signs in: everything after this has to
        // stand on the address alone.
        $reference = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\ExternalOrder::latest('created_at')->firstOrFail()->external_order_id
        );

        $this->flushSession();

        return $reference;
    }

    private function enableSignIn(Site $site): Site
    {
        // Inside the tenant context, because a `Site` read from outside one is a `Site` the global
        // scope refuses to hand back — which is the isolation working, not a test problem.
        return app(TenantContext::class)->runAs(
            \App\Models\Tenant::findOrFail($site->tenant_id),
            function () use ($site) {
                Site::whereKey($site->id)->update(['google_signin' => true]);

                return $site->fresh('primaryDomain');
            }
        );
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
