<?php

namespace App\Http\Controllers\Site;

use App\Domain\Sites\SiteResolver;
use App\Domain\Sites\Themes;
use App\Domain\Sites\WebApp;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The three files that turn a venue's site into something you can keep on a home screen.
 *
 * Outside the site middleware and resolving the Host themselves, for the same reason robots.txt is:
 * they live at the root of every host this application answers on, and the control panel — which is
 * not an app anybody installs — has to be able to say so rather than 404.
 *
 * Everything here is derived from the site's own record. There is no platform manifest, no platform
 * icon and no shared service worker: a white-label product whose app icon is the vendor's logo is
 * not white-label.
 */
class WebAppController extends Controller
{
    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly WebApp $app,
    ) {}

    /** What a browser reads before offering to install anything. */
    public function manifest(Request $request)
    {
        $site = $this->site($request);
        $brand = Themes::forSite($site);

        return response()
            ->json(
                $this->app->manifest($site, $brand, $this->locale($site)),
                200,
                [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )
            ->header('Content-Type', 'application/manifest+json; charset=UTF-8')
            // Short, because it carries the site's name and colours and an organiser who changes
            // either expects to see it. Long enough that it is not fetched on every page.
            ->header('Cache-Control', 'public, max-age=600');
    }

    /**
     * The tile, drawn from the venue's own colours.
     *
     * Answered with an ETag rather than a long max-age: the picture is cheap to draw and an
     * organiser who recolours their site should not have to explain to their staff why the home
     * screen is still the old colour a week later.
     */
    public function icon(Request $request, int $size)
    {
        if (! in_array($size, WebApp::SIZES, true)) {
            throw new NotFoundHttpException('No icon is drawn at that size.');
        }

        $site = $this->site($request);
        $brand = Themes::forSite($site);
        $etag = $this->app->etag($site, $brand, $size);

        if ($etag === $request->headers->get('If-None-Match')) {
            return response('', 304)->setEtag($etag);
        }

        return response($this->app->icon($site, $brand, $size), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ])->setEtag($etag);
    }

    /**
     * The service worker, with the two facts it cannot work out for itself prepended.
     *
     * The build stamp is what makes a browser install a new worker: it is a hash of the files the
     * worker precaches, so shipping a stylesheet retires the old shell and shipping nothing retires
     * nothing. The shell list carries *this site's* theme rather than all six, because five of them
     * are bytes nobody on this domain will ever render.
     *
     * Served from here rather than as a static file so it can have root scope: a worker registered
     * from `/site/js/sw.js` may only ever see requests under `/site/js/`, which is the one part of
     * the site that needs it least.
     */
    public function serviceWorker(Request $request)
    {
        $site = $this->site($request);
        $brand = Themes::forSite($site);

        $shell = [
            '/offline',
            '/site/css/site.css',
            '/site/css/themes/'.$brand['base_key'].'.css',
            '/site/css/widget.css',
            '/site/js/widget.js',
        ];

        $head = 'self.SEATMAP_BUILD='.json_encode($this->build($shell))
            .';self.SEATMAP_SHELL='.json_encode($shell, JSON_UNESCAPED_SLASHES).";\n";

        return response($head.file_get_contents(resource_path('js/sw.js')), 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            // A worker must never be served from a stale cache: that is how a site gets stuck on a
            // worker it has replaced, and the browser has its own 24-hour rule for exactly this.
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /** What a visitor sees when there is no signal and no cached copy of where they were going. */
    public function offline(Request $request)
    {
        $site = $this->site($request);

        app()->setLocale($this->locale($site));

        return response()->view('site.offline', [
            'site' => $site,
            'brand' => Themes::forSite($site),
            'title' => __('site.app.offlineTitle'),
        ])->header('Cache-Control', 'no-cache');
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * The language a home-screen icon speaks.
     *
     * The site's own, rather than the one this request resolved to — these routes sit outside the
     * site middleware, where that has not been worked out, and a manifest is read once at install
     * time rather than on every page. A venue in Tehran should not have its app named in English
     * because a crawler asked for the manifest with an English Accept-Language.
     */
    private function locale(Site $site): string
    {
        return $site->locale ?: (string) config('app.locale');
    }

    private function site(Request $request): Site
    {
        $site = $this->resolver->resolve($request->getHost());

        if (! $site) {
            // The panel, the console, the API. None of them is a thing anybody installs.
            throw new NotFoundHttpException('No site is published at this address.');
        }

        return $site;
    }

    /**
     * One short string that changes when any of the precached files does.
     *
     * Modification times rather than contents: the files are on this machine's disk, a deploy
     * rewrites them, and hashing five stylesheets on every request to a worker that is fetched on
     * every page load is work nobody asked for.
     *
     * @param  list<string>  $shell
     */
    private function build(array $shell): string
    {
        $parts = [];

        foreach ($shell as $path) {
            $file = public_path(ltrim($path, '/'));

            $parts[] = is_file($file) ? (string) filemtime($file) : '0';
        }

        return substr(md5(implode('|', $parts)), 0, 12);
    }
}
