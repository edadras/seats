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
        // The venue's clock is what an arrival window is written in, and reaching it needs the
        // event loaded before the lines are built — lazy loading is off.
        $order->loadMissing('event');

        $this->deliver($site, $order, $order->allocations->all(), $order->buyer['email'] ?? null);
    }

    /**
     * One ticket of an order, to somebody who is not the buyer.
     *
     * Transfers use this: the seat was bought by one person and is being used by another, so the
     * email goes to the new holder and carries their ticket alone. Nothing else about the booking
     * travels with it — the rest of the party's codes are not this person's business.
     */
    public function sendOne(Site $site, ExternalOrder $order, $allocation, string $email): void
    {
        $order->loadMissing('event');

        $this->deliver($site, $order, [$allocation], $email);
    }

    /** @param  list<\App\Models\Allocation>  $allocations */
    private function deliver(Site $site, ExternalOrder $order, array $allocations, ?string $email): void
    {
        if (! $email) {
            return;
        }

        $tickets = [];

        foreach ($allocations as $allocation) {
            $token = $allocation->ticket?->plainToken;

            if (! $token) {
                continue;
            }

            $tickets[] = [
                'seat' => trim(implode(' · ', array_filter([
                    $allocation->section_name, $allocation->row_name, $allocation->seat_label,
                ]))),
                'quantity' => $allocation->quantity,
                // When to arrive, on a timed-entry event. Empty everywhere else, and the email
                // shows nothing rather than a blank line where a time should be.
                'entry' => \App\Domain\Events\EntrySlots::window(
                    $allocation->entry_starts_at,
                    $allocation->entry_ends_at,
                    $order->event?->timezone,
                ),
                'token' => $token,
                'qr' => $this->qr->dataUri($token, 220),
            ];
        }

        if (! $tickets) {
            return;
        }

        try {
            /*
             * Sent in the site's own calendar, whoever happens to be sending it.
             *
             * Mail leaves from a queue worker, and the worker's process has no request in it — so
             * nothing has bound a calendar and the last booking's would otherwise still be in
             * force. `runAs` puts it back afterwards, which is what makes a run of a hundred
             * confirmations for four venues come out right.
             */
            \App\Support\Locale\Calendars::runAs(
                $site->calendar,
                fn () => Mail::to($email)->send(new TicketsIssued($site, $order, $tickets)),
            );
        } catch (\Throwable $e) {
            Log::warning('Could not email tickets.', [
                'order' => $order->external_order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
