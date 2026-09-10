<?php

namespace App\Domain\Risk;

use App\Exceptions\ApiException;
use App\Models\BlockedBuyer;

/**
 * Who may not buy, checked before the money rather than at the door.
 *
 * Two rules that between them decide whether this is a feature or a liability.
 *
 * **A block lapses on its own.** Most blocks should be six months rather than for ever, and a date
 * that has to be cleared by hand is a date somebody forgets — so a block with a date on it stops
 * applying because the date passed, exactly as every other deadline here does.
 *
 * **A refusal says so.** A checkout that silently fails is a person telephoning the box office and
 * a member of staff who cannot see why. The buyer is told they cannot book and to get in touch;
 * the reason itself stays inside, because it is somebody's note about a person and not a message.
 */
class Blocklist
{
    public function find(?string $email, ?string $phone = null): ?BlockedBuyer
    {
        $email = $this->tidy($email);
        $phone = $this->tidy($phone);

        if (null === $email && null === $phone) {
            return null;
        }

        return BlockedBuyer::query()
            ->where(function ($query) use ($email, $phone) {
                if (null !== $email) {
                    $query->orWhere('email', $email);
                }

                if (null !== $phone) {
                    $query->orWhere('phone', $phone);
                }
            })
            // Lapsed blocks are left in the table on purpose: "this happened once" is worth being
            // able to read when it happens a second time.
            ->where(fn ($query) => $query->whereNull('until')->orWhere('until', '>', now()))
            ->first();
    }

    public function blocks(?string $email, ?string $phone = null): bool
    {
        return null !== $this->find($email, $phone);
    }

    /** Refuse a booking, without handing the reason to the person it is about. */
    public function assertNotBlocked(?string $email, ?string $phone = null): void
    {
        if ($this->blocks($email, $phone)) {
            throw ApiException::conflict(
                'buyer_blocked',
                'This account cannot book for this organiser. Please contact the box office.',
            );
        }
    }

    /**
     * Put somebody on the list, or move the date on a block they already have.
     *
     * Not a second row: a person blocked twice is one person, and two rows would be two answers to
     * whether they may buy.
     */
    public function add(
        ?string $email,
        ?string $phone,
        string $reason,
        ?\DateTimeInterface $until = null,
        ?string $by = null,
    ): BlockedBuyer {
        $email = $this->tidy($email);
        $phone = $this->tidy($phone);

        if (null === $email && null === $phone) {
            throw ApiException::unprocessable(
                'block_needs_somebody',
                'A block needs an email address or a telephone number.',
            );
        }

        $existing = BlockedBuyer::query()
            ->when($email, fn ($query) => $query->orWhere('email', $email))
            ->when($phone, fn ($query) => $query->orWhere('phone', $phone))
            ->first();

        if ($existing) {
            $existing->forceFill([
                'email' => $email ?: $existing->email,
                'phone' => $phone ?: $existing->phone,
                'reason' => $reason,
                'until' => $until,
            ])->save();

            return $existing->fresh();
        }

        return BlockedBuyer::create([
            'email' => $email,
            'phone' => $phone,
            'reason' => $reason,
            'until' => $until,
            'created_by' => $by,
        ]);
    }

    private function tidy(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return '' === $value ? null : $value;
    }
}
