<?php

namespace Tests\Feature;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\HoldService;
use App\Domain\Pricing\PriceTiers;
use App\Exceptions\ApiException;
use App\Models\EventPriceTier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What a ticket costs today, and what it will cost next month.
 *
 * The claim under test is that there is exactly one price at any moment and that the whole platform
 * agrees about it: the number on the plan, the number in the hold's snapshot, and the number on the
 * order are the same number. A tier that moved the picker's prices but not the hold's would be a
 * buyer told €80 and charged €100, which is worse than having no tiers at all.
 *
 * The second claim is that nothing has to happen at midnight. There is no job, no column and no
 * switch: the tier in force is worked out from the clock every time a price is read, so a platform
 * that was asleep at the deadline still charges the new price on the first sale after it.
 */
class PriceTierTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** @return array{tenant: \App\Models\Tenant, event: \App\Models\Event, seats: mixed} */
    private function night(int $amount = 10000): array
    {
        return $this->makeSellableEvent(rows: 2, perRow: 4, amount: $amount);
    }

    private function tier(string $event, array $overrides = []): EventPriceTier
    {
        return EventPriceTier::create(array_merge([
            'event_id' => $event,
            'name' => 'Early bird',
            'starts_at' => null,
            'ends_at' => now()->addDays(7),
            'kind' => 'percent',
            'value' => -20,
            'sort_order' => 0,
        ], $overrides));
    }

    #[Test]
    public function a_tier_moves_what_the_buyer_is_quoted(): void
    {
        $night = $this->night(10000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $before = app(AvailabilityService::class)->forEvent($night['event']);

            $this->assertSame(10000, $before[0]['amount']);

            $this->tier($night['event']->id);

            $after = app(AvailabilityService::class)->forEvent($night['event']->fresh());

            $this->assertSame(8000, $after[0]['amount'], 'twenty per cent off a hundred euros');
        });
    }

    #[Test]
    public function the_hold_is_snapshotted_at_the_price_the_plan_showed(): void
    {
        $night = $this->night(10000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->tier($night['event']->id);

            $seat = $night['seats']->first();
            $hold = app(HoldService::class)->create($night['event']->fresh(), [$seat->id], 'session-a');

            // The one number that matters: what the buyer will actually be charged.
            $this->assertSame(8000, (int) $hold->total_amount);
        });
    }

    #[Test]
    public function the_price_changes_when_the_window_does_and_nothing_runs_to_make_it(): void
    {
        $night = $this->night(10000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->tier($night['event']->id, ['ends_at' => now()->addDays(3)]);
            $this->tier($night['event']->id, [
                'name' => 'Last week', 'starts_at' => now()->addDays(3), 'ends_at' => null,
                'value' => 15, 'sort_order' => 1,
            ]);

            $event = $night['event']->fresh();

            $this->assertSame(8000, app(AvailabilityService::class)->forEvent($event)[0]['amount']);

            // Four days on. No sweeper has run, nothing has been rewritten, and the platform may
            // as well have been switched off in between.
            $this->travel(4)->days();

            $this->assertSame(11500, app(AvailabilityService::class)->forEvent($event)[0]['amount']);
            $this->assertSame('Last week', app(PriceTiers::class)->describe($event)['name']);
        });
    }

    #[Test]
    public function a_tier_that_covers_nothing_leaves_the_zone_price_alone(): void
    {
        $night = $this->night(10000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->tier($night['event']->id, [
                'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4),
            ]);

            $event = $night['event']->fresh();

            $this->assertSame(10000, app(AvailabilityService::class)->forEvent($event)[0]['amount']);
            $this->assertNull(app(PriceTiers::class)->describe($event));
        });
    }

    #[Test]
    public function an_amount_tier_takes_money_off_and_never_below_nothing(): void
    {
        $night = $this->night(500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->tier($night['event']->id, ['kind' => 'amount', 'value' => -900]);

            // Five euros off a three-euro seat is a free seat, not a two-euro debt.
            $this->assertSame(0, app(AvailabilityService::class)->forEvent($night['event']->fresh())[0]['amount']);
        });
    }

    #[Test]
    public function standing_room_is_tiered_too(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 10000, chart: $this->geometryWithStandingArea(100));

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->tier($night['event']->id, ['kind' => 'percent', 'value' => -50]);

            $areas = app(AvailabilityService::class)->capacityForEvent($night['event']->fresh());

            $this->assertNotEmpty($areas, 'the fixture has a standing area');
            // Half of the standing zone price, which the fixture sets to half the seated one.
            $this->assertSame(2500, $areas[0]['amount']);
        });
    }

    #[Test]
    public function a_seat_priced_by_hand_is_not_moved_by_a_tier(): void
    {
        $night = $this->night(10000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $seat = $night['seats']->first();

            \App\Models\EventSeatOverride::create([
                'event_id' => $night['event']->id,
                'seat_id' => $seat->id,
                'amount' => 4200,
            ]);

            $this->tier($night['event']->id, ['value' => -20]);

            $rows = collect(app(AvailabilityService::class)->forEvent($night['event']->fresh()))
                ->keyBy('seat_id');

            // A number typed against one chair is a decision about that chair, not a starting point.
            $this->assertSame(4200, $rows[$seat->id]['amount']);
        });
    }

    #[Test]
    public function two_tiers_covering_one_moment_are_refused_when_they_are_saved(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->expectException(ApiException::class);

            app(PriceTiers::class)->replace($night['event'], [
                ['name' => 'Early', 'starts_at' => null, 'ends_at' => now()->addDays(5)->toIso8601String(), 'kind' => 'percent', 'value' => -10],
                ['name' => 'Also early', 'starts_at' => now()->addDays(3)->toIso8601String(), 'ends_at' => null, 'kind' => 'percent', 'value' => 10],
            ]);
        });
    }

    #[Test]
    public function a_window_that_ends_before_it_begins_is_refused(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->expectException(ApiException::class);

            app(PriceTiers::class)->replace($night['event'], [
                ['name' => 'Backwards', 'starts_at' => now()->addDays(5)->toIso8601String(),
                    'ends_at' => now()->addDay()->toIso8601String(), 'kind' => 'percent', 'value' => -10],
            ]);
        });
    }

    #[Test]
    public function windows_that_touch_do_not_overlap(): void
    {
        $night = $this->night(10000);
        $edge = now()->addDays(5);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $edge) {
            $saved = app(PriceTiers::class)->replace($night['event'], [
                ['name' => 'Early', 'starts_at' => null, 'ends_at' => $edge->toIso8601String(), 'kind' => 'percent', 'value' => -10],
                ['name' => 'Standard', 'starts_at' => $edge->toIso8601String(), 'ends_at' => null, 'kind' => 'percent', 'value' => 0],
            ]);

            $this->assertCount(2, $saved);

            // The moment itself belongs to the tier that begins on it, not to the one that ended.
            $this->travelTo($edge);

            $this->assertSame('Standard', app(PriceTiers::class)->describe($night['event']->fresh())['name']);
        });
    }

    #[Test]
    public function the_management_api_lists_and_replaces_them(): void
    {
        $night = $this->night(10000);
        $user = $this->makeUser($night['tenant']);
        $token = $user->createToken('test')->plainTextToken;

        $write = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson("/v1/events/{$night['event']->id}/price-tiers", [
                'tiers' => [
                    ['name' => 'Early bird', 'starts_at' => null, 'ends_at' => now()->addDays(7)->toIso8601String(),
                        'kind' => 'percent', 'value' => -20],
                ],
            ]);

        $write->assertOk()
            ->assertJsonPath('data.0.name', 'Early bird')
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('active.name', 'Early bird');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/v1/events/{$night['event']->id}/price-tiers")
            ->assertOk()
            ->assertJsonPath('data.0.value', -20);

        // And the buyer's own view has moved with it, which is the only reason any of this exists.
        $this->getJson("/v1/embed/events/{$night['event']->public_id}/availability")
            ->assertOk()
            ->assertJsonPath('price_tier.name', 'Early bird')
            ->assertJsonPath('seats.0.amount', 8000);
    }

    #[Test]
    public function an_empty_list_puts_the_prices_back(): void
    {
        $night = $this->night(10000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->tier($night['event']->id);

            app(PriceTiers::class)->replace($night['event'], []);

            $this->assertSame(10000, app(AvailabilityService::class)->forEvent($night['event']->fresh())[0]['amount']);
        });
    }
}
