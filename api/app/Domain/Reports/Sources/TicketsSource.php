<?php

namespace App\Domain\Reports\Sources;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tickets issued, used and voided.
 *
 * Attendance rather than money, so it takes the attendance permission: a volunteer coordinator can
 * be given this without being given revenue (ADR-0006 §5).
 */
class TicketsSource extends BaseSource
{
    public function key(): string
    {
        return 'tickets';
    }

    public function permission(): string
    {
        return 'reports.attendance.view';
    }

    public function dimensions(): array
    {
        return [
            'event' => $this->dimension('event', 'events.name'),
            'ticket_status' => $this->dimension('ticket_status', 'tickets.status'),
            'section' => $this->dimension('section', 'allocations.section_name'),
            'day' => $this->dimension('day', "date_trunc('day', tickets.issued_at)", 'date'),
        ];
    }

    public function measures(): array
    {
        return [
            'tickets' => $this->measure('tickets', 'count', 'tickets.id'),
            'used' => $this->measure('used', 'count', 'tickets.used_at'),
        ];
    }

    public function filters(): array
    {
        return [
            'event_id' => $this->filter('event_id', 'event', 'tickets.event_id'),
            'status' => $this->filter('status', 'enum', 'tickets.status', ['issued', 'used', 'void']),
            'issued_at' => $this->filter('issued_at', 'date_range', 'tickets.issued_at'),
        ];
    }

    public function query(): Builder
    {
        return Ticket::query()
            ->leftJoin('events', 'events.id', '=', 'tickets.event_id')
            ->leftJoin('allocations', 'allocations.id', '=', 'tickets.allocation_id');
    }
}
