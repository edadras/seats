<?php

namespace App\Domain\Events;

use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeatOverride;
use App\Models\EventSeries;
use App\Models\TicketType;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Putting the same production on again on another night.
 *
 * A three-week run is twenty-one events, and it has to be: each night has its own hall, its own
 * inventory and its own tickets. What nobody should have to do twenty-one times is type the
 * prices, redraw the concessions and re-block the two seats behind the pillar.
 *
 * So a repeat copies everything that describes the production — its prices, its ticket types, its
 * blocked seats, its fee and its tax — and nothing that describes the night: no orders, no holds,
 * no availability. The copy starts as a draft, because "on sale" is a decision somebody makes about
 * a specific night and not something that should happen by being copied.
 */
class EventRepeater
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<\DateTimeInterface>  $dates  when each new performance starts
     * @return list<Event>
     */
    public function repeat(Event $event, array $dates, ?string $seriesName = null): array
    {
        if ($dates === []) {
            return [];
        }

        return DB::transaction(function () use ($event, $dates, $seriesName) {
            $series = $this->seriesFor($event, $seriesName);
            $made = [];

            // How long the original runs for, so a copy on another night is the same length rather
            // than inheriting an end time from three weeks ago.
            $length = ($event->starts_at && $event->ends_at)
                ? $event->starts_at->diffInSeconds($event->ends_at)
                : null;

            foreach ($dates as $starts) {
                $copy = Event::create($event->only([
                    'tenant_id', 'venue_id', 'seat_map_id', 'seat_map_version_id',
                    'name', 'description', 'image_url', 'category', 'timezone', 'currency',
                    'hold_ttl_seconds', 'max_extends', 'max_seats_per_order', 'refund_policy',
                    'settings', 'booking_fee_kind', 'booking_fee_amount', 'booking_fee_percent',
                    'booking_fee_label', 'tax_rate', 'tax_included', 'tax_label',
                ]) + [
                    'series_id' => $series->id,
                    'public_id' => 'evt_'.Str::lower(Str::random(20)),
                    // Never on sale by accident. Somebody decides that about each night.
                    'status' => 'draft',
                    'starts_at' => $starts,
                    'ends_at' => $length ? (clone $starts)->modify('+'.$length.' seconds') : null,
                ]);

                $this->copyPricing($event, $copy);
                $this->copyTicketTypes($event, $copy);
                $this->copyOverrides($event, $copy);

                $made[] = $copy;
            }

            $event->forceFill(['series_id' => $series->id])->save();

            $this->audit->record('event.repeated', $event, [
                'name' => $event->name,
                'nights' => count($made),
                'series' => $series->name,
            ]);

            return $made;
        });
    }

    private function seriesFor(Event $event, ?string $name): EventSeries
    {
        if ($event->series_id) {
            // Already part of a run: another night joins it rather than starting a second one.
            return EventSeries::findOrFail($event->series_id);
        }

        $name = trim((string) ($name ?: $event->name));

        return EventSeries::create([
            'tenant_id' => $event->tenant_id,
            'name' => $name,
            'slug' => EventSeries::slugFor($name),
        ]);
    }

    private function copyPricing(Event $from, Event $to): void
    {
        foreach (EventPriceZone::where('event_id', $from->id)->orderBy('sort_order')->get() as $zone) {
            EventPriceZone::create($zone->only(['key', 'name', 'amount', 'color', 'sort_order']) + [
                'event_id' => $to->id,
            ]);
        }
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

    /**
     * Blocked seats and seat-level prices, but not the notes that were about one night.
     *
     * The two chairs behind the pillar are behind the pillar every night; "held back after refund
     * of order wc_1234" is about a booking that has nothing to do with next Tuesday.
     */
    private function copyOverrides(Event $from, Event $to): void
    {
        $rows = EventSeatOverride::where('event_id', $from->id)->get();

        foreach ($rows as $override) {
            EventSeatOverride::create([
                'tenant_id' => $override->tenant_id,
                'event_id' => $to->id,
                'seat_id' => $override->seat_id,
                'amount' => $override->amount,
                'zone_key' => $override->zone_key,
                'blocked' => $override->blocked,
                'note' => null,
            ]);
        }
    }
}
