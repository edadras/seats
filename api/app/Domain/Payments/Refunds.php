<?php

namespace App\Domain\Payments;

use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\Payments\RefundOutcome;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\OrderRefund;
use App\Models\User;
use App\Support\Audit\AuditLogger;

/**
 * Giving money back, before the seats go.
 *
 * Until now a refund in this platform was bookkeeping: the seats were released, the order was
 * marked refunded, every report agreed — and nobody's card was ever credited. Somebody had to open
 * the gateway's own dashboard afterwards and do it from memory, against a reference they had to go
 * and find. Half of what a refund means was missing.
 *
 * The order here is the whole design. The money goes back *first*, and the seats move only if it
 * did. A booking cancelled while the money stayed put is the worst of the three outcomes
 * available: the buyer has neither their seat nor their money, and the organiser finds out weeks
 * later from a complaint. So a gateway that refuses stops everything, and says why.
 *
 * The exception is money that was never taken by a gateway — cash at the window, a bank transfer,
 * a school's invoice. There is nothing to send back and the drawer is not something this platform
 * can reach into, so those are written down as owed in person and the seats go back on sale. That
 * is what a box office does anyway; what is new is that it is now recorded rather than assumed.
 */
class Refunds
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Send `$amount` back for this order, and record what happened.
     *
     * @throws ApiException when the gateway refuses — nothing else in the refund should proceed
     */
    public function give(ExternalOrder $order, int $amount, string $reason, ?User $by = null): OrderRefund
    {
        $amount = max(0, $amount);
        $already = $this->alreadyGiven($order);
        $charged = max(0, (int) $order->total_amount - (int) $order->voucher_amount);

        /*
         * More than was ever charged.
         *
         * Reachable by refunding four seats, then the same four again through a stale screen. The
         * gateway would probably refuse it too, but arriving at that conclusion by asking somebody
         * else's server for money is not a design.
         */
        if ($already + $amount > $charged) {
            throw ApiException::conflict(
                'refund_exceeds_payment',
                'That is more than this booking was charged.',
                ['charged' => $charged, 'already_refunded' => $already, 'asked' => $amount],
            );
        }

        $outcome = $this->send($order, $amount);

        $refund = OrderRefund::create([
            'tenant_id' => $order->tenant_id,
            'external_order_row_id' => $order->id,
            'amount' => $amount,
            'currency' => (string) $order->currency,
            'status' => $outcome->wasSent() ? 'sent' : ($outcome->hasFailed() ? 'failed' : 'manual'),
            'gateway' => $this->gatewayKey($order),
            'reference' => $outcome->reference,
            'reason' => $reason,
            'message' => $outcome->message,
            'requested_by' => $by?->id,
        ]);

        $this->audit->record('order.refund_sent', $order, [
            'reference' => $order->external_order_id,
            'amount' => $amount,
            'status' => $refund->status,
            'gateway' => $refund->gateway,
        ]);

        if ($outcome->hasFailed()) {
            /*
             * Recorded and then refused, in that order.
             *
             * The row survives the exception because the attempt is the thing worth knowing about:
             * an organiser looking at a booking that will not refund needs to see that it has been
             * tried three times and what the gateway said each time, not an empty history and a
             * toast that has already gone.
             */
            throw ApiException::unprocessable(
                'refund_refused',
                $outcome->message ?: 'The payment gateway would not send that money back.',
                ['gateway' => $refund->gateway],
            );
        }

        return $refund;
    }

    /**
     * What giving these seats back is worth.
     *
     * The whole booking less what a voucher paid — that part was never the organiser's money and
     * goes back to the voucher on its own — and less whatever has already gone back, so two
     * refunds of the same booking never add up to more than was charged.
     *
     * For some of the seats it is their share of what is left. The booking fee and the tax belong
     * to the booking rather than to any one chair, so a share of the remainder is the only split
     * that adds back up: the last seats out take whatever rounding was dropped along the way.
     *
     * @param  array<int, string>|null  $seatIds  null or empty means the whole booking
     */
    public function worthOf(ExternalOrder $order, ?array $seatIds): int
    {
        $charged = max(0, (int) $order->total_amount - (int) $order->voucher_amount);
        $left = max(0, $charged - $this->alreadyGiven($order));

        if (null === $seatIds || [] === $seatIds) {
            return $left;
        }

        $live = Allocation::where('external_order_row_id', $order->id)
            ->where('status', 'active')
            ->count();

        if ($live < 1) {
            return 0;
        }

        $going = Allocation::where('external_order_row_id', $order->id)
            ->where('status', 'active')
            ->whereIn('seat_id', $seatIds)
            ->count();

        return $going >= $live ? $left : (int) floor($left * $going / $live);
    }

    /** What has already gone back on this booking, however it went. */
    public function alreadyGiven(ExternalOrder $order): int
    {
        return (int) OrderRefund::where('external_order_row_id', $order->id)
            ->whereIn('status', ['sent', 'manual'])
            ->sum('amount');
    }

    /** Every attempt on this booking, newest first — the history a screen shows. */
    public function history(ExternalOrder $order): array
    {
        return OrderRefund::where('external_order_row_id', $order->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrderRefund $row) => [
                'id' => $row->id,
                'amount' => $row->amount,
                'currency' => $row->currency,
                'status' => $row->status,
                'gateway' => $row->gateway,
                'reference' => $row->reference,
                'reason' => $row->reason,
                'message' => $row->message,
                'at' => $row->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /* --------------------------------------------------------------------------- internals */

    private function send(ExternalOrder $order, int $amount): RefundOutcome
    {
        if (0 === $amount) {
            // A comp, or a booking paid entirely with a voucher. There is nothing to send and
            // nothing owed in person either.
            return RefundOutcome::unsupported(__('payments.errors.nothing_to_refund'));
        }

        $key = $this->gatewayKey($order);
        $reference = (string) ($order->metadata['payment_reference'] ?? '');

        if ('' === $key || '' === $reference || ! $this->gateways->has($key)) {
            /*
             * Cash at the window, a transfer, an invoice — or a booking from before this platform
             * kept the reference. Nothing to ask, so the money is owed in person.
             */
            return RefundOutcome::unsupported(__('payments.errors.refund_by_hand'));
        }

        try {
            return $this->gateways->get($key)->refund($order, $amount, $reference);
        } catch (\Throwable $e) {
            /*
             * A gateway that threw rather than answered — a timeout, a module misconfigured, a
             * certificate expired. Treated as a refusal, because the one thing that must not
             * happen here is releasing the seats on the strength of a request that may or may not
             * have gone through.
             */
            report($e);

            return RefundOutcome::failed(__('payments.errors.refund_unreachable'));
        }
    }

    private function gatewayKey(ExternalOrder $order): string
    {
        return (string) ($order->metadata['gateway'] ?? '');
    }
}
