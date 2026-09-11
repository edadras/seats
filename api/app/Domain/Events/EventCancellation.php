<?php

namespace App\Domain\Events;

use App\Exceptions\ApiException;
use App\Jobs\SettleCancelledEvent;
use App\Jobs\TellBuyersTheDateMoved;
use App\Models\EntrySlot;
use App\Models\Event;
use App\Models\Hold;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The night that is off, and the night that moved.
 *
 * `cancelled` was already a status an event could be set to. It stopped the event selling and did
 * nothing at all about the money already taken or the several hundred people holding tickets, so
 * an organiser calling off a show had to find every booking and refund it one at a time. That is
 * not a thing anybody does correctly at nine in the evening.
 *
 * Two halves, deliberately. Stopping the sale is immediate and happens in the request: nothing may
 * be sold for a cancelled night, not for the second it takes to answer. Refunding and telling
 * people is the long half — an arena is twenty thousand bookings — and runs as a job, chunked, so
 * a cancellation is not a request that times out halfway through the refunds.
 *
 * Moving a date keeps the tickets. That is the whole difference, and it is why it is a separate
 * path rather than a cancellation followed by a re-sale: the seats stay sold, the codes stay
 * valid, and the arrival windows move with the day.
 */
class EventCancellation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Call the night off.
     *
     * @param  bool  $refund  false where the money was taken somewhere this platform cannot reach
     *                        and the organiser will return it themselves. The seats are released
     *                        and the tickets voided either way — a valid ticket to a cancelled
     *                        event is a person at a locked door.
     */
    public function cancel(Event $event, string $reason, bool $refund = true, bool $notify = true): Event
    {
        if ($event->isCancelled()) {
            return $event;
        }

        DB::transaction(function () use ($event, $reason) {
            $event->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            // Carts in flight are released now rather than left to their own expiry: those seats
            // are not for sale, and a buyer mid-checkout should be told rather than charged.
            Hold::where('event_id', $event->id)
                ->where('status', 'active')
                ->update(['status' => 'released', 'released_at' => now(), 'updated_at' => now()]);

            DB::table('hold_items')
                ->where('event_id', $event->id)
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            $event->bumpAvailabilityVersion();
        });

        $this->audit->record('event.cancelled', $event, [
            'event' => $event->name,
            'reason' => $reason,
            'refunding' => $refund,
            'telling_buyers' => $notify,
        ]);

        SettleCancelledEvent::dispatch($event->id, $event->tenant_id, $reason, $refund, $notify);

        /*
         * And every system the organiser has connected.
         *
         * Told here rather than from the controller, so a cancellation is announced whichever door
         * it came in by — the panel, a console operator, or the job that cancels a whole run.
         */
        $this->publish($event, 'event.cancelled', ['reason' => $reason, 'refunding' => $refund]);

        return $event->refresh();
    }

    /**
     * Move it to another night.
     *
     * Everything sold stays sold. The arrival windows shift by exactly the same interval the event
     * did — a timetable of half-hours from ten in the morning is a timetable of half-hours from ten
     * in the morning on the new day too, and leaving them on the old one would print tickets for a
     * date that has passed.
     */
    public function reschedule(
        Event $event,
        Carbon $startsAt,
        ?Carbon $endsAt = null,
        string $reason = '',
        bool $notify = true,
    ): Event {
        if ($event->isCancelled()) {
            throw ApiException::conflict(
                'event_cancelled',
                'A cancelled event cannot be moved. Publish it again first.'
            );
        }

        $was = $event->starts_at;

        if (! $was) {
            throw ApiException::unprocessable(
                'event_has_no_date',
                'This event has no date to move.'
            );
        }

        $shift = $was->diffInSeconds($startsAt, false);

        DB::transaction(function () use ($event, $startsAt, $endsAt, $was, $shift) {
            $event->forceFill([
                'starts_at' => $startsAt,
                // A run that had an end keeps its length unless a new one is given; an organiser
                // moving a date is not usually also changing how long the thing lasts.
                'ends_at' => $endsAt ?: ($event->ends_at ? $event->ends_at->copy()->addSeconds($shift) : null),
                // Kept rather than overwritten: this is the answer to the only question anybody
                // asks afterwards — "wasn't this on the Tuesday?"
                'rescheduled_from' => $event->rescheduled_from ?: $was,
                'rescheduled_at' => now(),
            ])->save();

            foreach (EntrySlot::where('event_id', $event->id)->get() as $slot) {
                EntrySlot::whereKey($slot->id)->update([
                    'starts_at' => $slot->starts_at->copy()->addSeconds($shift),
                    'ends_at' => $slot->ends_at->copy()->addSeconds($shift),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->audit->record('event.rescheduled', $event, [
            'event' => $event->name,
            'from' => $was->toIso8601String(),
            'to' => $startsAt->toIso8601String(),
            'reason' => $reason,
            'telling_buyers' => $notify,
        ]);

        if ($notify) {
            TellBuyersTheDateMoved::dispatch($event->id, $event->tenant_id, $was->toIso8601String(), $reason);
        }

        $this->publish($event, 'event.rescheduled', [
            'was' => $was->toIso8601String(),
            'now' => $startsAt->toIso8601String(),
            'reason' => $reason,
        ]);

        return $event->refresh();
    }

    /**
     * Tell whatever the organiser has connected.
     *
     * Never allowed to fail the thing it is reporting: a shop whose webhook could not be queued is
     * a shop that is out of date, and an event that could not be cancelled because of it would be
     * an event still selling seats for a night that is not happening.
     */
    private function publish(Event $event, string $type, array $data): void
    {
        try {
            app(\App\Domain\Webhooks\WebhookDispatcher::class)->dispatch($event->tenant_id, $type, [
                'event_public_id' => $event->public_id,
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ] + $data);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
