<?php

namespace Tests\Feature;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Dunning;
use App\Domain\Sites\SiteProvisioner;
use App\Models\BillingMethod;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The platform billing an organiser.
 *
 * Every other money test in this suite is about the organiser's money. This is the one about the
 * platform's, and it exists because there was none: an account signed up, a subscription period was
 * written down, the period passed, and not one thing happened. No invoice, no charge, no notice.
 * The price list was a marketing table.
 */
class PlatformBillingTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_finished_period_becomes_an_invoice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);

        // A month goes by.
        $this->travel(35)->days();

        $made = app(Billing::class)->catchUp($fixture['tenant']);

        $this->assertCount(1, $made);
        $this->assertSame(4900, $made[0]->subscription_amount);
        $this->assertSame(4900, $made[0]->total);
        $this->assertSame('open', $made[0]->status);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{5}$/', $made[0]->number);
    }

    #[Test]
    public function a_period_that_has_not_finished_is_not_invoiced(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);

        $this->travel(5)->days();

        $this->assertSame([], app(Billing::class)->catchUp($fixture['tenant']),
            'Billed in arrears: a period is invoiced when it is over, never partway through.');
    }

    #[Test]
    public function the_commission_on_what_they_sold_is_on_the_same_invoice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->plan($fixture['tenant'], price: 0, commission: 1000);

        $this->buy($fixture, [0, 1]);

        $this->travel(35)->days();

        $invoice = app(Billing::class)->catchUp($fixture['tenant'])[0];

        // Two seats at 2500, ten per cent of what the organiser kept.
        $this->assertSame(500, $invoice->commission_amount);
        $this->assertSame(500, $invoice->total);
        $this->assertSame('commission', $invoice->lines[0]['kind']);
        // The figure the percentage was taken of, so it can be checked against the organiser's own
        // settlement screen rather than taken on trust.
        $this->assertSame(5000, $invoice->lines[0]['basis']);
    }

    #[Test]
    public function the_same_period_cannot_be_invoiced_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);

        $this->travel(35)->days();

        $first = app(Billing::class)->catchUp($fixture['tenant'])[0];

        // Written straight past the domain, the way a second worker or a retried job arrives.
        $this->expectException(\Illuminate\Database\QueryException::class);

        PlatformInvoice::create([
            'tenant_id' => $fixture['tenant']->id,
            'number' => '9999-00001',
            'currency' => 'EUR',
            'period_from' => $first->period_from->toDateString(),
            'period_to' => $first->period_to->toDateString(),
            'total' => 4900,
            'due_on' => now()->toDateString(),
        ]);
    }

    #[Test]
    public function four_missed_months_are_four_invoices_and_not_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);

        // Nobody ran the billing for a third of a year.
        $this->travel(130)->days();

        $made = app(Billing::class)->catchUp($fixture['tenant']);

        $this->assertCount(4, $made,
            'Each month was its own price and its own commission; rolling them together makes a figure nobody can check.');
        /*
         * Numbered sequentially, per year.
         *
         * The last of these four periods ends in the new year, so its number starts a new book —
         * which is what an accountant expects and what several tax authorities require.
         */
        foreach ($made as $invoice) {
            $this->assertSame(
                $invoice->period_to->format('Y'),
                substr($invoice->number, 0, 4),
                'An invoice is numbered in the year its period ended.',
            );
        }

        $this->assertCount(4, array_unique(array_map(
            fn (PlatformInvoice $invoice) => $invoice->number,
            $made,
        )), 'And no number is ever reused.');
    }

    #[Test]
    public function an_account_still_in_its_trial_is_not_billed(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Subscription::query()
            ->update(['status' => 'trialing', 'trial_ends_at' => now()->addMonths(3)]));

        $this->travel(35)->days();

        $this->assertSame([], app(Billing::class)->catchUp($fixture['tenant']), 'That is what a trial is.');
    }

    #[Test]
    public function an_account_that_pays_by_transfer_is_never_told_a_payment_failed(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);
        $this->travel(35)->days();

        $invoice = app(Billing::class)->catchUp($fixture['tenant'])[0];

        $after = app(Dunning::class)->collect($invoice);

        $this->assertSame('open', $after->status, 'Owed, and waiting for somebody to pay it.');
        $this->assertNull($after->next_attempt_at, 'There is nothing to retry.');
        $this->assertSame(0, Notification::where('kind', 'billing.payment_failed')->count(),
            'Nothing was charged, so nothing failed.');
    }

    #[Test]
    public function a_card_on_file_is_charged_and_the_invoice_is_settled(): void
    {
        config(['seatmap.billing.mode' => 'card', 'seatmap.billing.stripe.secret_key' => 'sk_test']);

        Http::fake(['*payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'succeeded'])]);

        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);
        $this->card($fixture['tenant']);
        $this->travel(35)->days();

        $invoice = app(Billing::class)->catchUp($fixture['tenant'])[0];
        $after = app(Dunning::class)->collect($invoice);

        $this->assertSame('paid', $after->status);
        $this->assertSame('stripe:pi_1', $after->reference);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'payment_intents')) {
                return true;
            }

            // Off-session, confirmed in the same call, and keyed on the invoice so a retried job
            // cannot take the money twice.
            return '4900' === (string) $request->data()['amount']
                && 'true' === $request->data()['off_session']
                && 'true' === $request->data()['confirm']
                && str_starts_with($request->header('Idempotency-Key')[0], 'platform-invoice-');
        });
    }

    #[Test]
    public function a_refused_card_climbs_a_ladder_rather_than_giving_up(): void
    {
        config(['seatmap.billing.mode' => 'card', 'seatmap.billing.stripe.secret_key' => 'sk_test']);

        Http::fake(['*payment_intents' => Http::response([
            'error' => ['code' => 'card_declined', 'message' => 'Your card has insufficient funds.'],
        ], 402)]);

        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);
        $this->card($fixture['tenant']);
        $this->travel(35)->days();

        $invoice = app(Dunning::class)->collect(app(Billing::class)->catchUp($fixture['tenant'])[0]);

        $this->assertSame('open', $invoice->status);
        $this->assertNotNull($invoice->next_attempt_at, 'A card refused for no money today may have money on Friday.');
        $this->assertSame('Your card has insufficient funds.', $invoice->last_error,
            'The gateway’s own sentence, which is more use than anything we could write about a code.');

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => $this->assertSame(
            1,
            Notification::where('kind', 'billing.payment_failed')->count(),
        ));
    }

    #[Test]
    public function a_ladder_that_runs_out_marks_the_account_past_due_and_stops_there(): void
    {
        config([
            'seatmap.billing.mode' => 'card',
            'seatmap.billing.stripe.secret_key' => 'sk_test',
            'seatmap.billing.retry_days' => [0, 3],
        ]);

        Http::fake(['*payment_intents' => Http::response([
            'error' => ['code' => 'card_declined', 'message' => 'Refused.'],
        ], 402)]);

        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);
        $this->card($fixture['tenant']);
        $this->travel(35)->days();

        $invoice = app(Billing::class)->catchUp($fixture['tenant'])[0];

        foreach ([1, 2] as $attempt) {
            $invoice = app(Dunning::class)->collect($invoice->fresh());
            $this->travel(4)->days();
        }

        $this->assertSame('uncollectible', $invoice->status);
        $this->assertNull($invoice->next_attempt_at);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $subscription = Subscription::firstOrFail();

            $this->assertSame('past_due', $subscription->status);
            $this->assertNotNull($subscription->past_due_since);
        });

        /*
         * And the account still works.
         *
         * Taking a venue's box office down on the night of a show over an unpaid invoice is a
         * decision with a full house on the other end of it. Billing raises the flag; a person
         * decides what to do about it.
         */
        $this->assertSame('active', $fixture['tenant']->fresh()->status);
    }

    #[Test]
    public function paying_brings_an_account_back_into_good_standing(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);
        $this->travel(35)->days();

        $invoice = app(Billing::class)->catchUp($fixture['tenant'])[0];

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Subscription::query()
            ->update(['status' => 'past_due', 'past_due_since' => now()->subWeek()]));

        app(Billing::class)->markPaid($invoice, ['method' => 'transfer', 'reference' => 'SEPA-3']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $subscription = Subscription::firstOrFail();

            $this->assertSame('active', $subscription->status);
            $this->assertNull($subscription->past_due_since);
        });
    }

    #[Test]
    public function voiding_one_frees_its_period_and_keeps_the_row(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);
        $this->travel(35)->days();

        $first = app(Billing::class)->catchUp($fixture['tenant'])[0];

        app(Billing::class)->void($first, 'Wrong plan on the account.');

        // The period is free again, so the right invoice can be raised over it.
        $second = app(Billing::class)->raise(
            $fixture['tenant'],
            $first->period_from,
            $first->period_to,
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, PlatformInvoice::count(), 'Voided, not deleted: a number that was quoted still exists.');
    }

    #[Test]
    public function the_organiser_sees_what_they_owe_and_how_they_pay(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->plan($fixture['tenant'], price: 4900);
        $this->travel(35)->days();

        app(Billing::class)->catchUp($fixture['tenant']);

        $body = $this->actingAs($owner)->getJson('/v1/billing')->assertOk()->json();

        $this->assertSame(4900, $body['owed']);
        $this->assertCount(1, $body['invoices']);
        $this->assertFalse($body['takes_cards'], 'This deployment takes transfers, and says so.');
    }

    #[Test]
    public function a_box_office_manager_is_not_shown_the_accounts_card(): void
    {
        $fixture = $this->makeSellableEvent();
        $clerk = $this->makeUser($fixture['tenant'], 'boxoffice');

        // Refunding a booking is not the same authority as seeing what the account pays for the
        // software, or replacing the card it pays with.
        $this->actingAs($clerk)->getJson('/v1/billing')->assertForbidden();
        $this->actingAs($clerk)->postJson('/v1/billing/invoice-me', [])->assertForbidden();
    }

    #[Test]
    public function an_account_can_say_it_would_rather_be_invoiced(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/billing/invoice-me', [
            'billing_name' => 'Northgate Theatre Trust',
            'billing_email' => 'accounts@northgate.test',
            'vat_number' => 'GB123456789',
        ])->assertOk()->assertJsonPath('kind', 'invoice');

        $method = BillingMethod::where('tenant_id', $fixture['tenant']->id)->firstOrFail();

        $this->assertSame('invoice', $method->kind);
        $this->assertSame('GB123456789', $method->vat_number);
        // Nothing is kept that we have undertaken not to use.
        $this->assertNull($method->method_reference);
    }

    #[Test]
    public function a_card_cannot_be_added_where_the_platform_takes_none(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/billing/card/setup', [
            'return_url' => 'https://northgate.test/billing',
        ])->assertStatus(422)->assertJsonPath('error.code', 'cards_not_taken');
    }

    #[Test]
    public function the_console_sees_every_invoice_and_only_an_operator_settles_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->plan($fixture['tenant'], price: 4900);
        $this->travel(35)->days();

        $invoice = app(Billing::class)->catchUp($fixture['tenant'])[0];

        $support = $this->admin('support@platform.test', 'support');

        $listed = $this->actingAs($support)->getJson('/v1/admin/invoices')->assertOk()->json();

        $this->assertCount(1, $listed['data']);
        $this->assertSame(4900, $listed['outstanding']);

        $this->actingAs($support)
            ->postJson('/v1/admin/invoices/'.$invoice->id.'/paid', [])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'support_may_not_change');

        $operator = $this->admin('operator@platform.test', 'operator');

        $this->actingAs($operator)
            ->postJson('/v1/admin/invoices/'.$invoice->id.'/paid', [
                'method' => 'transfer',
                'reference' => 'SEPA-9',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('reference', 'SEPA-9');
    }

    #[Test]
    public function an_organisers_owner_cannot_reach_the_invoice_console(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // Not found rather than forbidden: who runs the platform is not discoverable by trying.
        $this->actingAs($owner)->getJson('/v1/admin/invoices')->assertNotFound();
    }

    #[Test]
    public function an_organiser_reads_their_own_invoices_and_nobody_elses(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $other = $this->makeSellableEvent($this->makeTenant('Riverside'));

        $this->plan($fixture['tenant'], price: 4900);
        $this->plan($other['tenant'], price: 19900);
        $this->travel(35)->days();

        app(Billing::class)->catchUp($fixture['tenant']);
        $theirs = app(Billing::class)->catchUp($other['tenant'])[0];

        $body = $this->actingAs($owner)->getJson('/v1/billing')->assertOk()->json();

        $this->assertCount(1, $body['invoices']);
        $this->assertSame(4900, $body['invoices'][0]['total']);

        $this->actingAs($owner)
            ->getJson('/v1/billing/invoices/'.$theirs->id)
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function plan($tenant, int $price, int $commission = 0): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($price, $commission) {
            $subscription = Subscription::orderByDesc('created_at')->firstOrFail();

            Plan::whereKey($subscription->plan_id)->update([
                'price_amount' => $price,
                'commission_rate' => $commission,
                'currency' => 'EUR',
                'interval' => 'month',
            ]);

            $subscription->forceFill([
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => now(),
                'last_invoiced_to' => null,
            ])->save();
        });
    }

    private function card($tenant): BillingMethod
    {
        return BillingMethod::create([
            'tenant_id' => $tenant->id,
            'kind' => 'card',
            'gateway' => 'stripe',
            'customer_reference' => 'cus_1',
            'method_reference' => 'pm_1',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(3)->format('Y'),
            'is_default' => true,
        ]);
    }

    private function admin(string $email, string $level): User
    {
        $user = User::firstWhere('email', $email) ?? User::factory()->create([
            'email' => $email,
            'password' => Hash::make('correct horse battery'),
        ]);

        PlatformAdmin::firstOrCreate(['user_id' => $user->id], ['level' => $level]);

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
