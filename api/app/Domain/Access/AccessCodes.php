<?php

namespace App\Domain\Access;

use App\Exceptions\ApiException;
use App\Models\AccessCode;
use App\Models\AccessCodeUse;
use App\Models\Event;
use App\Models\Hold;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Codes that open a sale, and the arithmetic that keeps a cap honest.
 *
 * A use is spent when a seat leaves inventory, not when an order is paid for. That is the whole
 * point of a presale: checking at the till would be a race anybody could join and only lose after
 * choosing their seats. It also means a use has to be *returned* when a hold expires, or a mailing
 * list of a hundred becomes a mailing list of one after ninety-nine people opened a basket and
 * wandered off.
 *
 * The cap is a sum against a limit, so it is recomputed under an advisory lock keyed on the code —
 * the same defence a standing area and a timed-entry window get, and for the same reason: no
 * unique index can express "at most fifty live uses".
 */
class AccessCodes
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The code as this account stores it, or nothing. */
    public function find(string $typed): ?AccessCode
    {
        $code = AccessCode::normalise($typed);

        return '' === $code ? null : AccessCode::where('code', $code)->first();
    }

    /**
     * What a buyer is told when they type a code into the box on an event page.
     *
     * Deliberately not the same answer as `spend()`: this one runs before any seats are chosen, so
     * it can say "yes, and it is good for four seats" rather than only yes or no. It does not
     * reserve anything — two people may both be told yes and only one of them get in, which is
     * true of every sale and is why the real check happens under the lock.
     */
    public function offer(string $typed, Event $event, ?\DateTimeInterface $at = null): AccessOffer
    {
        $code = $this->find($typed);
        $at = $at ?: now();

        if (! $code || ! $code->covers($event)) {
            return AccessOffer::refused('unknown_code');
        }

        if (! $code->isLive($at)) {
            return AccessOffer::refused('code_not_live');
        }

        if ($this->isUsedUp($code)) {
            return AccessOffer::refused('code_used_up');
        }

        $state = SaleWindow::state($event, $at);

        if (SaleWindow::CLOSED === $state) {
            return AccessOffer::refused('event_not_sellable');
        }

        // A `presale` code before the presale has even opened is a code for the right sale on the
        // wrong night. Only an `always` code gets in ahead of that.
        if (SaleWindow::WAITING === $state && 'always' !== $code->opens) {
            return AccessOffer::refused('presale_not_open');
        }

        return AccessOffer::accepted($code);
    }

    /**
     * May this hold be taken at all, and if so against which code?
     *
     * Called from inside the hold transaction, where the answer is the one that counts.
     *
     * @throws ApiException when the sale is shut to this buyer
     */
    public function admit(Event $event, ?string $typed, int $seats, ?string $buyerEmail = null): ?AccessCode
    {
        $state = SaleWindow::state($event);

        if (SaleWindow::OPEN === $state) {
            // General sale. A code may still be presented — it might unlock a members' price —
            // but a wrong one is not a reason to refuse a sale that is open to everybody.
            return $typed ? $this->find($typed) : null;
        }

        if (SaleWindow::CLOSED === $state) {
            throw ApiException::conflict('event_not_sellable', 'This event is not on sale.');
        }

        /*
         * Standing, where this night lets a standing in.
         *
         * Checked before the code and only during a presale proper: a night that has not opened at
         * all has not opened for anybody, and a tier is an invitation to come early rather than a
         * key to a door nobody has unlocked yet.
         *
         * It returns no code because there is none. Everything downstream treats null as "came in
         * without one", which is exactly what happened — a subscriber walking past the queue is
         * not spending a code's last use.
         */
        if (SaleWindow::PRESALE === $state
            && $event->tier_presale
            && $buyerEmail
            && app(\App\Domain\Loyalty\Loyalty::class)->standsAtLeast($buyerEmail, (string) $event->tier_presale)) {
            return null;
        }

        /*
         * And a Friend, where this night lets members in.
         *
         * The same door as a tier and for the same reason: a venue that sells a membership on the
         * promise of booking first has to honour it without issuing everybody a code. Both are
         * checked because a venue may run both, and either is enough on its own.
         */
        if (SaleWindow::PRESALE === $state
            && $event->member_presale
            && $buyerEmail
            && app(\App\Domain\Memberships\Memberships::class)->admitsToPresale($buyerEmail)) {
            return null;
        }

        if (null === $typed || '' === trim($typed)) {
            throw ApiException::conflict(
                'access_code_required',
                'This event is in presale. A code is needed to buy from it.',
                ['sale_state' => $state],
            );
        }

        $offer = $this->offer($typed, $event, now());

        if (! $offer->ok) {
            /*
             * Each refusal names itself.
             *
             * A `throw ApiException::conflict($offer->reason, …)` would be three lines shorter and
             * would leave five codes that no catalogue check can see, because the code would be
             * decided at run time. These are the sentences a buyer reads when a presale turns them
             * away, which is exactly the moment not to be shrugged at in English.
             */
            throw match ($offer->reason) {
                'unknown_code' => ApiException::conflict(
                    'unknown_code',
                    'That code is not right.'
                ),
                'code_not_live' => ApiException::conflict(
                    'code_not_live',
                    'That code is not working at the moment.'
                ),
                'code_used_up' => ApiException::conflict(
                    'code_used_up',
                    'That code has been used as often as it can be.'
                ),
                'presale_not_open' => ApiException::conflict(
                    'presale_not_open',
                    'The presale has not opened yet.'
                ),
                default => ApiException::conflict(
                    'event_not_sellable',
                    'This event is not on sale.'
                ),
            };
        }

        $code = $offer->code;

        if ($code->max_seats && $seats > $code->max_seats) {
            throw ApiException::unprocessable(
                'access_code_seat_limit',
                sprintf('That code is good for %d seats at a time.', $code->max_seats),
                ['max_seats' => $code->max_seats],
                ['count' => $code->max_seats],
            );
        }

        return $code;
    }

    /**
     * Spend one use on a hold.
     *
     * Under the lock and inside the caller's transaction: the count is recomputed here and nowhere
     * else, because everywhere else it is already out of date.
     *
     * @throws ApiException when the code has run out between being offered and being spent
     */
    public function spend(AccessCode $code, Hold $hold, int $seats): void
    {
        $this->lock($code);

        if ($this->isUsedUp($code)) {
            throw ApiException::conflict('code_used_up', 'That code has just been used for the last time.');
        }

        AccessCodeUse::create([
            'tenant_id' => $code->tenant_id,
            'access_code_id' => $code->id,
            'hold_id' => $hold->id,
            'seats' => $seats,
        ]);
    }

    /**
     * Give a use back.
     *
     * Called when a hold expires or is released. Idempotent: releasing twice is a no-op, because a
     * hold that is swept and then released by a buyer's browser is an ordinary Tuesday.
     */
    public function release(Hold $hold): void
    {
        AccessCodeUse::where('hold_id', $hold->id)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    /**
     * Keep the use, and attach it to the order it became.
     *
     * A confirmed order is what a use was spent on; it is not released when the hold that carried
     * it is finished with, or the cap would leak one seat at a time.
     */
    public function settle(Hold $hold, string $orderRowId): void
    {
        AccessCodeUse::where('hold_id', $hold->id)
            ->whereNull('released_at')
            ->update(['external_order_row_id' => $orderRowId]);
    }

    /** Live uses: a hold that is still running, or an order that was placed. */
    public function used(AccessCode $code): int
    {
        return (int) DB::table('access_code_uses as u')
            ->leftJoin('holds as h', 'h.id', '=', 'u.hold_id')
            ->where('u.access_code_id', $code->id)
            ->whereNull('u.released_at')
            ->where(function ($query) {
                $query->whereNotNull('u.external_order_row_id')
                    ->orWhere(function ($live) {
                        $live->where('h.status', 'active')->where('h.expires_at', '>', now());
                    });
            })
            ->count();
    }

    public function isUsedUp(AccessCode $code): bool
    {
        return null !== $code->max_uses && $this->used($code) >= $code->max_uses;
    }

    /**
     * Serialise every buyer of one code behind the others.
     *
     * Keyed on the code alone, so a rush on the members' code never delays the radio station's.
     */
    private function lock(AccessCode $code): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['access-code:'.$code->id],
        );
    }

    public function record(string $action, AccessCode $code, array $context = []): void
    {
        $this->audit->record($action, $code, $context + ['code' => $code->code]);
    }
}
