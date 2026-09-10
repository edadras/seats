<?php

namespace App\Domain\Printing;

use App\Domain\Orders\TicketIssuer;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\Ticket;
use App\Support\Locale\Money;
use App\Support\Printing\EscPos;

/**
 * A ticket in somebody's hand, a second after the card clears.
 *
 * The window is the one place where the ticket a buyer walks away with is a physical thing, and
 * the difference between a good box office and a queue is whether that thing arrives immediately.
 * A PDF is not immediate: it is a file, a dialogue, a printer to choose and a page to wait for.
 *
 * **Printing re-mints the code.** Not a side effect — the point. A stored ticket cannot reproduce
 * its QR, because the database keeps a hash and nothing can recover the code from it; so the honest
 * answer is a new code, which necessarily stops any earlier copy working. That is also what you
 * want at a counter: a reprint is the live ticket, and the one somebody claims to have lost is not.
 *
 * **Two ways out, for two different reasons.** Raw ESC/POS goes straight at a roll printer through
 * a local agent and is instant. The print view goes through the browser and the operating system's
 * own driver, is slower by a dialogue, and can draw any script in the world — which is the only way
 * to print a Persian event name on hardware bought in Berlin. Both are built from the same lines
 * here, so the two cannot say different things about the same seat.
 */
class TicketReceipts
{
    public function __construct(private readonly TicketIssuer $issuer) {}

    /**
     * What one ticket says, in order, from the top of the roll.
     *
     * @return array{lines: list<array{kind: string, left?: string, right?: string, text?: string}>, token: string}
     */
    public function forAllocation(Allocation $allocation): array
    {
        $ticket = $this->issuer->reissue($allocation);

        if (! $ticket) {
            throw ApiException::conflict(
                'ticket_not_printable',
                'That seat has no live ticket to print: it has been used, refunded or never issued.',
            );
        }

        $allocation->loadMissing('order');

        // Looked up rather than loaded through the allocation: it declares no `event` relation, and
        // with lazy loading off an undeclared one is a silently null attribute rather than an error.
        $event = \App\Models\Event::with('venue')->find($allocation->event_id);
        $order = $allocation->order;
        $venue = $event?->venue?->name ?? '';

        $seat = trim(implode(' · ', array_filter([
            $allocation->section_name,
            $allocation->row_name ? __('panel.tickets.rowShort').' '.$allocation->row_name : null,
            $allocation->seat_label,
        ])));

        $lines = [
            ['kind' => 'title', 'text' => $event?->name ?? ''],
        ];

        if ('' !== $venue) {
            $lines[] = ['kind' => 'centre', 'text' => $venue];
        }

        if ($event?->starts_at) {
            $lines[] = ['kind' => 'centre', 'text' => $event->starts_at
                ->setTimezone($event->timezone ?: config('app.timezone'))
                ->translatedFormat('D j M Y · H:i')];
        }

        $lines[] = ['kind' => 'rule'];

        // The one line worth shouting, and the reason somebody is holding the paper at all.
        $lines[] = ['kind' => 'seat', 'text' => '' !== $seat ? $seat : __('panel.customers.standing')];

        if ($allocation->ticket_type_name) {
            $lines[] = ['kind' => 'centre', 'text' => $allocation->ticket_type_name];
        }

        $lines[] = ['kind' => 'rule'];
        $lines[] = [
            'kind' => 'pair',
            'left' => __('panel.orders.total'),
            'right' => Money::format((int) $allocation->amount, (string) ($order?->currency ?? 'EUR')),
        ];
        $lines[] = [
            'kind' => 'pair',
            'left' => __('panel.orders.reference'),
            'right' => (string) ($order?->external_order_id ?? ''),
        ];

        // The code itself, and the same characters underneath it: a QR that will not scan because
        // the roll was crumpled is a ticket somebody can still be let in on.
        $lines[] = ['kind' => 'qr', 'text' => $ticket->plainToken];
        $lines[] = ['kind' => 'small', 'text' => $ticket->token_prefix];

        return ['lines' => $lines, 'token' => (string) $ticket->plainToken];
    }

    /**
     * The whole booking, one ticket after another with a cut between them.
     *
     * @return list<array{lines: list<array<string, string>>, token: string}>
     */
    public function forOrder(ExternalOrder $order): array
    {
        $printable = Allocation::where('external_order_row_id', $order->id)
            ->where('status', 'active')
            ->orderBy('section_name')
            ->orderBy('row_name')
            ->orderBy('seat_label')
            ->get();

        if ($printable->isEmpty()) {
            throw ApiException::conflict(
                'ticket_not_printable',
                'That booking has no live seats to print.',
            );
        }

        return $printable->map(fn (Allocation $allocation) => $this->forAllocation($allocation))->all();
    }

    /**
     * Those lines as the bytes a roll printer understands.
     *
     * @param  list<array{lines: list<array<string, string>>, token: string}>  $receipts
     */
    public function escpos(array $receipts, int $width = 80, bool $cut = true): string
    {
        $printer = EscPos::forWidth($width)->start();

        foreach ($receipts as $receipt) {
            foreach ($receipt['lines'] as $line) {
                match ($line['kind']) {
                    'title' => $printer->align('centre')->bold()->size(1, 2)
                        ->line($line['text'])->size()->bold(false),
                    'seat' => $printer->align('centre')->bold()->size(2, 2)
                        ->line($line['text'])->size()->bold(false),
                    'centre' => $printer->align('centre')->line($line['text']),
                    'small' => $printer->align('centre')->line($line['text']),
                    'rule' => $printer->align('left')->rule(),
                    'pair' => $printer->align('left')->columnsPair($line['left'], $line['right']),
                    'qr' => $printer->align('centre')->feed(1)->qr($line['text'])->feed(1),
                    default => $printer->line($line['text'] ?? ''),
                };
            }

            // Between tickets as well as after the last one: two seats on one strip of paper is
            // two people sharing a ticket at the door.
            if ($cut) {
                $printer->cut();
            } else {
                $printer->feed(4);
            }
        }

        return $printer->bytes();
    }
}
