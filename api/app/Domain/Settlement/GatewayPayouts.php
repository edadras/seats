<?php

namespace App\Domain\Settlement;

use App\Exceptions\ApiException;
use App\Models\ExternalOrder;
use App\Models\GatewayPayout;
use App\Models\GatewayPayoutLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The books, against the bank.
 *
 * Every money screen on this platform is worked out from the orders in this database, and that is
 * the right way round — the arithmetic of a booking is frozen at the moment it is paid and never
 * recomputed. But it has always meant the same thing: the figures agree with themselves. A gateway
 * that declines a payment we recorded, reverses one, holds a deposit back, or charges a fee nobody
 * accounted for leaves the ledger saying one number and the bank another, and until now nothing
 * here could notice.
 *
 * So the statement is taken in as the gateway states it, and this puts the two halves side by side.
 * There are only four things that can be said about a line, and the value of the screen is that it
 * says which of the four every single line is:
 *
 *   - **matched** — we have the order, and the amount agrees;
 *   - **differs** — we have the order, and the amount does not. Almost always a partial refund
 *     nobody recorded, or a currency conversion;
 *   - **unknown** — they paid for something this database has never heard of;
 *   - **missing** — we recorded a payment they have not paid for.
 *
 * And one more, which costs nothing and catches the commonest mistake of all: whether the payout's
 * own gross, fees and net add up to each other and to the lines inside it. A statement that fails
 * its own arithmetic is a bad import, and finding that out immediately saves somebody an hour of
 * looking for a discrepancy that is not there.
 *
 * Nothing is stored. A reconciliation is a view over two sets of rows, and freezing it would mean a
 * refund processed tomorrow silently disagreeing with a verdict recorded today.
 */
