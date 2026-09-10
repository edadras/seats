<?php

namespace Tests\Feature;

use App\Domain\Seasons\SeasonCheckout;
use App\Domain\Seasons\Seasons;
use App\Domain\Sites\SiteProvisioner;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeries;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\SeasonBooking;
use App\Models\SeasonPass;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The same seats, every night of a run, bought once.
 *
 * The claim these tests are here to hold up is that **a season ticket is a way of buying and not a
 * new kind of admission**: what comes out of a subscription is exactly what buying each night
 * separately would have produced — one order, one allocation and one ticket per night — so nothing
 * at the door, in availability or in per-event revenue has to know a season exists.
 *
 * The other half is the money. One card charge, and the saving split across the nights so that
 * every night's order still adds up on its own and the nights sum to exactly what was charged. The
 * apportionment tests are the fussiest here because that is where a penny would go missing.
 */
class SeasonTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------ the arithmetic */

    #[Test]
    public function a_saving_is_split_across_the_nights_and_sums_to_itself(): void
    {
        $seasons = app(Seasons::class);

        // Three nights at different prices, and a saving that does not divide evenly by any of them.
        $shares = $seasons->apportion(1000, [5000, 3000, 2000]);

        $this->assertSame([500, 300, 200], $shares);
        $this->assertSame(1000, array_sum($shares));

        // The awkward one: a third of a penny each way.
        $awkward = $seasons->apportion(100, [1000, 1000, 1000]);

        $this->assertSame(100, array_sum($awkward));
        // Largest remainder, ties to the earlier night — so the same input always splits the same.
        $this->assertSame([34, 33, 33], $awkward);
    }

    #[Test]
    public function a_saving_never_exceeds_the_run_and_zero_stays_zero(): void
    {
        $seasons = app(Seasons::class);

        $this->assertSame([2000, 1000], $seasons->apportion(9999, [2000, 1000]));
        $this->assertSame([0, 0], $seasons->apportion(0, [2000, 1000]));
        $this->assertSame([], $seasons->apportion(500, []));
    }

    #[Test]
    public function a_percentage_pass_takes_that_percentage_off_the_run(): void
    {
        $pass = new SeasonPass(['discount_kind' => 'percent', 'discount_value' => 20]);

        $this->assertSame(2000, $pass->discountOn(10000));
        // Rounded half up, once. Not accumulated night by night.
        $this->assertSame(667, $pass->discountOn(3333));
        // Never more than the run: a pass worth more than the tickets would pay the buyer to come.
        $this->assertSame(1000, (new SeasonPass([
            'discount_kind' => 'fixed', 'discount_value' => 9999,
        ]))->discountOn(1000));
    }

    /* ------------------------------------------------------------------ the spread */

    #[Test]
    public function the_same_seats_are_held_on_every_night_of_the_run(): void
    {
        $run = $this->makeRun(3);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run, ['discount_kind' => 'percent', 'discount_value' => 10]);

        $this->subscribe($run, $pass, [0, 1]);
        $this->get('http://northgate.test/season/checkout')->assertOk();

        // One hold per night, all for the same two seats.
        $held = $this->inTenant($run, fn () => Hold::where('status', 'active')->with('items')->get());

        $this->assertCount(3, $held);
        $this->assertSame([2, 2, 2], $held->map(fn (Hold $hold) => $hold->items->count())->all());
        $this->assertCount(3, $held->pluck('event_id')->unique());
    }

    #[Test]
    public function a_night_whose_seats_are_gone_takes_the_whole_run_with_it(): void
    {
        $run = $this->makeRun(3);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run);

        // Somebody else, in their own browser, takes seat 0 on the second night.
        $this->hold($run, 1, [0])->assertCreated();
        $this->newBrowser();

        $this->subscribe($run, $pass, [0]);

        $this->get('http://northgate.test/season/checkout')
            ->assertRedirect()
            ->assertSessionHas('seatmap_message');

        /*
         * All or nothing. Two holds exist: the other buyer's second night, and this buyer's own
         * first night. The third night — which *could* have been held — was not left out of the
         * sale while a subscriber who cannot complete works out what to do.
         */
        $this->assertSame(2, $this->inTenant($run, fn () => Hold::where('status', 'active')->count()));
    }

    #[Test]
    public function reloading_the_checkout_does_not_take_a_second_set_of_seats(): void
    {
        $run = $this->makeRun(3);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run);

        $this->subscribe($run, $pass, [0]);

        $this->get('http://northgate.test/season/checkout')->assertOk();
        $this->get('http://northgate.test/season/checkout')->assertOk();
        $this->get('http://northgate.test/season/checkout')->assertOk();

        $this->assertSame(3, $this->inTenant($run, fn () => Hold::where('status', 'active')->count()));
    }

    /* ------------------------------------------------------------------ the purchase */

    #[Test]
    public function a_subscription_is_an_ordinary_order_per_night(): void
    {
        $run = $this->makeRun(3);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run, ['discount_kind' => 'percent', 'discount_value' => 25]);

        $this->subscribe($run, $pass, [0]);
        $this->buy();

        $booking = $this->booking($run);

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(3, $booking->orders()->count());

        // Every night produced what buying it on its own would have produced.
        foreach ($booking->orders()->with('allocations.ticket')->get() as $order) {
            $this->assertSame('confirmed', $order->status);
            $this->assertCount(1, $order->allocations);
            $this->assertNotNull($order->allocations->first()->ticket);
        }

        // Three nights at €25 is €75, less a quarter: €56.25, and the nights add up to it exactly.
        $this->assertSame(5625, (int) $booking->total_amount);
        $this->assertSame(1875, (int) $booking->discount_amount);
        $this->assertSame(5625, (int) $booking->orders()->sum('total_amount'));
    }

    #[Test]
    public function each_night_carries_its_own_share_of_the_saving(): void
    {
        $run = $this->makeRun(2);
        $this->makeSite($run['tenant']);
        // The second night is dearer, so an equal split would be the wrong split.
        $this->inTenant($run, fn () => EventPriceZone::where('event_id', $run['nights'][1]->id)
            ->where('key', 'standard')->update(['amount' => 7500]));

        $pass = $this->pass($run, ['discount_kind' => 'fixed', 'discount_value' => 1000]);

        $this->subscribe($run, $pass, [0]);
        $this->buy();

        $booking = $this->booking($run);
        $shares = $booking->orders()->get()
            ->map(fn (ExternalOrder $order) => (int) ($order->metadata['totals']['discount'] ?? 0))
            ->sort()
            ->values()
            ->all();

        // €25 and €75 out of €100: a quarter and three quarters of the ten euros.
        $this->assertSame([250, 750], $shares);
        $this->assertSame(1000, array_sum($shares));
    }

    #[Test]
    public function a_repeated_submit_buys_the_run_once(): void
    {
        $run = $this->makeRun(3);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run);

        $this->subscribe($run, $pass, [0]);

        // The season basket is cleared on the first submit, so the retry is made against the same
        // hold directly — which is what a browser resending the POST actually does.
        $tokens = $this->sessionBasket();
        $this->buy();
        $this->restoreBasket($tokens);
        $this->buy();

        $this->assertSame(1, $this->inTenant($run, fn () => SeasonBooking::count()));
        $this->assertSame(3, $this->inTenant($run, fn () => ExternalOrder::count()));
    }

    #[Test]
    public function a_run_given_away_needs_no_gateway(): void
    {
        $run = $this->makeRun(2);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run, ['discount_kind' => 'percent', 'discount_value' => 100]);

        $this->subscribe($run, $pass, [0]);

        // No `gateway` field at all: the page does not offer one when there is nothing to pay.
        $this->post('http://northgate.test/season/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test',
        ])->assertRedirect();

        $booking = $this->booking($run);

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(0, (int) $booking->total_amount);
    }

    #[Test]
    public function a_run_that_costs_something_still_has_to_choose_a_gateway(): void
    {
        $run = $this->makeRun(2);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run);

        $this->subscribe($run, $pass, [0]);

        $this->post('http://northgate.test/season/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test',
        ])->assertSessionHasErrors('gateway');
    }

    #[Test]
    public function a_flexible_pass_is_for_the_nights_the_buyer_ticked(): void
    {
        $run = $this->makeRun(4);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run, ['kind' => 'choose', 'nights' => 2]);

        // Two of the four, and the first of them is where the seats are picked.
        $this->post('http://northgate.test/season/'.$pass->id, [
            'nights' => [$run['nights'][0]->id, $run['nights'][2]->id],
        ])->assertRedirect();

        $this->hold($run, 0, [0])->assertCreated();
        $this->buy();

        $booking = $this->booking($run);

        $this->assertSame(2, $booking->orders()->count());
        $this->assertEqualsCanonicalizing(
            [$run['nights'][0]->id, $run['nights'][2]->id],
            $booking->orders()->pluck('event_id')->all(),
        );
    }

    #[Test]
    public function a_flexible_pass_refuses_fewer_nights_than_it_promises(): void
    {
        $run = $this->makeRun(4);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run, ['kind' => 'choose', 'nights' => 3]);

        $this->post('http://northgate.test/season/'.$pass->id, [
            'nights' => [$run['nights'][0]->id],
        ])->assertSessionHasErrors('nights');
    }

    #[Test]
    public function the_run_is_offered_on_a_night_of_it(): void
    {
        $run = $this->makeRun(3);
        $this->makeSite($run['tenant']);
        $this->pass($run, ['name' => 'Full season']);

        $this->get('http://northgate.test/events/'.$run['nights'][0]->public_id)
            ->assertOk()
            ->assertSee('Full season');
    }

    #[Test]
    public function a_paused_pass_is_offered_to_nobody(): void
    {
        $run = $this->makeRun(3);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run, ['name' => 'Full season', 'status' => 'paused']);

        $this->get('http://northgate.test/events/'.$run['nights'][0]->public_id)
            ->assertOk()
            ->assertDontSee('Full season');

        $this->get('http://northgate.test/season/'.$pass->id)->assertNotFound();
    }

    /* ------------------------------------------------------------------ the panel */

    #[Test]
    public function only_a_real_run_can_carry_a_season_ticket(): void
    {
        $run = $this->makeRun(3);
        $user = $this->makeUser($run['tenant']);

        $this->actingAs($user)->getJson('/v1/season-passes/series')
            ->assertOk()
            ->assertJsonPath('data.0.nights', 3);

        // A one-night "run" is a night. It is not offered, so a pass cannot be hung on it.
        $lonely = $this->makeRun(1);
        $owner = $this->makeUser($lonely['tenant']);

        $this->actingAs($owner)->getJson('/v1/season-passes/series')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_flexible_pass_has_to_say_how_many_nights(): void
    {
        $run = $this->makeRun(3);
        $user = $this->makeUser($run['tenant']);

        $this->actingAs($user)->postJson('/v1/season-passes', [
            'series_id' => $run['series']->id,
            'name' => 'Any few nights',
            'kind' => 'choose',
            'currency' => 'EUR',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'season_needs_nights');
    }

    #[Test]
    public function a_pass_somebody_bought_is_paused_rather_than_deleted(): void
    {
        $run = $this->makeRun(2);
        $this->makeSite($run['tenant']);
        $pass = $this->pass($run);
        $user = $this->makeUser($run['tenant']);

        $this->subscribe($run, $pass, [0]);
        $this->buy();

        $this->actingAs($user)->deleteJson('/v1/season-passes/'.$pass->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'season_pass_sold');

        $this->actingAs($user)->patchJson('/v1/season-passes/'.$pass->id, ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('live', false);
    }

    #[Test]
    public function the_screen_shows_what_would_sell_today_and_not_what_once_did(): void
    {
        $run = $this->makeRun(3);
        $pass = $this->pass($run);
        $user = $this->makeUser($run['tenant']);

        $this->actingAs($user)->getJson('/v1/season-passes/'.$pass->id)
            ->assertOk()
            ->assertJsonPath('nights_on_sale', 3)
            ->assertJsonPath('live', true);

        // The run has been and gone. Nothing is left to sell, and the screen says so rather than
        // sitting there looking active.
        $this->inTenant($run, fn () => Event::where('series_id', $run['series']->id)
            ->update(['starts_at' => now()->subMonth(), 'ends_at' => now()->subMonth()]));

        $this->actingAs($user)->getJson('/v1/season-passes/'.$pass->id)
            ->assertOk()
            ->assertJsonPath('nights_on_sale', 0)
            ->assertJsonPath('live', false);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /**
     * A run of `$nights` nights, all on one map, in one series.
     *
     * @return array{tenant: mixed, series: EventSeries, nights: list<Event>, seats: mixed}
     */
    private function makeRun(int $nights): array
    {
        $fixture = $this->makeSellableEvent(amount: 2500);

        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $nights) {
            $series = EventSeries::create([
                'tenant_id' => $fixture['tenant']->id,
                'name' => 'The Winter Run',
                'slug' => 'winter-run-'.Str::lower(Str::random(6)),
            ]);

            $first = $fixture['event'];
            $first->forceFill(['series_id' => $series->id, 'name' => 'Night 1'])->save();
            $made = [$first];

            for ($n = 2; $n <= $nights; $n++) {
                $night = Event::create([
                    'venue_id' => $first->venue_id,
                    'series_id' => $series->id,
                    'seat_map_id' => $first->seat_map_id,
                    'seat_map_version_id' => $first->seat_map_version_id,
                    'public_id' => 'evt_'.Str::lower(Str::random(20)),
                    'name' => 'Night '.$n,
                    'status' => 'published',
                    'starts_at' => now()->addWeeks($n),
                    'timezone' => 'Europe/Berlin',
                    'currency' => 'EUR',
                ]);

                foreach (['standard' => 2500, 'standing' => 1250] as $key => $amount) {
                    EventPriceZone::create([
                        'event_id' => $night->id,
                        'key' => $key,
                        'name' => ucfirst($key),
                        'amount' => $amount,
                    ]);
                }

                $made[] = $night;
            }

            return $fixture + ['series' => $series, 'nights' => $made];
        });
    }

    private function pass(array $run, array $attributes = []): SeasonPass
    {
        return $this->inTenant($run, fn () => SeasonPass::create($attributes + [
            'tenant_id' => $run['tenant']->id,
            'series_id' => $run['series']->id,
            'name' => 'Full season',
            'kind' => 'all',
            'discount_kind' => 'percent',
            'discount_value' => 20,
            'currency' => 'EUR',
        ]));
    }

    /** Choose the pass, then pick the seats on the first night, the way a buyer does. */
    private function subscribe(array $run, SeasonPass $pass, array $seats): void
    {
        $this->post('http://northgate.test/season/'.$pass->id)->assertRedirect();
        $this->hold($run, 0, $seats)->assertCreated();
    }

    /** @param  list<int>  $seats */
    private function hold(array $run, int $night, array $seats)
    {
        return $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $run['nights'][$night]->public_id,
            'seat_ids' => array_map(fn (int $i) => $run['seats'][$i]->id, $seats),
        ]);
    }

    /** A different person, in a different browser, on the same site. */
    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
    }

    private function buy(): void
    {
        $this->post('http://northgate.test/season/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function sessionBasket(): array
    {
        return [
            'seatmap_hold' => session('seatmap_hold'),
            'seatmap_season' => session('seatmap_season'),
            'seatmap_season_nights' => session('seatmap_season_nights'),
            'seatmap_season_holds' => session('seatmap_season_holds'),
        ];
    }

    private function restoreBasket(array $basket): void
    {
        $this->withSession(array_filter($basket, fn ($value) => null !== $value));
    }

    private function booking(array $run): SeasonBooking
    {
        return $this->inTenant(
            $run,
            fn () => SeasonBooking::orderByDesc('created_at')->firstOrFail()
        );
    }

    private function inTenant(array $run, callable $work)
    {
        return app(TenantContext::class)->runAs($run['tenant'], $work);
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
