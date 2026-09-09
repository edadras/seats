<?php

namespace App\Domain\Discounts;

use App\Models\DiscountCode;

/**
 * The answer to "may this buyer use this code, and what is it worth".
 *
 * A refusal carries a reason key rather than a sentence, because the sentence has to be written in
 * the buyer's language on a hosted site and in the caller's language over the API, and a domain
 * service does not know which of those it is talking to.
 */
final class DiscountOffer
{
    private function __construct(
        public readonly ?DiscountCode $code,
        public readonly int $amount,
        public readonly ?string $reason,
    ) {}

    public static function allowed(DiscountCode $code, int $amount): self
    {
        return new self($code, $amount, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, 0, $reason);
    }

    public function isAllowed(): bool
    {
        return null === $this->reason && null !== $this->code;
    }
}
