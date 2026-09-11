<?php

namespace App\Domain\Reports\Sources;

use App\Models\ExternalOrder;
use Illuminate\Database\Eloquent\Builder;

/** Money taken, by whatever the organiser wants to cut it by. */
class OrdersSource extends BaseSource
{
    public function key(): string
    {
        return 'orders';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
    }

    public function dimensions(): array
    {
        return [
            'event' => $this->dimension('event', 'events.name'),
            'order_status' => $this->dimension('order_status', 'external_orders.status'),
            'currency' => $this->dimension('currency', 'external_orders.currency'),
            'channel' => $this->dimension('channel', 'api_clients.name'),
            'day' => $this->dimension('day', "date_trunc('day', external_orders.created_at)", 'date'),
            'month' => $this->dimension('month', "date_trunc('month', external_orders.created_at)", 'date'),
            'weekday' => $this->dimension('weekday', "to_char(external_orders.created_at, 'ID')", 'weekday'),
        ];
    }

    public function measures(): array
    {
        return [
            'orders' => $this->measure('orders', 'count', 'external_orders.id'),
            'revenue' => $this->measure('revenue', 'sum', 'external_orders.total_amount', 'money'),
            'average_order' => $this->measure('average_order', 'avg', 'external_orders.total_amount', 'money'),
        ];
    }

    public function filters(): array
    {
        return [
            'event_id' => $this->filter('event_id', 'event', 'external_orders.event_id'),
            'status' => $this->filter('status', 'enum', 'external_orders.status', [
                'pending', 'confirmed', 'cancelled', 'refunded', 'partially_refunded',
            ]),
            'created_at' => $this->filter('created_at', 'date_range', 'external_orders.created_at'),
        ];
    }

    public function query(): Builder
    {
        /*
         * One row per order, and deliberately no join to allocations.
         *
         * Joining the seats would multiply every order's total by the number of seats on it, and
         * a revenue report that is quietly three times too big is worse than no revenue report.
         * Seat-level questions have their own source, where the grain is a seat.
         */
        return ExternalOrder::query()
            ->leftJoin('events', 'events.id', '=', 'external_orders.event_id')
            ->leftJoin('api_clients', 'api_clients.id', '=', 'external_orders.api_client_id')
            // A rehearsal is not a sale and has no place in a report about sales.
            ->where('events.is_rehearsal', false);
    }
}
