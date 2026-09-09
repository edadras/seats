<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Events\EntrySlots;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\EntrySlot;
use App\Models\Event;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The arrival windows an event sells.
 *
 * Saved as a whole list, like the ticket types and the questions: a day's timetable is one decision
 * about the event, and editing it a window at a time is how a Saturday ends up with a gap in it.
 *
 * A window somebody has already been sold into is never deleted. Their ticket says to arrive
 * between ten and half past, and removing the row would leave the door with an arrival time it
 * cannot look up. Closing it stops it being sold and leaves what was sold intact.
 *
 * There is also a generator, because typing "10:00 – 10:30" twenty times is the step where the
 * eleven o'clock gets a capacity of 3 by accident.
 */
class EntrySlotController extends Controller
{
    public function __construct(
        private readonly EntrySlots $slots,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        $sold = $this->soldCounts($event);

        return response()->json([
            'data' => collect($this->slots->forEvent($event))
                ->map(fn (array $slot) => $slot + ['sold' => $sold[$slot['id']] ?? 0])
                ->values(),
            'timezone' => $event->timezone,
        ]);
    }

    public function replace(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'slots' => ['present', 'array', 'max:96'],
            'slots.*.id' => ['nullable', 'uuid'],
            'slots.*.label' => ['nullable', 'string', 'max:80'],
            'slots.*.starts_at' => ['required', 'date'],
            'slots.*.ends_at' => ['required', 'date', 'after:slots.*.starts_at'],
            // Null is a window that limits nothing — a staggered arrival time with one hall behind
            // it — and is different from a window with no room left.
            'slots.*.capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'slots.*.status' => ['sometimes', Rule::in(['open', 'closed'])],
        ]);

        $this->assertNoOverlap($data['slots']);

        $sold = $this->soldCounts($event);
        $existing = EntrySlot::where('event_id', $event->id)->get()->keyBy('id');
        $keptIds = array_values(array_filter(array_column($data['slots'], 'id')));

        $blocked = $existing->keys()
            ->diff($keptIds)
            ->filter(fn (string $id) => ($sold[$id] ?? 0) > 0)
            ->values();

        if ($blocked->isNotEmpty()) {
            throw ApiException::conflict(
                'entry_slot_sold',
                'An arrival time somebody has been sold cannot be removed. Close it instead.',
                ['entry_slot_ids' => $blocked->all()],
            );
        }

        DB::transaction(function () use ($event, $data, $existing, $keptIds) {
            EntrySlot::where('event_id', $event->id)
                ->whereNotIn('id', $keptIds ?: ['00000000-0000-0000-0000-000000000000'])
                ->delete();

            foreach ($data['slots'] as $row) {
                $attributes = [
                    'label' => ($row['label'] ?? null) ?: null,
                    'starts_at' => Carbon::parse($row['starts_at']),
                    'ends_at' => Carbon::parse($row['ends_at']),
                    'capacity' => $row['capacity'] ?? null,
                    'status' => $row['status'] ?? 'open',
                ];

                $slot = ($row['id'] ?? null) ? $existing->get($row['id']) : null;

                if ($slot) {
                    EntrySlot::whereKey($slot->id)->update($attributes + ['updated_at' => now()]);

                    continue;
                }

                EntrySlot::create($attributes + [
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                ]);
            }
        });

        $this->audit->record('event.entry_slots_set', $event, [
            'count' => count($data['slots']),
        ]);

        return $this->index($request, $event);
    }

    /**
     * Fill a day with windows of one length.
     *
     * Not saved here: it hands back rows the screen shows, so an organiser sees what they are
     * about to create and can change one of them before anything exists. A generator that wrote
     * straight to the database would be a generator nobody dares press twice.
     */
    public function generate(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $from = Carbon::parse($data['starts_at']);
        $until = Carbon::parse($data['ends_at']);
        $slots = [];

        // A window that would run past the end is not made: an organiser who says the last entry
        // is at four means four, not four twenty.
        while ($from->copy()->addMinutes($data['minutes'])->lessThanOrEqualTo($until)) {
            $ends = $from->copy()->addMinutes($data['minutes']);

            $slots[] = [
                'id' => null,
                'label' => null,
                'starts_at' => $from->toIso8601String(),
                'ends_at' => $ends->toIso8601String(),
                'capacity' => $data['capacity'] ?? null,
                'status' => 'open',
            ];

            $from = $ends;

            if (count($slots) >= 96) {
                break;
            }
        }

        if ([] === $slots) {
            throw ApiException::unprocessable(
                'window_too_short',
                'That period is shorter than one arrival window.'
            );
        }

        return response()->json(['data' => $slots]);
    }

    /* --------------------------------------------------------------------------- helpers */

    /**
     * Two windows that overlap are two answers to "how many are inside at once".
     *
     * The whole point of timed entry is that a limit means something, and windows that overlap
     * make the limit unenforceable — the ten o'clock and the quarter past would each allow their
     * own hundred and the hall would hold two.
     */
    private function assertNoOverlap(array $slots): void
    {
        $ordered = collect($slots)
            ->map(fn (array $slot, int $index) => [
                'index' => $index,
                'from' => Carbon::parse($slot['starts_at']),
                'to' => Carbon::parse($slot['ends_at']),
            ])
            ->sortBy(fn (array $slot) => $slot['from']->getTimestamp())
            ->values();

        for ($i = 1; $i < $ordered->count(); $i++) {
            if ($ordered[$i]['from']->lessThan($ordered[$i - 1]['to'])) {
                throw ValidationException::withMessages([
                    'slots.'.$ordered[$i]['index'].'.starts_at' => __('panel.entrySlots.overlap'),
                ]);
            }
        }
    }

    /** @return array<string, int> */
    private function soldCounts(Event $event): array
    {
        return Allocation::where('event_id', $event->id)
            ->whereNotNull('entry_slot_id')
            ->where('status', 'active')
            ->selectRaw('entry_slot_id, sum(coalesce(quantity, 1)) as sold')
            ->groupBy('entry_slot_id')
            ->pluck('sold', 'entry_slot_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
