<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\ResaleListing;
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
 * Offering a seat you cannot use back to the public, at what you paid for it.
 *
 * The one property every test here is really about: **a listing moves no inventory**. The seat is
 * merely offered while the listing is open, and the swap happens in one transaction at the moment
 * somebody else pays. A buyer who lists a ticket and then finds they can come after all has lost
 * nothing, which is the difference between this and a refund.
 */
class ResaleTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_listed_seat_is_offered_again_without_being_taken_from_the_seller(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);

        $seat = $fixture['seats'][0]->id;
        $this->assertFalse($this->isOffered($fixture, $seat));

        $this->signIn();
        $this->offer($fixture);

        // Both at once, which is the whole design: the public may buy it, and it is still theirs.
        $this->assertTrue($this->isOffered($fixture, $seat));

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('active', Allocation::firstOrFail()->status);
            $this->assertSame('issued', Ticket::firstOrFail()->status);
            $this->assertSame('confirmed', ExternalOrder::firstOrFail()->status);

            // Face value, and there is no field on the form that could say otherwise.
            $listing = ResaleListing::firstOrFail();
            $this->assertSame('open', $listing->state);
            $this->assertSame(2500, (int) $listing->amount);
        });
    }

    #[Test]
    public function taking_it_off_sale_puts_it_back_out_of_reach(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->offer($fixture);

        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/unresell')
            ->assertRedirect('/account');

        $this->assertFalse($this->isOffered($fixture, $fixture['seats'][0]->id));

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('withdrawn', ResaleListing::firstOrFail()->state);
            $this->assertSame('active', Allocation::firstOrFail()->status);
        });
    }

    #[Test]
    public function the_seller_is_paid_the_moment_somebody_else_buys_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->offer($fixture);

        $reference = $this->reference($fixture);

        // A second buyer, taking the same seat off the map like anybody else.
        $this->flushSession();
        $this->buy($fixture, [0], 'Bo Nilsson', 'bo@example.test');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($reference) {
            $seller = Allocation::where('status', 'released')->firstOrFail();
            $buyer = Allocation::where('status', 'active')->firstOrFail();

            // One seat, two allocations, and only the second one is a ticket anybody can use.
            $this->assertSame($seller->seat_id, $buyer->seat_id);
            $this->assertSame('void', Ticket::where('allocation_id', $seller->id)->firstOrFail()->status);
            $this->assertSame('issued', Ticket::where('allocation_id', $buyer->id)->firstOrFail()->status);

            $listing = ResaleListing::firstOrFail();
            $this->assertSame('sold', $listing->state);
            $this->assertNotNull($listing->voucher_id);

            // Paid in credit, at face value, to the address that listed it.
            $credit = Voucher::findOrFail($listing->voucher_id);
            $this->assertSame(2500, (int) $credit->amount);
            $this->assertSame('amina@example.test', $credit->email);
            $this->assertSame('credit', $credit->kind);

            // And the seller's own booking now reads as what it is.
            $this->assertSame('refunded', ExternalOrder::where('external_order_id', $reference)
                ->firstOrFail()->status);
        });
    }

    #[Test]
    public function an_organiser_may_pay_the_seller_in_money_instead_of_credit(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true, 'resale_pays' => 'refund']);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->offer($fixture);

        $this->flushSession();
        $this->buy($fixture, [0], 'Bo Nilsson', 'bo@example.test');

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $listing = ResaleListing::firstOrFail();

            $this->assertSame('sold', $listing->state);
            // No voucher, because the money goes back the way it came. What this platform will not
            // do is quietly issue credit while the screen says "refund".
            $this->assertNull($listing->voucher_id);
            $this->assertSame(0, Voucher::count());
        });
    }

    #[Test]
    public function a_night_that_does_not_take_tickets_back_refuses(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        // `resale` is off by default: a venue that has never thought about it is not offering it.
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->offer($fixture);

        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ResaleListing::count()
        ));
    }

    #[Test]
    public function a_ticket_somebody_has_already_walked_in_on_cannot_be_offered(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Ticket::query()->update(['status' => 'used', 'used_at' => now()])
        );

        $this->signIn();
        $this->offer($fixture);

        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ResaleListing::count()
        ));
    }

    #[Test]
    public function staff_may_take_a_listing_down_and_have_no_way_to_put_one_up(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->offer($fixture);

        $owner = $this->makeUser($fixture['tenant']);
        $eventId = $fixture['event']->id;

        $this->actingAs($owner)
            ->getJson("/v1/events/{$eventId}/resale")
            ->assertOk()
            ->assertJsonPath('open', 1)
            ->assertJsonPath('data.0.state', 'open');

        $id = app(TenantContext::class)->runAs($fixture['tenant'], fn () => ResaleListing::firstOrFail()->id);

        $this->actingAs($owner)
            ->deleteJson("/v1/events/{$eventId}/resale/{$id}")
            ->assertOk()
            ->assertJsonPath('data.state', 'withdrawn');

        // There is no endpoint that lists somebody else's seat for them, and there will not be:
        // selling a customer's property is not an administrative action.
        $this->actingAs($owner)
            ->postJson("/v1/events/{$eventId}/resale", [])
            ->assertStatus(405);
    }

    #[Test]
    public function the_screen_is_behind_the_permissions_it_belongs_to(): void
    {
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');
        $eventId = $fixture['event']->id;

        $this->actingAs($doorman)->getJson("/v1/events/{$eventId}/resale")->assertForbidden();
    }

    #[Test]
    public function one_seat_is_offered_once_however_often_the_form_is_sent(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);
        $this->signIn();

        foreach (range(1, 3) as $ignored) {
            $this->offer($fixture);
        }

        // Two open listings against one allocation would be one seat sold twice. The database
        // refuses it as well — see the partial unique index — and so does the service.
        $this->assertSame(1, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ResaleListing::count()
        ));
    }

    #[Test]
    public function a_seat_taken_off_sale_can_be_offered_again(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);
        $this->signIn();

        $this->offer($fixture);
        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/unresell')
            ->assertRedirect('/account');
        $this->offer($fixture);

        $this->assertTrue($this->isOffered($fixture, $fixture['seats'][0]->id));

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            // Two rows, and only one of them open: what was taken down is history rather than
            // something in the way of changing your mind back.
            $this->assertSame(2, ResaleListing::count());
            $this->assertSame(1, ResaleListing::where('state', 'open')->count());
        });
    }

    #[Test]
    public function a_standing_ticket_is_refused_out_loud_rather_than_listed_and_forgotten(): void
    {
        $fixture = $this->makeSellableEvent(chart: $this->geometryWithStandingArea(100));
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);

        // The same booking, made into what it would have been if they had bought a place in the
        // pit instead of a chair: no seat, one admission, and an area it belongs to.
        $pit = $fixture['areas']->firstWhere('key', 'pit')->id;

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Allocation::query()->update([
            'seat_id' => null,
            'capacity_object_id' => $pit,
            'quantity' => 1,
        ]));

        $refused = app(TenantContext::class)->runAs($fixture['tenant'], function () {
            try {
                app(\App\Domain\Resale\Resales::class)->list(
                    Allocation::firstOrFail(), 'amina@example.test', 'Amina Farsi',
                );

                return 'listed';
            } catch (\App\Exceptions\ApiException $e) {
                return $e->errorCode();
            }
        });

        // A right to come in is not a particular chair, so there is nothing to hand to one buyer
        // rather than another. Said out loud: a listing that quietly never sold would be worse.
        $this->assertSame('resale_not_seated', $refused);
    }

    #[Test]
    public function a_seat_cannot_be_taken_off_sale_while_somebody_is_buying_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->offer($fixture);

        // A second buyer with it in their basket, not yet paid for.
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][0]->id],
        ])->assertCreated();

        $refused = app(TenantContext::class)->runAs($fixture['tenant'], function () {
            try {
                app(\App\Domain\Resale\Resales::class)->withdraw(ResaleListing::firstOrFail());

                return 'withdrawn';
            } catch (\App\Exceptions\ApiException $e) {
                return $e->errorCode();
            }
        });

        // Taking it back now would leave that buyer paying for a seat the database will refuse to
        // give them. The hold lasts minutes; the answer is to wait for it, and to say so.
        $this->assertSame('resale_being_bought', $refused);
        $this->assertSame('open', app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ResaleListing::firstOrFail()->state
        ));
    }

    #[Test]
    public function walking_in_on_a_listed_ticket_takes_the_seat_off_sale(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->terms($fixture, ['resale' => true]);
        $this->buy($fixture, [0]);
        $this->signIn();
        $this->offer($fixture);

        // People change their minds on the night: the seat is on offer, and its owner turns up.
        $scan = app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $event = Event::findOrFail($fixture['event']->id);
            // A fresh code, because the plaintext of the original exists only in the response that
            // minted it. Reissuing voids the old one, which is what it is for.
            $ticket = app(\App\Domain\Orders\TicketIssuer::class)
                ->reissue(Allocation::firstOrFail());

            return app(\App\Domain\Checkin\CheckinService::class)
                ->scan($event, (string) $ticket->plainToken, null);
        });

        $this->assertSame('valid', $scan['result']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            // The most conclusive fact about a seat is the person sitting in it.
            $this->assertSame('withdrawn', ResaleListing::firstOrFail()->state);
        });

        $this->assertFalse($this->isOffered($fixture, $fixture['seats'][0]->id));
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** Put every seat of the booking back on offer, as the account page does. */
    private function offer(array $fixture): void
    {
        $ids = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Allocation::where('status', 'active')->pluck('id')->all()
        );

        $this->post('http://northgate.test/account/orders/'.$this->reference($fixture).'/resell', [
            'allocation_ids' => $ids,
        ])->assertRedirect('/account');
    }

    /** Whether the public may buy that seat right now. */
    private function isOffered(array $fixture, string $seatId): bool
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $seatId) {
            $event = Event::findOrFail($fixture['event']->id);
            $free = app(\App\Domain\Availability\AvailabilityService::class)->forEvent($event);

            foreach ($free as $seat) {
                if ($seat['seat_id'] === $seatId) {
                    return 'available' === $seat['state'];
                }
            }

            return false;
        });
    }

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
    private function buy(
        array $fixture,
        array $seats,
        string $name = 'Amina Farsi',
        string $email = 'amina@example.test',
    ): void {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => $name,
            'email' => $email,
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
