<?php

namespace App\Domain\BoxOffice;

use App\Domain\Rehearsals\Live;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Shift;
use App\Models\ShiftMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The drawer, and whether it balances.
 *
 * Every venue asks the same question at eleven o'clock: is the money in the till the money that
 * should be in the till? Answering it is arithmetic, and the arithmetic is here.
 *
 *     expected = opening float
 *              + cash taken on sales made during this shift
 *              - cash handed back on refunds against those sales
 *              + movements in - movements out
 *
 * Every term but the last is counted from rows that already exist, and the last one exists because
 * nothing else on this platform knows that somebody paid for a taxi out of the drawer.
 *
 * **Only cash.** A card sale is money that never touched the drawer, and counting it would make
 * every honest evening look several hundred short. The counter records how each sale was paid for
 * so that this can tell them apart.
 *
 * **The count is a photograph.** At the close, both what was counted and what was expected are
 * written down. A refund granted the next morning changes what the till *would* now be expected to
 * have held, and it must not silently rewrite last night's discrepancy into agreement.
 */
class Tills
{
    /** How a sale at the counter was paid for. `cash` is the only one that touches the drawer. */
    public const METHODS = ['cash', 'card', 'transfer'];

    /**
     * Start a shift.
     *
     * @throws ApiException where this person already has one open — see the migration for why the
     *                      database says so as well
     */
    public function open(User $user, string $currency, int $float = 0, ?Event $event = null): Shift
    {
        if ($this->current($user)) {
            throw ApiException::conflict(
                'till_already_open',
                'You already have a till open. Close it before starting another.',
            );
        }

        return Shift::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'event_id' => $event?->id,
            'currency' => mb_strtoupper($currency),
            'opening_float' => max(0, $float),
            'opened_at' => now(),
        ]);
    }

    /** This person's open till, where they have one. */
    public function current(User $user): ?Shift
    {
        return Shift::where('user_id', $user->id)->whereNull('closed_at')->first();
    }

    /**
     * What the drawer should hold right now.
     *
     * Counted on every read. There is no column to take this off, which is the same rule the seats,
     * the vouchers and the channel quotas follow, and for the same reason: a running total kept in
     * a column is a running total that is wrong after the first thing nobody thought of.
     */
    public function expected(Shift $shift): int
    {
        $takings = $this->takings($shift);
        $movements = $this->movements($shift);

        return (int) $shift->opening_float
            + $takings['cash']
            - $takings['cash_refunded']
            + $movements['in']
            - $movements['out'];
    }

    /**
     * What this till has taken, split by how it was paid for.
     *
     * A sale that was later refunded still counts as cash taken, with the refund counted beside
     * it: the money went into the drawer and then came out again, and a line reading nought
     * against both would hide an evening's worth of handing money back. What it does not include
     * is a booking that was cancelled before it was ever paid for, which took nothing.
     *
     * Refunds are counted against the shift that made the sale, not the shift that granted the
     * refund: the money left the drawer that is short, and a shift that handed back this morning
     * what another sold last night would otherwise report a hole where there is none. A refund
     * granted after the close is reported in the shift's summary but cannot move a discrepancy
     * already written down.
     *
     * @return array{cash:int, card:int, transfer:int, owed:int, comp:int, cash_refunded:int, orders:int}
     */
    public function takings(Shift $shift): array
    {
        $rows = ExternalOrder::query()
            ->toBase()
            ->where('external_orders.shift_id', $shift->id)
            // A rehearsed sale at the window took no money out of anybody's hand, and counting it
            // would report a drawer with more in it than the operator ever received.
            ->tap(fn ($query) => Live::only($query, 'external_orders.event_id'))
            ->selectRaw(<<<'SQL'
                count(*) filter (where status in ('confirmed', 'partially_refunded', 'refunded')) as orders,
                coalesce(sum(total_amount) filter (
                    where status in ('confirmed', 'partially_refunded', 'refunded')
                      and metadata->>'method' = 'cash'
                      and metadata->>'payment' = 'paid'
                ), 0) as cash,
                coalesce(sum(total_amount) filter (
                    where status in ('confirmed', 'partially_refunded', 'refunded')
                      and metadata->>'method' = 'card'
                      and metadata->>'payment' = 'paid'
                ), 0) as card,
                coalesce(sum(total_amount) filter (
                    where status in ('confirmed', 'partially_refunded', 'refunded')
                      and metadata->>'method' = 'transfer'
                      and metadata->>'payment' = 'paid'
                ), 0) as transfer,
                coalesce(sum(total_amount) filter (
                    where status in ('confirmed', 'partially_refunded', 'refunded')
                      and metadata->>'payment' = 'owed'
                ), 0) as owed,
                count(*) filter (where metadata->>'payment' = 'comp') as comp
            SQL)
            ->first();

        /*
         * What has been handed back, counted from the seats that went back rather than from a
         * column on the order.
         *
         * There is no `refunded_amount` anywhere on this platform, deliberately: a refund releases
         * allocations, and the money that came off is the sum of what those seats were sold at. A
         * total kept beside them would be a second opinion about the same event, and the two would
         * differ the first time a partial refund was retried.
         */
        $refunded = (int) DB::table('allocations')
            ->join('external_orders as o', 'o.id', '=', 'allocations.external_order_row_id')
            ->where('o.shift_id', $shift->id)
            ->tap(fn ($query) => Live::only($query, 'o.event_id'))
            ->whereRaw("o.metadata->>'method' = 'cash'")
            ->whereRaw("o.metadata->>'payment' = 'paid'")
            ->whereIn('allocations.status', ['released', 'void'])
            ->sum('allocations.amount');

        return [
            'orders' => (int) ($rows->orders ?? 0),
            'cash' => (int) ($rows->cash ?? 0),
            'card' => (int) ($rows->card ?? 0),
            'transfer' => (int) ($rows->transfer ?? 0),
            'owed' => (int) ($rows->owed ?? 0),
            'comp' => (int) ($rows->comp ?? 0),
            'cash_refunded' => $refunded,
        ];
    }

    /** @return array{in:int, out:int} */
    public function movements(Shift $shift): array
    {
        $rows = ShiftMovement::query()
            ->toBase()
            ->where('shift_id', $shift->id)
            ->selectRaw(
                "coalesce(sum(amount) filter (where kind = 'in'), 0) as put_in, ".
                "coalesce(sum(amount) filter (where kind = 'out'), 0) as taken_out"
            )
            ->first();

        return ['in' => (int) ($rows->put_in ?? 0), 'out' => (int) ($rows->taken_out ?? 0)];
    }

    /** Money in or out of the drawer for a reason that is not a sale. */
    public function move(Shift $shift, string $kind, int $amount, string $reason, ?string $userId): ShiftMovement
    {
        if (! $shift->isOpen()) {
            throw ApiException::conflict('till_closed', 'That till is closed.');
        }

        if ($amount < 1) {
            throw ApiException::unprocessable('movement_needs_amount', 'Say how much moved.');
        }

        return ShiftMovement::create([
            'tenant_id' => $shift->tenant_id,
            'shift_id' => $shift->id,
            'kind' => 'in' === $kind ? 'in' : 'out',
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $userId,
        ]);
    }

    /**
     * Count the drawer and close.
     *
     * The difference is not an error to be corrected: it is the finding. A till that is four euros
     * over is as interesting as one that is four euros short, and neither is fixed by changing a
     * number afterwards — which is why nothing here lets a closed shift be reopened or recounted.
     */
    public function close(Shift $shift, int $counted, ?string $note = null): Shift
    {
        if (! $shift->isOpen()) {
            throw ApiException::conflict('till_closed', 'That till is closed.');
        }

        return DB::transaction(function () use ($shift, $counted, $note) {
            $shift->forceFill([
                'counted_cash' => max(0, $counted),
                'expected_cash' => $this->expected($shift),
                'note' => $note,
                'closed_at' => now(),
            ])->save();

            return $shift->fresh();
        });
    }

    /**
     * Everything a screen needs about one shift.
     *
     * `difference` is counted minus expected, so a positive number is an over and a negative one a
     * short — the way a person says it, rather than the way a ledger would.
     *
     * @return array<string, mixed>
     */
    public function summary(Shift $shift): array
    {
        $takings = $this->takings($shift);
        $movements = $this->movements($shift);
        $expected = $shift->isOpen() ? $this->expected($shift) : (int) $shift->expected_cash;

        return [
            'id' => $shift->id,
            'user' => $shift->user?->name,
            'event' => $shift->event?->name,
            'currency' => $shift->currency,
            'opening_float' => (int) $shift->opening_float,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'open' => $shift->isOpen(),
            'takings' => $takings,
            'movements' => $movements,
            'expected_cash' => $expected,
            'counted_cash' => $shift->isOpen() ? null : (int) $shift->counted_cash,
            // Null while it is open: a drawer nobody has counted has no discrepancy, and a zero
            // there would read as "it balanced".
            'difference' => $shift->isOpen() ? null : (int) $shift->counted_cash - $expected,
            'note' => $shift->note,
        ];
    }
}
