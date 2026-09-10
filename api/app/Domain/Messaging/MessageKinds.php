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
        /*
         * The night is off.
         *
         * Not optional, and not an announcement an organiser composes: somebody paid for a seat at
         * something that is no longer happening, and being told is not a marketing preference.
         * `reason` is the organiser's own sentence, because "cancelled" without one is the start
         * of an argument rather than the end of it.
         */
        'event.cancelled' => [
            'placeholders' => ['buyer', 'event', 'venue', 'starts', 'seats', 'reference', 'reason', 'site'],
            'optional' => false,
        ],
        /*
         * The night has moved. Their ticket still works, which is the first thing to say — the
         * commonest reaction to "your event has changed" is to assume the ticket has not.
         */
        'event.moved' => [
            'placeholders' => [
                'buyer', 'event', 'venue', 'starts', 'was', 'seats', 'reference', 'reason', 'site',
            ],
            'optional' => false,
        ],
        'event.reminder' => [
            'placeholders' => ['buyer', 'event', 'venue', 'starts', 'seats', 'reference', 'site'],
            'optional' => true,
        ],
        /*
         * Somebody who asked to be told when a seat came back, being told.
         *
         * Not optional: they asked for exactly this message and nothing else, and an organiser who
         * could switch it off would be collecting addresses for a promise they do not keep.
         */
        'waitlist.available' => [
            'placeholders' => [
                'buyer', 'event', 'venue', 'starts', 'quantity', 'hours', 'site', 'link', 'leave',
            ],
            'optional' => false,
        ],
        /*
         * A purchase that was started and never finished.
         *
         * Optional, and that switch is the whole of an organiser's control over this: a message
         * about somebody's own unfinished booking is a service message, but it is still a message
         * they did not ask for, and an account that would rather not send one must be able to say
         * so. `link` takes them back to a checkout with the same seats if the seats are still
         * there; `decline` is how they say no thank you, which is not optional either.
         */
        'order.unfinished' => [
            'placeholders' => [
                'buyer', 'event', 'venue', 'starts', 'seats', 'total', 'site', 'link', 'decline',
            ],
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
