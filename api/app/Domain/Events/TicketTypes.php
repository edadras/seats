<?php

namespace App\Domain\Events;

use App\Models\Event;
use App\Models\TicketType;

/**
 * The ticket types a buyer may choose from, shaped for the picker.
 *
 * One list, built once, used by the hosted site, the embed API and the WordPress plugin — because
 * three descriptions of "what a child ticket costs here" is three chances for them to disagree.
 *
 * The picker prices a type in the browser so the buyer sees the number change as they choose. That
 * arithmetic is duplicated in JavaScript on purpose and is duplicated *exactly*: `kind` and `value`
 * travel raw rather than as a pre-computed price, because a pre-computed price would have to be
 * computed per seat and this list is per event. The server prices the hold itself either way —
 * what the browser shows is a promise, and `TicketType::priceFrom` is what keeps it.
 */
final class TicketTypes
{
    /** @return list<array<string, mixed>> */
    public static function forEvent(Event $event): array
    {
        return TicketType::where('event_id', $event->id)
            ->where('status', 'active')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (TicketType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'kind' => $type->kind,
                'value' => $type->value,
                'is_default' => (bool) $type->is_default,
                'min_per_order' => $type->min_per_order ?: null,
                'max_per_order' => $type->max_per_order,
                // "Bring your student card": the buyer has to read this before they choose, not
                // find it out at the door.
                'proof_note' => $type->proof_note,
            ])
            ->values()
            ->all();
    }
}
