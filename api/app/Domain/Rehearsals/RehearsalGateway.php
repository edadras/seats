<?php

namespace App\Domain\Rehearsals;

use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Domain\Sites\Payments\RefundOutcome;
use App\Models\ExternalOrder;

/**
 * The way a rehearsal pays: it doesn't.
 *
 * Not a module and not in the {@see \App\Domain\Sites\Payments\GatewayRegistry}, and both of those
 * are on purpose. A registry entry is something a site can offer, and a gateway that settles
 * anything for nothing must never be offerable — a registry that held this would be one forgotten
 * checkbox away from giving the house away. So the only way to reach it is to be a night the
 * organiser has explicitly marked as a rehearsal, and the checkout reaches for it directly.
 *
 * It settles rather than redirects, because what is being rehearsed is everything after the money:
 * the allocation, the ticket, the email, the QR code at the door. A redirect to a fake bank would
 * rehearse the one part of the path nobody needs to see.
 */
class RehearsalGateway implements PaymentGateway
{
    public const KEY = 'rehearsal';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('payments.rehearsal.label');
    }

    public function description(): string
    {
        return __('payments.rehearsal.description');
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        return PaymentIntent::paid($this->reference($order));
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        return PaymentIntent::paid($this->reference($order));
    }

    /**
     * Giving back nothing, and saying so as a success.
     *
     * `unsupported` would be the wrong answer even though no money moves: that outcome tells a box
     * office to hand the cash over itself, and there is no cash. A rehearsed refund has to reach
     * the same screens a real one does — seats back, refund recorded, nothing owed to anybody —
     * which is exactly what `sent` means here.
     */
    public function refund(ExternalOrder $order, int $amount, string $reference): RefundOutcome
    {
        return RefundOutcome::sent($this->reference($order).':back');
    }

    /** Unmistakable in the order's metadata, on the refund row and in any export of either. */
    private function reference(ExternalOrder $order): string
    {
        return self::KEY.':'.$order->external_order_id;
    }
}
