<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Access\Permissions;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's place in one organiser's account.
 *
 * `role` holds either a built-in key or the key of a role the organiser invented, so there is one
 * column and one resolution path. What a role *means* is answered by Permissions and the Gate, not
 * here: a model that could answer "may they?" is a model every call site would ask instead of the
 * one place that knows.
 */
class TenantUser extends Model
{
    /*
     * Scoped like everything else. It was not, because the only code that read it was the
     * middleware that resolves a tenant *from* it — which necessarily runs before one is bound.
     * The moment a screen listed memberships, that omission became a page showing one organiser
     * the names and email addresses of another's staff.
     *
     * The middleware now asks unscoped, explicitly, which is the one place that is correct.
     */
    use BelongsToTenant, HasFactory, HasUuids;

    /** The built-in roles. A custom role's key is also valid here. */
    public const ROLES = Permissions::RESERVED_ROLE_KEYS;

    protected $fillable = [
        'tenant_id', 'user_id', 'role', 'last_seen_at', 'suspended_at', 'suspended_reason',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Suspension rather than removal, so somebody who left keeps their name on what they did.
     * Deleting the membership would orphan every audit row naming them, which is the opposite of
     * what an audit log is for.
     */
    public function isSuspended(): bool
    {
        return null !== $this->suspended_at;
    }
}
