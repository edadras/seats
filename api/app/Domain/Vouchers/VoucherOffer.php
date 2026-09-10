<?php

namespace App\Domain\Vouchers;

use App\Models\Voucher;

/**
 * The answer to "may this buyer spend this voucher here, and how much of it".
 *
 * A refusal carries a reason key rather than a sentence, for the same reason a discount's does: the
 * sentence has to be written in the buyer's language on a hosted site and in the caller's language
 * over the API, and a domain service does not know which of those it is talking to.
 *
 * `amount` is not the balance. It is what this booking can actually take off it — a fifty-euro
 * voucher against a twenty-euro booking spends twenty, and the other thirty stays where it is.
 */
final class VoucherOffer
{
    private function __construct(
        public readonly ?Voucher $voucher,
        public readonly int $balance,
        public readonly int $amount,
        public readonly ?string $reason,
    ) {}

    public static function allowed(Voucher $voucher, int $balance, int $amount): self
    {
        return new self($voucher, $balance, $amount, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, 0, 0, $reason);
    }

    public function isAllowed(): bool
    {
        return null === $this->reason && null !== $this->voucher;
    }

    /** What is left after this booking has taken its share. Shown back on the checkout. */
    public function remaining(): int
    {
        return max(0, $this->balance - $this->amount);
    }
}
