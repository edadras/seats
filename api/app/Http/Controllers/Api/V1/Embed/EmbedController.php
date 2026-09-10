<?php

namespace App\Http\Controllers\Api\V1\Embed;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\HoldService;
use App\Domain\SeatMaps\PublishedGeometry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\HoldResource;
use App\Models\Event;
use App\Models\Hold;
use App\Models\Site;
use App\Support\Locale\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The public surface the browser widget talks to.
 *
 * Unauthenticated by necessity, so it exposes only what a venue already shows publicly: layout,
 * prices and which seats are taken. No buyer identity, no ticket tokens, no tenant internals
 * (threat T9). The tenant is derived from the event's own record, never from the caller.
 */
class EmbedController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly HoldService $holds,
        private readonly TenantContext $tenantContext,
        private readonly PublishedGeometry $geometry,
    ) {}

    public function show(string $publicId)
    {
        $event = $this->resolveEvent($publicId);

        /*
         * Somebody loaded a picker on somebody else's page.
         *
         * This call, and not the availability poll beside it: the picker asks for the event once
         * per page and asks for availability every few seconds, so counting the poll would report
         * a visitor who left the tab open as an audience of four hundred.
         */
        app(\App\Domain\Insights\SalesPace::class)->record($event, 'embed');

        return response()->json([
            'public_id' => $event->public_id,
            // A picker pasted onto somebody's own page is read in whatever language that page
            // asked for, so the event's own words follow the same rule as the site's.
            'name' => $event->nameFor(),
            'description' => $event->descriptionFor(),
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            // How many decimal places that currency has. Sent because the picker formats money in
            // the browser, and a table of currency exponents copied into JavaScript is a table
            // that goes out of date somewhere nobody is looking.
            'currency_decimals' => Money::exponent((string) $event->currency),
            'status' => $event->status,
            'venue' => [
                'name' => $event->venue?->name,
                'city' => $event->venue?->city,
            ],
            'seat_map_version_id' => $event->seat_map_version_id,
            'hold_ttl_seconds' => $event->hold_ttl_seconds,
            'max_seats_per_order' => $event->max_seats_per_order,
            'areas' => $this->availability->capacityForEvent($event),
            'zones' => $event->priceZones->map(fn ($zone) => [
                'key' => $zone->key,
                'name' => $zone->name,
                'amount' => $zone->amount,
                'color' => $zone->color,
            ])->values(),
            'ticket_types' => \App\Domain\Events\TicketTypes::forEvent($event),
        ]);
    }

    /**
     * Geometry is immutable per version, so it is safe to cache hard and to serve from a CDN.
     * The widget fetches this once and then only polls availability.
     *
     * Each seat is enriched with its stable `seat_id`. Without it the client would have to pair
     * geometry with the availability list positionally, and nothing guarantees the two share an
     * order — one reshuffle and every buyer would be selecting the wrong chair.
     */
    public function seatMap(string $publicId)
    {
        $event = $this->resolveEvent($publicId);

        if (! $event->seat_map_version_id) {
            throw ApiException::conflict('map_not_published', 'This event has no published seat map.');
        }

        $version = $event->seatMapVersion;

        return response()
            ->json([
                'seat_map_version_id' => $version->id,
                'geometry' => $this->geometry->forVersion($version),
            ])
            ->setEtag($version->checksum ?? md5($version->id))
            ->header('Cache-Control', 'public, max-age=3600, immutable');
    }

    /**
     * Inject each object's stable id into the geometry.
     *
     * Seats get `seat_id`, capacity objects get `capacity_object_id`, both keyed on the chart key.
     * Without them a client would have to pair geometry with availability by array position, and
     * nothing guarantees the two share an order — one reshuffle and every buyer selects the wrong
     * chair.
     */
    public function availability(Request $request, string $publicId)
    {
        $event = $this->resolveEvent($publicId);

        $since = $request->query('since');
        $current = (string) $event->availability_version;

        // Nothing has changed since the caller's cursor: answer cheaply rather than recomputing
        // the whole map for a poll that happens every few seconds per open browser tab.
        if ($since !== null && $since === $current) {
            return response()->json(['cursor' => $current, 'full' => false, 'seats' => []]);
        }

        return response()->json([
            'cursor' => $current,
            'full' => true,
            'seats' => $this->availability->forEvent($event),
            // Standing areas and whole tables report places remaining rather than a state, because
            // "held" is not a useful answer about a pit that is half full.
            'areas' => $this->availability->capacityForEvent($event),
            // Empty on an event that is not timed entry, which is how the picker knows not to ask.
            'entry_slots' => app(\App\Domain\Events\EntrySlots::class)->forEvent($event, openOnly: true),
        ]);
    }

    public function hold(Request $request, string $publicId)
    {
        $event = $this->resolveEvent($publicId);

        $data = $request->validate([
            'seat_ids' => ['sometimes', 'array', 'max:'.config('seatmap.hold.max_seats')],
            'seat_ids.*' => ['uuid'],
            // Standing room is asked for by quantity: { "<capacity object id>": 3 }.
            'areas' => ['sometimes', 'array', 'max:20'],
            'areas.*' => ['integer', 'min:1', 'max:'.config('seatmap.hold.max_seats')],
            // Who each ticket is for. Seats not named here are sold at the event's default type,
            // so a caller that has never heard of concessions keeps selling full-price tickets.
            'seat_types' => ['sometimes', 'array', 'max:'.config('seatmap.hold.max_seats')],
            'seat_types.*' => ['uuid'],
            // The same for standing room, where a type is a share of an area's quantity:
            // { "<capacity object id>": { "<ticket type id>": 2 } }.
            'area_types' => ['sometimes', 'array', 'max:20'],
            'area_types.*' => ['array', 'max:20'],
            'area_types.*.*' => ['integer', 'min:1', 'max:'.config('seatmap.hold.max_seats')],
            'session_id' => ['required', 'string', 'max:100'],
            // Which arrival window, on an event that sells timed entry.
            'entry_slot_id' => ['sometimes', 'nullable', 'uuid'],
            // "Four together, please", instead of naming the chairs. The server chooses and holds
            // in one movement, because a suggestion a buyer has to confirm is a suggestion somebody
            // else can take in between.
            'best_available' => ['sometimes', 'array'],
            'best_available.quantity' => ['required_with:best_available', 'integer', 'min:1',
                'max:'.config('seatmap.hold.max_seats')],
            'best_available.max_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'best_available.zone_key' => ['sometimes', 'nullable', 'string', 'max:60'],
            'best_available.section_key' => ['sometimes', 'nullable', 'string', 'max:60'],
            'best_available.prefer' => ['sometimes', 'nullable', 'in:best,cheapest'],
            'best_available.ticket_type_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $hold = ($data['best_available'] ?? null)
            ? $this->holds->createBestAvailable(
                $event,
                (int) $data['best_available']['quantity'],
                $data['session_id'],
                null,
                $request->ip(),
                $data['best_available'],
                ['all' => $data['best_available']['ticket_type_id'] ?? null],
                $data['entry_slot_id'] ?? null,
            )
            : $this->holds->create(
                $event,
                $data['seat_ids'] ?? [],
                $data['session_id'],
                null,
                $request->ip(),
                $data['areas'] ?? [],
                $data['seat_types'] ?? [],
                $data['area_types'] ?? [],
                $data['entry_slot_id'] ?? null,
            );

        /*
         * Where to send the buyer to pay.
         *
         * A shop that made this hold has its own cart and ignores this. A website with no server
         * — somebody's own page with the widget pasted into it — has nowhere to take a payment,
         * and this is the answer: the organiser's own hosted checkout, which already knows how to
         * price a hold, take the money and issue the tickets. Decided here rather than in the
         * browser because which site an event belongs to is a fact this server holds.
         */
        return response()->json(
            (new HoldResource($hold->load('event')))->toArray($request)
                + ['cart_url' => $this->checkoutUrl($hold)],
            201
        );
    }

    /** The hosted checkout for this event's organiser, if they have a site on the internet. */
    private function checkoutUrl(Hold $hold): ?string
    {
        $site = Site::query()
            ->where('status', 'live')
            ->whereHas('domains', fn ($query) => $query->whereNotNull('verified_at'))
            ->with('primaryDomain')
            ->orderByDesc('created_at')
            ->first();

        return $site?->canonicalHost()
            ? $site->url('/checkout/resume?hold='.urlencode($hold->token))
            : null;
    }

    public function extendHold(string $token)
    {
        $hold = $this->resolveHold($token);

        return response()->json(new HoldResource($this->holds->extend($hold)->load('event')));
    }

    public function releaseHold(string $token)
    {
        $this->holds->release($this->resolveHold($token));

        return response()->noContent();
    }

    /**
     * Called by the storefront immediately before it creates an order. Answering "is this hold
     * still good?" here means a buyer is told the seats went while they were paying, instead of
     * discovering it after payment.
     */
    public function validateHold(string $token)
    {
        $hold = $this->resolveHold($token);
        $state = $hold->currentState();

        return response()->json([
            'valid' => $state === 'active',
            'reason' => $state === 'active' ? null : $state,
            'hold' => new HoldResource($hold->load('event')),
        ]);
    }

    /**
     * Public endpoints have no authenticated tenant, so the lookup is deliberately unscoped and
     * then binds the tenant it finds. Everything downstream is scoped normally from that point.
     */
    private function resolveEvent(string $publicId): Event
    {
        $event = $this->tenantContext->runUnscoped(
            fn () => Event::with(['venue', 'priceZones', 'seatMapVersion', 'tenant'])
                ->where('public_id', $publicId)
                ->first()
        );

        if (! $event || ! $event->tenant?->isActive()) {
            throw ApiException::notFound('Unknown event.', 'unknown_event');
        }

        if (! in_array($event->status, ['published', 'closed'], true)) {
            // A draft event must not be discoverable by guessing ids.
            throw ApiException::notFound('Unknown event.', 'unknown_event');
        }

        $this->tenantContext->set($event->tenant);

        return $event;
    }

    private function resolveHold(string $token): Hold
    {
        $hold = $this->tenantContext->runUnscoped(
            fn () => Hold::with(['event.tenant'])->where('token', $token)->first()
        );

        if (! $hold) {
            throw ApiException::notFound('Unknown hold token.', 'hold_not_found');
        }

        $this->tenantContext->set($hold->event->tenant);

        return $hold;
    }
}
