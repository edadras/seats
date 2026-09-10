<?php

namespace App\Domain\Resale;

use App\Domain\Vouchers\Vouchers;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\ResaleListing;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Putting a ticket back on sale at what it cost, instead of selling it outside on the night.
 *
 * A venue that offers this takes the touts' business away from them and gets a full house rather
 * than an empty seat and a refund. The whole design turns on one decision:
 *
 * **A listing moves no inventory.** The obvious implementation — release the seat when it is
 * listed, sell it again, refund the seller — takes the ticket away from somebody who is still
 * going if nobody buys it. So the allocation stays exactly where it is and the seat is merely
 * *offered* while the listing is open (see the availability SQL and HoldService, both of which
 * treat an allocation with an open listing as not occupying its seat). Everything happens at the
 * moment somebody else pays, in one transaction: the old allocation is released, its ticket
 * voided, the seller paid, and the new allocation written.
 *
 * **The seller is paid in credit by default.** Not meanness: a refund to a card charged eleven
 * months ago fails often enough that promising it is dishonest, and a voucher is the form of "your
 * money back" a venue can promise to honour on the spot. An organiser may choose otherwise.
 *
 * **Face value, always.** There is no field for a price, and there will not be one. A platform
 * that let a seller name their own price would be a touting platform with better paperwork.
 */
class Resales
{
    public function __construct(private readonly Vouchers $vouchers) {}

    /**
     * Offer a seat back to the public.
     *
     * @throws ApiException where the night does not allow it, or the seat is not theirs to offer
     */
    public function list(Allocation $allocation, string $sellerEmail, ?string $sellerName = null): ResaleListing
    {
        /*
         * Both fetched here rather than read off whatever the caller happened to eager-load.
         *
         * Lazy loading is off across the application, and an allocation has no `event` relation at
         * all — so `$allocation->event` is not a query that fails loudly, it is an undefined
         * attribute that is quietly null, and a null event here reads as "this night does not take
         * tickets back" and refuses a listing that should have stood.
         */
        $allocation->loadMissing('ticket');

        $event = Event::find($allocation->event_id);

        if (! $event || ! $event->resale) {
            throw ApiException::conflict('resale_closed', 'This event does not take tickets back for resale.');
        }

        if ('active' !== $allocation->status) {
            throw ApiException::conflict('resale_not_yours', 'That ticket is not on sale to give back.');
        }

        /*
         * Named seats only, and this is a refusal rather than an omission.
         *
         * A standing ticket is a right to come in, not a particular chair, so there is nothing to
         * hand to one person rather than another: "resell my place in the pit" would mean selling
         * one more admission and refunding somebody, which is a different feature with different
         * arithmetic. Refused out loud, because a listing that quietly never sold would be worse.
         */
        if (! $allocation->seat_id) {
            throw ApiException::conflict(
                'resale_not_seated',
                'A standing ticket is not a particular seat, so it cannot be offered to one buyer.',
            );
        }

        // A seat somebody has already walked through the door on is not a seat anybody can buy.
        if ($allocation->ticket && 'used' === $allocation->ticket->status) {
            throw ApiException::conflict('resale_used', 'That ticket has already been scanned at the door.');
        }

        if ($event->starts_at && $event->starts_at->isPast()) {
            throw ApiException::conflict('resale_over', 'That night has already happened.');
        }

        // Already on offer: the same answer as putting it up, so a form sent twice does not become
        // two listings. A *closed* listing against this seat is history and does not stand in the
        // way of offering it again — somebody may think better of taking it down.
        $existing = ResaleListing::where('allocation_id', $allocation->id)
            ->where('state', 'open')
            ->first();

        if ($existing) {
            return $existing;
        }

        return ResaleListing::create([
            'tenant_id' => $allocation->tenant_id,
            'event_id' => $allocation->event_id,
            'allocation_id' => $allocation->id,
            'seller_email' => mb_strtolower(trim($sellerEmail)),
            'seller_name' => $sellerName,
            // What they paid, and nothing else. See the class note.
            'amount' => (int) $allocation->amount,
            'currency' => (string) $allocation->currency,
            'state' => 'open',
            'listed_at' => now(),
        ]);
    }

    /** Change their mind: the seat stops being offered and was theirs the whole time. */
    public function withdraw(ResaleListing $listing): ResaleListing
    {
        if (! $listing->isOpen()) {
            throw ApiException::conflict('resale_settled', 'That listing is already finished with.');
        }

        /*
         * Not while somebody is at the checkout with it.
         *
         * A seat taken off sale under a live basket would be a seat the buyer is about to pay for
         * and cannot have: `allocations_one_active_per_seat` would refuse the new row and the
         * payment would fail with nothing anybody could act on. The hold lasts minutes, so the
         * answer is to wait for it — said out loud rather than by failing later.
         */
        if ($this->beingBought($listing)) {
            throw ApiException::conflict(
                'resale_being_bought',
                'Somebody is buying that seat at this moment. It can be taken off sale again if they do not finish.',
            );
        }

        $listing->forceFill(['state' => 'withdrawn', 'settled_at' => now()])->save();
        $listing->loadMissing('event');
        $listing->event?->bumpAvailabilityVersion();

        return $listing;
    }

