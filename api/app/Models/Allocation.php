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
        'tenant_id', 'event_id', 'seat_id', 'hold_id', 'external_order_row_id', 'api_client_id',
        'external_order_id', 'status', 'amount', 'currency', 'seat_map_version_id',
        'section_name', 'row_name', 'seat_label', 'allocated_at', 'released_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'allocated_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function seat()
    {
        return $this->belongsTo(Seat::class);
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
