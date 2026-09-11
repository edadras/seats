<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Rehearsals\Rehearsals;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

/**
 * Rehearsing a night, and sweeping the rehearsal away.
 *
 * Behind `events.manage`, which is also what a programme manager holds for the nights they were
 * given — the person putting on the show is exactly the person who should walk its checkout before
 * a stranger does.
 *
 * Three verbs and no more: ask how it is going, turn it on or off, clear it. Turning it on and
 * clearing it are deliberately not the same call, because they are opposite kinds of act — one
 * changes what a night is, the other destroys what a night has done — and a screen that did both
 * from one button would be a screen that deletes bookings by accident.
 */
class RehearsalController extends Controller
{
    public function __construct(private readonly Rehearsals $rehearsals) {}

    public function show(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        return response()->json($this->rehearsals->tally($event));
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate(['rehearsing' => ['required', 'boolean']]);

        $event = $data['rehearsing']
            ? $this->rehearsals->start($event)
            : $this->rehearsals->finish($event);

        return response()->json($this->rehearsals->tally($event));
    }

    /**
     * Clear the rehearsal.
     *
     * A `DELETE` on the rehearsal rather than on its bookings, and the distinction is the point: it
     * is not an order-deleting endpoint that happens to be restricted to rehearsals. The only thing
     * it can address is a night that is being rehearsed, and {@see Rehearsals::clear()} refuses
     * anything else before it reads a single row.
     */
    public function destroy(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $gone = $this->rehearsals->clear($event);

        return response()->json([
            'cleared' => $gone,
        ] + $this->rehearsals->tally($event->fresh()));
    }
}
