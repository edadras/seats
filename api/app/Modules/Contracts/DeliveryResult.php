<?php

namespace App\Modules\Contracts;

/**
 * What happened to one message.
 *
 * Three states, and the distinction between the last two is the one that matters: a provider that
 * refused ("not a mobile number") should never be retried, and a provider that could not be reached
 * should always be. Collapsing them means either giving up on messages that would have gone, or
 * hammering a provider that has already said no.
 */
final class DeliveryResult
{
    private function __construct(
        public readonly string $status,      // sent|refused|unavailable
        public readonly ?string $reference,  // the provider's own id, for reconciling later
        public readonly ?string $reason,
    ) {}

    public static function sent(?string $reference = null): self
    {
        return new self('sent', $reference, null);
    }

    /** The provider understood and said no. Do not retry. */
    public static function refused(string $reason): self
    {
        return new self('refused', null, $reason);
    }

    /** The provider could not be reached, or broke. Retry. */
    public static function unavailable(string $reason): self
    {
        return new self('unavailable', null, $reason);
    }

    public function delivered(): bool
    {
        return 'sent' === $this->status;
    }

    public function retryable(): bool
    {
        return 'unavailable' === $this->status;
    }
}
