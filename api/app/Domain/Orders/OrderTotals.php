<?php

namespace App\Domain\Orders;

use App\Models\Event;

/**
 * What a booking adds up to: tickets, less any discount, plus add-ons, plus a fee, plus tax, plus
 * whatever was given.
 *
 * One place computes this, and it is read from the same place by the summary the buyer looks at,
 * the amount the gateway is told to charge, the confirmation, and the invoice. Two implementations
 * of "what this booking costs" is how a buyer is shown one number and charged another.
 *
 * Everything is minor units and every division rounds half up, once, at the point it happens —
 * accumulating fractions and rounding at the end produces totals that are a penny out and cannot
 * be reconciled against a bank statement.
 *
 * Two things are deliberately on different sides of the fee and the tax. Add-ons are inside them:
 * a programme is a sale like any other, and an organiser who charges a booking fee charges it on
 * the whole booking. A **donation is outside both** — a fee on a donation is charging somebody for
 * the privilege of giving you money, and whether a gift is taxable is a question for the
 * organiser's accountant, not for a checkout. So it is added last, after the tax has been worked
 * out from everything else.
 *
 * A **voucher is on neither side**, because it is not part of what the booking cost at all. It is a
 * way of settling the amount that came out of all of the above: the sale stays a full-price sale,
 * the tax stays the tax on a full-price sale, and what changes is only how much of the total the
 * gateway is asked for. Treating it as a discount would understate the VAT on every redemption.
 */
final class OrderTotals
{
    private function __construct(
        public readonly int $tickets,
        public readonly int $discount,
        public readonly int $addons,
        public readonly int $fee,
        public readonly int $tax,
        public readonly int $donation,
        public readonly int $total,
        public readonly int $voucher,
        public readonly int $payable,
        public readonly int $taxRate,
        public readonly bool $taxIncluded,
        public readonly ?string $feeLabel,
        public readonly ?string $taxLabel,
    ) {}

    /**
     * @param  int  $tickets   what the seats themselves came to
     * @param  int  $discount  taken off the tickets, never off the tax, the fee or the add-ons
     * @param  int  $places    how many tickets, for a per-ticket fee
     * @param  int  $addons    programmes, drinks, parking — inside the fee and the tax
     * @param  int  $donation  given, and outside both
     * @param  int  $voucher   settled out of money already taken, and outside the arithmetic
     */
    public static function for(
        Event $event,
        int $tickets,
        int $discount = 0,
        int $places = 0,
        int $addons = 0,
        int $donation = 0,
        int $voucher = 0,
    ): self {
        $tickets = max(0, $tickets);
        $discount = max(0, min($tickets, $discount));
        $addons = max(0, $addons);
        $donation = max(0, $donation);
        $net = $tickets - $discount;

        // A discount is on the tickets. Half price on the seats is not half price on the wine, and
        // a code that quietly took money off a programme would be a code the bar cannot reconcile.
        $goods = $net + $addons;
        $fee = self::fee($event, $goods, $places);
        $taxable = $goods + $fee;
        $rate = (int) $event->tax_rate;
        $included = (bool) $event->tax_included;

        /*
         * Two ways to read the same rate.
         *
         * When the organiser's prices already contain the tax, the tax is the part of the total
         * that is tax — rate/(10000 + rate) — and the total does not move. When they do not, the
         * tax is added on top. Getting this backwards is a whole VAT rate of error, which is why
         * it is a stored answer rather than a guess.
         */
        $tax = 0 === $rate ? 0 : ($included
            ? intdiv($taxable * $rate + intdiv(10000 + $rate, 2), 10000 + $rate)
            : intdiv($taxable * $rate + 5000, 10000));

        $total = ($included ? $taxable : $taxable + $tax) + $donation;
        // Never more than the booking: the rest of a voucher stays a voucher, and a booking that
        // hands back change in cash is a gift card laundered into money.
        $voucher = max(0, min($total, $voucher));

        return new self(
            tickets: $tickets,
            discount: $discount,
            addons: $addons,
            fee: $fee,
            tax: $tax,
            donation: $donation,
            // The gift goes on last, after the tax has been worked out from everything else.
            total: $total,
            voucher: $voucher,
            // What the gateway is actually asked for. Zero is a real answer, and the checkout has
            // to be able to complete without a payment when it is.
            payable: $total - $voucher,
            taxRate: $rate,
            taxIncluded: $included,
            feeLabel: $event->booking_fee_label ?: null,
            taxLabel: $event->tax_label ?: null,
        );
    }

    private static function fee(Event $event, int $goods, int $places): int
    {
        $fixed = match ($event->booking_fee_kind) {
            'per_order' => (int) $event->booking_fee_amount,
            'per_ticket' => (int) $event->booking_fee_amount * max(0, $places),
            default => 0,
        };

        $percent = 0 === (int) $event->booking_fee_percent
            ? 0
            : intdiv($goods * (int) $event->booking_fee_percent + 50, 100);

        // A fee on nothing is nothing: an order discounted to zero is a comp, and charging a
        // booking fee on a free ticket is the kind of surprise that ends up in a complaint. A
        // booking that is nothing but a donation is the same case — the fee is on the goods.
        return 0 === $goods ? 0 : $fixed + $percent;
    }

    /** Written onto the order so the confirmation and the invoice read the same arithmetic back. */
    public function toArray(): array
    {
        return [
            'tickets' => $this->tickets,
            'discount' => $this->discount,
            'addons' => $this->addons,
            'fee' => $this->fee,
            'tax' => $this->tax,
            'donation' => $this->donation,
            'total' => $this->total,
            'voucher' => $this->voucher,
            'payable' => $this->payable,
            'tax_rate' => $this->taxRate,
            'tax_included' => $this->taxIncluded,
            'fee_label' => $this->feeLabel,
            'tax_label' => $this->taxLabel,
        ];
    }

    /** The lines a summary shows under the seats — only the ones that are not zero. */
    public function extraLines(): array
    {
        $lines = [];

        if ($this->addons > 0) {
            $lines[] = [
                'key' => 'addons',
                'label' => __('site.totals.addons'),
                'amount' => $this->addons,
            ];
        }

        if ($this->fee > 0) {
            $lines[] = [
                'key' => 'fee',
                'label' => $this->feeLabel ?: __('site.totals.fee'),
                'amount' => $this->fee,
            ];
        }

        if ($this->tax > 0) {
            $lines[] = [
                'key' => 'tax',
                'label' => __($this->taxIncluded ? 'site.totals.taxIncluded' : 'site.totals.tax', [
                    'name' => $this->taxLabel ?: __('site.totals.taxName'),
                    // Two decimals, because 8.75% is a real rate and "9%" is a different tax.
                    'rate' => \App\Support\Locale\Money::number($this->taxRate / 100, null, 2),
                ]),
                'amount' => $this->tax,
                // An inclusive tax is already inside the total; showing it as a line to be added
                // would make the summary add up to more than the buyer pays.
                'informational' => $this->taxIncluded,
            ];
        }

        // Last, and after the tax, because that is where it sits in the arithmetic as well as on
        // the page: nothing above it was computed from it.
        if ($this->donation > 0) {
            $lines[] = [
                'key' => 'donation',
                'label' => __('site.totals.donation'),
                'amount' => $this->donation,
            ];
        }

        return $lines;
    }
}
