<?php

namespace App\Domain\Waitlist;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Messaging\MessageDispatcher;
use App\Models\Event;
use App\Models\Site;
use App\Models\WaitingListEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Dates;
use App\Support\Locale\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Joining the queue for a sold-out night, and being told when a seat comes back.
 *
 * The rule that shapes everything here is first asked, first told. So notifications go out in the
 * order people joined, and each person gets a window in which the seat is theirs to buy — not a
 * reservation, because holding seats for somebody who may never open the email is how a sold-out
 * night ends up half empty, but long enough that a link in an email is worth following.
 *
 * Nobody is thrown off the list for missing their turn. A window that runs out puts them back in
 * the queue, behind anybody who has not had a turn yet, and they come round again on the next
 * release.
 *
 * That last sentence used to be a comment rather than a behaviour. The notifier only ever read
 * rows marked `waiting`, so somebody told once and asleep at three in the morning stayed at
 * `notified` for ever and was never told again — and `converted`, which the API would let an
 * organiser filter by, was set by nothing at all. {@see reopen()} and {@see bought()} are those
 * two states made real.
 */
class WaitingList
{
    /** How long a place stays open once somebody has been told about it. */
    public const CLAIM_MINUTES = 120;

    /**
     * How many unanswered turns somebody gets before the platform stops writing to them.
     *
     * Coming round again cannot be unlimited. One person on a list, one seat free and nobody buying
     * it would otherwise be an email every two hours until the doors open, which is not a waiting
     * list, it is a nuisance. Three is enough to cover a night's sleep and a working day; after
     * that the entry goes quiet rather than away, and the organiser can see exactly why.
     */
    public const MAX_TURNS = 3;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly MessageDispatcher $messages,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Put somebody on the list.
     *
     * Asking twice is not asking harder: a second attempt from the same address returns the same
     * place in the queue rather than a second one, and does not move them to the back.
     */
    public function join(Event $event, array $person): WaitingListEntry
    {
        $email = mb_strtolower(trim($person['email']));

        try {
            return DB::transaction(fn () => WaitingListEntry::create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'name' => trim($person['name']),
                'email' => $email,
                'phone' => $person['phone'] ?? null,
                'quantity' => max(1, min(20, (int) ($person['quantity'] ?? 1))),
                'locale' => $person['locale'] ?? app()->getLocale(),
                'status' => 'waiting',
                'token' => WaitingListEntry::newToken(),
            ]));
        } catch (UniqueConstraintViolationException) {
            $existing = WaitingListEntry::where('event_id', $event->id)
                ->where('email', $email)
                ->firstOrFail();

            // Somebody who left, or who went quiet and has come back of their own accord, is
            // asking again — and that is a new request, with its turns back.
            if (in_array($existing->status, ['left', 'lapsed'], true)) {
                $existing->forceFill([
                    'status' => 'waiting',
                    'left_at' => null,
                    'lapsed_at' => null,
                    'times_told' => 0,
                    'claim_expires_at' => null,
                    'quantity' => max(1, min(20, (int) ($person['quantity'] ?? 1))),
                ])->save();
            }

            return $existing;
        }
    }

    public function leave(WaitingListEntry $entry): WaitingListEntry
    {
        $entry->forceFill(['status' => 'left', 'left_at' => now()])->save();

        return $entry;
    }

    /** How many places are free right now, seats and standing together. */
    public function freePlaces(Event $event): int
    {
        $free = 0;

        foreach ($this->availability->forEvent($event) as $seat) {
            if ('available' === $seat['state']) {
                $free++;
            }
        }

        foreach ($this->availability->capacityForEvent($event) as $area) {
            $free += max(0, (int) $area['remaining']);
        }

        return $free;
    }

    /**
     * Put everybody whose turn has run out back in the queue.
     *
     * Run before anybody new is told, because the two are the same question: a window that closed
     * without a sale released nothing, but it did free the *promise*, and the person it was made
     * to is owed another turn rather than silence.
     *
     * They keep their place — the queue is still ordered by when they asked — but they go behind
     * anybody who has not had a turn at all, because a first turn is worth more than a fourth.
     * After {@see MAX_TURNS} unanswered ones the entry goes quiet: it stays on the list and on the
     * organiser's screen, and the platform stops writing to it.
     *
     * @return int how many came back into the queue
     */
    public function reopen(Event $event): int
    {
        $lapsed = WaitingListEntry::where('event_id', $event->id)
            ->where('status', 'notified')
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<=', now())
            ->get();

        $back = 0;

        foreach ($lapsed as $entry) {
            $spent = $entry->times_told >= self::MAX_TURNS;

            $entry->forceFill($spent
                ? ['status' => 'lapsed', 'lapsed_at' => now(), 'claim_expires_at' => null]
                : ['status' => 'waiting', 'claim_expires_at' => null])->save();

            $back += $spent ? 0 : 1;
        }

        return $back;
    }

    /**
     * They bought a seat, so they are off the queue without ever having to say so.
     *
     * Matched on the address they joined with, which is the only handle a waiting list has: a
     * booking carries an email and nothing that points back at a queue. Somebody who bought under
     * a different address stays waiting, and that is the right way round — the platform should not
     * guess that two addresses are one person.
     *
     * Called when an order confirms. `converted` was a status this platform would let an organiser
     * filter by and set nowhere, so the honest answer to "who on my list actually bought" was
     * always an empty page.
     */
    public function bought(Event $event, ?string $email): ?WaitingListEntry
    {
        $email = mb_strtolower(trim((string) $email));

        if ('' === $email) {
            return null;
        }

        $entry = WaitingListEntry::where('event_id', $event->id)
            ->where('email', $email)
            ->whereIn('status', ['waiting', 'notified', 'lapsed'])
            ->first();

        if (! $entry) {
            return null;
        }

        $entry->forceFill([
            'status' => 'converted',
            'converted_at' => now(),
            'claim_expires_at' => null,
        ])->save();

        return $entry;
    }

    /**
     * Tell as many people as there are places for, oldest first.
     *
     * Places already promised to somebody whose window is still open are subtracted before anybody
     * else is told, or a single returned seat would be offered to the whole queue at once and
     * nine people out of ten would follow a link to a sold-out page.
     *
     * @return int how many were told
     */
    public function notify(Event $event, Site $site, ?int $limit = null): int
    {
        // Before anything else, and whether or not there is a seat to offer: a turn that has run
        // out has run out, and leaving those rows at `notified` is what made them unreachable.
        $this->reopen($event);

        $free = $this->freePlaces($event);

        if ($free < 1) {
            return 0;
        }

        $promised = (int) WaitingListEntry::where('event_id', $event->id)
            ->where('status', 'notified')
            ->where('claim_expires_at', '>', now())
            ->sum('quantity');

        $spare = $free - $promised;

        if ($spare < 1) {
            return 0;
        }

        $queue = WaitingListEntry::where('event_id', $event->id)
            ->where('status', 'waiting')
            // Somebody who has never been told comes before somebody on their third turn, and
            // within each of those it is still first asked, first told.
            ->orderBy('times_told')
            ->orderBy('created_at')
            ->limit($limit ?: 100)
            ->get();

        $told = 0;

        foreach ($queue as $entry) {
            if ($spare < 1) {
                break;
            }

            // Somebody who asked for four when three are left is still worth telling: they can buy
            // three, or wait. Refusing to tell them would leave a seat unsold on principle.
            $spare -= $entry->quantity;

            $entry->forceFill([
                'status' => 'notified',
                'notified_at' => now(),
                'claim_expires_at' => now()->addMinutes(self::CLAIM_MINUTES),
                'times_told' => $entry->times_told + 1,
            ])->save();

            $this->tell($event, $site, $entry);
            $told++;
        }

        if ($told > 0) {
            $this->audit->record('waitlist.notified', $event, [
                'event' => $event->name,
                'told' => $told,
                'free' => $free,
            ]);

            /*
             * And whatever the organiser has connected.
             *
             * One announcement for the round rather than one per person: this fires by itself when
             * a seat frees, and a marketing system that wants to know "are seats coming back on
             * this night" is asking about the night, not about a queue it cannot see anyway.
             */
            try {
                app(\App\Domain\Webhooks\WebhookDispatcher::class)->dispatch($event->tenant_id, 'waitlist.offered', [
                    'event_public_id' => $event->public_id,
                    'name' => $event->name,
                    'told' => $told,
                    'free_places' => $free,
                    'claim_minutes' => self::CLAIM_MINUTES,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $told;
    }

    private function tell(Event $event, Site $site, WaitingListEntry $entry): void
    {
        $this->messages->announce(
            'waitlist.available',
            ['email' => $entry->email, 'phone' => $entry->phone],
            [
                'buyer' => $entry->name,
                // Their own language: they gave one when they joined the list, and this whole
                // message is being written in it.
                'event' => $event->nameFor($entry->locale),
                'venue' => $event->venue?->name ?? '',
                'starts' => $event->starts_at
                    ? Dates::longWhen($event->starts_at->setTimezone($event->timezone), $entry->locale)
                    : '',
                'quantity' => Money::number($entry->quantity, $entry->locale),
                'hours' => Money::number(intdiv(self::CLAIM_MINUTES, 60), $entry->locale),
                'site' => $site->name,
                'link' => $site->url('/events/'.$event->public_id.'?from=waitlist'),
                // Every message that puts somebody on a list has to carry the way off it.
                'leave' => $site->url('/waiting-list/'.$entry->token.'/leave'),
            ],
            $entry->locale,
            ['event_id' => $event->id],
        );
    }
}
