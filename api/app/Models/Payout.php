<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One period, paid once.
 *
 * Deliberately not a `BelongsToTenant` model. Payouts are the platform's side of the relationship
 * — written by an operator in the console, which reads unscoped by design — and the organiser's
 * own view of them is one narrow query that states its tenant out loud. A global scope here would
 * make the console silently return nothing and invite somebody to work around it.
 *
 * Nothing on a payout is editable once it exists. It is voided and replaced, which keeps the thing
 * that was sent and the thing that is true as two separate, findable rows.
 */
class Payout extends Model
{
    use HasUuids;

    public const STATUSES = ['recorded', 'paid', 'void'];

    protected $fillable = [
        'tenant_id', 'currency', 'period_from', 'period_to',
        'charged', 'refunded', 'kept', 'tax_kept', 'commission', 'payable',
        'commission_rate', 'orders', 'events',
        'status', 'reference', 'method', 'note', 'paid_at', 'created_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'charged' => 'integer',
        'refunded' => 'integer',
        'kept' => 'integer',
        'tax_kept' => 'integer',
        'commission' => 'integer',
        'payable' => 'integer',
        'commission_rate' => 'integer',
        'orders' => 'integer',
        'events' => 'array',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isVoid(): bool
    {
        return 'void' === $this->status;
    }
}
