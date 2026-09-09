<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The definitive record that a seat is sold. Only rows with status `active` occupy a seat, and the
 * partial unique index on (event_id, seat_id) WHERE status='active' is what makes that exclusive.
 */
class Allocation extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'seat_id', 'capacity_object_id', 'quantity', 'hold_id',
        'ticket_type_id', 'ticket_type_name',
        'external_order_row_id', 'api_client_id',
        'external_order_id', 'status', 'amount', 'currency', 'seat_map_version_id',
        'section_name', 'row_name', 'seat_label', 'allocated_at', 'released_at',
        'entry_slot_id', 'entry_starts_at', 'entry_ends_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'quantity' => 'integer',
        'allocated_at' => 'datetime',
        'released_at' => 'datetime',
        'entry_starts_at' => 'datetime',
        'entry_ends_at' => 'datetime',
    ];

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

    public function entrySlot()
    {
        return $this->belongsTo(EntrySlot::class);
    }

    public function ticket()
    {
        return $this->hasOne(Ticket::class);
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