class GatewayPayouts
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Take in a statement.
     *
     * @param  array<string, mixed>  $payout
     * @param  list<array<string, mixed>>  $lines
     */
    public function record(array $payout, array $lines, ?string $userId = null): GatewayPayout
    {
        $reference = trim((string) $payout['reference']);

        $held = GatewayPayout::where('gateway', $payout['gateway'])
            ->where('reference', $reference)
            ->first();

        if ($held) {
            // The same statement twice is one statement. Refused rather than merged: a second
            // upload that quietly doubled a venue's income would not be found until an accountant
            // found it, and by then nobody would remember there had been two.
            throw ApiException::conflict(
                'payout_already_recorded',
                'That payout has already been recorded.',
                ['reference' => $reference],
            );
        }

        return DB::transaction(function () use ($payout, $lines, $reference, $userId) {
            $record = GatewayPayout::create([
                'tenant_id' => $this->tenants->idOrFail(),
                'gateway' => $payout['gateway'],
                'reference' => $reference,
                'currency' => mb_strtoupper((string) $payout['currency']),
                'paid_on' => Carbon::parse($payout['paid_on'])->toDateString(),
                'gross' => (int) $payout['gross'],
                'fees' => (int) $payout['fees'],
                'net' => (int) $payout['net'],
                'note' => $payout['note'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                GatewayPayoutLine::create([
                    'tenant_id' => $record->tenant_id,
                    'gateway_payout_id' => $record->id,
                    'reference' => trim((string) ($line['reference'] ?? '')),
                    'kind' => in_array($line['kind'] ?? '', GatewayPayoutLine::KINDS, true)
                        ? $line['kind']
                        : 'adjustment',
                    'amount' => (int) ($line['amount'] ?? 0),
                    'fee' => (int) ($line['fee'] ?? 0),
                    'occurred_on' => isset($line['occurred_on'])
                        ? Carbon::parse($line['occurred_on'])->toDateString()
                        : null,
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $record->fresh('lines');
        });
    }

    /** Every statement taken in, newest first, each with what it did not explain. */
    public function all(): array
    {
        return GatewayPayout::with('lines')
            ->orderByDesc('paid_on')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (GatewayPayout $payout) => [
                'id' => $payout->id,
                'gateway' => $payout->gateway,
                'reference' => $payout->reference,
                'currency' => $payout->currency,
                'paid_on' => $payout->paid_on?->toDateString(),
                'gross' => $payout->gross,
                'fees' => $payout->fees,
                'net' => $payout->net,
                'lines' => $payout->lines->count(),
                // The headline: how many of its lines this platform cannot account for. A finance
                // officer scanning a list wants to know which statement to open, not its totals.
                'unexplained' => $this->unexplained($payout),
            ])
            ->all();
    }

    /**
     * One statement, line by line, against what this database says.
     *
     * @return array<string, mixed>
     */
    public function reconcile(GatewayPayout $payout): array
    {
        $payout->loadMissing('lines');

        $references = $payout->lines->pluck('reference')->filter()->unique()->values()->all();
        $orders = $this->ordersByReference($references);

        $matched = [];
        $differs = [];
        $unknown = [];

        foreach ($payout->lines as $line) {
            $order = $orders[$line->reference] ?? null;

            // A fee or an adjustment is the gateway's own line and answers to no order of ours.
            if (in_array($line->kind, ['fee', 'adjustment'], true)) {
                $matched[] = $this->line($line, null);

                continue;
            }

            if (! $order) {
                $unknown[] = $this->line($line, null);

                continue;
            }

            $expected = $this->expected($order, $line->kind);

            if ($expected === abs($line->amount)) {
                $matched[] = $this->line($line, $order);
            } else {
                $differs[] = $this->line($line, $order) + ['expected' => $expected];
            }
        }

        return [
            'payout' => [
                'id' => $payout->id,
                'gateway' => $payout->gateway,
                'reference' => $payout->reference,
                'currency' => $payout->currency,
                'paid_on' => $payout->paid_on?->toDateString(),
                'gross' => $payout->gross,
                'fees' => $payout->fees,
                'net' => $payout->net,
                'note' => $payout->note,
            ],
            'arithmetic' => $this->arithmetic($payout),
            'matched' => $matched,
            'differs' => $differs,
            'unknown' => $unknown,
            'missing' => $this->missing($payout),
        ];
    }

    public function remove(GatewayPayout $payout): void
    {
        // The lines go with it by the foreign key. A statement removed is a statement that was
        // imported wrongly; there is nothing here worth keeping a tombstone for.
        $payout->delete();
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * Whether the statement agrees with itself.
     *
     * Three claims a gateway makes and does not always keep: that its lines add up to its gross,
     * that its line fees add up to its fees, and that gross less fees is the net it transferred.
     * Each is reported with both figures, because "out by 240" is a number somebody can search a
     * statement for and "does not balance" is not.
     *
     * @return array<string, mixed>
     */
    private function arithmetic(GatewayPayout $payout): array
    {
        $lines = (int) $payout->lines->sum('amount');
        $lineFees = (int) $payout->lines->sum('fee');

        return [
            'lines_total' => $lines,
            'lines_match_gross' => $lines === $payout->gross,
            'line_fees_total' => $lineFees,
            'line_fees_match' => $lineFees === $payout->fees,
            'net_expected' => $payout->gross - $payout->fees,
            'net_matches' => ($payout->gross - $payout->fees) === $payout->net,
        ];
    }

    /**
     * Payments this database recorded that the statement does not pay for.
     *
     * Scoped to the same gateway, the same currency and a window around the payout's date, because
     * the alternative — every order ever — would report a fortnight of perfectly ordinary sales as
     * missing from a statement that was never meant to contain them. A payment that shows up in a
     * *later* statement is not missing either, so anything already claimed anywhere is excluded.
     *
     * @return list<array<string, mixed>>
     */
    private function missing(GatewayPayout $payout): array
    {
        $window = (int) config('seatmap.settlement.payout_window_days', 14);

        $claimed = GatewayPayoutLine::whereNotNull('reference')
            ->where('reference', '!=', '')
            ->pluck('reference')
            ->unique()
            ->all();

        return ExternalOrder::where('status', 'confirmed')
            ->where('currency', $payout->currency)
            ->whereNotNull('confirmed_at')
            ->whereBetween('confirmed_at', [
                $payout->paid_on->copy()->subDays($window)->startOfDay(),
                $payout->paid_on->copy()->endOfDay(),
            ])
            ->get()
            ->filter(function (ExternalOrder $order) use ($payout, $claimed) {
                $reference = (string) ($order->metadata['payment_reference'] ?? '');

                return '' !== $reference
                    && (string) ($order->metadata['gateway'] ?? '') === $payout->gateway
                    && ! in_array($reference, $claimed, true);
            })
            ->map(fn (ExternalOrder $order) => [
                'reference' => (string) $order->metadata['payment_reference'],
                'order_id' => $order->external_order_id,
                'amount' => (int) $order->total_amount,
                'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** How many lines of this statement this platform cannot account for. */
    private function unexplained(GatewayPayout $payout): int
    {
        $answer = $this->reconcile($payout);

        return count($answer['differs']) + count($answer['unknown']) + count($answer['missing']);
    }

    /**
     * Orders by the handle the gateway knows them by.
     *
     * `metadata->>'payment_reference'` rather than a column, because that is where the reference
     * has always lived — written down by the checkout the moment a payment begins. Asked of the
     * database rather than filtered in PHP: a venue with a hundred thousand orders would otherwise
     * load all of them to match forty lines.
     *
     * @param  list<string>  $references
     * @return array<string, ExternalOrder>
     */
    private function ordersByReference(array $references): array
    {
        if (! $references) {
            return [];
        }

        return ExternalOrder::whereIn(
            DB::raw("metadata->>'payment_reference'"),
            $references
        )->get()->keyBy(fn (ExternalOrder $order) => (string) $order->metadata['payment_reference'])->all();
    }

    /** What this database says a line of this kind should be worth. */
    private function expected(ExternalOrder $order, string $kind): int
    {
        if ('refund' === $kind) {
            // What actually went back, rather than what the order came to: a partial refund is the
            // commonest reason for a line to differ, and comparing against the total would report
            // every one of them as a discrepancy.
            return (int) \App\Models\OrderRefund::where('external_order_row_id', $order->id)
                ->sum('amount');
        }

        return (int) $order->total_amount;
    }

    /** @return array<string, mixed> */
    private function line(GatewayPayoutLine $line, ?ExternalOrder $order): array
    {
        return [
            'reference' => $line->reference,
            'kind' => $line->kind,
            'amount' => $line->amount,
            'fee' => $line->fee,
            'occurred_on' => $line->occurred_on?->toDateString(),
            'description' => $line->description,
            'order_id' => $order?->external_order_id,
        ];
    }
}
