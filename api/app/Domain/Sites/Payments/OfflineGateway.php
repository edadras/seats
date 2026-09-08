<?php

namespace App\Domain\Sites\Payments;

use App\Models\ExternalOrder;

/**
 * Pay at the box office.
 *
 * It settles nothing, and that is the point: it is a real way a great many venues sell — reserve
 * online, pay on the door — and it lets the whole hosted checkout be built and tested before any
 * gateway account exists.
 *
 * The seats are allocated and the tickets issued, exactly as for a card sale. What differs is only
 * that the money is collected elsewhere, which is a fact about the venue's till, not about the
 * inventory.
 */
class OfflineGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'offline';
    }

    public function label(): string
    {
        return 'Pay at the box office';
    }

    public function description(): string
    {
        return 'Your seats are reserved now. Pay when you collect your tickets.';
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        return PaymentIntent::paid('offline:'.$order->external_order_id);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        return PaymentIntent::paid('offline:'.$order->external_order_id);
    }
}
