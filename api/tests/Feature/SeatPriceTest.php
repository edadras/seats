<?php

namespace Tests\Feature;

use App\Models\EventSeatOverride;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A price zone is the ordinary case. A seat is the real one.
 *
 * A section called VIP is a name, not a price: the two behind the pillar are not worth what the
 * front row is. So a seat may carry its own amount, and these cover the part that matters — that
 * the amount a buyer is actually charged is the seat's, not the zone's.
 */
class SeatPriceTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_screen_is_given_the_hall_the_way_it_is_spoken_about(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $body = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/seat-prices")
            ->assertOk()
            ->json();

        $this->assertSame('EUR', $body['currency']);
        $this->assertNotEmpty($body['zones']);

        $section = $body['sections'][0];
        $this->assertArrayHasKey('rows', $section);

        $seat = $section['rows'][0]['seats'][0];
        $this->assertSame(2500, $seat['amount'], 'A seat with no price of its own costs its zone.');
        $this->assertNull($seat['own_amount']);
        $this->assertFalse($seat['blocked']);
        $this->assertFalse($seat['sold']);
    }

    #[Test]
    public function a_seat_can_cost_more_than_the_section_it_sits_in(): void
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $owner = $this->makeUser($fixture['tenant']);
        $seat = $fixture['seats']->first();

        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/seat-prices", [
            'seats' => [
                ['seat_id' => $seat->id, 'amount' => 9000, 'zone_key' => null],
            ],
        ])->assertOk()->assertJsonPath('changed', 1);

        // The buyer's side is the only opinion that counts: a hold on that seat is priced at the
        // seat's amount, and its neighbour still costs what the zone says.
        $neighbour = $fixture['seats'][1];

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seat->id, $neighbour->id],
            'session_id' => 'sess_seat_price',
        ])->assertCreated();

        $this->assertSame(11500, $hold->json('total_amount'), '9000 for the seat, 2500 for its neighbour.');
    }

    #[Test]
    public function a_seat_can_be_moved_to_another_zone_rather_than_given_a_number(): void
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $owner = $this->makeUser($fixture['tenant']);
        $seat = $fixture['seats']->first();

        // "Standing" is priced at half in the fixture. Naming a zone means the seat follows that
        // zone when it is repriced, which is different from copying today's number onto the seat.
        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/seat-prices", [
            'seats' => [['seat_id' => $seat->id, 'amount' => null, 'zone_key' => 'standing']],
        ])->assertOk();

        $body = $this->actingAs($owner)->getJson("/v1/events/{$event->id}/seat-prices")->json();
        $found = $this->findSeat($body, $seat->id);

        $this->assertSame(1250, $found['amount']);
        $this->assertNull($found['own_amount'], 'It follows the zone rather than carrying a number.');
        $this->assertSame('standing', $found['zone_key']);
    }

    #[Test]
    public function a_seat_with_nothing_left_to_say_loses_its_row(): void
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $owner = $this->makeUser($fixture['tenant']);
        $seat = $fixture['seats']->first();

        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/seat-prices", [
            'seats' => [['seat_id' => $seat->id, 'amount' => 9000, 'zone_key' => null]],
        ])->assertOk();

        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/seat-prices", [
            'seats' => [['seat_id' => $seat->id, 'amount' => null, 'zone_key' => null]],
        ])->assertOk()->assertJsonPath('cleared', 1);

        $this->assertDatabaseMissing('event_seat_overrides', ['event_id' => $event->id, 'seat_id' => $seat->id]);
    }

    #[Test]
    public function changing_one_seat_leaves_every_other_seat_alone(): void
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $owner = $this->makeUser($fixture['tenant']);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => EventSeatOverride::create([
            'event_id' => $event->id,
            'seat_id' => $fixture['seats'][1]->id,
            'blocked' => true,
            'note' => 'Broken seat',
        ]));

        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/seat-prices", [
            'seats' => [['seat_id' => $fixture['seats'][0]->id, 'amount' => 9000, 'zone_key' => null]],
        ])->assertOk();

        $this->assertDatabaseHas('event_seat_overrides', [
            'event_id' => $event->id,
            'seat_id' => $fixture['seats'][1]->id,
            'blocked' => true,
        ]);
    }

    #[Test]
    public function a_seat_from_another_hall_is_refused_before_anything_is_written(): void
    {
        $fixture = $this->makeSellableEvent();
        $other = $this->makeSellableEvent($this->makeTenant('Rival Theatre'));
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->putJson("/v1/events/{$fixture['event']->id}/seat-prices", [
            'seats' => [
                ['seat_id' => $fixture['seats'][0]->id, 'amount' => 4000, 'zone_key' => null],
                ['seat_id' => $other['seats'][0]->id, 'amount' => 4000, 'zone_key' => null],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_seats');

        $this->assertDatabaseMissing('event_seat_overrides', [
            'event_id' => $fixture['event']->id,
            'seat_id' => $fixture['seats'][0]->id,
        ]);
    }

    #[Test]
    public function a_zone_the_event_does_not_have_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->putJson("/v1/events/{$fixture['event']->id}/seat-prices", [
            'seats' => [['seat_id' => $fixture['seats'][0]->id, 'amount' => null, 'zone_key' => 'gold']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_zone');
    }

    #[Test]
    public function the_box_office_may_look_but_not_reprice(): void
    {
        $fixture = $this->makeSellableEvent();
        $staff = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($staff)->getJson("/v1/events/{$fixture['event']->id}/seat-prices")->assertOk();

        $this->actingAs($staff)->putJson("/v1/events/{$fixture['event']->id}/seat-prices", [
            'seats' => [['seat_id' => $fixture['seats'][0]->id, 'amount' => 1, 'zone_key' => null]],
        ])->assertForbidden();
    }

    private function findSeat(array $body, string $seatId): array
    {
        foreach ($body['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['seats'] as $seat) {
                    if ($seat['id'] === $seatId) {
                        return $seat;
                    }
                }
            }
        }

        $this->fail('The seat was not in the chart the screen was given.');
    }
}
