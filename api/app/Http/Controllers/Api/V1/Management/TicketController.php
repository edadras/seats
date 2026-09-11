<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Orders\OrderService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every ticket for an event, searchable by name, email, seat or the visible part of the code.
     *
     * This is what a box office actually does: someone arrives saying they booked, and staff need
     * to find them from whatever they can remember. Searching by the *whole* token is deliberately
     * not offered — a token is a credential, and a search box that accepts one is a search box that
     * logs one.
     */
    public function index(Request $request)
    {
        $this->authorize($request, 'tickets.view');

        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'in:issued,used,void'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tickets = Ticket::with(['allocation.order'])
            ->where('event_id', $data['event_id'])
            /*
             * A programme manager sees the tickets for the nights they run.
             *
             * The event arrives as a query parameter rather than as a bound route model, so the
             * middleware that scopes `{event}` never sees it — which is exactly the kind of gap
             * this narrowing exists to close.
             */
            ->tap(fn ($query) => app(\App\Domain\Programme\EventManagers::class)->narrow($query, $request->user()))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['q'] ?? null, function ($query, string $term) {
                // Escaped for LIKE: a buyer called "100%" should find themselves, not everyone.
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

                $query->where(function ($q) use ($like) {
                    $q->where('holder_name', 'ilike', $like)
                        ->orWhere('token_prefix', 'ilike', $like)
                        ->orWhereHas('allocation', fn ($a) => $a
                            ->where('seat_label', 'ilike', $like)
                            ->orWhere('row_name', 'ilike', $like)
                            ->orWhere('section_name', 'ilike', $like))
                        // The buyer's email lives on the order, not the ticket: one order, many
                        // seats, one person to contact.
                        ->orWhereHas('allocation.order', fn ($o) => $o
                            ->where('buyer->email', 'ilike', $like)
                            ->orWhere('buyer->name', 'ilike', $like)
                            ->orWhere('external_order_id', 'ilike', $like));
                });
            })
            ->orderBy('created_at')
            ->paginate(min((int) ($data['per_page'] ?? 50), 100));

        return $this->paginated($tickets, fn (Ticket $ticket) => $this->present($ticket));
    }

    /**
     * Void one seat's ticket and put the seat back on sale.
     *
     * Expressed as a partial refund of the order rather than as a direct edit: releasing a seat and
     * voiding its ticket has to happen together, and OrderService is where that pair is already
     * written, tested, and safe to repeat.
     */
    public function release(Request $request, Ticket $ticket)
    {
        $this->authorize($request, 'tickets.release');

        $allocation = $ticket->allocation;

        if (! $allocation) {
            throw ApiException::conflict('no_allocation', 'This ticket is not attached to a seat.');
        }

        if ('used' === $ticket->status) {
            throw ApiException::conflict(
                'already_used',
                'Someone has already come in on this ticket. Releasing the seat now would sell a place that is occupied.'
            );
        }

        $order = $allocation->order;

        if (! $order) {
            throw ApiException::conflict('no_order', 'This ticket is not attached to an order.');
        }

        $this->orders->refund($order, [$allocation->seat_id], 'released_by_organiser');

        $this->audit->record('ticket.released', $ticket, [
            'seat' => trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label),
        ]);

        return response()->json($this->present($ticket->fresh(['allocation.order'])));
    }

    /**
     * Tenant-side ticket lookup for support. Note what is absent: the QR token. It is issued once
     * to the storefront and never readable again, so a panel session cannot be used to harvest
     * working tickets.
     */
    public function show(Request $request, Ticket $ticket)
    {
        $this->authorize($request, 'tickets.view');

        return response()->json($this->present($ticket->load('allocation.order')));
    }

    private function present(Ticket $ticket): array
    {
        $allocation = $ticket->allocation;

        return [
            'id' => $ticket->id,
            'allocation_id' => $ticket->allocation_id,
            'event_id' => $ticket->event_id,
            'status' => $ticket->status,
            'holder_name' => $ticket->holder_name,
            'token_prefix' => $ticket->token_prefix,
            'seat' => [
                'section' => $allocation?->section_name,
                'row' => $allocation?->row_name,
                'label' => $allocation?->seat_label,
                'quantity' => $allocation?->quantity,
            ],
            'order' => $allocation?->order ? [
                'reference' => $allocation->order->external_order_id,
                'status' => $allocation->order->status,
                'buyer_name' => $allocation->order->buyer['name'] ?? null,
                'buyer_email' => $allocation->order->buyer['email'] ?? null,
            ] : null,
            'issued_at' => $ticket->issued_at?->toIso8601String(),
            'used_at' => $ticket->used_at?->toIso8601String(),
        ];
    }
}
