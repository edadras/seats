<?php

namespace Tests\Feature;

use App\Domain\BoxOffice\Tills;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Shift;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Is the money in the drawer the money that should be in the drawer?
 *
 * Every venue asks it at eleven o'clock, and until now this platform had no way to answer. The
 * tests below pin the arithmetic — only cash counts, a card never touched the drawer, movements
 * that are not sales are part of the total — and the two rules that make a reconciliation worth
 * anything: one open till per person, and a discrepancy that cannot be edited afterwards.
 */
class TillTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_till_starts_with_what_is_in_the_drawer(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $shift = $this->actingAs($owner)->postJson('/v1/shifts', [
            'currency' => 'EUR',
            'opening_float' => 10000,
        ])->assertCreated()->json();

        $this->assertTrue($shift['open']);
        $this->assertSame(10000, $shift['opening_float']);
        $this->assertSame(10000, $shift['expected_cash']);
        // Nobody has counted it, so there is no discrepancy — and a zero there would read as
        // "it balanced".
        $this->assertNull($shift['counted_cash']);
        $this->assertNull($shift['difference']);
    }

    #[Test]
    public function one_person_may_only_have_one_till_open(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/shifts', ['currency' => 'EUR'])->assertCreated();

        // What a double-clicked button does. Two open shifts would divide one evening's takings
        // between them at random.
        $this->actingAs($owner)->postJson('/v1/shifts', ['currency' => 'EUR'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'till_already_open');

        // And somebody else's till is their own business.
        $this->actingAs($this->makeUser($fixture['tenant'], 'box_office'))
            ->postJson('/v1/shifts', ['currency' => 'EUR'])->assertCreated();
    }

    #[Test]
    public function only_cash_reaches_the_drawer(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/shifts', [
            'currency' => 'EUR', 'opening_float' => 5000,
        ])->assertCreated();

        $this->sell($fixture, $owner, 0, 'cash');
        $this->sell($fixture, $owner, 1, 'card');

        $till = $this->actingAs($owner)->getJson('/v1/shifts/current')->assertOk()->json('data');

        $this->assertSame(2500, $till['takings']['cash']);
        $this->assertSame(2500, $till['takings']['card']);
        // The card sale is money that never touched the drawer. Counting it would report every
        // honest evening several hundred short.
        $this->assertSame(7500, $till['expected_cash']);
    }

    #[Test]
    public function a_comp_takes_nothing_and_an_invoice_takes_nothing_yet(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/shifts', ['currency' => 'EUR'])->assertCreated();

        $this->sell($fixture, $owner, 0, null, 'comp');
        $this->sell($fixture, $owner, 1, null, 'owed');

        $till = $this->actingAs($owner)->getJson('/v1/shifts/current')->assertOk()->json('data');

        $this->assertSame(0, $till['expected_cash']);
        $this->assertSame(1, $till['takings']['comp']);
        // Owed is money the school will send, and it is worth seeing on the shift — but it is not
        // in the drawer tonight.
        $this->assertSame(2500, $till['takings']['owed']);
    }

    #[Test]
    public function money_moves_for_reasons_that_are_not_sales(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $shift = $this->actingAs($owner)->postJson('/v1/shifts', [
            'currency' => 'EUR', 'opening_float' => 5000,
        ])->assertCreated()->json();

        // A taxi out of the drawer, and twenty put in to make change.
        $this->actingAs($owner)->postJson('/v1/shifts/'.$shift['id'].'/movements', [
            'kind' => 'out', 'amount' => 1800, 'reason' => 'Taxi for the sound engineer',
        ])->assertCreated();

        $after = $this->actingAs($owner)->postJson('/v1/shifts/'.$shift['id'].'/movements', [
            'kind' => 'in', 'amount' => 2000, 'reason' => 'Change from the safe',
        ])->assertCreated()->json();

        $this->assertSame(5200, $after['expected_cash']);
        $this->assertSame(2000, $after['movements']['in']);
        $this->assertSame(1800, $after['movements']['out']);

        // A movement without a reason is the thing an audit asks about first.
        $this->actingAs($owner)->postJson('/v1/shifts/'.$shift['id'].'/movements', [
            'kind' => 'out', 'amount' => 500,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_refund_comes_back_out_of_the_drawer_it_went_into(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/shifts', ['currency' => 'EUR'])->assertCreated();

        $sale = $this->sell($fixture, $owner, 0, 'cash');

        $this->actingAs($owner)
            ->postJson('/v1/orders/'.$sale['id'].'/refund', ['reason' => 'changed their mind'])
            ->assertOk();

        $till = $this->actingAs($owner)->getJson('/v1/shifts/current')->assertOk()->json('data');

        $this->assertSame(2500, $till['takings']['cash']);
        $this->assertSame(2500, $till['takings']['cash_refunded']);
        $this->assertSame(0, $till['expected_cash']);
    }

    #[Test]
    public function counting_the_drawer_is_a_photograph_and_not_a_live_view(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $shift = $this->actingAs($owner)->postJson('/v1/shifts', [
            'currency' => 'EUR', 'opening_float' => 5000,
        ])->assertCreated()->json();

        $sale = $this->sell($fixture, $owner, 0, 'cash');

        // Four euros short, which is the finding rather than a mistake to be corrected.
        $closed = $this->actingAs($owner)->postJson('/v1/shifts/'.$shift['id'].'/close', [
            'counted_cash' => 7100,
            'note' => 'Counted twice.',
        ])->assertOk()->json();

        $this->assertSame(7500, $closed['expected_cash']);
        $this->assertSame(7100, $closed['counted_cash']);
        $this->assertSame(-400, $closed['difference']);
        $this->assertFalse($closed['open']);

        // The next morning, a refund. Last night's discrepancy is last night's: it must not be
        // rewritten into agreement by something that happened after the drawer was counted.
        $this->actingAs($owner)
            ->postJson('/v1/orders/'.$sale['id'].'/refund', ['reason' => 'next morning'])
            ->assertOk();

        $again = $this->actingAs($owner)->getJson('/v1/shifts/'.$shift['id'])->assertOk()->json();

        $this->assertSame(7500, $again['expected_cash']);
        $this->assertSame(-400, $again['difference']);
    }

    #[Test]
    public function a_closed_till_cannot_be_reopened_or_recounted(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $shift = $this->actingAs($owner)->postJson('/v1/shifts', ['currency' => 'EUR'])
            ->assertCreated()->json();

        $this->actingAs($owner)->postJson('/v1/shifts/'.$shift['id'].'/close', [
            'counted_cash' => 0,
        ])->assertOk();

        $this->actingAs($owner)->postJson('/v1/shifts/'.$shift['id'].'/close', [
            'counted_cash' => 9999,
        ])->assertStatus(409)->assertJsonPath('error.code', 'till_closed');

        $this->actingAs($owner)->postJson('/v1/shifts/'.$shift['id'].'/movements', [
            'kind' => 'in', 'amount' => 100, 'reason' => 'after the fact',
        ])->assertStatus(409);

        // Closing it frees the person to open the next one.
        $this->actingAs($owner)->postJson('/v1/shifts', ['currency' => 'EUR'])->assertCreated();
    }

    #[Test]
    public function a_clerk_sees_their_own_evening_and_a_manager_sees_everybody_s(): void
    {
        $fixture = $this->makeSellableEvent();

        /*
         * A role that can work a window and read no reports.
         *
         * The six built-in roles all hand `reports.orders.view` to anybody who can sell, so the
         * narrowing has to be tested against a role an organiser invented — which is exactly the
         * venue this matters at: the one where the ushers sell in the interval.
         */
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => \App\Models\TenantRole::create([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'usher',
            'name' => 'Usher',
            'permissions' => ['orders.sell'],
        ]));

        $clerk = $this->makeUser($fixture['tenant'], 'usher');
        $other = $this->makeUser($fixture['tenant'], 'usher');
        $manager = $this->makeUser($fixture['tenant'], 'manager');

        $mine = $this->actingAs($clerk)->postJson('/v1/shifts', ['currency' => 'EUR'])
            ->assertCreated()->json();
        $theirs = $this->actingAs($other)->postJson('/v1/shifts', ['currency' => 'EUR'])
            ->assertCreated()->json();

        // A clerk holds `orders.sell` and not `reports.orders.view`: their own, and no more.
        $seen = $this->actingAs($clerk)->getJson('/v1/shifts')->assertOk()->json('data');
        $this->assertSame([$mine['id']], array_column($seen, 'id'));

        $all = $this->actingAs($manager)->getJson('/v1/shifts')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$mine['id'], $theirs['id']], array_column($all, 'id'));

        // Reading every clerk's evening is a management report. Reaching into their drawer is not
        // the same act, and the report permission is not a way round it.
        $this->actingAs($manager)->postJson('/v1/shifts/'.$mine['id'].'/movements', [
            'kind' => 'out', 'amount' => 100, 'reason' => 'borrowed',
        ])->assertStatus(403)->assertJsonPath('error.code', 'not_your_till');
    }

    #[Test]
    public function another_account_s_till_is_not_one_this_account_can_open(): void
    {
        $mine = $this->makeSellableEvent($this->makeTenant('Mine'));
        $theirs = $this->makeSellableEvent($this->makeTenant('Theirs'));

        $id = $this->actingAs($this->makeUser($theirs['tenant']))
            ->postJson('/v1/shifts', ['currency' => 'EUR'])->assertCreated()->json('id');

        $this->actingAs($this->makeUser($mine['tenant']))
            ->getJson('/v1/shifts/'.$id)->assertStatus(404);
    }

    #[Test]
    public function a_sale_with_no_till_open_is_still_a_sale(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        // A venue that never counts a drawer should not be stopped from selling.
        $sale = $this->sell($fixture, $owner, 0, 'cash');

        $this->assertNotEmpty($sale['reference']);
        $this->assertNull($sale['shift_id']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertNull(ExternalOrder::firstOrFail()->shift_id);
            $this->assertSame(0, Shift::count());
        });
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function sell(array $fixture, $user, int $seat, ?string $method, string $payment = 'paid'): array
    {
        return $this->actingAs($user)->postJson('/v1/events/'.$fixture['event']->id.'/sell', array_filter([
            'seat_ids' => [$fixture['seats'][$seat]->id],
            'buyer' => ['name' => 'At the window'],
            'payment' => $payment,
            'method' => $method,
        ], fn ($value) => null !== $value))->assertCreated()->json();
    }
}
