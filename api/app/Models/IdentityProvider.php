<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The account's own identity provider: where its staff really sign in.
 *
 * What is typed is the issuer, a client id and a secret. What is kept beside them is what the
 * issuer itself published — the three endpoints a sign-in actually uses — read once when the
 * settings were saved rather than fetched on every attempt, because a provider's well-known
 * document changing between one Tuesday and the next is not a thing that happens, and a sign-in
 * screen that makes two round trips to somebody else's server before it can redirect is a sign-in
 * screen that is down whenever they are slow.
 */
class IdentityProvider extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'label', 'issuer', 'client_id', 'client_secret',
        'authorize_url', 'token_url', 'userinfo_url', 'discovered_at',
        'enabled', 'required',
    ];

    /** The secret is never part of a response: there is no reading it back, only replacing it. */
    protected $hidden = ['client_secret'];

    protected $casts = [
        'client_secret' => 'encrypted',
        'discovered_at' => 'datetime',
        'enabled' => 'boolean',
        'required' => 'boolean',
    ];

    /** Whether this provider can actually take somebody's sign-in. */
    public function isUsable(): bool
    {
        return $this->enabled
            && '' !== trim((string) $this->authorize_url)
            && '' !== trim((string) $this->token_url)
            && '' !== trim((string) $this->userinfo_url);
    }

    /** Whether a password is still a way in to this account. */
    public function locksOutPasswords(): bool
    {
        return $this->required && $this->isUsable();
    }
}
