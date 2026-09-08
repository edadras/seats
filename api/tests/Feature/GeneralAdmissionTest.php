<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\CapacityObject;
use App\Models\EventCapacityOverride;
use App\Models\Hold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Standing room, booths and whole tables.
 *
 * These are sold by quantity, so their exclusivity cannot come from a unique index the way a named
 * seat's does — the invariant is a sum against a limit. That difference is what these tests exist
 * to pin down.
 */
class GeneralAdmissionTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    private function standingEvent(int $places = 100): array
    {
        return $this->makeSellableEvent(chart: $this->geometryWithStandingArea($places));
    }

    #[Test]
    public function publishing_creates_a_capacity_object_alongside_the_seats(): void
    {
        $ctx = $this->standingEvent(250);

        $this->asTenant($ctx['tenant'], function () {
            $area = CapacityObject::where('key', 'pit')->firstOrFail();

            $this->assertSame('area', $area->kind);
            $this->assertSame('generalAdmission', $area->capacity_type);
            $this->assertSame(250, $area->places);
            $this->assertSame('Standing pit', $area->label);
        });

        // The version's place count is seats plus standing, because that is the house.
        $this->asTenant($ctx['tenant'], fn () => $this->assertSame(
            265,
            \App\Models\SeatMapVersion::find($ctx['map']->fresh()->published_version_id)->seat_count,
        ));
    }

    #[Test]
    public function the_widget_is_told_how_many_places_remain(): void
    {
        $ctx = $this->standingEvent(50);

        $availability = $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->assertOk();

        $area = $availability->json('areas.0');

        $this->assertSame(50, $area['places']);
        $this->assertSame(0, $area['taken']);
        $this->assertSame(50, $area['remaining']);
        $this->assertSame('generalAdmission', $area['capacity_type']);
        // A price, so the widget can show what a place costs.
        $this->assertSame(1250, $area['amount']);
    }

    #[Test]
    public function a_buyer_can_hold_several_standing_places_at_once(): void
    {
        $ctx = $this->standingEvent(50);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $hold = $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 4 ],
            'session_id' => 'browser-1',
        ])->assertCreated();

        $this->assertSame(5000, $hold->json('total_amount'), '4 places at 1250');
        $this->assertSame( [], $hold->json('seats'), 'standing room has no named seats' );
        $this->assertSame(4, $hold->json('areas.0.quantity'));

        $remaining = $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")
            ->json('areas.0');

        $this->assertSame(4, $remaining['taken']);
        $this->assertSame(46, $remaining['remaining']);
        $this->assertSame(4, $remaining['held']);
        $this->assertSame(0, $remaining['allocated']);
    }

    #[Test]
    public function seats_and_standing_places_can_be_held_together(): void
    {
        $ctx = $this->standingEvent(50);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $hold = $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'seat_ids' => [ $ctx['seats'][0]->id ],
            'areas' => [ $areaId => 2 ],
            'session_id' => 'browser-1',
        ])->assertCreated();

        // One seat at 2500 plus two standing at 1250.
        $this->assertSame(5000, $hold->json('total_amount'));
        $this->assertCount(1, $hold->json('seats'));
        $this->assertCount(1, $hold->json('areas'));
    }

    #[Test]
    public function a_hold_cannot_exceed_the_remaining_capacity(): void
    {
        $ctx = $this->standingEvent(6);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 5 ],
            'session_id' => 'browser-1',
        ])->assertCreated();

        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 3 ],
            'session_id' => 'browser-2',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'capacity_unavailable')
            ->assertJsonPath('error.details.unavailable_capacity_object_ids', [ $areaId ]);

        // The one that fits still goes through.
        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 1 ],
            'session_id' => 'browser-3',
        ])->assertCreated();
    }

    #[Test]
    public function an_expired_standing_hold_returns_its_places(): void
    {
        $ctx = $this->standingEvent(4);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $hold = $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 4 ],
            'session_id' => 'browser-1',
        ])->assertCreated();

        $this->asTenant($ctx['tenant'], fn () => Hold::where('token', $hold->json('hold_token'))
            ->update(['expires_at' => now()->subSecond()]));

        // Expired holds are excluded by the running total itself, so the places come back without
        // waiting for the sweeper.
        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 4 ],
            'session_id' => 'browser-2',
        ])->assertCreated();
    }

    #[Test]
    public function confirming_turns_standing_places_into_an_allocation(): void
    {
        $ctx = $this->standingEvent(20);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;
        $this->storefrontApi = $this->makeApiClient($ctx['tenant']);

        $hold = $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 3 ],
            'session_id' => 'ga-session',
        ])->assertCreated();

        $this->storefront('POST', '/v1/integrations/woocommerce/orders', [
            'external_order_id' => 'wc_ga_1',
            'hold_token' => $hold->json('hold_token'),
        ])->assertCreated();

        $order = $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_ga_1/confirm')->assertOk();

        $this->assertSame('confirmed', $order->json('status'));
        $this->assertCount(1, $order->json('allocations'), 'three places are one allocation, not three');

        // A ticket names the area and how many places it admits, since there is no seat to print.
        $this->assertSame('Standing pit', $order->json('allocations.0.section'));
        $this->assertSame('3 places', $order->json('allocations.0.label'));
        $this->assertCount(1, $order->json('tickets'));

        $this->asTenant($ctx['tenant'], function () {
            $allocation = Allocation::where('status', 'active')->firstOrFail();

            $this->assertNull($allocation->seat_id);
            $this->assertNotNull($allocation->capacity_object_id);
            $this->assertSame(3, $allocation->quantity);
        });

        $area = $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('areas.0');

        $this->assertSame(3, $area['allocated']);
        $this->assertSame(0, $area['held'], 'the hold converted, so it no longer counts twice');
        $this->assertSame(17, $area['remaining']);
    }

    #[Test]
    public function refunding_standing_places_returns_them_to_sale(): void
    {
        $ctx = $this->standingEvent(10);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;
        $this->storefrontApi = $this->makeApiClient($ctx['tenant']);

        $hold = $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 10 ],
            'session_id' => 'ga-session',
        ])->assertCreated();

        $this->storefront('POST', '/v1/integrations/woocommerce/orders', [
            'external_order_id' => 'wc_ga_2',
            'hold_token' => $hold->json('hold_token'),
        ])->assertCreated();

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_ga_2/confirm')->assertOk();

        $this->assertSame(0, $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")
            ->json('areas.0.remaining'));

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_ga_2/refund')->assertOk();

        // Releasing the allocation is enough — unlike a refunded seat, there is nothing to block.
        $this->assertSame(10, $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")
            ->json('areas.0.remaining'));
    }

    #[Test]
    public function an_organiser_can_reduce_the_house_for_one_night(): void
    {
        $ctx = $this->standingEvent(100);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $this->asTenant($ctx['tenant'], fn () => EventCapacityOverride::create([
            'event_id' => $ctx['event']->id,
            'capacity_object_id' => $areaId,
            'places' => 6,
        ]));

        $area = $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('areas.0');

        $this->assertSame(6, $area['places'], 'the map still says 100; this event sells 6');
        $this->assertSame(6, $area['remaining']);

        // Seven is within the per-order limit, so this is the reduced house talking, not that cap.
        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 7 ],
            'session_id' => 'browser-1',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'capacity_unavailable');
    }

    #[Test]
    public function a_blocked_area_sells_nothing(): void
    {
        $ctx = $this->standingEvent(100);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $this->asTenant($ctx['tenant'], fn () => EventCapacityOverride::create([
            'event_id' => $ctx['event']->id,
            'capacity_object_id' => $areaId,
            'blocked' => true,
        ]));

        $area = $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('areas.0');

        $this->assertTrue($area['blocked']);
        $this->assertSame(0, $area['remaining']);

        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 1 ],
            'session_id' => 'browser-1',
        ])->assertStatus(409);
    }

    #[Test]
    public function an_area_from_another_tenants_map_is_rejected(): void
    {
        $mine = $this->standingEvent(50);
        $theirs = $this->makeSellableEvent(
            $this->makeTenant('Other'),
            chart: $this->geometryWithStandingArea(50),
        );

        $foreignAreaId = $theirs['areas']->firstWhere('key', 'pit')->id;

        $this->postJson("/v1/embed/events/{$mine['event']->public_id}/holds", [
            'areas' => [ $foreignAreaId => 1 ],
            'session_id' => 'browser-1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_capacity_objects');
    }

    #[Test]
    public function the_seat_limit_counts_standing_places_too(): void
    {
        $ctx = $this->standingEvent(500);
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        // max_seats_per_order defaults to 10, and a quantity of standing room is no different from
        // ten separate seats as far as that limit is concerned.
        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'areas' => [ $areaId => 11 ],
            'session_id' => 'browser-1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'too_many_seats');
    }

    #[Test]
    public function a_hold_with_neither_seats_nor_places_is_rejected(): void
    {
        $ctx = $this->standingEvent();

        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'session_id' => 'browser-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'no_seats');
    }
}
