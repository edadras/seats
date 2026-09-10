<?php

namespace App\Domain\Refunds;

use App\Models\Event;
use App\Models\ExternalOrder;
use App\Support\Locale\Money;

/**
 * The terms, in a form a buyer can read and the code can act on.
 *
 * One statement of the rule, used three times: to write the sentence on the checkout page, to
 * decide whether a request is granted the moment it is made, and to work out what actually goes
 * back. Three implementations of "may they have their money back" is how a page promises one thing
 * and a button does another.
 *
 * `refund_keeps_fee` is the honest half. An organiser who paid to take the money does not get that
 * back when they return it, and terms that pretended otherwise would have been written by somebody
 * who has never been charged a card fee. Where it is kept, the page says so before the buyer
 * clicks rather than afterwards.
 */
class RefundPolicy
{
    /** Nothing back, ever. The default, because it is the one an organiser has to opt out of. */
    public const NEVER = 'never';

    /** Up to a stated number of hours before the doors open. */
    public const UNTIL = 'until';

    /** Right up to the start. Generous, and some organisers mean it. */
    public const ALWAYS = 'always';

    public const KINDS = [self::NEVER, self::UNTIL, self::ALWAYS];

    /**
     * May this booking still be handed back?
     *
     * @return array{allowed: bool, reason: string, deadline: ?\Illuminate\Support\Carbon}
     */
    public function check(ExternalOrder $order): array
    {
        // Loaded here rather than assumed: lazy loading is off, and this is called from a buyer's
        // own page, from the box office and from a domain service, none of which should have to
        // remember what the terms need in order to be read.
        $order->loadMissing('event');

        $event = $order->event;

        if (! $event) {
            return $this->no('unknown_event');
        }

        if (! in_array($order->status, ['confirmed', 'partially_refunded'], true)) {
            // Nothing to hand back. A pending order was never charged and a refunded one already
            // was, and both are better said plainly than as "the terms do not allow it".
            return $this->no('nothing_to_refund');
        }

        if (self::NEVER === $event->refunds) {
            return $this->no('not_offered');
        }

        $deadline = $this->deadline($event);

        if ($deadline && now()->greaterThan($deadline)) {
            return ['allowed' => false, 'reason' => 'too_late', 'deadline' => $deadline];
        }

        return ['allowed' => true, 'reason' => 'allowed', 'deadline' => $deadline];
    }

    /** The last moment a buyer may ask, or null where the answer is "up to the start". */
    public function deadline(Event $event): ?\Illuminate\Support\Carbon
    {
        if (! $event->starts_at) {
            return null;
        }

        return match ($event->refunds) {
            self::UNTIL => $event->starts_at->copy()->subHours(max(0, (int) $event->refund_window_hours)),
            self::ALWAYS => $event->starts_at,
            default => null,
        };
    }

    /**
     * What actually goes back, in minor units.
     *
     * The seats, and the booking fee only where the organiser said they would return it. Tax
     * follows the seats: it was charged on them and is not the organiser's to keep.
     *
     * @param  ?array<int, string>  $allocationIds  null for the whole booking
     */
    public function amount(ExternalOrder $order, ?array $allocationIds = null): int
    {
        $order->loadMissing(['event', 'allocations']);

        $totals = $order->metadata['totals'] ?? null;
        $whole = null === $allocationIds || [] === $allocationIds;

        if ($whole) {
            $charged = (int) $order->total_amount;

            if ($order->event?->refund_keeps_fee) {
                return max(0, $charged - (int) ($totals['fee'] ?? 0));
            }

            return $charged;
        }

        // Part of a booking: the seats named, at what they were sold for. Whether a booking fee
        // survives a partial refund is the organiser's own terms, and this platform does not
        // invent an answer — see the settlement, which says the same thing.
        return (int) $order->allocations
            ->whereIn('id', $allocationIds)
            ->where('status', 'active')
            ->sum('amount');
    }

    /** The sentence a buyer reads before they pay. */
    public function sentence(Event $event, ?string $currency = null): string
    {
        if (self::NEVER === $event->refunds) {
            return __('site.refunds.never');
        }

        $keeps = $event->refund_keeps_fee && (int) $event->booking_fee_amount + (int) $event->booking_fee_percent > 0
            ? ' '.__('site.refunds.feeKept')
            : '';

        if (self::ALWAYS === $event->refunds) {
            return __('site.refunds.always').$keeps;
        }

        return __('site.refunds.until', [
            'hours' => Money::number((int) $event->refund_window_hours),
        ]).$keeps;
    }

    /** @return array{allowed: bool, reason: string, deadline: null} */
    private function no(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason, 'deadline' => null];
    }
}
