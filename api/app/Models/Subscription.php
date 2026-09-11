<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancelled_at',
        'past_due_since', 'last_invoiced_to',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        // What the platform's own billing has done about this account: how far it has invoiced,
        // and since when it has not been paid.
        'past_due_since' => 'datetime',
        'last_invoiced_to' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Good standing.
     *
     * `past_due` is deliberately not here and deliberately not usable *or* unusable by this
     * method's lights: an account that has not paid keeps working while somebody sorts it out, and
     * the decision to cut it off belongs to a person rather than to a boolean.
     */
    public function isUsable(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true);
    }

    public function isPastDue(): bool
    {
        return 'past_due' === $this->status || null !== $this->past_due_since;
    }
}
