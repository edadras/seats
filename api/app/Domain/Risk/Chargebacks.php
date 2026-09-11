<?php

namespace App\Domain\Risk;

use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Money taken back after the tickets were sent.
 *
 * A refund is the organiser deciding; a chargeback is the bank deciding, usually against them,
 * generally with a fee, and always weeks later. Sharing a word with refunds would produce a
 * settlement report saying an organiser gave money back when in fact it was taken from them, so
 * this is its own status and its own line.
 *
 * What it does is not a policy an organiser sets: the seats go back on sale and the ticket stops
 * working, in one transaction. A booking nobody paid for is not a booking, and the half a venue
 * actually cares about on the night is that the QR code at the door goes red.
 */
class Chargebacks
{
    public function __construct(private readonly Blocklist $blocklist) {}

    /**
     * Record one, and undo what it paid for.
     *
     * Idempotent: the same dispute reported twice — by a gateway webhook and by somebody typing it
     * in — is one chargeback, because the second one must not release seats that have since been
     * sold to somebody else.
     */
    public function record(
        ExternalOrder $order,
        string $reason,
        int $fee = 0,
        bool $block = false,
        ?string $by = null,
    ): ExternalOrder {
        if ($order->charged_back_at) {
            return $order->fresh(['allocations.ticket']);
        }

        if ('pending' === $order->status) {
            throw ApiException::conflict(
                'chargeback_not_paid',
                'Nothing was ever taken for that booking, so nothing can be taken back.',
            );
        }

        return DB::transaction(function () use ($order, $reason, $fee, $block, $by) {
            $allocations = Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($allocations as $allocation) {
                // Released rather than voided: the chair should be sellable again tonight. The
                // ticket is what has to stop working, and that is the line below.
                $allocation->forceFill(['status' => 'released', 'released_at' => now()])->save();

                Ticket::where('allocation_id', $allocation->id)->update([
                    'status' => 'void',
                    'voided_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $order->forceFill([
                'status' => 'charged_back',
                'charged_back_at' => now(),
                'chargeback_fee' => max(0, $fee),
                'chargeback_reason' => mb_substr($reason, 0, 190),
            ])->save();

            $order->event?->bumpAvailabilityVersion();

            /*
             * And, if asked, the person.
             *
             * Deliberately a choice rather than automatic: a disputed payment is sometimes a
             * stolen card and sometimes a buyer who could not reach anybody about a cancelled
             * train, and a platform that barred all of them would bar the second kind.
             */
            if ($block) {
                $this->blocklist->add(
                    $order->buyer['email'] ?? null,
                    $order->buyer['phone'] ?? null,
                    'Chargeback on '.$order->external_order_id.': '.$reason,
                    null,
                    $by,
                );
            }

            /*
             * And the points go with the money.
             *
             * A chargeback is not a refund — the organiser did not choose it — but it is the same
             * fact about the evening: nobody paid for it in the end. `settle()` reads what the
             * booking is worth now, which is nothing, and writes the difference.
             */
            try {
                app(\App\Domain\Loyalty\Loyalty::class)->settle($order->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            return $order->fresh(['allocations.ticket']);
        });
    }

    /**
     * What chargebacks have cost, for the takings.
     *
     * Separately from refunds on purpose: an organiser reading a settlement needs to tell what they
     * chose to give back from what was taken from them, and the fee is a third number again.
     *
     * @return array{count: int, amount: int, fees: int}
     */
    public function tallyFor(iterable $orders): array
    {
        $tally = ['count' => 0, 'amount' => 0, 'fees' => 0];

        foreach ($orders as $order) {
            if (! $order->charged_back_at) {
                continue;
            }

            $tally['count']++;
            $tally['amount'] += (int) $order->total_amount;
            $tally['fees'] += (int) $order->chargeback_fee;
        }

        return $tally;
    }
}
