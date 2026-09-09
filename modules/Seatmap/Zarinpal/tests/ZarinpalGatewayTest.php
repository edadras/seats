<?php

namespace Modules\Seatmap\Zarinpal\Tests;

use App\Models\ExternalOrder;
use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\Http;
use Modules\Seatmap\Zarinpal\Provider;
use Modules\Seatmap\Zarinpal\ZarinpalGateway;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zarinpal, against a faked Zarinpal.
 *
 * What is worth testing without a merchant account is exactly what goes wrong with one: the unit
 * the amount is sent in, and which answer codes mean the money moved.
 */
class ZarinpalGatewayTest extends TestCase
{
    #[Test]
    public function it_asks_for_an_authority_and_sends_the_buyer_to_it(): void
    {
        Http::fake([
            '*payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A0000012345']]),
        ]);

        $intent = $this->gateway()->begin($this->order(), $this->context('IRR', 500000));

        $this->assertSame('pending', $intent->status);
        $this->assertSame('A0000012345', $intent->reference);
        $this->assertStringContainsString('StartPay/A0000012345', $intent->redirectUrl);

        Http::assertSent(function ($request) {
            // Priced in rials here, so the number goes out untouched.
            return 500000 === $request['amount']
                && str_contains($request['callback_url'], '/pay/zarinpal/return/ORD-1');
        });
    }

    #[Test]
    public function a_venue_that_prices_in_tomans_is_multiplied_not_divided(): void
    {
        Http::fake(['*' => Http::response(['data' => ['code' => 100, 'authority' => 'A1']])]);

        // 50,000 tomans is 500,000 rials. The other direction charges a tenth of the ticket, and
        // nobody notices until the settlement report.
        $this->gateway(['amount_unit' => 'toman'])->begin($this->order(), $this->context('IRR', 50000));

        Http::assertSent(fn ($request) => 500000 === $request['amount']);
    }

    #[Test]
    public function it_refuses_a_currency_it_cannot_take_before_calling_anyone(): void
    {
        Http::fake();

        $intent = $this->gateway()->begin($this->order(), $this->context('EUR', 2500));

        $this->assertTrue($intent->hasFailed());
        Http::assertNothingSent();
    }

    #[Test]
    public function already_verified_is_paid_not_failed(): void
    {
        Http::fake(['*payment/verify.json' => Http::response(['data' => ['code' => 101, 'ref_id' => 987]])]);

        // 101 is what a reloaded return page produces. Reading it as a failure would cancel an
        // order that was paid for twenty minutes ago.
        $intent = $this->gateway()->settle($this->order(['payment_reference' => 'A1']), []);

        $this->assertTrue($intent->isPaid());
        $this->assertSame('zarinpal:987', $intent->reference);
    }

    #[Test]
    public function a_buyer_who_cancelled_at_the_gateway_is_not_left_pending(): void
    {
        Http::fake();

        $intent = $this->gateway()->settle($this->order(['payment_reference' => 'A1']), ['Status' => 'NOK']);

        $this->assertTrue($intent->hasFailed());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_verify_that_is_not_a_hundred_or_a_hundred_and_one_is_a_refusal(): void
    {
        Http::fake(['*' => Http::response(['data' => ['code' => -51]])]);

        $this->assertTrue($this->gateway()->settle($this->order(['payment_reference' => 'A1']), [])->hasFailed());
    }

    #[Test]
    public function without_a_reference_it_refuses_rather_than_guessing(): void
    {
        Http::fake();

        $this->assertTrue($this->gateway()->settle($this->order(), [])->hasFailed());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_provider_contributes_one_gateway(): void
    {
        $this->assertCount(1, (new Provider($this->moduleContext()))->payments());
    }

    /* --------------------------------------------------------------------------- helpers */

    private function gateway(array $settings = []): ZarinpalGateway
    {
        return new ZarinpalGateway($this->moduleContext($settings + [
            'merchant_id' => '00000000-0000-0000-0000-000000000000',
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
            'callback_url' => 'https://northgate.test/pay/zarinpal/return/ORD-1',
            'buyer' => ['name' => 'Dana', 'email' => 'dana@example.test'],
        ];
    }

    private function moduleContext(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/zarinpal',
            'provider' => Provider::class,
            'extends' => ['payments'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
