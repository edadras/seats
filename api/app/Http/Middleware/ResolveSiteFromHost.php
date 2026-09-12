<?php

namespace App\Http\Middleware;

use App\Domain\Sites\SiteResolver;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Binds the tenant for a public request to a hosted site, from the Host header alone.
 *
 * The panel's own hosts never reach here — they are matched first, in routes/web.php — so an
 * unknown Host is a 404 rather than a panel someone was not meant to find.
 */
class ResolveSiteFromHost
{
    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $site = $this->resolver->resolve($request->getHost());

        if (! $site) {
            throw new NotFoundHttpException('No site is published at this address.');
        }

        $tenant = $this->tenantContext->runUnscoped(fn () => Tenant::find($site->tenant_id));

        if (! $tenant || ! $tenant->isActive()) {
            // A suspended organiser's site goes dark rather than half-working: a visitor who could
            // still reach checkout would be buying from an account that cannot fulfil.
            throw new NotFoundHttpException('This site is not available.');
        }

        $this->tenantContext->set($tenant);
        $request->attributes->set('site', $site);

        /*
         * The calendar this site writes its dates in.
         *
         * Bound here rather than where a date is printed, because every page of a hosted site goes
         * through this and none of them should have to know: a programme, a basket, a confirmation
         * and the ticket that comes out of it are one venue's dates and are written one way.
         */
        \App\Support\Locale\Calendars::use($site->calendar);

        /*
         * Which link they arrived on, remembered here rather than at the checkout.
         *
         * Here because it is the only place every page of a hosted site goes through, and a
         * promoter's link points at whichever page they chose to share — a programme, a single
         * night, the front door. Last touch inside a window: a newer link replaces an older one,
         * because the newer one is the thing that finally worked.
         */
        $landing = app(\App\Domain\Attribution\Attribution::class)->fromRequest($request);

        if ($landing && $request->hasSession()) {
            $request->session()->put(\App\Domain\Attribution\Attribution::SESSION_KEY, $landing);
        }

        return $next($request);
    }
}
