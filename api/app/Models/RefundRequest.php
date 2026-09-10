<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One buyer asking for their money back, and what was decided.
 *
 * Kept even when the terms granted it instantly: "why is this booking refunded" is a question
 * asked months later, and "they asked on the 4th and the terms allowed it" is a better answer than
 * a status that changed for no recorded reason.
 */
class RefundRequest extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'external_order_row_id', 'allocation_ids', 'reason',
        'status', 'outcome_reason', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'allocation_ids' => 'array',
        'decided_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }
}
