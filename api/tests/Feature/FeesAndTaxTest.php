<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderTotals;
use App\Domain\Sites\SiteProvisioner;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A booking fee and a tax rate, as their own lines.
 *
 * The point of the exercise is that a booking's arithmetic exists in exactly one place. The summary
 * the buyer reads, the amount the gateway is told, and the record an invoice is written from are
 * all the same numbers — so these tests compare what the checkout page says with what the order
 * ends up carrying, rather than checking either on its own.
 */
class FeesAndTaxTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_event_with_neither_charges_what_it_always_did(): void
    {
        $fixture = $this->makeSellableEvent(amount: 2500);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, 1);

        $order = $this->order($fixture['tenant']);

        $this->assertSame(2500, $order->total_amount);
        $this->assertSame(0, $order->metadata['totals']['fee']);
        $this->assertSame(0, $order->metadata['totals']['tax']);
    }

    #[Test]
    public function a_per_ticket_fee_is_charged_once_for_every_ticket(): void
    {
        $fixture = $this->makeSellableEvent(amount: 2000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, ['booking_fee_kind' => 'per_ticket', 'booking_fee_amount' => 150]);

        $this->hold($fixture, 3);

        $this->get('http://northgate.test/checkout')->assertOk()->assertSee('4.50');

        $this->pay();

        $order = $this->order($fixture['tenant']);

        $this->assertSame(6000 + 450, $order->total_amount);
        $this->assertSame(450, $order->metadata['totals']['fee']);
    }

    #[Test]
    public function a_tax_inside_the_price_does_not_move_the_total(): void
    {
        $fixture = $this->makeSellableEvent(amount: 11900);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, ['tax_rate' => 1900, 'tax_included' => true, 'tax_label' => 'VAT']);

        $this->buy($fixture, 1);

        $order = $this->order($fixture['tenant']);

        // €119.00 with 19% inside it: the buyer pays €119.00 and €19.00 of it was tax.
        $this->assertSame(11900, $order->total_amount);
        $this->assertSame(1900, $order->metadata['totals']['tax']);
    }

    #[Test]
    public function a_tax_on_top_is_added_to_the_total(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, ['tax_rate' => 1900, 'tax_included' => false]);

        $this->buy($fixture, 1);

        $order = $this->order($fixture['tenant']);

        $this->assertSame(11900, $order->total_amount);
        $this->assertSame(1900, $order->metadata['totals']['tax']);
    }

    #[Test]
    public function tax_is_charged_on_the_fee_as_well_as_on_the_ticket(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, [
            'booking_fee_kind' => 'per_order', 'booking_fee_amount' => 500,
            'tax_rate' => 2000, 'tax_included' => false,
        ]);

        $this->buy($fixture, 1);

        $order = $this->order($fixture['tenant']);

        // A booking fee is a service somebody sold, and the state taxes it too.
        $this->assertSame(2100, $order->metadata['totals']['tax']);
        $this->assertSame(12600, $order->total_amount);
    }

    #[Test]
    public function a_discount_comes_off_the_tickets_before_the_fee_and_the_tax(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, [
            'booking_fee_kind' => 'per_order', 'booking_fee_amount' => 0, 'booking_fee_percent' => 10,
            'tax_rate' => 2000, 'tax_included' => false,
        ]);

        $this->makeCode($fixture['tenant'], ['code' => 'HALF', 'kind' => 'percent', 'value' => 50]);

        $this->hold($fixture, 1);
        $this->post('http://northgate.test/checkout/discount', ['code' => 'HALF'])->assertRedirect();
        $this->pay();

        $totals = $this->order($fixture['tenant'])->metadata['totals'];

        // €100 less half is €50; a 10% fee on that is €5; 20% tax on €55 is €11.
        $this->assertEquals(
            ['tickets' => 10000, 'discount' => 5000, 'fee' => 500, 'tax' => 1100, 'total' => 6600],
            array_intersect_key($totals, array_flip(['tickets', 'discount', 'fee', 'tax', 'total'])),
        );
    }

    #[Test]
    public function a_booking_discounted_to_nothing_carries_no_fee(): void
    {
        $fixture = $this->makeSellableEvent(amount: 4000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, ['booking_fee_kind' => 'per_order', 'booking_fee_amount' => 300]);
        $this->makeCode($fixture['tenant'], ['code' => 'GUEST', 'kind' => 'percent', 'value' => 100]);

        $this->hold($fixture, 1);
        $this->post('http://northgate.test/checkout/discount', ['code' => 'GUEST'])->assertRedirect();
        $this->pay();

        // A comp with a three-euro surprise on it is a complaint waiting to happen.
        $this->assertSame(0, $this->order($fixture['tenant'])->total_amount);
    }

    #[Test]
    public function the_checkout_shows_what_the_order_is_charged(): void
    {
        $fixture = $this->makeSellableEvent(amount: 3000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, [
            'booking_fee_kind' => 'per_order', 'booking_fee_amount' => 250,
            'booking_fee_label' => 'Handling', 'tax_rate' => 900, 'tax_included' => false,
        ]);

        $this->hold($fixture, 2);

        $page = $this->get('http://northgate.test/checkout')->assertOk();

        $page->assertSee('Handling')->assertSee('2.50');
        // 2 × €30 plus €2.50, and 9% on top of that.
        $page->assertSee('68.13');

        $this->pay();

        $this->assertSame(6813, $this->order($fixture['tenant'])->total_amount);
    }

    #[Test]
    public function the_rate_is_kept_to_the_hundredth(): void
    {
        // Switzerland charges 8.1%. A column that only holds whole per cent is a column somebody
        // rounds a tax rate into.
        $event = $this->makeSellableEvent()['event'];
        $event->forceFill(['tax_rate' => 810, 'tax_included' => false])->save();

        $totals = OrderTotals::for($event, 10000);

        $this->assertSame(810, $totals->tax);
        $this->assertSame(10810, $totals->total);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function settings(array $fixture, array $attributes): void
    {
        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $fixture['event']->forceFill($attributes)->save()
        );
    }

    private function makeCode($tenant, array $attributes): void
    {
        app(TenantContext::class)->runAs($tenant, fn () => \App\Models\DiscountCode::create($attributes + [
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]));
    }

    private function order($tenant): ExternalOrder
    {
        return app(TenantContext::class)->runAs(
            $tenant,
            fn () => ExternalOrder::orderByDesc('created_at')->firstOrFail()
        );
    }

    private function hold(array $fixture, int $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, range(0, $seats - 1)),
        ])->assertCreated();
    }

    private function pay(): void
    {
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
    }

    private function buy(array $fixture, int $seats): void
    {
        $this->hold($fixture, $seats);
        $this->pay();
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
}
