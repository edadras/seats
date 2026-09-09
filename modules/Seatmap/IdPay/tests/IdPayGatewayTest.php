<?php

namespace Modules\Seatmap\IdPay\Tests;

use App\Models\ExternalOrder;
use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\IdPay\IdPayGateway;
use Modules\Seatmap\IdPay\Provider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** IDPay, against a faked IDPay. */
class IdPayGatewayTest extends TestCase
{
    #[Test]
    public function it_takes_the_link_idpay_gives_it(): void
    {
        Http::fake(['*v1.1/payment' => Http::response(['id' => '9a2b', 'link' => 'https://idpay.ir/p/9a2b'])]);

        $intent = $this->gateway()->begin($this->order(), $this->context('IRR', 500000));

        $this->assertSame('9a2b', $intent->reference);
        $this->assertSame('https://idpay.ir/p/9a2b', $intent->redirectUrl);

        Http::assertSent(fn ($request) => 'key-1' === $request->header('X-API-KEY')[0]
            && 500000 === $request['amount']
            && 'ORD-1' === $request['order_id']);
    }

    #[Test]
    public function the_sandbox_is_a_header_not_a_different_host(): void
    {
        Http::fake(['*' => Http::response(['id' => '1', 'link' => 'https://idpay.ir/p/1'])]);

        $this->gateway(['sandbox' => true])->begin($this->order(), $this->context('IRR', 500000));

        Http::assertSent(fn ($request) => '1' === $request->header('X-SANDBOX')[0]
            && str_contains($request->url(), 'api.idpay.ir'));
    }

    #[Test]
    public function a_toman_price_is_multiplied_before_it_is_sent(): void
    {
        Http::fake(['*' => Http::response(['id' => '1', 'link' => 'https://idpay.ir/p/1'])]);

        $this->gateway(['amount_unit' => 'toman'])->begin($this->order(), $this->context('IRR', 50000));

        Http::assertSent(fn ($request) => 500000 === $request['amount']);
    }

    #[Test]
    public function verified_and_already_verified_are_both_paid(): void
    {
        foreach ([100, 101] as $status) {
            Http::fake(['*verify' => Http::response(['status' => $status, 'track_id' => 'T7'])]);

            $intent = $this->gateway()->settle($this->order(['payment_reference' => '9a2b']), []);

            $this->assertTrue($intent->isPaid(), 'status '.$status.' is paid');
            $this->assertSame('idpay:T7', $intent->reference);
        }
    }

    #[Test]
    public function anything_below_a_hundred_is_a_refusal(): void
    {
        Http::fake(['*verify' => Http::response(['status' => 6])]);

        $this->assertTrue(
            $this->gateway()->settle($this->order(['payment_reference' => '9a2b']), [])->hasFailed()
        );
    }

    #[Test]
    public function a_currency_it_cannot_take_is_refused_without_a_call(): void
    {
        Http::fake();

        $this->assertTrue($this->gateway()->begin($this->order(), $this->context('USD', 4500))->hasFailed());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_provider_contributes_one_gateway(): void
    {
        $this->assertCount(1, (new Provider($this->moduleContext()))->payments());
    }

    private function gateway(array $settings = []): IdPayGateway
    {
        return new IdPayGateway($this->moduleContext($settings + [
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
            'callback_url' => 'https://northgate.test/pay/idpay/return/ORD-1',
            'buyer' => ['name' => 'Dana', 'email' => 'dana@example.test', 'phone' => null],
        ];
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/idpay',
            'provider' => Provider::class,
            'extends' => ['payments'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
