<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody who may not buy from this organiser, and why.
 *
 * A row about a person rather than about a booking: repeat chargebacks, a barring order, somebody
 * who must not be in the building. The reason is required because a block nobody wrote a reason for
 * is a block nobody can defend a year later.
 */
class BlockedBuyer extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'email', 'phone', 'reason', 'until', 'created_by'];

    protected $casts = ['until' => 'datetime'];

    /** Is this block in force at the moment? A date that has passed lifts it without anybody acting. */
    public function inForce(): bool
    {
        return ! $this->until || $this->until->isFuture();
    }
}
