<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Waitlist\WaitingList;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Site;
use App\Models\WaitingListEntry;
use Illuminate\Http\Request;

/**
 * The queue, from the organiser's side.
 *
 * Mostly a thing to look at: how many people want in, who they are, and who has already been told.
 * The one action is "tell them now", for the organiser who has just released a block of house
 * seats and does not want to wait for the next scheduled round.
 *
 * Reading is `orders.view` — the list is names and addresses of people who have bought nothing
 * yet, which is customer data — and telling them is `messages.send`, because it writes to people.
 */
class WaitingListController extends Controller
{
    public function __construct(private readonly WaitingList $list) {}

    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'orders.view');

        $data = $request->validate([
            'status' => ['nullable', 'in:waiting,notified,converted,left'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $entries = WaitingListEntry::where('event_id', $event->id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('created_at')
            ->paginate(min(200, (int) ($data['per_page'] ?? 50)));

        $counts = WaitingListEntry::where('event_id', $event->id)
            ->selectRaw('status, count(*) as total, coalesce(sum(quantity), 0) as places')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Built by hand rather than through `paginated()`: that helper answers with a response,
        // and this endpoint carries a summary beside the page.
        return response()->json([
            'data' => collect($entries->items())
                ->map(fn (WaitingListEntry $entry) => $this->present($entry))
                ->values(),
            'meta' => [
                'page' => $entries->currentPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'last_page' => $entries->lastPage(),
            ],
            'summary' => [
                'waiting' => (int) ($counts['waiting']->total ?? 0),
                'waiting_places' => (int) ($counts['waiting']->places ?? 0),
                'notified' => (int) ($counts['notified']->total ?? 0),
                'left' => (int) ($counts['left']->total ?? 0),
                // What there actually is to offer, asked of the same code the site asks.
                'free_places' => $this->list->freePlaces($event),
            ],
        ]);
    }

    /** Tell the next people in line now, rather than waiting for the scheduled round. */
    public function notify(Request $request, Event $event)
    {
        $this->authorize($request, 'messages.send');

        $site = Site::where('status', 'live')->orderBy('created_at')->first();

        if (! $site) {
            throw ApiException::unprocessable(
                'no_live_site',
                'The message links to a page on a live website, and this account has none yet.'
            );
        }

        return response()->json(['told' => $this->list->notify($event, $site)]);
    }

    private function present(WaitingListEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'name' => $entry->name,
            'email' => $entry->email,
            'phone' => $entry->phone,
            'quantity' => $entry->quantity,
            'status' => $entry->status,
            'joined_at' => $entry->created_at?->toIso8601String(),
            'notified_at' => $entry->notified_at?->toIso8601String(),
            // Whether their turn is still live, rather than making a screen work it out from a date.
            'claiming' => $entry->isClaiming(),
        ];
    }
}
