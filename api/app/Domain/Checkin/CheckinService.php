<?php

namespace App\Domain\Checkin;

use App\Models\Checkin;
use App\Models\CheckinDevice;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scanning.
 *
 * The whole point of this class is the conditional UPDATE in `claim()`. Two doors scanning the
 * same QR at the same instant must not both hear "valid" (threat T7), and no amount of
 * read-then-write in application code can promise that. So the database decides: exactly one
 * UPDATE affects a row, and the loser is told who admitted the ticket and when — which is what
 * the staff at the second door actually need.
 */
class CheckinService
{
    /**
     * @return array{result: string, ticket: ?Ticket, first_scan: ?array}
     */
    public function scan(
        Event $event,
        string $token,
        ?CheckinDevice $device = null,
        ?Carbon $scannedAt = null,
        ?string $clientScanId = null,
        bool $offline = false,
    ): array {
        $scannedAt ??= now();

        $ticket = Ticket::with('allocation')
            ->where('token_hash', Ticket::hashToken($token))
            ->first();

        if (! $ticket) {
            return $this->record($event, null, $device, 'invalid', $scannedAt, $clientScanId, $offline);
        }

        if ($ticket->event_id !== $event->id) {
            return $this->record($event, $ticket, $device, 'wrong_event', $scannedAt, $clientScanId, $offline);
        }

        if ($ticket->status === 'void') {
            // Distinguish a refund from an administrative cancellation: the door staff will be
            // asked "why", and "refunded" is a different conversation from "cancelled".
            $result = $ticket->allocation && $ticket->allocation->status === 'released'
                ? 'refunded'
                : 'cancelled';

            return $this->record($event, $ticket, $device, $result, $scannedAt, $clientScanId, $offline);
        }

        if ($this->claim($ticket, $scannedAt)) {
            return $this->record($event, $ticket->refresh(), $device, 'valid', $scannedAt, $clientScanId, $offline);
        }

        return $this->record(
            $event, $ticket->refresh(), $device, 'already_used', $scannedAt, $clientScanId, $offline,
            $this->firstScan($ticket),
        );
    }

    /**
     * Atomically claim the ticket. `WHERE status = 'issued'` is evaluated by the database as part
     * of the UPDATE, so exactly one concurrent caller can see a row count of 1.
     */
    private function claim(Ticket $ticket, Carbon $scannedAt): bool
    {
        return DB::table('tickets')
            ->where('id', $ticket->id)
            ->where('status', 'issued')
            ->update([
                'status' => 'used',
                'used_at' => $scannedAt,
                'updated_at' => now(),
            ]) === 1;
    }

    /** Who got in on this ticket, according to the first successful scan. */
    private function firstScan(Ticket $ticket): ?array
    {
        $checkin = Checkin::with(['device', 'operator'])
            ->where('ticket_id', $ticket->id)
            ->where('result', 'valid')
            ->orderBy('scanned_at')
            ->first();

        if (! $checkin) {
            // Claimed but the log row is not visible yet (or was pruned): still tell the truth
            // about when, from the ticket itself.
            return $ticket->used_at ? [
                'scanned_at' => $ticket->used_at->toIso8601String(),
                'device' => null,
                'operator' => null,
            ] : null;
        }

        return [
            'scanned_at' => $checkin->scanned_at->toIso8601String(),
            'device' => $checkin->device?->name,
            'operator' => $checkin->operator?->name,
        ];
    }

    private function record(
        Event $event,
        ?Ticket $ticket,
        ?CheckinDevice $device,
        string $result,
        Carbon $scannedAt,
        ?string $clientScanId,
        bool $offline,
        ?array $firstScan = null,
    ): array {
        try {
            Checkin::create([
                'event_id' => $event->id,
                'ticket_id' => $ticket?->id,
                'checkin_device_id' => $device?->id,
                'checkin_operator_id' => $device?->checkin_operator_id,
                'result' => $result,
                'scanned_at' => $scannedAt,
                'client_scan_id' => $clientScanId,
                'offline' => $offline,
            ]);
        } catch (UniqueConstraintViolationException) {
            // This exact scan was already uploaded by this device — an offline batch being
            // replayed. The outcome above is still the right answer to return.
        }

        return ['result' => $result, 'ticket' => $ticket, 'first_scan' => $firstScan];
    }
}
