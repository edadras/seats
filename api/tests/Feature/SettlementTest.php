<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The end-of-run arithmetic: what came in, what went back out, and what is owed.
 *
 * These check the two things a settlement can get wrong in a way nobody notices until a venue
 * disputes it: money going back out on a refund, and the platform's commission being taken from
 * money that was never the organiser's. Every figure is compared against what the order actually
 * froze at the moment it was paid rather than recomputed from the event, because that is the whole
 * point of the screen.
 */
class SettlementTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function it_reads_back_what_each_booking_froze(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, [
            'booking_fee_kind' => 'per_order', 'booking_fee_amount' => 500,
            'tax_rate' => 2000, 'tax_included' => false,
        ]);
        $this->commission($fixture['tenant'], 250);

        $this->buy($fixture, [0, 1]);

        $row = $this->settlement($fixture)['rows'][0];

        // Two seats at €100, a €5 fee, 20% on top of both.
        $this->assertSame(20000, $row['tickets']);
        $this->assertSame(500, $row['fee']);
        $this->assertSame(4100, $row['tax']);
        $this->assertSame(24600, $row['charged']);
        $this->assertSame(0, $row['refunded']);
        $this->assertSame(2, $row['seats']);

        // 2.5% of what is kept once the tax is set aside: 24600 − 4100 = 20500, so 512.5 → 513.
        $this->assertSame(513, $row['commission']);
        $this->assertSame(24600 - 513, $row['payable']);
    }

    #[Test]
    public function a_full_refund_takes_the_fee_and_the_tax_back_out_with_it(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, [
            'booking_fee_kind' => 'per_order', 'booking_fee_amount' => 500,
            'tax_rate' => 2000, 'tax_included' => false,
        ]);
        $this->commission($fixture['tenant'], 250);

        $this->buy($fixture, [0, 1]);
        $this->refund($fixture);

        $row = $this->settlement($fixture)['rows'][0];

        // The buyer got everything back, so there is nothing to keep and nothing to charge on.
        $this->assertSame(24600, $row['charged']);
        $this->assertSame(24600, $row['refunded']);
        $this->assertSame(0, $row['kept']);
        $this->assertSame(0, $row['commission']);
        $this->assertSame(0, $row['payable']);
        $this->assertSame(2, $row['seats_refunded']);
        $this->assertSame(0, $row['seats']);
    }

    #[Test]
    public function a_part_refund_returns_the_seats_named_and_not_the_fee(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, ['booking_fee_kind' => 'per_order', 'booking_fee_amount' => 500]);

        $this->buy($fixture, [0, 1]);
        $this->refund($fixture, [$fixture['seats'][0]->id]);

        $row = $this->settlement($fixture)['rows'][0];

        // €200 of seats plus a €5 fee; one seat back is €100, and whether the fee survives a part
        // refund is the organiser's refund policy, not something the platform decides for them.
        $this->assertSame(20500, $row['charged']);
        $this->assertSame(10000, $row['refunded']);
        $this->assertSame(10500, $row['kept']);
        $this->assertSame(1, $row['seats']);
        $this->assertSame(1, $row['seats_refunded']);
    }

    #[Test]
    public function commission_is_never_charged_on_tax(): void
    {
        $fixture = $this->makeSellableEvent(amount: 11900);
        $this->makeSite($fixture['tenant']);
        $this->settings($fixture, ['tax_rate' => 1900, 'tax_included' => true]);
        $this->commission($fixture['tenant'], 1000);

        $this->buy($fixture, [0]);

        $row = $this->settlement($fixture)['rows'][0];

        // €119 with 19% inside it: €19 is the state's, so 10% is charged on €100, not on €119.
        $this->assertSame(11900, $row['charged']);
        $this->assertSame(1900, $row['tax_kept']);
        $this->assertSame(1000, $row['commission']);
    }

    #[Test]
    public function a_plan_with_no_commission_settles_the_whole_amount(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, [0]);

        $settlement = $this->settlement($fixture);

        $this->assertSame(0, $settlement['commission_rate']);
        $this->assertSame(0, $settlement['rows'][0]['commission']);
        $this->assertSame(5000, $settlement['rows'][0]['payable']);
    }

    #[Test]
    public function currencies_are_totalled_apart(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        // A second event in the same account, sold in another currency. Its takings must appear
        // beside the first, never added to them.
        $second = $this->makeSellableEvent($fixture['tenant'], amount: 7000);
        $this->settings($second, ['currency' => 'USD']);
        $this->buy($second, [0]);

        $totals = collect($this->settlement($fixture)['totals'])->keyBy('currency');

        $this->assertSame(['EUR', 'USD'], $totals->keys()->sort()->values()->all());
        $this->assertSame(5000, $totals['EUR']['charged']);
        $this->assertSame(7000, $totals['USD']['charged']);
    }

    #[Test]
    public function a_period_can_be_cut_by_payment_or_by_event_date(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $owner = $this->makeUser($fixture['tenant']);
        $today = now()->toDateString();
        $eventDay = $fixture['event']->starts_at->toDateString();

        // Paid today, for an event next week: counting by payment finds it today and counting by
        // the night it is for does not.
        $this->assertCount(1, $this->actingAs($owner)
            ->getJson('/v1/settlement?basis=paid&from='.$today.'&to='.$today)
            ->assertOk()->json('rows'));

        $this->assertCount(0, $this->actingAs($owner)
            ->getJson('/v1/settlement?basis=event&from='.$today.'&to='.$today)
            ->assertOk()->json('rows'));

        $this->assertCount(1, $this->actingAs($owner)
            ->getJson('/v1/settlement?basis=event&from='.$eventDay.'&to='.$eventDay)
            ->assertOk()->json('rows'));
    }

    #[Test]
    public function it_is_behind_the_permission_that_shows_money(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        // The door has `checkins.view` and nothing about the takings.
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)->getJson('/v1/settlement')->assertForbidden();
        $this->actingAs($doorman)->getJson('/v1/settlement/export')->assertForbidden();
        $this->actingAs($doorman)->getJson('/v1/settlement/statement')->assertForbidden();
    }

    #[Test]
    public function another_account_sees_none_of_it(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $stranger = $this->makeUser($this->makeTenant('Someone Else'));

        $this->assertSame([], $this->actingAs($stranger)
            ->getJson('/v1/settlement')->assertOk()->json('rows'));
    }

    #[Test]
    public function the_export_is_a_csv_a_book_keeper_can_add_up(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $owner = $this->makeUser($fixture['tenant']);

        $csv = $this->actingAs($owner)->get('/v1/settlement/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        // Decimal amounts with a full stop, not minor units and not the reader's numerals: this
        // file is opened by accounting software.
        $this->assertStringContainsString('100.00', $csv);
        $this->assertStringContainsString('Opening night', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    #[Test]
    public function the_statement_is_a_pdf(): void
    {
        $fixture = $this->makeSellableEvent(amount: 10000);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $owner = $this->makeUser($fixture['tenant']);

        $response = $this->actingAs($owner)->get('/v1/settlement/statement')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function settlement(array $fixture): array
    {
        return $this->actingAs($this->makeUser($fixture['tenant']))
            ->getJson('/v1/settlement')
            ->assertOk()
            ->json();
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

    /** @param  ?list<string>  $seatIds  null refunds the whole booking */
    private function refund(array $fixture, ?array $seatIds = null): void
    {
        $owner = $this->makeUser($fixture['tenant']);

        $order = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::orderByDesc('created_at')->firstOrFail()
        );

        $this->actingAs($owner)
            ->postJson("/v1/orders/{$order->id}/refund", $seatIds ? ['seat_ids' => $seatIds] : [])
            ->assertOk();
    }

    private function commission($tenant, int $basisPoints): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($basisPoints) {
            $subscription = Subscription::orderByDesc('created_at')->firstOrFail();

            Plan::whereKey($subscription->plan_id)->update(['commission_rate' => $basisPoints]);
        });
    }

    private function settings(array $fixture, array $attributes): void
    {
        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $fixture['event']->forceFill($attributes)->save()
        );
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
