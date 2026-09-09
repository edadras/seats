<?php

namespace App\Domain\Reports\Sources;

use App\Models\Checkin;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who came in, when, and through which door.
 *
 * Every scan is here, not just the ones that worked: a report that hides the refusals cannot
 * answer "how many people were turned away at door two", which is the question the night after.
 */
class CheckinsSource extends BaseSource
{
    public function key(): string
    {
        return 'checkins';
    }

    public function permission(): string
    {
        return 'reports.attendance.view';
    }

    public function dimensions(): array
    {
        return [
            'event' => $this->dimension('event', 'events.name'),
            'result' => $this->dimension('result', 'checkins.result'),
            'device' => $this->dimension('device', 'checkin_devices.name'),
            'operator' => $this->dimension('operator', 'checkin_operators.name'),
            'day' => $this->dimension('day', "date_trunc('day', checkins.scanned_at)", 'date'),
            'hour' => $this->dimension('hour', "date_trunc('hour', checkins.scanned_at)", 'datetime'),
            'offline' => $this->dimension('offline', 'checkins.offline', 'boolean'),
        ];
    }

    public function measures(): array
    {
        return [
            'scans' => $this->measure('scans', 'count', 'checkins.id'),
            'people' => $this->measure('people', 'count_distinct', 'checkins.ticket_id'),
        ];
    }

    public function filters(): array
    {
        return [
            'event_id' => $this->filter('event_id', 'event', 'checkins.event_id'),
            'result' => $this->filter('result', 'enum', 'checkins.result', [
                'valid', 'already_used', 'cancelled', 'refunded', 'wrong_event', 'invalid',
            ]),
            'scanned_at' => $this->filter('scanned_at', 'date_range', 'checkins.scanned_at'),
        ];
    }

    public function query(): Builder
    {
        return Checkin::query()
            ->leftJoin('events', 'events.id', '=', 'checkins.event_id')
            ->leftJoin('checkin_devices', 'checkin_devices.id', '=', 'checkins.checkin_device_id')
            ->leftJoin('checkin_operators', 'checkin_operators.id', '=', 'checkins.checkin_operator_id');
    }
}
