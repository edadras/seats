<?php

namespace Tests\Feature;

use App\Domain\Invoicing\InvoiceIssuer;
use App\Domain\Sites\SiteProvisioner;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Invoices: a document the buyer's accounts department will take.
 *
 * What matters is that a number, once issued, never changes and never repeats, and that the
 * document says what was true at the moment it was issued rather than what is true now. An
 * organiser who corrects their address must not silently rewrite something already filed.
 */
class InvoiceTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_site_that_has_not_filled_its_details_in_offers_nothing(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->makeSite($fixture['tenant']);

        // Switched on but with no address: an invoice with no issuing entity is not a document.
        $this->settings($site, ['invoices_enabled' => true, 'legal_name' => 'Northgate Ltd']);

        $reference = $this->buy($fixture);

        $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertNotFound();
    }

    #[Test]
    public function a_buyer_who_asks_for_one_gets_it(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $site = $this->invoicingSite($fixture['tenant']);

        $reference = $this->buy($fixture, [
            'invoice' => 1,
            'company' => 'Aurora Films BV',
            'tax_number' => 'NL123456789B01',
            'billing_address' => "Keizersgracht 1\n1015 Amsterdam",
        ]);

        $response = $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('invoice-NGT-', $response->headers->get('content-disposition'));

        $invoice = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Invoice::firstOrFail()
        );

        $this->assertSame('Aurora Films BV', $invoice->buyer['name']);
        $this->assertSame('NL123456789B01', $invoice->buyer['tax_number']);
        $this->assertSame('Northgate Theatre Ltd', $invoice->issuer['name']);
        $this->assertSame(5000, $invoice->totals['total']);
    }

    #[Test]
    public function the_number_is_issued_once_and_never_changes(): void
    {
        $fixture = $this->makeSellableEvent();
        $site = $this->invoicingSite($fixture['tenant']);

        $reference = $this->buy($fixture, ['invoice' => 1, 'company' => 'A Company']);

        $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertOk();
        $first = $this->invoiceOf($fixture['tenant'])->number;

        // Asked for again — the same document, not a second number.
        $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertOk();

        $this->assertSame(1, app(TenantContext::class)->runAs(
            $fixture['tenant'], fn () => Invoice::count()
        ));
        $this->assertSame($first, $this->invoiceOf($fixture['tenant'])->number);

        // And the organiser correcting their address does not rewrite it.
        $this->settings($site, ['legal_name' => 'Northgate Theatre PLC']);

        $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertOk();

        $this->assertSame('Northgate Theatre Ltd', $this->invoiceOf($fixture['tenant'])->issuer['name']);
    }

    #[Test]
    public function numbers_run_in_order_within_a_site_and_a_year(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->invoicingSite($fixture['tenant']);

        $numbers = [];

        foreach ([0, 1, 2] as $index) {
            $this->flushSession();
            $reference = $this->buy($fixture, ['invoice' => 1, 'company' => 'Buyer '.$index], $index);

            $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertOk();

            $numbers[] = app(TenantContext::class)->runAs(
                $fixture['tenant'],
                fn () => Invoice::orderByDesc('sequence')->firstOrFail()->number
            );
        }

        $year = now()->format('Y');

        $this->assertSame([
            "NGT-{$year}-0001", "NGT-{$year}-0002", "NGT-{$year}-0003",
        ], $numbers);
    }

    #[Test]
    public function an_unpaid_booking_never_takes_a_number(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->invoicingSite($fixture['tenant']);

        $reference = $this->buy($fixture, ['invoice' => 1, 'company' => 'A Company']);

        $order = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::where('external_order_id', $reference)->firstOrFail()
        );

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $order->forceFill(['status' => 'cancelled'])->save()
        );

        // A number given to a booking that was never paid for is a gap in a sequence that somebody
        // later has to explain to an auditor.
        $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertNotFound();

        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'], fn () => Invoice::count()
        ));
    }

    #[Test]
    public function somebody_elses_order_is_not_reachable(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->invoicingSite($fixture['tenant']);

        $reference = $this->buy($fixture, ['invoice' => 1, 'company' => 'A Company']);

        // A new browser, holding only the reference printed on the confirmation page.
        $this->flushSession();

        $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertNotFound();
    }

    #[Test]
    public function the_lines_are_grouped_the_way_a_person_would_write_them(): void
    {
        $fixture = $this->makeSellableEvent(amount: 2500);
        $this->invoicingSite($fixture['tenant']);

        $reference = $this->buy($fixture, ['invoice' => 1, 'company' => 'A Coach Party'], 0, 4);

        $this->get('http://northgate.test/order/'.$reference.'/invoice')->assertOk();

        $lines = $this->invoiceOf($fixture['tenant'])->lines;

        // Four seats at one price is one line of four, not four lines of one.
        $this->assertCount(1, $lines);
        $this->assertSame(4, $lines[0]['quantity']);
        $this->assertSame(10000, $lines[0]['amount']);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function invoiceOf($tenant): Invoice
    {
        return app(TenantContext::class)->runAs($tenant, fn () => Invoice::firstOrFail());
    }

    private function settings(Site $site, array $attributes): void
    {
        $site->forceFill($attributes)->save();
    }

    private function invoicingSite($tenant): Site
    {
        $site = $this->makeSite($tenant);

        $this->settings($site, [
            'invoices_enabled' => true,
            'legal_name' => 'Northgate Theatre Ltd',
            'tax_number' => 'GB123456789',
            'billing_address' => "12 Northgate\nYork YO1 1AA",
            'invoice_prefix' => 'NGT',
        ]);

        return $site->fresh();
    }

    private function buy(array $fixture, array $extra = [], int $from = 0, int $seats = 1): string
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(
                fn (int $i) => $fixture['seats'][$i]->id,
                range($from, $from + $seats - 1)
            ),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ] + $extra)->assertRedirect();

        return (string) session('seatmap_order');
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
