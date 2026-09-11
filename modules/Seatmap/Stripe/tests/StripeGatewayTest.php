<?php

namespace Modules\Seatmap\Stripe\Tests;

use App\Models\ExternalOrder;
use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\Stripe\Provider;
use Modules\Seatmap\Stripe\StripeGateway;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Stripe Checkout, against a faked Stripe. */
class StripeGatewayTest extends TestCase
{
    #[Test]
    public function it_opens_a_checkout_session_for_the_amount_the_hold_fixed(): void
    {
        Http::fake(['*checkout/sessions' => Http::response([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ])]);

        $intent = $this->gateway()->begin($this->order(), $this->context('EUR', 4500));

        $this->assertSame('cs_test_123', $intent->reference);
        $this->assertStringStartsWith('https://checkout.stripe.com/', $intent->redirectUrl);

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Minor units, straight through: no division anywhere, which is what makes a
            // zero-decimal currency work by the same rule as a two-decimal one.
            return '4500' === (string) $body['line_items[0][price_data][unit_amount]']
                && 'eur' === $body['line_items[0][price_data][currency]']
                && 'ORD-1' === $body['client_reference_id']
                && $request->hasHeader('Idempotency-Key');
        });
    }

    #[Test]
    public function a_paid_session_is_paid_however_many_times_it_is_asked(): void
    {
        Http::fake(['*checkout/sessions/cs_1' => Http::response([
            'payment_status' => 'paid',
            'status' => 'complete',
            'payment_intent' => 'pi_9',
        ])]);

        $gateway = $this->gateway();
        $order = $this->order(['payment_reference' => 'cs_1']);

        $first = $gateway->settle($order, []);
        $second = $gateway->settle($order, []);

        $this->assertTrue($first->isPaid());
        $this->assertSame($first->reference, $second->reference);
        $this->assertSame('stripe:pi_9', $first->reference);
    }

    #[Test]
    public function an_open_session_is_still_pending_rather_than_failed(): void
    {
        // The buyer is still on Stripe's page. Failing here would cancel an order somebody is in
        // the middle of paying for.
        Http::fake(['*' => Http::response(['payment_status' => 'unpaid', 'status' => 'open'])]);

        $intent = $this->gateway()->settle($this->order(['payment_reference' => 'cs_1']), []);

        $this->assertSame('pending', $intent->status);
    }

    #[Test]
    public function an_expired_session_is_a_refusal(): void
    {
        Http::fake(['*' => Http::response(['payment_status' => 'unpaid', 'status' => 'expired'])]);

        $this->assertTrue(
            $this->gateway()->settle($this->order(['payment_reference' => 'cs_1']), [])->hasFailed()
        );
    }

    #[Test]
    public function a_refusal_from_stripe_is_reported_rather_than_swallowed(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'amount_too_small']], 400)]);

        $intent = $this->gateway()->begin($this->order(), $this->context('EUR', 1));

        $this->assertTrue($intent->hasFailed());
        $this->assertStringContainsString('amount_too_small', $intent->message);
    }

    #[Test]
    public function the_secret_key_travels_as_a_bearer_token_and_nowhere_else(): void
    {
        Http::fake(['*' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/x'])]);

        $this->gateway()->begin($this->order(), $this->context('EUR', 4500));

        Http::assertSent(function ($request) {
            return 'Bearer sk_test_secret' === $request->header('Authorization')[0]
                && ! str_contains($request->url(), 'sk_test');
        });
    }

    #[Test]
    public function a_refund_goes_against_the_payment_under_the_session(): void
    {
        Http::fake([
            '*checkout/sessions/cs_1' => Http::response(['payment_intent' => 'pi_9']),
            '*refunds' => Http::response(['id' => 're_1']),
        ]);

        $outcome = $this->gateway()->refund($this->order(), 1500, 'stripe:cs_1');

        $this->assertTrue($outcome->wasSent());
        $this->assertSame('stripe:re_1', $outcome->reference);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/refunds')) {
                return false;
            }

            // The payment, not the session — Stripe will not refund a Checkout Session — and the
            // amount in minor units, because that is what the platform holds.
            return 'pi_9' === $request->data()['payment_intent']
                && '1500' === (string) $request->data()['amount']
                && 'refund-ORD-1-1500' === $request->header('Idempotency-Key')[0];
        });
    }

    #[Test]
    public function a_payment_settled_by_a_webhook_is_refunded_without_a_second_lookup(): void
    {
        Http::fake(['*refunds' => Http::response(['id' => 're_2'])]);

        // The reference is already a payment intent, so there is no session to read.
        $this->assertTrue($this->gateway()->refund($this->order(), 4500, 'stripe:pi_7')->wasSent());

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_payment_stripe_has_already_refunded_is_not_refunded_again(): void
    {
        Http::fake([
            '*refunds' => Http::response(['error' => ['code' => 'charge_already_refunded']], 400),
        ]);

        // Reported as sent rather than as a failure: the money is already where the refund was
        // trying to put it, and refusing would leave a booking nobody can ever close.
        $this->assertTrue($this->gateway()->refund($this->order(), 4500, 'stripe:pi_7')->wasSent());
    }

    #[Test]
    public function a_refund_stripe_refuses_is_reported_as_a_refusal(): void
    {
        Http::fake(['*refunds' => Http::response(['error' => ['code' => 'charge_disputed']], 400)]);

        $outcome = $this->gateway()->refund($this->order(), 4500, 'stripe:pi_7');

        $this->assertTrue($outcome->hasFailed());
        $this->assertStringContainsString('charge_disputed', (string) $outcome->message);
    }

    #[Test]
    public function the_provider_contributes_one_gateway(): void
    {
        $this->assertCount(1, (new Provider($this->moduleContext()))->payments());
    }

    /* --------------------------------------------------------------------------- helpers */

    private function gateway(array $settings = []): StripeGateway
    {
        return new StripeGateway($this->moduleContext($settings + ['secret_key' => 'sk_test_secret']));
    }

    private function order(array $metadata = []): ExternalOrder
    {
        return new ExternalOrder([
            'external_order_id' => 'ORD-1',
            'total_amount' => 4500,
            'currency' => 'EUR',
            'metadata' => $metadata,
        ]);
    }

    private function context(string $currency, int $amount): array
    {
        return [
            'amount' => $amount,
            'currency' => $currency,
            'return_url' => 'https://northgate.test/order/ORD-1',
            'callback_url' => 'https://northgate.test/pay/stripe/return/ORD-1',
            'buyer' => ['name' => 'Dana', 'email' => 'dana@example.test'],
        ];
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/stripe',
            'provider' => Provider::class,
            'extends' => ['payments'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
