<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What the platform says one organiser owes it for one period.
 *
 * Not a `BelongsToTenant` model, for the same reason a payout is not: this is the platform's side
 * of the relationship, written by a scheduled run and read in a console that reads unscoped. The
 * organiser's own view of their invoices is one narrow query that states its tenant out loud.
 *
 * Nothing here is recalculated. The plan's price changes, the commission rate changes, a refund
 * lands in the period this covers — none of that may alter an invoice that has been sent. It is
 * voided and replaced, which keeps the thing that was sent and the thing that is true as two
 * findable rows.
 */
class PlatformInvoice extends Model
{
    use HasUuids;

    public const STATUSES = ['open', 'paid', 'uncollectible', 'void'];

    protected $fillable = [
        'tenant_id', 'number', 'currency', 'period_from', 'period_to',
        'subscription_amount', 'commission_amount', 'tax_amount', 'total', 'vat_rate',
        'lines', 'status', 'due_on', 'issued_at', 'paid_at', 'method', 'reference',
        'attempts', 'next_attempt_at', 'last_attempt_at', 'last_error', 'settled_by', 'void_reason',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'due_on' => 'date',
        'subscription_amount' => 'integer',
        'commission_amount' => 'integer',
        'tax_amount' => 'integer',
        'total' => 'integer',
        'vat_rate' => 'integer',
        'attempts' => 'integer',
        'lines' => 'array',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isOpen(): bool
    {
        return 'open' === $this->status;
    }

    /** Owed, and the day it was due has gone. */
    public function isOverdue(?\DateTimeInterface $at = null): bool
    {
        return $this->isOpen()
            && $this->due_on
            && $this->due_on->endOfDay()->lessThan($at ?: now());
    }
}
