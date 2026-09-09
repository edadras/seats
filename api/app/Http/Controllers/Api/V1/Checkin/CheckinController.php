<?php

namespace App\Http\Controllers\Api\V1\Checkin;

use App\Domain\Checkin\CheckinService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CheckinDevice;
use App\Domain\Events\EventStats;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CheckinController extends Controller
{
    public function __construct(
        private readonly CheckinService $checkins,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Pair a scanner. The pairing code is single-use and short-lived, so a code left on a printout
     * cannot be used to enrol a device days later.
     */
    public function token(Request $request)
    {
        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'max:100'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $device = $this->tenantContext->runUnscoped(
            fn () => CheckinDevice::with('tenant')
                ->where('pairing_code_hash', hash('sha256', $data['pairing_code']))
                ->where('status', 'pending')
                ->where('pairing_expires_at', '>', now())
                ->first()
        );

        if (! $device) {
            throw ApiException::unauthorized('invalid_pairing_code', 'This pairing code is unknown or expired.');
        }

        $this->tenantContext->set($device->tenant);

        $device->forceFill([
            'name' => $data['device_name'],
            'status' => 'active',
            'paired_at' => now(),
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
        ])->save();

        $token = $device->createToken('checkin-device', ['checkin']);

        return response()->json([
            'token' => $token->plainTextToken,
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'event_ids' => $device->events()->pluck('events.id'),
            ],
            'events' => $device->events()->get()->map(fn (Event $e) => $this->presentEvent($e)),
        ]);
    }

    public function events(Request $request)
    {
        $device = $request->attributes->get('checkin_device');

        return response()->json([
            'data' => $device->events()->get()->map(fn (Event $e) => $this->presentEvent($e)),
        ]);
    }

    public function scan(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'event_id' => ['required', 'uuid'],
            'scanned_at' => ['sometimes', 'date'],
            'client_scan_id' => ['sometimes', 'string', 'max:100'],
        ]);

        $device = $request->attributes->get('checkin_device');
        $event = $this->authorizedEvent($device, $data['event_id']);

        $result = $this->checkins->scan(
            $event,
            $data['token'],
            $device,
            isset($data['scanned_at']) ? Carbon::parse($data['scanned_at']) : null,
            $data['client_scan_id'] ?? null,
        );

        // Always 200: "already used" is a successful answer to a valid question, not an HTTP
        // error. Scanner apps branch on `result`, which keeps offline replay simple.
        return response()->json($this->presentResult($result));
    }

    /**
     * Upload scans taken while the device was offline.
     *
     * Ordering by `scanned_at` before replaying is what makes the outcome match reality: whoever
     * physically walked in first should be the one recorded as admitted, regardless of which
     * device happened to reconnect first.
     */
    public function sync(Request $request)
    {
        $max = (int) config('seatmap.checkin.max_batch_scans');

        $data = $request->validate([
            'scans' => ['required', 'array', 'min:1', 'max:'.$max],
            'scans.*.token' => ['required', 'string', 'max:200'],
            'scans.*.event_id' => ['required', 'uuid'],
            'scans.*.scanned_at' => ['required', 'date'],
            'scans.*.client_scan_id' => ['required', 'string', 'max:100'],
        ]);

        $device = $request->attributes->get('checkin_device');

        $ordered = collect($data['scans'])
            ->map(fn ($scan, $index) => $scan + ['_index' => $index])
            ->sortBy(fn ($scan) => Carbon::parse($scan['scanned_at'])->getTimestampMs())
            ->values();

        $results = [];

        foreach ($ordered as $scan) {
            try {
                $event = $this->authorizedEvent($device, $scan['event_id']);

                $result = $this->checkins->scan(
                    $event,
                    $scan['token'],
                    $device,
                    Carbon::parse($scan['scanned_at']),
                    $scan['client_scan_id'],
                    offline: true,
                );

                $results[$scan['_index']] = $this->presentResult($result);
            } catch (ApiException $e) {
                // One bad row must not discard a batch the device cannot easily reconstruct.
                $results[$scan['_index']] = ['result' => 'invalid', 'error' => $e->errorCode()];
            }
        }

        ksort($results);

        return response()->json(['results' => array_values($results)]);
    }

    public function stats(Request $request, string $eventId)
    {
        $device = $request->attributes->get('checkin_device');
        $event = $this->authorizedEvent($device, $eventId);

        return response()->json(app(EventStats::class)->for($event));
    }

    private function authorizedEvent(CheckinDevice $device, string $eventId): Event
    {
        $event = Event::find($eventId);

        if (! $event || ! $device->mayScan($eventId)) {
            throw ApiException::forbidden('This device is not authorised to scan that event.', 'device_not_authorised');
        }

        return $event;
    }

    private function presentResult(array $result): array
    {
        /** @var Ticket|null $ticket */
        $ticket = $result['ticket'];

        return array_filter([
            'result' => $result['result'],
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'status' => $ticket->status,
                'holder_name' => $ticket->holder_name,
                'seat' => [
                    'section' => $ticket->allocation?->section_name,
                    'row' => $ticket->allocation?->row_name,
                    'label' => $ticket->allocation?->seat_label,
                ],
                // What kind of ticket this is, so the door knows to ask for the student card the
                // discount was given for. Null on an event that sells one kind, and the scanner
                // then shows nothing rather than an empty field.
                'ticket_type' => $ticket->allocation?->ticket_type_name,
                /*
                 * The window this person was sold into, and whether they are inside it.
                 *
                 * Shown, not enforced. A scanner that refused a valid ticket because somebody
                 * arrived ten minutes early would put a queue at the door with no way out of it,
                 * and the person holding the tablet is far better placed to decide than a clock
                 * on a server. What timed entry actually enforces is the selling: a window only
                 * ever holds as many people as it was given.
                 */
                'entry' => $ticket->allocation?->entry_starts_at ? [
                    'starts_at' => $ticket->allocation->entry_starts_at->toIso8601String(),
                    'ends_at' => $ticket->allocation->entry_ends_at?->toIso8601String(),
                    'state' => $this->entryState($ticket->allocation),
                ] : null,
            ] : null,
            'first_scan' => $result['first_scan'],
        ], fn ($v) => $v !== null);
    }

    /** `early`, `on_time` or `late` — the sentence the door needs, not a decision it must obey. */
    private function entryState(\App\Models\Allocation $allocation): string
    {
        $now = now();

        if ($now->lessThan($allocation->entry_starts_at)) {
            return 'early';
        }

        if ($allocation->entry_ends_at && $now->greaterThan($allocation->entry_ends_at)) {
            return 'late';
        }

        return 'on_time';
    }

    private function presentEvent(Event $event): array
    {
        return [
            'public_id' => $event->public_id,
            'id' => $event->id,
            'name' => $event->name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'status' => $event->status,
        ];
    }
}
