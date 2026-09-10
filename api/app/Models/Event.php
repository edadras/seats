<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Event extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'venue_id', 'series_id', 'seat_map_id', 'seat_map_version_id', 'public_id',
        'name', 'description', 'image_url', 'category', 'status', 'starts_at', 'ends_at', 'timezone', 'currency',
        'hold_ttl_seconds', 'max_extends', 'max_seats_per_order', 'refund_policy', 'settings',
        'booking_fee_kind', 'booking_fee_amount', 'booking_fee_percent', 'booking_fee_label',
        'tax_rate', 'tax_included', 'tax_label',
        'cancelled_at', 'cancellation_reason', 'rescheduled_from', 'rescheduled_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rescheduled_from' => 'datetime',
        'rescheduled_at' => 'datetime',
        'settings' => 'array',
        'hold_ttl_seconds' => 'integer',
        'max_extends' => 'integer',
        'max_seats_per_order' => 'integer',
        'availability_version' => 'integer',
        'booking_fee_amount' => 'integer',
        'booking_fee_percent' => 'integer',
        'tax_rate' => 'integer',
        'tax_included' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function series()
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }

    public function seatMap()
    {
        return $this->belongsTo(SeatMap::class);
    }

    public function seatMapVersion()
    {
        return $this->belongsTo(SeatMapVersion::class);
    }

    public function priceZones()
    {
        return $this->hasMany(EventPriceZone::class)->orderBy('sort_order');
    }

    public function seatOverrides()
    {
        return $this->hasMany(EventSeatOverride::class);
    }

    public function holds()
    {
        return $this->hasMany(Hold::class);
    }

    public function allocations()
    {
        return $this->hasMany(Allocation::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function isSellable(): bool
    {
        return $this->status === 'published' && $this->seat_map_version_id !== null;
    }

    public function isCancelled(): bool
    {
        return 'cancelled' === $this->status;
    }

    /**
     * Bump the availability cursor. Called whenever seat state changes so pollers can ask for
     * "what changed since N" rather than refetching the whole map.
     */
    public function bumpAvailabilityVersion(): int
    {
        $next = DB::table('events')
            ->where('id', $this->id)
            ->incrementEach(['availability_version' => 1]);

        $this->availability_version = (int) DB::table('events')
            ->where('id', $this->id)->value('availability_version');

        return $this->availability_version;
    }
}
