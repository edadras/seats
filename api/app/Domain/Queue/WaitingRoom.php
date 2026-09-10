<?php

namespace App\Domain\Queue;

use App\Models\Event;
use App\Models\QueueTicket;
use Illuminate\Support\Facades\DB;

/**
 * The door on a big sale: who is waiting, who is inside, and who goes next.
 *
 * Everything here is a sum against a limit — how many people are admitted, against how many this
 * venue wants choosing at once — so it is counted rather than kept in a counter, and counted under
 * an advisory lock keyed on the one event. That is the same discipline a seat's availability and an
 * add-on's stock are held to, and for the same reason: a stored number is a read-modify-write, and
 * two doors opening in the same second would both read "one short" and both let somebody in.
 *
 * The fairness rule is the part worth arguing about, so it is written down rather than implied.
 * Everybody who arrives **before** the doors open sits in a lobby with no place at all; when the
 * doors open the lobby is shuffled and given places in that order. Arriving early is worth nothing.
 * A queue that opens at ten and rewards whoever was already refreshing is a competition in who can
 * sit on a page, and it is won by people who were not at work. Anybody arriving **after** the doors
 * are open joins the back in arrival order, because by then "when you arrived" really is fair.
 *
 * None of this is proof of identity, and this file does not pretend otherwise. A token in a cookie
 * rations a shop; it does not stop a determined person opening ten browsers. What it does stop is
 * the ordinary case — a thousand honest buyers arriving at once and taking the site down between
 * them — and it does that well.
 */
class WaitingRoom
{
    public function __construct() {}

    /** Whether this night has a door at all. */
    public function guards(Event $event): bool
    {
        return (bool) $event->waiting_room && (int) $event->waiting_room_capacity > 0;
    }

    /**
     * Whether the doors are open.
     *
     * The sale's own opening instant, not a second setting: a room that opened at a different time
     * from the sale would be a second answer to "when can I buy", and one of the two would be wrong.
     */
    public function isOpen(Event $event, ?\DateTimeInterface $at = null): bool
    {
        $at = $at ?: now();

        return ! $event->on_sale_at || $event->on_sale_at->lessThanOrEqualTo($at);
    }

