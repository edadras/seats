<?php

namespace Modules\Seatmap\OfflinePayments;

use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Domain\Sites\Payments\RefundOutcome;
use App\Models\ExternalOrder;
use App\Modules\ModuleContext;

/**
 * Reserve online, pay on the door.
 *
 * It settles nothing, and that is the point: it is a real way a great many venues sell, and it lets
 * the whole hosted checkout be built and tested before any gateway account exists.
 *
 * The seats are allocated and the tickets issued, exactly as for a card sale. What differs is only
 * that the money is collected elsewhere — a fact about the venue's till, not about the inventory.
 */
class OfflineGateway implements PaymentGateway
{
    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'offline';
    }

    public function label(): string
    {
        return __('payments.offline.label');
    }

    public function description(): string
    {
        // An organiser can say where and when, because "pay at the box office" raises a different
        // question at a venue that opens an hour before than at one with a shop open all week.
        $instructions = $this->context->setting('instructions');

        return is_string($instructions) && '' !== trim($instructions)
            ? $instructions
            : __('payments.offline.description');
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        return PaymentIntent::paid('offline:'.$order->external_order_id);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        return PaymentIntent::paid('offline:'.$order->external_order_id);
    }

    /**
     * There was never anything to send back.
     *
     * This gateway is cash in a drawer, a transfer into the venue's account, an invoice a school
     * will pay in March. None of it moved through anything this platform can reach, so a refund is
     * a person handing money to another person. Said as `unsupported`, which releases the seats
     * and writes down that the money is owed — the two things a box office actually needs.
     */
    public function refund(ExternalOrder $order, int $amount, string $reference): RefundOutcome
    {
        return RefundOutcome::unsupported(__('payments.errors.refund_by_hand'));
    }
}
