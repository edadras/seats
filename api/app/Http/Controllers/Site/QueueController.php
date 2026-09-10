<?php

namespace App\Http\Controllers\Site;

use App\Domain\Queue\WaitingRoom;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\QueueTicket;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the waiting-room page asks, every few seconds, and what it is told.
 *
 * The whole of the queue's motion happens here. Every ask sweeps the leases that have run out and
 * lets the next people in, which sounds like a lot of work to hang off a poll and is in fact the
 * only version that behaves: a cron job running every minute would leave the room half empty for
 * fifty-nine seconds out of every minute of the busiest hour of the year, and the people outside
 * are refreshing anyway.
 *
 * The token lives in this browser's session, never in the URL. A place in a queue that could be
 * pasted into a message would be a place somebody could give away — or sell, which at the front of
 * a big onsale is worth real money.
 */
class QueueController extends Controller
{
    /** One session can be in several queues: a person may be waiting for two nights at once. */
    public const SESSION = 'seatmap_queue';

    public function __construct(private readonly WaitingRoom $room) {}

    /** Where I stand. Answered as JSON, because the page that asks is a page already drawn. */
    public function show(Request $request, string $publicId)
    {
        $event = $this->eventOrFail($publicId);

        if (! $this->room->guards($event)) {
            // No door on this night. Said plainly rather than 404, because a page that was drawn a
            // moment before the organiser switched the room off should send its reader onwards.
            return response()->json(['state' => 'admitted', 'ahead' => 0]);
        }

        $ticket = $this->ticketFor($request, $event);

        return response()->json($this->room->state($event, $ticket));
    }

    /**
     * Give up my place.
     *
     * A POST, and it does what it says: the place is gone, and coming back means joining the back
     * of the queue. Offered because somebody who has decided not to buy should be able to release
     * a slot rather than sitting on it until the lease runs out.
     */
    public function leave(Request $request, string $publicId)
    {
        $event = $this->eventOrFail($publicId);
        $ticket = $this->existingTicket($request, $event);

        if ($ticket) {
            $this->room->leave($ticket);
            $this->forget($request, $event);
        }

        return redirect('/');
    }

    /**
     * This browser's place, made on first sight.
     *
     * Public because the page that renders the room needs it too, and both must reach the same row
     * — two tickets for one browser would put the same person in the queue twice.
     */
    public function ticketFor(Request $request, Event $event): QueueTicket
    {
        $ticket = $this->existingTicket($request, $event);

        if ($ticket && ! in_array($ticket->status, ['expired', 'left'], true)) {
            return $ticket;
        }

        $ticket = $this->room->join(
            $event,
            null,
            (string) $request->session()->getId(),
            $request->ip(),
        );

        $tokens = (array) $request->session()->get(self::SESSION, []);
        $tokens[$event->id] = $ticket->token;
        $request->session()->put(self::SESSION, $tokens);

        return $ticket;
    }

    /** The row this browser already has, or nothing. Never creates one. */
    public function existingTicket(Request $request, Event $event): ?QueueTicket
    {
        $token = ((array) $request->session()->get(self::SESSION, []))[$event->id] ?? null;

        return $token
            ? QueueTicket::where('event_id', $event->id)->where('token', $token)->first()
            : null;
    }

    /**
     * Whoever is inside may buy; everybody else may look.
     *
     * This never *creates* a place, which is the point of it being separate from `place()` below:
     * a hold posted straight at the store route by somebody who has never seen the queue must be
     * refused, not quietly given number one.
     */
    public function isAdmitted(Request $request, Event $event): bool
    {
        if (! $this->room->guards($event)) {
            return true;
        }

        // Turning the handle here as well as on the poll: a buyer who followed a link straight to
        // the checkout should not have to go back and refresh a page to be let in.
        $this->room->turn($event);

        return (bool) $this->existingTicket($request, $event)?->isAdmitted();
    }

    /**
     * Take a place on arrival, and say whether it lets this visitor in.
     *
     * Called when the event page is drawn, so that somebody who lands on a queueing night is *in*
     * the queue from the moment the page reaches them — not from the moment their JavaScript first
     * polls. A visitor whose first poll is late, or whose script never runs at all, still has a
     * place, and the number the organiser is watching is the number of people actually waiting.
     */
    public function place(Request $request, Event $event): bool
    {
        if (! $this->room->guards($event)) {
            return true;
        }

        $ticket = $this->ticketFor($request, $event);
        $this->room->turn($event);

        return (bool) $ticket->fresh()?->isAdmitted();
    }

    /** The buyer has what they came for. Holding their slot afterwards keeps somebody else out. */
    public function done(Request $request, Event $event): void
    {
        $ticket = $this->existingTicket($request, $event);

        if ($ticket) {
            $this->room->leave($ticket);
            $this->forget($request, $event);
        }
    }

    private function forget(Request $request, Event $event): void
    {
        $tokens = (array) $request->session()->get(self::SESSION, []);
        unset($tokens[$event->id]);
        $request->session()->put(self::SESSION, $tokens);
    }

    private function eventOrFail(string $publicId): Event
    {
        $event = Event::where('public_id', $publicId)->first();

        if (! $event || 'draft' === $event->status) {
            throw new NotFoundHttpException('No such event.');
        }

        return $event;
    }
}
