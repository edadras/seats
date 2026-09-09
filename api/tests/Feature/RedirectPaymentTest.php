<?php

namespace Tests\Feature;

use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\SiteProvisioner;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\TenantModule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A sale through a gateway that redirects.
 *
 * The offline gateway settles inline, so until now nothing exercised the half of the checkout that
 * matters most with a real gateway: the buyer leaves, money moves somewhere else, and they come
 * back — or they do not come back at all.
 *
 * Zarinpal stands in for all of them here because the shape is the same for every redirect
 * gateway; each module's own behaviour is tested where the module lives.
 */
class RedirectPaymentTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_buyer_is_sent_to_the_gateway_and_the_order_waits(): void
    {
        [$site, $event, $seats] = $this->liveSite();

        Http::fake([
            '*payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A777']]),
        ]);

        $response = $this->checkout($site, $event, $seats);

        $response->assertRedirect();
        $this->assertStringContainsString('StartPay/A777', $response->headers->get('Location'));

        $order = $this->order($site);

        $this->assertSame('pending', $order->status, 'Nothing is confirmed before the money moves.');
        // The authority is written down, because the return is a URL anybody can type and the
        // only thing that makes it meaningful is a reference we already had.
        $this->assertSame('A777', $order->metadata['payment_reference']);
        $this->assertSame('zarinpal', $order->metadata['gateway']);
    }

    #[Test]
    public function coming_back_from_the_gateway_confirms_the_order(): void
    {
        [$site, $event, $seats] = $this->liveSite();

        Http::fake([
            '*payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A777']]),
            '*payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 5150]]),
        ]);

        $this->checkout($site, $event, $seats);
        $order = $this->order($site);

        $this->get('http://northgate.localhost/pay/zarinpal/return/'.$order->external_order_id.'?Status=OK&Authority=A777')
            ->assertRedirect('/order/'.$order->external_order_id);

        $this->assertSame('confirmed', $this->order($site)->status);
        $this->assertSame(2, $this->order($site)->allocations()->count());
    }

    #[Test]
    public function coming_back_twice_does_not_confirm_twice(): void
    {
        [$site, $event, $seats] = $this->liveSite();

        Http::fake([
            '*payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A777']]),
            // The second verify answers 101 — "already verified" — which is what Zarinpal really
            // says, and it must not undo anything.
            '*payment/verify.json' => Http::sequence()
                ->push(['data' => ['code' => 100, 'ref_id' => 5150]])
                ->push(['data' => ['code' => 101, 'ref_id' => 5150]]),
        ]);

        $this->checkout($site, $event, $seats);
        $reference = $this->order($site)->external_order_id;
        $url = 'http://northgate.localhost/pay/zarinpal/return/'.$reference;

        $this->get($url)->assertRedirect();
        $this->get($url)->assertRedirect();

        $this->assertSame('confirmed', $this->order($site)->status);
        $this->assertSame(2, $this->order($site)->allocations()->count(), 'Still two seats, not four.');
    }

    #[Test]
    public function a_buyer_who_never_comes_back_is_reconciled_later(): void
    {
        [$site, $event, $seats] = $this->liveSite();

        Http::fake([
            '*payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A777']]),
            '*payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 5150]]),
        ]);

        $this->checkout($site, $event, $seats);

        // They paid and closed the tab. Nothing came back through the return URL.
        $this->assertSame('pending', $this->order($site)->status);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('confirmed', $this->order($site)->status);
    }

    #[Test]
    public function a_return_naming_a_gateway_the_site_does_not_offer_is_not_a_way_in(): void
    {
        [$site, $event, $seats] = $this->liveSite();

        Http::fake(['*' => Http::response(['data' => ['code' => 100, 'authority' => 'A777']])]);

        $this->checkout($site, $event, $seats);
        $reference = $this->order($site)->external_order_id;

        // `offline` settles anything instantly. If the return path took any key, this would be a
        // one-request way to confirm an unpaid order.
        app(TenantContext::class)->runAs($site->tenant, fn () => Site::whereKey($site->id)
            ->update(['brand' => ['gateways' => ['zarinpal']]]));

        $this->get('http://northgate.localhost/pay/offline/return/'.$reference)->assertNotFound();

        $this->assertSame('pending', $this->order($site)->status);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function checkout(Site $site, $event, $seats)
    {
        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seats[0]->id, $seats[1]->id],
            'session_id' => 'sess_redirect',
        ])->assertCreated()->json();

        return $this->withSession(['seatmap_hold' => $hold['hold_token']])
            ->post('http://northgate.localhost/checkout', [
                'name' => 'Dana Scully',
                'email' => 'dana@example.test',
                'gateway' => 'zarinpal',
            ]);
    }

    private function order(Site $site): ExternalOrder
    {
        return app(TenantContext::class)->runAs(
            $site->tenant,
            fn () => ExternalOrder::latest('created_at')->firstOrFail()
        );
    }

    /** A live site whose organiser has turned Zarinpal on. */
    private function liveSite(): array
    {
        $fixture = $this->makeSellableEvent();
        $tenant = $fixture['tenant'];

        $site = app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.localhost',
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live', 'currency' => 'IRR']);

            TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_key' => 'seatmap/zarinpal',
                'enabled' => true,
                'settings' => [
                    'merchant_id' => encrypt('00000000-0000-0000-0000-000000000000'),
                    'amount_unit' => 'rial',
                ],
            ]);

            return $site->fresh();
        });

        // The event sells in rials, because Zarinpal takes nothing else.
        app(TenantContext::class)->runAs(
            $tenant,
            fn () => \App\Models\Event::whereKey($fixture['event']->id)->update(['currency' => 'IRR'])
        );

        app(GatewayRegistry::class)->forget();

        return [$site, $fixture['event']->fresh(), $fixture['seats']];
    }
}
