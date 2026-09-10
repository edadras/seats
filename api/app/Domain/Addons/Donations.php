<?php

namespace App\Domain\Addons;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Support\Locale\Money;

/**
 * Money given for nothing, which is the whole of why it is not an add-on.
 *
 * A donation has no price and no stock, because the giver decides both. What it also has is no
 * booking fee and no tax:
 *
 *   a fee on a donation is charging somebody for the privilege of giving you money;
 *   whether a gift is taxable is a question for the organiser's accountant and not for a checkout.
 *
 * That is why the amount is carried in its own column rather than as a line among the add-ons,
 * where it would be swept into the subtotal the fee and the VAT are computed from. Everything in
 * this file exists to keep it out of that subtotal.
 */
class Donations
{
    /** A donation is never more than this, because a mistyped amount is not a gift. */
    public const CEILING = 100_000_000;

    public function offered(Event $event): bool
    {
        return (bool) $event->donations;
    }

    /**
     * What the checkout should put in the box, and what to say above it.
     *
     * A suggestion is a number the buyer may overwrite, not a price. Zero suggested is a real
     * choice — "ask, but suggest nothing" is what a charity that does not want to anchor asks for.
     *
     * `suggested` is minor units, like every stored amount in this system. `typed` is what belongs
     * in a box labelled "amount in EUR" — three, not three hundred — because a buyer typing 3 into
     * that box means three euros, and reading it as three cents is a hundredfold insult.
     *
     * @return array{offered: bool, prompt: ?string, suggested: ?int, typed: ?string, step: string}|null
     */
    public function prompt(Event $event): ?array
    {
        if (! $this->offered($event)) {
            return null;
        }

        $currency = (string) $event->currency;
        $suggested = null === $event->donation_suggested
            ? null
            : max(0, (int) $event->donation_suggested);
        $places = Money::exponent($currency);

        return [
            'offered' => true,
            'prompt' => $event->donation_prompt ?: null,
            'suggested' => $suggested,
            'typed' => null === $suggested
                ? null
                : number_format(Money::toDecimal($suggested, $currency), $places, '.', ''),
            // 0.01 for a euro, 1 for a rial: a rial box that steps in hundredths invites somebody
            // to type a hundredth of a rial, which does not exist.
            'step' => $places ? number_format(10 ** -$places, $places, '.', '') : '1',
        ];
    }

    /**
     * What a buyer typed, as an amount this booking may carry.
     *
     * The box is labelled in the event's currency and the buyer types in it, so "3" is three euros
     * — converted here, once, through the same exponent table every other amount goes through.
     *
     * Zero on an event that asks for nothing, and zero on an event that asks and got no answer —
     * a checkout must not refuse a booking because somebody left the donation box empty.
     *
     * @throws ApiException when the amount is not one anybody meant to type
     */
    public function amount(Event $event, mixed $typed): int
    {
        if (! $this->offered($event)) {
            return 0;
        }

        $amount = Money::toMinorUnits((float) ($typed ?? 0), (string) $event->currency);

        if ($amount < 0) {
            throw ApiException::unprocessable('donation_negative', 'A donation cannot be less than nothing.');
        }

        if ($amount > self::CEILING) {
            throw ApiException::unprocessable(
                'donation_too_large',
                'That is more than this checkout will take. Please talk to the organiser.',
            );
        }

        return $amount;
    }
}
