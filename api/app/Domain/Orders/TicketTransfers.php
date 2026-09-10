<?php

namespace App\Domain\Orders;

use App\Domain\Sites\TicketMailer;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\TicketTransfer;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Giving one ticket to somebody else.
 *
 * The transfer takes effect at once rather than waiting for the other person to accept, and that is
 * the safe choice rather than the lazy one: two live codes for one seat is a queue at the door and
 * an argument about who is the real holder. Reissuing means there is exactly one working code at
 * every instant — the sender's stops working the moment they give it away, which is what giving it
 * away means.
 *
 * The seat is not re-sold and the order is not touched. What changes is whose name is on the
 * ticket, where it is sent, and what the door reads out when it scans.
 */
class TicketTransfers
{
    public function __construct(
        private readonly TicketIssuer $tickets,
        private readonly TicketMailer $mail,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string}  $to
     */
    public function give(Site $site, ExternalOrder $order, Allocation $allocation, array $to, array $from): TicketTransfer
    {
        if ($allocation->external_order_row_id !== $order->id) {
            throw ApiException::notFound('That ticket is not on this booking.');
        }

        $ticket = $allocation->ticket;

        if (! $ticket || 'issued' !== $ticket->status) {
            // A used ticket cannot be given away — somebody is already inside on it — and a voided
            // one is not a ticket. Both are refusals with a reason rather than a silent no-op.
            // One code, two sentences: a client branches on the code, and a person needs to be
            // told which of the two happened. So the message key is named rather than inferred.
            throw new ApiException(
                'ticket_not_transferable',
                'used' === $ticket?->status
                    ? 'That ticket has already been used to come in.'
                    : 'That ticket is no longer valid.',
                409,
                [],
                'used' === $ticket?->status ? 'ticket_already_used' : 'ticket_not_transferable',
            );
        }

        $toEmail = mb_strtolower(trim($to['email']));

        if ($toEmail === mb_strtolower(trim((string) ($from['email'] ?? '')))) {
            throw ApiException::unprocessable(
                'same_person',
                'That is the address the ticket is already sent to.'
            );
        }

        $transfer = DB::transaction(function () use ($order, $allocation, $ticket, $to, $toEmail, $from) {
            /*
             * Whose name is on it, before the new code exists.
             *
             * Written first so that a failure between the two leaves a ticket addressed to the new
             * holder with the old code — recoverable by resending — rather than a live code
             * addressed to nobody.
             */
            $ticket->forceFill([
                'holder_name' => trim($to['name']),
                'holder_email' => $toEmail,
            ])->save();

            $allocation->setRelation('ticket', $ticket);

            return TicketTransfer::create([
                'tenant_id' => $order->tenant_id,
                'ticket_id' => $ticket->id,
                'external_order_row_id' => $order->id,
                'from_email' => mb_strtolower(trim((string) ($from['email'] ?? ''))),
                'from_name' => $from['name'] ?? null,
                'to_email' => $toEmail,
                'to_name' => trim($to['name']),
                'transferred_at' => now(),
            ]);
        });

        // The old code dies here. Outside the transaction on purpose: the plaintext exists only in
        // memory, and a rollback after it was emailed would leave the new holder with a dead code.
        $reissued = $this->tickets->reissue($allocation);

        if ($reissued) {
            $allocation->setRelation('ticket', $reissued);
        }

        $this->mail->sendOne($site, $order, $allocation, $toEmail);

        $this->audit->record('ticket.transferred', $order, [
            'ticket_id' => $ticket->id,
            'to' => $toEmail,
            'seat' => trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label),
        ]);

        return $transfer;
    }
}
