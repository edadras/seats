<?php

namespace App\Domain\Orders;

use App\Models\Event;

/**
 * What a booking adds up to: tickets, less any discount, plus a fee, plus tax.
 *
 * One place computes this, and it is read from the same place by the summary the buyer looks at,
 * the amount the gateway is told to charge, the confirmation, and the invoice. Two implementations
 * of "what this booking costs" is how a buyer is shown one number and charged another.
 *
 * Everything is minor units and every division rounds half up, once, at the point it happens —
 * accumulating fractions and rounding at the end produces totals that are a penny out and cannot
 * be reconciled against a bank statement.
 */
final class OrderTotals
{
    private function __construct(
        public readonly int $tickets,
        public readonly int $discount,
        public readonly int $fee,
        public readonly int $tax,
        public readonly int $total,
        public readonly int $taxRate,
        public readonly bool $taxIncluded,
        public readonly ?string $feeLabel,
        public readonly ?string $taxLabel,
    ) {}

    /**
     * @param  int  $tickets   what the seats themselves came to
     * @param  int  $discount  taken off the tickets, never off the tax or the fee
     * @param  int  $places    how many tickets, for a per-ticket fee
     */
    public static function for(Event $event, int $tickets, int $discount = 0, int $places = 0): self
    {
        $tickets = max(0, $tickets);
        $discount = max(0, min($tickets, $discount));
        $net = $tickets - $discount;

        $fee = self::fee($event, $net, $places);
        $taxable = $net + $fee;
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

        return new self(
            tickets: $tickets,
            discount: $discount,
            fee: $fee,
            tax: $tax,
            total: $included ? $taxable : $taxable + $tax,
            taxRate: $rate,
            taxIncluded: $included,
            feeLabel: $event->booking_fee_label ?: null,
            taxLabel: $event->tax_label ?: null,
        );
    }

    private static function fee(Event $event, int $net, int $places): int
    {
        $fixed = match ($event->booking_fee_kind) {
            'per_order' => (int) $event->booking_fee_amount,
            'per_ticket' => (int) $event->booking_fee_amount * max(0, $places),
            default => 0,
        };

        $percent = 0 === (int) $event->booking_fee_percent
            ? 0
            : intdiv($net * (int) $event->booking_fee_percent + 50, 100);

        // A fee on nothing is nothing: an order discounted to zero is a comp, and charging a
        // booking fee on a free ticket is the kind of surprise that ends up in a complaint.
        return 0 === $net ? 0 : $fixed + $percent;
    }

    /** Written onto the order so the confirmation and the invoice read the same arithmetic back. */
    public function toArray(): array
    {
        return [
            'tickets' => $this->tickets,
            'discount' => $this->discount,
            'fee' => $this->fee,
            'tax' => $this->tax,
            'total' => $this->total,
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

        return $lines;
    }
}
