<?php

namespace App\Domain\Sites;

use App\Mail\TicketsIssued;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Support\Qr\QrRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a buyer their tickets.
 *
 * Called with the models that just issued them, because the plaintext token exists on those and
 * nowhere else — a minute later there is nothing left to send. That is also why this is not a
 * queued job taking an order id: the job would run after the tokens were gone.
 *
 * A failure to send is logged and swallowed. The seats are already sold and the tickets already
 * issued; throwing here would roll a completed purchase back into an error page over a mail server
 * being slow, and the buyer can see their codes on the confirmation page either way.
 */
class TicketMailer
{
    public function __construct(private readonly QrRenderer $qr) {}

    public function send(Site $site, ExternalOrder $order): void
    {
        $email = $order->buyer['email'] ?? null;

        if (! $email) {
            return;
        }

        $tickets = [];

        foreach ($order->allocations as $allocation) {
            $token = $allocation->ticket?->plainToken;

            if (! $token) {
                continue;
            }

            $tickets[] = [
                'seat' => trim(implode(' · ', array_filter([
                    $allocation->section_name, $allocation->row_name, $allocation->seat_label,
                ]))),
                'quantity' => $allocation->quantity,
                'token' => $token,
                'qr' => $this->qr->dataUri($token, 220),
            ];
        }

        if (! $tickets) {
            return;
        }

        try {
            Mail::to($email)->send(new TicketsIssued($site, $order, $tickets));
        } catch (\Throwable $e) {
            Log::warning('Could not email tickets.', [
                'order' => $order->external_order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
