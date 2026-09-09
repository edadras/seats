<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\Hold;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The complete path a real sale takes: widget reads the map, holds seats, the shop registers and
 * confirms an order, a ticket is issued and scanned at the door.
 */
class PurchaseFlowTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_buyer_can_hold_seats_and_the_shop_can_confirm_the_sale(): void
    {
        ['tenant' => $tenant, 'event' => $event, 'seats' => $seats] = $this->makeSellableEvent();
        $api = $this->makeApiClient($tenant);

        // --- Widget: read the event and its map -------------------------------------------
        $this->getJson("/v1/embed/events/{$event->public_id}")
            ->assertOk()
            ->assertJsonPath('public_id', $event->public_id)
            ->assertJsonPath('zones.0.amount', 2500);

        $map = $this->getJson("/v1/embed/events/{$event->public_id}/seat-map")
            ->assertOk()
            ->assertJsonPath('seat_map_version_id', $event->seat_map_version_id);

        // Geometry carries each seat's stable id. Without it a client would have to pair geometry
        // with availability by array position, and nothing guarantees the two share an order.
        $geometrySeatIds = collect($map->json('geometry.floors.0.objects'))
            ->firstWhere('type', 'section')['objects'];

        $geometrySeatIds = collect($geometrySeatIds)
            ->flatMap(fn ($row) => array_column($row['seats'], 'seat_id'));

        $this->assertCount(15, $geometrySeatIds);
        $this->assertEqualsCanonicalizing($seats->pluck('id')->all(), $geometrySeatIds->all());

        $availability = $this->getJson("/v1/embed/events/{$event->public_id}/availability")->assertOk();
        $this->assertCount(15, $availability->json('seats'));
        $this->assertSame('available', $availability->json('seats.0.state'));

        // --- Widget: hold two seats -------------------------------------------------------
        $chosen = [$seats[0]->id, $seats[1]->id];

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => $chosen,
            'session_id' => 'browser-session-1',
        ])->assertCreated();

        $holdToken = $hold->json('hold_token');

        $this->assertSame(5000, $hold->json('total_amount'), 'Two 2500 seats should total 5000.');
        $this->assertNotNull($hold->json('price_snapshot.signature'));

        // The held seats must now read as held, so another browser greys them out.
        $states = collect($this->getJson("/v1/embed/events/{$event->public_id}/availability")->json('seats'))
            ->keyBy('seat_id');
        $this->assertSame('held', $states[$chosen[0]]['state']);
        $this->assertSame('available', $states[$seats[2]->id]['state']);

        // --- Shop: register the order -----------------------------------------------------
        $body = json_encode(['external_order_id' => 'wc_1001', 'hold_token' => $holdToken]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated()->assertJsonPath('status', 'pending');

        // --- Shop: payment completed ------------------------------------------------------
        $path = '/v1/integrations/woocommerce/orders/wc_1001/confirm';
        $confirmBody = json_encode(['buyer' => ['name' => 'Dana Scully', 'email' => 'dana@example.test']]);

        $confirmed = $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $confirmBody)),
            $confirmBody,
        )->assertOk();

        $this->assertSame('confirmed', $confirmed->json('status'));
        $this->assertCount(2, $confirmed->json('allocations'));
        $this->assertCount(2, $confirmed->json('tickets'));

        // Tokens are handed over exactly once, here, so the shop can render the QR.
        $ticketToken = $confirmed->json('tickets.0.token');
        $this->assertNotNull($ticketToken);
        $this->assertStringStartsWith('TKT', $ticketToken);

        $this->asTenant($tenant, function () use ($event, $chosen) {
            $this->assertSame(2, Allocation::where('event_id', $event->id)->where('status', 'active')->count());
            $this->assertSame(2, Ticket::where('event_id', $event->id)->count());

            // The hold has done its job and must stop occupying the seats, or a later refund
            // could never put them back on sale.
            $hold = Hold::where('event_id', $event->id)->first();
            $this->assertSame('converted', $hold->status);
            $this->assertSame(0, $hold->items()->whereNull('released_at')->count());
        });

        $sold = collect($this->getJson("/v1/embed/events/{$event->public_id}/availability")->json('seats'))
            ->keyBy('seat_id');
        $this->assertSame('allocated', $sold[$chosen[0]]['state']);

        // --- Door: scan the ticket --------------------------------------------------------
        $device = $this->makeDevice($tenant, $event);

        $scan = $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => $ticketToken, 'event_id' => $event->id])
            ->assertOk();

        $this->assertSame('valid', $scan->json('result'));

        // A second scan of the same QR must not admit a second person, and must say who got in.
        $again = $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => $ticketToken, 'event_id' => $event->id])
            ->assertOk();

        $this->assertSame('already_used', $again->json('result'));
        $this->assertNotNull($again->json('first_scan.scanned_at'));
        $this->assertSame($device['name'], $again->json('first_scan.device'));
    }

    #[Test]
    public function a_seat_cannot_be_held_twice(): void
    {
        ['event' => $event, 'seats' => $seats] = $this->makeSellableEvent();

        $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seats[0]->id],
            'session_id' => 'session-a',
        ])->assertCreated();

        $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seats[0]->id, $seats[1]->id],
            'session_id' => 'session-b',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'seat_unavailable')
            ->assertJsonPath('error.details.unavailable_seat_ids', [$seats[0]->id]);
    }

    #[Test]
    public function an_expired_hold_returns_the_seat_to_sale(): void
    {
        ['event' => $event, 'seats' => $seats, 'tenant' => $tenant] = $this->makeSellableEvent();

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seats[0]->id],
            'session_id' => 'session-a',
        ])->assertCreated();

        // Age the hold past its TTL without waiting ten minutes.
        $this->asTenant($tenant, fn () => Hold::where('token', $hold->json('hold_token'))
            ->update(['expires_at' => now()->subSecond()]));

        // No sweeper has run. The seat must still be immediately bookable — the reclaim happens
        // inside the next hold's own transaction.
        $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seats[0]->id],
            'session_id' => 'session-b',
        ])->assertCreated();
    }

    /** @return array{token: string, name: string} */
    private function makeDevice(\App\Models\Tenant $tenant, \App\Models\Event $event): array
    {
        return $this->asTenant($tenant, function () use ($tenant, $event) {
            $code = 'pair-'.\Illuminate\Support\Str::random(10);

            $device = \App\Models\CheckinDevice::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Main door',
                'status' => 'pending',
                'pairing_code_hash' => hash('sha256', $code),
                'pairing_expires_at' => now()->addMinutes(30),
            ]);

            $device->grantAccessTo($event);

            $response = $this->postJson('/v1/checkin/auth/token', [
                'pairing_code' => $code,
                'device_name' => 'Main door',
            ])->assertOk();

            return ['token' => $response->json('token'), 'name' => 'Main door'];
        });
    }

    /** Turn header names into the SERVER-array form `call()` expects. */
}
