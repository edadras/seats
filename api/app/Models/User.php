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

    // The secret is a credential of the same kind as the password: it never leaves in a payload.
    protected $hidden = ['password', 'remember_token', 'totp_secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted rather than hashed: a TOTP code is checked by computing the same code, so
            // the server has to be able to read the secret back. Encryption is what stops a stolen
            // database from being a stolen set of authenticators.
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'recovery_codes' => 'array',
        ];
    }

    /** Enrolled *and* finished: a half-set-up authenticator must not lock anybody out. */
    public function hasTwoFactor(): bool
    {
        return null !== $this->totp_secret && null !== $this->totp_confirmed_at;
    }

    /**
     * Every account this person belongs to.
     *
     * Deliberately unscoped: this relation is what *decides* which tenant to bind, so it cannot be
     * inside one. It is the sign-in question — "whose account is this?" — and it is the only place
     * memberships are read across tenants. Everything after sign-in goes through the scoped model.
     */
    public function memberships()
    {
        return $this->hasMany(TenantUser::class)->withoutGlobalScope('tenant');
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
