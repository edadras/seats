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

    /**
     * Send money back to where it came from.
     *
     * `$amount` is in the currency's smallest unit and may be less than the whole charge: this
     * platform refunds a seat at a time, and four seats out of six is an ordinary Tuesday.
     * `$reference` is what `settle()` returned — the handle the gateway knows the payment by.
     *
     * A driver that cannot do this — cash, a bank transfer, an invoice — returns
     * `RefundOutcome::unsupported()` rather than failing. That is not the same event and must not
     * be reported as one: the seats still go back on sale and somebody hands the money over by
     * hand, which is what happens in a box office anyway.
     *
     * It must be safe to call twice with the same input. A gateway that has already refunded this
     * payment should say so — either as `sent` with the existing reference, or as `failed` with a
     * message a person can act on — and never take the money twice.
     */
    public function refund(ExternalOrder $order, int $amount, string $reference): RefundOutcome;
}
