<?php

namespace App\Domain\Sites\Payments;

use App\Models\ExternalOrder;

/**
 * How a hosted site takes money.
 *
 * ADR-0003 moved the shop inside our boundary, and this interface is the seam that keeps the move
 * from spreading: nothing that knows about seats learns about card processing, and nothing here
 * learns about seats beyond an amount and an order to attach the outcome to.
 *
 * A driver returns either a redirect for the buyer to follow, or a settled result. Everything else
 * — webhooks, 3-D Secure, retries — is the driver's business.
 */
interface PaymentGateway
{
    public function key(): string;

    /** Shown on the checkout page. */
    public function label(): string;

    public function description(): string;

    /**
     * Begin taking payment for an order that has already been registered against its hold.
     *
     * @param  array{amount:int,currency:string,return_url:string,buyer:array}  $context
     */
    public function begin(ExternalOrder $order, array $context): PaymentIntent;

    /**
     * Settle whatever came back — a redirect return, a webhook body. Returns the intent's new
     * state; it must be safe to call twice with the same input, because both of those arrive twice.
     */
    public function settle(ExternalOrder $order, array $payload): PaymentIntent;
}
