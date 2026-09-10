<?php

namespace App\Support\Access;

use App\Models\TenantRole;
use App\Models\TenantUser;
use Illuminate\Http\Request;

/**
 * What the caller of this request may do.
 *
 * One place, because the answer depends on three things that arrive separately — the membership,
 * whether that membership names a built-in role or one the organiser invented, and whether the
 * caller is a person at all — and working that out at each of seventy call sites is how a system
 * ends up with two of them disagreeing.
 *
 * Resolved once per request and cached on the request, because it is asked for on every mutation
 * and a custom role is a database row.
 */
class Gate
{
    /** @return list<string> */
    public function permissions(Request $request): array
    {
        if ($request->attributes->has('permissions')) {
            return $request->attributes->get('permissions');
        }

        $permissions = $this->resolve($request);

        $request->attributes->set('permissions', $permissions);

        return $permissions;
    }

    public function allows(Request $request, string $permission): bool
    {
        return in_array($permission, $this->permissions($request), true);
    }

    /**
     * What one membership holds, without a request to hang it on.
     *
     * Sign-in needs this: the answer is wanted before there is an authenticated request to resolve
     * it from, and working it out a second way there is how the panel and the server end up
     * disagreeing about what somebody may do.
     *
     * @return list<string>
     */
    public function forMembership(TenantUser $membership): array
    {
        if (Permissions::isBuiltIn($membership->role)) {
            return Permissions::forRole($membership->role);
        }

        // A role the organiser made. Unknown means nothing, not everything: a role deleted while
        // somebody held it must lock them out, never let them in.
        $role = TenantRole::where('key', $membership->role)->first();

        return $role ? Permissions::sanitise((array) $role->permissions) : [];
    }

    /** @return list<string> */
    private function resolve(Request $request): array
    {
        $membership = $request->attributes->get('membership');

        if (! $membership instanceof TenantUser) {
            /*
             * A signed API client or a check-in device, not a person. Those are authorised by their
             * own middleware against their own scopes, and giving them a role here would be giving
             * a machine a seat at a table it is not sitting at.
             */
            return [];
        }

        return $this->forMembership($membership);
    }
}
