<?php

namespace App\Domain\Sites\Payments;

/** What a gateway wants to happen next: send the buyer somewhere, or nothing — it is done. */
final class PaymentIntent
{
    private function __construct(
        public readonly string $status,      // pending|paid|failed
        public readonly ?string $redirectUrl,
        public readonly ?string $reference,
        public readonly ?string $message,
    ) {}

    public static function paid(?string $reference = null): self
    {
        return new self('paid', null, $reference, null);
    }

    public static function redirect(string $url, ?string $reference = null): self
    {
        return new self('pending', $url, $reference, null);
    }

    public static function pending(?string $reference = null): self
    {
        return new self('pending', null, $reference, null);
    }

    public static function failed(string $message): self
    {
        return new self('failed', null, null, $message);
    }

    public function isPaid(): bool
    {
        return 'paid' === $this->status;
    }

    public function hasFailed(): bool
    {
        return 'failed' === $this->status;
    }
}
