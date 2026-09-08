<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The SaaS's view of an order that lives in the tenant's shop.
 *
 * It holds a foreign key and a status, not a copy of the order. Totals here come from the hold's
 * price snapshot and are indicative only — tax and coupons are the shop's business (ADR-0001).
 */
class ExternalOrder extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** Transitions that are legal from each state. Anything else is a 409. */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['refunded', 'partially_refunded', 'cancelled'],
        'partially_refunded' => ['refunded', 'partially_refunded'],
        'refunded' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'tenant_id', 'event_id', 'api_client_id', 'hold_id', 'external_order_id', 'status',
        'currency', 'total_amount', 'buyer', 'metadata',
        'confirmed_at', 'cancelled_at', 'refunded_at',
    ];

    protected $casts = [
        'buyer' => 'array',
        'metadata' => 'array',
        'total_amount' => 'integer',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }

    /**
     * Ordered the way a human reads a ticket list. Without an explicit order Postgres is free to
     * return rows however it likes, so the same order could render its seats differently on two
     * requests — and a client indexing into the list would quietly read the wrong seat.
     */
    public function allocations()
    {
        return $this->hasMany(Allocation::class, 'external_order_row_id')
            ->orderBy('section_name')
            ->orderBy('row_name')
            ->orderByRaw('LPAD(seat_label, 12, \'0\')');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
