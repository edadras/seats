<?php

namespace App\Http\Controllers\Site;

use App\Domain\Availability\AvailabilityService;
use App\Domain\SeatMaps\PublishedGeometry;
use App\Domain\Sites\Themes;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Site;
use App\Models\SitePage;
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
        $brand = Themes::resolveBrand($site->theme_key, $site->brand ?? []);

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
        $events = Event::with('venue')
            ->where('status', 'published')
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        return $events->map(function (Event $event) use ($site) {
            $starts = $event->starts_at?->setTimezone($event->timezone ?: $site->timezone);
            $summary = $this->availability->summaryForEvent($event);
            $cheapest = $event->priceZones->min('amount');

            return [
                'name' => $event->name,
                'url' => '/events/'.$event->public_id,
                'starts_at_iso' => $event->starts_at?->toIso8601String(),
                'day' => $starts?->format('j') ?? '',
                'month' => $starts?->format('M') ?? '',
                'time' => $starts?->format('D j M · H:i') ?? '',
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
            'venue' => $event->venue?->name,
            'long_when' => $starts?->format('l j F Y · H:i') ?? '',
            'on_sale' => $onSale,
            'closed_message' => match ($event->status) {
                'cancelled' => 'This performance has been cancelled.',
                'closed' => 'Booking for this performance has closed.',
                default => 'Tickets for this performance are not on sale yet.',
            },
            'container_id' => $containerId,
            'boot' => $onSale ? $this->boot($site, $event, $containerId) : null,
        ];
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
            'currency' => [
                'code' => $event->currency ?: $site->currency,
                'symbol' => $this->symbol($event->currency ?: $site->currency),
                'decimals' => 2,
                'position' => 'left',
            ],
            'isRtl' => in_array($site->locale, ['fa', 'ar', 'he'], true),
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

    private function money(int $minor, ?string $currency): string
    {
        return $this->symbol($currency).number_format($minor / 100, 2);
    }

    private function symbol(?string $currency): string
    {
        return match (strtoupper((string) $currency)) {
            'EUR' => '€',
            'GBP' => '£',
            'USD' => '$',
            default => (strtoupper((string) $currency) ?: '').' ',
        };
    }
}
