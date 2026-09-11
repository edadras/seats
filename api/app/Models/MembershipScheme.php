<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One rung of a venue's Friends scheme: what it costs, how long it lasts, and what it is worth.
 *
 * It does not answer "who is in it" or "is this person still a member" — those are rows in
 * `memberships` and a comparison against today, and neither can be read off a column here.
 */
class MembershipScheme extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'currency', 'price', 'months',
        'discount_percent', 'presale', 'enabled', 'position', 'addon_id',
    ];

    protected $casts = [
        'price' => 'integer',
        'months' => 'integer',
        'discount_percent' => 'integer',
        'position' => 'integer',
        'presale' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }

    /** What this scheme takes off a subtotal. Rounded half up, once, like every other discount. */
    public function discountOn(int $tickets): int
    {
        if ($this->discount_percent < 1 || $tickets < 1) {
            return 0;
        }

        return min($tickets, (int) round($tickets * $this->discount_percent / 100));
    }
}
