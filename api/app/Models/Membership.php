<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody's membership: a period, an address, and the scheme it belongs to.
 *
 * Whether it is *current* is a comparison against now and is never stored — a status column would
 * need a nightly job to stay true, and the one night it did not run is the night a member is turned
 * away at their own presale.
 */
class Membership extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'membership_scheme_id', 'email', 'name',
        'starts_at', 'ends_at', 'source', 'external_order_row_id', 'cancelled_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function scheme()
    {
        return $this->belongsTo(MembershipScheme::class, 'membership_scheme_id');
    }

    public function isCurrent(): bool
    {
        return null === $this->cancelled_at && $this->ends_at->isFuture();
    }

    /** Only the ones that are running today. */
    public function scopeCurrent($query)
    {
        return $query->whereNull('cancelled_at')->where('ends_at', '>', now());
    }
}
