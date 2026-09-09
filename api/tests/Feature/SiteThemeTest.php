<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Domain\Sites\ThemeCss;
use App\Domain\Sites\Themes;
use App\Domain\Sites\ThemeTokens;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteTheme;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Themes an organiser makes their own.
 *
 * The line these hold is the one ADR-0003 draws: a theme is tokens and a stylesheet, never code.
 * So the interesting tests are not "can I set a colour" but "what happens when somebody sets a
 * colour to `red; } </style><script>`" — because that is a stylesheet we serve from the
 * organiser's own domain, with our origin's trust behind it.
 */
class SiteThemeTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------------ the tokens */

    #[Test]
    public function a_font_nobody_named_never_reaches_the_stylesheet(): void
    {
        $css = ThemeTokens::css([
            'heading_font' => 'Comic Sans; } body { display: none } .x {',
            'accent' => 'red; } </style><script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<', $css);
        $this->assertStringNotContainsString('display: none', $css);
        // Both were dropped, so both fall back rather than half-applying.
        $this->assertStringContainsString('--accent:'.ThemeTokens::TOKENS['accent']['default'], $css);
        $this->assertStringContainsString('--font-heading:'.ThemeTokens::FONTS['sans'], $css);
    }

    #[Test]
    public function every_token_reaches_the_page_as_a_custom_property(): void
    {
        $css = ThemeTokens::css([]);

        foreach (ThemeTokens::TOKENS as $token) {
            $this->assertStringContainsString($token['var'].':', $css, $token['var'].' is not emitted');
        }
    }

    #[Test]
    public function a_theme_is_the_floor_and_the_organisers_brand_is_the_ceiling(): void
    {
        // Noir is dark and red; this organiser wants their own green, and keeps everything else.
        $brand = Themes::resolveBrand('noir', ['accent' => '#00aa66']);

        $this->assertSame('#00aa66', $brand['tokens']['accent']);
        $this->assertSame('#0d0f14', $brand['tokens']['surface'], 'The theme still decides the rest.');
        $this->assertSame('noir', $brand['base_key']);
    }

    /* --------------------------------------------------------------------- the stylesheet */

    #[Test]
    public function a_stylesheet_cannot_close_the_element_it_lands_in(): void
    {
        $clean = ThemeCss::sanitise('.a { color: red } </style><script>alert(1)</script>');

        $this->assertStringNotContainsString('<', $clean);
        $this->assertStringNotContainsString('>', $clean);
    }

    #[Test]
    public function a_stylesheet_cannot_fetch_from_somebody_else_at_render_time(): void
    {
        $clean = ThemeCss::sanitise('@import url("https://evil.test/x.css"); .a { color: red }');

        $this->assertStringNotContainsString('@import', $clean);
        $this->assertStringContainsString('color: red', $clean);
    }

    #[Test]
    public function the_old_ways_of_running_script_from_a_stylesheet_are_taken_out(): void
    {
        $clean = ThemeCss::sanitise(
            '.a { width: expression(alert(1)); behavior: url(x.htc); -moz-binding: url(x.xml) }'
        );

        $this->assertStringNotContainsString('expression(', $clean);
        $this->assertStringNotContainsString('behavior:', $clean);
        $this->assertStringNotContainsString('-moz-binding', $clean);
    }

    #[Test]
    public function a_background_may_be_a_picture_and_nothing_else(): void
    {
        $clean = ThemeCss::sanitise(
            '.a { background: url(https://cdn.test/hero.jpg) }'.
            '.b { background: url("data:image/png;base64,AAAA") }'.
            '.c { background: url(javascript:alert(1)) }'.
            '.d { background: url("data:text/html,<b>hi</b>") }'
        );

        $this->assertStringContainsString('https://cdn.test/hero.jpg', $clean);
        $this->assertStringContainsString('data:image/png', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('text/html', $clean);
        $this->assertSame(2, substr_count($clean, 'about:blank'), 'Both bad ones are made inert.');
    }

    /* ------------------------------------------------------------------------- the panel */

    #[Test]
    public function a_theme_starts_as_a_copy_of_one_of_ours(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant);

        $body = $this->actingAs($owner)->postJson('/v1/themes', [
            'name' => 'Northgate Nights',
            'base_key' => 'noir',
        ])->assertCreated()->json();

        $this->assertSame('northgate-nights', $body['key']);
        $this->assertSame('#f0455f', $body['tokens']['accent'], 'Duplicating Noir means Noir.');
    }

    #[Test]
    public function saving_keeps_what_it_replaced(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant);

        $theme = $this->actingAs($owner)->postJson('/v1/themes', [
            'name' => 'House style',
            'base_key' => 'aurora',
        ])->json();

        $this->actingAs($owner)->patchJson('/v1/themes/'.$theme['id'], [
            'tokens' => ['accent' => '#123456'],
            'css' => '.masthead { border: 0 }',
            'note' => 'trying something',
        ])->assertOk();

        $body = $this->actingAs($owner)->getJson('/v1/themes/'.$theme['id'])->assertOk()->json();

        $this->assertSame('#123456', $body['tokens']['accent']);
        $this->assertCount(1, $body['versions']);

        // And back again, because a stylesheet is the one change that breaks every page at once.
        $this->actingAs($owner)
            ->postJson('/v1/themes/'.$theme['id'].'/versions/'.$body['versions'][0]['id'].'/revert')
            ->assertOk()
            ->assertJsonPath('tokens.accent', '#4a4fdc');
    }

    #[Test]
    public function an_organiser_is_told_when_their_stylesheet_was_trimmed(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant);

        $theme = $this->actingAs($owner)
            ->postJson('/v1/themes', ['name' => 'Trim', 'base_key' => 'aurora'])->json();

        $this->actingAs($owner)->patchJson('/v1/themes/'.$theme['id'], [
            'css' => '@import url(https://evil.test/x.css); .a { color: red }',
        ])->assertOk()->assertJsonPath('stylesheet_trimmed', true);
    }

    #[Test]
    public function a_theme_somebody_is_wearing_cannot_be_deleted_out_from_under_them(): void
    {
        $site = $this->hostedSite();
        $tenant = $site->tenant;
        $owner = $this->makeUser($tenant);

        $theme = $this->actingAs($owner)
            ->postJson('/v1/themes', ['name' => 'In use', 'base_key' => 'kiosk'])->json();

        $this->actingAs($owner)->patchJson('/v1/sites/'.$site->id, ['site_theme_id' => $theme['id']])
            ->assertOk();

        $this->actingAs($owner)->deleteJson('/v1/themes/'.$theme['id'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'theme_in_use');
    }

    #[Test]
    public function one_organisers_theme_is_invisible_to_another(): void
    {
        $mine = $this->makeTenant('Northgate');
        $theirs = $this->makeTenant('Riverside');
        $me = $this->makeUser($mine);
        $them = $this->makeUser($theirs);

        $theme = $this->actingAs($me)
            ->postJson('/v1/themes', ['name' => 'Mine', 'base_key' => 'aurora'])->json();

        $this->actingAs($them)->getJson('/v1/themes')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($them)->getJson('/v1/themes/'.$theme['id'])->assertNotFound();
    }

    /* -------------------------------------------------------------------------- the page */

    #[Test]
    public function a_site_wears_its_own_theme_all_the_way_to_the_page(): void
    {
        $site = $this->hostedSite();

        $theme = app(TenantContext::class)->runAs($site->tenant, fn () => SiteTheme::create([
            'key' => 'house',
            'name' => 'House',
            'base_key' => 'marquee',
            'tokens' => ['accent' => '#ff0066'],
            'css' => '.masthead { letter-spacing: 0.5em }',
        ]));

        app(TenantContext::class)->runAs(
            $site->tenant,
            fn () => Site::whereKey($site->id)->update(['site_theme_id' => $theme->id])
        );

        $response = $this->get('http://northgate.localhost/');

        $response->assertOk();
        $response->assertSee('--accent:#ff0066', false);
        $response->assertSee('letter-spacing: 0.5em', false);
        // The base theme's own stylesheet is still linked, and the body still names it, so the
        // furniture that goes with Marquee comes along.
        $response->assertSee('themes/marquee.css', false);
        $response->assertSee('class="theme-marquee"', false);
    }

    #[Test]
    public function every_first_party_theme_has_a_stylesheet_on_disk(): void
    {
        foreach (Themes::keys() as $key) {
            $this->assertFileExists(
                public_path('site/css/themes/'.$key.'.css'),
                $key.' has no stylesheet'
            );
        }
    }

    private function hostedSite(): Site
    {
        $fixture = $this->makeSellableEvent();
        $tenant = $fixture['tenant'];

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.localhost',
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
