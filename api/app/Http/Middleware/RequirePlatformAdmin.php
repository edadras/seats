<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\PlatformAdmin;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * The door to the platform console.
 *
 * Two things happen here and both matter. Membership of `platform_admins` is checked — never a
 * tenant role, because a tenant role that could be escalated into platform access would put every
 * organiser's data one bug away from every other's. And the tenant binding is cleared, because an
 * operator is not inside an account: a console request that arrived with a tenant bound would read
 * one organiser's rows through a global scope and call it "all of them".
 *
 * A request that gets past this reads unscoped on purpose, and every write it makes is recorded in
 * `platform_audit_logs` — an operator's actions are auditable by the platform even when the
 * organiser's own log knows nothing about them.
 */
class RequirePlatformAdmin
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            throw ApiException::unauthorized('unauthenticated', 'Authentication required.');
        }

        $admin = $this->tenantContext->runUnscoped(
            fn () => PlatformAdmin::where('user_id', $user->id)->first()
        );

        if (! $admin) {
            // Not-found rather than forbidden: the console's existence is not a secret, but who is
            // on it should not be discoverable by trying.
            throw ApiException::notFound('Not found.', 'not_found');
        }

        $this->tenantContext->set(null);
        $request->attributes->set('platform_admin', $admin);

        $admin->forceFill(['last_seen_at' => now()])->save();

        /*
         * The console reads across every organiser, so the whole request runs unscoped — stated
         * once, here, rather than as `withoutGlobalScope` sprinkled through a controller where one
         * forgotten call is a screen that quietly shows nothing.
         *
         * This is the one place in the application that does this on an authenticated path, and it
         * is why the check above is membership of `platform_admins` and nothing else.
         */
        return $this->tenantContext->runUnscoped(fn () => $next($request));
    }
}
