<?php

namespace Tests\Feature;

use App\Domain\Insights\SalesPace;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\EventView;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * "Two hundred sold" is not an answer.
 *
 * Two hundred out of a thousand people who looked is a pricing problem; two hundred out of two
 * hundred and twelve is a marketing one, and the remedies are opposite. So this covers the two
 * things that make a total mean something: a rate, and what happened to the people who looked.
 *
 * The counting of looks is the only number on this platform that cannot be recomputed from what
 * was sold, so it is the one thing here with a table of its own — and the tests below are as much
 * about what that table does *not* hold as about what it does.
 */
class SalesPaceTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* --------------------------------------------------------------------- counting looks */

    #[Test]
    public function two_people_looking_at_once_are_both_counted(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->inTenant($fixture, function () use ($fixture) {
            $event = Event::findOrFail($fixture['event']->id);
            $pace = app(SalesPace::class);

            $pace->record($event, 'site');
            $pace->record($event, 'site');
            $pace->record($event, 'embed');

            // One row per day per source, raised in place: a counter, not a log.
            $this->assertSame(2, EventView::where('source', 'site')->sum('views'));
            $this->assertSame(1, EventView::where('source', 'embed')->sum('views'));
            $this->assertSame(2, EventView::count());
        });
    }

    #[Test]
    public function the_page_is_worth_more_than_the_count_of_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // The table is gone from under it. The page still renders, because a visitor did not come
        // here to be counted.
        \Illuminate\Support\Facades\Schema::drop('event_views');

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
    }

    #[Test]
    public function opening_the_page_counts_a_look_and_nothing_about_the_looker(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();

        $row = $this->inTenant($fixture, fn () => EventView::firstOrFail());

        $this->assertSame(1, $row->views);
        $this->assertSame('site', $row->source);

        // Nothing that could be a person: no address, no identifier, nothing to erase later. The
        // columns are the whole promise, so the whole promise is asserted.
        $this->assertEqualsCanonicalizing(
            ['id', 'tenant_id', 'event_id', 'day', 'source', 'views', 'created_at', 'updated_at'],
            \Illuminate\Support\Facades\Schema::getColumnListing('event_views'),
        );
    }

    #[Test]
    public function a_picker_on_somebody_else_s_page_is_counted_apart(): void
    {
        $fixture = $this->makeSellableEvent();

        // The event call, which a picker makes once per page — not the availability poll beside
        // it, which it makes every few seconds and which would report one tab as an audience.
        $this->getJson('/v1/embed/events/'.$fixture['event']->public_id)->assertOk();
        $this->getJson('/v1/embed/events/'.$fixture['event']->public_id.'/availability')->assertOk();

        $rows = $this->inTenant($fixture, fn () => EventView::get());

        $this->assertCount(1, $rows);
        $this->assertSame('embed', $rows[0]->source);
        $this->assertSame(1, $rows[0]->views);
    }

    /* ---------------------------------------------------------------------------- the curve */

    #[Test]
    public function the_curve_has_a_row_for_every_day_including_the_quiet_ones(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->sold($fixture, 0, now()->subDays(2));
        $this->sold($fixture, 1, now());

        $pace = $this->pace($fixture, 5);

        $this->assertCount(5, $pace['curve']);
        $this->assertSame([0, 0, 1, 0, 1], array_column($pace['curve'], 'places'));

        // The running total is the night's, not the window's — it counts what was sold before the
        // window opened as well, or every chart would start at zero and lie about how full it is.
        $this->assertSame([0, 0, 1, 1, 2], array_column($pace['curve'], 'sold_so_far'));
    }

    #[Test]
    public function a_seat_sold_before_the_window_still_counts_towards_the_running_total(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->sold($fixture, 0, now()->subDays(40));

        $pace = $this->pace($fixture, 7);

        $this->assertSame(0, $pace['curve'][0]['places']);
        $this->assertSame(1, $pace['curve'][0]['sold_so_far']);
    }

    #[Test]
    public function the_takings_are_left_out_for_somebody_who_may_not_see_them(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->sold($fixture, 0, now());

        $withMoney = $this->pace($fixture, 7, true);
        $without = $this->pace($fixture, 7, false);

        $this->assertSame(2500, $withMoney['curve'][6]['amount']);
        $this->assertSame('EUR', $withMoney['currency']);

        // Absent, not zero: a zero is a number somebody would go on to add up.
        $this->assertArrayNotHasKey('amount', $without['curve'][6]);
        $this->assertArrayNotHasKey('amount', $without['funnel']);
        $this->assertNull($without['currency']);
    }

    /* ----------------------------------------------------------------------------- the rate */

    #[Test]
    public function the_rate_is_the_last_week_and_it_says_how_many_days_it_counted(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);

        // Seven a day for two days, nothing since: fourteen over seven days is two a day.
        foreach (range(0, 6) as $i) {
            $this->sold($fixture, $i, now()->subDay());
        }

        foreach (range(7, 13) as $i) {
            $this->sold($fixture, $i, now());
        }

        $pace = $this->pace($fixture, 30)['pace'];

        $this->assertSame(7, $pace['days_counted']);
        $this->assertSame(2.0, $pace['daily']);
        $this->assertSame(14, $pace['sold']);
    }

    #[Test]
    public function a_night_selling_nothing_sells_out_on_no_date(): void
    {
        $fixture = $this->makeSellableEvent();

        $pace = $this->pace($fixture, 30)['pace'];

        $this->assertSame(0.0, $pace['daily']);
        // Not a date a very long way off, which would read as an answer.
        $this->assertNull($pace['sells_out_on']);
        $this->assertFalse($pace['sold_out']);
    }

    #[Test]
    public function a_rate_that_runs_past_the_doors_is_not_a_sell_out_date(): void
    {
        // Fifteen seats, one sold in the last week, and the doors open in seven days: the line
        // reaches the last seat long after everybody has gone home.
        $fixture = $this->makeSellableEvent();
        $this->sold($fixture, 0, now()->subDays(3));

        $pace = $this->pace($fixture, 30)['pace'];

        $this->assertNull($pace['sells_out_on']);
        $this->assertSame(7, $pace['days_to_doors']);
        // The straight line still says where the night lands, and never above the house.
        $this->assertLessThanOrEqual($pace['capacity'], $pace['projected_sold']);
    }

    #[Test]
    public function a_night_that_is_selling_fast_is_given_the_date(): void
    {
        $fixture = $this->makeSellableEvent(rows: 3, perRow: 5);

        // Twelve of the fifteen gone in the last two days.
        foreach (range(0, 11) as $i) {
            $this->sold($fixture, $i, now()->subDay());
        }

        $pace = $this->pace($fixture, 30)['pace'];

        $this->assertSame(3, $pace['remaining']);
        $this->assertNotNull($pace['sells_out_on']);
        $this->assertSame(0.8, $pace['sold_share']);
    }

    /* --------------------------------------------------------------------------- the funnel */

    #[Test]
    public function the_funnel_counts_each_step_where_it_can_be_seen(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);

        // Four people looked. Two of them chose seats. One of those paid.
        foreach (range(1, 4) as $ignored) {
            $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        }

        $this->hold($fixture, [0])->assertCreated();
        $this->newBrowser();
        $this->hold($fixture, [1])->assertCreated();
        $this->checkout($fixture);

        $funnel = $this->pace($fixture, 7)['funnel'];

        $this->assertSame(4, $funnel['looked']);
        $this->assertSame(2, $funnel['baskets']);
        $this->assertSame(1, $funnel['checkouts']);
        $this->assertSame(1, $funnel['bought']);
        $this->assertSame(0.5, $funnel['basket_rate']);
        $this->assertSame(0.25, $funnel['overall_rate']);
    }

    #[Test]
    public function a_step_with_nothing_above_it_has_no_rate_at_all(): void
    {
        $fixture = $this->makeSellableEvent();

        $funnel = $this->pace($fixture, 7)['funnel'];

        // Null, not zero: "nobody bought" and "nobody looked" are different things to be told.
        $this->assertSame(0, $funnel['looked']);
        $this->assertNull($funnel['basket_rate']);
        $this->assertNull($funnel['overall_rate']);
    }

    /* ------------------------------------------------------------------------- the endpoint */

    #[Test]
    public function the_screen_is_behind_the_programme_and_the_money_behind_the_money(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->sold($fixture, 0, now());

        /*
         * A door volunteer holds `events.view` and not `reports.orders.view`, so they get the
         * shape of the sale and not a penny of it — the same split EventStats makes, and the
         * reason the money is a separate argument rather than a separate method.
         */
        $body = $this->actingAs($this->makeUser($fixture['tenant'], 'door'))
            ->getJson('/v1/events/'.$fixture['event']->id.'/pace')
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['pace']['sold']);
        $this->assertNull($body['currency']);
        $this->assertArrayNotHasKey('amount', $body['funnel']);
        $this->assertArrayNotHasKey('amount', $body['curve'][0]);

        $owner = $this->actingAs($this->makeUser($fixture['tenant']))
            ->getJson('/v1/events/'.$fixture['event']->id.'/pace?days=7')
            ->assertOk()
            ->json();

        $this->assertCount(7, $owner['curve']);
        $this->assertSame(2500, $owner['curve'][6]['amount']);
        // Present, and nought: nothing was bought through a checkout in the window, which is a
        // different statement from "you may not know what it took".
        $this->assertArrayHasKey('amount', $owner['funnel']);
        $this->assertSame('EUR', $owner['currency']);
    }

    #[Test]
    public function another_account_s_night_is_not_a_night_this_one_can_ask_about(): void
    {
        $mine = $this->makeSellableEvent($this->makeTenant('Mine'));
        $theirs = $this->makeSellableEvent($this->makeTenant('Theirs'));

        $this->actingAs($this->makeUser($mine['tenant']))
            ->getJson('/v1/events/'.$theirs['event']->id.'/pace')
            ->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function pace(array $fixture, int $days, bool $withMoney = true): array
    {
        return $this->inTenant($fixture, fn () => app(SalesPace::class)->forEvent(
            Event::findOrFail($fixture['event']->id),
            $days,
            $withMoney,
        ));
    }

    /** One seat, sold on a given day, written straight in: the curve reads allocations. */
    private function sold(array $fixture, int $seat, \DateTimeInterface $when): void
    {
        $this->inTenant($fixture, fn () => Allocation::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'seat_id' => $fixture['seats'][$seat]->id,
            'api_client_id' => $this->client($fixture),
            'external_order_id' => 'ord-'.$seat,
            'status' => 'active',
            'amount' => 2500,
            'currency' => 'EUR',
            'seat_map_version_id' => $fixture['event']->seat_map_version_id,
            'section_name' => 'Stalls',
            'row_name' => 'A',
            'seat_label' => (string) ($seat + 1),
            'allocated_at' => $when,
        ]));
    }

    private function client(array $fixture): string
    {
        return $this->inTenant($fixture, fn () => \App\Models\ApiClient::firstOrCreate(
            ['tenant_id' => $fixture['tenant']->id, 'kind' => 'box_office'],
            ['name' => 'Box office', 'status' => 'active'],
        )->id);
    }

    /** @param  list<int>  $seats */
    private function hold(array $fixture, array $seats)
    {
        return $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ]);
    }

    /** Take the hold that is in this browser's session all the way to paid. */
    private function checkout(array $fixture): void
    {
        $this->post('http://northgate.test/checkout', [
            'name' => 'A buyer',
            'email' => 'buyer@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
    }

    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
    }

    private function inTenant(array $fixture, callable $work)
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], $work);
    }

    private function makeSite($tenant): \App\Models\Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(\App\Domain\Sites\SiteProvisioner::class)->create($tenant->name);

            \App\Models\SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.test',
                'is_primary' => true,
                'verification_token' => \App\Models\SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
