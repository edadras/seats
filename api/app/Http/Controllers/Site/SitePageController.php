<?php

namespace App\Http\Controllers\Site;

use App\Domain\Access\AccessCodes;
use App\Domain\Access\SaleWindow;
use App\Domain\Availability\AvailabilityService;
use App\Domain\SeatMaps\PublishedGeometry;
use App\Domain\Sites\Themes;
use App\Domain\Waitlist\WaitingList;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Site;
use App\Models\SitePage;
use App\Support\Calendar\IcsFile;
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

        /*
         * Somebody looked.
         *
         * Counted here rather than in the picker, because the question this answers is "did people
         * hear about it" and a page that never got as far as a seat map is exactly the case that
         * matters. A count per day, not per visitor: nothing here knows who they were.
         */
        app(\App\Domain\Insights\SalesPace::class)->record($event, 'site');

        $page = SitePage::where('site_id', $site->id)->where('kind', 'event')->first();

        $blocks = $page && $page->isPublished()
            ? $page->liveBlocks()
            : [['id' => 'auto', 'type' => 'eventDetail', 'event_public_id' => '']];

        return $this->render($site, $page, $blocks, [
            'title' => $event->nameFor().' · '.$site->name,
            'description' => Str::limit((string) $event->descriptionFor(), 300),
            'canonical' => $site->url('/events/'.$event->public_id),
            // What a link to this page looks like when it is pasted into a message. Without it,
            // an event with a poster shares as a grey rectangle.
            'image' => Themes::url($event->image_url),
            // What a search engine is told, in the vocabulary it reads. A listing that shows the
            // date and the price is the difference between being found and being scrolled past.
            'jsonld' => $this->structuredData($site, $event),
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
            'image' => $meta['image'] ?? Themes::forSite($site)['logo_url'] ?? null,
            'jsonld' => $meta['jsonld'] ?? null,
            'headerMenu' => $site->menuFor('header'),
            'footerMenu' => $site->menuFor('footer'),

            // Passed as closures so a page with no event block never runs an availability query.
            'eventsFor' => fn (array $block) => $this->upcoming($site, (int) $block['limit']),
            // The words a visitor typed, and the categories there are to choose from. Read once
            // per page rather than per block: a page with two listings asks the same question.
            'listFilters' => fn () => $this->filters($site),
            'eventFor' => fn (array $block) => $this->detail(
                $site,
                $block['event_public_id'] ?: null,
                $meta['event'] ?? null
            ),
        ]);
    }

    /**
     * This event, described in schema.org's vocabulary.
     *
     * Only what is true. `offers` is present when the event is on sale and priced, because a price
     * in a search result that turns out not to exist is worse for the organiser than no price; and
     * `eventStatus` says cancelled when it is cancelled, which is the one thing a search engine
     * showing a stale listing most needs to be told.
     */
    private function structuredData(Site $site, Event $event): array
    {
        $cheapest = $event->priceZones->min('amount');
        // The general sale, not this visitor's: a search engine holds no presale code, and
        // advertising an offer that only a mailing list can take is advertising it to the wrong
        // people.
        $onSale = SaleWindow::isOpenToAll($event);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->nameFor(),
            'startDate' => $event->starts_at?->toIso8601String(),
            'eventStatus' => match ($event->status) {
                'cancelled' => 'https://schema.org/EventCancelled',
                default => 'https://schema.org/EventScheduled',
            },
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url' => $site->url('/events/'.$event->public_id),
            'organizer' => ['@type' => 'Organization', 'name' => $site->name, 'url' => $site->url('/')],
        ];

        if ($event->ends_at) {
            $data['endDate'] = $event->ends_at->toIso8601String();
        }

        if ($event->descriptionFor()) {
            $data['description'] = Str::limit((string) $event->descriptionFor(), 500);
        }

        if ($image = Themes::url($event->image_url)) {
            $data['image'] = [$image];
        }

        if ($event->venue) {
            $data['location'] = array_filter([
                '@type' => 'Place',
                'name' => $event->venue->name,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $event->venue->address,
                    'addressLocality' => $event->venue->city,
                    'addressCountry' => $event->venue->country,
                ]),
            ]);
        }

        if ($onSale && null !== $cheapest) {
            $data['offers'] = [
                '@type' => 'Offer',
                'price' => number_format(
                    Money::toDecimal((int) $cheapest, (string) $event->currency),
                    Money::exponent((string) $event->currency),
                    '.',
                    ''
                ),
                'priceCurrency' => $event->currency,
                'availability' => 'https://schema.org/InStock',
                'url' => $site->url('/events/'.$event->public_id),
            ];
        }

        return $data;
    }

    /**
     * One event, as a file a calendar will take.
     *
     * Offered because a ticket bought in September is for a night in November, and the single most
     * useful thing a buyer can do with an event page is put it where they will see it again.
     */
    public function calendar(Request $request, string $publicId)
    {
        $site = $request->attributes->get('site');

        $event = Event::with('venue')
            ->where('public_id', $publicId)
            ->whereIn('status', ['published', 'closed'])
            ->first();

        if (! $event || ! $event->starts_at) {
            throw new NotFoundHttpException('No such event.');
        }

        $body = IcsFile::event(
            uid: $event->public_id.'@'.($site->canonicalHost() ?: 'seatmap'),
            summary: $event->nameFor(),
            starts: $event->starts_at,
            ends: $event->ends_at,
            location: trim(implode(', ', array_filter([$event->venue?->name, $event->venue?->city]))) ?: null,
            description: $event->descriptionFor(),
            url: $site->url('/events/'.$event->public_id),
        );

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($event->nameFor()).'.ics"',
        ]);
    }

    /**
     * What a visitor is filtering by, and what there is to filter by.
     *
     * The categories come from the events that are actually on: offering "Comedy" on a season with
     * no comedy in it is a filter that returns nothing, which reads as a broken page rather than
     * as an empty category.
     */
    private function filters(Site $site): array
    {
        $request = request();
        $query = trim((string) $request->query('q', ''));

        $categories = Event::query()
            ->where('status', 'published')
            ->whereNotNull('seat_map_version_id')
            ->whereNotNull('category')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        return [
            'q' => mb_substr($query, 0, 80),
            'category' => in_array($request->query('category'), $categories, true)
                ? (string) $request->query('category')
                : '',
            'categories' => $categories,
            'active' => '' !== $query || in_array($request->query('category'), $categories, true),
        ];
    }

    private function upcoming(Site $site, int $limit): array
    {
        // `priceZones` too: the card prints a "from" price, so leaving it lazy is one query per
        // event — and, with lazy loading disabled, a 500 on the first site that has two of them.
        $filters = $this->filters($site);

        $events = Event::with(['venue', 'priceZones'])
            ->where('status', 'published')
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->when($filters['category'], fn ($q, $category) => $q->where('category', $category))
            ->when($filters['q'], function ($q, $term) {
                // The name, or the room it is in — the two things somebody remembers about a night
                // they meant to book. `ilike` because a search that is case-sensitive is a search
                // that finds nothing.
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';

                $q->where(function ($where) use ($like) {
                    $where->whereRaw('lower(events.name) like ?', [$like])
                        ->orWhereExists(fn ($exists) => $exists->from('venues')
                            ->whereColumn('venues.id', 'events.venue_id')
                            ->whereRaw('lower(venues.name) like ?', [$like]));
                });
            })
            ->orderBy('starts_at')
            // Fetched wider than asked for, because a run of twenty nights collapses to one card
            // and the page would otherwise show one production where it meant to show ten.
            ->limit($limit * 4)
            ->get();

        /*
         * A three-week run is one thing to decide about, not twenty-one.
         *
         * The programme shows the next night of each production and says how many more there are;
         * the event's own page lists the rest. Without this a visitor looking for "which night can
         * I come" has to read twenty-one identical cards to find out.
         */
        $runs = $events->whereNotNull('series_id')->groupBy('series_id')->map->count();

        $events = $events
            ->unique(fn (Event $event) => $event->series_id ? 'series:'.$event->series_id : $event->id)
            ->take($limit)
            ->values();

        $locale = app()->getLocale();

        return $events->map(function (Event $event) use ($site, $locale, $runs) {
            $starts = $event->starts_at?->setTimezone($event->timezone ?: $site->timezone);
            $summary = $this->availability->summaryForEvent($event);
            $cheapest = $event->priceZones->min('amount');

            return [
                'name' => $event->nameFor(),
                'url' => '/events/'.$event->public_id,
                'starts_at_iso' => $event->starts_at?->toIso8601String(),
                'image' => Themes::url($event->image_url),
                'category' => $event->categoryFor(),
            ] + $this->cover($event->nameFor()) + [
                // Dates, not format(): `D j M` prints "Tue 29 Sep" in every language, which is the
                // exact bug this exercise exists to remove — and the calendar follows the reader
                // too, so an Iranian visitor is told ۷ مهر rather than a date they must convert.
                'day' => Dates::day($starts, $locale),
                'month' => Dates::month($starts, $locale),
                'time' => Dates::shortWhen($starts, $locale),
                'venue' => $event->venue?->name,
                'from_price' => null === $cheapest ? null : $this->money($cheapest, $event->currency),
                'sold_out' => 0 === (int) ($summary['available'] ?? 0),
                // "and four more nights", said once on the card rather than as four more cards.
                'more_dates' => max(0, (int) ($runs[$event->series_id] ?? 1) - 1),
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
        /*
         * Whether the picker is drawn at all, and if not, why not.
         *
         * A presale is a third answer to a question that used to have two: the event is on sale,
         * but not to this visitor unless they hold a code. So the page renders the box instead of
         * the seats, and unlocking the box is what turns the seats on — server-side, on a reload,
         * because whether somebody may buy is never a decision a script on their own page makes.
         */
        $sale = SaleWindow::state($event);
        $held = request()->session()->get('seatmap_access_code');
        $unlocked = SaleWindow::OPEN === $sale || (
            SaleWindow::CLOSED !== $sale
            && $held
            && app(AccessCodes::class)->offer($held, $event)->ok
        );
        $onSale = SaleWindow::CLOSED !== $sale && $unlocked;

        /*
         * The door, where this night has one.
         *
         * A visitor who is not inside gets the room instead of the picker — and gets it *instead*
         * rather than on top of it, because a seat map that draws behind a queue is a seat map
         * being polled by everybody the queue exists to hold back.
         *
         * The room guards the **general** sale, in two stretches. Before it opens the page is the
         * lobby: people gather, nobody has a place, and the order is drawn when the doors open —
         * which is the whole reason arriving early is worth nothing. After it opens the room is the
         * queue proper. A presale is left alone: it is small, its code box is the page a code
         * holder came for, and putting a queue in front of a hundred people with codes would be
         * ceremony rather than protection.
         */
        $room = app(\App\Domain\Queue\WaitingRoom::class);
        $lobby = SaleWindow::WAITING === $sale && null === $event->presale_starts_at;
        $queued = $room->guards($event)
            && (SaleWindow::OPEN === $sale || $lobby)
            && ! app(\App\Http\Controllers\Site\QueueController::class)
                ->place(request(), $event);

        // One id, used by both the container and the boot payload: the picker finds its element
        // by that id, so two random values would leave the page with a div and nothing in it.
        $containerId = 'seatmap-'.Str::lower(Str::random(8));

        return [
            'public_id' => $event->public_id,
            // The reader's own language where the organiser wrote one, and the words they were
            // typed in where they did not. A missing translation is not a blank page.
            'name' => $event->nameFor(),
            'description' => $event->descriptionFor(),
            'category' => $event->categoryFor(),
            'image' => Themes::url($event->image_url),
            'venue' => $event->venue?->name,
            'long_when' => Dates::longWhen($starts),
            'day' => Dates::day($starts, app()->getLocale()),
            'month' => Dates::month($starts, app()->getLocale()),
            'time' => Dates::shortWhen($starts, app()->getLocale()),
            'from_price' => null === ($cheapest = $event->priceZones->min('amount'))
                ? null
                : $this->money($cheapest, $event->currency),
            'on_sale' => $onSale,
            /*
             * The limit, said before somebody chooses rather than after they have paid.
             *
             * A refusal at the checkout is correct and it is also a wasted evening: a buyer who
             * reads "four per person" while they are looking at the seat map chooses four.
             */
            'per_buyer' => $event->max_per_buyer,
            // Where the sale is open but this visitor is not in it, the page offers the box
            // instead of the seats. The two states are different sentences and different shapes.
            'sale_state' => $sale,
            'needs_code' => ! $unlocked && SaleWindow::takesCodes($event),
            'opens_at' => SaleWindow::opensAt($event)
                ? Dates::longWhen(SaleWindow::opensAt($event), app()->getLocale())
                : null,
            'closed_message' => match (true) {
                'cancelled' === $event->status => __('site.closed.cancelled'),
                'closed' === $event->status => __('site.closed.closed'),
                SaleWindow::PRESALE === $sale => __('site.access.presaleOnly'),
                SaleWindow::WAITING === $sale => __('site.access.notOpenYet'),
                default => __('site.closed.notYet'),
            },
            // The organiser's own sentence, where they gave one. "Cancelled" alone sends somebody
            // to a telephone; "cancelled, the singer is ill" does not.
            'closed_reason' => 'cancelled' === $event->status ? $event->cancellation_reason : null,
            // A night that used to be another night. People arrive on this page from a diary entry
            // they made months ago, and saying nothing about the change is how they turn up then.
            'moved_from' => $event->rescheduled_from
                ? Dates::longWhen($event->rescheduled_from, app()->getLocale())
                : null,
            'container_id' => $containerId,
            'calendar_url' => '/events/'.$event->public_id.'/calendar.ics',
            'boot' => ($onSale && ! $queued) ? $this->boot($site, $event, $containerId) : null,
            /*
             * What the waiting-room block needs to draw itself and to keep asking.
             *
             * Null on every ordinary night, which is almost all of them: a room in front of a sale
             * nobody is queueing for is a page between a buyer and their ticket for no reason.
             */
            'queue' => $queued ? [
                'poll' => '/queue/'.$event->public_id,
                'leave' => '/queue/'.$event->public_id.'/leave',
                'opens_at' => $event->on_sale_at?->toIso8601String(),
            ] : null,
            /*
             * The queue, offered only where there is one to join.
             *
             * A sold-out night, or one whose sale has closed — not a night that simply has not
             * opened yet, where the honest answer is "come back on Tuesday" rather than a form.
             * Seats come back all the time; until now they went back on sale silently, to whoever
             * happened to be looking.
             */
            // The other nights of the same run, so somebody who cannot come on Tuesday does not
            // have to go back to the programme and hunt for Wednesday.
            'other_dates' => $this->otherDates($site, $event),
            /*
             * Season tickets for this run, offered where the buyer already is.
             *
             * On the night's own page rather than on a separate part of the site nobody visits:
             * somebody looking at one Tuesday is exactly the person who might rather have all
             * twelve, and this is the only moment they are asked.
             */
            'season_passes' => app(\App\Domain\Seasons\Seasons::class)
                ->passesFor($event->series_id)
                ->map(fn (\App\Models\SeasonPass $pass) => [
                    'id' => $pass->id,
                    'name' => $pass->name,
                    'description' => $pass->description,
                    'saving' => 'percent' === $pass->discount_kind
                        ? __('site.season.savePercent', [
                            'percent' => \App\Support\Locale\Money::number($pass->discount_value),
                        ])
                        : __('site.season.saveAmount', [
                            'amount' => \App\Support\Locale\Money::format(
                                $pass->discount_value, $pass->currency, app()->getLocale()
                            ),
                        ]),
                ])->all(),
            'waiting_list' => ('cancelled' !== $event->status)
                && ('closed' === $event->status || ($onSale && 0 === app(WaitingList::class)->freePlaces($event))),
        ] + $this->cover($event->nameFor());
    }

    /**
     * The rest of the run, from this night's point of view.
     *
     * Only nights that are actually on sale and still ahead: a list that offered last Tuesday, or
     * a draft nobody has published, would be a page telling a visitor to buy something they cannot.
     *
     * @return list<array<string, mixed>>
     */
    private function otherDates(Site $site, Event $event): array
    {
        if (! $event->series_id) {
            return [];
        }

        $locale = app()->getLocale();

        return Event::where('series_id', $event->series_id)
            ->whereKeyNot($event->id)
            ->where('status', 'published')
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('starts_at')
            ->limit(40)
            ->get()
            ->map(function (Event $other) use ($site, $locale) {
                $starts = $other->starts_at?->setTimezone($other->timezone ?: $site->timezone);
                $summary = $this->availability->summaryForEvent($other);

                return [
                    'url' => '/events/'.$other->public_id,
                    'when' => Dates::longWhen($starts, $locale),
                    'day' => Dates::day($starts, $locale),
                    'month' => Dates::month($starts, $locale),
                    'time' => Dates::shortWhen($starts, $locale),
                    'sold_out' => 0 === (int) ($summary['available'] ?? 0),
                ];
            })
            ->all();
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
                // Empty on an event that sells one kind of ticket, and the picker then shows no
                // chooser at all rather than a chooser with one option in it.
                'ticket_types' => \App\Domain\Events\TicketTypes::forEvent($event),
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
