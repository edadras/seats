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
        return new Envelope(
            subject: __('mail.subject', [
                'event' => $this->order->event?->name ?? $this->site->name,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.tickets', with: [
            'brand' => \App\Domain\Sites\Themes::resolveBrand($this->site->theme_key, $this->site->brand ?? []),
        ]);
    }
}
