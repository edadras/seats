<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CheckinDevice;
use App\Models\Event;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The phones and tablets at the doors.
 *
 * This is the missing half of a feature that already existed. The scanner has always paired by
 * exchanging a single-use code for a device token — the README says so, and the endpoint that
 * spends the code has been there from the start — but nothing on this platform could ever *issue*
 * one. `devices.manage` was defined and granted to four roles and referred to by nothing. So a
 * scanner could be built, translated, tested and deployed, and an organiser still had no way to
 * put it in a volunteer's hand.
 *
 * What a duty manager needs from this screen, in the order they need it: which devices exist, which
 * nights each may scan, whether it has been seen lately, and — the reason this screen matters more
 * than it used to — when it last took a copy of the door list. A tablet whose copy was taken at
 * four o'clock has never heard of the forty tickets sold since, and the only person who can notice
 * that is looking at this screen before the house opens.
 */
class ScannerController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'devices.manage');

        return response()->json([
            'data' => CheckinDevice::with('events')->orderBy('created_at')->get()
                ->map(fn (CheckinDevice $device) => $this->present($device)),
            // The nights there are to grant, so the panel never keeps its own copy of the diary.
            'events' => Event::whereIn('status', ['published', 'draft'])
                ->orderByRaw('starts_at is null, starts_at')
                ->limit(200)
                ->get()
                ->map(fn (Event $event) => [
                    'id' => $event->id,
                    'name' => $event->nameFor(),
                    'starts_at' => $event->starts_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Make a device, and hand back the one code that will ever open it.
     *
     * The code is stored hashed and returned exactly once, like every other credential this
     * platform issues. It expires on its own, because the failure this prevents is a printout with
     * a live pairing code on it left on a desk after the run has finished.
     */
    public function store(Request $request)
    {
        $this->authorize($request, 'devices.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'event_ids' => ['sometimes', 'array', 'max:200'],
            'event_ids.*' => ['uuid'],
        ]);

        $code = $this->newCode();

        $device = CheckinDevice::create([
            'name' => $data['name'],
            'status' => 'pending',
            'pairing_code_hash' => hash('sha256', $code),
            'pairing_expires_at' => now()->addMinutes((int) config('seatmap.checkin.pairing_code_ttl_minutes')),
        ]);

        $this->grant($device, $data['event_ids'] ?? []);

        $this->audit->record('checkin_device.created', $device, ['name' => $device->name]);

        return response()->json([
            'data' => $this->present($device->fresh('events')),
            'pairing_code' => $code,
        ], 201);
    }

    public function update(Request $request, CheckinDevice $device)
    {
        $this->authorize($request, 'devices.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'event_ids' => ['sometimes', 'array', 'max:200'],
            'event_ids.*' => ['uuid'],
        ]);

        if (isset($data['name'])) {
            $device->update(['name' => $data['name']]);
        }

        if (array_key_exists('event_ids', $data)) {
            $this->grant($device, $data['event_ids']);
        }

        $this->audit->record('checkin_device.updated', $device, ['name' => $device->name]);

        return response()->json(['data' => $this->present($device->fresh('events'))]);
    }

    /**
     * A fresh code for a device somebody has to pair again.
     *
     * It also takes the old token away. A scanner being re-paired is a scanner somebody has lost
     * track of, and leaving the previous one working is how a phone in a drawer keeps admitting
     * people.
     */
    public function recode(Request $request, CheckinDevice $device)
    {
        $this->authorize($request, 'devices.manage');

        $code = $this->newCode();

        $device->tokens()->delete();

        $device->forceFill([
            'status' => 'pending',
            'paired_at' => null,
            'pairing_code_hash' => hash('sha256', $code),
            'pairing_expires_at' => now()->addMinutes((int) config('seatmap.checkin.pairing_code_ttl_minutes')),
        ])->save();

        $this->audit->record('checkin_device.recoded', $device, ['name' => $device->name]);

        return response()->json([
            'data' => $this->present($device->fresh('events')),
            'pairing_code' => $code,
        ]);
    }

    /**
     * Take a scanner out of service.
     *
     * Deleted rather than disabled, and its token with it: a device is a phone somebody handed
     * back, not a record anybody audits. What it scanned stays — those are check-ins, and they
     * belong to the event.
     */
    public function destroy(Request $request, CheckinDevice $device)
    {
        $this->authorize($request, 'devices.manage');

        $device->tokens()->delete();

        $this->audit->record('checkin_device.removed', $device, ['name' => $device->name]);

        $device->delete();

        return response()->json(['deleted' => true]);
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * Replace the set of nights this device may scan.
     *
     * An event that is not this account's is silently left out rather than refused: the id came
     * from a list this account was given, so a stale one is a stale panel and not an attack, and
     * losing the whole grant over one of them would be worse.
     *
     * @param  list<string>  $eventIds
     */
    private function grant(CheckinDevice $device, array $eventIds): void
    {
        $events = Event::whereIn('id', $eventIds)->get();

        foreach ($device->events()->get() as $held) {
            if (! $events->contains('id', $held->id)) {
                $device->revokeAccessTo($held);
            }
        }

        foreach ($events as $event) {
            $device->grantAccessTo($event);
        }
    }

    /** Readable aloud across a foyer, and 80 bits of randomness. */
    private function newCode(): string
    {
        return implode('-', [
            Str::upper(Str::random(4)),
            Str::upper(Str::random(4)),
            Str::upper(Str::random(4)),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(CheckinDevice $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'status' => $device->status,
            'paired_at' => $device->paired_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'pairing_expires_at' => $device->pairing_expires_at?->toIso8601String(),
            // The one fact that decides whether this device will work when the wifi does not.
            'door_list_taken_at' => $device->door_list_taken_at?->toIso8601String(),
            'event_ids' => $device->events->pluck('id')->values(),
        ];
    }
}
