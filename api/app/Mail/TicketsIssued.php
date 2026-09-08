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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your tickets for '.($this->order->event?->name ?? $this->site->name),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.tickets', with: [
            'brand' => \App\Domain\Sites\Themes::resolveBrand($this->site->theme_key, $this->site->brand ?? []),
        ]);
    }
}
