<?php

namespace App\Domain\Reports\Sources;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;

/**
 * The programme itself: what is on, in what state, in what house.
 *
 * Deliberately without money, so somebody planning a season can be given it without being given
 * the takings.
 */
class EventsSource extends BaseSource
{
    public function key(): string
    {
        return 'events';
    }

    public function permission(): string
    {
        return 'reports.attendance.view';
    }

    public function dimensions(): array
    {
        return [
            'event' => $this->dimension('event', 'events.name'),
            'venue' => $this->dimension('venue', 'venues.name'),
            'event_status' => $this->dimension('event_status', 'events.status'),
            'currency' => $this->dimension('currency', 'events.currency'),
            'month' => $this->dimension('month', "date_trunc('month', events.starts_at)", 'date'),
            'weekday' => $this->dimension('weekday', "to_char(events.starts_at, 'ID')", 'weekday'),
        ];
    }

    public function measures(): array
    {
        return [
            'events' => $this->measure('events', 'count', 'events.id'),
            'capacity' => $this->measure('capacity', 'sum', 'seat_map_versions.seat_count'),
        ];
    }

    public function filters(): array
    {
        return [
            'status' => $this->filter('status', 'enum', 'events.status', [
                'draft', 'published', 'closed', 'cancelled',
            ]),
            'starts_at' => $this->filter('starts_at', 'date_range', 'events.starts_at'),
        ];
    }

    public function query(): Builder
    {
        return Event::query()
            ->leftJoin('venues', 'venues.id', '=', 'events.venue_id')
            ->leftJoin('seat_map_versions', 'seat_map_versions.id', '=', 'events.seat_map_version_id');
    }
}
