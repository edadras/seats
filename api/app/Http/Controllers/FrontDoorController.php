<?php

namespace App\Http\Controllers;

use App\Domain\Sites\SiteResolver;
use App\Http\Controllers\Site\SitePageController;
use App\Models\SiteDomain;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one door every unmatched GET comes through, and where it decides whether it is a panel
 * request or a visitor to somebody's event site.
 *
 * A router-level split does not work here. The panel is served at `/` and so is a site's home page,
 * and route matching happens before middleware — so a domain-constrained group and an unconstrained
 * one collide on the same URI, and whichever registers last wins for everybody. Deciding inside one
 * action is both simpler and the only version that is correct when no panel host is configured,
 * which is how the thing runs in development.
 */
class FrontDoorController extends Controller
{
    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request, SitePageController $pages, string $path = '')
    {
        $host = SiteDomain::normalise($request->getHost());
        $panelHosts = array_map([SiteDomain::class, 'normalise'], config('seatmap.sites.panel_hosts', []));

        // A configured panel host is never looked up as a site: it is the control plane, and a site
        // claiming that hostname would be a takeover of the panel itself.
        if (in_array($host, $panelHosts, true)) {
            return view('panel');
        }

        $site = $this->resolver->resolve($host);

        if ($site) {
            $tenant = $this->tenantContext->runUnscoped(fn () => Tenant::find($site->tenant_id));

            if (! $tenant || ! $tenant->isActive()) {
                throw new NotFoundHttpException('This site is not available.');
            }

            $this->tenantContext->set($tenant);
            $request->attributes->set('site', $site);

            return $pages->show($request, $path);
        }

        // No panel host configured means a single-host deployment — development, or a install that
        // has not split the two yet — so the panel answers for anything that is not a site.
        if (! $panelHosts) {
            return view('panel');
        }

        throw new NotFoundHttpException('No site is published at this address.');
    }
}
