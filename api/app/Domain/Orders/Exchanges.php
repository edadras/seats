<?php

namespace App\Domain\Orders;

use App\Domain\Vouchers\Vouchers;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\ExternalOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moving to another night, or to other seats on the same one.
 *
 * The thing most often asked for, and the thing this platform has been quietly refusing: the answer
 * used to be "we will refund you, then buy again", which loses the seats in between and is how a
 * buyer ends up with neither.
 *
 * **What an exchange actually is here: a refund taken as credit, spent immediately.** The buyer
 * chooses their new seats first and holds them like anybody else; only when they confirm does the
 * old booking go back, as a voucher that pays for the new one. Nothing is released until the new
 * seats are secured, which is the whole point — and it reuses the credit machinery rather than
 * inventing a second kind of money.
 *
 * **The terms are the event's, and they are the same three words the refunds use** — never, until,
 * always — because an organiser who has learned one has learned the other. A fee may be kept for
 * the work of it, and it is taken out of what the old seats are worth rather than charged
 * separately: a buyer moving from a €30 seat to a €30 seat with a €3 fee pays €3, and does not get
 * an invoice for €30 and a refund for €27.
 */
class Exchanges
{
    public function __construct(private readonly Vouchers $vouchers) {}

    /**
     * May this booking still be moved, and until when?
     *
     * @return array{allowed: bool, reason: string, deadline: ?Carbon, fee: int}
     */
    public function check(ExternalOrder $order): array
    {
        $order->loadMissing('event');
        $event = $order->event;

        if (! $event) {
            return $this->no('unknown_event');
        }

        if (! in_array($order->status, ['confirmed', 'partially_refunded'], true)) {
            return $this->no('nothing_to_exchange');
        }

        if ('never' === ($event->exchanges ?? 'never')) {
            return $this->no('not_offered');
        }

        if ($event->isCancelled()) {
            // A cancelled night is a refund, not an exchange: there is nothing to move away from.
            return $this->no('event_cancelled');
        }

        $deadline = $this->deadline($event);

        if ($deadline && now()->greaterThan($deadline)) {
            return ['allowed' => false, 'reason' => 'too_late', 'deadline' => $deadline, 'fee' => 0];
        }

        return [
            'allowed' => true,
            'reason' => 'allowed',
            'deadline' => $deadline,
            'fee' => max(0, (int) ($event->exchange_fee_amount ?? 0)),
        ];
    }

    public function deadline(Event $event): ?Carbon
    {
        if (! $event->starts_at) {
            return null;
        }

        return match ($event->exchanges) {
            'until' => $event->starts_at->copy()->subHours(max(0, (int) $event->exchange_window_hours)),
            'always' => $event->starts_at,
            default => null,
        };
    }

    /**
     * What the seats being given back are worth towards the new booking.
     *
     * What was paid for those seats, less the fee. Not the whole order: somebody moving two of
     * their four seats is keeping the other two, and the booking fee they paid once is not
     * refundable twice.
     *
     * @param  list<string>  $allocationIds
     */
    public function worth(ExternalOrder $order, array $allocationIds, int $fee): int
    {
        $seats = Allocation::where('external_order_row_id', $order->id)
            ->where('status', 'active')
            ->whereIn('id', $allocationIds)
            ->sum('amount');

        return max(0, (int) $seats - $fee);
    }

    /**
     * Give the old seats back and hand over the credit that pays for the new ones.
     *
     * Returns the voucher, which the checkout then spends exactly as it spends any other. The
     * caller does this *before* charging and after the new seats are held: the order of those two
     * is what stops a buyer being left with neither.
     *
     * @param  list<string>  $allocationIds
     */
    public function giveBack(ExternalOrder $order, array $allocationIds, string $email): \App\Models\Voucher
    {
        $terms = $this->check($order);

        if (! $terms['allowed']) {
            throw ApiException::conflict(
                'exchange_closed',
                'This booking can no longer be moved.',
                ['reason' => $terms['reason']],
            );
        }

        $fee = (int) $terms['fee'];
        $worth = $this->worth($order, $allocationIds, $fee);

        if ($worth < 1) {
            throw ApiException::unprocessable('exchange_nothing', 'Those seats are not yours to move.');
        }

        return DB::transaction(function () use ($order, $allocationIds, $email, $worth) {
            $seats = Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')
                ->whereIn('id', $allocationIds)
                ->lockForUpdate()
                ->get();

            foreach ($seats as $seat) {
                $seat->forceFill(['status' => 'released', 'released_at' => now()])->save();

                \App\Models\Ticket::where('allocation_id', $seat->id)->update([
                    'status' => 'void',
                    'voided_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $left = Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count();

            $order->forceFill([
                'status' => $left > 0 ? 'partially_refunded' : 'refunded',
                'refunded_at' => now(),
            ])->save();

            $order->event?->bumpAvailabilityVersion();

            return $this->vouchers->credit(
                (string) $order->tenant_id,
                $email,
                $worth,
                (string) $order->currency,
                // The same shape as every other credit note: what it is for, said once, in the
                // language of whoever is looking at the account it lands in.
                __('panel.vouchers.creditFromExchange', ['reference' => $order->external_order_id]),
                $order,
            );
        });
    }

    /** @return array{allowed: bool, reason: string, deadline: null, fee: int} */
    private function no(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason, 'deadline' => null, 'fee' => 0];
    }
}
