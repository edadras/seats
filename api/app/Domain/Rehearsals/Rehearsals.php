<?php

namespace App\Domain\Rehearsals;

use App\Exceptions\ApiException;
use App\Models\AccessCodeUse;
use App\Models\Allocation;
use App\Models\Checkin;
use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\Site;
use App\Models\MessageDelivery;
use App\Models\Ticket;
use App\Models\Voucher;
use App\Models\VoucherMovement;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * A night rehearsed: bought, paid for, emailed, scanned and then swept away.
 *
 * What an organiser needs before an onsale is not a sandbox account with its own login and its own
 * copy of everything — that is a second platform to keep in step, and the bugs that matter are
 * always in the one thing a copy cannot reproduce, which is this venue's own prices, fees, email
 * wording and seating plan. What they need is to walk their own checkout, on their own site, and
 * then have it never have happened.
 *
 * So a rehearsal is the real event, flagged, and three rules hold it together:
 *
 *   1. **No money can move.** A rehearsal's checkout never reaches a gateway — it is handed
 *      {@see RehearsalGateway}, which is not in the registry and cannot be chosen by a site.
 *   2. **No figure counts it.** Every account-wide total asks `Live::only()`, which is one question
 *      about the event rather than a flag on each row that could disagree with it.
 *   3. **Nothing is left behind.** {@see clear()} removes the bookings *and* what they consumed:
 *      a discount code's used count, a gift voucher's balance, a presale code's use, the scans at
 *      the door, the emails in the log.
 *
 * The two refusals are what make the second rule sound. An event with bookings on it cannot become
 * a rehearsal, and a rehearsal with bookings on it cannot go back to selling until it is cleared.
 * Together they mean "a booking on a rehearsal night" and "a rehearsal booking" are the same set,
 * always — so no report has to hold an opinion about which of two flags to believe.
 *
 * One thing is deliberately left alone: people who joined the waiting list while the night was
 * being rehearsed stay on it. They are not something a rehearsal created — they are strangers who
 * want to come — and the night they queued for is the night that goes on sale.
 */
