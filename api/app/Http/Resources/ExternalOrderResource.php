<?php

namespace App\Http\Resources;

use App\Models\Allocation;
use Illuminate\Http\Resources\Json\JsonResource;

class ExternalOrderResource extends JsonResource
{
    /** Tokens are returned only to the storefront that owns the order, and only on demand. */
    public function __construct($resource, private readonly bool $includeTicketTokens = false)
    {
        parent::__construct($resource);
    }

    public function toArray($request): array
    {
        $this->resource->loadMissing(['allocations.ticket', 'event']);

        return [
            'external_order_id' => $this->external_order_id,
            'status' => $this->status,
            'event_public_id' => $this->event?->public_id,
            'hold_token' => $this->hold?->token,
            'currency' => $this->currency,
            'total_amount' => $this->total_amount,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'allocations' => $this->allocations->map(fn (Allocation $a) => [
                'id' => $a->id,
                'seat_id' => $a->seat_id,
                'section' => $a->section_name,
                'row' => $a->row_name,
                'label' => $a->seat_label,
                'amount' => $a->amount,
                'status' => $a->status,
            ])->values(),
            'tickets' => $this->allocations
                ->filter(fn (Allocation $a) => $a->ticket !== null)
                ->map(fn (Allocation $a) => array_filter([
                    'id' => $a->ticket->id,
                    'allocation_id' => $a->id,
                    'status' => $a->ticket->status,
                    // Present only on the response to the call that issued it.
                    'token' => $this->includeTicketTokens ? $a->ticket->plainToken : null,
                    'seat' => [
                        'section' => $a->section_name,
                        'row' => $a->row_name,
                        'label' => $a->seat_label,
                    ],
                    'issued_at' => $a->ticket->issued_at?->toIso8601String(),
                    'used_at' => $a->ticket->used_at?->toIso8601String(),
                ], fn ($v) => $v !== null))->values(),
        ];
    }
}
