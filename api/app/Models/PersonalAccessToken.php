<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model, with one change: the token holder is resolved without tenant scoping.
 *
 * Authentication necessarily happens *before* the tenant is known — that is what it establishes.
 * `CheckinDevice` is tenant-scoped, so with the scope applied the lookup runs with no tenant bound,
 * the fail-closed scope returns nothing, and every device request 401s. (It appeared to work in
 * tests only because the tenant bound during pairing leaked into the next request in-process.)
 *
 * This does not weaken isolation: the token itself is the secret, and `ResolveCheckinDevice`
 * immediately binds and validates the tenant that owns the device before anything is read.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function tokenable(): MorphTo
    {
        return parent::tokenable()->withoutGlobalScope('tenant');
    }
}
