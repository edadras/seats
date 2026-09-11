<?php

namespace Tests\Feature;

use App\Domain\Refunds\RefundRequests;
use App\Domain\Sites\Payments\GatewayRegistry;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\OrderRefund;
use App\Models\RefundRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\Support\RecordingGateway;
use Tests\TestCase;

/**
 * A refund that reaches the card.
 *
 * Refunding used to be bookkeeping: the seats went back on sale, the tickets were voided, every
 * report agreed, and nobody's card was ever credited. Somebody had to open the gateway's own
 * dashboard afterwards and do it from memory.
 *
 * The rule these checks exist to hold is the ordering. Money first, seats second — because a
 * booking cancelled while the money stayed put is the worst of the outcomes available, and it is
 * the one nobody notices until a complaint arrives weeks later.
 */
class RefundToCardTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_money_goes_back_through_the_gateway_that_took_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $gateway = $this->gateway($fixture);

        $order = $this->cardSale($fixture, [0, 1]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])->assertOk();

        $this->assertCount(1, $gateway->asked);
        $this->assertSame(5000, $gateway->asked[0]['amount'], 'Two seats at 2500.');
        $this->assertSame('ref_live', $gateway->asked[0]['reference'], 'The handle from the sale, not the order id.');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($order) {
            $refund = OrderRefund::firstOrFail();

            $this->assertSame('sent', $refund->status);
            $this->assertSame('recording', $refund->gateway);
            $this->assertSame('rec_refund_1', $refund->reference);

            $this->assertSame(0, Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count());
        });
    }

    #[Test]
    public function a_gateway_that_refuses_keeps_the_seats_sold(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->gateway($fixture, 'failed');

        $order = $this->cardSale($fixture, [0, 1]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refund_refused');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($order) {
            // Nothing moved. The buyer still holds what they paid for, which is the only honest
            // state when the money did not come back.
            $this->assertSame('confirmed', ExternalOrder::whereKey($order->id)->firstOrFail()->status);
            $this->assertSame(2, Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count());

            // But the attempt is written down, because "it has failed three times and here is what
            // the gateway said" is the only thing somebody can act on.
            $refund = OrderRefund::firstOrFail();
            $this->assertSame('failed', $refund->status);
            $this->assertSame('The card has expired.', $refund->message);
        });
    }

    #[Test]
    public function a_gateway_that_does_not_answer_at_all_is_a_refusal(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->gateway($fixture, 'throw');

        $order = $this->cardSale($fixture, [0]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refund_refused');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($order) {
            $this->assertSame(1, Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count(), 'A timeout is not permission to release a seat.');
        });
    }

    #[Test]
    public function seat_by_seat_refunds_add_up_to_exactly_what_was_charged(): void
    {
        // Three seats at 2500 is 7500, which does not divide by three into round hundreds once a
        // fee is on it — the case where a naive split loses or invents a unit.
        $fixture = $this->makeSellableEvent(amount: 2501);
        $owner = $this->makeUser($fixture['tenant']);
        $gateway = $this->gateway($fixture);

        $order = $this->cardSale($fixture, [0, 1, 2]);
        $charged = (int) $order->total_amount;

        $seats = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Allocation::where('external_order_row_id', $order->id)->pluck('seat_id')->all()
        );

        foreach ($seats as $seat) {
            $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [
                'seat_ids' => [$seat],
            ])->assertOk();
        }

        $this->assertCount(3, $gateway->asked);
        $this->assertSame($charged, $gateway->total(), 'Not a unit more, and not a unit less.');
    }

    #[Test]
    public function the_same_booking_cannot_be_refunded_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $gateway = $this->gateway($fixture);

        $order = $this->cardSale($fixture, [0, 1]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])->assertOk();

        // A second clerk, on a screen that had not refreshed.
        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_refundable');

        $this->assertSame(5000, $gateway->total(), 'The gateway was asked once.');
    }

    #[Test]
    public function money_taken_at_the_window_is_recorded_as_owed_by_hand(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $gateway = $this->gateway($fixture);

        // No gateway and no reference: cash, a transfer, a school's invoice.
        $order = $this->cardSale($fixture, [0], gateway: null);

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])->assertOk();

        $this->assertSame([], $gateway->asked, 'Nothing to ask.');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($order) {
            $this->assertSame('manual', OrderRefund::firstOrFail()->status);

            // And the seats still go back on sale, because that is what a box office does anyway.
            $this->assertSame(0, Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count());
        });
    }

    #[Test]
    public function credit_instead_of_money_never_touches_the_gateway(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $gateway = $this->gateway($fixture);

        $order = $this->cardSale($fixture, [0, 1]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [
            'as_credit' => true,
        ])->assertOk();

        $this->assertSame([], $gateway->asked, 'That money is becoming a voucher, not going back.');

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $this->assertSame(0, OrderRefund::count())
        );
    }

    #[Test]
    public function the_order_screen_shows_what_was_sent_and_when(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->gateway($fixture);

        $order = $this->cardSale($fixture, [0, 1]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])->assertOk();

        $body = $this->actingAs($owner)->getJson('/v1/orders/'.$order->id)->assertOk()->json();

        $this->assertCount(1, $body['refunds']);
        $this->assertSame('sent', $body['refunds'][0]['status']);
        $this->assertSame(5000, $body['refunds'][0]['amount']);
        $this->assertSame('rec_refund_1', $body['refunds'][0]['reference']);
    }

    #[Test]
    public function a_request_the_box_office_grants_sends_the_money_too(): void
    {
        $fixture = $this->makeSellableEvent();
        $gateway = $this->gateway($fixture);

        $order = $this->cardSale($fixture, [0, 1]);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($order) {
            $request = RefundRequest::create([
                'tenant_id' => $order->tenant_id,
                'event_id' => $order->event_id,
                'external_order_row_id' => $order->id,
                'status' => 'pending',
                'reason' => 'I cannot come.',
            ]);

            app(RefundRequests::class)->grant($request);
        });

        $this->assertSame(5000, $gateway->total(), 'A buyer who asked gets their money, not a status change.');
    }

    #[Test]
    public function a_request_a_gateway_refuses_stays_pending_for_somebody_to_act_on(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->gateway($fixture, 'failed');

        $order = $this->cardSale($fixture, [0, 1]);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($order) {
            $request = RefundRequest::create([
                'tenant_id' => $order->tenant_id,
                'event_id' => $order->event_id,
                'external_order_row_id' => $order->id,
                'status' => 'pending',
                'reason' => 'I cannot come.',
            ]);

            try {
                app(RefundRequests::class)->grant($request);
                $this->fail('A gateway that refused should have stopped the grant.');
            } catch (\App\Exceptions\ApiException $e) {
                $this->assertSame('refund_refused', $e->errorCode());
            }

            // Pending, not approved. A buyer told "approved" whose money never moved is worse off
            // than one still waiting, because nobody is looking at an approved request any more.
            $this->assertSame('pending', $request->fresh()->status);
            $this->assertSame('confirmed', ExternalOrder::whereKey($order->id)->firstOrFail()->status);
        });
    }

    /* --------------------------------------------------------------------------- helpers */

    /**
     * Turn the recording gateway on for this organiser.
     *
     * Registered inside the tenant's own context on purpose: the registry rebuilds its set when the
     * tenant changes, so a gateway registered outside one belongs to nobody and is gone by the time
     * a request arrives.
     */
    private function gateway(array $fixture, string $answer = 'sent'): RecordingGateway
    {
        $gateway = new RecordingGateway($answer);

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(GatewayRegistry::class)->register($gateway)
        );

        return $gateway;
    }

    /**
     * A confirmed booking that was paid for through the recording gateway.
     *
     * Registered and confirmed over the integration API — the same path a shop uses — and then the
     * payment handle is written on, which is what a hosted checkout does at the moment it sends
     * the buyer away.
     */
    private function cardSale(array $fixture, array $seats, ?string $gateway = 'recording'): ExternalOrder
    {
        $event = $fixture['event'];
        $reference = 'wc_'.Str::lower(Str::random(10));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => array_map(fn (int $index) => $fixture['seats'][$index]->id, $seats),
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $api = $this->makeApiClient($fixture['tenant']);

        $this->signedPost($api, '/v1/integrations/woocommerce/orders', [
            'external_order_id' => $reference,
            'hold_token' => $hold['hold_token'],
        ])->assertCreated();

        $this->signedPost($api, '/v1/integrations/woocommerce/orders/'.$reference.'/confirm', [
            'buyer' => ['name' => 'Dana Scully', 'email' => 'dana@example.test'],
        ])->assertOk();

        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($reference, $gateway) {
            $order = ExternalOrder::where('external_order_id', $reference)->firstOrFail();

            if (null !== $gateway) {
                $order->forceFill([
                    'metadata' => ($order->metadata ?? []) + [
                        'gateway' => $gateway,
                        'payment_reference' => 'ref_live',
                    ],
                ])->save();
            }

            return $order->fresh();
        });
    }

    private function signedPost(array $api, string $path, array $payload)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $body)),
            $body,
        );
    }
}
