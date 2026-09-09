<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Orders\OrderService;
use App\Domain\Orders\TicketIssuer;
use App\Domain\Sites\TicketMailer;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ExternalOrder;
use App\Models\MessageDelivery;
use App\Models\Site;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * The box office's own screen: find a booking, see everything on it, put it right.
 *
 * Refunding was reachable only over the signed integration API — which is correct for a shop that
 * owns the money, and useless for an organiser selling from their own site, who had the permission
 * and nowhere to use it. This is that missing screen.
 *
 * Reading is `orders.view`; changing anything is `orders.refund`. Sending somebody their tickets
 * again takes `tickets.release`, because it necessarily kills the codes that were emailed — the
 * platform keeps a hash of those and cannot hand them back — and that is the same power.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly TicketIssuer $tickets,
        private readonly TicketMailer $mail,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'orders.view');

        $data = $request->validate([
            'event_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:pending,confirmed,cancelled,refunded,partially_refunded'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $orders = ExternalOrder::query()
            ->with(['event:id,name,starts_at,timezone'])
            // One query for the seat counts rather than one per row: a list of fifty orders was
            // fifty extra queries, and with lazy loading off it was fifty errors.
            ->withCount(['allocations as seats_count' => fn ($query) => $query->where('status', 'active')])
            ->when($data['event_id'] ?? null, fn ($query, $id) => $query->where('event_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['q'] ?? null, function ($query, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))).'%';

                $query->where(function ($where) use ($like) {
                    $where->whereRaw('lower(external_orders.external_order_id) like ?', [$like])
                        ->orWhereRaw("lower(coalesce(external_orders.buyer->>'name', '')) like ?", [$like])
                        ->orWhereRaw("lower(coalesce(external_orders.buyer->>'email', '')) like ?", [$like]);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min(100, (int) ($data['per_page'] ?? 25)));

        return $this->paginated($orders, fn (ExternalOrder $order) => $this->row($order));
    }

    public function show(Request $request, ExternalOrder $order)
    {
        $this->authorize($request, 'orders.view');

        $order->load(['event.venue', 'allocations.ticket', 'apiClient']);

        return response()->json($this->row($order) + [
            'buyer' => [
                'name' => $order->buyer['name'] ?? null,
                'email' => $order->buyer['email'] ?? null,
                'phone' => $order->buyer['phone'] ?? null,
            ],
            'channel' => $order->apiClient?->name,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'refunded_at' => $order->refunded_at?->toIso8601String(),
            'lines' => $order->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'seat_id' => $allocation->seat_id,
                'section' => $allocation->section_name,
                'row' => $allocation->row_name,
                'seat' => $allocation->seat_id ? $allocation->seat_label : null,
                'quantity' => $allocation->seat_id ? 1 : (int) ($allocation->quantity ?: 1),
                'amount' => (int) $allocation->amount,
                'status' => $allocation->status,
                'ticket_status' => $allocation->ticket?->status,
                'used_at' => $allocation->ticket?->used_at?->toIso8601String(),
            ])->values(),
            // What this buyer was actually told, and whether it arrived. The question a box office
            // is asked at the window is "did they get it", and the answer is a row.
            'messages' => MessageDelivery::where('external_order_row_id', $order->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (MessageDelivery $delivery) => [
                    'kind' => $delivery->kind,
                    'channel' => $delivery->channel,
                    'recipient' => $delivery->recipient,
                    'status' => $delivery->status,
                    'reason' => $delivery->reason,
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ])->values(),
            'can_resend' => $order->allocations->contains(
                fn ($allocation) => 'issued' === $allocation->ticket?->status
            ),
            // Read back rather than recomputed: this is what was charged, and an event whose fee
            // or tax rate changed since must not rewrite an old booking's history.
            'totals' => $order->metadata['totals'] ?? null,
            'discount' => $order->metadata['discount'] ?? null,
            // What the buyer was asked at checkout. The label is the one they saw, not the one the
            // question carries now — an organiser rewording it must not change what a past answer
            // appears to be an answer to.
            'answers' => \App\Models\QuestionAnswer::where('external_order_row_id', $order->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($answer) => [
                    'label' => $answer->label,
                    'value' => $answer->value,
                    'allocation_id' => $answer->allocation_id,
                ])
                ->values(),
        ]);
    }

    /**
     * Refund the whole order, or the seats named.
     *
     * The money is not moved here and this does not pretend to: a refund releases the seats, voids
     * the tickets and records what happened. Sending the money back is the gateway's business and
     * an organiser does it where they took it — a platform that ticked "refunded" while the card
     * was never credited would be worse than one that says plainly which half it did.
     */
    public function refund(Request $request, ExternalOrder $order)
    {
        $this->authorize($request, 'orders.refund');

        $data = $request->validate([
            'seat_ids' => ['sometimes', 'array'],
            'seat_ids.*' => ['uuid'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        if (! in_array($order->status, ['confirmed', 'partially_refunded'], true)) {
            throw ApiException::conflict(
                'order_not_refundable',
                'Only a confirmed order can be refunded.'
            );
        }

        $refunded = $this->orders->refund(
            $order,
            $data['seat_ids'] ?? null,
            ($data['reason'] ?? null) ?: 'refunded_in_panel',
        );

        return response()->json($this->row($refunded->fresh(['event', 'allocations.ticket'])));
    }

    public function cancel(Request $request, ExternalOrder $order)
    {
        $this->authorize($request, 'orders.refund');

        if (in_array($order->status, ['refunded', 'cancelled'], true)) {
            return response()->json($this->row($order));
        }

        $cancelled = $this->orders->cancel($order, 'cancelled_in_panel');

        return response()->json($this->row($cancelled->fresh(['event', 'allocations.ticket'])));
    }

    /**
     * Send the buyer their tickets again.
     *
     * Which means new codes: the platform stores a hash of what it emailed and cannot recover the
     * code itself, so "send it again" is necessarily "issue another one and stop the old working".
     * That is the honest answer to a lost email and the wrong answer to a ticket already passed to
     * a friend, so it is a deliberate action with the consequence written beside the button.
     */
    public function resend(Request $request, ExternalOrder $order)
    {
        $this->authorize($request, 'tickets.release');

        if ('confirmed' !== $order->status) {
            throw ApiException::conflict('order_not_confirmed', 'There are no tickets to send yet.');
        }

        $email = $order->buyer['email'] ?? null;

        if (! $email) {
            throw ApiException::unprocessable('no_address', 'This order has no email address on it.');
        }

        $site = Site::where('status', 'live')->orderBy('created_at')->first();

        if (! $site) {
            throw ApiException::unprocessable(
                'no_site',
                'Tickets are emailed from a website, and this account has none live.'
            );
        }

        $order->load('allocations.ticket');
        $issued = 0;

        foreach ($order->allocations as $allocation) {
            $ticket = $this->tickets->reissue($allocation);

            if (! $ticket?->plainToken) {
                continue;
            }

            // Onto the loaded relation, not just into a variable: the mailer reads the token from
            // `$allocation->ticket`, and the instance `reissue()` handed back is a different object
            // from the one this allocation is holding.
            $allocation->setRelation('ticket', $ticket);
            $issued++;
        }

        if (0 === $issued) {
            throw ApiException::conflict('nothing_to_send', 'Every ticket on this order has been used or voided.');
        }

        $this->mail->send($site, $order);

        $this->audit->record('order.tickets_resent', $order, [
            'external_order_id' => $order->external_order_id,
            'tickets' => $issued,
            'to' => $email,
        ]);

        return response()->json(['sent' => $issued, 'to' => $email]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function row(ExternalOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->external_order_id,
            'status' => $order->status,
            'currency' => $order->currency,
            'total_amount' => (int) $order->total_amount,
            'placed_at' => $order->created_at?->toIso8601String(),
            'buyer_name' => $order->buyer['name'] ?? null,
            'buyer_email' => $order->buyer['email'] ?? null,
            'seats' => (int) ($order->seats_count ?? $order->allocations()->where('status', 'active')->count()),
            'event' => $order->event ? [
                'id' => $order->event->id,
                'name' => $order->event->name,
                'starts_at' => $order->event->starts_at?->toIso8601String(),
            ] : null,
        ];
    }
}
