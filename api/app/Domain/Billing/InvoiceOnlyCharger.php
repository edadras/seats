<?php

namespace App\Domain\Billing;

use App\Models\BillingMethod;
use App\Models\PlatformInvoice;

/**
 * The platform sends an invoice and somebody pays it.
 *
 * The default, and a complete answer rather than a placeholder. A great many venues are public
 * bodies, universities and councils that cannot put a card on a form: they pay by transfer against
 * a purchase order, weeks later, and somebody reconciles a bank statement. A self-hosted deployment
 * billing three friendly customers is in exactly the same position.
 *
 * So this charger says `unsupported` — there is nothing to charge, nothing went wrong, and the
 * invoice waits. The dunning ladder treats that as "still owed" rather than "refused", which is the
 * difference between a reminder and a threat.
 */
class InvoiceOnlyCharger implements PlatformCharger
{
    public function key(): string
    {
        return 'invoice';
    }

    public function isConfigured(): bool
    {
        // Always. Writing an invoice down needs no credentials, which is why this is the default:
        // a deployment that has configured nothing still bills correctly, it just does not collect
        // automatically.
        return true;
    }

    public function setupUrl(BillingMethod $method, string $returnUrl): ?string
    {
        return null;
    }

    public function collectSetup(BillingMethod $method, array $payload): ?array
    {
        return null;
    }

    public function charge(PlatformInvoice $invoice, BillingMethod $method): ChargeOutcome
    {
        return ChargeOutcome::unsupported(__('billing.errors.pay_by_transfer'));
    }
}
