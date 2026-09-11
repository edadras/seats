<?php

namespace App\Domain\Orders;

use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ApiClient;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\HoldItem;
use App\Models\Ticket;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The WooCommerce order lifecycle, from the SaaS side.
 *
 * Everything here has to survive being called twice. The plugin retries on timeouts, WooCommerce
 * fires status hooks more than once, and a reconciliation job re-drives anything that looks stuck.
 * So each method is written to be safe on replay: an already-confirmed order confirms to the same
 * result, a cancel after cancel is a no-op, and a refund of already-refunded seats changes nothing.
 */
class OrderService
{
    public function __construct(
        private readonly TicketIssuer $tickets,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly WebhookDispatcher $webhooks,
        private readonly \App\Domain\Messaging\OrderMessages $messages,
        private readonly \App\Domain\Notifications\Notifier $notifier,
        private readonly \App\Domain\Access\AccessCodes $access,
        private readonly \App\Domain\Vouchers\Vouchers $vouchers,
    ) {}

    /** Register an order against a hold — called as soon as WooCommerce creates the order. */
    /** Places in a hold, counting a standing area as the number of people it is for. */
    private function placesIn(Hold $hold): int
    {
        return (int) $hold->items()->sum(DB::raw('COALESCE(quantity, 1)'));
    }

