<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Models\Ticket;

class TicketController extends Controller
{
    /**
     * Tenant-side ticket lookup for support. Note what is absent: the QR token. It is issued once
     * to the storefront and never readable again, so a panel session cannot be used to harvest
     * working tickets.
     */
    public function show(Ticket $ticket)
    {
        $ticket->load('allocation');

        return response()->json([
            'id' => $ticket->id,
            'allocation_id' => $ticket->allocation_id,
            'event_id' => $ticket->event_id,
            'status' => $ticket->status,
            'holder_name' => $ticket->holder_name,
            'token_prefix' => $ticket->token_prefix,
            'seat' => [
                'section' => $ticket->allocation?->section_name,
                'row' => $ticket->allocation?->row_name,
                'label' => $ticket->allocation?->seat_label,
            ],
            'issued_at' => $ticket->issued_at?->toIso8601String(),
            'used_at' => $ticket->used_at?->toIso8601String(),
        ]);
    }
}
