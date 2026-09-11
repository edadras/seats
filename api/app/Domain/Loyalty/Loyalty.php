<?php

namespace App\Domain\Loyalty;

use App\Domain\Vouchers\Vouchers;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgramme;
use App\Models\Voucher;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Points earned by coming, and what they are worth.
 *
 * Everything else on this platform is about one night. This is the first thing that is about the
 * years either side of it, and the shape it takes from the rest of the platform is the ledger: a
 * balance is the sum of what happened, never a column, so it cannot drift from the bookings that
 * produced it and there is nothing to repair when one of them is undone.
 *
 * Three decisions worth stating, because each of them is the reason something else is simple.
 *
 * **Settling, not awarding.** {@see settle()} works out what a booking is worth *now* and writes
 * the difference from what it has already been credited. Confirming, refunding a seat, refunding
 * the rest, a chargeback months later — all of them are the same call, it is safe to make twice,
 * and there is no separate "take the points back" path that could disagree with the giving one.
 *
 * **One currency.** A rate of "a point per euro" says nothing in an account that also sells in
 * rials, and a single pool fed by two currencies is arithmetic nobody can explain at a counter.
 * The programme names its currency; takings in any other simply do not earn.
 *
 * **Points buy credit, not tickets.** Redeeming issues an ordinary credit note against the buyer's
 * address — the same thing a refund taken as credit issues — which then pays for seats through the
 * checkout path that already exists, with its own expiry, its own ledger and its own tests. A
 * second kind of money at the checkout would be a second set of edge cases at the one place on
 * this platform where an edge case costs somebody their evening.
 */
