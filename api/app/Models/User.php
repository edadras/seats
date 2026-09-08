<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Users are global, not tenant-scoped: one person may be a member of several organiser accounts.
 * Authorisation always goes through the TenantUser membership, never through this model alone.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function memberships()
    {
        return $this->hasMany(TenantUser::class);
    }

    public function membershipFor(string $tenantId): ?TenantUser
    {
        return $this->memberships()->where('tenant_id', $tenantId)->first();
    }

    /** The role this user holds in the given tenant, or null if they are not a member. */
    public function roleIn(string $tenantId): ?string
    {
        return $this->membershipFor($tenantId)?->role;
    }
}
