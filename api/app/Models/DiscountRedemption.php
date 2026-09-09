<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One use of one code, on one order.
 *
 * The row is the claim on the code's remaining uses; the unique index on
 * (discount_code_id, external_order_row_id) is what makes a retried checkout use it once.
 */
class DiscountRedemption extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'discount_code_id', 'external_order_row_id', 'amount', 'currency',
    ];

    protected $casts = ['amount' => 'integer'];

    public function code()
    {
        return $this->belongsTo(DiscountCode::class, 'discount_code_id');
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }
}
