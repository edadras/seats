<?php

namespace Modules\Seatmap\NextPay\Tests;

use App\Models\ExternalOrder;
use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\NextPay\NextPayGateway;
use Modules\Seatmap\NextPay\Provider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NextPay, against a faked NextPay.
 *
 * NextPay's success codes are -1 for the token call and 0 for verify. They are exactly the values
 * a careless reading treats as failure, so these are the tests that matter.
 */
class NextPayGatewayTest extends TestCase
{
    #[Test]
    public function minus_one_from_the_token_call_is_success(): void
    {
        Http::fake(['*gateway/token' => Http::response(['code' => -1, 'trans_id' => 'tr-88'])]);

        $intent = $this->gateway()->begin($this->order(), $this->context('IRR', 500000));

        $this->assertSame('pending', $intent->status);
        $this->assertSame('tr-88', $intent->reference);
        $this->assertStringContainsString('gateway/payment/tr-88', $intent->redirectUrl);
    }

    #[Test]
    public function anything_else_from_the_token_call_is_a_refusal(): void
    {
        Http::fake(['*' => Http::response(['code' => -33])]);

        $this->assertTrue($this->gateway()->begin($this->order(), $this->context('IRR', 500000))->hasFailed());
    }

    #[Test]
    public function zero_and_minus_two_from_verify_are_both_paid(): void
    {
        foreach ([0, -2] as $code) {
            Http::fake(['*verify' => Http::response(['code' => $code, 'Shaparak_Ref_Id' => '99'])]);

            $this->assertTrue(
                $this->gateway()->settle($this->order(['payment_reference' => 'tr-88']), [])->isPaid(),
                'code '.$code.' is paid'
            );
        }
    }

    #[Test]
    public function a_verify_with_any_other_code_is_a_refusal(): void
    {
        Http::fake(['*verify' => Http::response(['code' => -4])]);

        $this->assertTrue(
            $this->gateway()->settle($this->order(['payment_reference' => 'tr-88']), [])->hasFailed()
        );
    }

    #[Test]
    public function the_amount_is_verified_against_the_order_not_the_request(): void
    {
        Http::fake(['*verify' => Http::response(['code' => 0])]);

        // A tampered return that claims a smaller amount changes nothing: the number sent to
        // NextPay comes from the order this platform wrote.
        $this->gateway()->settle($this->order(['payment_reference' => 'tr-88']), ['amount' => 1]);

        Http::assertSent(fn ($request) => 500000 === $request['amount']);
    }

    #[Test]
    public function the_provider_contributes_one_gateway(): void
    {
        $this->assertCount(1, (new Provider($this->moduleContext()))->payments());
    }

    private function gateway(array $settings = []): NextPayGateway
    {
        return new NextPayGateway($this->moduleContext($settings + [
            'api_key' => 'key-1',
            'amount_unit' => 'rial',
        ]));
    }

    private function order(array $metadata = []): ExternalOrder
    {
        return new ExternalOrder([
            'external_order_id' => 'ORD-1',
            'total_amount' => 500000,
            'currency' => 'IRR',
            'metadata' => $metadata,
        ]);
    }

    private function context(string $currency, int $amount): array
    {
        return [
            'amount' => $amount,
            'currency' => $currency,
            'return_url' => 'https://northgate.test/order/ORD-1',
            'callback_url' => 'https://northgate.test/pay/nextpay/return/ORD-1',
            'buyer' => ['name' => 'Dana', 'email' => 'dana@example.test', 'phone' => '0912'],
        ];
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/nextpay',
            'provider' => Provider::class,
            'extends' => ['payments'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
