<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One attempt to bring a buyer back to a purchase they started and did not finish.
 *
 * It holds no basket. The basket is on the order this points at — its hold, its signed price
 * snapshot, its seats — and copying that here would be a second cart to drift from the first.
 */
class BasketRecovery extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /**
     * `waiting` has been noticed and not yet written to; `declined` is somebody who said no
     * thank you, and is never written to about this basket again.
     */
    public const STATUSES = ['waiting', 'sent', 'recovered', 'expired', 'declined'];

    protected $fillable = [
        'tenant_id', 'site_id', 'event_id', 'external_order_row_id',
        'email', 'name', 'locale', 'currency', 'total_amount', 'seats',
        'token', 'status', 'sent_at', 'recovered_at', 'recovered_order_id',
    ];

    protected $casts = [
        'total_amount' => 'integer',
        'seats' => 'integer',
        'sent_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    /** Long and random: this link puts seats in somebody's basket. */
    public static function newToken(): string
    {
        return Str::lower(Str::random(48));
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }

    public function recoveredOrder()
    {
        return $this->belongsTo(ExternalOrder::class, 'recovered_order_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    /** Whether this link is still worth following. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['waiting', 'sent'], true);
    }
}