    public function register(
        ApiClient $client,
        string $externalOrderId,
        string $holdToken,
        array $buyer = [],
        array $metadata = [],
    ): array {
        $existing = ExternalOrder::where('api_client_id', $client->id)
            ->where('external_order_id', $externalOrderId)
            ->first();

        if ($existing) {
            // Replay. Do not touch the hold: the order is already bound to one.
            return [$existing, false];
        }

        $hold = Hold::where('token', $holdToken)->first();

        if (! $hold) {
            throw ApiException::notFound('Unknown hold token.', 'hold_not_found');
        }

        if (! $hold->isActive()) {
            throw ApiException::conflict('hold_'.$hold->currentState(), sprintf(
                'This hold is %s; the seats are no longer reserved.', $hold->currentState()
            ));
        }

        /*
         * How many this person may have for this night.
         *
         * Here rather than at confirmation, because registering an order happens before a shop
         * takes the money and confirming it happens after: refusing at the later of the two would
         * mean money taken for a booking that does not exist. A shop that sends no address until
         * confirmation is therefore not held to the limit at all, which is the honest consequence
         * of that ordering and is written down in the contract rather than papered over.
         */
        // Who may not buy from this organiser at all, asked before the money rather than at the
        // door: a person barred at a turnstile has already paid, and getting that back is a
        // conversation nobody wants to have on the night.
        app(\App\Domain\Risk\Blocklist::class)
            ->assertNotBlocked($buyer['email'] ?? null, $buyer['phone'] ?? null);

        if ($email = ($buyer['email'] ?? null)) {
            app(\App\Domain\Access\PurchaseLimits::class)
                ->assertWithin($hold->event, $email, $this->placesIn($hold), $client);
        }

        try {
            $order = DB::transaction(function () use ($client, $externalOrderId, $hold, $buyer, $metadata) {
                $order = ExternalOrder::create([
                    'event_id' => $hold->event_id,
                    'api_client_id' => $client->id,
                    'hold_id' => $hold->id,
                    'external_order_id' => $externalOrderId,
                    'status' => 'pending',
                    'currency' => $hold->currency,
                    'total_amount' => $hold->total_amount,
                    'buyer' => $buyer,
                    'metadata' => $metadata,
                ]);

                $hold->forceFill(['external_order_id' => $externalOrderId])->save();

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // Two copies of the same "order created" hook arrived at once.
            return [
                ExternalOrder::where('api_client_id', $client->id)
                    ->where('external_order_id', $externalOrderId)->firstOrFail(),
                false,
            ];
        }

        $this->audit->record('order.registered', $order, [
            'external_order_id' => $externalOrderId,
            'hold_token' => $hold->token,
        ]);

        return [$order, true];
    }

    /**
     * Turn the hold into allocations and issue tickets. Exactly-once: the unique index on
     * (api_client_id, external_order_id, seat_id) is the backstop if the idempotency layer is
     * somehow bypassed.
     */
    public function confirm(ExternalOrder $order, array $buyer = [], ?\DateTimeInterface $paidAt = null): ExternalOrder
    {
        if ($order->status === 'confirmed') {
            return $order->load('allocations.ticket');
        }

        if (in_array($order->status, ['refunded', 'partially_refunded'], true)) {
            // Already sold and then refunded — re-confirming would resurrect a voided ticket.
            return $order->load('allocations.ticket');
        }

        if (! $order->canTransitionTo('confirmed')) {
            throw ApiException::conflict(
                'invalid_transition',
                sprintf('An order in state "%s" cannot be confirmed.', $order->status),
                [],
                ['status' => $order->status],
            );
        }

        $hold = $order->hold;

        if (! $hold) {
            throw ApiException::conflict('hold_missing', 'This order is not attached to a hold.');
        }

        if ($hold->status === 'converted') {
            // The hold converted under a previous attempt whose response never reached the caller.
            return $order->fresh(['allocations.ticket']);
        }

        if (! $hold->isActive()) {
            throw ApiException::conflict('hold_'.$hold->currentState(), sprintf(
                'The hold for this order is %s; its seats have been returned to sale. '.
                'The buyer must choose seats again.',
                $hold->currentState()
            ));
        }

        return DB::transaction(function () use ($order, $hold, $buyer, $paidAt) {
            /** @var Hold $hold */
            $hold = Hold::whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($hold->status === 'converted') {
                return $order->fresh(['allocations.ticket']);
            }

            if (! $hold->isActive()) {
                throw ApiException::conflict('hold_'.$hold->currentState(), 'The hold expired before confirmation.');
            }

            $event = $order->event;
            $hold->loadMissing('entrySlot');
            $items = HoldItem::with(['seat.section', 'seat.row', 'capacityObject', 'ticketType'])
                ->where('hold_id', $hold->id)
                ->whereNull('released_at')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::conflict('hold_empty', 'The hold has no seats left to allocate.');
            }

            /*
             * Seats somebody else offered back to the public, changing hands here.
             *
             * Before the new allocations are written, and inside this same transaction: the
             * partial unique index on (event_id, seat_id) WHERE active would refuse the new row
             * while the old one is still there, and a swap that half happened would leave a seat
             * sold twice or belonging to nobody. See App\Domain\Resale\Resales::settleSeats.
             */
            $seatIds = $items->pluck('seat_id')->filter()->all();

            app(\App\Domain\Resale\Resales::class)->settleSeats($event, $seatIds, $order);

            /*
             * After the swap, every seat in this basket must actually be free.
             *
             * A hold reserves a seat, so ordinarily this cannot fail — except for a seat that was
             * on offer and stopped being so between the basket and the payment: its owner had
             * their ticket scanned at the door, say, which closes the listing and leaves them in
             * the chair. The database would refuse the row a moment later with a constraint error
             * nobody could act on; this refuses it with the sentence that already exists for
             * exactly this, before any money moves.
             */
            $taken = [] === $seatIds ? [] : Allocation::query()
                ->where('event_id', $event->id)
                ->where('status', 'active')
                ->whereIn('seat_id', $seatIds)
                ->pluck('seat_id')
                ->all();

            if ([] !== $taken) {
                throw ApiException::seatsUnavailable($taken);
            }

            $allocations = [];

            foreach ($items as $item) {
                $allocations[] = Allocation::create([
                    'event_id' => $event->id,
                    'seat_id' => $item->seat_id,
                    'capacity_object_id' => $item->capacity_object_id,
                    'ticket_type_id' => $item->ticket_type_id,
                    // Denormalised for the same reason the section name is: a type renamed next
                    // season must not change what a ticket sold this season says it is.
                    'ticket_type_name' => $item->ticketType?->name,
                    'quantity' => $item->quantity,
                    'hold_id' => $hold->id,
                    'external_order_row_id' => $order->id,
                    'api_client_id' => $order->api_client_id,
                    'external_order_id' => $order->external_order_id,
                    'status' => 'active',
                    'amount' => $item->amount,
                    'currency' => $order->currency,
                    'seat_map_version_id' => $hold->seat_map_version_id,
                    // Copied off the hold, and the window's own times copied beside it: an
                    // organiser who rewrites tomorrow's timetable must not change what a ticket
                    // already in somebody's pocket says they were told to arrive.
                    'entry_slot_id' => $hold->entry_slot_id,
                    'entry_starts_at' => $hold->entrySlot?->starts_at,
                    'entry_ends_at' => $hold->entrySlot?->ends_at,
                    // Denormalised at sale time so a ticket stays readable even if a later map
                    // version renames the section. Standing room has no row or seat, so its area
                    // name goes in the section column and the label says what it is.
                    'section_name' => $item->isCapacity()
                        ? ($item->capacityObject?->label ?? '')
                        : ($item->seat?->section?->name ?? ''),
                    'row_name' => $item->isCapacity() ? '' : ($item->seat?->row?->name ?? ''),
                    'seat_label' => $item->isCapacity()
                        ? ($item->quantity > 1 ? $item->quantity.' places' : 'General admission')
                        : ($item->seat?->label ?? ''),
                    'allocated_at' => now(),
                ]);
            }

            // The seats now belong to allocations; the hold rows must stop occupying them or the
            // partial index would block any later resale after a refund.
            HoldItem::where('hold_id', $hold->id)->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            $hold->forceFill(['status' => 'converted', 'converted_at' => now()])->save();

            // The presale code stays spent, and is now attached to the booking rather than to a
            // hold that no longer exists. Releasing it here would leak the cap one seat at a time.
            $this->access->settle($hold, $order->id);

            // Keep the plaintext tokens: they exist only in memory, and the storefront needs them
            // in this response to render the QR. Re-reading the order below would lose them.
            $issuedTokens = [];

            /*
             * A booking being paid in instalments gets its seats now and its code when it is paid
             * for.
             *
             * Two promises, and running them together is how a party of forty arrives with codes
             * they never paid for. The chairs above are already theirs — nobody else can have them
             * — and the ticket is minted by `PaymentPlans` with the last payment.
             */
            $owing = app(\App\Domain\Payments\PaymentPlans::class)->owes($order);

            foreach ($owing ? [] : $allocations as $allocation) {
                $ticket = $this->tickets->issue($allocation, $buyer['name'] ?? null);

                if ($ticket->plainToken !== null) {
                    $issuedTokens[$allocation->id] = $ticket->plainToken;
                }
            }

            $order->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => $paidAt ? \Illuminate\Support\Carbon::instance(
                    \Illuminate\Support\Carbon::parse($paidAt)
                ) : now(),
                'buyer' => array_filter($buyer) ?: $order->buyer,
            ])->save();

            $event->bumpAvailabilityVersion();

            $this->audit->record('order.confirmed', $order, [
                'external_order_id' => $order->external_order_id,
                'seats' => count($allocations),
                'total_amount' => $order->total_amount,
            ]);

            /*
             * If they were waiting for this night, they are not waiting any more.
             *
             * Matched on the address they joined the list with, which is the only handle a queue
             * has. Doing it here rather than on the waiting-list side is what makes it true however
             * the seat was sold — the website, the counter, a shop over the integration API — since
             * all three arrive at this one method.
             */
            try {
                app(\App\Domain\Waitlist\WaitingList::class)->bought($event, $buyer['email'] ?? null);
            } catch (\Throwable $e) {
                // A queue that could not be tidied is not a reason to fail a sale somebody has
                // already paid for. Reported and carried past, like the messages below.
                report($e);
            }

            /*
             * And whatever this evening is worth in points.
             *
             * Here for the same reason as the queue above: every sale on this platform arrives at
             * this one method, whichever door it came in by. Carried past on failure for the same
             * reason too — a loyalty scheme is not worth failing a paid booking over.
             */
            try {
                app(\App\Domain\Loyalty\Loyalty::class)->settle($order->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            // The buyer is told, on whatever channels this organiser has turned on. Failures are
            // recorded and swallowed inside: an order that fails because a text message could not
            // be sent is a worse outcome than a text message that arrives late.
            $this->messages->confirmed($order);

            $this->webhooks->dispatch($order->tenant_id, 'order.confirmed', [
                'external_order_id' => $order->external_order_id,
                'event_public_id' => $event->public_id,
                'seats' => count($allocations),
                'total_amount' => $order->total_amount,
                'currency' => $order->currency,
            ]);

            return $this->withIssuedTokens($order->fresh(['allocations.ticket']), $issuedTokens);
        });
    }

    /**
     * Re-attach the plaintext tokens minted during this call to the freshly loaded models.
     *
     * A retried confirm legitimately produces none — the tickets already existed — and the caller
     * then sees tickets without tokens, which is correct: a token is issued once and never again.
     *
     * @param  array<string, string>  $tokensByAllocation
     */
    private function withIssuedTokens(ExternalOrder $order, array $tokensByAllocation): ExternalOrder
    {
        foreach ($order->allocations as $allocation) {
            if (isset($tokensByAllocation[$allocation->id]) && $allocation->ticket) {
                $allocation->ticket->plainToken = $tokensByAllocation[$allocation->id];
            }
        }

        return $order;
    }

    /** Release the seats behind an order that failed, was cancelled, or was deleted. */
    public function cancel(ExternalOrder $order, string $reason = 'cancelled'): ExternalOrder
    {
        if ($order->status === 'cancelled') {
            return $order;
        }

        if ($order->status === 'confirmed') {
            // A confirmed order has tickets in the buyer's hands. Cancelling it is a refund in all
            // but name, so route it through the refund path rather than silently voiding seats.
            return $this->refund($order, null, $reason);
        }

        return DB::transaction(function () use ($order, $reason) {
            if ($order->hold) {
                HoldItem::where('hold_id', $order->hold_id)->whereNull('released_at')
                    ->update(['released_at' => now(), 'updated_at' => now()]);

                Hold::whereKey($order->hold_id)->where('status', 'active')
                    ->update(['status' => 'released', 'released_at' => now(), 'updated_at' => now()]);
            }

            $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            $order->event?->bumpAvailabilityVersion();

            // Voucher money never belonged to the organiser. A cancelled booking gives it back to
            // the card it came off — which is the voucher — rather than keeping it.
            $this->vouchers->release($order);

            $this->audit->record('order.cancelled', $order, [
                'external_order_id' => $order->external_order_id,
                'reason' => $reason,
            ]);

            // A booking that never happened is not an evening somebody came to.
            try {
                app(\App\Domain\Loyalty\Loyalty::class)->settle($order->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            $this->messages->cancelled($order);

            $this->webhooks->dispatch($order->tenant_id, 'order.cancelled', [
                'external_order_id' => $order->external_order_id,
                'reason' => $reason,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Void tickets and apply the event's seat policy.
     *
     * `release` puts the seat back on sale; `hold_back` keeps it out of inventory so the organiser
     * can decide what to do with it. Which one applies is the organiser's choice, per event.
     *
     * @param  list<string>|null  $seatIds  Null means the whole order.
     */
    public function refund(ExternalOrder $order, ?array $seatIds = null, string $reason = 'refund'): ExternalOrder
    {
        if ($order->status === 'refunded') {
            return $order->load('allocations.ticket');
        }

        if ($order->status === 'pending') {
            // Nothing was ever allocated; the honest outcome is a cancellation.
            return $this->cancel($order, $reason);
        }

        return DB::transaction(function () use ($order, $seatIds, $reason) {
            $query = Allocation::where('external_order_row_id', $order->id)->where('status', 'active');

            if ($seatIds !== null) {
                $query->whereIn('seat_id', $seatIds);
            }

            $allocations = $query->lockForUpdate()->get();

            if ($allocations->isEmpty()) {
                // Already refunded, or the named seats are not on this order. Report current state
                // rather than failing — the caller is probably retrying.
                return $order->fresh(['allocations.ticket']);
            }

            $policy = $order->event?->refund_policy ?? 'release';

            foreach ($allocations as $allocation) {
                $allocation->forceFill([
                    'status' => $policy === 'release' ? 'released' : 'void',
                    'released_at' => now(),
                ])->save();

                $this->tickets->void($allocation);

                if ($policy === 'hold_back' && $allocation->seat_id) {
                    // Keep the seat out of sale by blocking it for this event.
                    \App\Models\EventSeatOverride::updateOrCreate(
                        ['event_id' => $order->event_id, 'seat_id' => $allocation->seat_id],
                        ['blocked' => true, 'note' => 'Held back after refund of order '.$order->external_order_id],
                    );
                }

                // A refunded standing place needs no block: releasing the allocation already
                // returns its quantity to the area's running total.
            }

            $remaining = Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count();

            $order->forceFill([
                'status' => $remaining > 0 ? 'partially_refunded' : 'refunded',
                'refunded_at' => now(),
            ])->save();

            $order->event?->bumpAvailabilityVersion();

            /*
             * Voucher money goes back to the voucher, and only on a full refund.
             *
             * On a partial one it stays spent, deliberately: the seats that were kept still have to
             * be paid for, and the money already settled is what pays for them. Giving the voucher
             * back while the buyer keeps half the booking would hand them the same money twice.
             */
            if (0 === $remaining) {
                $this->vouchers->release($order);
            }

            $this->audit->record('order.refunded', $order, [
                'external_order_id' => $order->external_order_id,
                'seats' => $allocations->count(),
                'policy' => $policy,
                'reason' => $reason,
                'remaining_active' => $remaining,
            ]);

            // Money going back out is worth telling the box office about without them having to
            // go looking. The audit log records it either way; this is the part that arrives.
            $this->notifier->raise('order.refunded', [
                'reference' => $order->external_order_id,
                'event' => (string) ($order->event?->name ?? ''),
                'seats' => $allocations->count(),
            ], $order);

            $this->webhooks->dispatch($order->tenant_id, 'order.refunded', [
                'external_order_id' => $order->external_order_id,
                'seats' => $allocations->count(),
                'policy' => $policy,
                'fully_refunded' => $remaining === 0,
            ]);

            // The points follow the seats. `settle()` reads what the booking is worth now and
            // writes the difference, so half a refund takes back half of them without any separate
            // path that could disagree with the one that gave them.
            try {
                app(\App\Domain\Loyalty\Loyalty::class)->settle($order->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            return $order->fresh(['allocations.ticket']);
        });
    }
}
