<?php

namespace App\Mail;

use App\Models\ExternalOrder;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The buyer's tickets, with a scannable code for each seat. */
class TicketsIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Site $site,
        public readonly ExternalOrder $order,
        public readonly array $tickets,
        ?string $locale = null,
    ) {
        // Pinned at construction, not read at render. This mail is built inside the buyer's
        // request but rendered by the mailer, and a queued send later would otherwise render it in
        // whatever language the worker happened to be in.
        $this->locale($locale ?? app()->getLocale());
    }

    public function envelope(): Envelope
    {
        /*
         * From the venue, not from the platform.
         *
         * This is the one email in the system a buyer is certain to open, so it is the one that
         * most needs the venue's name on it. The address follows the same rule as everywhere else:
         * theirs where the operator has authorised the domain, ours with their address in Reply-To
         * where it has not.
         */
        // Fetched rather than read off the relation: lazy loading is off, and this renders in a
        // worker where nothing has been eager-loaded for it.
        $from = app(\App\Domain\Messaging\SenderIdentity::class)
            ->current(\App\Models\Tenant::find($this->site->tenant_id));

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address($from['address'], $from['name']),
            replyTo: $from['reply_to']
                ? [new \Illuminate\Mail\Mailables\Address($from['reply_to'], $from['name'])]
                : [],
            subject: __('mail.subject', [
                'event' => $this->order->event?->name ?? $this->site->name,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.tickets', with: [
            'brand' => \App\Domain\Sites\Themes::forSite($this->site),
        ]);
    }
}
