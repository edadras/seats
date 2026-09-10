<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a voucher's money, signed.
 *
 * These rows are the balance. `issue` and `refund` are positive, `spend` is negative, `void` takes
 * back whatever was left, and the sum of them is what the holder may still spend. Nothing here is
 * ever edited or deleted: a ledger that can be rewritten is not a ledger, and an organiser being
 * asked where a hundred euros went needs the row that says.
 */
class VoucherMovement extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const KINDS = ['issue', 'spend', 'refund', 'void'];

    protected $fillable = [
        'tenant_id', 'voucher_id', 'external_order_row_id', 'kind', 'amount', 'currency',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }
}
