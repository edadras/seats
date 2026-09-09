<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Events\EventStats;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Site;
use App\Models\User;
use App\Support\Access\Gate;
use Illuminate\Http\Request;

/**
 * The first screen: what is selling, what is coming, and what changed.
 *
 * One endpoint rather than a screen that fans out to six, because the first thing anybody sees
 * should not be six spinners settling at different times. It is also the only place in the panel
 * where numbers from four different permissions meet, which is why each part of the answer is
 * asked for separately below rather than assembled and then filtered — a filter you can forget is
 * a filter that eventually leaks the takings to a door volunteer.
 */
class OverviewController extends Controller
{
    public function __construct(private readonly EventStats $stats) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'events.view');

        $gate = app(Gate::class);
        $maySeeMoney = $gate->allows($request, 'reports.orders.view');
        $maySeeDoor = $gate->allows($request, 'checkins.view');

        $upcoming = Event::with('venue')
            ->where('status', 'published')
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'events' => [
                'on_sale' => $upcoming->count(),
                'draft' => Event::where('status', 'draft')->count(),
            ],
            'seats' => [
                // Sold this month, counted from allocations rather than orders: an order can carry
                // several seats and a refund releases them one at a time.
                'sold_this_month' => Allocation::where('status', 'active')
                    ->where('allocated_at', '>=', now()->startOfMonth())
                    ->sum('quantity'),
            ],
            'money' => $maySeeMoney ? $this->takings() : null,
            'door' => $maySeeDoor ? $this->door() : null,
            'sites' => ['live' => Site::where('status', 'live')->count()],
            // The next few nights, with how full each one is. Five, because a list somebody
            // scrolls is a list they stopped reading.
            'next' => $upcoming->take(5)->map(
                fn (Event $event) => $this->night($event, $maySeeMoney)
            )->values(),
            'activity' => $gate->allows($request, 'audit.view') ? $this->activity() : null,
        ]);
    }

    /** What the month has taken, by currency: one account may sell in more than one. */
    private function takings(): array
    {
        $rows = Allocation::query()
            ->selectRaw('currency, sum(amount) as gross')
            ->where('status', 'active')
            ->where('allocated_at', '>=', now()->startOfMonth())
            ->groupBy('currency')
            ->orderByDesc('gross')
            ->get();

        return [
            'this_month' => $rows->map(fn ($row) => [
                'currency' => $row->currency,
                'gross_amount' => (int) $row->gross,
            ])->values(),
        ];
    }

    private function door(): array
    {
        $issued = \App\Models\Ticket::whereIn('status', ['issued', 'used'])->count();
        $used = \App\Models\Ticket::where('status', 'used')->count();

        return [
            'issued' => $issued,
            'checked_in' => $used,
            'rate' => $issued > 0 ? round($used / $issued, 4) : 0.0,
        ];
    }

    private function night(Event $event, bool $withMoney): array
    {
        $stats = $this->stats->for($event, withMoney: $withMoney);
        $total = (int) ($stats['seats_total'] ?? 0);
        $sold = (int) ($stats['allocated'] ?? 0);

        return [
            'id' => $event->id,
            'public_id' => $event->public_id,
            'name' => $event->name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'venue' => $event->venue?->name,
            'seats_total' => $total,
            'allocated' => $sold,
            'available' => (int) ($stats['available'] ?? 0),
            // Worked out here so every reader of this endpoint divides the same way.
            'sold_ratio' => $total > 0 ? round($sold / $total, 4) : 0.0,
            'gross_amount' => $withMoney ? ($stats['gross_amount'] ?? 0) : null,
            'currency' => $event->currency,
        ];
    }

    /** The last few things that happened, named rather than numbered. */
    private function activity(): array
    {
        $logs = AuditLog::visible()->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get();

        // Only rows whose actor was a person: `actor_id` is a UUID, an `ak_…` key id or a device
        // id depending on `actor_type`, and `users.id` is a uuid column.
        $names = User::whereIn(
            'id',
            $logs->where('actor_type', 'user')->pluck('actor_id')->filter()->unique()
        )->pluck('name', 'id');

        return $logs->map(fn (AuditLog $log) => [
            'action' => $log->action,
            'subject_label' => $log->subject_label,
            'actor' => ['type' => $log->actor_type, 'name' => $names[$log->actor_id] ?? null],
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
