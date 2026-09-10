<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteMenu;
use App\Models\SiteMenuItem;
use App\Models\SitePage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A site written in more than one language.
 *
 * The events on a hosted site have been translatable for a while and the platform's own chrome
 * speaks six languages. What stayed in one language was everything the organiser wrote themselves —
 * the About page, the visiting directions, the words in the header — which on a Persian venue's
 * site is the half a visitor actually reads.
 *
 * Two rules carry the whole feature, and both are tested here: a translation is an *overlay* on the
 * page rather than a second copy of it, and a field with nothing written in it falls back to the
 * original rather than leaving a hole.
 */
class SitePageTranslationTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_page_is_read_in_the_visitors_language(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);
        $page = $this->makePage($site, 'about', 'About us', [
            ['id' => 'b1', 'type' => 'richText', 'text' => 'A theatre since 1904.'],
        ]);

        $this->translate($fixture, $page, 'fa', [
            'title' => 'دربارهٔ ما',
            'blocks' => ['b1' => ['text' => 'تئاتری از سال ۱۹۰۴.']],
        ]);

        $english = $this->get('http://northgate.test/about')->assertOk();
        $persian = $this->get('http://northgate.test/about?lang=fa')->assertOk();

        $english->assertSee('A theatre since 1904.', false);
        $persian->assertSee('تئاتری از سال ۱۹۰۴.', false);
        $persian->assertDontSee('A theatre since 1904.', false);
    }

    #[Test]
    public function a_field_nobody_translated_falls_back_to_the_original(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);
        $page = $this->makePage($site, 'visit', 'Visiting', [
            ['id' => 'b1', 'type' => 'richText', 'text' => 'Two minutes from the station.'],
            ['id' => 'b2', 'type' => 'richText', 'text' => 'The bar opens at six.'],
        ]);

        // Only the first block was translated. The second is still worth reading in English.
        $this->translate($fixture, $page, 'fa', [
            'blocks' => ['b1' => ['text' => 'دو دقیقه تا ایستگاه.']],
        ]);

        $persian = $this->get('http://northgate.test/visit?lang=fa')->assertOk();

        $persian->assertSee('دو دقیقه تا ایستگاه.', false);
        // A half-translated page is a page with some English on it, which is what a reader would
        // rather have than a page with holes in it.
        $persian->assertSee('The bar opens at six.', false);
    }

    #[Test]
    public function a_translation_cannot_invent_a_block_or_a_field(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);
        $page = $this->makePage($site, 'about', 'About us', [
            ['id' => 'b1', 'type' => 'richText', 'text' => 'A theatre since 1904.'],
        ]);

        $saved = $this->translate($fixture, $page, 'fa', [
            'blocks' => [
                // A block this page does not have, and a field this block does not have.
                'ghost' => ['text' => 'Nowhere'],
                'b1' => ['heading' => 'Invented', 'text' => 'واقعی'],
            ],
        ]);

        $this->assertSame(
            ['b1' => ['text' => 'واقعی']],
            $saved['translations']['fa']['blocks'],
        );

        // Which is what keeps this an overlay rather than a second editor — and why a page cannot
        // end up with two different shapes in two languages.
        $this->get('http://northgate.test/about?lang=fa')->assertDontSee('Nowhere', false);
    }

    #[Test]
    public function the_switcher_offers_the_languages_the_site_is_written_in_and_no_others(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        // The provisioner already gives a new site its home page; this only needs a site to visit.
        // One language: nothing to switch between, and a control offering one choice is furniture
        // that asks a question with one answer.
        $this->get('http://northgate.test/')->assertOk()->assertDontSee('class="langs"', false);

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->patchJson('/v1/sites/'.$site->id, ['locales' => ['fa', 'it']])
            ->assertOk()
            ->assertJsonPath('locales', ['en', 'fa', 'it']);

        $home = $this->get('http://northgate.test/')->assertOk();

        $home->assertSee('lang=fa', false);
        $home->assertSee('lang=it', false);
        // Not offered, because nothing on this site is written in it.
        $home->assertDontSee('lang=de', false);
    }

    #[Test]
    public function the_sites_own_language_can_never_be_taken_out_of_the_list(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->patchJson('/v1/sites/'.$site->id, ['locales' => ['fa']])
            ->assertOk()
            // It is what every untranslated word on the site is written in.
            ->assertJsonPath('locales', ['en', 'fa']);

        // And a language this platform does not speak is not one a site can be published in: the
        // page would have translated words and English buttons.
        $this->actingAs($this->makeUser($fixture['tenant']))
            ->patchJson('/v1/sites/'.$site->id, ['locales' => ['fa', 'xx']])
            ->assertOk()
            ->assertJsonPath('locales', ['en', 'fa']);
    }

    #[Test]
    public function the_words_in_the_header_are_read_in_the_visitors_language_too(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);
        $page = $this->makePage($site, 'about', 'About us', []);

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->putJson('/v1/sites/'.$site->id.'/menus/header', [
                'items' => [[
                    'label' => 'About us',
                    'translations' => ['fa' => 'دربارهٔ ما'],
                    'target_type' => 'page',
                    'site_page_id' => $page->id,
                ]],
            ])->assertOk();

        $this->get('http://northgate.test/about')->assertOk()->assertSee('About us');
        $this->get('http://northgate.test/about?lang=fa')->assertOk()->assertSee('دربارهٔ ما', false);
    }

    #[Test]
    public function a_translation_with_nothing_in_it_is_not_a_translation(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);
        $page = $this->makePage($site, 'about', 'About us', [
            ['id' => 'b1', 'type' => 'richText', 'text' => 'A theatre since 1904.'],
        ]);

        $this->translate($fixture, $page, 'fa', ['title' => 'دربارهٔ ما']);

        $emptied = $this->translate($fixture, $page, 'fa', [
            'title' => '   ',
            'blocks' => ['b1' => ['text' => '']],
        ]);

        // The locale is dropped rather than kept as a row of blanks, so "is this page written in
        // Persian" stays answerable by looking.
        $this->assertSame([], $emptied['translations']);
        $this->assertSame([], $emptied['written_in']);
    }

    #[Test]
    public function another_account_s_page_is_not_one_this_account_can_translate(): void
    {
        $mine = $this->makeSellableEvent($this->makeTenant('Mine'));
        $theirs = $this->makeSellableEvent($this->makeTenant('Theirs'));

        $theirSite = $this->makeSite($theirs['tenant'], 'somewhere.test');
        $theirPage = $this->makePage($theirSite, 'about', 'About', []);

        $this->actingAs($this->makeUser($mine['tenant']))
            ->putJson('/v1/sites/'.$theirSite->id.'/pages/'.$theirPage->id.'/translations', [
                'locale' => 'fa',
                'title' => 'دزدیده‌شده',
            ])->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function translate(array $fixture, SitePage $page, string $locale, array $words): array
    {
        return $this->actingAs($this->makeUser($fixture['tenant']))
            ->putJson('/v1/sites/'.$page->site_id.'/pages/'.$page->id.'/translations',
                ['locale' => $locale] + $words)
            ->assertOk()
            ->json();
    }

    private function makePage(Site $site, string $slug, string $title, array $blocks): SitePage
    {
        return app(TenantContext::class)->runAs($site->tenant, fn () => SitePage::create([
            'site_id' => $site->id,
            'slug' => $slug,
            'title' => $title,
            'kind' => '' === $slug ? 'home' : 'page',
            'draft_blocks' => $blocks,
            'published_blocks' => $blocks,
            'published_at' => now(),
        ]));
    }

    private function makeSite($tenant, string $hostname = 'northgate.test'): Site
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

            $site->update(['status' => 'live', 'locale' => 'en']);

            SiteMenu::firstOrCreate(
                ['site_id' => $site->id, 'key' => 'header'],
                ['tenant_id' => $tenant->id, 'name' => 'Header'],
            );

            return $site->fresh();
        });
    }
}
