<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\ExternalOrder;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Settling a period once.
 *
 * The settlement report would tell anybody who asked what a window was worth, and told nobody
 * whether it had been paid. So the same month could go out twice, a fortnight could fall between
 * two payouts nobody lined up, and a refund in March quietly rewrote what February had appeared to
 * be worth long after the money left.
 *
 * These are the checks that a payout means something: the days can only be settled once, the
 * figures do not move afterwards, and undoing one frees its days rather than hiding them.
 */
class PayoutTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_period_is_settled_at_the_figures_it_had(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->commission($fixture['tenant'], 1000);
        $this->buy($fixture, [0, 1]);

        $body = $this->settle($fixture, $this->today(), $this->today())
            ->assertStatus(201)
            ->json();

        $this->assertCount(1, $body['data'], 'One currency, one payout.');

        $payout = $body['data'][0];

        $this->assertSame(5000, $payout['charged']);
        $this->assertSame(500, $payout['commission'], '10% of what was kept.');
        $this->assertSame(4500, $payout['payable']);
        $this->assertSame('recorded', $payout['status']);
        $this->assertCount(1, $payout['events'], 'The breakdown is kept, so the statement reprints.');
    }

    #[Test]
    public function the_same_days_cannot_be_settled_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $this->settle($fixture, $this->today(), $this->today())->assertStatus(201);

        $this->settle($fixture, $this->today(), $this->today())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'period_already_settled');

        $this->assertSame(1, Payout::count());
    }

    #[Test]
    public function a_period_that_merely_touches_a_settled_one_is_refused_too(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $today = $this->today();
        $yesterday = now()->subDay()->toDateString();

        $this->settle($fixture, $yesterday, $today)->assertStatus(201);

        // Starting on the day the last one ended is that day paid twice — the commonest way a
        // month gets counted in two payouts.
        $this->settle($fixture, $today, now()->addDays(3)->toDateString())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'period_already_settled');
    }

    #[Test]
    public function the_day_after_carries_on_where_the_last_one_stopped(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $this->settle($fixture, $this->today(), $this->today())->assertStatus(201);

        $body = $this->actingAs($this->operator())
            ->getJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/payouts')
            ->assertOk()
            ->json();

        $this->assertSame(now()->addDay()->toDateString(), $body['next_from']);
    }

    #[Test]
    public function a_refund_afterwards_does_not_rewrite_what_was_paid(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $this->settle($fixture, $this->today(), $this->today())->assertStatus(201);

        // A week later, one of those seats comes back.
        $this->refund($fixture);

        $payout = Payout::firstOrFail();

        $this->assertSame(5000, $payout->charged, 'What was sent is what was sent.');
        $this->assertSame(0, $payout->refunded);

        // And the live report now says something different, which is also true — the two are
        // different questions and a platform that could only answer one of them is worse.
        $live = $this->actingAs($this->makeUser($fixture['tenant']))
            ->getJson('/v1/settlement')->assertOk()->json();

        $this->assertSame(5000, $live['totals'][0]['refunded']);
    }

    #[Test]
    public function voiding_one_frees_its_days_and_keeps_the_row(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $first = $this->settle($fixture, $this->today(), $this->today())
            ->assertStatus(201)->json('data.0.id');

        $this->actingAs($this->operator())
            ->postJson('/v1/admin/payouts/'.$first.'/void', ['reason' => 'Wrong bank account.'])
            ->assertOk()
            ->assertJsonPath('status', 'void');

        // The days are free again, so the right payout can be made over them.
        $second = $this->settle($fixture, $this->today(), $this->today())->assertStatus(201)->json('data.0.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Payout::count(), 'Voided, not deleted: what was sent is still findable.');
        $this->assertSame('Wrong bank account.', Payout::findOrFail($first)->void_reason);
    }

    #[Test]
    public function a_voided_payout_cannot_be_voided_or_paid_again(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $id = $this->settle($fixture, $this->today(), $this->today())->assertStatus(201)->json('data.0.id');

        $this->actingAs($this->operator())
            ->postJson('/v1/admin/payouts/'.$id.'/void', ['reason' => 'Wrong bank account.'])->assertOk();

        $this->actingAs($this->operator())
            ->postJson('/v1/admin/payouts/'.$id.'/void', ['reason' => 'Again.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'payout_already_void');

        $this->actingAs($this->operator())
            ->postJson('/v1/admin/payouts/'.$id.'/paid', [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'payout_is_void');
    }

    #[Test]
    public function the_database_itself_refuses_an_overlap(): void
    {
        $fixture = $this->makeSellableEvent();

        $row = [
            'tenant_id' => $fixture['tenant']->id,
            'currency' => 'EUR',
            'charged' => 5000, 'refunded' => 0, 'kept' => 5000, 'tax_kept' => 0,
            'commission' => 0, 'payable' => 5000, 'commission_rate' => 0, 'orders' => 1,
        ];

        Payout::create($row + ['period_from' => '2026-03-01', 'period_to' => '2026-03-10']);

        /*
         * Written straight to the table, past every check in the domain.
         *
         * This is the case a check cannot cover: two operators clicking at the same moment arrive
         * as two inserts, and neither one can see the other. If the guarantee lived only in PHP,
         * this insert would succeed and a week in March would be paid twice.
         */
        $this->expectException(\Illuminate\Database\QueryException::class);

        Payout::create($row + ['period_from' => '2026-03-05', 'period_to' => '2026-03-15']);
    }

    #[Test]
    public function the_same_days_in_another_currency_are_a_different_period(): void
    {
        $fixture = $this->makeSellableEvent();

        $row = [
            'tenant_id' => $fixture['tenant']->id,
            'period_from' => '2026-03-01', 'period_to' => '2026-03-31',
            'charged' => 5000, 'refunded' => 0, 'kept' => 5000, 'tax_kept' => 0,
            'commission' => 0, 'payable' => 5000, 'commission_rate' => 0, 'orders' => 1,
        ];

        Payout::create($row + ['currency' => 'EUR']);
        Payout::create($row + ['currency' => 'IRR']);

        $this->assertSame(2, Payout::count(), 'Euro and rial are two debts, not one paid twice.');
    }

    #[Test]
    public function a_period_with_nothing_in_it_is_refused_rather_than_recorded_as_zero(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // A period typed wrongly, recorded as a zero, would block the right one behind the overlap
        // rule until somebody worked out why.
        $this->settle($fixture, '2020-01-01', '2020-01-31')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'nothing_to_settle');

        $this->assertSame(0, Payout::count());
    }

    #[Test]
    public function a_period_that_runs_backwards_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->settle($fixture, $this->today(), now()->subWeek()->toDateString())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'period_runs_backwards');
    }

    #[Test]
    public function the_preview_says_what_it_would_pay_and_what_is_in_the_way(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $before = $this->preview($fixture, $this->today(), $this->today());

        $this->assertSame(5000, $before['currencies'][0]['payable']);
        $this->assertSame([], $before['clashes'], 'Nothing settled yet.');
        $this->assertNull($before['suggested_from']);

        $this->settle($fixture, $this->today(), $this->today())->assertStatus(201);

        $after = $this->preview($fixture, $this->today(), $this->today());

        $this->assertCount(1, $after['clashes'], 'And now it names what is in the way.');
        $this->assertSame($this->today(), $after['clashes'][0]['from']);
    }

    #[Test]
    public function two_currencies_are_two_payouts(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        // A second night on the same account, selling in another currency, on the same days.
        $other = $this->makeSellableEvent($fixture['tenant']);

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\Event::whereKey($other['event']->id)->update(['currency' => 'IRR'])
        );

        $this->buy($other, [0]);

        $body = $this->settle($fixture, $this->today(), $this->today())->assertStatus(201)->json();

        $this->assertCount(2, $body['data'], 'Two currencies cannot be one number to pay.');
        $this->assertSame(['EUR', 'IRR'], collect($body['data'])->pluck('currency')->sort()->values()->all());
    }

    #[Test]
    public function support_may_look_but_not_send_money(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $support = $this->operator('support@platform.test', 'support');

        $this->actingAs($support)
            ->getJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/payouts')
            ->assertOk();

        $this->actingAs($support)
            ->postJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/payouts', [
                'from' => $this->today(),
                'to' => $this->today(),
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'support_may_not_change');

        $this->assertSame(0, Payout::count());
    }

    #[Test]
    public function an_organisers_owner_cannot_reach_the_payout_console_at_all(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // Not found rather than forbidden: who runs the platform is not discoverable by trying.
        $this->actingAs($owner)
            ->getJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/payouts')
            ->assertNotFound();

        $this->actingAs($owner)
            ->postJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/payouts', [
                'from' => $this->today(),
                'to' => $this->today(),
            ])
            ->assertNotFound();
    }

    #[Test]
    public function the_organiser_reads_their_own_payouts_and_nobody_elses(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);
        $this->settle($fixture, $this->today(), $this->today())->assertStatus(201);

        // A second organiser, with their own settled period.
        $other = $this->makeSellableEvent($this->makeTenant('Riverside'));

        Payout::create([
            'tenant_id' => $other['tenant']->id,
            'currency' => 'EUR',
            'period_from' => $this->today(),
            'period_to' => $this->today(),
            'charged' => 999900, 'refunded' => 0, 'kept' => 999900, 'tax_kept' => 0,
            'commission' => 0, 'payable' => 999900, 'commission_rate' => 0, 'orders' => 1,
        ]);

        $body = $this->actingAs($this->makeUser($fixture['tenant']))
            ->getJson('/v1/settlement/payouts')
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame(5000, $body['data'][0]['payable'], 'Their own figures, not the other account\'s.');
    }

    #[Test]
    public function a_door_volunteer_is_not_shown_the_payouts(): void
    {
        $fixture = $this->makeSellableEvent();
        $door = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($door)->getJson('/v1/settlement/payouts')->assertForbidden();
    }

    #[Test]
    public function settling_is_written_into_the_platforms_own_log(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $this->settle($fixture, $this->today(), $this->today())->assertStatus(201);

        $entry = PlatformAuditLog::where('action', 'payout.settled')->firstOrFail();

        $this->assertSame($fixture['tenant']->id, $entry->tenant_id);
        $this->assertSame($this->today(), $entry->context['from']);
    }

    #[Test]
    public function money_that_has_already_left_is_recorded_as_paid_at_once(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $body = $this->settle($fixture, $this->today(), $this->today(), [
            'paid_at' => now()->toIso8601String(),
            'reference' => 'SEPA-99',
            'method' => 'transfer',
        ])->assertStatus(201)->json();

        $this->assertSame('paid', $body['data'][0]['status']);
        $this->assertSame('SEPA-99', $body['data'][0]['reference']);
        $this->assertNotNull($body['data'][0]['paid_at']);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function settle(array $fixture, string $from, string $to, array $extra = [])
    {
        return $this->actingAs($this->operator())
            ->postJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/payouts', [
                'from' => $from,
                'to' => $to,
            ] + $extra);
    }

    private function preview(array $fixture, string $from, string $to): array
    {
        return $this->actingAs($this->operator())
            ->getJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/payouts/preview?from='.$from.'&to='.$to)
            ->assertOk()
            ->json();
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    private function operator(string $email = 'operator@platform.test', string $level = 'operator'): User
    {
        $user = User::firstWhere('email', $email) ?? User::factory()->create([
            'email' => $email,
            'password' => Hash::make('correct horse battery'),
        ]);

        PlatformAdmin::firstOrCreate(['user_id' => $user->id], ['level' => $level]);

        // Sanctum resolves its guard once per test, so a second actingAs would still be answered
        // as the first caller without this.
        app('auth')->forgetGuards();

        return $user;
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

    private function refund(array $fixture): void
    {
        $owner = $this->makeUser($fixture['tenant']);

        $order = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::orderByDesc('created_at')->firstOrFail()
        );

        $this->actingAs($owner)->postJson("/v1/orders/{$order->id}/refund", [])->assertOk();
    }

    private function commission($tenant, int $basisPoints): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($basisPoints) {
            $subscription = Subscription::orderByDesc('created_at')->firstOrFail();

            Plan::whereKey($subscription->plan_id)->update(['commission_rate' => $basisPoints]);
        });
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
