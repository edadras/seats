<?php

namespace App\Domain\Customers;

use App\Models\Allocation;
use App\Models\ExternalOrder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who has bought from this organiser, worked out from the orders themselves.
 *
 * There is no customers table, and that is a decision rather than an omission. A buyer on this
 * platform is not an account: they type a name and an email at a checkout, or a shop hands those
 * over with an order, and nobody signs in. A second table holding a copy of that would have to be
 * written by every path that creates an order — the hosted checkout, the WooCommerce integration,
 * a module — and the first one that forgot would produce a customer list quietly missing people.
 * Derived from `external_orders`, the list cannot drift from the orders it describes.
 *
 * Identity is the email, lowercased and trimmed, because it is the only thing on an order that is
 * the same person twice. It is never the URL key: an address in a path is an address in an access
 * log, a browser history and a referrer header, so the key is its SHA-256 and the address itself
 * travels in the body of an authorised response.
 *
 * Orders with no email are not people and are not listed; the count of them is returned so a
 * screen can say so rather than silently disagree with the orders report.
 */
class CustomerDirectory
{
    /** Statuses where money actually arrived. A refund is an order, not takings. */
    public const PAID = ['confirmed', 'partially_refunded'];

    public const MAX_EXPORT = 20000;

    /** How a list may be ordered. The direction is written here, never taken from the request. */
    private const SORTS = [
        'recent' => ['last_order_at', 'desc'],
        'oldest' => ['first_order_at', 'asc'],
        'orders' => ['orders_count', 'desc'],
        'spend' => ['spend', 'desc'],
        'name' => ['name', 'asc'],
    ];

    /** The normalised address: one person, however they capitalised it. */
    private const EMAIL = "lower(btrim(external_orders.buyer->>'email'))";

    /** The key a URL may carry. */
    private const ID = "encode(sha256(convert_to(lower(btrim(external_orders.buyer->>'email')), 'UTF8')), 'hex')";

