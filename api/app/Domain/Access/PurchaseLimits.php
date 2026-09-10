<?php

namespace App\Domain\Access;

use App\Exceptions\ApiException;
use App\Models\ApiClient;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * How many one person may buy of one night.
 *
 * `max_seats_per_order` stops nothing on its own: four at a time, six times over, is twenty-four.
 * A limit that means anything is counted across everything that person has already bought, which
 * is what this does.
 *
 * **Where it is checked, and where it deliberately is not.** Before the money, always: the hosted
 * checkout asks before it charges, and a shop's order is registered before it takes payment. Never
 * at confirmation, which happens *after* the gateway has settled — refusing there would mean money
 * taken for a booking that does not exist, and one ticket over a limit is a smaller wrong than
 * that. The consequence is that two payments started in the same second can both get through. That
 * is a real hole, it is small, and it is the right way round.
 *
 * **The counter is exempt.** A limit is a rule for a website; the person at the window is standing
 * in front of the clerk, and an organiser who wants to say no to them can say no to them.
 *
 * **A person is an email address**, said plainly rather than implied. It is the only identity a
 * ticket buyer has here — no account, no device fingerprint — and somebody determined can use a
 * second address. This is not the defence against that. It is the defence against the ordinary
 * case: one person quietly buying half the front row of a show that is going to sell out.
 */
class PurchaseLimits
{
    /** Statuses that mean somebody is holding tickets they have paid for. */
    private const PAID = ['confirmed', 'partially_refunded'];

    /**
     * How many places this address already holds for this night.
     *
     * Counted from live allocations rather than from orders: a refunded seat is a seat this person
     * no longer has, and a limit that remembered it would tell somebody who cancelled last week
     * that they may not come at all.
     */
    public function bought(Event $event, string $email): int
    {
        $email = mb_strtolower(trim($email));

        if ('' === $email) {
            return 0;
        }

        return (int) DB::table('allocations')
            ->join('external_orders as o', 'o.id', '=', 'allocations.external_order_row_id')
            ->where('allocations.event_id', $event->id)
            ->where('allocations.status', 'active')
            ->whereIn('o.status', self::PAID)
            ->whereRaw("lower(btrim(o.buyer->>'email')) = ?", [$email])
            ->selectRaw('COALESCE(SUM(COALESCE(allocations.quantity, 1)), 0) as places')
            ->value('places');
    }

    /**
     * Refuse a basket that would take one person past this night's limit.
     *
     * @throws ApiException naming what is left, because "no" without a number sends somebody to
     *                      the telephone
     */
    public function assertWithin(Event $event, ?string $email, int $wanted, ?ApiClient $client = null): void
    {
        $limit = (int) ($event->max_per_buyer ?? 0);

        if ($limit < 1 || $wanted < 1) {
            return;
        }

        // The window is not a website. See the class note.
        if ($client && 'box_office' === $client->kind) {
            return;
        }

        // Nobody to count against. A sale with no address is a counter sale or a comp, both of
        // which are somebody's deliberate decision rather than a stranger with a script.
        if (! $email || '' === trim($email)) {
            return;
        }

        $already = $this->bought($event, $email);
        $left = max(0, $limit - $already);

        if ($wanted > $left) {
            $key = 0 === $left ? 'errors.buyer_limit_used' : 'errors.buyer_limit_reached';

            throw new ApiException(
                'buyer_limit_reached',
                0 === $left
                    ? sprintf('This event is limited to %d per person, and you already have that many.', $limit)
                    : sprintf('This event is limited to %d tickets per person; you may take %d more.', $limit, $left),
                409,
                ['limit' => $limit, 'already' => $already, 'left' => $left, 'wanted' => $wanted],
                $key,
                ['count' => $left, 'limit' => $limit],
            );
        }
    }
}
