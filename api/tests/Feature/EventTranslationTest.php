<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Event;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * An event's own words, in the six languages everything around them already speaks.
 *
 * The rule that matters is the fallback. An organiser who has written Persian and German has not
 * thereby broken their French page: a missing translation must render the words the event was
 * typed in, never a blank.
 */
class EventTranslationTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_site_reads_the_event_in_the_readers_language(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->translate($fixture, ['fa' => ['name' => 'شب افتتاحیه', 'description' => 'آغاز فصل.']]);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id.'?lang=fa')
            ->assertOk()
            ->assertSee('شب افتتاحیه', false)
            ->assertSee('آغاز فصل.', false);
    }

    #[Test]
    public function a_language_nobody_wrote_falls_back_to_the_original(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->translate($fixture, ['fa' => ['name' => 'شب افتتاحیه']]);

        // French was never written. The page is still a page.
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id.'?lang=fr')
            ->assertOk()
            ->assertSee('Opening night', false);

        // And a half-written language falls back field by field, not all or nothing: the Persian
        // name is used and the English description with it.
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id.'?lang=fa')
            ->assertOk()
            ->assertSee('شب افتتاحیه', false);
    }

    #[Test]
    public function the_calendar_file_and_the_search_engine_get_it_too(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->translate($fixture, ['de' => ['name' => 'Premierenabend']]);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id.'?lang=de')
            ->assertOk()
            // The structured data a search engine reads is the same words as the page around it.
            ->assertSee('Premierenabend', false);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id.'/calendar.ics?lang=de')
            ->assertOk()
            ->assertSee('Premierenabend', false);
    }

    #[Test]
    public function what_a_buyer_is_emailed_is_in_their_own_language(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->translate($fixture, ['fa' => ['name' => 'شب افتتاحیه']]);

        $this->postJson('http://northgate.test/_store/hold?lang=fa', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][0]->id],
        ])->assertCreated();

        $this->post('http://northgate.test/checkout?lang=fa', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $told = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\MessageDelivery::where('kind', 'order.confirmed')->firstOrFail()
        );

        $this->assertSame('fa', $told->locale);
        $this->assertStringContainsString('شب افتتاحیه', (string) $told->preview);
    }

    #[Test]
    public function the_panel_saves_a_whole_set_and_drops_the_empties(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $saved = $this->actingAs($owner)
            ->putJson("/v1/events/{$fixture['event']->id}/translations", [
                'translations' => [
                    'fa' => ['name' => 'شب افتتاحیه', 'description' => ''],
                    // Blank in both fields is a language deliberately not written, and storing it
                    // would put an empty string where a fallback belongs.
                    'de' => ['name' => '', 'description' => ''],
                    // A language this platform does not speak is a typo or a client bug.
                    'xx' => ['name' => 'Nope'],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(['fa'], array_keys($saved));
        $this->assertSame(['name' => 'شب افتتاحیه'], $saved['fa']);
    }

    #[Test]
    public function writing_them_takes_the_permission_to_change_an_event(): void
    {
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)
            ->putJson("/v1/events/{$fixture['event']->id}/translations", ['translations' => []])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function translate(array $fixture, array $translations): void
    {
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Event::whereKey(
            $fixture['event']->id
        )->update(['translations' => json_encode($translations)]));
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
