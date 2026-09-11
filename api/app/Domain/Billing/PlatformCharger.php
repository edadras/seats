<?php

namespace App\Domain\Billing;

use App\Models\BillingMethod;
use App\Models\PlatformInvoice;

/**
 * How the platform takes its own money.
 *
 * Deliberately not `App\Domain\Sites\Payments\PaymentGateway`, and the difference matters. That
 * interface is an *organiser's* gateway: it carries their credentials and takes money into their
 * account, and it is configured per tenant through a module. This one is the platform's, configured
 * once by whoever deploys it, and money moves the other way. Sharing an interface between them is
 * how an organiser's key ends up charging for the platform's invoices.
 */
interface PlatformCharger
{
    public function key(): string;

    /** Whether this deployment can charge a card at all. */
    public function isConfigured(): bool;

    /**
     * Somewhere for an organiser to put a card, on the gateway's own page.
     *
     * A hosted page rather than fields on ours: the platform has no more business holding card
     * numbers than an organiser does. Returns the URL to send them to, or null if this charger
     * cannot take a card.
     */
    public function setupUrl(BillingMethod $method, string $returnUrl): ?string;

    /**
     * Read back what the buyer set up, after they come back from that page.
     *
     * Returns the fields to write on the billing method — the handles and the four digits — or
     * null when the gateway will not say, which is a setup that did not finish.
     *
     * @return array<string, mixed>|null
     */
    public function collectSetup(BillingMethod $method, array $payload): ?array;

    /**
     * Charge the card on file, off-session.
     *
     * Off-session is the whole difficulty: nobody is at a keyboard to answer a bank's challenge, so
     * a card that needs one fails here and the organiser has to be written to. That is a refusal
     * with a message a person can act on, not an error.
     *
     * It must be safe to call twice with the same invoice. A dunning ladder retries, a job is
     * redelivered, an operator presses the button — and none of those may take the money twice.
     */
    public function charge(PlatformInvoice $invoice, BillingMethod $method): ChargeOutcome;
}
