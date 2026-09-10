<?php

namespace App\Domain\Productions;

use App\Domain\Availability\AvailabilityService;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeries;
use App\Models\SeatMap;
use App\Models\TicketType;
use App\Models\Venue;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One show, in twelve towns.
 *
 * A run in one building was already a grouping here. A tour is the harder half of the same idea:
 * the hall changes, the chart changes, the prices may change and the door certainly does, and what
 * stays the same is the thing an audience recognises — the name, the poster and the sentence.
 *
 * **A stop is a copy that knows it is going somewhere else.** Repeating a night in the same hall
 * copies the two blocked seats behind the pillar; the pillar is somewhere else in Glasgow, so a
 * tour date copies nothing about a room. It copies what describes the show: the concessions, the
 * booking fee, the tax, and the prices whose categories exist in the new chart — a price for a
 * category the new hall has never heard of is not a price, it is a row nobody can sell.
 *
 * **Every figure is counted.** How a tour is selling is the sum of what its nights have sold, read
 * at the moment somebody asks. Nothing is kept on the production, because a production that stored
 * its own total would be a second opinion about the same tickets.
 */
class Productions
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Put the same show on somewhere else.
     *
     * `$from` is the night being copied — ordinarily the most recent date of the run, because that
     * is the one whose prices somebody last thought about.
     */
    public function addStop(EventSeries $production, Event $from, array $stop): Event
    {
        $venue = Venue::findOrFail($stop['venue_id']);
        $map = SeatMap::where('id', $stop['seat_map_id'])->firstOrFail();

        if ($map->venue_id !== $venue->id) {
            throw ApiException::unprocessable(
                'map_not_at_venue',
                'That chart belongs to a different building.',
            );
        }

        if (! $map->published_version_id) {
            throw ApiException::unprocessable(
                'map_not_published',
                'That chart has never been published, so there is nothing to sell in it yet.',
            );
        }

        app(\App\Support\Plans\PlanLimits::class)->assertCanAddEvents(1);

        return DB::transaction(function () use ($production, $from, $stop, $venue, $map) {
            $starts = new \DateTimeImmutable($stop['starts_at']);
            $length = ($from->starts_at && $from->ends_at)
                ? $from->starts_at->diffInSeconds($from->ends_at)
                : null;

            $night = Event::create($from->only([
                'tenant_id', 'name', 'description', 'image_url', 'category', 'currency',
                'hold_ttl_seconds', 'max_extends', 'max_seats_per_order', 'max_per_buyer',
                'refund_policy', 'refunds', 'refund_window_hours', 'refund_keeps_fee',
                'booking_fee_kind', 'booking_fee_amount', 'booking_fee_percent', 'booking_fee_label',
                'tax_rate', 'tax_included', 'tax_label', 'settings',
            ]) + [
                'series_id' => $production->id,
                'venue_id' => $venue->id,
                'seat_map_id' => $map->id,
                'seat_map_version_id' => $map->published_version_id,
                'public_id' => 'evt_'.Str::lower(Str::random(20)),
                // Never on sale by being copied. Somebody decides that about each town.
                'status' => 'draft',
                // The hall's own clock, not the last one's: half past seven in Glasgow is half
                // past seven in Glasgow.
                'timezone' => $venue->timezone ?: $from->timezone,
                'starts_at' => $starts,
                'ends_at' => $length ? $starts->modify('+'.$length.' seconds') : null,
            ]);

            $copied = $this->copyPricing($from, $night, $map);
            $this->copyTicketTypes($from, $night);

            $from->forceFill(['series_id' => $production->id])->save();

            $this->audit->record('production.stop_added', $night, [
                'production' => $production->name,
                'venue' => $venue->name,
                'prices_copied' => $copied,
            ]);

            return $night->fresh();
        });
    }

    /**
     * How the whole run is doing, night by night and added up.
     *
     * `$withMoney` is the caller's permission rather than a preference: somebody who may see a
     * programme is not thereby somebody who may see the takings.
     *
     * @return array{dates: list<array<string, mixed>>, totals: array<string, int>, venues: int, cities: int}
     */
    public function summary(EventSeries $production, bool $withMoney = false): array
    {
        $events = Event::with(['venue', 'priceZones'])
            ->where('series_id', $production->id)
            ->orderBy('starts_at')
            ->get();

        $sold = $this->soldByEvent($events->pluck('id')->all(), $withMoney);

        $dates = $events->map(function (Event $event) use ($sold, $withMoney) {
            $summary = $this->availability->summaryForEvent($event);
            $figures = $sold[$event->id] ?? ['seats' => 0, 'revenue' => 0];

            return [
                'id' => $event->id,
                'public_id' => $event->public_id,
                'name' => $event->name,
                'status' => $event->status,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'timezone' => $event->timezone,
                'currency' => $event->currency,
                'venue' => $event->venue?->name,
                'city' => $event->venue?->city,
                'country' => $event->venue?->country,
                'capacity' => (int) $summary['seats_total'],
                'available' => (int) $summary['available'],
                'sold' => (int) $figures['seats'],
                // Null rather than nought where the caller may not see money: nought is a figure,
                // and a figure somebody is not allowed to see must not be guessable from a screen.
                'revenue' => $withMoney ? (int) $figures['revenue'] : null,
                'cancelled_at' => $event->cancelled_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'dates' => $dates,
            'venues' => $events->pluck('venue_id')->filter()->unique()->count(),
            'cities' => $events->map(fn (Event $event) => $event->venue?->city)
                ->filter()->unique()->count(),
            'totals' => [
                'dates' => count($dates),
                'capacity' => (int) array_sum(array_column($dates, 'capacity')),
                'sold' => (int) array_sum(array_column($dates, 'sold')),
                'available' => (int) array_sum(array_column($dates, 'available')),
                'revenue' => $withMoney ? (int) array_sum(array_column($dates, 'revenue')) : null,
            ],
        ];
    }

    /**
     * Every production, with enough beside it to choose one.
     *
     * @return list<array<string, mixed>>
     */
    public function all(bool $withMoney = false): array
    {
        return EventSeries::orderBy('name')->get()->map(function (EventSeries $production) use ($withMoney) {
            $summary = $this->summary($production, $withMoney);
            $next = collect($summary['dates'])
                ->filter(fn (array $date) => $date['starts_at'] && $date['starts_at'] > now()->toIso8601String())
                ->first();

            return [
                'id' => $production->id,
                'name' => $production->name,
                'slug' => $production->slug,
                'description' => $production->description,
                'image_url' => $production->image_url,
                'category' => $production->category,
                'venues' => $summary['venues'],
                'cities' => $summary['cities'],
                'next_date' => $next['starts_at'] ?? null,
                'next_city' => $next['city'] ?? null,
                'totals' => $summary['totals'],
            ];
        })->values()->all();
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * What each night has sold, counted from the allocations rather than from a column.
     *
     * @param  list<string>  $eventIds
     * @return array<string, array{seats: int, revenue: int}>
     */
    private function soldByEvent(array $eventIds, bool $withMoney): array
    {
        if ([] === $eventIds) {
            return [];
        }

        $rows = DB::table('allocations')
            ->select('event_id')
            ->selectRaw('count(*) as seats')
            ->selectRaw('coalesce(sum(amount), 0) as revenue')
            ->whereIn('event_id', $eventIds)
            ->where('status', 'active')
            ->groupBy('event_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->event_id] = [
                'seats' => (int) $row->seats,
                'revenue' => $withMoney ? (int) $row->revenue : 0,
            ];
        }

        return $out;
    }

    /**
     * The prices, for the categories the new chart actually has.
     *
     * A price for a category this hall has never heard of is not a price: it is a row nobody can
     * sell and somebody has to notice and delete. So the ones that match are copied and the rest
     * are left behind, and the panel shows the new hall's own categories waiting to be priced.
     */
    private function copyPricing(Event $from, Event $to, SeatMap $map): int
    {
        $version = \App\Models\SeatMapVersion::find($map->published_version_id);
        $keys = array_map(
            fn (array $category) => (string) ($category['key'] ?? ''),
            (array) (($version?->geometry['categories']) ?? [])
        );

        $copied = 0;

        foreach (EventPriceZone::where('event_id', $from->id)->orderBy('sort_order')->get() as $zone) {
            if (! in_array((string) $zone->key, $keys, true)) {
                continue;
            }

            EventPriceZone::create($zone->only(['key', 'name', 'amount', 'color', 'sort_order']) + [
                'event_id' => $to->id,
            ]);

            $copied++;
        }

        return $copied;
    }

    private function copyTicketTypes(Event $from, Event $to): void
    {
        foreach (TicketType::where('event_id', $from->id)->orderBy('position')->get() as $type) {
            TicketType::create($type->only([
                'tenant_id', 'name', 'description', 'kind', 'value', 'is_default',
                'min_per_order', 'max_per_order', 'proof_note', 'position', 'status',
            ]) + ['event_id' => $to->id]);
        }
    }
}
