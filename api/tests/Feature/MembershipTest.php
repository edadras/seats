<?php

namespace Tests\Feature;

use App\Domain\Access\AccessCodes;
use App\Domain\Inventory\HoldService;
use App\Domain\Memberships\Memberships;
use App\Domain\Orders\OrderService;
use App\Models\Addon;
use App\Models\Membership;
use App\Models\MembershipScheme;
use App\Models\OrderAddon;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A venue's Friends scheme.
 *
 * The platform already had two things that look like this and are not: loyalty, which is earned by
 * coming, and a season ticket, which is one run of one production. A membership is a fee, a period
 * and a standing arrangement — and it is the oldest of the three.
 *
 * Two decisions are what these tests are really about. **It is sold as an add-on**, so a membership
 * bought beside a ticket is an ordinary booking that happens to have made somebody a member — one
 * order, one gateway, one refund path, and nothing downstream learning a new shape. And **a renewal
 * extends rather than replaces**, because a scheme that took four months off somebody for paying
 * early would be one that punished the members it exists to keep.
 */
class MembershipTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private int $taken = 0;

    /* ------------------------------------------------------------------------------ schemes */

    #[Test]
    public function a_scheme_on_sale_gets_something_to_sell_it_with(): void
    {
        $tenant = $this->makeTenant();

        $scheme = $this->scheme($tenant, ['name' => 'Friends'], sellOnline: true);

        $this->assertNotNull($scheme->addon_id);

        $addon = Addon::withoutGlobalScopes()->find($scheme->addon_id);

        // Event-wide and one per order: joining twice in one booking is not a thing somebody means.
        $this->assertNull($addon->event_id);
        $this->assertSame(1, (int) $addon->max_per_order);
        $this->assertSame('order', $addon->per);
        $this->assertTrue((bool) $addon->visible);
        $this->assertSame(3000, (int) $addon->price);
    }

    #[Test]
    public function taking_it_off_sale_hides_it_rather_than_deleting_it(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant, [], sellOnline: true);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            $again = app(Memberships::class)->save($scheme, [
                'name' => 'Friends',
                'currency' => 'EUR',
                'price' => 3000,
                'months' => 12,
            ], false);

            // The add-on is on the booking of everybody who has already joined, so it stays.
            $this->assertNotNull($again->addon_id);
            $this->assertFalse((bool) Addon::find($again->addon_id)->visible);
        });
    }

    #[Test]
    public function a_scheme_people_have_joined_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant);

        app(TenantContext::class)->runAs($tenant, fn () => app(Memberships::class)
            ->grant($scheme, 'lotte@example.test'));

        $this->actingAs($this->makeUser($tenant))
            ->deleteJson('/v1/memberships/'.$scheme->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'membership_scheme_in_use');
    }

    /* -------------------------------------------------------------------------- joining */

    #[Test]
    public function renewing_early_adds_to_what_somebody_has_rather_than_replacing_it(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant, ['months' => 12]);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            $first = app(Memberships::class)->grant($scheme, 'lotte@example.test');
            $ends = $first->ends_at->copy();

            // Four months early. A scheme that took those four months away would be one that
            // punished paying early, which is the opposite of what a Friends scheme is for.
            $second = app(Memberships::class)->grant($scheme, 'lotte@example.test');

            $this->assertTrue($second->ends_at->isSameDay($ends->copy()->addMonths(12)));
            $this->assertSame(2, Membership::where('email', 'lotte@example.test')->count());
        });
    }

    #[Test]
    public function a_membership_runs_out_by_itself(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            $one = app(Memberships::class)->grant($scheme, 'lotte@example.test');

            $this->assertTrue($one->isCurrent());

            // Nothing runs to make this true; it is a comparison against today, which is why a
            // member is never turned away because a nightly job did not run.
            $one->forceFill(['ends_at' => now()->subDay()])->save();

            $this->assertFalse($one->fresh()->isCurrent());
            $this->assertNull(app(Memberships::class)->standing('lotte@example.test'));
        });
    }

    #[Test]
    public function ending_one_keeps_the_record(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant);

        $membership = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(Memberships::class)->grant($scheme, 'lotte@example.test')
        );

        $this->actingAs($this->makeUser($tenant))
            ->deleteJson('/v1/memberships/members/'.$membership->id)
            ->assertOk()
            ->assertJsonPath('current', false);

        // Kept rather than deleted: it was true.
        $this->assertNotNull(Membership::withoutGlobalScopes()->find($membership->id));
    }

    /* --------------------------------------------------------------- bought with a ticket */

    #[Test]
    public function buying_the_add_on_makes_somebody_a_member(): void
    {
        $night = $this->makeSellableEvent();
        $scheme = $this->scheme($night['tenant'], [], sellOnline: true);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $scheme) {
            $order = $this->sell($night, [$scheme->addon_id => 1]);

            $this->assertSame('confirmed', $order->status);

            $membership = app(Memberships::class)->standing('sam@example.test');

            $this->assertNotNull($membership);
            $this->assertSame($scheme->id, $membership->membership_scheme_id);
            $this->assertSame('bought', $membership->source);
            $this->assertSame($order->id, $membership->external_order_row_id);
        });
    }

    #[Test]
    public function confirming_the_same_booking_twice_does_not_buy_two_years(): void
    {
        $night = $this->makeSellableEvent();
        $scheme = $this->scheme($night['tenant'], [], sellOnline: true);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $scheme) {
            $order = $this->sell($night, [$scheme->addon_id => 1]);

            // A gateway that sent its webhook and its redirect both.
            app(Memberships::class)->settle($order->fresh());
            app(Memberships::class)->settle($order->fresh());

            $this->assertSame(1, Membership::where('email', 'sam@example.test')->count());
        });
    }

    #[Test]
    public function a_booking_with_no_membership_on_it_makes_nobody_a_member(): void
    {
        $night = $this->makeSellableEvent();
        $this->scheme($night['tenant'], [], sellOnline: true);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->sell($night);

            $this->assertSame(0, Membership::count());
        });
    }

    /* ------------------------------------------------------------------------- what it buys */

    #[Test]
    public function a_member_gets_their_percentage_off_the_seats_and_nothing_else(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant, ['discount_percent' => 20]);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            app(Memberships::class)->grant($scheme, 'lotte@example.test');

            // The seats only. The fee, the tax and a programme are somebody else's arithmetic —
            // the same rule a discount code follows.
            $this->assertSame(2000, app(Memberships::class)->discountOn('lotte@example.test', 10000));
            $this->assertSame(0, app(Memberships::class)->discountOn('nobody@example.test', 10000));
        });
    }

    #[Test]
    public function the_best_current_membership_is_the_one_that_counts(): void
    {
        $tenant = $this->makeTenant();
        $friend = $this->scheme($tenant, ['name' => 'Friend', 'discount_percent' => 10]);
        $patron = $this->scheme($tenant, ['name' => 'Patron', 'discount_percent' => 25]);

        app(TenantContext::class)->runAs($tenant, function () use ($friend, $patron) {
            app(Memberships::class)->grant($friend, 'lotte@example.test');
            app(Memberships::class)->grant($patron, 'lotte@example.test');

            $this->assertSame('Patron', app(Memberships::class)->standing('lotte@example.test')->scheme->name);
            $this->assertSame(2500, app(Memberships::class)->discountOn('lotte@example.test', 10000));
        });
    }

    #[Test]
    public function a_scheme_switched_off_stops_being_worth_anything(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant, ['discount_percent' => 20]);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            app(Memberships::class)->grant($scheme, 'lotte@example.test');
            $scheme->forceFill(['enabled' => false])->save();

            $this->assertSame(0, app(Memberships::class)->discountOn('lotte@example.test', 10000));
        });
    }

    #[Test]
    public function a_member_walks_past_the_presale_door_and_a_stranger_does_not(): void
    {
        $night = $this->makeSellableEvent();
        $scheme = $this->scheme($night['tenant'], ['presale' => true]);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $scheme) {
            app(Memberships::class)->grant($scheme, 'lotte@example.test');

            $night['event']->forceFill([
                'presale_starts_at' => now()->subDay(),
                'on_sale_at' => now()->addWeek(),
                'member_presale' => true,
            ])->save();

            $event = $night['event']->fresh();

            // No code spent, because there was none: a Friend walking past the queue is not using
            // up somebody else's invitation.
            $this->assertNull(app(AccessCodes::class)->admit($event, null, 2, 'lotte@example.test'));

            $this->expectException(\App\Exceptions\ApiException::class);
            app(AccessCodes::class)->admit($event, null, 2, 'nobody@example.test');
        });
    }

    #[Test]
    public function a_night_that_does_not_let_members_in_does_not_let_members_in(): void
    {
        $night = $this->makeSellableEvent();
        $scheme = $this->scheme($night['tenant'], ['presale' => true]);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $scheme) {
            app(Memberships::class)->grant($scheme, 'lotte@example.test');

            $night['event']->forceFill([
                'presale_starts_at' => now()->subDay(),
                'on_sale_at' => now()->addWeek(),
                // The switch is off: the promise is the venue's to make night by night.
                'member_presale' => false,
            ])->save();

            $this->expectException(\App\Exceptions\ApiException::class);
            app(AccessCodes::class)->admit($night['event']->fresh(), null, 2, 'lotte@example.test');
        });
    }

    /* --------------------------------------------------------------------------- reminders */

    #[Test]
    public function somebody_who_has_already_renewed_is_not_reminded(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant, ['months' => 12]);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            $ending = app(Memberships::class)->grant($scheme, 'lotte@example.test');
            $ending->forceFill(['ends_at' => now()->addDays(10)])->save();

            $this->assertCount(1, app(Memberships::class)->expiring(21));

            // They have paid for next year. Telling them to renew would be a reminder to do
            // something they have done.
            app(Memberships::class)->grant($scheme, 'lotte@example.test');

            $this->assertCount(0, app(Memberships::class)->expiring(21));
        });
    }

    #[Test]
    public function the_reminder_reaches_a_member_whose_year_is_nearly_up(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            $one = app(Memberships::class)->grant($scheme, 'lotte@example.test', 'Lotte');
            $one->forceFill(['ends_at' => now()->addDays(10)])->save();
        });

        $this->artisan('memberships:remind')->assertExitCode(0);

        app(TenantContext::class)->runAs($tenant, function () {
            $sent = \App\Models\MessageDelivery::where('kind', 'membership.expiring')->get();

            $this->assertCount(1, $sent);
            $this->assertSame('lotte@example.test', $sent[0]->recipient);
        });

        // And once, not once a day for three weeks.
        $this->artisan('memberships:remind')->assertExitCode(0);

        app(TenantContext::class)->runAs($tenant, function () {
            $this->assertSame(1, \App\Models\MessageDelivery::where('kind', 'membership.expiring')->count());
        });
    }

    /* ------------------------------------------------------- what a member is charged on the site */

    #[Test]
    public function a_signed_in_member_is_charged_the_member_price(): void
    {
        $night = $this->makeSellableEvent(amount: 5000);
        $this->makeSiteFor($night['tenant']);
        $scheme = $this->scheme($night['tenant'], ['discount_percent' => 20]);

        app(TenantContext::class)->runAs($night['tenant'], fn () => app(Memberships::class)
            ->grant($scheme, 'lotte@example.test', 'Lotte'));

        $seatIds = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => \App\Models\Seat::where('seat_map_id', $night['map']->id)->limit(2)->pluck('id')->all()
        );

        // Known by the address they are signed in as — not by what they type into the form. A
        // discount that appeared when somebody typed an address would be a summary that changes
        // under them; one that appeared only at the payment step would be a charge the page never
        // promised.
        $this->withSession(['seatmap_buyer' => ['email' => 'lotte@example.test']]);

        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $night['event']->public_id,
            'seat_ids' => $seatIds,
        ])->assertCreated();

        $this->get('http://northgate.test/checkout')
            ->assertOk()
            // Named, rather than shown as an anonymous reduction: somebody paying to be a Friend
            // should see the Friend.
            ->assertSee('Friends discount', false);

        $this->post('http://northgate.test/checkout', [
            'name' => 'Lotte',
            'email' => 'lotte@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $order = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => \App\Models\ExternalOrder::latest('created_at')->first()
        );

        // Two seats at 50.00, less a fifth.
        $this->assertSame(8000, (int) $order->total_amount);
        $this->assertSame(2000, (int) ($order->metadata['discount']['amount'] ?? 0));
    }

    #[Test]
    public function a_stranger_typing_a_members_address_is_not_that_member(): void
    {
        $night = $this->makeSellableEvent(amount: 5000);
        $this->makeSiteFor($night['tenant']);
        $scheme = $this->scheme($night['tenant'], ['discount_percent' => 20]);

        app(TenantContext::class)->runAs($night['tenant'], fn () => app(Memberships::class)
            ->grant($scheme, 'lotte@example.test'));

        $seatIds = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => \App\Models\Seat::where('seat_map_id', $night['map']->id)->limit(2)->pluck('id')->all()
        );

        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $night['event']->public_id,
            'seat_ids' => $seatIds,
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Not Lotte',
            'email' => 'lotte@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $order = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => \App\Models\ExternalOrder::latest('created_at')->first()
        );

        $this->assertSame(10000, (int) $order->total_amount);
    }

    /* ------------------------------------------------------------------------------ the screen */

    #[Test]
    public function the_screen_lists_schemes_and_the_people_on_them(): void
    {
        $tenant = $this->makeTenant();
        $scheme = $this->scheme($tenant, ['name' => 'Friends']);

        app(TenantContext::class)->runAs($tenant, function () use ($scheme) {
            app(Memberships::class)->grant($scheme, 'lotte@example.test', 'Lotte');
        });

        $user = $this->makeUser($tenant);

        $schemes = $this->actingAs($user)->getJson('/v1/memberships')->assertOk()->json('data');

        $this->assertCount(1, $schemes);
        $this->assertSame(1, $schemes[0]['members']);

        $people = $this->actingAs($user)
            ->getJson('/v1/memberships/members?state=current')->assertOk()->json();

        $this->assertSame(1, $people['meta']['total']);
        $this->assertSame('lotte@example.test', $people['data'][0]['email']);
    }

    #[Test]
    public function somebody_who_may_not_hand_out_credit_may_not_hand_out_membership(): void
    {
        $tenant = $this->makeTenant();

        // A door supervisor scans tickets and does not run the Friends scheme.
        $this->actingAs($this->makeUser($tenant, 'door'))
            ->getJson('/v1/memberships')->assertStatus(403);
    }

    #[Test]
    public function one_organiser_cannot_see_anothers_members(): void
    {
        $theirs = $this->makeTenant('Theirs');
        $scheme = $this->scheme($theirs);

        app(TenantContext::class)->runAs($theirs, fn () => app(Memberships::class)
            ->grant($scheme, 'lotte@example.test'));

        $mine = $this->makeTenant('Mine');

        $this->actingAs($this->makeUser($mine))
            ->getJson('/v1/memberships/members')->assertOk()->assertJsonPath('meta.total', 0);
    }

    /* ------------------------------------------------------------------------------ fixtures */

    private function makeSiteFor(Tenant $tenant, string $hostname = 'northgate.test'): \App\Models\Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $hostname) {
            $site = app(\App\Domain\Sites\SiteProvisioner::class)->create($tenant->name);

            \App\Models\SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'is_primary' => true,
                'verification_token' => \App\Models\SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live', 'locale' => 'en']);

            return $site->fresh();
        });
    }

    private function scheme(Tenant $tenant, array $overrides = [], bool $sellOnline = false): MembershipScheme
    {
        return app(TenantContext::class)->runAs($tenant, fn () => app(Memberships::class)->save(
            null,
            $overrides + [
                'name' => 'Friends',
                'description' => 'A bit off every seat.',
                'currency' => 'EUR',
                'price' => 3000,
                'months' => 12,
                'discount_percent' => 0,
                'presale' => false,
                'enabled' => true,
            ],
            $sellOnline
        ));
    }

    /** One booking, paid for, optionally carrying add-ons. */
    private function sell(array $night, array $addons = []): \App\Models\ExternalOrder
    {
        $ids = $night['seats']->slice($this->taken, 2)->pluck('id')->all();
        $this->taken += 2;

        $hold = app(HoldService::class)->create($night['event'], $ids, 'session-'.uniqid());
        $client = $this->makeApiClient($night['tenant'])['client'];

        [$order] = app(OrderService::class)->register(
            $client,
            'ORD-'.strtoupper(uniqid()),
            $hold->token,
            ['name' => 'Sam Buyer', 'email' => 'sam@example.test', 'phone' => '+44 20 7946 0000']
        );

        foreach ($addons as $addonId => $quantity) {
            $addon = Addon::find($addonId);

            OrderAddon::create([
                'tenant_id' => $order->tenant_id,
                'external_order_row_id' => $order->id,
                'addon_id' => $addon->id,
                'name' => $addon->name,
                'quantity' => $quantity,
                'unit_price' => $addon->price,
                'amount' => $addon->price * $quantity,
                'currency' => $addon->currency,
            ]);
        }

        return app(OrderService::class)->confirm($order->fresh());
    }
}
