<?php

namespace Tests\Feature;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\HoldService;
use App\Domain\Pricing\DemandPricing;
use App\Models\Event;
use App\Models\EventDemandStep;
use App\Models\EventPriceTier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What the room costs because of how much of it is gone.
 *
 * Timed tiers answered "what does this cost this week". What they could not answer is the question
 * a box office actually asks, which is how the night is going: a show that sold out in a morning
 * went at a price somebody guessed at in January.
 *
 * The claims under test are the same two the tiers make — one price at any moment, agreed by the
 * plan and the hold — plus the two this feature adds: the rails hold whatever the ladder does, and
 * a price never moves under somebody who already has seats in a basket.
 */
class DemandPricingTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_quiet_night_is_priced_as_written(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [['sold_from' => 50, 'kind' => 'percent', 'value' => 25]]);

            $this->assertSame(10000, $this->quoted($night), 'Nothing sold, so nothing on the price.');
        });
    }

    #[Test]
    public function the_price_climbs_as_the_house_fills(): void
    {
        // Eight seats, so each one is twelve and a half per cent of the house.
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [
                ['sold_from' => 50, 'kind' => 'percent', 'value' => 20],
                ['sold_from' => 75, 'kind' => 'percent', 'value' => 50],
            ]);

            $this->sell($night, 3);   // 37% — below the first rung
            $this->assertSame(10000, $this->quoted($night));

            $this->sell($night, 1);   // 50%
            $this->assertSame(12000, $this->quoted($night));

            $this->sell($night, 2);   // 75%
            $this->assertSame(15000, $this->quoted($night));
        });
    }

    #[Test]
    public function the_ceiling_holds_whatever_the_ladder_does(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [['sold_from' => 0, 'kind' => 'percent', 'value' => 500]], [
                'price_ceiling' => 12500,
            ]);

            // Six times the price is a typo, and a typo in a price is a night sold at the wrong
            // number. The rail is what makes that survivable.
            $this->assertSame(12500, $this->quoted($night));
        });
    }

    #[Test]
    public function the_floor_holds_it_the_other_way(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [['sold_from' => 0, 'kind' => 'percent', 'value' => -90]], [
                'price_floor' => 7500,
            ]);

            $this->assertSame(7500, $this->quoted($night));
        });
    }

    #[Test]
    public function the_floor_wins_where_somebody_has_typed_the_two_rails_the_wrong_way_round(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            // Saved past the endpoint, which refuses this — the two together say something that
            // cannot be true, and of the two, "never sell below this" has money on the other end.
            $this->ladder($night, [], ['price_floor' => 15000, 'price_ceiling' => 9000]);

            $this->assertSame(15000, $this->quoted($night));
        });
    }

    #[Test]
    public function the_timed_tier_is_adjusted_and_not_replaced(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            // Early bird takes twenty per cent off; the house is then most of the way gone.
            EventPriceTier::create([
                'event_id' => $night['event']->id,
                'name' => 'Early bird',
                'starts_at' => null,
                'ends_at' => now()->addDays(7),
                'kind' => 'percent',
                'value' => -20,
                'sort_order' => 0,
            ]);

            $this->ladder($night, [['sold_from' => 50, 'kind' => 'percent', 'value' => 50]]);
            $this->sell($night, 4);

            // The published price for this window is 8000, and the night being half gone puts
            // fifty per cent on *that* — not on the number nobody was quoted.
            $this->assertSame(12000, $this->quoted($night));
        });
    }

    #[Test]
    public function the_hold_is_snapshotted_at_the_price_the_plan_showed(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [['sold_from' => 25, 'kind' => 'percent', 'value' => 30]]);
            $this->sell($night, 2);

            $seat = $night['seats'][5];
            $hold = app(HoldService::class)->create($night['event']->fresh(), [$seat->id], 'session-demand');

            $this->assertSame(13000, (int) $hold->total_amount,
                'The number quoted on the plan is the number written into the hold.');
        });
    }

    #[Test]
    public function a_price_that_moves_does_not_move_under_somebody_already_holding_seats(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [['sold_from' => 50, 'kind' => 'percent', 'value' => 40]]);

            $hold = app(HoldService::class)->create($night['event']->fresh(), [$night['seats'][6]->id], 'sess-early');

            $this->assertSame(10000, (int) $hold->total_amount);

            // The house fills up while they are typing their address.
            $this->sell($night, 4);

            /*
             * Still ten thousand.
             *
             * A price that changed under a buyer between the plan and the payment is the worst
             * thing this feature could do, and it is the reason the hold carries a snapshot at all.
             */
            $this->assertSame(10000, (int) $hold->fresh()->total_amount);
        });
    }

    #[Test]
    public function holds_are_not_counted_as_sold(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [['sold_from' => 50, 'kind' => 'percent', 'value' => 40]]);

            // Four of eight seats in somebody's basket — half the house, none of it sold.
            app(HoldService::class)->create($night['event']->fresh(), [
                $night['seats'][0]->id, $night['seats'][1]->id,
                $night['seats'][2]->id, $night['seats'][3]->id,
            ], 'sess-basket');

            /*
             * A burst of holds that expire would ratchet the price up and drop it again an hour
             * later, which is a price nobody can explain and a screen that argues with itself.
             */
            $this->assertSame(10000, $this->quoted($night));
        });
    }

    #[Test]
    public function blocked_seats_are_not_part_of_the_house(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            // Four held back for the house: the room is four seats, not eight.
            foreach ([0, 1, 2, 3] as $index) {
                \App\Models\EventSeatOverride::create([
                    'tenant_id' => $night['tenant']->id,
                    'event_id' => $night['event']->id,
                    'seat_id' => $night['seats'][$index]->id,
                    'blocked' => true,
                ]);
            }

            $this->ladder($night, [['sold_from' => 50, 'kind' => 'percent', 'value' => 40]]);
            $this->sell($night, 2, from: 4);

            // Two of the four sellable seats is half, so the rung is reached — which it never would
            // be if the forty held back counted towards a house that could not sell them.
            $this->assertSame(4, app(DemandPricing::class)->capacity($night['event']->fresh()));
            $this->assertSame(14000, $this->quoted($night));
        });
    }

    #[Test]
    public function nothing_moves_until_somebody_turns_it_on(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->ladder($night, [['sold_from' => 0, 'kind' => 'percent', 'value' => 50]], [
                'demand_pricing' => false,
            ]);

            // A house forbidden by its funding to move prices can still write the ladder down and
            // look at it. Nothing changes until the switch.
            $this->assertSame(10000, $this->quoted($night));
        });
    }

    #[Test]
    public function an_explicit_seat_price_is_never_moved(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            \App\Models\EventSeatOverride::create([
                'tenant_id' => $night['tenant']->id,
                'event_id' => $night['event']->id,
                'seat_id' => $night['seats'][7]->id,
                'amount' => 4200,
            ]);

            $this->ladder($night, [['sold_from' => 0, 'kind' => 'percent', 'value' => 50]]);

            $seats = app(AvailabilityService::class)->forEvent($night['event']->fresh());
            $named = collect($seats)->firstWhere('seat_id', $night['seats'][7]->id);

            // A house that has typed an exact number against a seat has said what it costs.
            $this->assertSame(4200, $named['amount']);
        });
    }

    #[Test]
    public function the_organiser_sets_the_switch_the_ladder_and_the_rails_together(): void
    {
        $night = $this->night();
        $owner = $this->makeUser($night['tenant']);

        $body = $this->actingAs($owner)
            ->putJson('/v1/events/'.$night['event']->id.'/demand-pricing', [
                'demand_pricing' => true,
                'price_floor' => 8000,
                'price_ceiling' => 20000,
                'steps' => [
                    ['name' => 'Filling up', 'sold_from' => 60, 'kind' => 'percent', 'value' => 15],
                    ['name' => 'Nearly gone', 'sold_from' => 90, 'kind' => 'percent', 'value' => 40],
                ],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($body['demand_pricing']);
        $this->assertCount(2, $body['data']);
        // Sorted by threshold whatever order they arrived in, so the screen reads as a ladder.
        $this->assertSame([60, 90], array_column($body['data'], 'sold_from'));
        $this->assertSame(0, $body['sold_percent']);
        $this->assertSame(8, $body['capacity']);
    }

    #[Test]
    public function rails_the_wrong_way_round_are_refused_rather_than_resolved(): void
    {
        $night = $this->night();
        $owner = $this->makeUser($night['tenant']);

        $this->actingAs($owner)
            ->putJson('/v1/events/'.$night['event']->id.'/demand-pricing', [
                'demand_pricing' => true,
                'price_floor' => 20000,
                'price_ceiling' => 8000,
                'steps' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rails_crossed');
    }

    #[Test]
    public function two_rungs_at_the_same_percentage_are_refused(): void
    {
        $night = $this->night();
        $owner = $this->makeUser($night['tenant']);

        $this->actingAs($owner)
            ->putJson('/v1/events/'.$night['event']->id.'/demand-pricing', [
                'demand_pricing' => true,
                'steps' => [
                    ['sold_from' => 80, 'kind' => 'percent', 'value' => 10],
                    ['sold_from' => 80, 'kind' => 'amount', 'value' => 500],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'demand_steps_collide');
    }

    #[Test]
    public function somebody_who_may_not_set_a_price_may_not_read_the_rules_for_changing_it(): void
    {
        $night = $this->night();
        $door = $this->makeUser($night['tenant'], 'door');

        $this->actingAs($door)
            ->getJson('/v1/events/'.$night['event']->id.'/demand-pricing')
            ->assertForbidden();

        $this->actingAs($door)
            ->putJson('/v1/events/'.$night['event']->id.'/demand-pricing', [
                'demand_pricing' => true,
                'steps' => [],
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** @return array{tenant: \App\Models\Tenant, event: Event, seats: mixed} */
    private function night(int $amount = 10000): array
    {
        return $this->makeSellableEvent(rows: 2, perRow: 4, amount: $amount);
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function ladder(array $night, array $steps, array $event = []): void
    {
        Event::whereKey($night['event']->id)->update($event + ['demand_pricing' => true]);

        app(DemandPricing::class)->replace($night['event']->fresh(), $steps);
    }

    /** What the first seat on the plan is quoted at right now. */
    private function quoted(array $night): int
    {
        return (int) app(AvailabilityService::class)->forEvent($night['event']->fresh())[0]['amount'];
    }

    /**
     * Sell seats outright, which is what the ladder counts.
     *
     * Written straight to the allocations rather than driven through a checkout: what is under test
     * is the price of the *next* seat, and putting four real orders through the shop to move one
     * percentage would be four checkouts' worth of unrelated ways for the test to fail.
     */
    private function sell(array $night, int $howMany, ?int $from = null): void
    {
        // Carries on where the last call stopped, so a test can fill the house a few seats at a
        // time and watch the price climb.
        $from ??= $this->soldSoFar;
        $this->soldSoFar = $from + $howMany;

        $client = $this->clients[$night['tenant']->id]
            ??= \App\Models\ApiClient::factory()->create(['tenant_id' => $night['tenant']->id]);

        for ($index = $from; $index < $from + $howMany; $index++) {
            \App\Models\Allocation::create([
                'tenant_id' => $night['tenant']->id,
                'event_id' => $night['event']->id,
                'seat_id' => $night['seats'][$index]->id,
                'api_client_id' => $client->id,
                'external_order_id' => 'sold-'.$index,
                'status' => 'active',
                'amount' => 10000,
                'currency' => 'EUR',
                'seat_map_version_id' => $night['event']->seat_map_version_id,
                'section_name' => 'Stalls',
                'row_name' => 'A',
                'seat_label' => (string) ($index + 1),
                'allocated_at' => now(),
            ]);
        }
    }

    /** @var array<string, \App\Models\ApiClient> */
    private array $clients = [];

    private int $soldSoFar = 0;
}