class Rehearsals
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Start rehearsing a night.
     *
     * Refused once anything has been sold, and that refusal is the whole guarantee: if a night with
     * real bookings could be flagged, the account's takings would drop by whatever it had already
     * taken, and no amount of clearing afterwards would bring back the fact that those bookings
     * were real.
     */
    public function start(Event $event): Event
    {
        if ($event->is_rehearsal) {
            return $event;
        }

        if (ExternalOrder::where('event_id', $event->id)->exists()) {
            throw ApiException::conflict(
                'rehearsal_has_real_bookings',
                'This night has already sold something, so it cannot be rehearsed. Copy it to a new date and rehearse that instead.'
            );
        }

        $event->forceFill(['is_rehearsal' => true])->save();

        $this->audit->record('rehearsal.started', $event, ['event' => $event->name]);

        return $event;
    }

    /**
     * Stop rehearsing, and start selling.
     *
     * Refused while rehearsal bookings are still on the night. Clearing first is not a tidiness
     * rule: those bookings hold seats and carry tickets that would scan at the door, and the moment
     * the flag comes off they would be counted as takings nobody ever received.
     */
    public function finish(Event $event): Event
    {
        if (! $event->is_rehearsal) {
            return $event;
        }

        $left = ExternalOrder::where('event_id', $event->id)->count();

        if ($left > 0) {
            throw ApiException::conflict(
                'rehearsal_not_cleared',
                'Clear the rehearsal first, or its bookings would go on sale as real ones.',
                ['bookings' => $left],
            );
        }

        $event->forceFill(['is_rehearsal' => false])->save();

        $this->audit->record('rehearsal.finished', $event, ['event' => $event->name]);

        return $event;
    }

    /**
     * Sweep a rehearsal away, including what it spent.
     *
     * Order matters. The rows that *point at* an order go first, or Postgres will have nulled their
     * foreign key by the time we look for them — `nullOnDelete` is right for a real order being
     * removed under a privacy request, and wrong here, because an emptied column is exactly the
     * evidence this method needs to find the row at all.
     *
     * Deleting allocations takes their tickets with them by cascade, and with the seats gone the
     * hall is free again: availability on this platform is derived from live allocations, never
     * stored, so there is no counter anywhere to put back.
     *
     * @return array<string, int> what went, for the screen that asked
     */
    public function clear(Event $event): array
    {
        if (! $event->is_rehearsal) {
            throw ApiException::denied(
                'not_a_rehearsal',
                'This night is selling for real. Its bookings are not ours to delete.'
            );
        }

        return DB::transaction(function () use ($event) {
            $orderIds = ExternalOrder::where('event_id', $event->id)->pluck('id')->all();

            $gone = [
                'bookings' => count($orderIds),
                // Scans rather than tickets: every ticket on a rehearsed night is a rehearsal
                // ticket, so the whole event's door log goes, and it goes before the tickets do.
                'scans' => Checkin::where('event_id', $event->id)->delete(),
                'tickets' => Ticket::where('event_id', $event->id)->count(),
            ];

            if ($orderIds) {
                $this->giveBackDiscounts($orderIds);

                $gone['messages'] = MessageDelivery::whereIn('external_order_row_id', $orderIds)->delete();
                $gone['presale_codes'] = AccessCodeUse::whereIn('external_order_row_id', $orderIds)->delete();

                /*
                 * A gift voucher is real money even on a rehearsed night: the balance sits on a card
                 * somebody paid for. Its balance is the sum of its movements, so deleting the
                 * movements this rehearsal wrote puts every penny back where it was — and a voucher
                 * *bought* during the rehearsal was never paid for, so it goes entirely.
                 */
                $gone['voucher_movements'] = VoucherMovement::whereIn('external_order_row_id', $orderIds)->delete();
                $gone['vouchers'] = Voucher::whereIn('bought_with_order_id', $orderIds)->delete();
            }

            // Seats before bookings: `allocations.external_order_row_id` is nulled rather than
            // cascaded, so an allocation orphaned by the delete below would hold its seat for ever.
            $gone['seats'] = Allocation::where('event_id', $event->id)->delete();

            // Baskets somebody left mid-rehearsal, and the one the sale itself converted.
            $gone['baskets'] = Hold::where('event_id', $event->id)->delete();

            ExternalOrder::whereIn('id', $orderIds)->delete();

            $this->audit->record('rehearsal.cleared', $event, ['event' => $event->name] + $gone);

            return $gone;
        });
    }

    /**
     * Hand back the uses a rehearsal spent out of a discount code.
     *
     * The only counter on this platform that a rehearsal can move. `used_count` is a column rather
     * than a count of redemptions because the check that enforces a code's limit has to be a single
     * atomic statement, and the redemptions cascade away with their orders — so without this the
     * code would be one use poorer for every rehearsed booking, silently, for ever.
     */
    private function giveBackDiscounts(array $orderIds): void
    {
        $spent = DiscountRedemption::whereIn('external_order_row_id', $orderIds)
            ->selectRaw('discount_code_id, count(*) as uses')
            ->groupBy('discount_code_id')
            ->pluck('uses', 'discount_code_id');

        foreach ($spent as $codeId => $uses) {
            DiscountCode::whereKey($codeId)->update([
                'used_count' => DB::raw('GREATEST(0, used_count - '.(int) $uses.')'),
            ]);
        }
    }

    /** What a rehearsal has done so far, for the screen that offers to clear it. */
    public function tally(Event $event): array
    {
        $orders = ExternalOrder::where('event_id', $event->id)->get(['id', 'status', 'total_amount', 'currency']);

        return [
            'rehearsing' => (bool) $event->is_rehearsal,
            'bookings' => $orders->count(),
            'confirmed' => $orders->whereIn('status', ['confirmed', 'partially_refunded', 'refunded'])->count(),
            'seats' => Allocation::where('event_id', $event->id)->where('status', 'active')->count(),
            'tickets' => Ticket::where('event_id', $event->id)->count(),
            'scans' => Checkin::where('event_id', $event->id)->count(),
            // What would have been charged, had any of it been real. The sentence an organiser
            // reads before pressing clear is "twelve bookings, £418 that never moved".
            'not_charged' => (int) $orders->sum('total_amount'),
            'currency' => (string) ($orders->first()->currency ?? $event->currency),
            // Where to go and be the buyer. A rehearsal is a page somebody has to actually open,
            // and an organiser should not have to assemble the address out of their own site's
            // domain and a public id they have never seen.
            'link' => $this->pageFor($event),
        ];
    }

    /**
     * The address of the page a buyer would land on, if this account has a site to serve it.
     *
     * Null rather than a guess when it has none. A rehearsal on an account whose site is not live
     * yet is still worth running — the panel can sell at the window, and the figures still have to
     * stay out of the takings — so this is an offer, not a requirement.
     */
    private function pageFor(Event $event): ?string
    {
        $site = Site::where('status', 'live')->orderBy('created_at')->first();

        return $site?->url('/events/'.$event->public_id);
    }
}
