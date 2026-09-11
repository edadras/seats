<?php

namespace Tests\Feature;

use App\Domain\Loyalty\Loyalty;
use App\Domain\Sites\SiteProvisioner;
use App\Domain\Vouchers\Vouchers;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgramme;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Voucher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Points earned by coming, and what they are worth.
 *
 * The claims that matter are the ones about undoing. A scheme that gives points is easy; a scheme
 * that takes the right number back when half a booking is refunded, when a booking is cancelled,
 * when a card is charged back months later — and that cannot be made to give them twice — is the
 * whole of the work. `settle()` is one method for all of it, so these are really one claim tested
 * from several directions.
 */
class LoyaltyTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------ earning */

    #[Test]
    public function coming_earns_points_and_the_ledger_says_why(): void
    {
        $fixture = $this->scheme();
        $this->buy($fixture, [0, 1]);

        // Two seats at 25.00 is 50 whole euros, at two points each.
        $this->assertSame(100, $this->points($fixture, 'dana@example.test'));

        $this->inTenant($fixture, function () {
            $movement = LoyaltyMovement::firstOrFail();

            $this->assertSame('earn', $movement->kind);
            $this->assertNotNull($movement->external_order_row_id, 'The evening it came from.');
        });
    }

    #[Test]
    public function settling_twice_gives_nothing_twice(): void
    {
        $fixture = $this->scheme();
        $this->buy($fixture, [0]);

        $order = $this->order($fixture);

        // `settle()` reads what the booking is worth now and writes the difference, so calling it
        // again — a retried webhook, a second confirm — writes nothing at all.
        $this->assertSame(0, $this->inTenant($fixture, fn () => app(Loyalty::class)->settle($order)));
        $this->assertSame(50, $this->points($fixture, 'dana@example.test'));
        $this->assertSame(1, $this->inTenant($fixture, fn () => LoyaltyMovement::count()));
    }

    #[Test]
    public function a_comp_earns_nothing(): void
    {
        $fixture = $this->scheme();
        $this->buy($fixture, [0]);

        $order = $this->order($fixture);

        $this->inTenant($fixture, function () use ($order) {
            $order->forceFill(['metadata' => ($order->metadata ?? []) + ['payment' => 'comp']])->save();

            app(Loyalty::class)->settle($order->fresh());
        });

        // Paying somebody points for being given a seat is a scheme that rewards the box office's
        // generosity rather than the audience's custom.
        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
    }

    #[Test]
    public function takings_in_another_currency_do_not_earn(): void
    {
        $fixture = $this->scheme(currency: 'GBP');
        $this->buy($fixture, [0]);

        // A single pool fed by two currencies is arithmetic nobody can explain at a counter.
        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
    }

    #[Test]
    public function nothing_earns_while_the_scheme_is_off(): void
    {
        $fixture = $this->scheme(enabled: false);
        $this->buy($fixture, [0]);

        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
    }

    /* ------------------------------------------------------------------ and undoing */

    #[Test]
    public function half_a_refund_takes_half_the_points(): void
    {
        $fixture = $this->scheme();
        $owner = $this->makeUser($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $this->assertSame(100, $this->points($fixture, 'dana@example.test'));

        $seat = $this->inTenant($fixture, fn () => Allocation::where('status', 'active')->firstOrFail());

        $this->actingAs($owner)->postJson('/v1/orders/'.$this->order($fixture)->id.'/refund', [
            'seat_ids' => [$seat->seat_id],
        ])->assertOk();

        $this->assertSame(50, $this->points($fixture, 'dana@example.test'));

        // A negative earn rather than a deletion, so the history still says somebody came and then
        // gave a seat back.
        $this->assertSame(1, $this->inTenant(
            $fixture,
            fn () => LoyaltyMovement::where('kind', 'reverse')->count()
        ));
    }

    #[Test]
    public function a_booking_that_never_happened_is_not_an_evening_somebody_came_to(): void
    {
        $fixture = $this->scheme();
        $owner = $this->makeUser($fixture['tenant']);
        $this->buy($fixture, [0]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$this->order($fixture)->id.'/refund', [])
            ->assertOk();

        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
    }

    #[Test]
    public function a_chargeback_takes_the_points_with_the_money(): void
    {
        $fixture = $this->scheme();
        $owner = $this->makeUser($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $this->actingAs($owner)->postJson('/v1/orders/'.$this->order($fixture)->id.'/chargeback', [
            'reason' => 'Disputed',
        ])->assertOk();

        // Not a refund — the organiser did not choose it — but the same fact about the evening:
        // nobody paid for it in the end.
        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
    }

    /* ------------------------------------------------------------------ standing */

    #[Test]
    public function a_tier_is_the_highest_rung_the_window_reaches(): void
    {
        $fixture = $this->scheme(tiers: [
            ['key' => 'friend', 'name' => 'Friend', 'from_points' => 50],
            ['key' => 'patron', 'name' => 'Patron', 'from_points' => 200],
        ]);

        $this->buy($fixture, [0]);

        $standing = $this->inTenant($fixture, fn () => app(Loyalty::class)->standing('dana@example.test'));

        $this->assertSame('friend', $standing['key']);
        $this->assertSame(150, $standing['next']['needs'], 'And what is left to the next one.');
    }

    #[Test]
    public function spending_points_does_not_cost_somebody_their_standing(): void
    {
        $fixture = $this->scheme(tiers: [['key' => 'friend', 'name' => 'Friend', 'from_points' => 50]]);
        $this->buy($fixture, [0, 1]);

        $this->inTenant($fixture, fn () => app(Loyalty::class)->redeem('dana@example.test', 100));

        // Asking somebody to choose between the reward and the tier it came with is a scheme
        // nobody uses twice.
        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
        $this->assertSame('friend', $this->inTenant(
            $fixture,
            fn () => app(Loyalty::class)->standing('dana@example.test')['key']
        ));
    }

    #[Test]
    public function points_earned_before_the_window_do_not_hold_a_tier_up(): void
    {
        $fixture = $this->scheme(tiers: [['key' => 'friend', 'name' => 'Friend', 'from_points' => 50]]);
        $this->buy($fixture, [0, 1]);

        $this->inTenant($fixture, fn () => LoyaltyMovement::query()
            ->update(['created_at' => now()->subMonths(18)]));

        // A standing that only ever ratchets up is a label, not a standing.
        $this->assertNull($this->inTenant(
            $fixture,
            fn () => app(Loyalty::class)->standing('dana@example.test')['key']
        ));
    }

    /* ------------------------------------------------------------------ what they buy */

    #[Test]
    public function points_turn_into_credit_the_checkout_already_knows_how_to_spend(): void
    {
        $fixture = $this->scheme();
        $this->buy($fixture, [0, 1]);

        $voucher = $this->inTenant($fixture, fn () => app(Loyalty::class)->redeem('dana@example.test', 100));

        $this->assertSame('credit', $voucher->kind);
        $this->assertSame('dana@example.test', $voucher->email);
        // A hundred points at a hundred to the euro is one euro, in minor units.
        $this->assertSame(100, (int) $voucher->amount);
        $this->assertSame(100, $this->inTenant($fixture, fn () => app(Vouchers::class)->balance($voucher)));
        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
    }

    #[Test]
    public function the_remainder_stays_theirs(): void
    {
        $fixture = $this->scheme();
        $this->buy($fixture, [0, 1]);

        $this->inTenant($fixture, fn () => app(Loyalty::class)->adjust('dana@example.test', 50, 'goodwill'));

        // 150 points at a hundred to the euro is one euro and fifty points left over. A scheme
        // that swallowed the fifty is a scheme people distrust.
        $voucher = $this->inTenant($fixture, fn () => app(Loyalty::class)->redeem('dana@example.test', 150));

        $this->assertSame(100, (int) $voucher->amount);
        $this->assertSame(50, $this->points($fixture, 'dana@example.test'));
    }

    #[Test]
    public function nobody_turns_in_points_they_do_not_have(): void
    {
        $fixture = $this->scheme();
        $this->buy($fixture, [0]);

        $this->inTenant($fixture, function () {
            try {
                app(Loyalty::class)->redeem('dana@example.test', 5000);
                $this->fail('A balance that could go negative is a balance nobody can trust.');
            } catch (\App\Exceptions\ApiException $e) {
                $this->assertSame('loyalty_not_enough', $e->errorCode());
            }

            $this->assertSame(0, Voucher::count());
        });
    }

    #[Test]
    public function a_buyer_turns_their_own_points_in_from_their_own_page(): void
    {
        $fixture = $this->scheme();
        $this->buy($fixture, [0, 1]);

        // Signed in as themselves — the session this server wrote when they bought.
        $this->withSession(['seatmap_buyer' => ['email' => 'dana@example.test', 'name' => 'Dana']])
            ->get('http://northgate.test/account')
            ->assertOk()
            ->assertSee('100');

        $this->withSession(['seatmap_buyer' => ['email' => 'dana@example.test', 'name' => 'Dana']])
            ->post('http://northgate.test/account/points', ['points' => 100])
            ->assertRedirect('/account');

        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
        $this->assertSame(1, $this->inTenant($fixture, fn () => Voucher::where('kind', 'credit')->count()));
    }

    /* ------------------------------------------------------------------ what a tier opens */

    #[Test]
    public function a_tier_walks_past_the_presale_queue(): void
    {
        $fixture = $this->scheme(tiers: [['key' => 'friend', 'name' => 'Friend', 'from_points' => 50]]);
        $this->buy($fixture, [0, 1]);

        // The night goes into presale, and lets friends of the house in early.
        $this->inTenant($fixture, fn () => Event::whereKey($fixture['event']->id)->update([
            'presale_starts_at' => now()->subDay(),
            'on_sale_at' => now()->addWeek(),
            'tier_presale' => 'friend',
        ]));

        $this->flushSession();

        // A stranger is asked for a code, as they always were.
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][4]->id],
        ])->assertStatus(409)->assertJsonPath('error.code', 'access_code_required');

        // Somebody signed in whose standing reaches the rung is let through without one — and does
        // not spend a code's last use, because there is no code.
        $this->withSession(['seatmap_buyer' => ['email' => 'dana@example.test', 'name' => 'Dana']])
            ->postJson('http://northgate.test/_store/hold', [
                'event_public_id' => $fixture['event']->public_id,
                'seat_ids' => [$fixture['seats'][4]->id],
            ])->assertCreated();
    }

    #[Test]
    public function a_standing_too_low_is_still_asked_for_a_code(): void
    {
        $fixture = $this->scheme(tiers: [['key' => 'patron', 'name' => 'Patron', 'from_points' => 5000]]);
        $this->buy($fixture, [0]);

        $this->inTenant($fixture, fn () => Event::whereKey($fixture['event']->id)->update([
            'presale_starts_at' => now()->subDay(),
            'on_sale_at' => now()->addWeek(),
            'tier_presale' => 'patron',
        ]));

        $this->flushSession();

        $this->withSession(['seatmap_buyer' => ['email' => 'dana@example.test', 'name' => 'Dana']])
            ->postJson('http://northgate.test/_store/hold', [
                'event_public_id' => $fixture['event']->public_id,
                'seat_ids' => [$fixture['seats'][4]->id],
            ])->assertStatus(409)->assertJsonPath('error.code', 'access_code_required');
    }

    /* ------------------------------------------------------------------ the organiser's side */

    #[Test]
    public function the_scheme_is_set_up_and_read_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $body = $this->actingAs($owner)->putJson('/v1/loyalty', [
            'name' => 'Friends of the Northgate',
            'enabled' => true,
            'currency' => 'eur',
            'earn_rate' => 2,
            'points_per_unit' => 100,
            'min_redeem' => 100,
            'window_months' => 12,
            'inactive_months' => 24,
            'tiers' => [
                ['key' => 'patron', 'name' => 'Patron', 'from_points' => 500],
                ['key' => 'friend', 'name' => 'Friend', 'from_points' => 50],
            ],
        ])->assertOk()->json();

        $this->assertSame('EUR', $body['currency']);
        // Sorted on the way out: the order decides which tier somebody is in, and a list saved out
        // of order by a screen that meant no harm would hand everybody the wrong standing.
        $this->assertSame(['friend', 'patron'], array_column($body['tiers'], 'key'));
        $this->assertSame(100, $body['unit'], 'What one whole euro is, in minor units.');
    }

    #[Test]
    public function the_organiser_sees_who_has_points_and_can_put_some_right(): void
    {
        $fixture = $this->scheme();
        $owner = $this->makeUser($fixture['tenant']);
        $this->buy($fixture, [0]);

        $members = $this->actingAs($owner)->getJson('/v1/loyalty/members')->assertOk()->json();

        $this->assertSame(1, $members['total']);
        $this->assertSame('dana@example.test', $members['data'][0]['email']);
        $this->assertSame(50, $members['data'][0]['balance']);

        $this->actingAs($owner)->postJson('/v1/loyalty/adjust', [
            'email' => 'dana@example.test',
            'points' => 25,
            'why' => 'Sat through the interval twice.',
        ])->assertOk()->assertJsonPath('balance', 75);

        // Every scheme's worst day is the one where somebody did this without saying why.
        $this->assertSame('Sat through the interval twice.', $this->inTenant(
            $fixture,
            fn () => LoyaltyMovement::where('kind', 'adjust')->value('note')
        ));
    }

    #[Test]
    public function only_somebody_who_may_hand_out_credit_may_set_up_a_scheme(): void
    {
        $fixture = $this->makeSellableEvent();
        // A doorkeeper: the box office may hand out gift cards, so it may set up the machine
        // that hands them out too — the refusal to test is somebody who may do neither.
        $clerk = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($clerk)->getJson('/v1/loyalty')->assertForbidden();
        $this->actingAs($clerk)->putJson('/v1/loyalty', [])->assertForbidden();
        $this->actingAs($clerk)->getJson('/v1/loyalty/members')->assertForbidden();
        $this->actingAs($clerk)->postJson('/v1/loyalty/adjust', [])->assertForbidden();
    }

    #[Test]
    public function points_that_have_gone_quiet_go(): void
    {
        $fixture = $this->scheme(quietMonths: 12);
        $this->buy($fixture, [0]);

        $this->artisan('loyalty:expire')->assertSuccessful();
        $this->assertSame(50, $this->points($fixture, 'dana@example.test'), 'Nothing has gone quiet.');

        $this->inTenant($fixture, fn () => LoyaltyMovement::query()
            ->update(['created_at' => now()->subMonths(18)]));

        $this->artisan('loyalty:expire')->assertSuccessful();

        $this->assertSame(0, $this->points($fixture, 'dana@example.test'));
        $this->assertSame(1, $this->inTenant(
            $fixture,
            fn () => LoyaltyMovement::where('kind', 'expire')->count()
        ));
    }

    #[Test]
    public function a_scheme_that_promises_they_never_expire_keeps_that_promise(): void
    {
        $fixture = $this->scheme(quietMonths: null);
        $this->buy($fixture, [0]);

        $this->inTenant($fixture, fn () => LoyaltyMovement::query()
            ->update(['created_at' => now()->subYears(5)]));

        $this->artisan('loyalty:expire')->assertSuccessful();

        $this->assertSame(50, $this->points($fixture, 'dana@example.test'));
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** A sellable night on a live site, with a scheme running over it. */
    private function scheme(
        bool $enabled = true,
        string $currency = 'EUR',
        ?array $tiers = null,
        ?int $quietMonths = null,
    ): array {
        $fixture = $this->makeSellableEvent(amount: 2500);
        $this->makeSite($fixture['tenant']);

        $this->inTenant($fixture, fn () => LoyaltyProgramme::create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Friends of the Northgate',
            'enabled' => $enabled,
            'currency' => $currency,
            'earn_rate' => 2,
            'points_per_unit' => 100,
            'min_redeem' => 50,
            'window_months' => 12,
            'inactive_months' => $quietMonths,
            'tiers' => $tiers ?? [],
        ]));

        return $fixture;
    }

    /** @param  list<int>  $seats */
    private function buy(array $fixture, array $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Dana Scully',
            'email' => 'dana@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $this->flushSession();
    }

    private function points(array $fixture, string $email): int
    {
        return $this->inTenant($fixture, fn () => app(Loyalty::class)->balance($email));
    }

    private function order(array $fixture): ExternalOrder
    {
        return $this->inTenant($fixture, fn () => ExternalOrder::orderByDesc('created_at')->firstOrFail());
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
