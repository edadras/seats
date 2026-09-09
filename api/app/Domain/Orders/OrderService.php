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
    ) {}

    /** Register an order against a hold — called as soon as WooCommerce creates the order. */
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
            throw ApiException::conflict('invalid_transition', sprintf(
                'An order in state "%s" cannot be confirmed.', $order->status
            ));
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
            $items = HoldItem::with(['seat.section', 'seat.row', 'capacityObject'])
                ->where('hold_id', $hold->id)
                ->whereNull('released_at')
                ->get();

            if ($items->isEmpty()) {
                throw ApiException::conflict('hold_empty', 'The hold has no seats left to allocate.');
            }

            $allocations = [];

            foreach ($items as $item) {
                $allocations[] = Allocation::create([
                    'event_id' => $event->id,
                    'seat_id' => $item->seat_id,
                    'capacity_object_id' => $item->capacity_object_id,
                    'quantity' => $item->quantity,
                    'hold_id' => $hold->id,
                    'external_order_row_id' => $order->id,
                    'api_client_id' => $order->api_client_id,
                    'external_order_id' => $order->external_order_id,
                    'status' => 'active',
                    'amount' => $item->amount,
                    'currency' => $order->currency,
                    'seat_map_version_id' => $hold->seat_map_version_id,
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

            // Keep the plaintext tokens: they exist only in memory, and the storefront needs them
            // in this response to render the QR. Re-reading the order below would lose them.
            $issuedTokens = [];

            foreach ($allocations as $allocation) {
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

            $this->audit->record('order.cancelled', $order, [
                'external_order_id' => $order->external_order_id,
                'reason' => $reason,
            ]);

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

            $this->audit->record('order.refunded', $order, [
                'external_order_id' => $order->external_order_id,
                'seats' => $allocations->count(),
                'policy' => $policy,
                'reason' => $reason,
                'remaining_active' => $remaining,
            ]);

            $this->webhooks->dispatch($order->tenant_id, 'order.refunded', [
                'external_order_id' => $order->external_order_id,
                'seats' => $allocations->count(),
                'policy' => $policy,
                'fully_refunded' => $remaining === 0,
            ]);

            return $order->fresh(['allocations.ticket']);
        });
    }
}
