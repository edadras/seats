<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody who runs the platform rather than an account on it.
 *
 * Not a tenant role, and not reachable from one: an operator is not a member of anybody's
 * organiser. No global scope either — this table is *about* the platform, and scoping it to a
 * tenant would be nonsense.
 */
class PlatformAdmin extends Model
{
    use HasUuids;

    public const LEVELS = ['support', 'operator'];

    protected $fillable = ['user_id', 'level', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->withoutGlobalScope('tenant');
    }

    /** Support can look; an operator can change things. */
    public function mayChange(): bool
    {
        return 'operator' === $this->level;
    }
}
