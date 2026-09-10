<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One purchase, over several nights.
 *
 * It groups orders and it charges once. It owns no seats, no tickets and no allocations: those
 * belong to the nights' own orders, exactly as they would if the buyer had bought each night
 * separately, which is the whole point — the door has never heard of this table.
 */
class SeasonBooking extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'season_pass_id', 'series_id', 'site_id', 'reference', 'buyer',
        'currency', 'total_amount', 'discount_amount', 'seats', 'nights', 'status',
        'lead_order_id', 'gateway', 'payment_reference', 'metadata',
        'confirmed_at', 'cancelled_at',
    ];

    protected $casts = [
        'buyer' => 'array',
        'metadata' => 'array',
        'total_amount' => 'integer',
        'discount_amount' => 'integer',
        'seats' => 'integer',
        'nights' => 'integer',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function pass()
    {
        return $this->belongsTo(SeasonPass::class, 'season_pass_id');
    }

    public function series()
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }

    /** Every night's order, oldest night first. */
    public function orders()
    {
        return $this->hasMany(ExternalOrder::class, 'season_booking_id');
    }

    public function leadOrder()
    {
        return $this->belongsTo(ExternalOrder::class, 'lead_order_id');
    }

    public function isConfirmed(): bool
    {
        return 'confirmed' === $this->status;
    }
}
