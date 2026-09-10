<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The offer: buy this whole run at once, and here is what that saves.
 *
 * A pass owns no inventory and no price of its own. Every night in the run is still priced by the
 * event it belongs to — a Tuesday that costs less costs less on a season ticket too — and the pass
 * only says what comes off the sum. Giving a pass its own price would have been a second pricing
 * system, and the first thing an organiser would do with it is forget to keep the two in step.
 */
class SeasonPass extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** Every night, or a number of the buyer's choosing. */
    public const KINDS = ['all', 'choose'];

    protected $fillable = [
        'tenant_id', 'series_id', 'name', 'description', 'kind', 'nights',
        'discount_kind', 'discount_value', 'currency', 'max_seats',
        'on_sale_at', 'off_sale_at', 'status', 'position', 'created_by',
    ];

    protected $casts = [
        'nights' => 'integer',
        'discount_value' => 'integer',
        'max_seats' => 'integer',
        'position' => 'integer',
        'on_sale_at' => 'datetime',
        'off_sale_at' => 'datetime',
    ];

    public function series()
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }

    public function bookings()
    {
        return $this->hasMany(SeasonBooking::class);
    }

    /** Whether the buyer picks which nights, or gets all of them. */
    public function isFlexible(): bool
    {
        return 'choose' === $this->kind;
    }

    /** Switched on, started, and not finished. */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?: now();

        return 'active' === $this->status
            && ! ($this->on_sale_at && $this->on_sale_at->greaterThan($at))
            && ! ($this->off_sale_at && $this->off_sale_at->lessThanOrEqualTo($at));
    }

    /**
     * What this pass takes off a run costing `$tickets`.
     *
     * Never more than the tickets themselves: a pass worth more than the run would be a pass that
     * paid the buyer to come, and rounding a percentage always rounds half up, once, here.
     */
    public function discountOn(int $tickets): int
    {
        $tickets = max(0, $tickets);

        $off = 'percent' === $this->discount_kind
            ? intdiv($tickets * $this->discount_value + 50, 100)
            : $this->discount_value;

        return max(0, min($tickets, $off));
    }
}
