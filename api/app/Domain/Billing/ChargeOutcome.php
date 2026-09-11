<?php

namespace App\Domain\Billing;

/**
 * What happened when the platform tried to take its own money.
 *
 * Three answers, and the middle one is why this is not a boolean. `unsupported` is an account that
 * pays by transfer: there is nothing to charge, nothing went wrong, and the invoice simply waits
 * for somebody to pay it. Treating that as a failure would start a dunning ladder against a venue
 * that is doing exactly what it agreed to.
 */
final class ChargeOutcome
{
    private function __construct(
        public readonly string $status,      // paid|unsupported|failed
        public readonly ?string $reference,
        public readonly ?string $message,
    ) {}

    public static function paid(?string $reference = null): self
    {
        return new self('paid', $reference, null);
    }

    /** Nothing to charge: this account pays by transfer, or no card is on file. */
    public static function unsupported(?string $message = null): self
    {
        return new self('unsupported', null, $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', null, $message);
    }

    public function wasPaid(): bool
    {
        return 'paid' === $this->status;
    }

    public function hasFailed(): bool
    {
        return 'failed' === $this->status;
    }
}
