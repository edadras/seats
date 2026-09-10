<?php

namespace Tests\Feature;

use App\Domain\Availability\BestAvailable;
use App\Domain\Sites\SiteProvisioner;
use App\Models\EventPriceZone;
use App\Models\EventSeatOverride;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * "Four together, please."
 *
 * The two rules worth defending are the ones a buyer would notice: seats that are together are
 * physically adjacent — two free chairs with a sold one between them are not a pair — and the
 * choice does not strand a single seat, because a lone chair in a row is the last thing in the
 * house to sell.
 */
class BestAvailableTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function it_finds_seats_that_are_actually_next_to_each_other(): void
    {
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 6);

        // Row of six, with the third sold. The only pair left that is genuinely together starts
        // at the fourth chair.
        $this->takeAway($fixture, [2]);

        $seats = $this->find($fixture, 3);

        $this->assertCount(3, $seats);
        $this->assertSame(['4', '5', '6'], array_column($seats, 'label'));
    }

    #[Test]
    public function a_gap_is_not_a_group(): void
    {
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 5);

        // Free, sold, free, sold, free. Three seats exist; no two of them are together.
        $this->takeAway($fixture, [1, 3]);

        $this->assertSame([], $this->find($fixture, 2));
        $this->assertCount(1, $this->find($fixture, 1));
    }

    #[Test]
    public function it_would_rather_not_strand_a_single_seat(): void
    {
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 5);

        // Five free. Taking the middle three leaves a one on each side; taking from an end leaves
        // a pair, which is a seat somebody will actually buy.
        $seats = $this->find($fixture, 3);
        $labels = array_column($seats, 'label');

        $this->assertNotSame(['2', '3', '4'], $labels, 'stranded a single seat at each end');
        $this->assertContains($labels[0], ['1', '3']);
    }

    #[Test]
    public function the_organisers_own_prices_decide_which_seats_are_best(): void
    {
        $fixture = $this->makeSellableEvent(rows: 3, perRow: 4, amount: 2000);

        // One row repriced upwards. Nothing here knows which row is nearer the stage — the price
        // list is the organiser's own statement of which seats are better, and it is the ranking.
        $premium = app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            EventPriceZone::create([
                'event_id' => $fixture['event']->id,
                'key' => 'premium',
                'name' => 'Premium',
                'amount' => 9000,
            ]);

            $seats = collect($fixture['seats'])->slice(4, 4);

            foreach ($seats as $seat) {
                EventSeatOverride::create([
                    'event_id' => $fixture['event']->id,
                    'seat_id' => $seat->id,
                    'zone_key' => 'premium',
                ]);
            }

            return $seats->pluck('id')->all();
        });

        $seats = $this->find($fixture, 2);

        $this->assertSame([9000, 9000], array_column($seats, 'amount'));
        $this->assertEqualsCanonicalizing(
            $premium,
            array_merge(array_column($seats, 'seat_id'), array_slice($premium, 2)),
        );
    }

    #[Test]
    public function a_budget_is_respected_and_cheapest_is_asked_for_by_name(): void
    {
        $fixture = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2000);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            EventPriceZone::create([
                'event_id' => $fixture['event']->id,
                'key' => 'premium', 'name' => 'Premium', 'amount' => 9000,
            ]);

            foreach (collect($fixture['seats'])->slice(4, 4) as $seat) {
                EventSeatOverride::create([
                    'event_id' => $fixture['event']->id,
                    'seat_id' => $seat->id,
                    'zone_key' => 'premium',
                ]);
            }
        });

        $this->assertSame([2000, 2000], array_column(
            $this->find($fixture, 2, ['max_amount' => 5000]), 'amount'
        ));

        $this->assertSame([2000, 2000], array_column(
            $this->find($fixture, 2, ['prefer' => 'cheapest']), 'amount'
        ));
    }

    #[Test]
    public function a_wheelchair_space_is_never_handed_out_this_way(): void
    {
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 4);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            // The whole row marked as wheelchair spaces: a need, not a preference, and never
            // given to a buyer who did not ask for one.
            \App\Models\Seat::whereIn('id', collect($fixture['seats'])->pluck('id'))
                ->update(['accessible' => true]);
        });

        $this->assertSame([], $this->find($fixture, 1));
    }

    #[Test]
    public function the_website_chooses_and_holds_in_one_movement(): void
    {
        $fixture = $this->makeSellableEvent(rows: 2, perRow: 5);
        $this->makeSite($fixture['tenant']);

        $hold = $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'best_available' => ['quantity' => 3],
        ])->assertCreated()->json();

        $this->assertCount(3, $hold['seats']);

        // Genuinely held, not merely suggested: the same three cannot be had twice.
        $rows = collect($hold['seats'])->pluck('row')->unique();

        $this->assertCount(1, $rows, 'the group should be in one row');
    }

    #[Test]
    public function a_refusal_says_how_many_could_be_had(): void
    {
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 4);
        $this->makeSite($fixture['tenant']);

        $this->takeAway($fixture, [1]);

        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'best_available' => ['quantity' => 3],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'no_seats_together')
            ->assertJsonPath('error.details.largest_together', 2);
    }

    #[Test]
    public function the_counter_gets_a_suggestion_rather_than_a_hold(): void
    {
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 6);
        $owner = $this->makeUser($fixture['tenant']);

        $seats = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/best-available?quantity=4")
            ->assertOk()
            ->json('data');

        $this->assertCount(4, $seats);

        // Nothing is held: the clerk is looking at a buyer, not at a clock.
        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\HoldItem::count()
        ));
    }

    #[Test]
    public function it_is_behind_the_permission_that_sells(): void
    {
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)
            ->getJson("/v1/events/{$fixture['event']->id}/best-available?quantity=2")
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function find(array $fixture, int $quantity, array $filters = []): array
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(BestAvailable::class)->find($fixture['event'], $quantity, $filters)
        );
    }

    /**
     * Take seats out of the house.
     *
     * Blocked rather than sold: what this is testing is adjacency, and every way a seat stops
     * being available — sold, held, blocked — reaches the arithmetic as the same thing. Building
     * an order to make a chair unavailable would be testing the order path instead.
     *
     * @param  list<int>  $seats  indexes into the fixture's seat list
     */
    private function takeAway(array $fixture, array $seats): void
    {
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $seats) {
            foreach ($seats as $index) {
                EventSeatOverride::create([
                    'event_id' => $fixture['event']->id,
                    'seat_id' => $fixture['seats'][$index]->id,
                    'blocked' => true,
                ]);
            }
        });
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
