<?php

namespace App\Domain\Reports\Sources;

use App\Models\Allocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Seats actually sold, cut by where they are in the house.
 *
 * Reads the denormalised section, row and label written at sale time, so a report about last
 * season still says what was sold even if this season's chart renamed the balcony.
 */
class SeatsSoldSource extends BaseSource
{
    public function key(): string
    {
        return 'seats_sold';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
    }

    public function dimensions(): array
    {
        return [
            'event' => $this->dimension('event', 'events.name'),
            'section' => $this->dimension('section', 'allocations.section_name'),
            'row' => $this->dimension('row', 'allocations.row_name'),
            // The name written at sale time, not a join to the types table: "how many children
            // came in September" must keep answering the same thing after the type is renamed.
            'ticket_type' => $this->dimension('ticket_type', "coalesce(allocations.ticket_type_name, '')"),
            'allocation_status' => $this->dimension('allocation_status', 'allocations.status'),
            'currency' => $this->dimension('currency', 'allocations.currency'),
            'day' => $this->dimension('day', "date_trunc('day', allocations.allocated_at)", 'date'),
            'month' => $this->dimension('month', "date_trunc('month', allocations.allocated_at)", 'date'),
        ];
    }

    public function measures(): array
    {
        return [
            'seats' => $this->measure('seats', 'count', 'allocations.id'),
            'revenue' => $this->measure('revenue', 'sum', 'allocations.amount', 'money'),
            'average_seat' => $this->measure('average_seat', 'avg', 'allocations.amount', 'money'),
            'highest_seat' => $this->measure('highest_seat', 'max', 'allocations.amount', 'money'),
        ];
    }

    public function filters(): array
    {
        return [
            'event_id' => $this->filter('event_id', 'event', 'allocations.event_id'),
            'status' => $this->filter('status', 'enum', 'allocations.status', ['active', 'released', 'void']),
            'allocated_at' => $this->filter('allocated_at', 'date_range', 'allocations.allocated_at'),
        ];
    }

    public function query(): Builder
    {
        return Allocation::query()
            ->leftJoin('events', 'events.id', '=', 'allocations.event_id');
    }
}
