<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Binds the tenant for panel/management calls.
 *
 * The tenant comes from the caller's membership, never from a request parameter alone: an
 * X-Tenant-Id header is honoured only to *choose between* tenants the user already belongs to.
 */
class ResolveTenantFromUser
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            throw ApiException::unauthorized('unauthenticated', 'Authentication required.');
        }

        $memberships = $user->memberships()->get();

        if ($memberships->isEmpty()) {
            throw ApiException::forbidden('This account is not a member of any organiser.', 'no_membership');
        }

        $requested = $request->header('X-Tenant-Id');

        $membership = $requested
            ? $memberships->firstWhere('tenant_id', $requested)
            : $memberships->first();

        if (! $membership) {
            // The user is authenticated but not a member: report it as not-found so the header
            // cannot be used to probe which tenant ids exist.
            throw ApiException::notFound('Unknown organiser.', 'unknown_tenant');
        }

        $tenant = $this->tenantContext->runUnscoped(fn () => Tenant::find($membership->tenant_id));

        if (! $tenant || ! $tenant->isActive()) {
            throw ApiException::forbidden('This organiser account is suspended.', 'tenant_suspended');
        }

        $this->tenantContext->set($tenant);
        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
