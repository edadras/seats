<?php

namespace Tests\Feature;

use App\Domain\Discounts\Discounts;
use App\Domain\Rehearsals\Rehearsals;
use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\SiteProvisioner;
use App\Domain\Sites\StorefrontCheckout;
use App\Domain\Vouchers\Vouchers;
use App\Models\Allocation;
use App\Models\Checkin;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Models\Voucher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\Support\RecordingGateway;
use Tests\TestCase;

/**
 * A rehearsal: the buyer's path walked through, with nobody's money in it.
 *
 * Three claims, and each of them is the kind that fails silently if it fails at all.
 *
 *   1. No money moves. A real gateway is never reached, whatever the form asks for.
 *   2. No figure counts it. The takings, the reports, the customer directory and an add-on's stock
 *      all behave as though the night had not happened.
 *   3. Nothing is left behind. Clearing puts the seats back and hands back what the rehearsal spent
 *      out of a discount code or a gift voucher — the two counters a rehearsal can actually move.
 *
 * The pair of refusals is tested first, because everything else rests on them: if a night with real
 * bookings could be flagged, or a rehearsal could go back on sale with its bookings still on it, the
 * other two claims would be about a set nobody can define.
 */
class RehearsalTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------ the two refusals */

    #[Test]
    public function a_night_that_has_sold_something_cannot_be_rehearsed(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $this->actingAs($owner)
            ->putJson('/v1/events/'.$fixture['event']->id.'/rehearsal', ['rehearsing' => true])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'rehearsal_has_real_bookings');

        $this->assertFalse((bool) $this->event($fixture)->is_rehearsal);
    }

    #[Test]
    public function a_rehearsal_cannot_go_back_on_sale_until_it_is_cleared(): void
    {
        $fixture = $this->rehearsing();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0], 'rehearsal');

        $this->actingAs($owner)
            ->putJson('/v1/events/'.$fixture['event']->id.'/rehearsal', ['rehearsing' => false])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'rehearsal_not_cleared')
            ->assertJsonPath('error.details.bookings', 1);

        // Cleared, it goes back without argument.
        $this->actingAs($owner)->deleteJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertSuccessful();

        $this->actingAs($owner)
            ->putJson('/v1/events/'.$fixture['event']->id.'/rehearsal', ['rehearsing' => false])
            ->assertOk()
            ->assertJsonPath('rehearsing', false);
    }

    #[Test]
    public function clearing_refuses_on_a_night_that_is_selling_for_real(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $this->actingAs($owner)->deleteJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'not_a_rehearsal');

        $this->assertSame(1, $this->inTenant($fixture, fn () => ExternalOrder::count()));
    }

    #[Test]
    public function rehearsing_is_for_somebody_who_may_manage_the_programme(): void
    {
        $fixture = $this->makeSellableEvent();
        $viewer = $this->makeUser($fixture['tenant'], 'viewer');

        $this->actingAs($viewer)
            ->putJson('/v1/events/'.$fixture['event']->id.'/rehearsal', ['rehearsing' => true])
            ->assertForbidden();

        $this->actingAs($viewer)->getJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertForbidden();

        $this->actingAs($viewer)->deleteJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ no money moves */

    #[Test]
    public function a_stale_gateway_key_never_reaches_the_real_gateway(): void
    {
        $fixture = $this->rehearsing();
        $site = $this->makeSite($fixture['tenant']);
        $gateway = $this->recording($fixture);

        $this->hold($fixture, [0])->assertCreated();

        $order = $this->inTenant($fixture, function () use ($site) {
            $hold = Hold::with('event')->orderByDesc('created_at')->firstOrFail();

            [$order] = app(StorefrontCheckout::class)->place(
                $site,
                $hold,
                ['name' => 'Dana Scully', 'email' => 'dana@example.test'],
                // The key a stale form would send: a real gateway, on a night being rehearsed.
                'recording',
                $site->url('/order/x'),
            );

            return $order;
        });

        $this->assertSame([], $gateway->begun, 'The real gateway was never asked for a penny.');
        $this->assertSame('confirmed', $order->status, 'And the booking went through anyway.');
        $this->assertSame('rehearsal:'.$order->external_order_id, $order->metadata['payment_reference']);
        $this->assertSame('rehearsal', $order->metadata['gateway']);
    }

    #[Test]
    public function the_checkout_offers_the_rehearsal_and_refuses_the_real_ways_to_pay(): void
    {
        $fixture = $this->rehearsing();
        $this->makeSite($fixture['tenant']);
        $this->hold($fixture, [0])->assertCreated();

        $this->get('http://northgate.test/checkout')
            ->assertOk()
            ->assertSee(__('payments.rehearsal.label'))
            // The banner, on the page where somebody is about to type a card number.
            ->assertSee(__('site.rehearsal.title'))
            ->assertDontSee(__('payments.offline.label'));

        // A form naming a real gateway is a stale form, and it is refused rather than silently
        // redirected: the buyer has to be told what is going to happen before it happens.
        $this->post('http://northgate.test/checkout', [
            'name' => 'Dana Scully',
            'email' => 'dana@example.test',
            'gateway' => 'offline',
        ])->assertSessionHasErrors('gateway');

        $this->assertSame(0, $this->inTenant($fixture, fn () => ExternalOrder::count()));
    }

    #[Test]
    public function somebody_else_shop_cannot_sell_a_night_being_rehearsed(): void
    {
        $fixture = $this->rehearsing();

        /*
         * The embedded picker is where a WooCommerce shop — or anybody's own checkout — asks for
         * seats, and what happens after it is money this platform neither takes nor can stop. A
         * rehearsal's promise can only be kept where we are the ones taking the money, so the edge
         * of what we control is where it is enforced.
         */
        $this->postJson('/v1/embed/events/'.$fixture['event']->public_id.'/holds', [
            'seat_ids' => [$fixture['seats'][0]->id],
            'session_id' => 'sess_'.Str::random(8),
        ])->assertStatus(409)->assertJsonPath('error.code', 'event_rehearsing');

        // The plan itself still reads: a picker that could not draw the room would look broken to
        // the organiser who is deliberately looking at it.
        $this->getJson('/v1/embed/events/'.$fixture['event']->public_id.'/availability')
            ->assertOk();
    }

    #[Test]
    public function the_whole_path_works_and_charges_nobody(): void
    {
        $fixture = $this->rehearsing();
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, [0, 1], 'rehearsal');

        $this->inTenant($fixture, function () {
            $order = ExternalOrder::firstOrFail();

            $this->assertSame('confirmed', $order->status);
            $this->assertSame(5000, (int) $order->total_amount, 'Priced exactly as a real sale.');
            // Tickets are the point: what a rehearsal is for is finding out that the QR code at
            // the door works before a thousand people arrive holding one.
            $this->assertSame(2, Ticket::where('status', 'issued')->count());
            $this->assertSame(2, Allocation::where('status', 'active')->count());
        });
    }

    /* ------------------------------------------------------------------ no figure counts it */

    #[Test]
    public function the_takings_do_not_count_a_rehearsal(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        // A real sale on one night, a rehearsal on another, in the same account.
        $this->buy($fixture, [0]);

        $other = $this->rehearsing($this->nightBeside($fixture));
        $this->buy($other, [0], 'rehearsal');

        $settlement = $this->actingAs($owner)->getJson('/v1/settlement')->assertOk()->json();

        $this->assertSame(2500, $settlement['totals'][0]['charged'], 'One real seat, and only one.');

        // And the event breakdown does not mention the rehearsal at all.
        $events = array_column($settlement['events'] ?? [], 'event_id');
        $this->assertNotContains($other['event']->id, $events);
    }

    #[Test]
    public function a_report_does_not_count_a_rehearsal(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $other = $this->rehearsing($this->nightBeside($fixture));
        $this->buy($other, [0], 'rehearsal');

        $report = $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'orders',
            'definition' => ['dimensions' => [], 'measures' => ['orders', 'revenue']],
        ])->assertOk()->json();

        $this->assertCount(1, $report['rows'], 'One row, because there is one real order.');
        $this->assertSame(1, (int) $report['rows'][0]['m0']);
        $this->assertSame(2500, (int) $report['rows'][0]['m1']);
    }

    #[Test]
    public function the_customer_directory_does_not_know_the_rehearsal_buyer(): void
    {
        $fixture = $this->rehearsing();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0], 'rehearsal');

        $customers = $this->actingAs($owner)->getJson('/v1/customers')->assertOk()->json();

        $this->assertSame([], $customers['data'], 'Nobody bought anything.');
    }

    #[Test]
    public function a_rehearsal_is_listed_nowhere_and_opens_by_its_own_address(): void
    {
        $fixture = $this->rehearsing();
        $this->makeSite($fixture['tenant']);

        // Not in what's on, and not in the sitemap — a rehearsal must never be offered to a search
        // engine or put in front of an organiser's actual customers.
        $this->get('http://northgate.test/')->assertOk()->assertDontSee($fixture['event']->name);
        $this->get('http://northgate.test/sitemap.xml')->assertOk()
            ->assertDontSee('/events/'.$fixture['event']->public_id);

        // And reachable by its own address, because that is the one thing a rehearsal is for.
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertSee(__('site.rehearsal.title'));
    }

    #[Test]
    public function a_rehearsal_does_not_sell_out_an_add_on(): void
    {
        $fixture = $this->rehearsing();
        $site = $this->makeSite($fixture['tenant']);

        $addon = $this->inTenant($fixture, fn () => \App\Models\Addon::create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Programme',
            'price' => 500,
            'currency' => 'EUR',
            'stock' => 1,
            'visible' => true,
        ]));

        $this->hold($fixture, [0])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Dana Scully',
            'email' => 'dana@example.test',
            'gateway' => 'rehearsal',
            'addons' => [$addon->id => 1],
        ])->assertRedirect();

        // The last programme is still there. Stock is often held for a whole season, so a rehearsal
        // that consumed it would sell out a real buyer on a different night.
        $this->assertSame(1, $this->inTenant(
            $fixture,
            fn () => app(\App\Domain\Addons\Addons::class)->remaining($addon->fresh())
        ));
    }

    /* ------------------------------------------------------------------ nothing is left behind */

    #[Test]
    public function clearing_puts_the_seats_back_and_takes_the_tickets_away(): void
    {
        $fixture = $this->rehearsing();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1], 'rehearsal');

        // Somebody stood at the door with a scanner, too: a rehearsal that stopped short of the
        // door would leave the one thing nobody can test twice untested.
        $this->inTenant($fixture, fn () => Checkin::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'ticket_id' => Ticket::firstOrFail()->id,
            'result' => 'valid',
            'scanned_at' => now(),
        ]));

        $cleared = $this->actingAs($owner)
            ->deleteJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertSuccessful()
            ->json();

        $this->assertSame(1, $cleared['cleared']['bookings']);
        $this->assertSame(2, $cleared['cleared']['seats']);
        $this->assertSame(1, $cleared['cleared']['scans']);
        $this->assertSame(0, $cleared['bookings'], 'And the tally agrees afterwards.');

        $this->inTenant($fixture, function () {
            $this->assertSame(0, ExternalOrder::count());
            $this->assertSame(0, Allocation::count());
            $this->assertSame(0, Ticket::count());
            $this->assertSame(0, Checkin::count());
        });

        // The hall is free again, which on this platform is not a counter being reset: availability
        // is derived from live allocations, so deleting them *is* putting the seats back.
        $free = $this->inTenant($fixture, fn () => collect(
            app(\App\Domain\Availability\AvailabilityService::class)->forEvent($this->event($fixture))
        )->where('state', 'available')->count());

        $this->assertSame(15, $free, 'Three rows of five, all of them back.');
    }

    #[Test]
    public function clearing_hands_back_what_a_discount_code_spent(): void
    {
        $fixture = $this->rehearsing();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0], 'rehearsal');

        $code = $this->inTenant($fixture, function () use ($fixture) {
            $code = DiscountCode::create([
                'tenant_id' => $fixture['tenant']->id,
                'code' => 'FIRSTNIGHT',
                'kind' => 'percent',
                'value' => 10,
                'max_uses' => 1,
                'status' => 'active',
            ]);

            app(Discounts::class)->applyTo(ExternalOrder::firstOrFail(), $code, 250);

            return $code->fresh();
        });

        $this->assertSame(1, (int) $code->used_count, 'The rehearsal spent the only use.');

        $this->actingAs($owner)->deleteJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertSuccessful();

        // Back to nought, or the code would be one use poorer for ever — silently, because the
        // redemption row that explains it goes with the booking.
        $this->assertSame(0, (int) $this->inTenant(
            $fixture,
            fn () => DiscountCode::firstOrFail()->used_count
        ));
    }

    #[Test]
    public function clearing_hands_back_what_a_gift_voucher_spent(): void
    {
        $fixture = $this->rehearsing();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0], 'rehearsal');

        $voucher = $this->inTenant($fixture, function () use ($fixture) {
            $voucher = app(Vouchers::class)->issue([
                'tenant_id' => $fixture['tenant']->id,
                'kind' => 'gift',
                'code' => Voucher::suggest(),
                'amount' => 5000,
                'currency' => 'EUR',
            ]);

            app(Vouchers::class)->spend($voucher, ExternalOrder::firstOrFail(), 2000);

            return $voucher;
        });

        $this->assertSame(3000, $this->inTenant(
            $fixture,
            fn () => app(Vouchers::class)->balance($voucher->fresh())
        ), 'Real money, spent on a rehearsal.');

        $this->actingAs($owner)->deleteJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertSuccessful();

        $this->assertSame(5000, $this->inTenant(
            $fixture,
            fn () => app(Vouchers::class)->balance($voucher->fresh())
        ), 'Every penny back: a gift card is somebody else’s money.');
    }

    #[Test]
    public function the_tally_says_what_the_rehearsal_has_done(): void
    {
        $fixture = $this->rehearsing();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1], 'rehearsal');

        $tally = $this->actingAs($owner)
            ->getJson('/v1/events/'.$fixture['event']->id.'/rehearsal')
            ->assertOk()
            ->json();

        $this->assertTrue($tally['rehearsing']);
        $this->assertSame(1, $tally['bookings']);
        $this->assertSame(2, $tally['seats']);
        $this->assertSame(2, $tally['tickets']);
        $this->assertSame(0, $tally['scans']);
        $this->assertSame(5000, $tally['not_charged']);
        $this->assertStringContainsString(
            '/events/'.$fixture['event']->public_id,
            (string) $tally['link'],
            'The address to go to and be the buyer.'
        );
    }

    #[Test]
    public function rehearsing_an_untouched_night_is_allowed_and_recorded(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)
            ->putJson('/v1/events/'.$fixture['event']->id.'/rehearsal', ['rehearsing' => true])
            ->assertOk()
            ->assertJsonPath('rehearsing', true);

        $this->assertTrue((bool) $this->event($fixture)->is_rehearsal);

        $this->assertTrue($this->inTenant(
            $fixture,
            // Found rather than assumed to be the latest row: publishing the chart wrote one too,
            // and two rows a millisecond apart have no reliable order.
            fn () => \App\Models\AuditLog::where('action', 'rehearsal.started')->exists()
        ));
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** A fixture whose night is being rehearsed. */
    private function rehearsing(?array $fixture = null): array
    {
        $fixture ??= $this->makeSellableEvent();

        $this->inTenant($fixture, fn () => app(Rehearsals::class)->start($this->event($fixture)));

        return ['event' => $this->event($fixture)] + $fixture;
    }

    /** A second night in the same account, on the same chart. */
    private function nightBeside(array $fixture): array
    {
        $event = $this->inTenant($fixture, fn () => Event::create([
            'venue_id' => $fixture['venue']->id,
            'seat_map_id' => $fixture['map']->id,
            'seat_map_version_id' => $fixture['event']->seat_map_version_id,
            'public_id' => 'evt_'.Str::lower(Str::random(20)),
            'name' => 'Second night',
            'status' => 'published',
            'starts_at' => now()->addWeeks(2),
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
        ]));

        $this->inTenant($fixture, fn () => \App\Models\EventPriceZone::create([
            'event_id' => $event->id,
            'key' => 'standard',
            'name' => 'Standard',
            'amount' => 2500,
        ]));

        return ['event' => $event->fresh()] + $fixture;
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
    private function buy(array $fixture, array $seats, string $gateway = 'offline'): void
    {
        $this->hold($fixture, $seats)->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Dana Scully',
            'email' => 'dana@example.test',
            'gateway' => $gateway,
        ])->assertRedirect();

        // Each buy is a fresh visitor: the basket lives in the session, and a second hold made in
        // the same one would be the same person adding to the first.
        $this->flushSession();
    }

    private function recording(array $fixture, string $answer = 'sent'): RecordingGateway
    {
        $gateway = new RecordingGateway($answer);

        // Inside the tenant: the registry is built per account, and a gateway registered outside
        // one is registered for nobody.
        $this->inTenant($fixture, fn () => app(GatewayRegistry::class)->register($gateway));

        return $gateway;
    }

    private function event(array $fixture): Event
    {
        return $this->inTenant($fixture, fn () => Event::findOrFail($fixture['event']->id));
    }

    private function inTenant(array $fixture, callable $work)
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], $work);
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
