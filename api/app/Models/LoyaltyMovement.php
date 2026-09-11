<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened to somebody's points.
 *
 * The balance is the sum of these and is never stored, in the same way a voucher's balance and an
 * agent's credit are not: a total kept beside the events that produced it is a second opinion, and
 * the two differ the first time one of them is undone.
 */
class LoyaltyMovement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'email', 'kind', 'points', 'external_order_row_id', 'note',
    ];

    protected $casts = ['points' => 'integer'];

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }
}
