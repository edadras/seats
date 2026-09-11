<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Events\EventCancellation;
use App\Domain\Events\EventStats;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeatOverride;
use App\Models\Seat;
use App\Models\SeatMap;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function __construct(
        private readonly EventStats $stats,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'events.view');

        // `seatMapVersion` as well: present() reads the published chart's categories, and a list of
        // events that lazy-loaded it would be one query per row — or, with lazy loading disabled,
        // a 500 on the first account that has two events.
        /*
         * An agent's programme is the list they were given.
         *
         * Not a screen decision: the counter, the availability call and every other thing that
         * starts from "which events are there" reads this, and an agent who could see a night they
         * may not sell would spend their morning being refused at the end of it.
         */
        $agent = app(\App\Domain\Agents\SalesAgents::class)->forUser($request->user());

        /*
         * And a programme manager's programme is the nights they run.
         *
         * Null means the question does not apply — anybody who is not a manager sees the whole
         * list. An empty array means they run nothing, and they see nothing: the safe end to fail
         * at, because an empty grant list that read as "no restriction" would hand a promoter the
         * whole season.
         */
        $managed = app(\App\Domain\Programme\EventManagers::class)->eventIdsFor($request->user());

        $events = Event::with(['venue', 'priceZones', 'seatMapVersion'])
            ->when($agent && ! $agent->all_events, fn ($query) => $query->whereIn(
                'id',
                \App\Models\SalesAgentEvent::where('sales_agent_id', $agent->id)->pluck('event_id')
            ))
            ->when(null !== $managed, fn ($query) => $query->whereIn('id', $managed))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('starts_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($events, fn (Event $event) => $this->present($event));
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'events.manage');

        // What the plan says, before anything is created — a limit checked after the fact is a
        // row somebody has to delete.
        app(\App\Support\Plans\PlanLimits::class)->assertCanAddEvent();

        $data = $this->validateEvent($request, creating: true);
        $data = $this->resolveTimes($data, $data['timezone'] ?? 'UTC');

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

    public function show(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        return response()->json($this->present($event));
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $this->validateEvent($request, creating: false);
        $data = $this->resolveTimes($data, $data['timezone'] ?? $event->timezone ?: 'UTC');

        if (isset($data['status']) && $data['status'] === 'published' && ! $event->seat_map_version_id) {
            throw ApiException::conflict(
                'map_not_published',
                'Publish the seat map before putting this event on sale.'
            );
        }

        // Filled from the request and audited *before* the write, so the log records what the
        // database is about to be told rather than what the caller believed they asked for. After
        // save() there is nothing dirty left to read, and those two differ often enough to matter.
        $event->fill($data);

        $this->audit->recordChange('event.updated', $event);

        $event->save();

        return response()->json($this->present($event->fresh()));
    }

    /**
     * Move a night onto the chart's latest published version.
     *
     * An event stays on the version it was created with, deliberately: a chart republished the
     * afternoon before a show must not silently move the seats somebody has already bought. But
     * that leaves no way at all to take up a change made afterwards — a corrected row, a blocked
     * pillar seat, the heights that make the hall a room — so this is that way, as a decision
     * somebody makes rather than something that happens to them.
     *
     * Refused where the new version does not contain a seat this event has already sold: a booking
     * pointing at a chair that no longer exists is a ticket nobody can honour, and the organiser
     * needs to fix the chart rather than find out at the door.
     */
    public function useLatestChart(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $map = $event->seatMap;
        $target = $map?->published_version_id;

        if (! $target) {
            throw ApiException::conflict(
                'map_not_published',
                'Publish the seat map before putting this event on sale.'
            );
        }

        if ($target === $event->seat_map_version_id) {
            return response()->json($this->present($event));
        }

        $sold = \App\Models\Allocation::where('event_id', $event->id)
            ->where('status', 'active')
            ->whereNotNull('seat_id')
            ->pluck('seat_id')
            ->unique();

        if ($sold->isNotEmpty()) {
            $present = \Illuminate\Support\Facades\DB::table('seat_placements')
                ->where('seat_map_version_id', $target)
                ->whereIn('seat_id', $sold->all())
                ->pluck('seat_id')
                ->unique();

            $missing = $sold->diff($present);

            if ($missing->isNotEmpty()) {
                throw ApiException::conflict(
                    'chart_missing_sold_seats',
                    'The new chart is missing seats this event has already sold.',
                    ['missing' => $missing->count()],
                );
            }
        }

        $event->forceFill(['seat_map_version_id' => $target])->save();

        $this->audit->record('event.chart_updated', $event, [
            'name' => $event->name,
            'version' => $map->publishedVersion?->version,
        ]);

        $event->bumpAvailabilityVersion();

        return response()->json($this->present($event->fresh()));
    }

    /**
     * Replace pricing wholesale.
     *
     * A PUT, not a PATCH: partial price edits across zones and overrides are where "half the map
     * is priced from last season" bugs come from. Sending the complete intended state each time
     * makes the result unambiguous.
     */
    /**
     * What this event is called, in each of the six languages.
     *
     * Its own endpoint rather than a field on the event: this is a list an organiser works through
     * a language at a time, sometimes weeks after the event was created and often by somebody else
     * — and folding it into the general update would mean every save of a start time had to carry
     * every translation with it.
     *
     * Saved as a whole set, like the price zones and the ticket types. A language left blank is a
     * language deliberately not written, and it falls back to the original rather than being
     * stored as an empty string that would render as nothing.
     */
    public function translations(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        return response()->json([
            'original' => [
                'name' => $event->name,
                'description' => $event->description,
                'category' => $event->category,
            ],
            'locales' => Locales::codes(),
            'data' => (object) ($event->translations ?? []),
        ]);
    }

    public function saveTranslations(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'translations' => ['present', 'array'],
            'translations.*.name' => ['nullable', 'string', 'max:200'],
            'translations.*.description' => ['nullable', 'string', 'max:5000'],
            'translations.*.category' => ['nullable', 'string', 'max:60'],
        ]);

        $kept = [];

        foreach ($data['translations'] as $locale => $fields) {
            if (! Locales::supports($locale)) {
                // A language this platform does not speak is a typo or a client bug, and storing
                // it would put a key in the column that nothing will ever read again.
                continue;
            }

            $written = array_filter([
                'name' => trim((string) ($fields['name'] ?? '')),
                'description' => trim((string) ($fields['description'] ?? '')),
                'category' => trim((string) ($fields['category'] ?? '')),
            ], fn (string $value) => '' !== $value);

            if ([] === $written) {
                continue;
            }

            $kept[$locale] = $written;
        }

        $event->forceFill(['translations' => $kept ?: null])->save();

        $this->audit->record('event.translations_set', $event, [
            'event' => $event->name,
            'languages' => array_keys($kept),
        ]);

        return $this->translations($request, $event->refresh());
    }

    /**
     * Call a night off.
     *
     * Two permissions, not one. Cancelling an event stops it selling, which is `events.manage`,
     * and hands back everything already taken, which is `orders.refund` — and somebody who may do
     * the first is not automatically somebody who may do the second.
     *
     * `confirm` has to carry the event's own name. This is the one action on the platform that
     * cannot be undone by pressing something else: the tickets are void and the money is gone
     * back, and a mis-click on a list of twelve dates would be a disaster with no way back.
     */
    public function cancel(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');
        $this->authorize($request, 'orders.refund');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
            'confirm' => ['required', 'string'],
            // False where the money was taken somewhere this platform cannot reach and the
            // organiser will hand it back themselves. The seats come back either way.
            'refund' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        if (trim($data['confirm']) !== trim($event->name)) {
            throw ApiException::unprocessable(
                'confirm_with_the_name',
                'Type the event name exactly to confirm.',
            );
        }

        $cancelled = app(EventCancellation::class)->cancel(
            $event,
            $data['reason'],
            $request->boolean('refund', true),
            $request->boolean('notify', true),
        );

        return response()->json($this->present($cancelled));
    }

    /**
     * Move it to another night.
     *
     * `events.manage` alone: nothing is refunded and nothing is voided. Every ticket already sold
     * stays sold and stays valid, which is the whole difference from a cancellation.
     */
    public function reschedule(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:300'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        $moved = app(EventCancellation::class)->reschedule(
            $event,
            Carbon::parse($data['starts_at']),
            ($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null,
            $data['reason'] ?? '',
            $request->boolean('notify', true),
        );

        return response()->json($this->present($moved));
    }

    public function pricing(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'alpha'],
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
            // The two amounts on a booking that are not the ticket. Sent with the prices because
            // they are the same decision — what this evening costs — made on the same screen.
            'booking_fee_kind' => ['sometimes', Rule::in(['none', 'per_order', 'per_ticket'])],
            'booking_fee_amount' => ['sometimes', 'integer', 'min:0'],
            'booking_fee_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'booking_fee_label' => ['nullable', 'string', 'max:60'],
            // Basis points: 1900 is 19%, 875 is 8.75%.
            'tax_rate' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'tax_included' => ['sometimes', 'boolean'],
            'tax_label' => ['nullable', 'string', 'max:40'],
        ]);

        DB::transaction(function () use ($event, $data) {
            $event->update(['currency' => mb_strtoupper($data['currency'])] + array_intersect_key($data, array_flip([
                'booking_fee_kind', 'booking_fee_amount', 'booking_fee_percent', 'booking_fee_label',
                'tax_rate', 'tax_included', 'tax_label',
            ])));

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

            /*
             * Overrides are replaced only when the caller sent the key at all.
             *
             * The distinction matters: the panel's price screen knows nothing about blocked seats,
             * and if a request without `overrides` cleared them, correcting one price would unblock
             * the broken row somebody taped off this morning. Sending `"overrides": []` still means
             * "there are none", which is the wholesale semantics this endpoint promises.
             */
            if (! array_key_exists('overrides', $data)) {
                $event->bumpAvailabilityVersion();

                return;
            }

            $overrides = $data['overrides'];

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
            'overrides' => array_key_exists('overrides', $data) ? count($data['overrides']) : 'unchanged',
        ]);

        return response()->json($this->present($event->fresh()));
    }

    public function stats(Request $request, Event $event)
    {
        $this->authorize($request, 'reports.attendance.view');

        // Attendance to anyone who may see attendance; the takings only to someone who may see
        // those. Both on one endpoint because they are one screen, and a door volunteer looking
        // at how many are still outside should not learn the revenue on the way past.
        return response()->json($this->stats->for(
            $event,
            withMoney: app(\App\Support\Access\Gate::class)->allows($request, 'reports.orders.view'),
        ));
    }

    public function checkins(Request $request, Event $event)
    {
        $this->authorize($request, 'checkins.view');

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


    /**
     * A time somebody typed is a wall clock, not an instant.
     *
     * "Saturday, 21:00" means nine in the evening at the venue, so a datetime with no offset in it
     * is read in the event's own timezone rather than the server's. Without this an organiser in
     * Istanbul who types 21:00 sells a midnight show, and the panel and the website disagree with
     * the poster on the door.
     *
     * A value that carries its own offset or a Z is left exactly as it came: the caller has already
     * said which instant they mean, and second-guessing them would be the same bug pointed the
     * other way.
     */
    private function resolveTimes(array $data, string $timezone): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            $value = $data[$field] ?? null;

            if (! is_string($value) || '' === $value || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value)) {
                continue;
            }

            $data[$field] = CarbonImmutable::parse($value, $timezone)->utc();
        }

        return $data;
    }

    private function validateEvent(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:200'],
            'seat_map_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000'],
            // The poster. `url` alone would accept javascript: and data:, which is a
            // stored XSS on a domain we serve, so the scheme is named rather than implied.
            'image_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'category' => ['nullable', 'string', 'max:40'],
            'starts_at' => [$required, 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'in:draft,published,closed,cancelled'],
            'hold_ttl_seconds' => ['sometimes', 'integer', 'min:60', 'max:3600'],
            'max_extends' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'max_seats_per_order' => ['sometimes', 'integer', 'min:1', 'max:50'],
            // Null is no limit, which is the ordinary case: most nights do not need one, and a
            // limit invented for a night that did not is an argument with a family of six.
            'max_per_buyer' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            // How long a checkout form must have been on screen before it may be sent. A person
            // takes fifteen seconds to type their name and card; a script takes none.
            'checkout_min_seconds' => ['sometimes', 'integer', 'min:0', 'max:120'],
            // The two other things a buyer may do with a ticket they cannot use. Both off by
            // default: a venue that has never thought about either should not discover it has
            // been offering them.
            // Who may buy the wheelchair spaces, and from when. `counter` keeps them off the
            // public plan for good; `until` lets them go a stated number of hours before doors.
            'accessible_sale' => ['sometimes', 'in:always,until,counter'],
            'accessible_release_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
            // Whether the checkout asks what a buyer needs in order to get in and sit down.
            'ask_access_needs' => ['sometimes', 'boolean'],
            'exchanges' => ['sometimes', 'in:never,until,always'],
            'exchange_window_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
            'exchange_fee_amount' => ['sometimes', 'integer', 'min:0'],
            'resale' => ['sometimes', 'boolean'],
            'resale_pays' => ['sometimes', 'in:credit,refund'],
            'refund_policy' => ['sometimes', 'in:release,hold_back'],
            // What the *seat* does on a refund is `refund_policy`; these three are whether the
            // buyer may ask for one at all, and are the terms shown on their own page.
            'refunds' => ['sometimes', Rule::in(\App\Domain\Refunds\RefundPolicy::KINDS)],
            'refund_window_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
            'refund_keeps_fee' => ['sometimes', 'boolean'],
            'presale_starts_at' => ['sometimes', 'nullable', 'date'],
            'on_sale_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:presale_starts_at'],
            /*
             * The door on a big sale.
             *
             * Capacity is how many people this venue wants *choosing at once*, not how many seats
             * there are: the room exists to keep a shop usable, and the two numbers have nothing to
             * do with each other.
             */
            'waiting_room' => ['sometimes', 'boolean'],
            'waiting_room_capacity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'waiting_room_minutes' => ['sometimes', 'integer', 'min:1', 'max:120'],
        ]);
    }

    /**
     * Put the same production on again on other nights.
     *
     * Copies what describes the production — prices, ticket types, blocked seats, the fee and the
     * tax — and nothing that describes a night. The copies start as drafts, because "on sale" is a
     * decision somebody makes about a specific evening and not something that should happen by
     * being copied.
     */
    public function repeat(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'dates' => ['required', 'array', 'min:1', 'max:60'],
            'dates.*' => ['required', 'date'],
            'series_name' => ['nullable', 'string', 'max:200'],
        ]);

        app(\App\Support\Plans\PlanLimits::class)->assertCanAddEvents(count($data['dates']));

        $made = app(\App\Domain\Events\EventRepeater::class)->repeat(
            $event,
            array_map(fn (string $when) => new \DateTimeImmutable($when), $data['dates']),
            $data['series_name'] ?? null,
        );

        return response()->json([
            'data' => array_map(fn (Event $copy) => $this->present($copy), $made),
        ], 201);
    }

    private function present(Event $event): array
    {
        // A no-op on the list, which eager-loads both; the safety net is for the single-model
        // routes, where the event arrives from route binding with nothing loaded.
        // `seatMap` as well: whether this night is behind its own chart is read below, and lazy
        // loading is off — an unloaded relation would answer "no" for every event on the list.
        $event->loadMissing(['priceZones', 'seatMapVersion', 'seatMap']);

        return [
            'id' => $event->id,
            'public_id' => $event->public_id,
            'name' => $event->name,
            'description' => $event->description,
            'image_url' => $event->image_url,
            'category' => $event->category,
            'status' => $event->status,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            // What happened to this night, where something did. Null on almost every event, and
            // the screen shows nothing rather than an empty row.
            'cancelled_at' => $event->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $event->cancellation_reason,
            'rescheduled_from' => $event->rescheduled_from?->toIso8601String(),
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            'booking_fee_kind' => $event->booking_fee_kind,
            'booking_fee_amount' => (int) $event->booking_fee_amount,
            'booking_fee_percent' => (int) $event->booking_fee_percent,
            'booking_fee_label' => $event->booking_fee_label,
            'tax_rate' => (int) $event->tax_rate,
            'tax_included' => (bool) $event->tax_included,
            'tax_label' => $event->tax_label,
            'venue_id' => $event->venue_id,
            'seat_map_id' => $event->seat_map_id,
            'seat_map_version_id' => $event->seat_map_version_id,
            // Whether the chart has been republished since this night was put on it. Worked out
            // rather than stored: publishing a chart touches no event, by design.
            'chart_outdated' => (bool) ($event->seat_map_version_id
                && $event->seatMap?->published_version_id
                && $event->seatMap->published_version_id !== $event->seat_map_version_id),
            'hold_ttl_seconds' => $event->hold_ttl_seconds,
            'max_extends' => $event->max_extends,
            'max_seats_per_order' => $event->max_seats_per_order,
            'max_per_buyer' => $event->max_per_buyer,
            'checkout_min_seconds' => (int) $event->checkout_min_seconds,
            'accessible_sale' => $event->accessible_sale,
            'accessible_release_hours' => (int) $event->accessible_release_hours,
            'ask_access_needs' => (bool) $event->ask_access_needs,
            // When the wheelchair spaces go on general sale, so a screen can say so rather than
            // making somebody work it out from an hour count and a start time.
            'accessible_releases_at' => app(\App\Domain\Access\AccessibleSeats::class)
                ->releasesAt($event)?->toIso8601String(),
            'exchanges' => $event->exchanges,
            'exchange_window_hours' => (int) $event->exchange_window_hours,
            'exchange_fee_amount' => (int) $event->exchange_fee_amount,
            'resale' => (bool) $event->resale,
            'resale_pays' => $event->resale_pays,
            'refund_policy' => $event->refund_policy,
            'refunds' => $event->refunds,
            'refund_window_hours' => (int) $event->refund_window_hours,
            'refund_keeps_fee' => (bool) $event->refund_keeps_fee,
            'waiting_room' => (bool) $event->waiting_room,
            'waiting_room_capacity' => (int) $event->waiting_room_capacity,
            'waiting_room_minutes' => (int) $event->waiting_room_minutes,
            'presale_starts_at' => $event->presale_starts_at?->toIso8601String(),
            'on_sale_at' => $event->on_sale_at?->toIso8601String(),
            // What the sale is doing right now, so a screen does not have to work it out from
            // two instants and a clock it may not share.
            'sale_state' => \App\Domain\Access\SaleWindow::state($event),
            /*
             * Whether this night is being rehearsed.
             *
             * Travels with every event so that every screen showing a list can say so. A rehearsal
             * that looks like an ordinary night on one screen is how somebody announces one.
             */
            'is_rehearsal' => (bool) $event->is_rehearsal,
            'availability_version' => $event->availability_version,

            /*
             * Prices travel with the event, because they are the event: a screen that had to fetch
             * them separately would render a currency before it knew the amounts, and a price that
             * appears a moment after its symbol is a price somebody misreads.
             */
            'price_zones' => $event->priceZones
                ->map(fn ($zone) => [
                    'key' => $zone->key,
                    'name' => $zone->name,
                    'amount' => $zone->amount,
                    'color' => $zone->color,
                ])->values(),

            // What the published chart calls its categories, so the pricing screen can offer the
            // zones the map actually has rather than asking somebody to retype them.
            'categories' => array_values(array_map(
                fn (array $category) => [
                    'key' => $category['key'] ?? '',
                    'label' => $category['label'] ?? ($category['key'] ?? ''),
                    'color' => $category['color'] ?? null,
                ],
                $event->seatMapVersion?->geometry['categories'] ?? []
            )),
        ];
    }
}
