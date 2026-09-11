<?php

namespace App\Domain\Refunds;

use App\Domain\Orders\OrderService;
use App\Exceptions\ApiException;
use App\Models\ExternalOrder;
use App\Models\RefundRequest;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Domain\Notifications\Notifier;

/**
 * Asking for money back, and the two ways that ends.
 *
 * Inside the terms it is granted at once — the organiser already said yes when they wrote them,
 * and making somebody wait for a human to repeat that answer is a queue for nothing. Outside them
 * it becomes a request the box office sees, because "no" is not always the right answer: a buyer
 * in hospital is a conversation, not a policy.
 *
 * Either way the asking is recorded. A refunded booking with no record of why is a booking
 * somebody will argue about in three months.
 */
class RefundRequests
{
    public function __construct(
        private readonly RefundPolicy $policy,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly \App\Domain\Payments\Refunds $refunds,
    ) {}

    /**
     * A buyer asks.
     *
     * @param  ?array<int, string>  $allocationIds  null for the whole booking
     */
    public function ask(ExternalOrder $order, ?array $allocationIds, string $reason = ''): RefundRequest
    {
        $order->loadMissing('event');

        $existing = RefundRequest::where('external_order_row_id', $order->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            // Asking twice is asking once. A second row would put the same booking in front of the
            // box office twice and invite two different answers.
            return $existing;
        }

        $verdict = $this->policy->check($order);

        $request = RefundRequest::create([
            'tenant_id' => $order->tenant_id,
            'event_id' => $order->event_id,
            'external_order_row_id' => $order->id,
            'allocation_ids' => $allocationIds ?: null,
            'reason' => $reason ?: null,
            'status' => 'pending',
        ]);

        if ($verdict['allowed']) {
            return $this->grant($request, null, 'within_the_terms');
        }

        if ('nothing_to_refund' === $verdict['reason']) {
            throw ApiException::conflict(
                'nothing_to_refund',
                'There is nothing on this booking to hand back.'
            );
        }

        // Outside the terms: the box office decides. Told about now rather than found later —
        // a request nobody sees is a customer who telephones instead.
        $this->notifier->raise('refund.requested', [
            'reference' => $order->external_order_id,
            'event' => (string) ($order->event?->name ?? ''),
            'reason' => $reason,
        ], $order);

        $this->audit->record('refund.requested', $order, [
            'external_order_id' => $order->external_order_id,
            'seats' => $allocationIds ? count($allocationIds) : null,
            'refused_because' => $verdict['reason'],
        ]);

        return $request;
    }

    /** The box office says yes. */
    public function grant(RefundRequest $request, ?User $by = null, string $because = ''): RefundRequest
    {
        if (! $request->isPending()) {
            return $request;
        }

        $order = $request->order()->with(['event', 'allocations'])->firstOrFail();
        $seatIds = $this->seatIds($order, $request);

        /*
         * The money first, then the seats.
         *
         * A buyer who asked for their money back and got a cancelled booking instead is worse off
         * than one who was told no. So a gateway that refuses throws out of here with the request
         * still pending, which is the state somebody can act on.
         */
        $this->refunds->give($order, $this->refunds->worthOf($order, $seatIds), 'refund_requested', $by);

        $this->orders->refund($order, $seatIds, 'refund_requested');

        $request->forceFill([
            'status' => 'approved',
            'outcome_reason' => $because ?: null,
            'decided_by' => $by?->id,
            'decided_at' => now(),
        ])->save();

        $this->audit->record('refund.granted', $order, [
            'external_order_id' => $order->external_order_id,
            'seats' => $seatIds ? count($seatIds) : null,
            'because' => $because,
            'by' => $by?->name,
        ]);

        return $request->refresh();
    }

    /** The box office says no, and why — which the buyer is owed. */
    public function decline(RefundRequest $request, ?User $by, string $because): RefundRequest
    {
        if (! $request->isPending()) {
            return $request;
        }

        $request->forceFill([
            'status' => 'declined',
            'outcome_reason' => $because,
            'decided_by' => $by?->id,
            'decided_at' => now(),
        ])->save();

        $this->audit->record('refund.declined', $request->order, [
            'external_order_id' => $request->order?->external_order_id,
            'because' => $because,
            'by' => $by?->name,
        ]);

        return $request->refresh();
    }

    /**
     * Which seats the refund names.
     *
     * `OrderService::refund` takes seat ids, not allocation ids — it is the seat that comes back
     * on sale — so a request naming allocations is translated here rather than everywhere.
     *
     * @return ?list<string>
     */
    private function seatIds(ExternalOrder $order, RefundRequest $request): ?array
    {
        if (! $request->allocation_ids) {
            return null;
        }

        $ids = $order->allocations
            ->whereIn('id', $request->allocation_ids)
            ->pluck('seat_id')
            ->filter()
            ->values()
            ->all();

        // Standing places have no seat id, so a partial refund of them cannot be expressed this
        // way. Refusing beats refunding the wrong thing, and the box office can still do it.
        if ([] === $ids) {
            throw ApiException::unprocessable(
                'seats_not_named',
                'Those places cannot be handed back one at a time.'
            );
        }

        return $ids;
    }
}
