<?php

namespace Tests\Feature;

use App\Domain\Productions\Productions;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeries;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\TicketType;
use App\Models\Venue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * One show, in twelve towns.
 *
 * A run in one building was already a grouping here. What a tour adds is that the hall changes, and
 * with it the chart, the prices and the door. Two claims follow from that.
 *
 * **A stop is a copy that knows it is going somewhere else.** It carries what describes the show —
 * the concessions, the fee, the tax, and the prices whose categories exist in the new chart — and
 * nothing that describes a room. A price for a category the new hall has never heard of is not a
 * price; it is a row nobody can sell and somebody has to notice.
 *
 * **The production carries the poster and the paragraph.** Twelve towns must not mean twelve copies
 * of one description, each of which somebody has to remember to change — and a night with something
 * of its own to say still wins.
 */
class ProductionTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** Another building in the same account, with a chart of its own. */
    private function otherHall(array $night, string $city, string $categoryKey): array
    {
        return app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $city, $categoryKey) {
            $venue = Venue::factory()->create([
                'tenant_id' => $night['tenant']->id,
                'name' => $city.' Playhouse',
                'city' => $city,
                'timezone' => 'Europe/London',
            ]);

            $map = SeatMap::create(['venue_id' => $venue->id, 'name' => $city.' stalls']);

            $chart = $this->geometry(2, 4);
            $chart['categories'] = [
                ['key' => $categoryKey, 'label' => 'Seats', 'color' => '#2d6cdf', 'accessible' => false],
            ];

            foreach ($chart['floors'][0]['objects'] as $index => $object) {
                if ('row' === ($object['type'] ?? '')) {
                    $chart['floors'][0]['objects'][$index]['categoryKey'] = $categoryKey;
                }
            }

            $version = SeatMapVersion::create([
                'seat_map_id' => $map->id,
                'version' => 1,
                'status' => 'draft',
                'geometry' => $chart,
            ]);

            app(\App\Domain\SeatMaps\SeatMapPublisher::class)->publish($map, $version);

            return ['venue' => $venue, 'map' => $map->fresh()];
        });
    }

    private function production(array $night, string $name = 'Hamlet'): EventSeries
    {
        return app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $name) {
            $production = EventSeries::create([
                'tenant_id' => $night['tenant']->id,
                'name' => $name,
                'slug' => EventSeries::slugFor($name),
            ]);

            $night['event']->forceFill(['series_id' => $production->id])->save();

            return $production;
        });
    }

    #[Test]
    public function a_tour_date_copies_the_show_and_nothing_about_the_room(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 5, amount: 3000);
        $production = $this->production($night);
        $glasgow = $this->otherHall($night, 'Glasgow', 'standard');

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $production, $glasgow) {
            TicketType::create([
                'tenant_id' => $night['tenant']->id,
                'event_id' => $night['event']->id,
                'name' => 'Under 26',
                'kind' => 'percent',
                'value' => 50,
                'position' => 1,
                'status' => 'active',
            ]);

            $stop = app(Productions::class)->addStop($production, $night['event'], [
                'venue_id' => $glasgow['venue']->id,
                'seat_map_id' => $glasgow['map']->id,
                'starts_at' => now()->addMonth()->toIso8601String(),
            ]);

            $this->assertSame($production->id, $stop->series_id);
            $this->assertSame($glasgow['venue']->id, $stop->venue_id);
            // Never on sale by being copied: going on sale is a decision about a town.
            $this->assertSame('draft', $stop->status);
            // The hall's own clock. Half past seven in Glasgow is half past seven in Glasgow.
            $this->assertSame('Europe/London', $stop->timezone);

            $this->assertSame(1, TicketType::where('event_id', $stop->id)->count(),
                'the concessions travel with the show');
            $this->assertSame(
                3000,
                (int) EventPriceZone::where('event_id', $stop->id)->where('key', 'standard')->value('amount'),
                'and so does the price of a category this hall has'
            );
            $this->assertSame(0, EventPriceZone::where('event_id', $stop->id)->where('key', 'standing')->count(),
                'while a price for a category this hall has never heard of is left behind');
            $this->assertSame(0, \App\Models\EventSeatOverride::where('event_id', $stop->id)->count(),
                'and the two seats behind the pillar are the pillar in the other building');
        });
    }

    #[Test]
    public function a_chart_from_another_building_is_refused(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $production = $this->production($night);
        $leeds = $this->otherHall($night, 'Leeds', 'standard');

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $production, $leeds) {
            try {
                app(Productions::class)->addStop($production, $night['event'], [
                    // The Leeds chart, in the original building. One of the two is wrong, and
                    // nothing downstream could tell which.
                    'venue_id' => $night['venue']->id,
                    'seat_map_id' => $leeds['map']->id,
                    'starts_at' => now()->addMonth()->toIso8601String(),
                ]);

                $this->fail('A chart from another building was accepted.');
            } catch (ApiException $refusal) {
                $this->assertSame('map_not_at_venue', $refusal->errorCode());
            }
        });
    }

    #[Test]
    public function an_unpublished_chart_cannot_have_a_date_put_in_it(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $production = $this->production($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $production) {
            $venue = Venue::factory()->create(['tenant_id' => $night['tenant']->id, 'city' => 'Cardiff']);
            $map = SeatMap::create(['venue_id' => $venue->id, 'name' => 'Never published']);

            try {
                app(Productions::class)->addStop($production, $night['event'], [
                    'venue_id' => $venue->id,
                    'seat_map_id' => $map->id,
                    'starts_at' => now()->addMonth()->toIso8601String(),
                ]);

                $this->fail('A date was put into a chart that has never been published.');
            } catch (ApiException $refusal) {
                $this->assertSame('map_not_published', $refusal->errorCode());
            }
        });
    }

    #[Test]
    public function a_night_shows_the_productions_words_until_it_has_its_own(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $production = $this->production($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $production) {
            $production->forceFill([
                'description' => 'Shakespeare, in two hours, in the round.',
                'image_url' => '/media/hamlet.jpg',
                'category' => 'Theatre',
            ])->save();

            $event = Event::find($night['event']->id);

            $this->assertSame('Shakespeare, in two hours, in the round.', $event->descriptionFor());
            $this->assertSame('/media/hamlet.jpg', $event->posterFor());
            $this->assertSame('Theatre', $event->categoryFor());

            // A night with something of its own to say still wins: the last performance of a run
            // is sometimes a different evening from the first.
            $event->forceFill([
                'description' => 'The final performance, with a talk afterwards.',
                'image_url' => '/media/last-night.jpg',
            ])->save();

            $event = Event::find($event->id);

            $this->assertSame('The final performance, with a talk afterwards.', $event->descriptionFor());
            $this->assertSame('/media/last-night.jpg', $event->posterFor());
            $this->assertSame('Theatre', $event->categoryFor(), 'and inherits what it has not overridden');
        });
    }

    #[Test]
    public function the_run_adds_up_across_its_towns(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 5, amount: 3000);
        $production = $this->production($night);
        $glasgow = $this->otherHall($night, 'Glasgow', 'standard');

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $production, $glasgow) {
            app(Productions::class)->addStop($production, $night['event'], [
                'venue_id' => $glasgow['venue']->id,
                'seat_map_id' => $glasgow['map']->id,
                'starts_at' => now()->addMonth()->toIso8601String(),
            ]);

            $summary = app(Productions::class)->summary($production->fresh(), withMoney: true);

            $this->assertSame(2, $summary['totals']['dates']);
            $this->assertSame(2, $summary['venues']);
            $this->assertSame(2, $summary['cities']);
            // Fifteen chairs in the first hall, eight in the second.
            $this->assertSame(23, $summary['totals']['capacity']);
            $this->assertSame(0, $summary['totals']['sold']);
            $this->assertSame(['Glasgow'], array_values(array_filter(
                array_map(fn (array $date) => $date['city'], $summary['dates']),
                fn (?string $city) => 'Glasgow' === $city
            )));
        });
    }

    #[Test]
    public function the_takings_are_withheld_from_somebody_who_may_not_see_money(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $production = $this->production($night);
        $reader = $this->makeUser($night['tenant'], 'viewer');
        $manager = $this->makeUser($night['tenant'], 'manager');
        // A role the organiser made that has nothing to do with the programme.
        app(TenantContext::class)->runAs($night['tenant'], fn () => \App\Models\TenantRole::create([
            'tenant_id' => $night['tenant']->id,
            'key' => 'cloakroom',
            'name' => 'Cloakroom',
            'permissions' => ['checkins.view'],
        ]));

        $cloakroom = $this->makeUser($night['tenant'], 'cloakroom');

        $this->asMember($manager)->getJson("/v1/productions/{$production->id}")
            ->assertOk()
            ->assertJsonPath('totals.dates', 1)
            ->assertJsonPath('totals.revenue', 0);

        // A viewer may look at the programme — that is what a production is — and may not see the
        // takings. Null rather than nought: a figure somebody may not see must not be guessable
        // from a screen.
        $this->asMember($reader)->getJson("/v1/productions/{$production->id}")
            ->assertOk()
            ->assertJsonPath('totals.dates', 1)
            ->assertJsonPath('totals.revenue', null)
            ->assertJsonPath('dates.0.revenue', null);

        // And somebody whose role says nothing about events does not see the programme at all.
        $this->asMember($cloakroom)->getJson("/v1/productions/{$production->id}")->assertForbidden();
    }

    #[Test]
    public function a_production_with_dates_is_not_deleted_by_accident(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $production = $this->production($night);
        $manager = $this->makeUser($night['tenant'], 'manager');

        $this->asMember($manager)->deleteJson("/v1/productions/{$production->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'production_has_dates');

        app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => Event::where('series_id', $production->id)->update(['series_id' => null])
        );

        $this->asMember($manager)->deleteJson("/v1/productions/{$production->id}")->assertOk();
    }

    #[Test]
    public function the_panel_can_put_the_show_on_somewhere_else(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2200);
        $production = $this->production($night, 'The Tempest');
        $leeds = $this->otherHall($night, 'Leeds', 'standard');
        $manager = $this->makeUser($night['tenant'], 'manager');
        $reader = $this->makeUser($night['tenant'], 'viewer');

        $made = $this->asMember($manager)->postJson("/v1/productions/{$production->id}/dates", [
            'venue_id' => $leeds['venue']->id,
            'seat_map_id' => $leeds['map']->id,
            'starts_at' => now()->addMonths(2)->toIso8601String(),
        ])->assertCreated()->assertJsonPath('status', 'draft');

        $this->asMember($manager)->getJson("/v1/productions/{$production->id}")
            ->assertOk()
            ->assertJsonPath('totals.dates', 2)
            ->assertJsonPath('cities', 2);

        // Adding a town is managing events, not reading them.
        $this->asMember($reader)->postJson("/v1/productions/{$production->id}/dates", [
            'venue_id' => $leeds['venue']->id,
            'seat_map_id' => $leeds['map']->id,
            'starts_at' => now()->addMonths(3)->toIso8601String(),
        ])->assertForbidden();

        $this->assertNotNull(Event::find($made->json('id')));
    }

    #[Test]
    public function the_other_dates_of_a_tour_say_which_town_they_are_in(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $production = $this->production($night);
        $glasgow = $this->otherHall($night, 'Glasgow', 'standard');

        $stop = app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $production, $glasgow) {
            $stop = app(Productions::class)->addStop($production, $night['event'], [
                'venue_id' => $glasgow['venue']->id,
                'seat_map_id' => $glasgow['map']->id,
                'starts_at' => now()->addMonth()->toIso8601String(),
            ]);

            // Published, because the other-dates list on a site is what is on sale.
            $stop->forceFill(['status' => 'published'])->save();

            return $stop->fresh();
        });

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $site = app(\App\Domain\Sites\SiteProvisioner::class)->create($night['tenant']->name);

            \App\Models\SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'tour.test',
                'is_primary' => true,
                'verification_token' => \App\Models\SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);
        });

        $page = $this->get('http://tour.test/events/'.$night['event']->public_id);

        $page->assertOk();
        // The town, on the list of the run's other dates: "Sun 4 Oct" tells somebody in Manchester
        // nothing about whether that night is anywhere near them.
        $page->assertSee('Glasgow', false);
        $this->assertNotNull($stop);
    }
}
