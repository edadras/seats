<?php

namespace App\Domain\Reports\Sources;

use App\Models\ExternalOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The same orders as `orders`, seen from the person who placed them.
 *
 * One row per order, so the money adds up the way the orders report does; what is different is
 * that a buyer can be grouped by, and that `customers` counts people rather than orders — which
 * is how "how many of last month's buyers had bought before" becomes answerable at all.
 *
 * The address is normalised in the expression rather than assumed to be clean: two orders from the
 * same person, typed with different capitals, are one customer.
 */
class BuyersSource extends BaseSource
{
    public function key(): string
    {
        return 'buyers';
    }

    /**
     * Money's permission, not attendance's.
     *
     * This dataset carries buyers' names and addresses beside their totals, and the role that may
     * read the takings is the one that may already open the orders those came from.
     */
    public function permission(): string
    {
        return 'reports.orders.view';
    }

    public function dimensions(): array
    {
        return [
            'buyer' => $this->dimension('buyer', "nullif(btrim(external_orders.buyer->>'name'), '')"),
            'buyer_email' => $this->dimension('buyer_email', "lower(btrim(external_orders.buyer->>'email'))"),
            'event' => $this->dimension('event', 'events.name'),
            'order_status' => $this->dimension('order_status', 'external_orders.status'),
            'currency' => $this->dimension('currency', 'external_orders.currency'),
            'channel' => $this->dimension('channel', 'api_clients.name'),
            'day' => $this->dimension('day', "date_trunc('day', external_orders.created_at)", 'date'),
            'month' => $this->dimension('month', "date_trunc('month', external_orders.created_at)", 'date'),
        ];
    }

    public function measures(): array
    {
        return [
            'customers' => $this->measure(
                'customers',
                'count_distinct',
                "lower(btrim(external_orders.buyer->>'email'))"
            ),
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
        // Orders nobody can be attributed to would otherwise group under an empty name and read as
        // one enormous customer. They are counted on the customers screen instead, where the number
        // is labelled for what it is.
        return ExternalOrder::query()
            ->leftJoin('events', 'events.id', '=', 'external_orders.event_id')
            ->leftJoin('api_clients', 'api_clients.id', '=', 'external_orders.api_client_id')
            ->whereRaw("nullif(btrim(coalesce(external_orders.buyer->>'email', '')), '') is not null");
    }
}
