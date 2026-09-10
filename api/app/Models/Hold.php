<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'seat_map_version_id', 'token', 'session_id', 'source',
        'api_client_id', 'status', 'expires_at', 'extends_used', 'currency', 'total_amount',
        'price_snapshot', 'external_order_id', 'released_at', 'converted_at', 'ip', 'entry_slot_id',
        'access_code_id',
    ];

    protected $hidden = ['ip'];

    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'converted_at' => 'datetime',
        'price_snapshot' => 'array',
        'total_amount' => 'integer',
        'extends_used' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function entrySlot()
    {
        return $this->belongsTo(EntrySlot::class);
    }

    public function items()
    {
        return $this->hasMany(HoldItem::class);
    }

    /**
     * "Active" is a computed fact, not a column we trust on its own: a hold whose row still says
     * `active` but whose `expires_at` has passed is not active. Every read must ask both.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function currentState(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }

        return $this->expires_at->isFuture() ? 'active' : 'expired';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('expires_at', '>', now());
    }
}
