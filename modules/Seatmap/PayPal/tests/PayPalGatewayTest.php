<?php

namespace Modules\Seatmap\PayPal\Tests;

use App\Models\ExternalOrder;
use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\PayPal\PayPalGateway;
use Modules\Seatmap\PayPal\Provider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** PayPal Orders v2, against a faked PayPal. */
class PayPalGatewayTest extends TestCase
{
    #[Test]
    public function it_sends_a_decimal_amount_in_the_currencys_own_places(): void
    {
        $this->fakePayPal(['*v2/checkout/orders' => Http::response([
            'id' => '5O190127TN364715T',
            'links' => [['rel' => 'payer-action', 'href' => 'https://www.paypal.com/checkoutnow?token=5O1']],
        ])]);

        $intent = $this->gateway()->begin($this->order(), $this->context('EUR', 4500));

        $this->assertSame('5O190127TN364715T', $intent->reference);
        $this->assertStringContainsString('paypal.com', $intent->redirectUrl);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'v2/checkout/orders')) {
                return true;
            }

            // 4500 minor units of a two-place currency is 45.00 — through the same exponent table
            // everything else uses, so a yen and a dinar come out right too.
            return '45.00' === $request['purchase_units'][0]['amount']['value']
                && 'EUR' === $request['purchase_units'][0]['amount']['currency_code'];
        });
    }

    #[Test]
    public function a_zero_decimal_currency_is_not_divided_by_a_hundred(): void
    {
        $this->fakePayPal(['*v2/checkout/orders' => Http::response([
            'id' => 'X1',
            'links' => [['rel' => 'payer-action', 'href' => 'https://www.paypal.com/x']],
        ])]);

        $this->gateway()->begin($this->order(), $this->context('JPY', 5000));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'v2/checkout/orders')) {
                return true;
            }

            return '5000' === $request['purchase_units'][0]['amount']['value'];
        });
    }

    #[Test]
    public function a_completed_capture_is_paid(): void
    {
        $this->fakePayPal(['*capture' => Http::response(['status' => 'COMPLETED'])]);

        $intent = $this->gateway()->settle($this->order(['payment_reference' => 'X1']), []);

        $this->assertTrue($intent->isPaid());
        $this->assertSame('paypal:X1', $intent->reference);
    }

    #[Test]
    public function capturing_an_already_captured_order_is_still_paid(): void
    {
        // Exactly what a reloaded return page produces.
        $this->fakePayPal(['*capture' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']],
        ], 422)]);

        $this->assertTrue(
            $this->gateway()->settle($this->order(['payment_reference' => 'X1']), [])->isPaid()
        );
    }

    #[Test]
    public function a_capture_that_failed_for_any_other_reason_is_a_refusal(): void
    {
        $this->fakePayPal(['*capture' => Http::response([
            'name' => 'INSTRUMENT_DECLINED',
            'details' => [['issue' => 'INSTRUMENT_DECLINED']],
        ], 422)]);

        $intent = $this->gateway()->settle($this->order(['payment_reference' => 'X1']), []);

        $this->assertTrue($intent->hasFailed());
        $this->assertStringContainsString('INSTRUMENT_DECLINED', $intent->message);
    }

    #[Test]
    public function credentials_that_do_not_work_are_reported_as_configuration_not_as_a_decline(): void
    {
        Http::fake(['*oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $intent = $this->gateway()->begin($this->order(), $this->context('EUR', 4500));

        $this->assertTrue($intent->hasFailed());
        $this->assertStringContainsString(__('payments.paypal.label'), $intent->message);
    }

    #[Test]
    public function the_provider_contributes_one_gateway(): void
    {
        $this->assertCount(1, (new Provider($this->moduleContext()))->payments());
    }

    /* --------------------------------------------------------------------------- helpers */

    private function fakePayPal(array $routes): void
    {
        Http::fake($routes + ['*oauth2/token' => Http::response(['access_token' => 'A21AA'])]);
    }

    private function gateway(array $settings = []): PayPalGateway
    {
        return new PayPalGateway($this->moduleContext($settings + [
            'client_id' => 'client',
            'client_secret' => 'secret',
            'sandbox' => true,
        ]));
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
            'callback_url' => 'https://northgate.test/pay/paypal/return/ORD-1',
            'buyer' => ['name' => 'Dana', 'email' => 'dana@example.test'],
        ];
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/paypal',
            'provider' => Provider::class,
            'extends' => ['payments'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
