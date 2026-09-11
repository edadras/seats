<?php

namespace Tests\Feature;

use App\Domain\Sites\Measurement;
use App\Domain\Sites\SiteProvisioner;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Whether an organiser's marketing worked, and the question that has to come first.
 *
 * A hosted site had no measurement at all: an organiser who paid for a poster could see how many
 * tickets sold and nothing about where the buyers came from, which is most of what the money was
 * for.
 *
 * Two claims carry it. **Ids, never a snippet** — a box an organiser can paste script tags into is
 * a stored cross-site scripting hole on a domain we serve and a checkout we run the card form on,
 * and the shape of every id is narrow enough to be certain about. And **nothing loads until a
 * visitor says yes** — not the tag, not the address, nothing; a site that measures nothing does not
 * even ask.
 */
class MeasurementTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_id_that_is_not_that_providers_id_is_dropped(): void
    {
        $clean = Measurement::clean([
            'ga4' => 'G-ABC1234567',
            'meta' => '1234567890123',
            'plausible' => 'northgate.example',
        ]);

        $this->assertSame([
            'ga4' => 'G-ABC1234567',
            'meta' => '1234567890123',
            'plausible' => 'northgate.example',
        ], $clean);

        foreach ([
            ['ga4' => 'UA-12345-1'],
            ['ga4' => '<script>alert(1)</script>'],
            ['ga4' => 'G-'],
            ['meta' => 'not-a-number'],
            ['meta' => '12345'],
            ['plausible' => 'javascript:alert(1)'],
            ['plausible' => 'not a domain'],
            ['plausible' => 'https://northgate.example'],
            ['nonsense' => 'anything'],
        ] as $bad) {
            $this->assertSame([], Measurement::clean($bad), json_encode($bad));
        }
    }

    #[Test]
    public function a_pasted_id_is_forgiven_its_case(): void
    {
        // Google writes theirs in capitals and Plausible theirs in lower case; a copy from a
        // slide deck is neither, and that should not be a support ticket.
        $this->assertSame('G-ABC1234567', Measurement::clean(['ga4' => 'g-abc1234567'])['ga4']);
        $this->assertSame('northgate.example', Measurement::clean(['plausible' => 'Northgate.Example'])['plausible']);
    }

    #[Test]
    public function the_script_address_is_built_here_rather_than_stored(): void
    {
        $site = $this->makeSite();

        $this->save($site, ['ga4' => 'G-ABC1234567', 'meta' => '1234567890123']);

        $tags = Measurement::forSite($site->fresh());

        $this->assertCount(2, $tags);
        $this->assertSame('https://www.googletagmanager.com/gtag/js?id=G-ABC1234567', $tags[0]['src']);
        $this->assertSame('https://connect.facebook.net/en_US/fbevents.js', $tags[1]['src']);
    }

    /* ------------------------------------------------------------------------ what is rendered */

    #[Test]
    public function a_site_that_measures_nothing_asks_nothing(): void
    {
        $this->makeSite();

        $page = $this->get('http://northgate.test/')->assertOk();

        $page->assertDontSee('data-consent', false)
            ->assertDontSee('googletagmanager', false)
            // And no link to a question it never asks.
            ->assertDontSee('data-consent-reopen', false);
    }

    #[Test]
    public function nothing_is_loaded_until_the_visitor_has_been_asked(): void
    {
        $site = $this->makeSite();

        $this->save($site, ['ga4' => 'G-ABC1234567']);

        $page = $this->get('http://northgate.test/')->assertOk();
        $body = $page->getContent();

        // The bar is there…
        $this->assertStringContainsString('data-consent', $body);
        $this->assertStringContainsString(__('site.cookies.yes'), $body);

        // …and the tag is not. The id is in the page because the script needs it to build the tag
        // *after* a yes; what must not be there is anything that loads by itself.
        $this->assertStringNotContainsString('<script async src="https://www.googletagmanager.com', $body);
        $this->assertStringNotContainsString('<script src="https://connect.facebook.net', $body);
    }

    #[Test]
    public function a_page_records_what_happened_on_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        $this->save($site, ['ga4' => 'G-ABC1234567']);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            // Queued through one function, so a page recording something never has to know
            // whether anybody has consented.
            ->assertSee('seatmapTrack', false)
            ->assertSee('view_item', false);
    }

    #[Test]
    public function the_bar_is_the_only_thing_that_knows_about_consent(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        // No measurement configured: the event page still records, into a function that does not
        // exist, which is a no-op rather than an error.
        $page = $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();

        $page->assertSee('seatmapTrack', false)
            ->assertDontSee('data-consent-yes', false);
    }

    /* ------------------------------------------------------------------------------- the screen */

    #[Test]
    public function the_panel_saves_ids_and_says_which_it_kept(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        $saved = $this->actingAs($this->makeUser($fixture['tenant']))
            ->patchJson('/v1/sites/'.$site->id, [
                'measurement' => [
                    'ga4' => 'G-ABC1234567',
                    'meta' => 'not-a-pixel',
                    'plausible' => '',
                ],
            ])->assertOk()->json();

        // What comes back is what will be used, so an organiser sees the one that was dropped.
        $this->assertSame(['ga4' => 'G-ABC1234567'], $saved['measurement']);
    }

    #[Test]
    public function a_snippet_cannot_be_smuggled_in_as_an_id(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->patchJson('/v1/sites/'.$site->id, [
                'measurement' => ['ga4' => 'G-AB"></script><script>alert(1)</script>'],
            ])->assertOk()->assertJsonPath('measurement', []);

        $this->get('http://northgate.test/')->assertOk()->assertDontSee('alert(1)', false);
    }

    #[Test]
    public function somebody_who_may_not_manage_the_site_cannot_change_what_it_measures(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        $this->actingAs($this->makeUser($fixture['tenant'], 'door'))
            ->patchJson('/v1/sites/'.$site->id, ['measurement' => ['ga4' => 'G-ABC1234567']])
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------------------ fixtures */

    private function save(Site $site, array $ids): void
    {
        app(TenantContext::class)->runAs(
            $site->tenant,
            fn () => $site->forceFill(['measurement' => Measurement::clean($ids)])->save()
        );
    }

    private function makeSite($tenant = null, string $hostname = 'northgate.test'): Site
    {
        $tenant ??= $this->makeTenant();

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

            return $site->fresh();
        });
    }
}
