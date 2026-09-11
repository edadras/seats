<?php

namespace Tests\Feature;

use App\Domain\Settlement\GatewayPayouts;
use App\Models\ExternalOrder;
use App\Models\GatewayPayout;
use App\Models\OrderRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The books, against the bank.
 *
 * Every money figure on this platform is worked out from the orders in this database, and that is
 * the right way round — a booking's arithmetic is frozen when it is paid and never recomputed. But
 * it has always meant the same thing: the figures agree with themselves. A gateway that declines a
 * payment we recorded, reverses one, or charges a fee nobody accounted for leaves the ledger saying
 * one number and the bank another, and nothing here could notice.
 *
 * What is worth testing is not that the join works. It is the four sentences this screen exists to
 * be able to say — matched, differs, unknown, missing — and the fifth that costs nothing and catches
 * the commonest mistake of all: a statement that fails its own arithmetic.
 */
class GatewayReconciliationTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_payment_we_recorded_and_they_paid_for_is_matched(): void
    {
        $ctx = $this->paidOrder('wc_rec_1', 'pay_alpha');

        $answer = $this->reconcile($ctx['tenant'], [
            ['reference' => 'pay_alpha', 'kind' => 'payment', 'amount' => 5000, 'fee' => 150],
        ], gross: 5000, fees: 150, net: 4850);

        $this->assertCount(1, $answer['matched']);
        $this->assertSame('wc_rec_1', $answer['matched'][0]['order_id']);
        $this->assertSame([], $answer['differs']);
        $this->assertSame([], $answer['unknown']);
        $this->assertSame([], $answer['missing']);
    }

    #[Test]
    public function a_line_for_the_wrong_amount_says_both_figures(): void
    {
        $ctx = $this->paidOrder('wc_rec_2', 'pay_beta');

        $answer = $this->reconcile($ctx['tenant'], [
            // They paid less than the order came to, which in practice is a partial refund nobody
            // wrote down or a currency conversion.
            ['reference' => 'pay_beta', 'kind' => 'payment', 'amount' => 4200, 'fee' => 120],
        ], gross: 4200, fees: 120, net: 4080);

        $this->assertCount(1, $answer['differs']);
        $this->assertSame(4200, $answer['differs'][0]['amount']);
        // "Out by 800" is a number somebody can search a statement for; "does not reconcile" is not.
        $this->assertSame(5000, $answer['differs'][0]['expected']);
    }

    #[Test]
    public function money_they_paid_for_something_we_have_never_heard_of_is_named(): void
    {
        $ctx = $this->paidOrder('wc_rec_3', 'pay_gamma');

        $answer = $this->reconcile($ctx['tenant'], [
            ['reference' => 'pay_gamma', 'kind' => 'payment', 'amount' => 5000],
            ['reference' => 'pay_from_nowhere', 'kind' => 'payment', 'amount' => 900],
        ], gross: 5900, fees: 0, net: 5900);

        $this->assertCount(1, $answer['unknown']);
        $this->assertSame('pay_from_nowhere', $answer['unknown'][0]['reference']);
        $this->assertNull($answer['unknown'][0]['order_id']);
    }

    #[Test]
    public function a_payment_we_recorded_and_nobody_paid_for_is_named_too(): void
    {
        $ctx = $this->paidOrder('wc_rec_4', 'pay_delta');

        // The statement arrives with nothing in it at all: the payment we think we took is simply
        // not there, which is the case a ledger agreeing with itself can never surface.
        $answer = $this->reconcile($ctx['tenant'], [], gross: 0, fees: 0, net: 0);

        $this->assertCount(1, $answer['missing']);
        $this->assertSame('pay_delta', $answer['missing'][0]['reference']);
        $this->assertSame('wc_rec_4', $answer['missing'][0]['order_id']);
        $this->assertSame(5000, $answer['missing'][0]['amount']);
    }

    #[Test]
    public function a_refund_is_compared_with_what_actually_went_back(): void
    {
        $ctx = $this->paidOrder('wc_rec_5', 'pay_epsilon');

        app(TenantContext::class)->runAs($ctx['tenant'], function () {
            $order = ExternalOrder::where('external_order_id', 'wc_rec_5')->firstOrFail();

            OrderRefund::create([
                'external_order_row_id' => $order->id,
                'amount' => 1200,
                'currency' => $order->currency,
                'status' => 'sent',
            ]);
        });

        $answer = $this->reconcile($ctx['tenant'], [
            ['reference' => 'pay_epsilon', 'kind' => 'payment', 'amount' => 5000],
            // Negative, because that is how it arrives on a statement.
            ['reference' => 'pay_epsilon', 'kind' => 'refund', 'amount' => -1200],
        ], gross: 3800, fees: 0, net: 3800);

        // Compared against what went back, not against the order total — otherwise every partial
        // refund on the platform would be reported as a discrepancy.
        $this->assertCount(2, $answer['matched']);
        $this->assertSame([], $answer['differs']);
    }

    #[Test]
    public function the_gateways_own_fee_lines_answer_to_no_order_of_ours(): void
    {
        $ctx = $this->paidOrder('wc_rec_6', 'pay_zeta');

        $answer = $this->reconcile($ctx['tenant'], [
            ['reference' => 'pay_zeta', 'kind' => 'payment', 'amount' => 5000],
            ['reference' => 'monthly', 'kind' => 'fee', 'amount' => -2500, 'description' => 'Account fee'],
        ], gross: 2500, fees: 0, net: 2500);

        $this->assertSame([], $answer['unknown']);
        $this->assertCount(2, $answer['matched']);
    }

    #[Test]
    public function a_statement_that_fails_its_own_arithmetic_says_so(): void
    {
        $ctx = $this->paidOrder('wc_rec_7', 'pay_eta');

        $answer = $this->reconcile($ctx['tenant'], [
            ['reference' => 'pay_eta', 'kind' => 'payment', 'amount' => 5000, 'fee' => 150],
        ], gross: 5000, fees: 150, net: 4000);

        $this->assertTrue($answer['arithmetic']['lines_match_gross']);
        $this->assertTrue($answer['arithmetic']['line_fees_match']);
        // The commonest import mistake there is, and finding it immediately saves an hour of
        // looking for a discrepancy that is not there.
        $this->assertFalse($answer['arithmetic']['net_matches']);
        $this->assertSame(4850, $answer['arithmetic']['net_expected']);
    }

    #[Test]
    public function the_same_statement_twice_is_refused_rather_than_doubled(): void
    {
        $ctx = $this->paidOrder('wc_rec_8', 'pay_theta');
        $user = $this->makeUser($ctx['tenant']);

        $payload = [
            'gateway' => 'stripe',
            'reference' => 'po_0001',
            'currency' => 'EUR',
            'paid_on' => now()->toDateString(),
            'gross' => 5000,
            'fees' => 150,
            'net' => 4850,
            'lines' => [['reference' => 'pay_theta', 'kind' => 'payment', 'amount' => 5000]],
        ];

        $this->asMember($user)->postJson('/v1/settlement/gateway-payouts', $payload)->assertCreated();

        // A second upload that quietly doubled a venue's income would not be found until an
        // accountant found it, and by then nobody would remember there had been two.
        $this->asMember($user)
            ->postJson('/v1/settlement/gateway-payouts', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'payout_already_recorded');

        $this->assertSame(1, GatewayPayout::count());
    }

    #[Test]
    public function the_list_says_which_statement_to_open(): void
    {
        $ctx = $this->paidOrder('wc_rec_9', 'pay_iota');
        $user = $this->makeUser($ctx['tenant']);

        $this->asMember($user)->postJson('/v1/settlement/gateway-payouts', [
            'gateway' => 'stripe',
            'reference' => 'po_0002',
            'currency' => 'EUR',
            'paid_on' => now()->toDateString(),
            'gross' => 900,
            'fees' => 0,
            'net' => 900,
            'lines' => [['reference' => 'pay_from_nowhere', 'kind' => 'payment', 'amount' => 900]],
        ])->assertCreated();

        $listed = $this->asMember($user)->getJson('/v1/settlement/gateway-payouts')->assertOk();

        // A finance officer scanning a list wants to know which statement to open, not its totals:
        // one line they paid that we cannot place, and one payment of ours they did not pay.
        $this->assertSame(2, $listed->json('data.0.unexplained'));
        $this->assertSame('po_0002', $listed->json('data.0.reference'));
    }

    #[Test]
    public function one_venues_statement_is_not_another_venues_to_read(): void
    {
        $mine = $this->paidOrder('wc_rec_10', 'pay_kappa');
        $theirs = $this->makeTenant('Someone Else');

        $this->asMember($this->makeUser($mine['tenant']))
            ->postJson('/v1/settlement/gateway-payouts', [
                'gateway' => 'stripe',
                'reference' => 'po_0003',
                'currency' => 'EUR',
                'paid_on' => now()->toDateString(),
                'gross' => 5000, 'fees' => 0, 'net' => 5000,
            ])->assertCreated();

        $this->asMember($this->makeUser($theirs))
            ->getJson('/v1/settlement/gateway-payouts/'.GatewayPayout::first()->id)
            ->assertStatus(404);
    }

    #[Test]
    public function somebody_who_may_not_see_the_money_cannot_see_the_bank_either(): void
    {
        $ctx = $this->paidOrder('wc_rec_11', 'pay_lambda');

        // The door, not the box office: a clerk at the window already reads `reports.orders.view`
        // because they settle a till against it, and a volunteer at the door does not.
        $this->asMember($this->makeUser($ctx['tenant'], 'door'))
            ->getJson('/v1/settlement/gateway-payouts')
            ->assertStatus(403);
    }

    /* --------------------------------------------------------------------------- helpers */

    /**
     * A confirmed order of 5000, carrying the reference the gateway would know it by.
     *
     * @return array{tenant: \App\Models\Tenant}
     */
    private function paidOrder(string $orderId, string $reference): array
    {
        $ctx = $this->sellableOrder($orderId);

        $this->storefront('POST', "/v1/integrations/woocommerce/orders/{$orderId}/confirm")->assertOk();

        app(TenantContext::class)->runAs($ctx['tenant'], function () use ($orderId, $reference) {
            $order = ExternalOrder::where('external_order_id', $orderId)->firstOrFail();

            // Where the handle has always lived: written down by the checkout the moment a payment
            // begins (App\Domain\Sites\StorefrontCheckout).
            $order->forceFill([
                'total_amount' => 5000,
                'metadata' => array_merge($order->metadata ?? [], [
                    'gateway' => 'stripe',
                    'payment_reference' => $reference,
                ]),
            ])->save();
        });

        return $ctx;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function reconcile(
        \App\Models\Tenant $tenant,
        array $lines,
        int $gross,
        int $fees,
        int $net,
    ): array {
        return app(TenantContext::class)->runAs($tenant, function () use ($lines, $gross, $fees, $net) {
            $payout = app(GatewayPayouts::class)->record([
                'gateway' => 'stripe',
                'reference' => 'po_'.bin2hex(random_bytes(4)),
                'currency' => 'EUR',
                'paid_on' => now()->toDateString(),
                'gross' => $gross,
                'fees' => $fees,
                'net' => $net,
            ], $lines);

            return app(GatewayPayouts::class)->reconcile($payout);
        });
    }
}
