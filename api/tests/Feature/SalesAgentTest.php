<?php

namespace Tests\Feature;

use App\Domain\Agents\SalesAgents;
use App\Domain\Orders\OrderService;
use App\Models\AgentCreditEntry;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\SalesAgent;
use App\Models\TenantUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The shops and bureaux that sell an organiser's tickets over their own counter.
 *
 * Three claims, and they are the whole feature.
 *
 * **An agent sells what they were given.** Not "events" as a permission — a bureau is handed the
 * summer festival and not the members' evening, and the refusal happens before a seat is held.
 *
 * **Credit is money that moved; everything else is counted.** Only a payment in, a settlement out
 * and an adjustment somebody signed are written down. What has been sold, refunded and earned is
 * read from the allocations, so a refund hands the credit straight back without anything having to
 * remember to.
 *
 * **An agent sees their own bookings.** `orders.view` lets somebody look up a booking they took; it
 * is not a licence to read the customers of the bureau across town.
 */
class SalesAgentTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** An agent, the person who signs in as them, and their allowance. */
    private function agentFor(array $night, array $attributes = [], array $events = []): array
    {
        $user = $this->makeUser($night['tenant'], 'agent');

        $agent = app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $user, $attributes, $events) {
            // `$attributes` first: PHP's `+` keeps the left-hand key, so defaults on the left
            // would quietly win over what the test asked for.
            $agent = SalesAgent::create($attributes + [
                'tenant_id' => $night['tenant']->id,
                'name' => 'Bureau 12',
                'code' => 'bureau-12',
                'user_id' => $user->id,
                'commission_rate' => 1000,
                'credit_limit' => 0,
                'active' => true,
            ]);

            app(SalesAgents::class)->allow(
                $agent,
                $events ?: [$night['event']->id],
                (bool) ($attributes['all_events'] ?? false),
            );

            return $agent->fresh();
        });

        return ['agent' => $agent, 'user' => $user];
    }

    /** @return array<string, mixed> */
    private function sale(array $night, array $who, int $seats = 2): \Illuminate\Testing\TestResponse
    {
        $ids = $night['seats']->slice(0, $seats)->pluck('id')->all();

        return $this->asMember($who['user'])->postJson("/v1/events/{$night['event']->id}/sell", [
            'seat_ids' => $ids,
            'buyer' => ['name' => 'Walk-up buyer', 'email' => 'walkup@example.test'],
            'payment' => 'paid',
            'method' => 'cash',
        ]);
    }

    #[Test]
    public function an_agent_sells_what_they_were_given_and_nothing_else(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $other = $this->makeSellableEvent(tenant: $night['tenant'], rows: 2, perRow: 4, amount: 2500);
        $who = $this->agentFor($night, ['credit_limit' => 100000]);

        // The night they were given.
        $this->asMember($who['user'])->getJson("/v1/events/{$night['event']->id}/counter")->assertOk();

        // And the one they were not, refused before anybody chooses a seat.
        $this->asMember($who['user'])->getJson("/v1/events/{$other['event']->id}/counter")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'agent_event_not_allowed');

        $this->asMember($who['user'])->postJson("/v1/events/{$other['event']->id}/sell", [
            'seat_ids' => $other['seats']->take(1)->pluck('id')->all(),
            'buyer' => ['name' => 'Walk-up buyer'],
            'payment' => 'paid',
            'method' => 'cash',
        ])->assertForbidden()->assertJsonPath('error.code', 'agent_event_not_allowed');

        // Their programme is their allowance, so they are not offered it either.
        $this->asMember($who['user'])->getJson('/v1/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $night['event']->id);
    }

    #[Test]
    public function an_agent_cannot_sell_more_than_they_have_paid_for(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night);

        // Prepaid, and nothing paid in yet: the first sale is refused before a seat is held.
        $this->sale($night, $who)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'agent_credit_exhausted');

        $this->assertSame(0, ExternalOrder::withoutGlobalScopes()->count(), 'and nothing was written');

        // Money in. Two seats at 2500 come to 5000, less ten per cent commission — 4500.
        app(TenantContext::class)->runAs($night['tenant'], fn () => app(SalesAgents::class)->record(
            $who['agent'],
            ['kind' => 'topup', 'amount' => 4500, 'currency' => 'EUR', 'reference' => 'BANK-1'],
        ));

        $this->sale($night, $who)->assertCreated();

        // And they are back to nought, so the next sale is refused again.
        $this->sale($night, $who)->assertStatus(409);
    }

    #[Test]
    public function what_they_owe_is_counted_from_the_seats_rather_than_stored(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night, ['credit_limit' => 100000]);

        $sold = $this->sale($night, $who)->assertCreated()->json();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($who) {
            $account = app(SalesAgents::class)->balance($who['agent']);

            $this->assertSame(5000, $account['sold']);
            $this->assertSame(500, $account['commission'], 'ten per cent of what they sold');
            // Nothing paid in, so they owe the face value less their commission.
            $this->assertSame(-4500, $account['balance']);
            $this->assertSame(95500, $account['available'], 'the limit is what is left to sell against');
        });

        // A refund hands the credit straight back, with nothing written to make it happen.
        app(TenantContext::class)->runAs($night['tenant'], function () use ($sold, $who) {
            app(OrderService::class)->refund(ExternalOrder::findOrFail($sold['id']));

            $account = app(SalesAgents::class)->balance($who['agent']->fresh());

            $this->assertSame(0, $account['sold']);
            $this->assertSame(0, $account['commission']);
            $this->assertSame(0, $account['balance']);
            $this->assertSame(5000, $account['refunded'], 'and it says what came back');
        });
    }

    #[Test]
    public function the_rate_agreed_today_does_not_rewrite_what_was_owed_last_season(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night, ['credit_limit' => 100000]);

        $this->sale($night, $who)->assertCreated();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($who) {
            $before = app(SalesAgents::class)->balance($who['agent'])['commission'];

            $who['agent']->forceFill(['commission_rate' => 2500])->save();

            $after = app(SalesAgents::class)->balance($who['agent']->fresh())['commission'];

            $this->assertSame(500, $before);
            $this->assertSame($before, $after, 'the booking kept the rate it was sold at');
        });
    }

    #[Test]
    public function the_ledger_decides_direction_rather_than_the_caller(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $who = $this->agentFor($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($who) {
            $agents = app(SalesAgents::class);

            // A top-up sent as a negative is still money in: a panel where a typo pays an agency
            // instead of charging it is a panel nobody can use.
            $agents->record($who['agent'], ['kind' => 'topup', 'amount' => -5000, 'currency' => 'EUR']);
            $agents->record($who['agent'], ['kind' => 'settlement', 'amount' => 1000, 'currency' => 'EUR']);
            $agents->record($who['agent'], ['kind' => 'adjustment', 'amount' => -250, 'currency' => 'EUR']);

            $account = $agents->balance($who['agent']);

            $this->assertSame(5000, $account['paid_in']);
            $this->assertSame(-1000, $account['settled_out']);
            $this->assertSame(-250, $account['adjustments']);
            $this->assertSame(3750, $account['balance']);
        });
    }

    #[Test]
    public function an_agent_sees_their_own_bookings_and_nobody_elses(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $mine = $this->agentFor($night, ['credit_limit' => 100000]);

        $sold = $this->sale($night, $mine)->assertCreated()->json();

        // A second bureau, with the same event and no sales of its own.
        $theirs = app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $user = $this->makeUser($night['tenant'], 'agent');
            $agent = SalesAgent::create([
                'tenant_id' => $night['tenant']->id,
                'name' => 'Bureau 34',
                'code' => 'bureau-34',
                'user_id' => $user->id,
                'commission_rate' => 500,
                'credit_limit' => 100000,
            ]);

            app(SalesAgents::class)->allow($agent, [$night['event']->id]);

            return ['agent' => $agent, 'user' => $user];
        });

        $this->asMember($mine['user'])->getJson('/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asMember($theirs['user'])->getJson('/v1/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And cannot open one by knowing its id.
        $this->asMember($theirs['user'])->getJson('/v1/orders/'.$sold['id'])->assertNotFound();
        $this->asMember($mine['user'])->getJson('/v1/orders/'.$sold['id'])->assertOk();
    }

    #[Test]
    public function an_agency_is_not_handed_the_organisers_audience(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $mine = $this->agentFor($night, ['credit_limit' => 100000]);

        $this->sale($night, $mine)->assertCreated();

        /*
         * The reason `orders.view.own` exists at all.
         *
         * `orders.view` is not only the bookings screen: the customer directory, the waiting list
         * and every abandoned basket are behind the same permission, because for the organiser's own
         * box office they are all the same authority. For a shop across town they are not — that is
         * the organiser's audience, and selling an agency tickets is not selling them the list of
         * who bought.
         */
        $this->asMember($mine['user'])->getJson('/v1/customers')->assertForbidden();
        $this->asMember($mine['user'])->getJson('/v1/events/'.$night['event']->id.'/waiting-list')->assertForbidden();
        $this->asMember($mine['user'])->getJson('/v1/baskets')->assertForbidden();
        $this->asMember($mine['user'])->getJson('/v1/instalments')->assertForbidden();

        // Nor the house's attendee list, which is what `tickets.view` is.
        $this->asMember($mine['user'])->getJson('/v1/tickets?event_id='.$night['event']->id)->assertForbidden();

        // What they sold is theirs, and still answers.
        $this->asMember($mine['user'])->getJson('/v1/orders')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_narrow_permission_shows_nothing_to_somebody_who_sells_for_nobody(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $mine = $this->agentFor($night, ['credit_limit' => 100000]);

        $sold = $this->sale($night, $mine)->assertCreated()->json();

        // A role built out of the narrow permission, held by somebody who is not an agency at all.
        $stranger = $this->makeUser($night['tenant'], 'agent');

        /*
         * Nothing, rather than everything. `orders.view.own` answers "what did *you* sell", and for
         * somebody who sells for nobody the honest answer is an empty list — a permission that fails
         * open is how a narrow role quietly becomes the wide one.
         */
        $this->asMember($stranger)->getJson('/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->asMember($stranger)->getJson('/v1/orders/'.$sold['id'])->assertNotFound();
    }

    #[Test]
    public function the_owner_grants_credit_and_the_agent_reads_their_own_account(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night);
        $owner = $this->makeUser($night['tenant'], 'manager');

        $this->asMember($owner)->postJson("/v1/sales-agents/{$who['agent']->id}/credit", [
            'kind' => 'topup',
            'amount' => 20000,
            'currency' => 'EUR',
            'method' => 'transfer',
            'reference' => 'BANK-77',
        ])->assertCreated()->assertJsonPath('account.paid_in', 20000);

        // The agent's own view of the same account, which needs no permission because it is about
        // the caller.
        $this->asMember($who['user'])->getJson('/v1/sales-agents/summary')
            ->assertOk()
            ->assertJsonPath('agent.account.available', 20000)
            ->assertJsonCount(1, 'events');

        // And somebody who is not an agent is told so rather than refused.
        $this->asMember($owner)->getJson('/v1/sales-agents/summary')
            ->assertOk()
            ->assertJsonPath('agent', null);

        // Selling is not the same job as deciding who may sell.
        $this->asMember($who['user'])->postJson("/v1/sales-agents/{$who['agent']->id}/credit", [
            'kind' => 'topup', 'amount' => 999, 'currency' => 'EUR',
        ])->assertForbidden();
    }

    #[Test]
    public function the_statement_says_what_a_period_sold_and_what_is_outstanding_now(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night, ['credit_limit' => 100000]);
        $owner = $this->makeUser($night['tenant'], 'manager');

        $this->sale($night, $who)->assertCreated();

        $statement = $this->asMember($owner)
            ->getJson("/v1/sales-agents/{$who['agent']->id}/statement?from=".now()->subDay()->toDateString())
            ->assertOk()
            ->json();

        $this->assertSame(5000, $statement['period']['sold']);
        $this->assertSame(500, $statement['period']['commission']);
        $this->assertSame(4500, $statement['period']['due'], 'what they owe for the period');
        $this->assertSame(-4500, $statement['account']['balance'], 'and what is between us now');

        // A period before any of it happened is empty, and the account is still the account.
        $empty = $this->asMember($owner)->getJson(
            "/v1/sales-agents/{$who['agent']->id}/statement?to=".now()->subWeek()->toDateString()
        )->assertOk()->json();

        $this->assertSame(0, $empty['period']['sold']);
        $this->assertSame(-4500, $empty['account']['balance']);
    }

    #[Test]
    public function an_agency_reads_its_own_statement_and_reaches_no_others(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $mine = $this->agentFor($night, ['credit_limit' => 100000, 'commission_rate' => 1000]);

        $this->sale($night, $mine, seats: 2)->assertCreated();

        $seen = $this->asMember($mine['user'])
            ->getJson('/v1/sales-agents/summary/statement')
            ->assertOk()
            ->json();

        /*
         * The organiser's figures, read by the agency. Both sides arguing about a month from the
         * same numbers is the whole point — the alternative is a settlement that takes a fortnight
         * because the two spreadsheets disagree.
         */
        $mineAsOwner = $this->actingAs($mine['owner'] ?? $this->makeUser($night['tenant'], 'owner'))
            ->getJson('/v1/sales-agents/'.$mine['agent']->id.'/statement')
            ->assertOk()
            ->json();

        $this->assertSame($mineAsOwner['period'], $seen['period']);
        $this->assertSame($mineAsOwner['account']['balance'], $seen['account']['balance']);
        $this->assertSame(
            $seen['period']['sold'] - $seen['period']['commission'],
            $seen['period']['due'],
        );

        // And it is theirs and only theirs: no route from here to the bureau across town.
        $theirs = app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            return SalesAgent::create([
                'tenant_id' => $night['tenant']->id,
                'name' => 'Bureau 34',
                'code' => 'bureau-34',
                'commission_rate' => 500,
                'credit_limit' => 100000,
            ]);
        });

        $this->asMember($mine['user'])
            ->getJson('/v1/sales-agents/'.$theirs->id.'/statement')
            ->assertForbidden();

        $this->asMember($mine['user'])->getJson('/v1/sales-agents')->assertForbidden();
    }

    #[Test]
    public function somebody_who_sells_for_nobody_is_told_so_rather_than_refused(): void
    {
        $night = $this->makeSellableEvent();
        $staff = $this->makeUser($night['tenant'], 'box_office');

        // Not a wall: there is nothing here for them, which is not the same as being kept out.
        $this->asMember($staff)
            ->getJson('/v1/sales-agents/summary/statement')
            ->assertOk()
            ->assertJsonPath('agent', null);
    }

    #[Test]
    public function an_agent_who_has_sold_something_is_switched_off_rather_than_deleted(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night, ['credit_limit' => 100000]);
        $owner = $this->makeUser($night['tenant'], 'manager');

        $this->sale($night, $who)->assertCreated();

        $this->asMember($owner)->deleteJson("/v1/sales-agents/{$who['agent']->id}")
            ->assertOk()
            ->assertJsonPath('deactivated', true)
            ->assertJsonPath('active', false);

        // Switched off is switched off: the counter refuses them.
        $this->sale($night, $who)->assertStatus(403);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($who) {
            $this->assertNotNull(SalesAgent::find($who['agent']->id), 'and their account is still there');
        });
    }

    #[Test]
    public function a_suspended_agent_and_an_agent_with_no_allowance_are_told_apart(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night, ['credit_limit' => 100000]);

        app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => $who['agent']->forceFill(['active' => false])->save()
        );

        $this->sale($night, $who)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'agent_suspended');
    }

    #[Test]
    public function a_comp_costs_an_agent_nothing_and_is_still_theirs(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $who = $this->agentFor($night);

        // Nothing paid in, and a comp is worth nothing: a limit is not a reason to refuse a gift.
        $sale = $this->asMember($who['user'])->postJson("/v1/events/{$night['event']->id}/sell", [
            'seat_ids' => $night['seats']->take(1)->pluck('id')->all(),
            'buyer' => ['name' => 'Guest of the house'],
            'payment' => 'comp',
        ])->assertCreated()->json();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($sale, $who) {
            $order = ExternalOrder::findOrFail($sale['id']);

            $this->assertSame($who['agent']->id, $order->sales_agent_id);
            $this->assertSame(0, app(SalesAgents::class)->balance($who['agent'])['balance']);
        });
    }
}
