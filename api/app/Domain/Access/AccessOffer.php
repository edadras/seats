<?php

namespace App\Domain\Access;

use App\Models\AccessCode;

/**
 * What a buyer is told about the code they typed.
 *
 * A refusal carries a reason rather than a bare no, because "that code is not right" and "that
 * code was for the presale, which opens on Friday" send a person to two different next steps, and
 * only one of them is the telephone.
 */
final class AccessOffer
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?AccessCode $code,
        public readonly string $reason,
    ) {}

    public static function accepted(AccessCode $code): self
    {
        return new self(true, $code, '');
    }

    public static function refused(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
