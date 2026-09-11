<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One transaction inside a payout, as the gateway lists it.
 *
 * `amount` is signed, because that is how a statement arrives: a refund is a negative number on the
 * same statement as the sale. A column that needed its sign convention explained in a comment is a
 * column somebody eventually adds up the wrong way.
 */
class GatewayPayoutLine extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** What a line can be. Anything a gateway calls something else lands in `adjustment`. */
    public const KINDS = ['payment', 'refund', 'fee', 'adjustment'];

    protected $fillable = [
        'tenant_id', 'gateway_payout_id', 'reference', 'kind',
        'amount', 'fee', 'occurred_on', 'description',
    ];

    protected $casts = [
        'amount' => 'integer',
        'fee' => 'integer',
        'occurred_on' => 'date',
    ];

    public function payout()
    {
        return $this->belongsTo(GatewayPayout::class, 'gateway_payout_id');
    }
}
