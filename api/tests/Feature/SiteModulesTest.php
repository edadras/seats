<?php

namespace Tests\Feature;

use App\Domain\Sites\Blocks;
use App\Domain\Sites\SiteProvisioner;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The modules a venue's own page is actually built from.
 *
 * A page editor that offers a heading, a paragraph and a picture is a page editor nobody can make a
 * venue's home page with. What a venue puts in front of somebody deciding whether to come is a
 * slideshow of the room, the facts about the night, a trailer, the conditions of sale, and a button
 * that sells — so those are blocks rather than things an organiser pastes into a custom-HTML box.
 *
 * Two claims are worth the most guarding here, and both are about the `video` block. An address an
 * organiser typed is resolved to a provider and an id *on the way in*, so nothing they wrote ever
 * reaches an `iframe` src; and the player is not loaded until somebody presses play, so a page about
 * buying a ticket does not hand every visitor to a third party first.
 */
class SiteModulesTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* --------------------------------------------------------------------------- the slideshow */

    #[Test]
    public function a_slideshow_shows_every_picture_it_was_given(): void
    {
        $site = $this->makeSite($this->makeSellableEvent()['tenant']);

        $this->makePage($site, 'about', 'About', [[
            'id' => 'b1',
            'type' => 'slideshow',
            'title' => 'The room',
            'items' => [
                ['url' => 'https://cdn.test/stalls.jpg', 'alt' => 'The stalls', 'caption' => 'From the circle'],
                ['url' => 'https://cdn.test/foyer.jpg', 'alt' => 'The foyer'],
            ],
        ]]);

        $page = $this->get('http://northgate.test/about')->assertOk();

        $page->assertSee('https://cdn.test/stalls.jpg', false)
            ->assertSee('https://cdn.test/foyer.jpg', false)
            ->assertSee('The stalls', false)
            ->assertSee('From the circle', false)
            // Swipeable before any script runs: the track is the feature, the arrows are an extra.
            ->assertSee('data-slides-track', false);
    }

    #[Test]
    public function a_half_written_row_survives_a_save_and_does_not_survive_publishing(): void
    {
        /*
         * Both halves matter, and they pull in opposite directions.
         *
         * The editor adds a row and saves immediately, so a sanitiser that dropped the empty one
         * would make "add a picture" a button that does nothing — it would draw a row, the server
         * would delete it, and the repaint would take it away again. A published page, on the other
         * hand, may not carry a slide with no picture in it: that is a hole, not a draft.
         */
        $draft = Blocks::sanitiseAll([[
            'type' => 'slideshow',
            'items' => [
                ['url' => 'https://cdn.test/one.jpg'],
                ['caption' => 'Still being written'],
                ['url' => 'javascript:alert(1)'],
            ],
        ]]);

        $this->assertCount(3, $draft[0]['items']);
        // What was not a picture address is gone, even while the row it was in is kept.
        $this->assertSame('', $draft[0]['items'][2]['url']);

        $live = Blocks::tidy($draft);

        $this->assertCount(1, $live[0]['items']);
        $this->assertSame('https://cdn.test/one.jpg', $live[0]['items'][0]['url']);
    }

    #[Test]
    public function publishing_tidies_every_kind_of_list(): void
    {
        $live = Blocks::tidy(Blocks::sanitiseAll([
            ['type' => 'slideshow', 'items' => [['url' => 'https://cdn.test/a.jpg'], []]],
            ['type' => 'specs', 'items' => [['label' => 'Doors', 'value' => '19:00'], ['value' => 'orphan']]],
            ['type' => 'faq', 'items' => [['question' => 'When?', 'answer' => 'Seven.'], ['answer' => 'To what?']]],
            ['type' => 'buttons', 'items' => [['label' => 'Book', 'href' => '/whats-on'], ['label' => 'Nowhere']]],
        ]));

        foreach ($live as $block) {
            $this->assertCount(1, $block['items'], $block['type']);
        }
    }

    #[Test]
    public function a_slideshow_holds_twelve_pictures_at_most(): void
    {
        $items = [];

        for ($i = 0; $i < 30; $i++) {
            $items[] = ['url' => 'https://cdn.test/'.$i.'.jpg'];
        }

        $clean = Blocks::sanitiseAll([['type' => 'slideshow', 'items' => $items]]);

        $this->assertCount(12, $clean[0]['items']);
    }

    #[Test]
    public function a_slide_cannot_lead_somewhere_that_is_not_a_link(): void
    {
        $clean = Blocks::sanitiseAll([[
            'type' => 'slideshow',
            'items' => [
                ['url' => 'https://cdn.test/one.jpg', 'href' => 'javascript:alert(1)'],
                ['url' => 'https://cdn.test/two.jpg', 'href' => '/whats-on'],
            ],
        ]]);

        $this->assertSame('', $clean[0]['items'][0]['href']);
        $this->assertSame('/whats-on', $clean[0]['items'][1]['href']);
    }

    #[Test]
    public function a_slideshow_stands_still_unless_it_was_asked_to_move(): void
    {
        $clean = Blocks::sanitiseAll([
            ['type' => 'slideshow', 'items' => [['url' => 'https://cdn.test/a.jpg']]],
            ['type' => 'slideshow', 'autoplay' => true, 'items' => [['url' => 'https://cdn.test/b.jpg']]],
        ]);

        $this->assertFalse($clean[0]['autoplay']);
        $this->assertTrue($clean[1]['autoplay']);
    }

    /* ------------------------------------------------------------------------------- the video */

    #[Test]
    public function a_video_address_is_resolved_to_a_provider_and_an_id(): void
    {
        $cases = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://youtu.be/dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://www.youtube.com/shorts/dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://www.youtube.com/embed/dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://vimeo.com/347119375' => ['vimeo', '347119375'],
            'https://player.vimeo.com/video/347119375' => ['vimeo', '347119375'],
            'https://cdn.test/trailer.mp4' => ['file', 'https://cdn.test/trailer.mp4'],
        ];

        foreach ($cases as $url => $expected) {
            $resolved = Blocks::video($url);

            $this->assertSame($expected[0], $resolved['provider'], $url);
            $this->assertSame($expected[1], $resolved['key'], $url);
        }
    }

    #[Test]
    public function an_address_that_is_not_a_video_is_not_a_video(): void
    {
        foreach ([
            'javascript:alert(1)',
            'https://evil.test/embed/anything',
            'https://www.youtube.com/watch?v=',
            'https://www.youtube.com/watch?v=' . str_repeat('a', 40),
            'https://vimeo.com/channels/staffpicks',
            '',
            ['not' => 'a string'],
        ] as $url) {
            $resolved = Blocks::video($url);

            $this->assertNull($resolved['provider'], is_string($url) ? $url : 'an array');
            $this->assertNull($resolved['key']);
        }
    }

    #[Test]
    public function a_video_renders_a_still_and_loads_the_player_only_on_a_press(): void
    {
        $site = $this->makeSite($this->makeSellableEvent()['tenant']);

        $this->makePage($site, 'trailer', 'Trailer', [[
            'id' => 'b1',
            'type' => 'video',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'title' => 'The trailer',
            'poster' => 'https://cdn.test/still.jpg',
        ]]);

        $page = $this->get('http://northgate.test/trailer')->assertOk();

        $page->assertSee('The trailer', false)
            ->assertSee('https://cdn.test/still.jpg', false)
            // The embed is built from the provider and the id, on the cookieless host.
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false);

        // Nothing is loaded from anybody until a visitor asks for it.
        $this->assertStringNotContainsString('<iframe', $page->getContent());
    }

    #[Test]
    public function a_video_nobody_can_play_renders_nothing(): void
    {
        $site = $this->makeSite($this->makeSellableEvent()['tenant']);

        $this->makePage($site, 'trailer', 'Trailer', [[
            'id' => 'b1',
            'type' => 'video',
            'url' => 'https://evil.test/not-a-video',
            'title' => 'Watch this',
        ]]);

        $this->get('http://northgate.test/trailer')
            ->assertOk()
            ->assertDontSee('Watch this', false)
            ->assertDontSee('evil.test', false);
    }

    #[Test]
    public function a_video_file_is_played_by_the_browser_itself(): void
    {
        $site = $this->makeSite($this->makeSellableEvent()['tenant']);

        $this->makePage($site, 'trailer', 'Trailer', [[
            'id' => 'b1',
            'type' => 'video',
            'url' => 'https://cdn.test/trailer.mp4',
        ]]);

        $this->get('http://northgate.test/trailer')
            ->assertOk()
            ->assertSee('<source src="https://cdn.test/trailer.mp4">', false);
    }

    /* ------------------------------------------------------------------------------- the facts */

    #[Test]
    public function the_facts_are_rendered_as_a_description_list(): void
    {
        $site = $this->makeSite($this->makeSellableEvent()['tenant']);

        $this->makePage($site, 'about', 'About', [[
            'id' => 'b1',
            'type' => 'specs',
            'title' => 'The details',
            'items' => [
                ['label' => 'Doors', 'value' => '19:00'],
                ['label' => 'Running time', 'value' => '2h 20m, with an interval'],
                ['value' => 'A value with nothing to label it'],
            ],
        ]]);

        $page = $this->get('http://northgate.test/about')->assertOk();

        $page->assertSee('Running time', false)
            ->assertSee('2h 20m, with an interval', false)
            ->assertDontSee('A value with nothing to label it', false);
    }

    #[Test]
    public function the_facts_hold_twenty_rows_at_most(): void
    {
        $items = [];

        for ($i = 0; $i < 40; $i++) {
            $items[] = ['label' => 'Row '.$i, 'value' => (string) $i];
        }

        $clean = Blocks::sanitiseAll([['type' => 'specs', 'items' => $items]]);

        $this->assertCount(20, $clean[0]['items']);
    }

    /* ------------------------------------------------------------------------------- the terms */

    #[Test]
    public function the_terms_are_folded_until_somebody_opens_them(): void
    {
        $site = $this->makeSite($this->makeSellableEvent()['tenant']);

        $this->makePage($site, 'terms', 'Terms', [
            ['id' => 'b1', 'type' => 'terms', 'title' => 'Conditions', 'text' => 'No exchanges.'],
            ['id' => 'b2', 'type' => 'terms', 'title' => 'Access', 'text' => 'Step-free.', 'collapsed' => false],
        ]);

        $page = $this->get('http://northgate.test/terms')->assertOk();

        // Folded, but in the page rather than behind a script: the words are findable either way.
        $page->assertSee('Conditions', false)
            ->assertSee('No exchanges.', false)
            ->assertSee('<details class="terms">', false)
            ->assertSee('Step-free.', false)
            ->assertSee('terms--open', false);
    }

    #[Test]
    public function terms_with_nothing_written_in_them_render_nothing(): void
    {
        $site = $this->makeSite($this->makeSellableEvent()['tenant']);

        $this->makePage($site, 'terms', 'Terms', [
            ['id' => 'b1', 'type' => 'terms', 'title' => 'Conditions we never wrote'],
        ]);

        $this->get('http://northgate.test/terms')
            ->assertOk()
            ->assertDontSee('Conditions we never wrote', false);
    }

    #[Test]
    public function the_terms_can_be_read_in_the_visitors_language(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);
        $page = $this->makePage($site, 'terms', 'Terms', [
            ['id' => 'b1', 'type' => 'terms', 'title' => 'Conditions', 'text' => 'No exchanges.'],
        ]);

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->putJson('/v1/sites/'.$site->id.'/pages/'.$page->id.'/translations', [
                'locale' => 'fa',
                'blocks' => ['b1' => ['title' => 'شرایط', 'text' => 'بلیت تعویض نمی‌شود.']],
            ])->assertOk();

        $this->get('http://northgate.test/terms?lang=fa')
            ->assertOk()
            ->assertSee('بلیت تعویض نمی‌شود.', false)
            ->assertDontSee('No exchanges.', false);
    }

    /* --------------------------------------------------------------------------- the buy button */

    #[Test]
    public function a_buy_block_offers_the_night_it_names(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        $this->makePage($site, '', 'Home', [[
            'id' => 'b1',
            'type' => 'buy',
            'event_public_id' => $fixture['event']->public_id,
            'title' => 'Opening night',
            'label' => 'Book a seat',
            'note' => 'Doors at seven.',
        ]]);

        $page = $this->get('http://northgate.test/')->assertOk();

        $page->assertSee('Book a seat', false)
            ->assertSee('Doors at seven.', false)
            ->assertSee('/events/'.$fixture['event']->public_id, false)
            // The price the event itself would quote, not a second opinion about it: the cheapest
            // zone, formatted in the event's own currency.
            ->assertSee('From €12.50', false);
    }

    #[Test]
    public function a_buy_block_with_no_event_means_the_one_the_page_is_for(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        $this->makeEventPage($site, [
            ['id' => 'b1', 'type' => 'buy', 'event_public_id' => '', 'label' => 'Book now'],
        ]);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertSee('Book now', false)
            ->assertSee($fixture['event']->name, false);
    }

    #[Test]
    public function a_buy_block_says_what_the_event_says_when_the_sale_is_shut(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $fixture['event']->forceFill(['status' => 'closed'])->save();
        });

        $this->makePage($site, '', 'Home', [[
            'id' => 'b1',
            'type' => 'buy',
            'event_public_id' => $fixture['event']->public_id,
            'label' => 'Book a seat',
        ]]);

        $page = $this->get('http://northgate.test/')->assertOk();

        // A button that leads to a page with nothing to buy is worse than a sentence.
        $page->assertSee(__('site.closed.closed'), false)
            ->assertDontSee('Book a seat', false);
    }

    #[Test]
    public function a_buy_block_does_not_advertise_a_presale_to_somebody_without_a_code(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $fixture['event']->forceFill([
                'presale_starts_at' => now()->subDay(),
                'on_sale_at' => now()->addDays(3),
            ])->save();
        });

        $this->makePage($site, '', 'Home', [[
            'id' => 'b1',
            'type' => 'buy',
            'event_public_id' => $fixture['event']->public_id,
            'label' => 'Book a seat',
        ]]);

        $this->get('http://northgate.test/')
            ->assertOk()
            ->assertSee(__('site.access.presaleOnly'), false)
            ->assertDontSee('Book a seat', false);
    }

    #[Test]
    public function a_buy_block_cannot_name_another_organisers_event(): void
    {
        $mine = $this->makeSellableEvent($this->makeTenant('Mine'));
        $theirs = $this->makeSellableEvent($this->makeTenant('Theirs'));

        $site = $this->makeSite($mine['tenant']);

        $this->makePage($site, '', 'Home', [[
            'id' => 'b1',
            'type' => 'buy',
            'event_public_id' => $theirs['event']->public_id,
            'title' => 'Somebody else’s night',
        ]]);

        // Scoped by the ordinary tenant scope, so the block simply has no event and renders nothing.
        $this->get('http://northgate.test/')
            ->assertOk()
            ->assertDontSee('Somebody else’s night', false);
    }

    /* ------------------------------------------------------------------------------- the picker */

    #[Test]
    public function the_panel_is_offered_every_module(): void
    {
        $fixture = $this->makeSellableEvent();

        $meta = $this->actingAs($this->makeUser($fixture['tenant']))
            ->getJson('/v1/site-themes')
            ->assertOk()
            ->json('blocks');

        $types = array_column($meta, 'type');

        foreach (['slideshow', 'video', 'specs', 'terms', 'buy'] as $type) {
            $this->assertContains($type, $types);
        }

        // Each one is named in the panel's language and has an icon the panel actually holds.
        foreach ($meta as $kind) {
            $this->assertNotSame('', $kind['name']);
            $this->assertNotSame('site.blocks.'.$kind['type'], $kind['name']);
            $this->assertNotSame('', $kind['icon']);
        }
    }

    #[Test]
    public function an_unknown_module_is_dropped_rather_than_rendered(): void
    {
        $clean = Blocks::sanitiseAll([
            ['type' => 'slideshow', 'items' => [['url' => 'https://cdn.test/a.jpg']]],
            ['type' => 'somethingNewer', 'items' => []],
        ]);

        $this->assertCount(1, $clean);
        $this->assertSame('slideshow', $clean[0]['type']);
    }

    /* ------------------------------------------------------------------------------- fixtures */

    private function makePage(Site $site, string $slug, string $title, array $blocks): SitePage
    {
        return app(TenantContext::class)->runAs($site->tenant, function () use ($site, $slug, $title, $blocks) {
            $clean = Blocks::sanitiseAll($blocks);

            // Written through the sanitiser and published through `tidy`, because that is the only
            // way blocks ever reach the database: a test that stored a shape the panel cannot
            // produce would be testing the renderer against an input it will never see.
            return SitePage::updateOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                [
                    'title' => $title,
                    'kind' => '' === $slug ? 'home' : 'page',
                    'draft_blocks' => $clean,
                    'published_blocks' => Blocks::tidy($clean),
                    'published_at' => now(),
                ]
            );
        });
    }

    /** The one page that serves every event. */
    private function makeEventPage(Site $site, array $blocks): SitePage
    {
        return app(TenantContext::class)->runAs($site->tenant, function () use ($site, $blocks) {
            $clean = Blocks::sanitiseAll($blocks);

            return SitePage::updateOrCreate(
                ['site_id' => $site->id, 'slug' => 'event'],
                [
                    'title' => 'Event',
                    'kind' => 'event',
                    'draft_blocks' => $clean,
                    'published_blocks' => Blocks::tidy($clean),
                    'published_at' => now(),
                ]
            );
        });
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

            return $site->fresh();
        });
    }
}
