<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What one booking bought beside its seats.
 *
 * The name and the unit price are copied rather than joined. An organiser who puts the programme
 * up next month must not change what a booking made this month says it paid — the same rule the
 * seat price snapshot follows, and for the same reason.
 */
class OrderAddon extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'order_addons';

    protected $fillable = [
        'tenant_id', 'external_order_row_id', 'addon_id', 'name',
        'quantity', 'unit_price', 'amount', 'currency', 'refunded_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'amount' => 'integer',
        'refunded_at' => 'datetime',
    ];

    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }
}
