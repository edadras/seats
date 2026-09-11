<?php

namespace Tests\Support;

use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Domain\Sites\Payments\RefundOutcome;
use App\Models\ExternalOrder;

/**
 * A gateway that remembers what it was asked, and answers however the test says.
 *
 * Every real driver is tested against its own API's shapes where the module lives. What needs a
 * stand-in here is the platform's side of the bargain: that money is asked for before seats move,
 * that a refusal stops everything, and that the amount asked for is the amount that should have
 * been.
 */
class RecordingGateway implements PaymentGateway
{
    /** @var array<int, array{amount:int, reference:string, order:string}> */
    public array $asked = [];

    public function __construct(private readonly string $answer = 'sent') {}

    public function key(): string
    {
        return 'recording';
    }

    public function label(): string
    {
        return 'Recording';
    }

    public function description(): string
    {
        return 'A gateway that only exists inside a test.';
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        return PaymentIntent::paid('rec_'.$order->external_order_id);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        return PaymentIntent::paid('rec_'.$order->external_order_id);
    }

    public function refund(ExternalOrder $order, int $amount, string $reference): RefundOutcome
    {
        $this->asked[] = [
            'amount' => $amount,
            'reference' => $reference,
            'order' => $order->external_order_id,
        ];

        return match ($this->answer) {
            'failed' => RefundOutcome::failed('The card has expired.'),
            'unsupported' => RefundOutcome::unsupported('Not through this one.'),
            'throw' => throw new \RuntimeException('The gateway did not answer.'),
            default => RefundOutcome::sent('rec_refund_'.count($this->asked)),
        };
    }

    /** What every refund on this gateway came to. */
    public function total(): int
    {
        return array_sum(array_column($this->asked, 'amount'));
    }
}
