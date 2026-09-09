<?php

namespace App\Domain\Messaging;

/**
 * The messages this platform sends, and what each one may say.
 *
 * A closed list, with the placeholders declared beside it. An organiser writes the wording; the
 * variables they may use are these and nothing else, because a template is rendered by
 * substitution and a placeholder nobody declared would render as itself in front of a buyer.
 *
 * Kinds are deliberately few. Every one of them is a message somebody actually asked for — the
 * confirmation, the cancellation, the reminder the night before — rather than a framework for
 * messages nobody has written yet.
 */
class MessageKinds
{
    public const KINDS = [
        'order.confirmed' => [
            'placeholders' => ['buyer', 'event', 'venue', 'starts', 'seats', 'total', 'reference', 'site'],
            // Whether an organiser can switch it off. A confirmation is the one message a buyer is
            // entitled to, so it stays on; a reminder is a choice.
            'optional' => false,
        ],
        'order.cancelled' => [
            'placeholders' => ['buyer', 'event', 'reference', 'site'],
            'optional' => false,
        ],
        'event.reminder' => [
            'placeholders' => ['buyer', 'event', 'venue', 'starts', 'seats', 'reference', 'site'],
            'optional' => true,
        ],
        /*
         * An announcement carries the organiser's own words rather than a template's, so there is
         * nothing here to write wording for — but it is a kind, because everything it sends is a
         * delivery, and a delivery has to say what it was. Its channels are chosen per
         * announcement, which is why it is not in the channel-settings screen.
         */
        'announcement' => [
            'placeholders' => ['buyer', 'event', 'site'],
            'optional' => true,
            'composed' => true,
        ],
        /*
         * The platform talking to the organiser rather than to a buyer: something went wrong that
         * should not wait for somebody to open the panel. Email only, and not switchable off —
         * an alarm somebody turned off is not an alarm.
         */
        'system.notice' => [
            'placeholders' => ['title', 'body', 'account'],
            'optional' => false,
            'internal' => true,
        ],
    ];

    public static function keys(): array
    {
        return array_keys(self::KINDS);
    }

    public static function exists(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS);
    }

    public static function placeholders(string $kind): array
    {
        return self::KINDS[$kind]['placeholders'] ?? [];
    }

    public static function isOptional(string $kind): bool
    {
        return (bool) (self::KINDS[$kind]['optional'] ?? false);
    }

    /** Kinds an organiser writes wording for, which is not all of them. */
    public static function editable(): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $kind) => empty(self::KINDS[$kind]['composed'])
                && empty(self::KINDS[$kind]['internal'])
        ));
    }

    /** What the panel needs to draw the screen, without a second copy of this table. */
    public static function describe(): array
    {
        return array_map(fn (string $kind) => [
            'key' => $kind,
            'name' => __('messaging.kinds.'.str_replace('.', '_', $kind).'.name'),
            'description' => __('messaging.kinds.'.str_replace('.', '_', $kind).'.description'),
            'placeholders' => self::placeholders($kind),
            'optional' => self::isOptional($kind),
        ], self::editable());
    }
}
