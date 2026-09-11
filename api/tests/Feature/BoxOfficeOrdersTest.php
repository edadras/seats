<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\Ticket;
use App\Domain\Sites\SiteProvisioner;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The box office's own screen.
 *
 * `orders.refund` existed as a permission with nowhere to use it: refunding was reachable only
 * over the signed integration API, which is right for a shop that owns the money and no use to an
 * organiser selling from their own site. These are the checks that the panel's own path does the
 * same thing that path does — releases the seats, voids the tickets — and refuses the same things.
 *
 * The money half lives in `RefundToCardTest`: these bookings came in over the integration API and
 * carry no payment handle, so nothing here has a gateway to ask.
 */
class BoxOfficeOrdersTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_booking_can_be_found_by_reference_name_or_address(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $reference = $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0, 1]);
        $this->sell($fixture, ['name' => 'Amir Rahimi', 'email' => 'amir@example.test'], [5]);

        $all = $this->actingAs($owner)->getJson('/v1/orders')->assertOk()->json();

        $this->assertCount(2, $all['data']);
        $this->assertSame(
            [1, 2],
            collect($all['data'])->pluck('seats')->sort()->values()->all(),
            'The seat count comes back with the row, not one query per row.'
        );

        foreach ([$reference, 'dana', 'DANA@example.test'] as $term) {
            $found = $this->actingAs($owner)->getJson('/v1/orders?q='.urlencode($term))->assertOk()->json();

            $this->assertCount(1, $found['data'], 'Searching for '.$term);
            $this->assertSame($reference, $found['data'][0]['reference']);
        }
    }

    #[Test]
    public function the_whole_booking_can_be_refunded_from_the_panel(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $reference = $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0, 1]);
        $order = $this->orderFor($fixture, $reference);

        $body = $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])
            ->assertOk()->json();

        $this->assertSame('refunded', $body['status']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($order) {
            // The seats are back on sale and the tickets do not work. A booking registered by a
            // shop has already had its money handed back on that side, so nothing is sent here.
            $this->assertSame(0, Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count());
            $this->assertSame(2, Ticket::where('status', 'void')->count());
        });
    }

    #[Test]
    public function one_seat_of_several_can_be_given_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $reference = $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0, 1]);
        $order = $this->orderFor($fixture, $reference);

        $body = $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [
            'seat_ids' => [$fixture['seats'][0]->id],
        ])->assertOk()->json();

        $this->assertSame('partially_refunded', $body['status']);
        $this->assertSame(1, $body['seats'], 'One seat is still theirs.');
    }

    #[Test]
    public function a_pending_order_is_cancelled_rather_than_refunded(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $reference = $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0], confirm: false);
        $order = $this->orderFor($fixture, $reference);

        // Nothing was paid, so there is nothing to refund — and saying "refunded" about money that
        // never moved is the one thing a box office must not do.
        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])
            ->assertStatus(409)->assertJsonPath('error.code', 'order_not_refundable');

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/cancel', [])
            ->assertOk()->assertJsonPath('status', 'cancelled');
    }

    #[Test]
    public function the_door_may_not_refund_and_the_box_office_may(): void
    {
        $fixture = $this->makeSellableEvent();
        $reference = $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $order = $this->orderFor($fixture, $reference);

        $this->actingAs($this->makeUser($fixture['tenant'], 'door'))
            ->getJson('/v1/orders')->assertForbidden();

        $viewer = $this->makeUser($fixture['tenant'], 'viewer');
        $this->actingAs($viewer)->postJson('/v1/orders/'.$order->id.'/refund', [])->assertForbidden();

        $this->actingAs($this->makeUser($fixture['tenant'], 'box_office'))
            ->postJson('/v1/orders/'.$order->id.'/refund', [])->assertOk();
    }

    #[Test]
    public function another_organisers_order_is_not_theirs_to_refund(): void
    {
        $ours = $this->makeSellableEvent();
        $theirs = $this->makeSellableEvent($this->makeTenant('Rival Halls'));

        $reference = $this->sell($theirs, ['name' => 'Theirs', 'email' => 'theirs@example.test'], [0]);
        $order = $this->orderFor($theirs, $reference);

        $this->actingAs($this->makeUser($ours['tenant']))
            ->postJson('/v1/orders/'.$order->id.'/refund', [])
            ->assertNotFound();
    }

    #[Test]
    public function sending_the_tickets_again_mints_new_codes_and_is_written_down(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $reference = $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0, 1]);
        $order = $this->orderFor($fixture, $reference);

        $before = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Ticket::orderBy('created_at')->pluck('token_hash')->all()
        );

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/resend', [])
            ->assertOk()
            ->assertJsonPath('sent', 2)
            ->assertJsonPath('to', 'dana@example.test');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($before) {
            $after = Ticket::orderBy('created_at')->pluck('token_hash')->all();

            $this->assertSame([], array_intersect($before, $after), 'The old codes are dead.');
            // Somebody's tickets being reissued is a thing an organiser may need to explain later.
            $this->assertTrue(AuditLog::where('action', 'order.tickets_resent')->exists());
        });

        Mail::assertSent(\App\Mail\TicketsIssued::class);
    }

    /* --------------------------------------------------------------------------- helpers */

    private array $clients = [];

    private function orderFor(array $fixture, string $reference): ExternalOrder
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::where('external_order_id', $reference)->firstOrFail()
        );
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

    /** @param  list<int>  $seats */
    private function sell(array $fixture, array $buyer, array $seats, bool $confirm = true): string
    {
        $event = $fixture['event'];
        $reference = 'wc_'.Str::lower(Str::random(10));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => array_map(fn (int $index) => $fixture['seats'][$index]->id, $seats),
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $api = $this->clients[$fixture['tenant']->id] ??= $this->makeApiClient($fixture['tenant']);
        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $hold['hold_token']]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated();

        if ($confirm) {
            $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
            $payload = json_encode(['buyer' => $buyer]);

            $this->call(
                'POST', $path, [], [], [],
                $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $payload)),
                $payload,
            )->assertOk();
        }

        return $reference;
    }
}
