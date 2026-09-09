<?php

namespace App\Domain\Discounts;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reading a code, and spending one.
 *
 * The two are deliberately separate calls. `offer()` is asked on every render of the checkout —
 * it must be cheap, must never write, and must be re-asked at the moment of payment, because a
 * code that was live when the buyer typed it may have run out while they were finding their card.
 * `redeem()` is asked exactly once, when there is an order to attach the use to.
 */
class Discounts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The one this account means by that spelling, or null. */
    public function find(string $code): ?DiscountCode
    {
        $code = DiscountCode::normalise($code);

        return '' === $code ? null : DiscountCode::where('code', $code)->first();
    }

    /**
     * What this code does to this basket.
     *
     * Order matters in the refusals: a buyer who mistypes should be told the code is unknown, and
     * a buyer holding a real code that does not apply here should be told which of the three
     * reasons it is — otherwise "invalid code" is the answer to everything and the support inbox
     * fills up with people who were told nothing.
     */
    public function offer(string $typed, Event $event, int $subtotal, string $currency, int $seats): DiscountOffer
    {
        $code = $this->find($typed);

        if (! $code) {
            return DiscountOffer::refused('unknown');
        }

        if ('active' !== $code->status) {
            return DiscountOffer::refused('paused');
        }

        if ($code->starts_at && $code->starts_at->isFuture()) {
            return DiscountOffer::refused('not_started');
        }

        if ($code->ends_at && ! $code->ends_at->isFuture()) {
            return DiscountOffer::refused('expired');
        }

        if ($code->isUsedUp()) {
            return DiscountOffer::refused('used_up');
        }

        if ($code->event_id && $code->event_id !== $event->id) {
            return DiscountOffer::refused('wrong_event');
        }

        // A fixed amount is money, and money has a currency. Nothing in this system converts one
        // currency into another, because a rate nobody agreed to is how an organiser discovers
        // they gave away four times what they meant to. Mismatched, it is refused.
        if ('fixed' === $code->kind && $code->currency && $code->currency !== $currency) {
            return DiscountOffer::refused('wrong_currency');
        }

        if ($seats < $code->min_seats) {
            return DiscountOffer::refused('too_few_seats');
        }

        $amount = $code->amountOff($subtotal);

        if ($amount <= 0) {
            return DiscountOffer::refused('nothing_off');
        }

        return DiscountOffer::allowed($code, $amount);
    }

    /**
     * Spend one use of a code on one order.
     *
     * Two buyers reaching the last use of a code at the same moment is the case this is written
     * for. A read of `used_count` followed by a write is two statements and loses that race; a
     * conditional UPDATE is one statement and Postgres serialises it, so exactly one of them gets
     * the last use and the other is told the code is gone.
     *
     * The redemption row inside the same transaction makes a retried checkout free: the second
     * attempt violates the unique index, the transaction unwinds — taking the increment with it —
     * and the caller is told the order already carries this discount.
     *
     * @return bool false when the code ran out between the offer and the payment
     */
    public function redeem(DiscountCode $code, ExternalOrder $order, int $amount): bool
    {
        try {
            return DB::transaction(function () use ($code, $order, $amount) {
                $claimed = DB::table('discount_codes')
                    ->where('id', $code->id)
                    // "Not capped, or not yet at the cap" — re-read here rather than trusted from the
                    // instance, so a cap lowered since the offer is still honoured.
                    ->where(fn ($q) => $q->whereNull('max_uses')->orWhereRaw('used_count < max_uses'))
                    ->update([
                        'used_count' => DB::raw('used_count + 1'),
                        'updated_at' => now(),
                    ]);

                if (0 === $claimed) {
                    return false;
                }

                DiscountRedemption::create([
                    'tenant_id' => $code->tenant_id,
                    'discount_code_id' => $code->id,
                    'external_order_row_id' => $order->id,
                    'amount' => $amount,
                    'currency' => $order->currency,
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Already spent on this very order. The increment rolled back with the transaction.
            return true;
        }
    }

    /**
     * Take the discount off an order that has just been registered, and write down what happened.
     *
     * The order's own total is the thing charged and the thing shown on the confirmation, so it is
     * the thing that has to change; `metadata.discount` keeps the arithmetic beside it, because an
     * organiser looking at a €38 order for €45 of seats needs to be able to see why.
     */
    public function applyTo(ExternalOrder $order, DiscountCode $code, int $amount): bool
    {
        $subtotal = (int) $order->total_amount;
        $amount = max(0, min($subtotal, $amount));

        if (! $this->redeem($code, $order, $amount)) {
            return false;
        }

        $order->forceFill([
            'total_amount' => $subtotal - $amount,
            'metadata' => ($order->metadata ?? []) + [
                'discount' => [
                    'code' => $code->code,
                    'kind' => $code->kind,
                    'value' => $code->value,
                    'subtotal' => $subtotal,
                    'amount' => $amount,
                ],
            ],
        ])->save();

        $this->audit->record('discount.redeemed', $order, [
            'code' => $code->code,
            'amount' => $amount,
            'currency' => $order->currency,
        ]);

        return true;
    }

    /** A code an organiser has not chosen for themselves. Unambiguous by construction: no O, no I. */
    public static function suggest(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
