<?php

namespace App\Domain\Wallet;

use App\Domain\Events\EntrySlots;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Support\Locale\Dates;

/**
 * What is written on a pass, decided once for both wallets.
 *
 * Apple and Google want the same facts in different shapes. Deciding them twice is how a ticket
 * ends up saying "Stalls A 5" on one phone and "A5" on the other, and how an arrival window gets
 * onto one and not the other.
 *
 * The barcode carries the ticket's own code — the same string the scanner at the door reads off
 * the QR in the email. A pass with a different code would be a pass that does not get anybody in.
 */
final class PassContent
{
    private function __construct(
        public readonly string $serial,
        public readonly string $event,
        public readonly string $venue,
        public readonly string $when,
        public readonly ?string $whenIso,
        public readonly string $seat,
        public readonly ?string $ticketType,
        public readonly ?string $entry,
        public readonly string $reference,
        public readonly string $barcode,
        public readonly string $organiser,
    ) {}

    public static function for(
        Site $site,
        ExternalOrder $order,
        Allocation $allocation,
        string $token,
        ?string $locale = null,
    ): self {
        $event = $order->event;
        $starts = $event?->starts_at;

        return new self(
            // The allocation, not the ticket: a reissued ticket replaces the pass in the wallet
            // rather than adding a second one for the same chair.
            serial: 'seat-'.$allocation->id,
            event: (string) ($event?->nameFor($locale) ?? ''),
            venue: (string) ($event?->venue?->name ?? $site->name),
            when: $starts ? Dates::longWhen($starts->setTimezone($event->timezone), $locale) : '',
            whenIso: $starts?->toIso8601String(),
            seat: $allocation->seat_id
                ? trim(implode(' · ', array_filter([
                    $allocation->section_name, $allocation->row_name, $allocation->seat_label,
                ])))
                : trim((string) $allocation->section_name),
            ticketType: $allocation->ticket_type_name ?: null,
            entry: EntrySlots::window(
                $allocation->entry_starts_at,
                $allocation->entry_ends_at,
                $event?->timezone,
            ),
            reference: (string) $order->external_order_id,
            barcode: $token,
            organiser: $site->name,
        );
    }
}
