<?php

namespace Tests\Feature;

use App\Domain\Orders\Exchanges;
use App\Domain\Sites\SiteProvisioner;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Models\Voucher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Moving a booking to another night, or to other seats on the same one.
 *
 * What the old answer — "we will refund you, then buy again" — costs a buyer is the seats in
 * between, and it is how somebody ends up with neither. So the order here is fixed and every test
 * is really about it: **the new seats are held first, the old ones are given back at the checkout,
 * and the money never moves twice.** An exchange abandoned halfway leaves the original ticket
 * exactly as it was.
 */
class ExchangeTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_old_seats_are_still_theirs_until_the_new_ones_are_paid_for(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['exchanges' => 'always']);
        $this->buy($fixture, [0]);

        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/exchange')
            ->assertRedirect('/events/'.$fixture['event']->public_id);

        // Pressing "move these seats" is an intention, not a transaction. Somebody who closes the
        // tab here still holds the ticket they paid for.
        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('active', Allocation::firstOrFail()->status);
            $this->assertSame('issued', Ticket::firstOrFail()->status);
            $this->assertSame('confirmed', ExternalOrder::firstOrFail()->status);
            $this->assertSame(0, Voucher::count());
        });
    }

    #[Test]
    public function paying_for_the_new_seats_gives_the_old_ones_back_in_the_same_breath(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['exchanges' => 'always']);
        $this->buy($fixture, [0]);

        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/exchange')
            ->assertRedirect();

        $reference = $this->reference($fixture);
        $this->buy($fixture, [1]);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $reference) {
            $old = Allocation::where('seat_id', $fixture['seats'][0]->id)->firstOrFail();
            $new = Allocation::where('seat_id', $fixture['seats'][1]->id)->firstOrFail();

            $this->assertSame('released', $old->status);
            $this->assertSame('active', $new->status);
            $this->assertSame('void', Ticket::where('allocation_id', $old->id)->firstOrFail()->status);
            $this->assertSame('issued', Ticket::where('allocation_id', $new->id)->firstOrFail()->status);

            $given = ExternalOrder::where('external_order_id', $reference)->firstOrFail();
            $taken = ExternalOrder::where('external_order_id', '!=', $reference)->firstOrFail();

            // A seat for a seat at the same price: the new booking is worth what it is worth, and
            // every penny of it was settled with the credit the old one came back as. Nobody was
            // charged twice for one seat, and nothing was left in the voucher.
            $this->assertSame('refunded', $given->status);
            $this->assertSame(2500, (int) $taken->total_amount);
            $this->assertSame(2500, (int) $taken->voucher_amount);
            $this->assertSame(0, app(\App\Domain\Vouchers\Vouchers::class)->balance(Voucher::firstOrFail()));
        });
    }

    #[Test]
    public function the_fee_comes_out_of_what_the_old_seats_were_worth(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['exchanges' => 'always', 'exchange_fee_amount' => 500]);
        $this->buy($fixture, [0]);

        $this->signIn();
        $reference = $this->reference($fixture);
        $this->post('http://northgate.test/account/orders/'.$reference.'/exchange')
            ->assertRedirect();
        $this->buy($fixture, [1]);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($reference) {
            $taken = ExternalOrder::where('external_order_id', '!=', $reference)->firstOrFail();

            // €25 back less a €5 fee, and the new €25 seat therefore costs €5 — not an invoice for
            // €25 and a refund for €20, which is the same arithmetic and a worse afternoon.
            $this->assertSame(2000, (int) Voucher::firstOrFail()->amount);
            $this->assertSame(2000, (int) $taken->voucher_amount);
            $this->assertSame(500, (int) $taken->total_amount - (int) $taken->voucher_amount);
        });
    }

    #[Test]
    public function a_night_that_does_not_offer_it_says_so_rather_than_starting_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        // `never` is the default: an organiser who has never thought about exchanges is not
        // quietly offering them.
        $this->buy($fixture, [0]);

        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/exchange')
            ->assertRedirect('/account')
            ->assertSessionMissing('seatmap_exchange');
    }

    #[Test]
    public function the_window_shuts_when_the_organiser_said_it_would(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        // The doors open in a week and the window is a year wide, so it closed long ago.
        $this->terms($fixture, ['exchanges' => 'until', 'exchange_window_hours' => 8760]);
        $this->buy($fixture, [0]);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $order = ExternalOrder::with('event')->firstOrFail();
            $terms = app(Exchanges::class)->check($order);

            $this->assertFalse($terms['allowed']);
            $this->assertSame('too_late', $terms['reason']);
        });

        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/exchange')
            ->assertRedirect('/account')
            ->assertSessionMissing('seatmap_exchange');
    }

    #[Test]
    public function a_cancelled_night_is_a_refund_and_not_an_exchange(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['exchanges' => 'always']);
        $this->buy($fixture, [0]);
        $this->terms($fixture, ['status' => 'cancelled', 'cancelled_at' => now()]);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $terms = app(Exchanges::class)->check(ExternalOrder::with('event')->firstOrFail());

            $this->assertFalse($terms['allowed']);
            $this->assertSame('event_cancelled', $terms['reason']);
        });
    }

    #[Test]
    public function somebody_else_cannot_spend_your_exchange(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['exchanges' => 'always']);
        $this->buy($fixture, [0]);

        $this->signIn();
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/exchange')
            ->assertRedirect();

        // The same session, a different name in the box: a stranger's reference must not be a way
        // to take a stranger's seats.
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][1]->id],
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Bo Nilsson',
            'email' => 'bo@example.test',
            'gateway' => 'offline',
        ])->assertRedirect('/checkout');

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('active', Allocation::firstOrFail()->status);
            $this->assertSame('confirmed', ExternalOrder::firstOrFail()->status);
            $this->assertSame(0, Voucher::count());
        });
    }

    #[Test]
    public function the_deadline_is_the_one_the_terms_describe(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $exchanges = app(Exchanges::class);
            $event = Event::findOrFail($fixture['event']->id);

            // Never: there is no deadline because there is no offer.
            $this->assertNull($exchanges->deadline($event));

            $event->forceFill(['exchanges' => 'always'])->save();
            $this->assertTrue($exchanges->deadline($event)->equalTo($event->starts_at));

            $event->forceFill(['exchanges' => 'until', 'exchange_window_hours' => 48])->save();
            $this->assertTrue($exchanges->deadline($event)
                ->equalTo($event->starts_at->copy()->subHours(48)));
        });
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function terms(array $fixture, array $attributes): void
    {
        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::whereKey($fixture['event']->id)->update($attributes)
        );
    }

    private function reference(array $fixture): string
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::orderBy('created_at')->firstOrFail()->external_order_id
        );
    }

    private function signIn(): void
    {
        $this->withSession(['seatmap_buyer' => [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
        ]]);
    }

    /** @param  list<int>  $seats */
    private function buy(array $fixture, array $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
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