    /**
     * Find or make this browser's place in the queue.
     *
     * Idempotent per token: a page that reloads every few seconds must not join the queue every few
     * seconds, and a person who lands on two of the organiser's pages is one person.
     */
    public function join(Event $event, ?string $token, string $sessionId, ?string $ip = null): QueueTicket
    {
        $existing = $token
            ? QueueTicket::where('event_id', $event->id)->where('token', $token)->first()
            : null;

        if ($existing && ! in_array($existing->status, ['expired', 'left'], true)) {
            return $existing;
        }

        return DB::transaction(function () use ($event, $sessionId, $ip) {
            $this->lock($event);

            /*
             * Before the doors: no place. After them: the back of the queue.
             *
             * The place is taken under the same lock the draw and the admissions use, so two people
             * arriving in the same millisecond cannot both be number four hundred — and the partial
             * unique index says so even if this code one day forgets.
             */
            $open = $this->isOpen($event);

            return QueueTicket::create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'token' => QueueTicket::newToken(),
                'session_id' => $sessionId,
                'ip' => $ip,
                'status' => $open ? 'queued' : 'lobby',
                'place' => $open ? $this->nextPlace($event) : null,
                'joined_at' => now(),
            ]);
        });
    }

    /**
     * Where somebody stands, and whether they may go in.
     *
     * Every call also turns the handle: expired leases are swept and the next people are let in.
     * The queue moves because people are looking at it, which is the one thing a waiting room can
     * rely on happening — a cron job that ran every minute would leave the room half empty for
     * fifty-nine seconds of every minute of the busiest hour of the year.
     *
     * @return array{state: string, place: ?int, ahead: int, inside: int, capacity: int, expires_at: ?string, opens_at: ?string}
     */
    public function state(Event $event, QueueTicket $ticket): array
    {
        $this->turn($event);
        $ticket->refresh();

        $ahead = 'queued' === $ticket->status && null !== $ticket->place
            ? QueueTicket::where('event_id', $event->id)
                ->where('status', 'queued')
                ->where('place', '<', $ticket->place)
                ->count()
            : 0;

        return [
            'state' => $ticket->isAdmitted() ? 'admitted' : $ticket->status,
            'place' => $ticket->place,
            'ahead' => $ahead,
            'inside' => $this->inside($event),
            'capacity' => (int) $event->waiting_room_capacity,
            'expires_at' => $ticket->expires_at?->toIso8601String(),
            'opens_at' => $event->on_sale_at?->toIso8601String(),
        ];
    }

    /**
     * Sweep the lapsed, draw the lobby if the doors have just opened, and let people in.
     *
     * One lock, one pass, in that order — because each step changes what the next one sees, and
     * three separate locks would let somebody be admitted into a room that the sweep was about to
     * make space in anyway.
     */
    public function turn(Event $event): int
    {
        return DB::transaction(function () use ($event) {
            $this->lock($event);

            $this->sweep($event);

            if ($this->isOpen($event)) {
                $this->draw($event);
            }

            return $this->admit($event);
        });
    }

    /**
     * Give the lobby its places, in a shuffled order.
     *
     * This runs once — the first time anybody looks after the doors open — because after it there
     * is nobody left in the lobby to draw. Ordered by `random()` in the database rather than in PHP
     * so that a lobby of forty thousand is one statement rather than forty thousand rows through a
     * web process.
     */
    public function draw(Event $event): int
    {
        $waiting = QueueTicket::where('event_id', $event->id)
            ->where('status', 'lobby')
            ->inRandomOrder()
            ->pluck('id');

        if ($waiting->isEmpty()) {
            return 0;
        }

        $place = $this->nextPlace($event);

        foreach ($waiting as $id) {
            QueueTicket::whereKey($id)->update([
                'status' => 'queued',
                'place' => $place++,
                'updated_at' => now(),
            ]);
        }

        return $waiting->count();
    }

    /**
     * Let people in until the room is full.
     *
     * The count is taken here, inside the lock, and again after every admission — not read once and
     * decremented — because that is the only version of this that is still true when two of these
     * run at the same time.
     */
    public function admit(Event $event): int
    {
        $room = max(0, (int) $event->waiting_room_capacity - $this->inside($event));

        if ($room < 1) {
            return 0;
        }

        $next = QueueTicket::where('event_id', $event->id)
            ->where('status', 'queued')
            ->orderBy('place')
            ->limit($room)
            ->pluck('id');

        if ($next->isEmpty()) {
            return 0;
        }

        QueueTicket::whereIn('id', $next)->update([
            'status' => 'admitted',
            'admitted_at' => now(),
            'expires_at' => now()->addMinutes(max(1, (int) $event->waiting_room_minutes)),
            'updated_at' => now(),
        ]);

        return $next->count();
    }

    /**
     * Take back the leases that have run out.
     *
     * Without this, one person who walked away from their desk holds a place open for the rest of
     * the sale — and the room looks full to everybody outside it while nobody inside is buying.
     */
    public function sweep(Event $event): int
    {
        return QueueTicket::where('event_id', $event->id)
            ->where('status', 'admitted')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'left_at' => now(), 'updated_at' => now()]);
    }

    /** How many people are in the shop. Counted, never read off a column. */
    public function inside(Event $event): int
    {
        return QueueTicket::where('event_id', $event->id)
            ->where('status', 'admitted')
            ->where('expires_at', '>', now())
            ->count();
    }

    /** How many are still outside it. */
    public function waiting(Event $event): int
    {
        return QueueTicket::where('event_id', $event->id)
            ->whereIn('status', ['lobby', 'queued'])
            ->count();
    }

    /**
     * Somebody who finished, or went away.
     *
     * Called when a booking is placed: the buyer has what they came for, and holding their slot
     * open afterwards would keep somebody else outside for nothing.
     */
    public function leave(QueueTicket $ticket): void
    {
        if ($ticket->isWaiting() || 'admitted' === $ticket->status) {
            $ticket->forceFill(['status' => 'left', 'left_at' => now()])->save();
        }
    }

    /** The place after the last one taken. Read inside the lock, never cached. */
    private function nextPlace(Event $event): int
    {
        return 1 + (int) QueueTicket::where('event_id', $event->id)->max('place');
    }

    /**
     * Serialise every door on one event behind the others.
     *
     * Keyed on the event alone, so a rush on tonight's final never delays a matinee in another
     * town — and the same key shape as everything else that counts against a limit here.
     */
    private function lock(Event $event): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['queue:'.$event->id],
        );
    }
}
