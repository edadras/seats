<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Domain\Sites\WebApp;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A venue's site, installable and usable with no signal.
 *
 * A ticket shop is the web's clearest case for an app: it is bought on a sofa and needed at a door,
 * in a queue, on a phone sharing one cell with several hundred other people. What this holds up is
 * the server's half of that — the manifest a browser reads before offering to install anything, the
 * tile it puts on a home screen, and the worker that makes the ticket open without the network. The
 * browser's half, which is the half that actually matters, is `webapp_smoke`.
 *
 * The claim running through all of it: **every one of these is the venue's, not the platform's.**
 * A white-label product whose home-screen icon is the vendor's logo is not white-label.
 */
class WebAppTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_manifest_describes_the_venue_rather_than_the_platform(): void
    {
        $site = $this->makeSite('northgate.test');

        $response = $this->get('http://northgate.test/manifest.webmanifest')->assertOk();

        $this->assertStringContainsString('application/manifest+json', $response->headers->get('Content-Type'));

        $manifest = $response->json();

        $this->assertSame($site->name, $manifest['name']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('en', $manifest['lang']);
        $this->assertSame('ltr', $manifest['dir']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $manifest['theme_color']);

        $this->assertSame(
            ['180x180', '192x192', '512x512'],
            array_column($manifest['icons'], 'sizes'),
        );
    }

    #[Test]
    public function a_short_name_is_short_enough_to_sit_under_an_icon(): void
    {
        $app = app(WebApp::class);
        $brand = ['tokens' => []];

        $site = new Site(['name' => 'The Royal Shakespeare Company at Stratford']);

        // One word where there is a short one, rather than a name cut mid-syllable.
        $this->assertSame('The', $app->manifest($site, $brand, 'en')['short_name']);
        $this->assertSame(
            'Northgate Theatre',
            $app->manifest(new Site(['name' => 'Northgate Theatre']), $brand, 'en')['name'],
        );
    }

    #[Test]
    public function a_persian_site_is_installed_the_other_way_round(): void
    {
        $site = $this->makeSite('northgate.test');

        app(TenantContext::class)->runAs(
            \App\Models\Tenant::find($site->tenant_id),
            fn () => $site->update(['locale' => 'fa', 'locales' => ['fa']]),
        );

        $manifest = $this->get('http://northgate.test/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('fa', $manifest['lang']);
        $this->assertSame('rtl', $manifest['dir']);
    }

    #[Test]
    public function the_one_shortcut_is_offered_only_where_it_leads_somewhere(): void
    {
        $site = $this->makeSite('northgate.test');

        // Sign-in is off by default on a fresh site, and a shortcut to a page that tells you to go
        // and look in your email is not a shortcut.
        $this->assertArrayNotHasKey(
            'shortcuts',
            $this->get('http://northgate.test/manifest.webmanifest')->json(),
        );
    }

    #[Test]
    public function the_tile_is_a_picture_drawn_in_the_venues_own_colours(): void
    {
        $this->makeSite('northgate.test');

        foreach (WebApp::SIZES as $size) {
            $response = $this->get('http://northgate.test/app-icon-'.$size.'.png')->assertOk();

            $this->assertSame('image/png', $response->headers->get('Content-Type'));

            $drawn = imagecreatefromstring($response->getContent());

            $this->assertSame($size, imagesx($drawn));
            $this->assertSame($size, imagesy($drawn));

            // The corner is brand colour and the band across the middle is not everywhere the
            // same: letters were drawn on it. Sampled across the row rather than at the exact
            // centre, which on two letters is the gap between them.
            $ground = imagecolorat($drawn, 2, 2);
            $marked = false;

            for ($x = (int) ($size * 0.2); $x < (int) ($size * 0.8); $x += 2) {
                $marked = $marked || imagecolorat($drawn, $x, (int) ($size / 2)) !== $ground;
            }

            $this->assertTrue($marked, 'nothing was written on the '.$size.'px tile');
        }
    }

    #[Test]
    public function a_size_nobody_asked_for_is_not_drawn(): void
    {
        $this->makeSite('northgate.test');

        // Otherwise the address is a way to ask this server for arbitrarily large images.
        $this->get('http://northgate.test/app-icon-4096.png')->assertNotFound();
    }

    #[Test]
    public function the_tile_is_not_drawn_twice_for_the_same_colours(): void
    {
        $site = $this->makeSite('northgate.test');

        $tag = $this->get('http://northgate.test/app-icon-192.png')->headers->get('ETag');

        $this->assertNotEmpty($tag);

        $this->withHeaders(['If-None-Match' => $tag])
            ->get('http://northgate.test/app-icon-192.png')
            ->assertStatus(304);

        // A rename is a new tile, and a home screen that still shows the old letters a week later
        // is a bug nobody can explain.
        app(TenantContext::class)->runAs(
            \App\Models\Tenant::find($site->tenant_id),
            fn () => $site->update(['name' => 'Southgate Rooms']),
        );

        $this->withHeaders(['If-None-Match' => $tag])
            ->get('http://northgate.test/app-icon-192.png')
            ->assertOk();
    }

    #[Test]
    public function the_worker_is_told_the_build_and_this_sites_own_theme(): void
    {
        $this->makeSite('northgate.test');

        $response = $this->get('http://northgate.test/sw.js')->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('text/javascript', $response->headers->get('Content-Type'));
        // Without this header a worker served from anywhere may only ever see its own directory.
        $this->assertSame('/', $response->headers->get('Service-Worker-Allowed'));
        // Laravel adds `private` of its own; what matters is that a worker is never revalidated
        // from a stale cache, which is how a site gets stuck on a worker it has replaced.
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));

        $this->assertMatchesRegularExpression('/self\.SEATMAP_BUILD="[a-f0-9]{12}"/', $body);
        $this->assertStringContainsString('"/offline"', $body);
        $this->assertStringContainsString('/site/css/themes/', $body);
        $this->assertStringContainsString('/site/js/widget.js', $body);
    }

    #[Test]
    public function the_build_stamp_moves_when_the_shell_does(): void
    {
        $this->makeSite('northgate.test');

        $before = $this->stamp();

        touch(public_path('site/css/site.css'), time() + 60);

        $this->assertNotSame($before, $this->stamp());
    }

    #[Test]
    public function the_worker_never_touches_a_page_about_one_person(): void
    {
        $this->makeSite('northgate.test');

        $body = $this->get('http://northgate.test/sw.js')->getContent();

        // The list itself, rather than the behaviour: what the worker does with these is the
        // browser's business and `webapp_smoke` proves it there. What can be proved here is that
        // nobody has quietly dropped one out of the list.
        foreach (['/checkout', '/_store/', '/pay/', '/account', '/season/', '/queue'] as $path) {
            $this->assertStringContainsString("'".$path."'", $body, $path.' is no longer private');
        }
    }

    #[Test]
    public function the_offline_page_needs_nothing_it_cannot_have(): void
    {
        $this->makeSite('northgate.test');

        $page = $this->get('http://northgate.test/offline')->assertOk()->getContent();

        // The one page that renders when the network does not cannot ask the network for a
        // stylesheet, a script or a font.
        $this->assertStringNotContainsString('<link rel="stylesheet"', $page);
        $this->assertStringNotContainsString('<script src', $page);
        $this->assertStringContainsString('noindex', $page);
        $this->assertStringContainsString(__('site.app.offlineTitle'), $page);
    }

    #[Test]
    public function every_page_carries_the_things_that_make_it_installable(): void
    {
        $this->makeSite('northgate.test');

        $this->get('http://northgate.test/')
            ->assertOk()
            ->assertSee('rel="manifest" href="/manifest.webmanifest"', escape: false)
            ->assertSee('rel="apple-touch-icon" href="/app-icon-180.png"', escape: false)
            ->assertSee('name="theme-color"', escape: false)
            // Without `viewport-fit=cover` the safe-area insets every sticky bar is padded with
            // are all zero, and the page stops short of the bottom of a modern phone.
            ->assertSee('viewport-fit=cover', escape: false)
            ->assertSee("navigator.serviceWorker.register( '/sw.js'", escape: false)
            // The links themselves, not a button that needs a script to do anything.
            ->assertSee('<div class="menu__panel" id="site-menu">', escape: false)
            ->assertSee('<button class="menu__button" type="button" data-menu hidden', escape: false);
    }

    #[Test]
    public function the_control_panel_is_not_an_app(): void
    {
        config()->set('seatmap.sites.panel_hosts', ['panel.test']);

        $this->makeSite('northgate.test');

        foreach (['/manifest.webmanifest', '/sw.js', '/app-icon-192.png', '/offline'] as $path) {
            $this->get('http://panel.test'.$path)->assertNotFound();
        }
    }

    /* --------------------------------------------------------------------------- helpers */

    private function stamp(): string
    {
        $body = $this->get('http://northgate.test/sw.js')->getContent();

        preg_match('/self\.SEATMAP_BUILD="([a-f0-9]+)"/', $body, $found);

        return $found[1] ?? '';
    }

    private function makeSite(string $hostname): Site
    {
        $tenant = $this->makeTenant();

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
