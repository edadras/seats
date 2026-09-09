<?php

namespace App\Domain\Events;

use App\Exceptions\ApiException;
use App\Models\EntrySlot;
use App\Models\Event;
use App\Support\Locale\Dates;
use Illuminate\Support\Facades\DB;

/**
 * Arrival windows, and how full each one is.
 *
 * A timed-entry event is not sold by the chair. The constraint is how many people may be inside
 * between ten and half past, which is a sum against a limit rather than one row per seat — so the
 * accounting here is the same shape as a standing area's: count what is held and what is sold,
 * compare, and do it under a lock while the decision is being made.
 *
 * Whether an event *is* timed entry is answered by whether it has any open slot. There is no
 * separate switch to leave in the wrong position: an organiser who adds windows has timed entry,
 * and one who deletes them all does not.
 */
class EntrySlots
{
    /** What the buyer chooses from, and what the panel shows. */
    public function forEvent(Event $event, bool $openOnly = false): array
    {
        $slots = EntrySlot::where('event_id', $event->id)
            ->when($openOnly, fn ($query) => $query->where('status', 'open'))
            ->orderBy('starts_at')
            ->get();

        if ($slots->isEmpty()) {
            return [];
        }

        $taken = $this->taken($event);

        return $slots->map(function (EntrySlot $slot) use ($taken, $event) {
            $used = (int) ($taken[$slot->id] ?? 0);
            $capacity = $slot->capacity;

            return [
                'id' => $slot->id,
                'label' => $slot->label ?: $this->clock($slot, $event),
                // The label as it was actually typed — usually nothing. The panel needs to know
                // the difference, or an edit would save the clock it was shown as a real name.
                'name' => $slot->label,
                'starts_at' => $slot->starts_at?->toIso8601String(),
                'ends_at' => $slot->ends_at?->toIso8601String(),
                'capacity' => $capacity,
                'taken' => $used,
                // Null capacity is a window that limits nothing, so it never runs out. Reporting
                // it as zero left would close a window the organiser deliberately left open.
                'remaining' => null === $capacity ? null : max(0, $capacity - $used),
                'status' => $slot->status,
                'sold_out' => null !== $capacity && $used >= $capacity,
            ];
        })->all();
    }

    /** Does this event sell by arrival window at all? */
    public function required(Event $event): bool
    {
        return EntrySlot::where('event_id', $event->id)->where('status', 'open')->exists();
    }

    /**
     * The slot a hold is for, checked before anything is locked.
     *
     * Refusing here rather than inside the transaction means a buyer who picked a window that
     * filled while they were choosing gets a sentence about that window, not a failed booking.
     */
    public function resolve(Event $event, ?string $slotId, int $places): ?EntrySlot
    {
        if (! $this->required($event)) {
            // Not a timed-entry event. A slot id sent anyway is a caller's mistake worth saying
            // out loud rather than ignoring — silently dropping it would print a ticket with no
            // arrival time on it and nobody would know why.
            if ($slotId) {
                throw ApiException::unprocessable(
                    'entry_slot_not_offered',
                    'This event does not sell timed entry.'
                );
            }

            return null;
        }

        if (! $slotId) {
            throw ApiException::unprocessable(
                'entry_slot_required',
                'Choose an arrival time before booking.'
            );
        }

        $slot = EntrySlot::where('event_id', $event->id)->find($slotId);

        if (! $slot) {
            throw ApiException::notFound('Unknown arrival time.', 'unknown_entry_slot');
        }

        if (! $slot->isOpen()) {
            throw ApiException::conflict('entry_slot_closed', 'That arrival time is no longer offered.');
        }

        if (null !== $slot->capacity && $this->remaining($event, $slot) < $places) {
            throw ApiException::conflict('entry_slot_full', 'That arrival time is full.');
        }

        return $slot;
    }

