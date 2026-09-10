<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of a payment plan: an amount, a date, and whether it has been paid.
 *
 * A row rather than a formula, because what a box office chases is a date and an amount — and a
 * date moved because a school's treasurer is away is a fact about this booking, not a new formula.
 */
class OrderInstalment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'external_order_row_id', 'sequence', 'kind', 'amount', 'due_on',
        'paid_at', 'method', 'recorded_by', 'note',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'amount' => 'integer',
        'due_on' => 'date',
        'paid_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }

    public function isPaid(): bool
    {
        return null !== $this->paid_at;
    }

    /**
     * Late, as of now.
     *
     * End-of-day rather than the stroke of midnight: an instalment due on the 14th is not late
     * until the 15th, which is what everybody means and what a treasurer would say in an argument.
     */
    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_on->endOfDay()->isPast();
    }
}
