<?php

namespace Tests\Feature;

use App\Domain\Addons\Addons;
use App\Domain\Orders\OrderTotals;
use App\Domain\Sites\SiteProvisioner;
use App\Models\Addon;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\OrderAddon;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A programme, a glass of wine, and money given for nothing at all.
 *
 * Two shapes with one difference that decides the arithmetic: an add-on is a sale and sits inside
 * the booking fee and the tax; a donation is a gift and sits outside both. Most of what is checked
 * here is that boundary, because getting it wrong charges somebody a fee for the privilege of
 * giving an organiser money.
 */
class AddonTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_event_that_sells_nothing_extra_is_unchanged(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $order = $this->order($fixture);

        $this->assertSame(5000, (int) $order->total_amount);
        $this->assertSame(0, (int) $order->donation);
        $this->assertCount(0, $order->addonLines);
    }

    #[Test]
    public function a_programme_is_charged_and_the_fee_and_tax_are_charged_on_it(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, [
            'booking_fee_kind' => 'per_order',
            'booking_fee_amount' => 100,
            'tax_rate' => 1000,           // 10%
            'tax_included' => false,
        ]);
        $addon = $this->addon($fixture, ['name' => 'Programme', 'price' => 500]);

        $this->buy($fixture, [0], addons: [$addon->id => 2]);

        // €50 seat + €10 of programmes + €1 fee = €61, and 10% on all of it = €6.10.
        $order = $this->order($fixture);

        $this->assertSame(6710, (int) $order->total_amount);
        $this->assertSame(1000, (int) $order->metadata['totals']['addons']);
        $this->assertSame(610, (int) $order->metadata['totals']['tax']);
        $this->assertSame(1000, (int) $order->addonLines->first()->amount);
        $this->assertSame(2, (int) $order->addonLines->first()->quantity);
    }

    #[Test]
    public function a_donation_carries_no_fee_and_no_tax(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, [
            'booking_fee_kind' => 'per_order',
            'booking_fee_amount' => 100,
            'tax_rate' => 1000,
            'tax_included' => false,
            'donations' => true,
        ]);

        $this->buy($fixture, [0], donation: '20');

        /*
         * €50 seat + €1 fee = €51, tax €5.10, and the €20 gift on top: €76.10.
         *
         * If the donation were inside the taxable base the total would be €78.10, and the extra
         * two euros would be a fee and a tax on somebody's charity.
         */
        $order = $this->order($fixture);

        $this->assertSame(7610, (int) $order->total_amount);
        $this->assertSame(100, (int) $order->metadata['totals']['fee']);
        $this->assertSame(510, (int) $order->metadata['totals']['tax']);
        $this->assertSame(2000, (int) $order->donation);
    }

    #[Test]
    public function a_booking_that_is_nothing_but_a_gift_carries_no_fee(): void
    {
        // The seats are free — a comp — and the buyer gives something anyway. A booking fee here
        // would be a charge on a free ticket and a charge on a donation at the same time.
        $event = new Event([
            'booking_fee_kind' => 'per_order',
            'booking_fee_amount' => 100,
            'tax_rate' => 1000,
            'tax_included' => false,
        ]);

        $totals = OrderTotals::for($event, tickets: 0, donation: 2500);

        $this->assertSame(0, $totals->fee);
        $this->assertSame(0, $totals->tax);
        $this->assertSame(2500, $totals->total);
    }

    #[Test]
    public function a_discount_comes_off_the_tickets_and_not_off_the_wine(): void
    {
        $event = new Event(['booking_fee_kind' => 'none', 'tax_rate' => 0]);

        // Half price on the seats is not half price on the bar, and a code that quietly took money
        // off a programme would be a code the bar cannot reconcile.
        $totals = OrderTotals::for($event, tickets: 10000, discount: 5000, addons: 800);

        $this->assertSame(5000, $totals->discount);
        $this->assertSame(800, $totals->addons);
        $this->assertSame(5800, $totals->total);
    }

    #[Test]
    public function a_per_ticket_addon_is_one_each_whatever_the_browser_says(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $addon = $this->addon($fixture, ['name' => 'Cloakroom', 'price' => 200, 'per' => 'ticket']);

        // Three seats, and a request that asks for one. It is not a choice: the number follows the
        // tickets, so it is three.
        $this->buy($fixture, [0, 1, 2], addons: [$addon->id => 1]);

        $line = $this->order($fixture)->addonLines->first();

        $this->assertSame(3, (int) $line->quantity);
        $this->assertSame(600, (int) $line->amount);
    }

    #[Test]
    public function the_last_programme_cannot_be_sold_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $addon = $this->addon($fixture, ['name' => 'Poster', 'price' => 1000, 'stock' => 1]);

        $this->buy($fixture, [0], addons: [$addon->id => 1]);

        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(Addons::class)->remaining($addon->fresh())
        ));

        // A second buyer, in a second session, after the last one went.
        $this->newBrowser();
        $this->hold($fixture, [1])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Bijan', 'email' => 'bijan@example.test', 'gateway' => 'offline',
            'addons' => [$addon->id => 1],
        ])->assertRedirect('/checkout');

        // Their seats are still theirs: nothing has been charged, and losing a booking over a
        // ten-euro poster would be the wrong trade.
        $this->assertSame(1, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::where('status', 'confirmed')->count()
        ));
    }

    #[Test]
    public function a_cancelled_booking_gives_its_programmes_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $addon = $this->addon($fixture, ['name' => 'Poster', 'price' => 1000, 'stock' => 2]);

        $this->buy($fixture, [0], addons: [$addon->id => 2]);

        $this->assertSame(0, $this->remaining($fixture, $addon));

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => ExternalOrder::query()
            ->update(['status' => 'cancelled']));

        // The organiser did not hand a poster over, so the poster is still there to sell.
        $this->assertSame(2, $this->remaining($fixture, $addon));
    }

    #[Test]
    public function more_than_the_organiser_allows_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $addon = $this->addon($fixture, ['name' => 'Wine', 'price' => 700, 'max_per_order' => 2]);

        $this->hold($fixture, [0])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina', 'email' => 'amina@example.test', 'gateway' => 'offline',
            'addons' => [$addon->id => 5],
        ])->assertRedirect('/checkout');

        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => OrderAddon::count()
        ));
    }

    #[Test]
    public function something_this_event_does_not_sell_is_refused_rather_than_dropped(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $other = $this->makeSellableEvent();
        $theirs = $this->addon($other, ['name' => 'Their programme', 'price' => 500]);

        $this->hold($fixture, [0])->assertCreated();

        // Silently dropping it would charge for seats and hand over no programme.
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina', 'email' => 'amina@example.test', 'gateway' => 'offline',
            'addons' => [$theirs->id => 1],
        ])->assertRedirect('/checkout');

        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::where('status', 'confirmed')->count()
        ));
    }

    #[Test]
    public function the_donation_box_is_read_in_the_money_it_is_labelled_in(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['donations' => true]);

        // A buyer typing 3 into a box marked EUR means three euros. Reading it as three cents is a
        // hundredfold insult to somebody who meant to be generous.
        $this->buy($fixture, [0], donation: '3');

        $this->assertSame(300, (int) $this->order($fixture)->donation);
    }

    #[Test]
    public function a_donation_is_ignored_where_none_was_asked_for(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);

        // The event does not ask. A hand-written form must not be able to give it one anyway —
        // not because a gift is unwelcome, but because it would appear on a total nobody agreed to.
        $this->buy($fixture, [0], donation: '50');

        $order = $this->order($fixture);

        $this->assertSame(0, (int) $order->donation);
        $this->assertSame(5000, (int) $order->total_amount);
    }

    #[Test]
    public function the_quote_agrees_with_what_is_charged(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['booking_fee_kind' => 'per_order', 'booking_fee_amount' => 100, 'donations' => true]);
        $addon = $this->addon($fixture, ['name' => 'Programme', 'price' => 500]);

        $this->hold($fixture, [0])->assertCreated();

        $quote = $this->postJson('http://northgate.test/checkout/quote', [
            'addons' => [$addon->id => 2],
            'donation' => '3',
        ])->assertOk();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina', 'email' => 'amina@example.test', 'gateway' => 'offline',
            'addons' => [$addon->id => 2],
            'donation' => '3',
        ])->assertRedirect();

        // The one bug a checkout must not have: a summary that disagrees with the charge.
        $this->assertSame(
            (int) $quote->json('total'),
            (int) $this->order($fixture)->total_amount,
        );
    }

    #[Test]
    public function the_invoice_lists_the_programmes_and_not_the_gift(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $site = $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['donations' => true]);
        $addon = $this->addon($fixture, ['name' => 'Programme', 'price' => 500]);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => $site->update([
            'invoices_enabled' => true,
            'legal_name' => 'Northgate Theatre Ltd',
            'billing_address' => '1 Northgate, York',
        ]));

        $this->buy($fixture, [0], addons: [$addon->id => 1], donation: '20');

        $invoice = app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(
            \App\Domain\Invoicing\InvoiceIssuer::class
        )->issue(
            $site,
            ExternalOrder::with(['allocations', 'event', 'addonLines'])->firstOrFail(),
        ));

        $descriptions = array_column($invoice->lines, 'description');

        // A programme is a sale and belongs on the document. A gift is not a sale, and the totals
        // below it were not computed from it.
        $this->assertContains('Programme', $descriptions);
        $this->assertSame(2000, (int) $invoice->totals['donation']);
    }

    #[Test]
    public function the_organiser_saves_what_is_on_the_counter_as_one_list(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $saved = $this->actingAs($owner)->putJson('/v1/events/'.$fixture['event']->id.'/addons', [
            'addons' => [
                ['name' => 'Programme', 'price' => 500, 'stock' => 100],
                ['name' => 'Wine', 'price' => 700, 'max_per_order' => 4],
            ],
            'donations' => true,
            'donation_prompt' => 'Help us keep the lights on',
            'donation_suggested' => 500,
        ])->assertOk();

        $saved->assertJsonPath('donations.offered', true);
        $saved->assertJsonPath('data.0.name', 'Programme');
        $saved->assertJsonPath('data.0.remaining', 100);
        // The event's own money, always — a euro programme against a rial event is a line nobody
        // can add up.
        $saved->assertJsonPath('data.1.currency', $fixture['event']->currency);
    }

    #[Test]
    public function something_that_has_been_bought_is_hidden_rather_than_deleted(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $addon = $this->addon($fixture, ['name' => 'Programme', 'price' => 500]);
        $owner = $this->makeUser($fixture['tenant']);

        $this->buy($fixture, [0], addons: [$addon->id => 1]);

        $this->actingAs($owner)->putJson('/v1/events/'.$fixture['event']->id.'/addons', ['addons' => []])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'addon_sold');

        // Hiding it is the way: the booking still points at it, and a deleted row would leave a
        // buyer holding a receipt for something nobody can name.
        $this->actingAs($owner)->putJson('/v1/events/'.$fixture['event']->id.'/addons', [
            'addons' => [['id' => $addon->id, 'name' => 'Programme', 'price' => 500, 'visible' => false]],
        ])->assertOk()->assertJsonPath('data.0.visible', false);
    }

    #[Test]
    public function the_counter_is_behind_the_permission_that_sets_prices(): void
    {
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)
            ->putJson('/v1/events/'.$fixture['event']->id.'/addons', ['addons' => []])
            ->assertForbidden();
    }

    #[Test]
    public function the_checkout_offers_what_is_on_the_counter(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['donations' => true, 'donation_prompt' => 'Keep the lights on']);
        $this->addon($fixture, ['name' => 'Programme', 'price' => 500]);
        $this->addon($fixture, ['name' => 'Old poster', 'price' => 100, 'visible' => false]);

        $this->hold($fixture, [0])->assertCreated();

        $this->get('http://northgate.test/checkout')
            ->assertOk()
            ->assertSee('Programme')
            ->assertSee('Keep the lights on')
            // A hidden add-on is not offered, and is not a thing a hand-written form finds either.
            ->assertDontSee('Old poster');
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function remaining(array $fixture, Addon $addon): int
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(Addons::class)->remaining($addon->fresh())
        );
    }

    private function terms(array $fixture, array $attributes): void
    {
        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::whereKey($fixture['event']->id)->update($attributes)
        );
    }

    private function addon(array $fixture, array $attributes): Addon
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], fn () => Addon::create($attributes + [
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'currency' => $fixture['event']->currency,
        ]));
    }

    private function order(array $fixture): ExternalOrder
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::with('addonLines')->orderByDesc('created_at')->firstOrFail()
        );
    }

    /** @param  list<int>  $seats */
    private function hold(array $fixture, array $seats)
    {
        return $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ]);
    }

    /** @param  list<int>  $seats */
    private function buy(array $fixture, array $seats, array $addons = [], string $donation = ''): void
    {
        $this->hold($fixture, $seats)->assertCreated();

        $this->post('http://northgate.test/checkout', array_filter([
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
            'addons' => $addons,
            // What a buyer types, in the currency the box is labelled in.
            'donation' => $donation,
        ]))->assertRedirect();
    }

    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
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
