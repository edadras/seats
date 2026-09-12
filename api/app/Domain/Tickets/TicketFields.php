<?php

namespace App\Domain\Tickets;

use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Support\Locale\Dates;
use App\Support\Locale\Money;

/**
 * Everything a designed ticket may print, and nothing else.
 *
 * A closed list, like {@see \App\Domain\Messaging\MessageKinds}, and for the same reason: an
 * organiser drags fields onto a picture, and a field nobody declared would print its own name onto
 * a document somebody hands over at a door. Adding one here is a deliberate act and shows up in
 * the panel automatically.
 *
 * Two of these are not text and are marked so. The QR is the whole purpose of the document — it is
 * drawn as an image and sized as a square — and the ticket code is set in a monospaced face because
 * it is read aloud down a telephone when a scanner will not cooperate.
 */
final class TicketFields
{
    /** @var array<string, array{kind:string}> */
    public const FIELDS = [
        'event' => ['kind' => 'text'],
        'venue' => ['kind' => 'text'],
        'when' => ['kind' => 'text'],
        'seat' => ['kind' => 'text'],
        'entry' => ['kind' => 'text'],
        'type' => ['kind' => 'text'],
        'holder' => ['kind' => 'text'],
        'price' => ['kind' => 'text'],
        'reference' => ['kind' => 'text'],
        'code' => ['kind' => 'code'],
        'qr' => ['kind' => 'image'],
    ];

    public static function has(string $key): bool
    {
        return isset(self::FIELDS[$key]);
    }

    public static function kindOf(string $key): string
    {
        return self::FIELDS[$key]['kind'] ?? 'text';
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * What each field says for one seat on one booking.
     *
     * Every value is a string, the QR included — that one is a data URI, because a ticket that
     * fetches its own barcode from somewhere is a ticket that is sometimes a blank square.
     *
     * @param  callable(string): string  $qr
     * @return array<string, string>
     */
    public static function values(
        Site $site,
        ExternalOrder $order,
        Allocation $allocation,
        ?string $token,
        callable $qr,
    ): array {
        $event = $order->event;
        $starts = $event?->starts_at?->setTimezone($event->timezone ?: $site->timezone);

        return [
            'event' => (string) ($event?->nameFor() ?? ''),
            'venue' => (string) ($event?->venue?->name ?? $site->name),
            'when' => $starts ? Dates::longWhen($starts) : '',
            'seat' => self::seat($allocation),
            'entry' => $allocation->entry_starts_at
                ? \App\Domain\Events\EntrySlots::window(
                    $allocation->entry_starts_at,
                    $allocation->entry_ends_at,
                    $event?->timezone,
                )
                : '',
            'type' => (string) ($allocation->ticket_type_name ?? ''),
            // Whoever this seat is actually for: the name written on the ticket where one was
            // given, and the buyer's otherwise. A ticket transferred to a friend carries theirs.
            'holder' => (string) ($allocation->ticket?->holder_name ?: ($order->buyer['name'] ?? '')),
            'price' => null !== $allocation->amount
                ? Money::format((int) $allocation->amount, $allocation->currency ?: $site->currency)
                : '',
            'reference' => (string) $order->external_order_id,
            'code' => (string) ($token ?? ($allocation->ticket?->token_prefix.'…')),
            'qr' => $token ? $qr($token) : '',
        ];
    }

    /** A named chair, or a count of places where the room is sold by the head. */
    private static function seat(Allocation $allocation): string
    {
        if ($allocation->seat_id) {
            return trim(implode(' ', array_filter([
                $allocation->section_name,
                $allocation->row_name,
                $allocation->seat_label,
            ])));
        }

        return trim(($allocation->section_name ?: __('site.standing'))
            .' × '.Money::number($allocation->quantity ?: 1));
    }
}
