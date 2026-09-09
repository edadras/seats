<?php

namespace Tests\Feature;

use App\Models\EventPriceZone;
use App\Models\EventSeatOverride;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What an event charges, and in what.
 *
 * The currency belongs to the event rather than the account, because a company that tours plays
 * Tehran in rials and Berlin in euros. These cover the two ways that goes wrong in practice: a
 * price list saved from one screen quietly wiping what another screen owns, and a currency that
 * arrives in whatever case somebody typed it.
 */
class EventPricingTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function saving_prices_leaves_seat_overrides_alone(): void
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $owner = $this->makeUser($fixture['tenant']);
        $seat = $fixture['seats']->first();

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => EventSeatOverride::create([
            'event_id' => $event->id,
            'seat_id' => $seat->id,
            'blocked' => true,
            'note' => 'Broken seat',
        ]));

        // The panel's price screen knows nothing about blocked seats and sends no `overrides` key.
        // Correcting a price must not unblock the row somebody taped off this morning.
        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/pricing", [
            'currency' => 'EUR',
            'zones' => [['key' => 'standard', 'name' => 'Standard', 'amount' => 3000]],
        ])->assertOk();

        $this->assertDatabaseHas('event_seat_overrides', [
            'event_id' => $event->id,
            'seat_id' => $seat->id,
            'blocked' => true,
        ]);

        $this->assertSame(
            3000,
            app(TenantContext::class)->runAs(
                $fixture['tenant'],
                fn () => EventPriceZone::where('event_id', $event->id)->where('key', 'standard')->value('amount')
            )
        );
    }

    #[Test]
    public function sending_an_empty_override_list_clears_them(): void
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $owner = $this->makeUser($fixture['tenant']);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => EventSeatOverride::create([
            'event_id' => $event->id,
            'seat_id' => $fixture['seats']->first()->id,
            'blocked' => true,
        ]));

        // Saying "there are none" is different from saying nothing, and the endpoint honours both.
        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/pricing", [
            'currency' => 'EUR',
            'zones' => [['key' => 'standard', 'name' => 'Standard', 'amount' => 3000]],
            'overrides' => [],
        ])->assertOk();

        $this->assertDatabaseMissing('event_seat_overrides', ['event_id' => $event->id]);
    }

    #[Test]
    public function the_currency_is_stored_in_the_case_the_world_writes_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/pricing", [
            'currency' => 'irr',
            'zones' => [['key' => 'standard', 'name' => 'Standard', 'amount' => 500000]],
        ])->assertOk()->assertJsonPath('currency', 'IRR');

        // A rial has no minor unit, so 500000 is five hundred thousand rials and not five thousand.
        $this->assertStringContainsString('۵۰۰٬۰۰۰', \App\Support\Locale\Money::format(500000, 'IRR', 'fa'));
    }

    #[Test]
    public function a_currency_that_is_not_a_currency_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->putJson("/v1/events/{$fixture['event']->id}/pricing", [
            'currency' => '€€€',
            'zones' => [['key' => 'standard', 'name' => 'Standard', 'amount' => 100]],
        ])->assertStatus(422);
    }

    #[Test]
    public function the_event_carries_the_zones_the_map_has_so_nobody_retypes_them(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $response = $this->actingAs($owner)->getJson('/v1/events')->assertOk();

        $event = $response->json('data.0');

        $this->assertNotEmpty($event['price_zones'], 'Prices travel with the event.');
        $this->assertNotEmpty($event['categories'], 'The screen offers the chart’s own categories.');
        $this->assertSame(
            ['color', 'key', 'label'],
            collect($event['categories'][0])->keys()->sort()->values()->all()
        );
    }
}
