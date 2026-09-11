<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Rehearsals\Live;
use App\Domain\Attribution\Attribution;
use App\Http\Controllers\Controller;
use App\Models\ExternalOrder;
use App\Models\Promoter;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The people who sell on an organiser's behalf, and what they have sold.
 *
 * The list is behind `events.manage` and the figures behind `reports.orders.view`, which is the
 * same pair the takings already use: what a promoter is owed is money, and whoever may add a
 * promoter is not necessarily whoever may see what the night took.
 */
class PromoterController extends Controller
{
    public function __construct(private readonly Attribution $attribution, private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'events.manage');

        $promoters = Promoter::query()->orderBy('name')->get();

        return response()->json([
            'data' => $promoters->map(fn (Promoter $promoter) => $this->present($promoter))->all(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'events.manage');

        $promoter = Promoter::create($this->validated($request, creating: true));

        $this->audit->record('promoter.created', $promoter, ['name' => $promoter->name]);

        return response()->json($this->present($promoter), 201);
    }

    public function update(Request $request, Promoter $promoter)
    {
        $this->authorize($request, 'events.manage');

        $promoter->update($this->validated($request, creating: false, promoter: $promoter));

        $this->audit->record('promoter.updated', $promoter, ['name' => $promoter->name]);

        return response()->json($this->present($promoter->fresh()));
    }

    public function destroy(Request $request, Promoter $promoter)
    {
        $this->authorize($request, 'events.manage');

        // Deactivated rather than deleted where they have sold anything: the bookings keep their
        // copy of what happened either way, but a link that quietly starts working again because
        // somebody recreated the code is worse than a promoter who is simply switched off.
        if ($promoter->orders()->exists()) {
            $promoter->update(['active' => false]);

            $this->audit->record('promoter.deactivated', $promoter, ['name' => $promoter->name]);

            return response()->json($this->present($promoter->fresh()));
        }

        $this->audit->record('promoter.deleted', $promoter, ['name' => $promoter->name]);
        $promoter->delete();

        return response()->noContent();
    }

    /**
     * What each of them has sold, and what is owed.
     *
     * Worked out from the bookings on every read rather than kept in a column: commission follows
     * the tickets, so a refund takes it back without anything having to remember to.
     */
    public function performance(Request $request)
    {
        $this->authorize($request, 'reports.orders.view');

        $filters = $request->validate([
            'event_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $orders = ExternalOrder::query()
            ->whereNotNull('promoter_id')
            // Nobody is owed a commission on a rehearsal.
            ->tap(fn ($query) => Live::only($query, 'external_orders.event_id'))
            ->whereIn('status', ['confirmed', 'partially_refunded'])
            ->when($filters['event_id'] ?? null, fn ($q, $id) => $q->where('event_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('confirmed_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('confirmed_at', '<=', $to))
            ->with('allocations')
            ->get();

        $rows = [];

        foreach ($orders as $order) {
            $key = (string) $order->promoter_id;
            $tickets = $order->allocations->where('status', 'active');

            $rows[$key] ??= [
                'promoter_id' => $order->promoter_id,
                // The name as it was on the booking, not as it is now: an invoice for March is
                // for whoever they were in March.
                'name' => $order->attribution['promoter'] ?? null,
                'orders' => 0, 'tickets' => 0, 'gross' => 0, 'commission' => 0,
                'currency' => $order->currency,
            ];

            $rows[$key]['orders']++;
            $rows[$key]['tickets'] += $tickets->count();
            $rows[$key]['gross'] += (int) $tickets->sum('amount');
            $rows[$key]['commission'] += $this->attribution->commissionOn($order);
        }

        $named = Promoter::whereIn('id', array_keys($rows))->get()->keyBy('id');

        foreach ($rows as $id => $row) {
            $rows[$id]['name'] = $row['name'] ?: ($named[$id]->name ?? null);
            $rows[$id]['code'] = $named[$id]->code ?? null;
        }

        /*
         * And what arrived on a link nobody is being paid for.
         *
         * Every organiser's second question, and the one a promoter table alone cannot answer: how
         * much came from the newsletter, the listing site, the poster with the code on it.
         */
        $campaigns = ExternalOrder::query()
            ->whereNull('promoter_id')
            ->tap(fn ($query) => Live::only($query, 'external_orders.event_id'))
            ->whereNotNull('attribution')
            ->whereIn('status', ['confirmed', 'partially_refunded'])
            ->when($filters['event_id'] ?? null, fn ($q, $id) => $q->where('event_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('confirmed_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('confirmed_at', '<=', $to))
            ->select([
                DB::raw("coalesce(attribution->>'utm_source', attribution->>'referrer', 'direct') as source"),
                DB::raw("coalesce(attribution->>'utm_campaign', '') as campaign"),
                DB::raw('count(*) as orders'),
            ])
            ->groupBy('source', 'campaign')
            ->orderByDesc('orders')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'source' => $row->source,
                'campaign' => '' === $row->campaign ? null : $row->campaign,
                'orders' => (int) $row->orders,
            ])->all();

        return response()->json(['data' => array_values($rows), 'campaigns' => $campaigns]);
    }

    private function validated(Request $request, bool $creating, ?Promoter $promoter = null): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'code' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:40',
                // Letters, digits and dashes: it goes in a URL and is read out over a telephone.
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('promoters', 'code')
                    ->where('tenant_id', app(\App\Support\Tenancy\TenantContext::class)->idOrFail())
                    ->ignore($promoter?->id),
            ],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            // Basis points: 750 is 7.5%. Capped at everything, because a hundred per cent
            // commission is a typo rather than an agreement.
            'commission_rate' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'active' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    private function present(Promoter $promoter): array
    {
        return [
            'id' => $promoter->id,
            'name' => $promoter->name,
            'code' => $promoter->code,
            'contact_email' => $promoter->contact_email,
            'commission_rate' => (int) $promoter->commission_rate,
            'commission_percent' => $promoter->commissionPercent(),
            'active' => (bool) $promoter->active,
            'note' => $promoter->note,
            // The query a link carries. The host is the organiser's own site, which the panel
            // knows and this endpoint deliberately does not: an account may have several.
            'link_query' => 'p='.$promoter->code,
        ];
    }
}
