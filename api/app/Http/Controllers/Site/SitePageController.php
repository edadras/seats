<?php

namespace App\Http\Controllers\Site;

use App\Domain\Availability\AvailabilityService;
use App\Domain\SeatMaps\PublishedGeometry;
use App\Domain\Sites\Themes;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Site;
use App\Models\SitePage;
use App\Support\Locale\Dates;
use App\Support\Locale\Locales;
use App\Support\Locale\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a hosted event site.
 *
 * The site and the tenant were bound from the Host by ResolveSiteFromHost; nothing here reads a
 * tenant from the request. Everything a page shows is either the page's own published blocks or
 * this tenant's own events, so the ordinary tenant scope is the isolation.
 */
class SitePageController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PublishedGeometry $geometry,
    ) {}

    public function show(Request $request, string $path = '')
    {
        $site = $request->attributes->get('site');
        $slug = trim($path, '/');

        $page = SitePage::where('site_id', $site->id)->where('slug', $slug)->first();

        if (! $page || ! $page->isPublished()) {
            throw new NotFoundHttpException('No such page.');
        }

        return $this->render($site, $page, $page->liveBlocks(), [
            'title' => $page->seo_title ?: ($page->title.' · '.$site->name),
            'description' => $page->seo_description,
            'canonical' => $site->url($page->path()),
        ]);
    }

    /**
     * One page serves every event.
     *
     * The site has a page of kind `event` holding whatever the organiser wants around the picker;
     * this fills in which event it is. Without that, adding a date would mean building a page.
     */
    public function event(Request $request, string $publicId)
    {
        $site = $request->attributes->get('site');

        $event = Event::with(['venue', 'priceZones'])->where('public_id', $publicId)->first();

        if (! $event || 'draft' === $event->status) {
            throw new NotFoundHttpException('No such event.');
        }

        $page = SitePage::where('site_id', $site->id)->where('kind', 'event')->first();

        $blocks = $page && $page->isPublished()
            ? $page->liveBlocks()
            : [['id' => 'auto', 'type' => 'eventDetail', 'event_public_id' => '']];

        return $this->render($site, $page, $blocks, [
            'title' => $event->name.' · '.$site->name,
            'description' => Str::limit((string) $event->description, 300),
            'canonical' => $site->url('/events/'.$event->public_id),
            'event' => $event,
        ]);
    }

    private function render(Site $site, ?SitePage $page, array $blocks, array $meta)
    {
        $brand = Themes::forSite($site);

        return response()->view('site.page', [
            'site' => $site,
            'page' => $page,
            'blocks' => $blocks,
            'brand' => $brand,
            'title' => $meta['title'],
            'description' => $meta['description'] ?? null,
            'canonical' => $meta['canonical'] ?? null,
            'headerMenu' => $site->menuFor('header'),
            'footerMenu' => $site->menuFor('footer'),

            // Passed as closures so a page with no event block never runs an availability query.
            'eventsFor' => fn (array $block) => $this->upcoming($site, (int) $block['limit']),
            'eventFor' => fn (array $block) => $this->detail(
                $site,
                $block['event_public_id'] ?: null,
                $meta['event'] ?? null
            ),
        ]);
    }

    private function upcoming(Site $site, int $limit): array
    {
        // `priceZones` too: the card prints a "from" price, so leaving it lazy is one query per
        // event — and, with lazy loading disabled, a 500 on the first site that has two of them.
        $events = Event::with(['venue', 'priceZones'])
            ->where('status', 'published')
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        $locale = app()->getLocale();

        return $events->map(function (Event $event) use ($site, $locale) {
            $starts = $event->starts_at?->setTimezone($event->timezone ?: $site->timezone);
            $summary = $this->availability->summaryForEvent($event);
            $cheapest = $event->priceZones->min('amount');

            return [
                'name' => $event->name,
                'url' => '/events/'.$event->public_id,
                'starts_at_iso' => $event->starts_at?->toIso8601String(),
                'image' => Themes::url($event->image_url),
                'category' => $event->category,
            ] + $this->cover($event->name) + [
                // Dates, not format(): `D j M` prints "Tue 29 Sep" in every language, which is the
                // exact bug this exercise exists to remove — and the calendar follows the reader
                // too, so an Iranian visitor is told ۷ مهر rather than a date they must convert.
                'day' => Dates::day($starts, $locale),
                'month' => Dates::month($starts, $locale),
                'time' => Dates::shortWhen($starts, $locale),
                'venue' => $event->venue?->name,
                'from_price' => null === $cheapest ? null : $this->money($cheapest, $event->currency),
                'sold_out' => 0 === (int) ($summary['available'] ?? 0),
            ];
        })->all();
    }

    private function detail(Site $site, ?string $publicId, ?Event $fallback): ?array
    {
        $event = $publicId
            ? Event::with(['venue', 'priceZones'])->where('public_id', $publicId)->first()
            : $fallback;

        if (! $event) {
            return null;
        }

        $starts = $event->starts_at?->setTimezone($event->timezone ?: $site->timezone);
        $onSale = 'published' === $event->status && null !== $event->seat_map_version_id;

        // One id, used by both the container and the boot payload: the picker finds its element
        // by that id, so two random values would leave the page with a div and nothing in it.
        $containerId = 'seatmap-'.Str::lower(Str::random(8));

        return [
            'public_id' => $event->public_id,
            'name' => $event->name,
            'description' => $event->description,
            'image' => Themes::url($event->image_url),
            'category' => $event->category,
            'venue' => $event->venue?->name,
            'long_when' => Dates::longWhen($starts),
            'day' => Dates::day($starts, app()->getLocale()),
            'month' => Dates::month($starts, app()->getLocale()),
            'time' => Dates::shortWhen($starts, app()->getLocale()),
            'from_price' => null === ($cheapest = $event->priceZones->min('amount'))
                ? null
                : $this->money($cheapest, $event->currency),
            'on_sale' => $onSale,
            'closed_message' => match ($event->status) {
                'cancelled' => __('site.closed.cancelled'),
                'closed' => __('site.closed.closed'),
                default => __('site.closed.notYet'),
            },
            'container_id' => $containerId,
            'boot' => $onSale ? $this->boot($site, $event, $containerId) : null,
        ] + $this->cover($event->name);
    }

    /**
     * What the picker needs to start.
     *
     * `restUrl` points at this site's own store routes rather than at the seating API: the price a
     * buyer is charged has to be set by a server, and a browser that could call the pricing API
     * directly is a browser that could argue with it (ADR-0001, and the same reasoning holds here).
     */
    private function boot(Site $site, Event $event, string $containerId): array
    {
        return [
            'containerId' => $containerId,
            'eventPublicId' => $event->public_id,
            'restUrl' => '/_store',
            'nonce' => csrf_token(),
            'nonceHeader' => 'X-CSRF-TOKEN',
            // The event decides the currency; the reader decides how it is written. `symbol` and
            // `position` stay in the payload for the WordPress path, which formats money itself
            // from what its shop knows — one picker, two hosts, and neither told the other's story.
            'currency' => [
                'code' => $currency = ($event->currency ?: $site->currency),
                'symbol' => $currency,
                'decimals' => Money::exponent($currency),
                'position' => 'left_space',
            ],
            'locale' => $locale = app()->getLocale(),
            'isRtl' => Locales::isRtl($locale),
            'event' => [
                'zones' => $event->priceZones->map(fn ($zone) => [
                    'key' => $zone->key,
                    'name' => $zone->name,
                    'amount' => $zone->amount,
                    'color' => $zone->color,
                ])->values()->all(),
                'max_seats_per_order' => $event->max_seats_per_order,
            ],
            // Enriched with the platform-wide seat ids, not the raw chart: without them the
            // picker has nothing to place a hold against and every seat is inert.
            'geometry' => $event->seatMapVersion
                ? $this->geometry->forVersion($event->seatMapVersion)
                : ['floors' => []],
            'i18n' => trans('site.picker'),
        ];
    }

    /**
     * A cover for an event that has no artwork.
     *
     * Not a grey box and not a broken image: a hue taken from the name, and the name's own
     * initials set large. Every organiser starts with nothing uploaded, and the first thing they
     * see of their own website should not look unfinished.
     *
     * The hue is a hash, so a given event is always the same colour — a listing that reshuffles
     * its palette on every page load reads as broken rather than as lively.
     */
    private function cover(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = mb_strtoupper(implode('', array_map(
            fn (string $word) => mb_substr($word, 0, 1),
            array_slice($words, 0, 2)
        )));

        return [
            'hue' => crc32($name) % 360,
            'initials' => '' === $initials ? '·' : $initials,
        ];
    }

    /** The event's currency, written the reader's way (ADR-0005 §5). */
    private function money(int $minor, ?string $currency): string
    {
        return Money::format($minor, $currency ?: 'EUR');
    }
}