    /** Is there a live basket holding the seat this listing offers? */
    private function beingBought(ResaleListing $listing): bool
    {
        $seatId = Allocation::whereKey($listing->allocation_id)->value('seat_id');

        if (! $seatId) {
            return false;
        }

        return DB::table('hold_items')
            ->join('holds', 'holds.id', '=', 'hold_items.hold_id')
            ->where('hold_items.event_id', $listing->event_id)
            ->where('hold_items.seat_id', $seatId)
            ->whereNull('hold_items.released_at')
            ->where('holds.status', 'active')
            ->where('holds.expires_at', '>', now())
            ->exists();
    }

    /**
     * Somebody else has bought these seats: hand them over and pay the sellers.
     *
     * Called from inside the confirmation's own transaction, before the new allocations are
     * written, because the partial unique index on (event_id, seat_id) WHERE active would
     * otherwise refuse the new row — and because a swap that half happened would be a seat sold
     * twice or nobody's at all.
     *
     * @param  list<string>  $seatIds
     * @return int how many seats changed hands this way
     */
    public function settleSeats(Event $event, array $seatIds, ExternalOrder $buyer): int
    {
        if ([] === $seatIds) {
            return 0;
        }

        $listings = ResaleListing::query()
            ->where('event_id', $event->id)
            ->where('state', 'open')
            ->whereIn('allocation_id', Allocation::query()
                ->where('event_id', $event->id)
                ->where('status', 'active')
                ->whereIn('seat_id', $seatIds)
                ->select('id'))
            ->lockForUpdate()
            ->get();

        foreach ($listings as $listing) {
            $this->handOver($listing, $event, $buyer);
        }

        return $listings->count();
    }

    /**
     * One seat changing hands.
     *
     * The order matters and is the whole of the correctness here: release, void, pay, mark. The
     * release has to come first or the new allocation cannot be written; the payment has to be
     * inside the same transaction or a crash between them leaves somebody with neither the seat
     * nor the money.
     */
    private function handOver(ResaleListing $listing, Event $event, ExternalOrder $buyer): void
    {
        $allocation = Allocation::whereKey($listing->allocation_id)->lockForUpdate()->first();

        if (! $allocation || 'active' !== $allocation->status) {
            // Refunded or otherwise gone while the basket was open. Nothing was sold twice: the
            // listing simply has nothing behind it any more.
            $listing->forceFill(['state' => 'withdrawn', 'settled_at' => now()])->save();

            return;
        }

        $allocation->forceFill(['status' => 'released', 'released_at' => now()])->save();

        Ticket::where('allocation_id', $allocation->id)->update([
            'status' => 'void',
            'voided_at' => now(),
            'updated_at' => now(),
        ]);

        $voucher = null;

        if ('credit' === ($event->resale_pays ?? 'credit')) {
            $voucher = $this->vouchers->credit(
                (string) $listing->tenant_id,
                (string) $listing->seller_email,
                (int) $listing->amount,
                (string) $listing->currency,
                // Said in the note, because a voucher that appears in somebody's account with no
                // explanation is a support email.
                __('panel.vouchers.creditFromResale', [
                    'seat' => trim(($allocation->row_name ?? '').' '.($allocation->seat_label ?? '')),
                ]),
                null,
            );
        }

        $listing->forceFill([
            'state' => 'sold',
            'settled_at' => now(),
            'voucher_id' => $voucher?->id,
        ])->save();

        // The seller's order is now short of a seat. Its status follows the same rule a partial
        // refund follows, so a booking with one seat left still reads as a booking.
        $order = ExternalOrder::whereKey($allocation->external_order_row_id)->first();

        if ($order) {
            $left = Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count();

            $order->forceFill([
                'status' => $left > 0 ? 'partially_refunded' : 'refunded',
                'refunded_at' => now(),
            ])->save();
        }
    }

    /**
     * What is on offer for one night, for the panel.
     *
     * @return array<string, mixed>
     */
    public function forEvent(Event $event): array
    {
        $listings = ResaleListing::with('allocation')
            ->where('event_id', $event->id)
            ->orderByDesc('listed_at')
            ->limit(200)
            ->get();

        return [
            'open' => $listings->where('state', 'open')->count(),
            'sold' => $listings->where('state', 'sold')->count(),
            'data' => $listings->map(fn (ResaleListing $listing) => [
                'id' => $listing->id,
                'state' => $listing->state,
                'seat' => trim(($listing->allocation?->section_name ?? '').' '.
                    ($listing->allocation?->row_name ?? '').' '.
                    ($listing->allocation?->seat_label ?? '')),
                'seller' => $listing->seller_name ?: $listing->seller_email,
                'amount' => (int) $listing->amount,
                'currency' => $listing->currency,
                'listed_at' => $listing->listed_at?->toIso8601String(),
                'settled_at' => $listing->settled_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
