<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Event;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Calendar\IcsFile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The parts of an event site that are not for the person reading it.
 *
 * A search result that shows the date and the price, a link that unfurls with the poster on it, a
 * file a calendar will take, and a programme somebody can search. None of it changes what is sold;
 * all of it decides whether anybody arrives.
 */
class EventSiteSeoTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('seatmap.sites.panel_hosts', ['panel.test']);
        config()->set('seatmap.sites.scheme', 'http');
    }

    #[Test]
    public function an_event_page_says_what_it_is_in_a_vocabulary_a_search_engine_reads(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => $fixture['event']->forceFill([
            'image_url' => 'https://pictures.test/poster.jpg',
            'description' => 'An evening of it.',
        ])->save());

        $page = $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        $html = $page->getContent();

        $this->assertMatchesRegularExpression('~<script type="application/ld\+json">(.+?)</script>~s', $html);

        preg_match('~<script type="application/ld\+json">(.+?)</script>~s', $html, $found);
        $data = json_decode(html_entity_decode($found[1]), true);

        $this->assertSame('Event', $data['@type']);
        $this->assertSame($fixture['event']->name, $data['name']);
        $this->assertSame('https://schema.org/EventScheduled', $data['eventStatus']);
        $this->assertSame($fixture['venue']->name, $data['location']['name'] ?? null);
        // A price a search result can show, in the currency it is charged in — the cheapest way
        // in, which is what "from" means and what a listing should promise.
        $this->assertSame('12.50', $data['offers']['price']);
        $this->assertSame('EUR', $data['offers']['priceCurrency']);

        // And a link to it unfurls with the poster rather than as a grey rectangle.
        $page->assertSee('<meta property="og:image" content="https://pictures.test/poster.jpg">', escape: false);
    }

    #[Test]
    public function a_cancelled_event_says_so_where_it_matters(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $fixture['event']->forceFill(['status' => 'cancelled'])->save()
        );

        $html = $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()->getContent();

        // The one thing a search engine showing a stale listing most needs to be told — and no
        // price, because there is nothing to sell.
        $this->assertStringContainsString('https://schema.org/EventCancelled', $html);
        $this->assertStringNotContainsString('"offers"', $html);
    }

    #[Test]
    public function the_sitemap_lists_the_pages_and_the_nights_and_nothing_in_draft(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant'], 'northgate.test');

        $draft = app(TenantContext::class)->runAs($fixture['tenant'], fn () => Event::create([
            'venue_id' => $fixture['venue']->id,
            'seat_map_id' => $fixture['map']->id,
            'seat_map_version_id' => $fixture['map']->published_version_id,
            'public_id' => 'evt_notyet',
            'name' => 'Not announced',
            'status' => 'draft',
            'starts_at' => now()->addMonth(),
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
        ]));

        $xml = $this->get('http://northgate.test/sitemap.xml')
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('<loc>http://northgate.test/</loc>', $xml);
        $this->assertStringContainsString('/events/'.$fixture['event']->public_id, $xml);
        $this->assertStringNotContainsString($draft->public_id, $xml, 'A draft is not on the internet.');
    }

    #[Test]
    public function robots_says_yes_to_a_venue_and_no_to_the_panel(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $site = $this->get('http://northgate.test/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Allow: /', $site);
        $this->assertStringContainsString('Sitemap: http://northgate.test/sitemap.xml', $site);
        // A checkout in an index is of no use to anybody.
        $this->assertStringContainsString('Disallow: /checkout', $site);

        // The control panel is not a thing to crawl, and it used to answer with the same file.
        $panel = $this->get('http://panel.test/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString("Disallow: /\n", $panel);
        $this->assertStringNotContainsString('Sitemap:', $panel);
    }

    #[Test]
    public function an_event_can_be_put_in_a_calendar(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        $response = $this->get('http://northgate.test/events/'.$fixture['event']->public_id.'/calendar.ics');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/calendar; charset=UTF-8');

        $ics = $response->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Opening night', $ics);
        $this->assertStringContainsString('LOCATION:', $ics);
        $this->assertStringContainsString('URL:http://northgate.test/events/'.$fixture['event']->public_id, $ics);
        // Every line ends CRLF, which the readers people actually use insist on.
        $this->assertSame([], array_filter(explode("\r\n", trim($ics)), fn ($line) => str_contains($line, "\n")));
    }

    #[Test]
    public function a_long_persian_name_is_folded_without_being_cut_in_half(): void
    {
        $name = str_repeat('شب‌های تئاتر شمال ', 6);

        $ics = IcsFile::event(
            uid: 'evt_x@northgate.test',
            summary: $name,
            starts: new \DateTimeImmutable('2026-10-01 19:30:00'),
        );

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'Lines fold at 75 octets.');
        }

        // Unfolded — continuation lines start with one space — the name survives intact.
        $unfolded = str_replace("\r\n ", '', $ics);

        $this->assertStringContainsString('SUMMARY:'.trim($name), $unfolded);
        $this->assertSame($ics, mb_convert_encoding($ics, 'UTF-8', 'UTF-8'), 'And it is still UTF-8.');
    }

    #[Test]
    public function the_programme_can_be_searched(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant'], 'northgate.test');

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Event::create([
            'venue_id' => $fixture['venue']->id,
            'seat_map_id' => $fixture['map']->id,
            'seat_map_version_id' => $fixture['map']->published_version_id,
            'public_id' => 'evt_lateone',
            'name' => 'Late night session',
            'category' => 'Club',
            'status' => 'published',
            'starts_at' => now()->addMonth(),
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
        ]));

        $this->get('http://northgate.test/')->assertOk()
            ->assertSee('Opening night')->assertSee('Late night session');

        $this->get('http://northgate.test/?q=late')->assertOk()
            ->assertSee('Late night session')->assertDontSee('Opening night');

        // The room is the other thing somebody remembers about a night they meant to book.
        $this->get('http://northgate.test/?q='.urlencode($fixture['venue']->name))->assertOk()
            ->assertSee('Opening night');

        $this->get('http://northgate.test/?category=Club')->assertOk()
            ->assertSee('Late night session')->assertDontSee('Opening night');

        // Nothing matched is a different page from nothing is on.
        $this->get('http://northgate.test/?q=zzzz')->assertOk()
            ->assertSee(__('site.nothingMatched'));
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
