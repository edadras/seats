<?php

namespace Tests\Feature;

use App\Domain\Inventory\HoldService;
use App\Domain\Orders\OrderService;
use App\Domain\Payments\PaymentPlans;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\OrderInstalment;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A party of forty, a deposit in November and the balance in March.
 *
 * Two claims, and they are the whole feature. **The seats go at the deposit and the tickets go at
 * the last payment**: a school that has paid a fifth has the chairs — nobody else can be sold them
 * — and does not yet have forty codes that open a door. And **the plan has to add up to the
 * price**, because a schedule that comes to less is a debt nobody agreed to and one that comes to
 * more is an overcharge somebody will find.
 *
 * Everything else here follows from the platform's usual rule: what is owed and whether it is late
 * are counted from rows and compared against the clock, never stored, so nothing has to run at
 * midnight for a booking to become overdue.
 */
class PaymentPlanTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private int $taken = 0;

    /** Somebody who may read a booking and may not sell one. No built-in role is that shape. */
    private function makeBookkeeper(\App\Models\Tenant $tenant): \App\Models\User
    {
        app(TenantContext::class)->runAs($tenant, fn () => \App\Models\TenantRole::create([
            'tenant_id' => $tenant->id,
            'key' => 'bookkeeper',
            'name' => 'Bookkeeper',
            'permissions' => ['orders.view', 'reports.orders.view'],
        ]));

        return $this->makeUser($tenant, 'bookkeeper');
    }

    /** A confirmed booking with no plan on it yet. */
    private function booking(array $night, int $seats = 2): ExternalOrder
    {
        $ids = $night['seats']->slice($this->taken, $seats)->pluck('id')->all();
        $this->taken += $seats;

        $hold = app(HoldService::class)->create($night['event'], $ids, 'session-'.uniqid());
        $client = $this->makeApiClient($night['tenant'])['client'];

        [$order] = app(OrderService::class)->register(
            $client,
            'ORD-'.strtoupper(uniqid()),
            $hold->token,
            ['name' => 'Miss Fielding', 'email' => 'office@stmarys.test'],
        );

        return $order->fresh();
    }

    #[Test]
    public function the_seats_go_at_the_deposit_and_the_tickets_at_the_last_payment(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 4);
            $plans = app(PaymentPlans::class);

            $plans->create($order, [
                'deposit' => 2000,
                'instalments' => 2,
                'every_days' => 30,
                'deposit_paid' => true,
            ]);

            app(OrderService::class)->confirm($order->fresh());

            $order = $order->fresh();
            $allocations = Allocation::where('external_order_row_id', $order->id)->get();

            $this->assertSame('confirmed', $order->status);
            $this->assertCount(4, $allocations, 'the chairs are theirs from the deposit');
            $this->assertSame(0, Ticket::whereIn('allocation_id', $allocations->pluck('id'))->count(),
                'and nothing that opens a door exists yet');

            $state = $plans->state($order);

            $this->assertSame(10000, $state['total']);
            $this->assertSame(2000, $state['paid']);
            $this->assertSame(8000, $state['balance']);
            $this->assertSame('due', $state['state']);

            foreach ($plans->instalments($order)->whereNull('paid_at') as $instalment) {
                $plans->pay($instalment, ['method' => 'transfer']);
            }

            $this->assertSame(0, $plans->state($order->fresh())['balance']);
            $this->assertSame(4, Ticket::whereIn('allocation_id', $allocations->pluck('id'))
                ->where('status', 'issued')->count(),
                'the codes arrive with the last payment, not before it');
        });
    }

    #[Test]
    public function a_plan_has_to_add_up_to_what_the_booking_costs(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 2);

            try {
                app(PaymentPlans::class)->create($order, [
                    'schedule' => [
                        ['amount' => 1000, 'due_on' => now()->toDateString()],
                        ['amount' => 1000, 'due_on' => now()->addMonth()->toDateString()],
                    ],
                ]);

                $this->fail('A plan that comes to less than the booking was accepted.');
            } catch (ApiException $refusal) {
                $this->assertSame('plan_does_not_add_up', $refusal->errorCode());
                $this->assertSame(5000, $refusal->details()['total']);
                $this->assertSame(2000, $refusal->details()['scheduled']);
            }

            $this->assertSame(0, OrderInstalment::where('external_order_row_id', $order->id)->count(),
                'and nothing was written on the way to refusing it');
        });
    }

    #[Test]
    public function the_remainder_lands_on_one_payment_rather_than_being_scattered(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 3333);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 1);
            $plans = app(PaymentPlans::class);

            $plans->create($order, ['deposit' => 333, 'instalments' => 3, 'every_days' => 30]);

            $amounts = $plans->instalments($order)->pluck('amount')->all();

            $this->assertSame(3333, array_sum($amounts), 'the plan is the price, to the penny');
            $this->assertSame([333, 1000, 1000, 1000], $amounts);
        });
    }

    #[Test]
    public function overdue_is_worked_out_from_the_clock_with_nothing_run_at_midnight(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 2);
            $plans = app(PaymentPlans::class);

            $plans->create($order, [
                'schedule' => [
                    ['amount' => 1000, 'due_on' => now()->addDays(3)->toDateString()],
                    ['amount' => 4000, 'due_on' => now()->addDays(20)->toDateString()],
                ],
            ]);

            $this->assertSame('due', $plans->state($order)['state']);
            $this->assertSame([], $plans->outstanding('overdue'));

            // Nothing runs. The date simply arrives.
            $this->travel(5)->days();

            $this->assertSame('overdue', $plans->state($order->fresh())['state']);
            $this->assertCount(1, $plans->outstanding('overdue'));
            $this->assertSame(5000, $plans->outstanding('overdue')[0]['balance'],
                'the call is about the whole booking, not one late line');

            $this->travelBack();
        });
    }

    #[Test]
    public function a_payment_recorded_twice_is_one_payment(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 2);
            $plans = app(PaymentPlans::class);

            $plans->create($order, ['deposit' => 1000, 'instalments' => 1, 'every_days' => 30]);

            $first = $plans->instalments($order)->first();
            $when = $plans->pay($first, ['method' => 'cash'])->paid_at;

            $this->travel(2)->minutes();

            $again = $plans->pay($first->fresh(), ['method' => 'card']);

            $this->assertTrue($when->equalTo($again->paid_at), 'the second entry changed nothing');
            $this->assertSame('cash', $again->method);
            $this->travelBack();
        });
    }

    #[Test]
    public function a_booking_cannot_be_given_two_plans(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 2);
            $plans = app(PaymentPlans::class);

            $plans->create($order, ['deposit' => 1000, 'instalments' => 1]);

            $this->expectException(ApiException::class);
            $plans->create($order, ['deposit' => 500, 'instalments' => 2]);
        });
    }

    #[Test]
    public function a_date_can_be_moved_and_an_amount_cannot_quietly_change_the_total(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 2);
            $plans = app(PaymentPlans::class);

            $plans->create($order, ['deposit' => 1000, 'instalments' => 2, 'every_days' => 30]);

            $last = $plans->instalments($order)->last();
            $moved = $plans->amend($last, ['due_on' => now()->addDays(90)->toDateString()]);

            $this->assertSame(now()->addDays(90)->toDateString(), $moved->due_on->toDateString());

            try {
                $plans->amend($moved, ['amount' => 1]);
                $this->fail('An amount that broke the total was accepted.');
            } catch (ApiException $refusal) {
                $this->assertSame('plan_does_not_add_up', $refusal->errorCode());
            }

            $this->assertSame(5000, (int) $plans->state($order->fresh())['total']);
        });
    }

    #[Test]
    public function the_counter_sells_a_party_on_a_plan_and_the_reader_cannot_take_the_money(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2000);
        $seats = $night['seats']->slice(0, 4)->pluck('id')->all();

        $seller = $this->makeUser($night['tenant'], 'box_office');
        // Somebody in the office who may look at bookings and may not take money for one: a role
        // the organiser made, which is the only way to hold one half of this pair.
        $reader = $this->makeBookkeeper($night['tenant']);

        $sale = $this->asMember($seller)->postJson("/v1/events/{$night['event']->id}/sell", [
            'seat_ids' => $seats,
            'buyer' => ['name' => 'Miss Fielding', 'email' => 'office@stmarys.test'],
            'group_name' => "St Mary's School",
            'payment' => 'plan',
            'method' => 'transfer',
            'plan' => ['deposit' => 2000, 'instalments' => 2, 'every_days' => 30],
        ])->assertCreated();

        $sale->assertJsonPath('plan.has_plan', true)
            ->assertJsonPath('plan.balance', 6000)
            ->assertJsonPath('plan.state', 'due');

        $order = ExternalOrder::find($sale->json('id'));

        $this->assertSame("St Mary's School", $order->group_name);
        $this->assertSame(0, Ticket::whereIn(
            'allocation_id',
            Allocation::where('external_order_row_id', $order->id)->pluck('id')
        )->count(), 'a party with a deposit has seats, not codes');

        $due = OrderInstalment::where('external_order_row_id', $order->id)
            ->whereNull('paid_at')->orderBy('sequence')->first();

        // Reading a plan is reading a booking; taking money for one is the counter's work.
        $this->asMember($reader)->getJson("/v1/orders/{$order->id}/payment-plan")->assertOk();
        $this->asMember($reader)->postJson("/v1/instalments/{$due->id}/pay")->assertForbidden();

        $this->asMember($seller)->postJson("/v1/instalments/{$due->id}/pay", ['method' => 'transfer'])
            ->assertOk()
            ->assertJsonPath('balance', 3000);
    }

    #[Test]
    public function the_chase_list_is_what_somebody_rings_about_on_a_monday(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 6, amount: 2500);
        $reader = $this->makeBookkeeper($night['tenant']);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $late = $this->booking($night, 2);
            $late->forceFill(['group_name' => 'Coach party'])->save();
            $soon = $this->booking($night, 2);

            app(PaymentPlans::class)->create($late, [
                'schedule' => [['amount' => 5000, 'due_on' => now()->subDays(3)->toDateString()]],
            ]);
            app(PaymentPlans::class)->create($soon, [
                'schedule' => [['amount' => 5000, 'due_on' => now()->addDays(14)->toDateString()]],
            ]);
        });

        $this->asMember($reader)->getJson('/v1/instalments?state=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.group_name', 'Coach party')
            ->assertJsonPath('data.0.state', 'overdue');

        $this->asMember($reader)->getJson('/v1/instalments')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_booking_with_no_plan_is_settled_and_keeps_its_tickets(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 2500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->booking($night, 2);

            app(OrderService::class)->confirm($order->fresh());

            $state = app(PaymentPlans::class)->state($order->fresh());

            $this->assertFalse($state['has_plan']);
            $this->assertSame(0, $state['balance']);
            $this->assertSame(2, Ticket::whereIn(
                'allocation_id',
                Allocation::where('external_order_row_id', $order->id)->pluck('id')
            )->where('status', 'issued')->count(), 'an ordinary sale is untouched by any of this');
        });
    }
}
