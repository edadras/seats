<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Queue\WaitingRoom;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

/**
 * The door on one night, from the organiser's side.
 *
 * Behind `events.view`, because that is what it is: a live number about a night's sale, not a way
 * to change anything. The room itself is switched on and sized on the event, beside the sale times
 * it belongs with.
 *
 * Every read turns the handle — lapsed leases swept, the next people let in — which is deliberate.
 * An organiser refreshing this screen at ten o'clock on the morning of a big onsale is another pair
 * of hands on the door, and the alternative is a cron job that leaves the room half empty for
 * fifty-nine seconds out of every minute of the busiest hour of the year.
 */
class WaitingRoomController extends Controller
{
    public function __construct(private readonly WaitingRoom $room) {}

    public function show(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        if (! $this->room->guards($event)) {
            return response()->json([
                'guarded' => false,
                'open' => true,
                'waiting' => 0,
                'inside' => 0,
                'capacity' => 0,
                'minutes' => 0,
            ]);
        }

        $this->room->turn($event);

        return response()->json([
            'guarded' => true,
            'open' => $this->room->isOpen($event),
            // Counted, never read off a column — the same as everywhere else in this application
            // that answers a question of the shape "how many, against a limit".
            'waiting' => $this->room->waiting($event),
            'inside' => $this->room->inside($event),
            'capacity' => (int) $event->waiting_room_capacity,
            'minutes' => (int) $event->waiting_room_minutes,
            'opens_at' => $event->on_sale_at?->toIso8601String(),
        ]);
    }
}
