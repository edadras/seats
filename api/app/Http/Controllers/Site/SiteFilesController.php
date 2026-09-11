<?php

namespace App\Http\Controllers\Site;

use App\Domain\Sites\SiteResolver;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Site;
use App\Models\SitePage;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The two files a site serves to machines rather than to people.
 *
 * Outside the site middleware, and resolving the Host itself, because these paths exist on every
 * host this application answers on — including the control panel, which is emphatically not a
 * thing to index. A route that only existed for sites would leave `panel.example/robots.txt` as a
 * 404, and a missing robots.txt means "crawl everything".
 *
 * They were `public/robots.txt` before: one static file, the same answer for a venue's website and
 * for the panel, which is the wrong answer for at least one of them.
 */
class SiteFilesController extends Controller
{
    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly TenantContext $tenants,
    ) {}

    public function robots(Request $request)
    {
        $site = $this->resolver->resolve($request->getHost());

        if (! $site) {
            // The panel, the console, the API. Nothing here is for a search engine, and the parts
            // worth having are behind a sign-in anyway.
            return $this->text("User-agent: *\nDisallow: /\n");
        }

        return $this->text(
            "User-agent: *\nAllow: /\n".
            // Not secrets — a checkout with no session in it is an empty page — but they are of no
            // use in an index and a crawler following them wastes everybody's time.
            "Disallow: /checkout\nDisallow: /order/\nDisallow: /account\n".
            'Sitemap: '.$site->url('/sitemap.xml')."\n"
        );
    }

    public function sitemap(Request $request)
    {
        $site = $this->resolver->resolve($request->getHost());

        if (! $site) {
            throw new NotFoundHttpException('No site is published at this address.');
        }

        $urls = $this->inTenant($site, fn () => $this->urls($site));

        return response()->view('site.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * Every address on this site worth a visit: its published pages, and the events that have not
     * happened yet. A draft page is not in it, because it is not on the internet.
     */
    private function urls(Site $site): array
    {
        $urls = [['loc' => $site->url('/'), 'changefreq' => 'daily', 'priority' => '1.0']];

        foreach (SitePage::where('site_id', $site->id)->whereNotNull('published_at')->get() as $page) {
            if ('' === $page->slug) {
                continue; // The home page is already first in the list.
            }

            $urls[] = [
                'loc' => $site->url($page->path()),
                'lastmod' => $page->published_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        $events = Event::where('status', 'published')
            ->where('is_rehearsal', false)
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('starts_at')
            ->limit(2000)
            ->get();

        foreach ($events as $event) {
            $urls[] = [
                'loc' => $site->url('/events/'.$event->public_id),
                'lastmod' => $event->updated_at?->toAtomString(),
                'changefreq' => 'daily',
                'priority' => '0.9',
            ];
        }

        return $urls;
    }

    /** The tenant scope the site middleware would have bound, bound here instead. */
    private function inTenant(Site $site, callable $work): array
    {
        $tenant = $this->tenants->runUnscoped(fn () => Tenant::find($site->tenant_id));

        if (! $tenant || ! $tenant->isActive()) {
            throw new NotFoundHttpException('This site is not available.');
        }

        return $this->tenants->runAs($tenant, $work);
    }

    private function text(string $body)
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