    /**
     * How many more may come in during this window.
     *
     * Called twice on the way to a booking: once to answer the buyer, and again inside the
     * transaction under the lock, where the answer is the one that counts.
     */
    public function remaining(Event $event, EntrySlot $slot): int
    {
        if (null === $slot->capacity) {
            return PHP_INT_MAX;
        }

        return max(0, $slot->capacity - (int) ($this->taken($event, $slot->id)[$slot->id] ?? 0));
    }

    /**
     * People already held or sold, per slot.
     *
     * One query for the whole event rather than one per window: a day sold in half-hours is
     * twenty rows on a screen, and twenty round trips to draw it is twenty too many.
     *
     * @return array<string, int>
     */
    public function taken(Event $event, ?string $slotId = null): array
    {
        $bindings = ['event_id' => $event->id, 'tenant_id' => $event->tenant_id];
        $only = '';

        if ($slotId) {
            $only = ' AND s.id = :slot_id';
            $bindings['slot_id'] = $slotId;
        }

        // Raw, so the two halves are one statement and the tenant is stated rather than assumed:
        // this runs outside the global scope that normally makes a cross-tenant read impossible.
        $rows = DB::select(<<<SQL
            SELECT s.id,
                COALESCE((
                    SELECT SUM(hi.quantity) FROM hold_items hi
                    JOIN holds hd ON hd.id = hi.hold_id
                    WHERE hd.entry_slot_id = s.id AND hi.released_at IS NULL
                      AND hd.status = 'active' AND hd.expires_at > NOW()
                ), 0) + COALESCE((
                    SELECT SUM(al.quantity) FROM allocations al
                    WHERE al.entry_slot_id = s.id AND al.status = 'active'
                ), 0) AS taken
            FROM entry_slots s
            WHERE s.event_id = :event_id AND s.tenant_id = :tenant_id{$only}
        SQL, $bindings);

        $taken = [];

        foreach ($rows as $row) {
            $taken[$row->id] = (int) $row->taken;
        }

        return $taken;
    }

    /**
     * Serialise every buyer of one window behind the others.
     *
     * The same defence a standing area gets, for the same reason: the invariant is a sum against a
     * limit and no unique index can express it, so the total is recomputed under a transaction
     * lock keyed on this window alone. A rush on the ten o'clock never delays the eleven.
     */
    public function lock(Event $event, EntrySlot $slot): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['slot:'.$event->id.':'.$slot->id],
        );
    }

    /**
     * "Entry 10:00 – 10:30", spelled once for every surface that prints it.
     *
     * In the venue's own clock, always. A window is a time to stand outside a building, and a
     * ticket that said one hour while the picker said another — because one formatted in UTC and
     * the other in the event's zone — would put somebody at a door sixty minutes early.
     */
    public static function window(
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
        ?string $timezone = null,
    ): ?string {
        if (! $from) {
            return null;
        }

        return __('site.entry.between', [
            'from' => self::clockAt($from, $timezone),
            'to' => self::clockAt($to ?: $from, $timezone),
        ]);
    }

    /**
     * The same window without the word in front of it.
     *
     * For a column already headed "Entry", where repeating it on every row is noise.
     */
    public static function range(
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
        ?string $timezone = null,
    ): ?string {
        if (! $from) {
            return null;
        }

        return self::clockAt($from, $timezone).' – '.self::clockAt($to ?: $from, $timezone);
    }

    /** "10:00 – 10:30", in the venue's clock, for a window nobody bothered to name. */
    private function clock(EntrySlot $slot, Event $event): string
    {
        return self::clockAt($slot->starts_at, $event->timezone)
            .' – '.self::clockAt($slot->ends_at, $event->timezone);
    }

    private static function clockAt(\DateTimeInterface $when, ?string $timezone): string
    {
        $zone = $timezone ?: config('app.timezone');

        return Dates::pattern(
            (new \DateTimeImmutable($when->format('c')))->setTimezone(new \DateTimeZone($zone)),
            'HH:mm',
        );
    }
}
