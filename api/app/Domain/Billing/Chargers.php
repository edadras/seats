<?php

namespace App\Domain\Billing;

/**
 * Which charger this deployment uses to take its own money.
 *
 * One, chosen by configuration rather than per account, because it is the *platform's* merchant
 * relationship and there is only one platform. An organiser choosing their own gateway is a
 * different question with a different answer, and it lives in the module system.
 *
 * A deployment that has configured nothing still bills correctly: it raises invoices and somebody
 * pays them. That is the honest default — the alternative is a platform that silently does nothing
 * at all, which is where this started.
 */
class Chargers
{
    public function __construct(
        private readonly StripePlatformCharger $stripe,
        private readonly InvoiceOnlyCharger $invoice,
    ) {}

    public function current(): PlatformCharger
    {
        return 'card' === (string) config('seatmap.billing.mode', 'invoice') && $this->stripe->isConfigured()
            ? $this->stripe
            : $this->invoice;
    }

    /** Whether a card can be put on file at all, which decides whether the panel offers it. */
    public function takesCards(): bool
    {
        return $this->current() instanceof StripePlatformCharger;
    }
}
