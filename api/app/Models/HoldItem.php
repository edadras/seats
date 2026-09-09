<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One seat inside a hold.
 *
 * `released_at IS NULL` is what the partial unique index keys on, so setting it is how a seat is
 * handed back — never by deleting the row, which would lose the audit trail.
 */
class HoldItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'hold_id', 'event_id', 'seat_id', 'capacity_object_id', 'ticket_type_id',
        'quantity', 'amount', 'zone_key', 'released_at',
    ];

    protected $casts = ['released_at' => 'datetime', 'amount' => 'integer', 'quantity' => 'integer'];

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }

    public function seat()
    {
        return $this->belongsTo(Seat::class);
    }

    public function capacityObject()
    {
        return $this->belongsTo(CapacityObject::class);
    }

    public function ticketType()
    {
        return $this->belongsTo(TicketType::class);
    }

    /** A hold item is either a named seat or a quantity of a capacity object, never both. */
    public function isCapacity(): bool
    {
        return $this->capacity_object_id !== null;
    }
}
