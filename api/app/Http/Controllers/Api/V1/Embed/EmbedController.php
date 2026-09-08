<?php

namespace App\Http\Controllers\Api\V1\Embed;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\HoldService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\HoldResource;
use App\Models\Event;
use App\Models\Hold;
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
    ) {}

    public function show(string $publicId)
    {
        $event = $this->resolveEvent($publicId);

        return response()->json([
            'public_id' => $event->public_id,
            'name' => $event->name,
            'description' => $event->description,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            'status' => $event->status,
            'venue' => [
                'name' => $event->venue?->name,
                'city' => $event->venue?->city,
            ],
            'seat_map_version_id' => $event->seat_map_version_id,
            'hold_ttl_seconds' => $event->hold_ttl_seconds,
            'max_seats_per_order' => $event->max_seats_per_order,
            'zones' => $event->priceZones->map(fn ($zone) => [
                'key' => $zone->key,
                'name' => $zone->name,
                'amount' => $zone->amount,
                'color' => $zone->color,
            ])->values(),
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
                'geometry' => $this->withSeatIds($version),
            ])
            ->setEtag($version->checksum ?? md5($version->id))
            ->header('Cache-Control', 'public, max-age=3600, immutable');
    }

    /** Inject each seat's UUID into the geometry, keyed by (section, row, seat). */
    private function withSeatIds(\App\Models\SeatMapVersion $version): array
    {
        $geometry = $version->geometry;

        $ids = DB::table('seat_placements as sp')
            ->join('seats as s', 's.id', '=', 'sp.seat_id')
            ->join('seat_rows as r', 'r.id', '=', 's.seat_row_id')
            ->join('sections as sec', 'sec.id', '=', 's.section_id')
            ->where('sp.seat_map_version_id', $version->id)
            ->select('sp.seat_id', 'sec.key as section_key', 'r.key as row_key', 's.key as seat_key')
            ->get()
            ->keyBy(fn ($row) => $row->section_key.'/'.$row->row_key.'/'.$row->seat_key);

        foreach ($geometry['sections'] ?? [] as $sIndex => $section) {
            foreach ($section['rows'] ?? [] as $rIndex => $row) {
                foreach ($row['seats'] ?? [] as $seatIndex => $seat) {
                    $composite = $section['key'].'/'.$row['key'].'/'.$seat['key'];

                    $geometry['sections'][$sIndex]['rows'][$rIndex]['seats'][$seatIndex]['seat_id']
                        = $ids->get($composite)?->seat_id;
                }
            }
        }

        return $geometry;
    }

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
        ]);
    }

    public function hold(Request $request, string $publicId)
    {
        $event = $this->resolveEvent($publicId);

        $data = $request->validate([
            'seat_ids' => ['required', 'array', 'min:1', 'max:'.config('seatmap.hold.max_seats')],
            'seat_ids.*' => ['uuid'],
            'session_id' => ['required', 'string', 'max:100'],
        ]);

        $hold = $this->holds->create(
            $event,
            $data['seat_ids'],
            $data['session_id'],
            null,
            $request->ip(),
        );

        return response()->json(new HoldResource($hold->load('event')), 201);
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
            throw ApiException::notFound('Unknown event.');
        }

        if (! in_array($event->status, ['published', 'closed'], true)) {
            // A draft event must not be discoverable by guessing ids.
            throw ApiException::notFound('Unknown event.');
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
            throw ApiException::notFound('Unknown hold token.');
        }

        $this->tenantContext->set($hold->event->tenant);

        return $hold;
    }
}
