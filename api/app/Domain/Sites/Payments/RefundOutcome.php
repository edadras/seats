<?php

namespace App\Domain\Sites\Payments;

/**
 * What happened when money was sent back.
 *
 * Three outcomes, and the middle one is the reason this is not a boolean. A card payment can be
 * refunded through the gateway that took it. Cash over the counter, a bank transfer, an invoice
 * paid by a school — those were never taken by a gateway and cannot be given back by one, and
 * calling that a failure would stop a box office from doing something perfectly ordinary. So
 * `unsupported` is its own answer: there is nothing to send, hand it over yourself, and the seats
 * still go back on sale.
 *
 * `failed` is the gateway saying no — already refunded, balance too low, a reference it does not
 * recognise. That one must stop everything: a booking cancelled while the money stayed put is the
 * worst of the outcomes available, because the buyer has neither their seat nor their money.
 */
final class RefundOutcome
{
    private function __construct(
        public readonly string $status,      // sent|unsupported|failed
        public readonly ?string $reference,
        public readonly ?string $message,
    ) {}

    /** The gateway took it back, and this is what it called the refund. */
    public static function sent(?string $reference = null): self
    {
        return new self('sent', $reference, null);
    }

    /** Nothing to send: this money never came through a gateway. */
    public static function unsupported(?string $message = null): self
    {
        return new self('unsupported', null, $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', null, $message);
    }

    public function wasSent(): bool
    {
        return 'sent' === $this->status;
    }

    public function hasFailed(): bool
    {
        return 'failed' === $this->status;
    }
}
