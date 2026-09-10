<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cash that moved for a reason that was not a sale.
 *
 * A taxi paid for out of the drawer, twenty pounds put in to make change, a float topped up
 * mid-evening. These are the only cash movements written down: everything else — what was sold and
 * what was handed back — is already an order, and a second copy of it here is a second number to
 * disagree with the first.
 */
class ShiftMovement extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'shift_id', 'kind', 'amount', 'reason', 'created_by'];

    protected $casts = ['amount' => 'integer'];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /** In or out, as a sign. The amount itself is always positive: a negative "in" is a typo. */
    public function signed(): int
    {
        return 'in' === $this->kind ? $this->amount : -$this->amount;
    }
}
