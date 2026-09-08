<?php

namespace App\Domain\Orders;

use App\Models\Allocation;
use App\Models\Ticket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Issues and voids tickets.
 *
 * The QR token is 32 random bytes and carries no meaning at all — no order id, no name, no
 * signature. That is deliberate: verification is a hash lookup against a row we issued, so there
 * is nothing for an attacker to forge offline (threat T6), and a leaked database yields hashes
 * rather than working tickets.
 */
class TicketIssuer
{
    public function issue(Allocation $allocation, ?string $holderName = null): Ticket
    {
        $existing = Ticket::where('allocation_id', $allocation->id)->first();

        if ($existing) {
            return $existing; // Idempotent: a retried confirm must not mint a second ticket.
        }

        [$token, $hash] = $this->generateToken();

        try {
            // Wrapped in its own transaction so that, when this runs inside the confirm
            // transaction, a violation rolls back to a savepoint instead of poisoning the whole
            // transaction — Postgres refuses every later statement once one has failed.
            $ticket = DB::transaction(fn () => Ticket::create([
                'event_id' => $allocation->event_id,
                'allocation_id' => $allocation->id,
                'token_hash' => $hash,
                'token_prefix' => substr($token, 0, 12),
                'status' => 'issued',
                'holder_name' => $holderName,
                'issued_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another confirm for the same allocation won. Its ticket is the real one, and its
            // token is not ours to reveal.
            return Ticket::where('allocation_id', $allocation->id)->firstOrFail();
        }

        // The plaintext token exists only in memory, and only here. It is attached to the model
        // for the caller to put in the response and the QR; it is never persisted.
        $ticket->plainToken = $token;

        return $ticket;
    }

    public function void(Allocation $allocation): void
    {
        Ticket::where('allocation_id', $allocation->id)
            ->whereIn('status', ['issued', 'used'])
            ->update(['status' => 'void', 'voided_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0: string, 1: string} plaintext token, storage hash */
    private function generateToken(): array
    {
        // Base32 (Crockford-ish, no padding): unambiguous in a QR and safe in a URL.
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $bytes = random_bytes(32);
        $token = '';

        for ($i = 0; $i < strlen($bytes); $i++) {
            $token .= $alphabet[ord($bytes[$i]) & 31];
        }

        $token = 'TKT'.$token;

        return [$token, Ticket::hashToken($token)];
    }
}
