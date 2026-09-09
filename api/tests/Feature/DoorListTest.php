<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The list the door works from when the scanner does not.
 *
 * The checks that matter are the ones about who is on it: one row per ticket rather than per
 * booking, refunded tickets gone, and no money anywhere — the door needs names and seats, and a
 * volunteer with `checkins.view` must not learn what the evening took from it.
 */
class DoorListTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function every_ticket_gets_its_own_row(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0, 1, 2]);

        $list = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/door-list")
            ->assertOk()
            ->json();

        // A family of four arrives one at a time; a list that could only tick off whole bookings
        // would have somebody doing arithmetic at the door while a queue formed.
        $this->assertCount(3, $list['data']);
        $this->assertSame(3, $list['meta']['expected']);
        $this->assertSame(0, $list['meta']['arrived']);
        $this->assertSame(['Dana Scully'], array_unique(array_column($list['data'], 'name')));
    }

    #[Test]
    public function it_can_be_searched_by_name_reference_or_seat(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $reference = $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Amir Rahimi', 'email' => 'amir@example.test'], [5]);

        // Read back from the allocation, which is where the door list reads it from: the seat's
        // own relations are not loaded here, and lazy loading is off.
        $seat = app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $allocation = \App\Models\Allocation::where('seat_id', $fixture['seats'][0]->id)
                ->firstOrFail();

            return trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label);
        });

        foreach (['scully', 'DANA@example.test', $reference, $seat] as $term) {
            $found = $this->actingAs($owner)
                ->getJson("/v1/events/{$fixture['event']->id}/door-list?q=".urlencode($term))
                ->assertOk()
                ->json('data');

            $this->assertCount(1, $found, 'Searching for '.$term);
            $this->assertSame('Dana Scully', $found[0]['name']);
        }
    }

    #[Test]
    public function somebody_already_inside_is_marked_and_can_be_filtered_out(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0, 1]);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            Ticket::query()->orderBy('created_at')->first()
                ->forceFill(['status' => 'used', 'used_at' => now()])->save();
        });

        $all = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/door-list")
            ->assertOk()->json();

        $this->assertSame(1, $all['meta']['arrived']);
        $this->assertSame([false, true], collect($all['data'])->pluck('arrived')->sort()->values()->all());

        $waiting = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/door-list?state=out")
            ->assertOk()->json('data');

        $this->assertCount(1, $waiting);
        $this->assertFalse($waiting[0]['arrived']);
    }

    #[Test]
    public function a_refunded_ticket_is_not_on_the_list(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $reference = $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0, 1]);

        $order = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\ExternalOrder::where('external_order_id', $reference)->firstOrFail()
        );

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund')->assertOk();

        $list = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/door-list")
            ->assertOk()->json();

        // Somebody at the door holding a voided ticket is holding a ticket that was cancelled, and
        // a list that still showed them would let them in.
        $this->assertCount(0, $list['data']);
        $this->assertSame(0, $list['meta']['expected']);
    }

    #[Test]
    public function the_file_holds_everybody_and_no_money(): void
    {
        $fixture = $this->makeSellableEvent(amount: 4200);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0, 1]);

        $csv = $this->actingAs($owner)
            ->get("/v1/events/{$fixture['event']->id}/door-list/export")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Dana Scully', $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1, 'A heading and two tickets.');
        // The door needs names and seats. What the evening took is nobody's business at the door.
        $this->assertStringNotContainsString('42.00', $csv);
        $this->assertStringNotContainsString('4200', $csv);
    }

    #[Test]
    public function the_door_can_read_it_and_nothing_else(): void
    {
        $fixture = $this->makeSellableEvent();
        $door = $this->makeUser($fixture['tenant'], 'door');
        $viewer = $this->makeUser($fixture['tenant'], 'viewer');

        $this->actingAs($door)->getJson("/v1/events/{$fixture['event']->id}/door-list")->assertOk();
        // A viewer reads the programme, not the guest list.
        $this->actingAs($viewer)->getJson("/v1/events/{$fixture['event']->id}/door-list")->assertForbidden();
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** @param  list<int>  $seats */
    private function sell(array $fixture, array $buyer, array $seats): string
    {
        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $api = $this->makeApiClient($fixture['tenant']);
        $reference = 'wc_'.Str::lower(Str::random(10));
        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $hold['hold_token']]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated();

        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $payload = json_encode(['buyer' => $buyer]);

        $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $payload)),
            $payload,
        )->assertOk();

        return $reference;
    }
}