    /**
     * One page of the directory.
     *
     * @param  array{q?:string, currency?:string, event_id?:string, sort?:string}  $filters
     * @return array{rows:list<array>, total:int, currencies:list<string>, currency:?string, without_email:int}
     */
    public function page(array $filters, int $perPage, int $page): array
    {
        $currency = $this->currency($filters['currency'] ?? null);
        $sort = self::SORTS[$filters['sort'] ?? 'recent'] ?? self::SORTS['recent'];

        $grouped = $this->grouped($filters, $currency);

        $total = DB::query()->fromSub(clone $grouped, 'people')->count();

        $rows = $grouped
            ->orderByRaw($sort[0].' '.$sort[1])
            // A tie on "last order" is common — two people who bought on the same evening — and
            // without a second key the same person can appear on two pages and nowhere else.
            ->orderByRaw('email asc')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            'rows' => $this->withSpend($rows, $filters),
            'total' => $total,
            'currencies' => $this->currencies(),
            'currency' => $currency,
            'without_email' => $this->withoutEmail($filters),
        ];
    }

    /** Everyone the filters match, for a file. */
    public function all(array $filters): array
    {
        $currency = $this->currency($filters['currency'] ?? null);

        $rows = $this->grouped($filters, $currency)
            ->orderByRaw('last_order_at desc')
            ->limit(self::MAX_EXPORT)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return ['rows' => $this->withSpend($rows, $filters), 'currency' => $currency];
    }

    /**
     * One person: what is known about them, and every order they placed.
     *
     * Returns null rather than an empty person when the key matches nothing, so the caller can
     * answer 404 — a hash that matches no order of this tenant's is not a customer with no orders.
     */
    public function find(string $id): ?array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $id)) {
            return null;
        }

        $orders = ExternalOrder::query()
            ->whereRaw(self::ID.' = ?', [$id])
            ->with(['event:id,name,starts_at,timezone,currency', 'allocations.ticket:id,allocation_id,status,used_at'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        $latest = $orders->first();
        $paid = $orders->whereIn('status', self::PAID);
        $spend = [];

        foreach ($paid->groupBy('currency') as $code => $group) {
            $spend[] = ['currency' => $code, 'amount' => (int) $group->sum('total_amount')];
        }

        return [
            'id' => $id,
            'name' => $this->latestField($orders, 'name'),
            'email' => $this->latestField($orders, 'email'),
            'phone' => $this->latestField($orders, 'phone'),
            'orders_count' => $orders->count(),
            'confirmed_count' => $paid->count(),
            'events_count' => $orders->pluck('event_id')->unique()->count(),
            'seats_count' => (int) $orders->sum(
                fn (ExternalOrder $order) => $order->allocations
                    ->where('status', 'active')
                    ->sum(fn ($allocation) => $allocation->quantity ?: 1)
            ),
            'first_order_at' => $orders->min('created_at')?->toIso8601String(),
            'last_order_at' => $latest->created_at?->toIso8601String(),
            'spend' => $spend,
            'orders' => $orders->map(fn (ExternalOrder $order) => $this->order($order))->values()->all(),
        ];
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * The aggregate, one row per person.
     *
     * `array_agg(... order by created_at desc) filter (where ... is not null)` is how the most
     * recent *non-empty* name and phone win: a buyer who left the phone box blank last week should
     * still show the number they gave in March, and plain `max()` would pick alphabetically.
     */
    private function grouped(array $filters, ?string $currency): QueryBuilder
    {
        $paid = "external_orders.status in ('".implode("', '", self::PAID)."')";

        $query = $this->base($filters)->selectRaw(
            self::ID.' as id, '.
            self::EMAIL.' as email, '.
            "(array_agg(btrim(external_orders.buyer->>'name') order by external_orders.created_at desc) ".
                "filter (where nullif(btrim(coalesce(external_orders.buyer->>'name', '')), '') is not null))[1] as name, ".
            "(array_agg(btrim(external_orders.buyer->>'phone') order by external_orders.created_at desc) ".
                "filter (where nullif(btrim(coalesce(external_orders.buyer->>'phone', '')), '') is not null))[1] as phone, ".
            'count(*) as orders_count, '.
            'count(*) filter (where '.$paid.') as confirmed_count, '.
            'count(distinct external_orders.event_id) as events_count, '.
            'coalesce(sum(coalesce(seats.seats, 0)), 0) as seats_count, '.
            'min(external_orders.created_at) as first_order_at, '.
            'max(external_orders.created_at) as last_order_at, '.
            'coalesce(sum(external_orders.total_amount) filter (where '.$paid.
                ' and external_orders.currency = ?), 0) as spend',
            [$currency ?? '']
        );

        return $query->groupByRaw(self::ID.', '.self::EMAIL);
    }

    /**
     * Orders that belong to a person, with their seat counts alongside.
     *
     * The seats arrive through a grouped sub-join rather than a plain join to `allocations`:
     * joining the rows themselves would repeat each order once per seat and multiply every total
     * on this screen by the size of the party.
     */
    private function base(array $filters): QueryBuilder
    {
        $seats = Allocation::query()
            ->selectRaw('external_order_row_id, sum(quantity) as seats')
            ->where('status', 'active')
            ->whereNotNull('external_order_row_id')
            ->groupBy('external_order_row_id');

        $query = ExternalOrder::query()
            ->toBase()
            ->leftJoinSub($seats, 'seats', 'seats.external_order_row_id', '=', 'external_orders.id')
            ->whereRaw("nullif(btrim(coalesce(external_orders.buyer->>'email', '')), '') is not null");

        if (! empty($filters['event_id'])) {
            $query->where('external_orders.event_id', $filters['event_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('external_orders.status', $filters['status']);
        }

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($filters['q']))).'%';

            $query->where(function ($where) use ($term) {
                $where->whereRaw("lower(coalesce(external_orders.buyer->>'email', '')) like ?", [$term])
                    ->orWhereRaw("lower(coalesce(external_orders.buyer->>'name', '')) like ?", [$term])
                    ->orWhereRaw("lower(coalesce(external_orders.buyer->>'phone', '')) like ?", [$term])
                    ->orWhereRaw('lower(external_orders.external_order_id) like ?', [$term]);
            });
        }

        return $query;
    }

    /**
     * What each person on this page has spent, in each currency they spent it.
     *
     * A separate query rather than a column, because an organiser who sells one night in euros and
     * another in rials has two totals and no exchange rate to add them with. The list's sortable
     * `spend` column is one chosen currency; this is the truth beside it.
     */
    private function withSpend(array $rows, array $filters): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_column($rows, 'id');

        $totals = $this->base($filters)
            ->selectRaw(self::ID.' as id, external_orders.currency as currency, sum(external_orders.total_amount) as amount')
            ->whereIn('external_orders.status', self::PAID)
            ->whereRaw(self::ID.' in ('.implode(',', array_fill(0, count($ids), '?')).')', $ids)
            ->groupByRaw(self::ID.', external_orders.currency')
            ->get();

        $byPerson = [];

        foreach ($totals as $total) {
            $byPerson[$total->id][] = ['currency' => $total->currency, 'amount' => (int) $total->amount];
        }

        return array_map(function (array $row) use ($byPerson) {
            // The sortable column keeps its own name; `spend` is every currency this person paid
            // in, so a screen can show both without either pretending to be the other.
            $row['spend_in_currency'] = (int) ($row['spend'] ?? 0);
            $row['spend'] = $byPerson[$row['id']] ?? [];
            $row['orders_count'] = (int) $row['orders_count'];
            $row['confirmed_count'] = (int) $row['confirmed_count'];
            $row['events_count'] = (int) $row['events_count'];
            $row['seats_count'] = (int) $row['seats_count'];

            return $row;
        }, $rows);
    }

    private function order(ExternalOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->external_order_id,
            'status' => $order->status,
            'currency' => $order->currency,
            'total_amount' => (int) $order->total_amount,
            'placed_at' => $order->created_at?->toIso8601String(),
            'event' => $order->event ? [
                'id' => $order->event->id,
                'name' => $order->event->name,
                'starts_at' => $order->event->starts_at?->toIso8601String(),
            ] : null,
            'checked_in' => $order->allocations
                ->filter(fn ($allocation) => $allocation->ticket?->used_at)
                ->count(),
            'lines' => $order->allocations->map(fn ($allocation) => [
                'section' => $allocation->section_name,
                'row' => $allocation->row_name,
                'seat' => $allocation->seat_id ? $allocation->seat_label : null,
                'quantity' => $allocation->seat_id ? 1 : (int) ($allocation->quantity ?: 1),
                'amount' => (int) $allocation->amount,
                'currency' => $allocation->currency,
                'status' => $allocation->status,
                'used_at' => $allocation->ticket?->used_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** The most recent order that actually filled this field in. */
    private function latestField($orders, string $field): ?string
    {
        foreach ($orders as $order) {
            $value = trim((string) (($order->buyer ?? [])[$field] ?? ''));

            if ('' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /** The currencies this account has actually taken money in, commonest first. */
    private function currencies(): array
    {
        return ExternalOrder::query()
            ->toBase()
            ->selectRaw('external_orders.currency, count(*) as orders')
            ->groupBy('external_orders.currency')
            ->orderByDesc('orders')
            ->pluck('currency')
            ->all();
    }

    private function currency(?string $wanted): ?string
    {
        $currencies = $this->currencies();

        if ($wanted && in_array($wanted, $currencies, true)) {
            return $wanted;
        }

        return $currencies[0] ?? null;
    }

    /** Orders nobody can be attributed to. Counted so the screen can admit to them. */
    private function withoutEmail(array $filters): int
    {
        $query = ExternalOrder::query()->toBase()
            ->whereRaw("nullif(btrim(coalesce(external_orders.buyer->>'email', '')), '') is null");

        if (! empty($filters['event_id'])) {
            $query->where('external_orders.event_id', $filters['event_id']);
        }

        return $query->count();
    }
}
