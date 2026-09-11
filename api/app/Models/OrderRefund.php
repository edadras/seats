<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to give money back.
 *
 * `sent` rows add up to what has actually left the organiser, which is what stops a second refund
 * from sending more than was ever charged. `manual` rows are the ones a box office settles out of
 * the drawer; they count against the same total for the same reason.
 */
class OrderRefund extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'external_order_row_id', 'amount', 'currency',
        'status', 'gateway', 'reference', 'reason', 'message', 'requested_by',
    ];

    protected $casts = ['amount' => 'integer'];

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }
}
