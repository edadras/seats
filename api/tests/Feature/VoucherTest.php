<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderTotals;
use App\Domain\Sites\SiteProvisioner;
use App\Domain\Vouchers\Vouchers;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Voucher;
use App\Models\VoucherMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Money the organiser already has, being spent.
 *
 * The line these tests are drawn along is the one that decides the VAT: a discount changes what a
 * booking cost, a voucher changes how the unchanged cost was settled. So the assertions that matter
 * most are the ones about the tax staying exactly where it was while the amount charged moves.
 *
 * The other half is the balance. There is no balance column, and these prove it: what is left is
 * the sum of the movements, a spend is idempotent per booking, and a refunded booking gives the
 * money back to the voucher rather than to the organiser.
 */
class VoucherTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------ the arithmetic */

    #[Test]
    public function a_voucher_does_not_change_the_tax(): void
    {
        $event = new Event([
            'booking_fee_kind' => 'per_order',
            'booking_fee_amount' => 100,
            'tax_rate' => 1000,           // 10%
            'tax_included' => false,
        ]);

        $without = OrderTotals::for($event, tickets: 5000);
        $with = OrderTotals::for($event, tickets: 5000, voucher: 3000);

        // €50 + €1 fee = €51, tax €5.10, total €56.10 — with or without the voucher.
        $this->assertSame(5610, $without->total);
        $this->assertSame(5610, $with->total);
        $this->assertSame(510, $with->tax);
        $this->assertSame(100, $with->fee);

        // What moves is only what is left to charge.
        $this->assertSame(5610, $without->payable);
        $this->assertSame(3000, $with->voucher);
        $this->assertSame(2610, $with->payable);
    }

    #[Test]
    public function a_discount_of_the_same_size_does_change_the_tax(): void
    {
        // The control for the test above, and the whole reason the two are different things.
        $event = new Event(['tax_rate' => 1000, 'tax_included' => false]);

        $voucher = OrderTotals::for($event, tickets: 5000, voucher: 3000);
        $discount = OrderTotals::for($event, tickets: 5000, discount: 3000);

        $this->assertSame(500, $voucher->tax);
        $this->assertSame(200, $discount->tax);
    }

    #[Test]
    public function a_voucher_never_pays_more_than_the_booking(): void
    {
        $totals = OrderTotals::for(new Event, tickets: 2000, voucher: 9999);

        $this->assertSame(2000, $totals->voucher);
        $this->assertSame(0, $totals->payable);
    }

    /* ------------------------------------------------------------------ the balance */

    #[Test]
    public function the_balance_is_the_sum_of_the_movements(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $voucher = $this->gift($fixture, 10000);

        $this->assertSame(10000, $this->balance($fixture, $voucher));

        $this->inTenant($fixture, fn () => VoucherMovement::create([
            'tenant_id' => $voucher->tenant_id,
            'voucher_id' => $voucher->id,
            'kind' => 'spend',
            'amount' => -2500,
            'currency' => 'EUR',
        ]));

        $this->assertSame(7500, $this->balance($fixture, $voucher));
    }

    #[Test]
    public function a_gift_voucher_is_refused_for_every_reason_it_should_be(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);

        $this->assertSame('unknown', $this->offer($fixture, 'NOSUCHTHING')->reason);

        $expired = $this->gift($fixture, 5000, ['expires_at' => now()->subDay()]);
        $this->assertSame('expired', $this->offer($fixture, $expired->code)->reason);

        $voided = $this->gift($fixture, 5000);
        $this->inTenant($fixture, fn () => app(Vouchers::class)->void($voided->fresh()));
        $this->assertSame('void', $this->offer($fixture, $voided->code)->reason);

        $foreign = $this->gift($fixture, 5000, ['currency' => 'GBP']);
        $this->assertSame('currency', $this->offer($fixture, $foreign->code)->reason);
    }

    #[Test]
    public function a_voided_voucher_keeps_its_ledger_and_writes_the_rest_off(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $voucher = $this->gift($fixture, 4000);

        $this->inTenant($fixture, fn () => app(Vouchers::class)->void($voucher->fresh(), 'lost card'));

        $this->assertSame(0, $this->balance($fixture, $voucher));
        $this->assertSame('void', $voucher->fresh()->status);

        // Not deleted — written off, with the row that says so.
        $this->inTenant($fixture, function () use ($voucher) {
            $this->assertSame(-4000, (int) VoucherMovement::where('voucher_id', $voucher->id)
                ->where('kind', 'void')->value('amount'));
        });
    }

    /* ------------------------------------------------------------------ the checkout */

    #[Test]
    public function a_gift_voucher_pays_part_of_a_booking(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $voucher = $this->gift($fixture, 2000);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code])
            ->assertRedirect('/checkout');

        $this->get('http://northgate.test/checkout')
            ->assertOk()
            ->assertSee('Paid by voucher')
            ->assertSee('Left to pay');

        $this->buy();

        $order = $this->order($fixture);

        // The sale is still €50. €20 of it was settled out of the voucher.
        $this->assertSame(5000, (int) $order->total_amount);
        $this->assertSame(2000, (int) $order->voucher_amount);
        $this->assertSame(0, $this->balance($fixture, $voucher));
    }

    #[Test]
    public function a_voucher_that_covers_everything_needs_no_gateway(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $voucher = $this->gift($fixture, 9000);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code])
            ->assertRedirect('/checkout');

        // No `gateway` field at all: the page does not offer one, so the form does not send one.
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
        ])->assertRedirect();

        $order = $this->order($fixture);

        $this->assertSame('confirmed', $order->status);
        $this->assertSame(5000, (int) $order->voucher_amount);
        // €90 issued, €50 spent.
        $this->assertSame(4000, $this->balance($fixture, $voucher));
    }

    #[Test]
    public function a_booking_with_nothing_covered_still_has_to_choose_a_gateway(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);

        $this->hold($fixture, [0])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
        ])->assertSessionHasErrors('gateway');
    }

    #[Test]
    public function the_amount_charged_is_what_is_left_after_the_voucher(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $voucher = $this->gift($fixture, 1500);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code]);

        $quote = $this->postJson('http://northgate.test/checkout/quote', [])->assertOk()->json();

        $this->assertSame(5000, $quote['total']);
        $this->assertSame(1500, $quote['voucher']);
        $this->assertSame(3500, $quote['payable']);
    }

    #[Test]
    public function a_voucher_is_spent_once_however_many_times_the_form_is_posted(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $voucher = $this->gift($fixture, 2000);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code]);

        // The same hold, submitted twice — a browser retrying the POST. It lands on one order.
        $this->buy();
        $this->buy();

        $this->inTenant($fixture, function () {
            $this->assertSame(1, ExternalOrder::count());
        });

        $this->assertSame(0, $this->balance($fixture, $voucher));
    }

    #[Test]
    public function account_credit_is_spent_without_anybody_typing_anything(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->credit($fixture, 'amina@example.test', 3000);

        // Signed in, the way the buyer account page leaves the session.
        $this->withSession(['seatmap_buyer' => ['email' => 'amina@example.test', 'name' => 'Amina']]);
        $this->hold($fixture, [0])->assertCreated();

        $this->get('http://northgate.test/checkout')->assertOk()->assertSee('Your credit');

        $this->buy();

        $this->assertSame(3000, (int) $this->order($fixture)->voucher_amount);
    }

    #[Test]
    public function somebody_elses_credit_is_not_offered(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->credit($fixture, 'someone@example.test', 3000);

        $this->withSession(['seatmap_buyer' => ['email' => 'amina@example.test', 'name' => 'Amina']]);
        $this->hold($fixture, [0])->assertCreated();
        $this->buy();

        $this->assertSame(0, (int) $this->order($fixture)->voucher_amount);
    }

    /* ------------------------------------------------------------------ giving it back */

    #[Test]
    public function a_refunded_booking_gives_the_voucher_money_back(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $voucher = $this->gift($fixture, 5000);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code]);
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test',
        ])->assertRedirect();

        $this->assertSame(0, $this->balance($fixture, $voucher));

        $order = $this->order($fixture);
        $this->inTenant($fixture, fn () => app(OrderService::class)->refund($order->fresh()));

        $this->assertSame(5000, $this->balance($fixture, $voucher));
    }

    #[Test]
    public function refunding_twice_gives_it_back_once(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $voucher = $this->gift($fixture, 5000);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code]);
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test',
        ])->assertRedirect();

        $order = $this->order($fixture);

        $this->inTenant($fixture, function () use ($order) {
            app(OrderService::class)->refund($order->fresh());
            app(OrderService::class)->refund($order->fresh());
        });

        $this->assertSame(5000, $this->balance($fixture, $voucher));
    }

    /* ------------------------------------------------------------------ the panel */

    #[Test]
    public function issuing_a_voucher_takes_the_permission_and_writes_the_ledger(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant'], 'box_office');

        $response = $this->actingAs($user)->postJson('/v1/vouchers', [
            'kind' => 'gift',
            'code' => 'ABCD-EFGH',
            'amount' => 5000,
            'currency' => 'EUR',
            'recipient' => 'Amina',
        ])->assertCreated();

        $this->assertSame(5000, $response->json('balance'));
        $this->assertSame(0, $response->json('spent'));
        $this->assertSame('ABCD-EFGH', $response->json('code'));

        $this->actingAs($user)->getJson('/v1/vouchers/'.$response->json('id'))
            ->assertOk()
            ->assertJsonPath('movements.0.kind', 'issue')
            ->assertJsonPath('movements.0.amount', 5000);
    }

    #[Test]
    public function a_credit_note_may_not_carry_a_code(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant'], 'box_office');

        // The API is asked for a credit and given a code. The code is dropped rather than stored:
        // a credit note with a code would be bearer money somebody was handed.
        $response = $this->actingAs($user)->postJson('/v1/vouchers', [
            'kind' => 'credit',
            'code' => 'SHOULD-NOT-STICK',
            'email' => 'AMINA@example.test',
            'amount' => 2000,
            'currency' => 'EUR',
        ])->assertCreated();

        $this->assertNull($response->json('code'));
        $this->assertSame('amina@example.test', $response->json('email'));
    }

    #[Test]
    public function the_same_code_cannot_be_issued_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant'], 'box_office');

        $body = ['kind' => 'gift', 'code' => 'TWICE', 'amount' => 1000, 'currency' => 'EUR'];

        $this->actingAs($user)->postJson('/v1/vouchers', $body)->assertCreated();
        $this->actingAs($user)->postJson('/v1/vouchers', $body)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'voucher_code_taken');
    }

    #[Test]
    public function a_box_office_without_the_permission_cannot_issue_money(): void
    {
        // The door has `tickets.view` and `checkins.view` and nothing that moves money.
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)->getJson('/v1/vouchers')->assertForbidden();
        $this->actingAs($doorman)->postJson('/v1/vouchers', [
            'kind' => 'gift', 'code' => 'NOPE', 'amount' => 1000, 'currency' => 'EUR',
        ])->assertForbidden();
    }

    #[Test]
    public function a_refund_can_be_taken_as_credit(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test', 'gateway' => 'offline',
        ])->assertRedirect();

        $order = $this->order($fixture);
        $user = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($user)
            ->postJson('/v1/orders/'.$order->id.'/refund', ['as_credit' => true])
            ->assertOk();

        $this->inTenant($fixture, function () {
            $voucher = Voucher::where('kind', 'credit')->firstOrFail();

            $this->assertSame('amina@example.test', $voucher->email);
            $this->assertSame(5000, (int) $voucher->amount);
            $this->assertNull($voucher->code);
        });
    }

    #[Test]
    public function credit_is_refused_on_a_part_refund_and_nothing_is_refunded(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);

        $this->hold($fixture, [0, 1])->assertCreated();
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test', 'gateway' => 'offline',
        ])->assertRedirect();

        $order = $this->order($fixture);
        $user = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($user)->postJson('/v1/orders/'.$order->id.'/refund', [
            'as_credit' => true,
            'seat_ids' => [$fixture['seats'][0]->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'credit_needs_whole_booking');

        // Refused before anything moved: the booking is untouched and no credit exists.
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->inTenant($fixture, fn () => $this->assertSame(0, Voucher::count()));
    }

    #[Test]
    public function refunding_as_credit_takes_more_than_the_refund_permission(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test', 'gateway' => 'offline',
        ])->assertRedirect();

        $order = $this->order($fixture);
        // A role of the organiser's own with `orders.refund` and nothing that issues money.
        $this->inTenant($fixture, fn () => \App\Models\TenantRole::create([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'refunder',
            'name' => 'Refunds only',
            'permissions' => ['orders.view', 'orders.refund'],
        ]));
        $user = $this->makeUser($fixture['tenant'], 'refunder');

        $this->actingAs($user)
            ->postJson('/v1/orders/'.$order->id.'/refund', ['as_credit' => true])
            ->assertForbidden();

        $this->assertSame('confirmed', $order->fresh()->status);

        // The control: the same person, the same booking, without asking for credit. If this were
        // forbidden too, the test above would be proving nothing about `vouchers.manage`.
        $this->actingAs($user)->postJson('/v1/orders/'.$order->id.'/refund', [])->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->inTenant($fixture, fn () => $this->assertSame(0, Voucher::count()));
    }

    #[Test]
    public function the_receipt_and_the_invoice_say_how_it_was_settled(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $site = $this->makeSite($fixture['tenant']);
        $this->inTenant($fixture, fn () => $site->update([
            'invoices_enabled' => true,
            'legal_name' => 'Northgate Theatre Ltd',
            'billing_address' => '1 Northgate, Town',
        ]));
        $voucher = $this->gift($fixture, 2000);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code]);
        $this->buy();

        $order = $this->order($fixture);

        $this->get('http://northgate.test/order/'.$order->external_order_id)
            ->assertOk()
            ->assertSee('paid with your voucher', false);

        // And the document an accountant files: the sale is still the sale, with the part that
        // was settled out of a voucher said underneath it rather than taken off the top.
        $this->inTenant($fixture, function () use ($site, $order) {
            $invoice = app(\App\Domain\Invoicing\InvoiceIssuer::class)->issue($site, $order->fresh());

            $this->assertSame(5000, (int) $invoice->totals['total']);
            $this->assertSame(2000, (int) $invoice->totals['voucher']);
            $this->assertSame(3000, (int) $invoice->totals['payable']);
        });
    }

    #[Test]
    public function the_settlement_says_which_part_never_reached_a_bank(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $voucher = $this->gift($fixture, 2000);

        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout/voucher', ['code' => $voucher->code]);
        $this->buy();

        $row = $this->inTenant(
            $fixture,
            fn () => app(\App\Domain\Settlement\Settlement::class)->forEvent($fixture['event'])
        );

        // The sale is €50 and the organiser is owed for all of it — €20 of it was simply taken
        // earlier, when somebody bought the voucher.
        $this->assertSame(5000, $row['charged']);
        $this->assertSame(2000, $row['voucher']);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function offer(array $fixture, string $code)
    {
        return $this->inTenant(
            $fixture,
            fn () => app(Vouchers::class)->offer($code, 'EUR', 100000)
        );
    }

    private function balance(array $fixture, Voucher $voucher): int
    {
        return $this->inTenant(
            $fixture,
            fn () => app(Vouchers::class)->balance($voucher->fresh())
        );
    }

    private function gift(array $fixture, int $amount, array $attributes = []): Voucher
    {
        return $this->inTenant($fixture, fn () => app(Vouchers::class)->issue($attributes + [
            'tenant_id' => $fixture['tenant']->id,
            'kind' => 'gift',
            'code' => Voucher::suggest(),
            'amount' => $amount,
            'currency' => 'EUR',
        ]));
    }

    private function credit(array $fixture, string $email, int $amount): Voucher
    {
        return $this->inTenant($fixture, fn () => app(Vouchers::class)->credit(
            $fixture['tenant']->id, $email, $amount, 'EUR'
        ));
    }

    private function inTenant(array $fixture, callable $work)
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], $work);
    }

    private function order(array $fixture): ExternalOrder
    {
        return $this->inTenant(
            $fixture,
            fn () => ExternalOrder::orderByDesc('created_at')->firstOrFail()
        );
    }

    /** @param  list<int>  $seats */
    private function hold(array $fixture, array $seats)
    {
        return $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ]);
    }

    private function buy(): void
    {
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
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
