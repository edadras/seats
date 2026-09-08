<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Availability\AvailabilityService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeatOverride;
use App\Models\Seat;
use App\Models\SeatMap;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $events = Event::with('venue')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('starts_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($events, fn (Event $event) => $this->present($event));
    }

    public function store(Request $request)
    {
        $this->authorizeWrite($request);

        $data = $this->validateEvent($request, creating: true);

        $map = SeatMap::findOrFail($data['seat_map_id']);

        $event = Event::create($data + [
            'venue_id' => $map->venue_id,
            'public_id' => 'evt_'.Str::lower(Str::random(20)),
            'status' => $data['status'] ?? 'draft',
            // An event sells against the map version published *now*, and keeps selling against it
            // even if the map is republished later.
            'seat_map_version_id' => $map->published_version_id,
        ]);

        $this->audit->record('event.created', $event, ['name' => $event->name]);

        return response()->json($this->present($event), 201);
    }

    public function show(Event $event)
    {
        return response()->json($this->present($event));
    }

    public function update(Request $request, Event $event)
    {
        $this->authorizeWrite($request);

        $data = $this->validateEvent($request, creating: false);

        if (isset($data['status']) && $data['status'] === 'published' && ! $event->seat_map_version_id) {
            throw ApiException::conflict(
                'map_not_published',
                'Publish the seat map before putting this event on sale.'
            );
        }

        $event->update($data);

        $this->audit->record('event.updated', $event, ['changes' => array_keys($data)]);

        return response()->json($this->present($event->fresh()));
    }

    /**
     * Replace pricing wholesale.
     *
     * A PUT, not a PATCH: partial price edits across zones and overrides are where "half the map
     * is priced from last season" bugs come from. Sending the complete intended state each time
     * makes the result unambiguous.
     */
    public function pricing(Request $request, Event $event)
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'zones' => ['required', 'array', 'min:1'],
            'zones.*.key' => ['required', 'string', 'max:60'],
            'zones.*.name' => ['required', 'string', 'max:120'],
            'zones.*.amount' => ['required', 'integer', 'min:0'],
            'zones.*.color' => ['nullable', 'string', 'max:16'],
            'overrides' => ['sometimes', 'array'],
            'overrides.*.seat_id' => ['required', 'uuid'],
            'overrides.*.blocked' => ['sometimes', 'boolean'],
            'overrides.*.amount' => ['nullable', 'integer', 'min:0'],
            'overrides.*.zone_key' => ['nullable', 'string', 'max:60'],
            'overrides.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($event, $data) {
            $event->update(['currency' => $data['currency']]);

            EventPriceZone::where('event_id', $event->id)->delete();

            foreach (array_values($data['zones']) as $order => $zone) {
                EventPriceZone::create([
                    'event_id' => $event->id,
                    'key' => $zone['key'],
                    'name' => $zone['name'],
                    'amount' => $zone['amount'],
                    'color' => $zone['color'] ?? null,
                    'sort_order' => $order,
                ]);
            }

            $overrides = $data['overrides'] ?? [];

            if ($overrides !== []) {
                // Reject seats from another map before writing anything, so a typo cannot half-apply.
                $seatIds = array_column($overrides, 'seat_id');
                $valid = Seat::whereIn('id', $seatIds)
                    ->where('seat_map_id', $event->seat_map_id)
                    ->pluck('id')->all();

                $unknown = array_values(array_diff($seatIds, $valid));

                if ($unknown !== []) {
                    throw ApiException::unprocessable(
                        'unknown_seats',
                        'Some overrides refer to seats that are not in this event\'s seat map.',
                        ['unknown_seat_ids' => $unknown],
                    );
                }
            }

            EventSeatOverride::where('event_id', $event->id)->delete();

            foreach ($overrides as $override) {
                EventSeatOverride::create([
                    'event_id' => $event->id,
                    'seat_id' => $override['seat_id'],
                    'blocked' => (bool) ($override['blocked'] ?? false),
                    'amount' => $override['amount'] ?? null,
                    'zone_key' => $override['zone_key'] ?? null,
                    'note' => $override['note'] ?? null,
                ]);
            }

            $event->bumpAvailabilityVersion();
        });

        $this->audit->record('event.pricing_replaced', $event, [
            'zones' => count($data['zones']),
            'overrides' => count($data['overrides'] ?? []),
        ]);

        return response()->json($this->present($event->fresh()));
    }

    public function stats(Event $event)
    {
        return response()->json($this->buildStats($event));
    }

    public function checkins(Request $request, Event $event)
    {
        $checkins = Checkin::with(['device', 'operator'])
            ->where('event_id', $event->id)
            ->orderByDesc('scanned_at')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return $this->paginated($checkins, fn (Checkin $c) => [
            'id' => $c->id,
            'ticket_id' => $c->ticket_id,
            'result' => $c->result,
            'scanned_at' => $c->scanned_at?->toIso8601String(),
            'device' => $c->device?->name,
            'operator' => $c->operator?->name,
        ]);
    }

    public function buildStats(Event $event): array
    {
        $summary = $this->availability->summaryForEvent($event);

        $gross = (int) $event->allocations()->where('status', 'active')->sum('amount');
        $checkedIn = $event->tickets()->where('status', 'used')->count();
        $issued = $event->tickets()->whereIn('status', ['issued', 'used'])->count();

        return $summary + [
            // Indicative only: coupons and tax live in the shop, not here (ADR-0001).
            'gross_amount' => $gross,
            'currency' => $event->currency,
            'tickets_issued' => $issued,
            'checked_in' => $checkedIn,
            'checkin_rate' => $issued > 0 ? round($checkedIn / $issued, 4) : 0.0,
        ];
    }

    private function validateEvent(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:200'],
            'seat_map_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => [$required, 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'in:draft,published,closed,cancelled'],
            'hold_ttl_seconds' => ['sometimes', 'integer', 'min:60', 'max:3600'],
            'max_extends' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'max_seats_per_order' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'refund_policy' => ['sometimes', 'in:release,hold_back'],
        ]);
    }

    private function present(Event $event): array
    {
        return [
            'id' => $event->id,
            'public_id' => $event->public_id,
            'name' => $event->name,
            'description' => $event->description,
            'status' => $event->status,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            'venue_id' => $event->venue_id,
            'seat_map_id' => $event->seat_map_id,
            'seat_map_version_id' => $event->seat_map_version_id,
            'hold_ttl_seconds' => $event->hold_ttl_seconds,
            'max_extends' => $event->max_extends,
            'max_seats_per_order' => $event->max_seats_per_order,
            'refund_policy' => $event->refund_policy,
            'availability_version' => $event->availability_version,
        ];
    }
}
