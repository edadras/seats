<?php

/**
 * Messages, and the screen an organiser writes them on.
 *
 * `defaults` is the wording this platform sends when an organiser has written none — which is
 * every account on its first day. It is a real message in every language rather than a placeholder,
 * because the alternative to a good default is not a better message; it is a blank one.
 *
 * The braces are placeholders substituted at send time (App\Domain\Messaging\MessageRenderer), not
 * Laravel's `:name`: an organiser edits these strings in the panel, and one syntax is enough.
 */
return [
    'title' => 'Messages',
    'subtitle' => 'What a buyer is told, on which channels, in their own language.',
    'kinds' => [
        'order_confirmed' => [
            'name' => 'Order confirmed',
            'description' => 'Sent the moment a payment settles. This is the one message a buyer is entitled to.',
        ],
        'order_cancelled' => [
            'name' => 'Order cancelled',
            'description' => 'Sent when an order is cancelled or refunded.',
        ],
        'event_reminder' => [
            'name' => 'Event reminder',
            'description' => 'Sent the day before, to everyone holding a ticket. Off unless you turn it on.',
        ],
    ],
    'channels' => [
        'email' => 'Email',
    ],
    'defaults' => [
        'order_confirmed' => [
            'subject' => 'Your tickets for {event}',
            'body' => "{buyer}, your tickets are booked.\n\n{event}\n{venue}\n{starts}\nSeats: {seats}\nTotal: {total}\n\nBooking reference {reference}.",
        ],
        'order_cancelled' => [
            'subject' => 'Your booking {reference} has been cancelled',
            'body' => "{buyer}, your booking for {event} has been cancelled.\n\nBooking reference {reference}.",
        ],
        'event_reminder' => [
            'subject' => '{event} is tomorrow',
            'body' => "{buyer}, {event} is tomorrow.\n\n{venue}\n{starts}\nSeats: {seats}\n\nBooking reference {reference}.",
        ],
    ],
    'template' => 'Wording',
    'subject' => 'Subject',
    'body' => 'Message',
    'locale' => 'Language',
    'usingDefault' => 'Using our wording. Type your own to replace it.',
    'placeholders' => 'Available: :list',
    'save' => 'Save the wording',
    'saved' => 'Wording saved.',
    'reset' => 'Back to our wording',
    'resetDone' => 'Back to our wording.',
    'channelsOn' => 'Sent on',
    'channelHint' => 'A buyer without an address for a channel simply is not sent that one.',
    'required' => 'Always sent',
    'log' => 'Delivery log',
    'noLog' => 'Nothing has been sent yet',
    'noLogHint' => 'Confirmations appear here the moment somebody buys.',
    'status' => [
        'queued' => 'Queued',
        'sent' => 'Sent',
        'refused' => 'Refused',
        'unavailable' => 'Could not be reached',
    ],
    'recipient' => 'To',
    'when' => 'When',
    'attempts' => 'Attempts',
    'reason' => 'Reason',
    'test' => 'Send a test',
    'testTo' => 'Send it to',
    'testSent' => 'Test sent. Look for it in the log below.',
    'testHint' => 'Uses your wording with example values, so you can see what a buyer sees.',
    'filterAll' => 'Every status',
    'preview' => 'Preview',
    'example' => [
        'buyer' => 'Alex Doe',
        'event' => 'Opening night',
        'venue' => 'Northgate Theatre',
        'seats' => 'Stalls A 12, Stalls A 13',
    ],
];