class Loyalty
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Vouchers $vouchers,
        private readonly AuditLogger $audit,
    ) {}

    /** The account's programme, or null where nobody has set one up. */
    public function programme(): ?LoyaltyProgramme
    {
        return LoyaltyProgramme::first();
    }

    /**
     * Bring a booking's points up to date, whatever has happened to it.
     *
     * Called on confirmation, on refund, on cancellation and after a chargeback. It reads what the
     * booking is worth now and writes only the difference, so calling it twice writes nothing and
     * calling it after half the seats went back takes back half the points.
     *
     * @return int what changed, for the caller that wants to say so
     */
    public function settle(ExternalOrder $order): int
    {
        $programme = $this->programme();

        if (! $programme || ! $programme->isLive()) {
            return 0;
        }

        $email = $this->addressOf($order);

        if ('' === $email || $order->currency !== $programme->currency) {
            return 0;
        }

        $due = $this->worthOf($programme, $order);
        $given = (int) LoyaltyMovement::where('external_order_row_id', $order->id)->sum('points');
        $difference = $due - $given;

        if (0 === $difference) {
            return 0;
        }

        LoyaltyMovement::create([
            'tenant_id' => $order->tenant_id,
            'email' => $email,
            // A reversal is a negative earn rather than a deletion, so the history still says that
            // somebody came and then gave the seat back.
            'kind' => $difference > 0 ? 'earn' : 'reverse',
            'points' => $difference,
            'external_order_row_id' => $order->id,
        ]);

        return $difference;
    }

    /**
     * What a booking is worth at this moment.
     *
     * From the seats that are still live rather than from the order's total, for the same reason
     * every other figure on this platform is: a total is what was agreed, and what somebody still
     * holds is what they still hold. A comp earns nothing — it was a gift, and paying somebody
     * points for being given a seat is a programme that rewards the box office's generosity.
     */
    private function worthOf(LoyaltyProgramme $programme, ExternalOrder $order): int
    {
        if ('comp' === ($order->metadata['payment'] ?? null)) {
            return 0;
        }

        if (! in_array($order->status, ['confirmed', 'partially_refunded', 'refunded'], true)) {
            return 0;
        }

        if ($order->charged_back_at) {
            // Money that was taken back out of the account is not money anybody spent here.
            return 0;
        }

        $live = (int) Allocation::where('external_order_row_id', $order->id)
            ->where('status', 'active')
            ->sum('amount');

        $unit = 10 ** Money::exponent((string) $order->currency);

        return intdiv($live, $unit) * $programme->earn_rate;
    }

    /** What somebody has, counted rather than stored. */
    public function balance(string $email): int
    {
        return (int) LoyaltyMovement::where('email', $this->tidy($email))->sum('points');
    }

    /**
     * What somebody has earned inside the window the tiers are read over.
     *
     * Earned rather than held: spending points must not cost somebody their standing, or the
     * programme would be asking them to choose between the reward and the tier it came with.
     * Reversals count, because a booking that was given back was not an evening they came to.
     */
    public function earnedInWindow(LoyaltyProgramme $programme, string $email): int
    {
        return max(0, (int) LoyaltyMovement::where('email', $this->tidy($email))
            ->whereIn('kind', ['earn', 'reverse'])
            ->where('created_at', '>=', now()->subMonths(max(1, $programme->window_months)))
            ->sum('points'));
    }

    /**
     * Which rung somebody stands on, and what is left to the next.
     *
     * @return array{key:?string, name:?string, points:int, next:?array{key:string,name:string,needs:int}}
     */
    public function standing(string $email, ?LoyaltyProgramme $programme = null): array
    {
        $programme ??= $this->programme();

        if (! $programme) {
            return ['key' => null, 'name' => null, 'points' => 0, 'next' => null];
        }

        $earned = $this->earnedInWindow($programme, $email);
        $ladder = $programme->ladder();

        $here = null;
        $next = null;

        foreach ($ladder as $rung) {
            if ($earned >= $rung['from_points']) {
                $here = $rung;

                continue;
            }

            // The first rung above them, which is the only one worth telling them about.
            $next ??= $rung + ['needs' => $rung['from_points'] - $earned];
        }

        return [
            'key' => $here['key'] ?? null,
            'name' => $here['name'] ?? null,
            'points' => $earned,
            'next' => $next ? [
                'key' => $next['key'],
                'name' => $next['name'],
                'needs' => $next['needs'],
            ] : null,
        ];
    }

    /**
     * Whether somebody's standing is at or above a named rung.
     *
     * By position on the ladder rather than by name, so a tier renamed in March does not lock out
     * everybody who reached it in February.
     */
    public function standsAtLeast(string $email, string $tierKey): bool
    {
        $programme = $this->programme();

        if (! $programme || ! $programme->isLive() || '' === trim($tierKey)) {
            return false;
        }

        $ladder = $programme->ladder();
        $wanted = null;

        foreach ($ladder as $rung) {
            if ($rung['key'] === $tierKey) {
                $wanted = $rung;
            }
        }

        if (! $wanted) {
            return false;
        }

        return $this->earnedInWindow($programme, $email) >= $wanted['from_points'];
    }

    /**
     * Turn points into credit to spend here.
     *
     * Under an advisory lock keyed on the address, and the balance is read again inside it: the
     * whole point of the lock is that the answer may have changed while this request was waiting
     * for it, and two tabs redeeming at once is exactly the case a reward programme invites.
     */
    public function redeem(string $email, int $points): Voucher
    {
        $email = $this->tidy($email);
        $programme = $this->programme();

        if (! $programme || ! $programme->isLive() || $programme->points_per_unit < 1) {
            throw ApiException::denied('loyalty_closed', 'This account is not running a points scheme.');
        }

        if ($points < $programme->min_redeem) {
            throw ApiException::unprocessable(
                'loyalty_below_minimum',
                'That is fewer points than this scheme will turn into credit.',
                ['minimum' => $programme->min_redeem],
            );
        }

        return DB::transaction(function () use ($email, $points, $programme) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['loyalty:'.$email]);

            if ($this->balance($email) < $points) {
                throw ApiException::unprocessable(
                    'loyalty_not_enough',
                    'There are not that many points on this address.',
                    ['balance' => $this->balance($email)],
                );
            }

            /*
             * Rounded down to whole units of money, and the remainder is not taken.
             *
             * A scheme that charged 550 points for £5 because 500 was not a round number would be
             * a scheme people distrust. They keep the fifty.
             */
            $units = intdiv($points, $programme->points_per_unit);
            $spend = $units * $programme->points_per_unit;
            $amount = $units * (10 ** Money::exponent($programme->currency));

            LoyaltyMovement::create([
                'tenant_id' => $programme->tenant_id,
                'email' => $email,
                'kind' => 'spend',
                'points' => -$spend,
                'note' => 'credit',
            ]);

            $voucher = $this->vouchers->credit(
                $programme->tenant_id,
                $email,
                $amount,
                $programme->currency,
                __('site.points.creditNote', ['points' => $spend]),
            );

            $this->audit->record('loyalty.redeemed', $voucher, [
                'email' => $email,
                'points' => $spend,
                'amount' => $amount,
            ]);

            return $voucher;
        });
    }

    /** An organiser putting points on or taking them off by hand, with a reason. */
    public function adjust(string $email, int $points, ?string $why = null): LoyaltyMovement
    {
        $email = $this->tidy($email);

        $movement = LoyaltyMovement::create([
            'tenant_id' => $this->tenants->idOrFail(),
            'email' => $email,
            'kind' => 'adjust',
            'points' => $points,
            'note' => $why ? mb_substr($why, 0, 200) : null,
        ]);

        $this->audit->record('loyalty.adjusted', $movement, [
            'email' => $email,
            'points' => $points,
            'why' => $why,
        ]);

        return $movement;
    }

    /**
     * Everybody with points, for the organiser's screen.
     *
     * @return array{rows: list<array>, total: int}
     */
    public function members(int $perPage = 50, int $page = 1): array
    {
        $programme = $this->programme();

        // One query for the page and one for the count, both grouped the same way. Counting the
        // groups needs the grouping to happen first, which is what the subquery is for — a
        // `count(*)` beside a `group by` counts the rows inside each group instead.
        $grouped = fn () => LoyaltyMovement::query()
            ->toBase()
            ->where('tenant_id', $this->tenants->idOrFail())
            ->selectRaw('email, sum(points) as balance, max(created_at) as last_at')
            ->groupBy('email')
            ->havingRaw('sum(points) <> 0');

        $rows = $grouped()
            ->orderByRaw('sum(points) desc')
            ->forPage($page, $perPage)
            ->get();

        $total = DB::query()->fromSub($grouped(), 'counted')->count();

        return [
            'rows' => $rows->map(fn ($row) => [
                'email' => $row->email,
                'balance' => (int) $row->balance,
                'last_at' => $row->last_at,
            ] + ($programme ? $this->standing($row->email, $programme) : []))->all(),
            'total' => (int) $total,
        ];
    }

    /** What one address has done, newest first. */
    public function history(string $email, int $limit = 50): array
    {
        return LoyaltyMovement::where('email', $this->tidy($email))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (LoyaltyMovement $movement) => [
                'kind' => $movement->kind,
                'points' => $movement->points,
                'note' => $movement->note,
                'at' => $movement->created_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Points that have gone quiet for long enough.
     *
     * The whole balance at once rather than point by point. "Use it or lose it after two years"
     * is a rule somebody can check against their own receipts; first-in-first-out expiry of
     * individual points is a rule nobody can, and a programme whose arithmetic a customer cannot
     * reproduce is a programme they write to the box office about.
     *
     * @return int how many addresses were emptied
     */
    public function expireQuiet(LoyaltyProgramme $programme): int
    {
        if (! $programme->inactive_months) {
            return 0;
        }

        $since = now()->subMonths($programme->inactive_months);

        $quiet = LoyaltyMovement::query()
            ->selectRaw('email, sum(points) as balance, max(created_at) as last_at')
            ->groupBy('email')
            ->havingRaw('sum(points) > 0')
            ->havingRaw('max(created_at) <= ?', [$since])
            ->get();

        foreach ($quiet as $row) {
            LoyaltyMovement::create([
                'tenant_id' => $programme->tenant_id,
                'email' => $row->email,
                'kind' => 'expire',
                'points' => -(int) $row->balance,
                'note' => 'quiet',
            ]);
        }

        return $quiet->count();
    }

    /* --------------------------------------------------------------------------- helpers */

    private function addressOf(ExternalOrder $order): string
    {
        return $this->tidy((string) ($order->buyer['email'] ?? ''));
    }

    private function tidy(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
