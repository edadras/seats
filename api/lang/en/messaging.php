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
        'order_unfinished' => [
            'name' => 'Unfinished booking',
            'description' => 'Sent once, an hour later, to somebody whose payment never finished. It is about their own booking and carries a way to say no thanks. Off unless you turn it on.',
        ],
        'event_reminder' => [
            'name' => 'Event reminder',
            'description' => 'Sent the day before, to everyone holding a ticket. Off unless you turn it on.',
        ],
        'event_cancelled' => [
            'name' => "Event cancelled",
            'description' => "Sent to everybody holding a ticket when a night is called off. It cannot be switched off — somebody paid for a seat at something that is not happening.",
        ],
        'event_moved' => [
            'name' => "Event moved",
            'description' => "Sent when a date changes. Their ticket still works, which is the first thing it says.",
        ],
        'waitlist_available' => [
            'name' => 'A seat came free',
            'description' => 'Sent to the next people on a waiting list when places come back. They asked for exactly this, so it cannot be switched off.',
        ],
        'season_renewal' => [
            'name' => 'Season renewal',
            'description' => "Sent to last season's subscribers when their seats are being kept for them. It cannot be switched off — somebody stands to lose the chairs they have sat in for years.",
        ],
    ],
    'channels' => [
        'email' => 'Email',
    ],
    'defaults' => [
        'system_notice' => [
            'subject' => 'A message from {account}: {title}',
            'body' => "{title}\n\n{body}\n\nThis is an automatic notice from your Seatmap account.",
        ],
        'order_confirmed' => [
            'subject' => 'Your tickets for {event}',
            'body' => "{buyer}, your tickets are booked.\n\n{event}\n{venue}\n{starts}\nSeats: {seats}\nTotal: {total}\n\nBooking reference {reference}.",
        ],
        'order_cancelled' => [
            'subject' => 'Your booking {reference} has been cancelled',
            'body' => "{buyer}, your booking for {event} has been cancelled.\n\nBooking reference {reference}.",
        ],
        'order_unfinished' => [
            'subject' => 'Your booking for {event} is not finished',
            'body' => "{buyer}, your payment for {event} did not go through, so your booking was never completed.\n\n{venue}\n{starts}\nSeats: {seats}\nTotal: {total}\n\nIf you still want them, pick up where you left off here — we will try to give you the same seats:\n{link}\n\nIf you have changed your mind, no reply is needed. To hear nothing further about this booking: {decline}",
        ],
        'event_reminder' => [
            'subject' => '{event} is tomorrow',
            'body' => "{buyer}, {event} is tomorrow.\n\n{venue}\n{starts}\nSeats: {seats}\n\nBooking reference {reference}.",
        ],
        'event_cancelled' => [
            'subject' => "Cancelled: {event}",
            'body' => "{buyer}, we are sorry to say that {event} on {starts} at {venue} has been cancelled.\n\n{reason}\n\nYour booking {reference} has been refunded. Nothing is owed and your tickets are no longer valid.",
        ],
        'event_moved' => [
            'subject' => "New date: {event}",
            'body' => "{buyer}, {event} has moved from {was} to {starts} at {venue}.\n\n{reason}\n\nYour tickets are still valid — booking {reference}, {seats}. There is nothing you need to do.",
        ],
        'waitlist_available' => [
            'subject' => 'A seat has come free for {event}',
            'body' => "{buyer}, a place has come back for {event}.\n\n{venue}\n{starts}\n\nYou asked for {quantity}. Seats are on sale again for the next {hours} hours, first come first served:\n{link}\n\nTo come off this list: {leave}",
        ],
        'season_renewal' => [
            'subject' => 'Your seats for {run}',
            'body' => "{buyer}, your seats for {run} are being kept for you: {seats}.\n\nThey are yours until {deadline}, after which they go on general sale. To keep them, or to let us know you cannot come this year:\n{link}\n\n{site}",
        ],
    ],
    'logKinds' => [
        'announcement' => 'Announcement',
        'system_notice' => 'System notice',
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
    'announceHeading' => 'Announcements',
    'announceIntro' => 'Write to everybody who has bought — an event moved, a change of door, a thank-you.',
    'announceNew' => 'Write an announcement',
    'announceAudience' => 'Who it goes to',
    'audienceEveryone' => 'Everybody who has bought from you',
    'audienceEvent' => 'People who bought for one event',
    'announceChannels' => 'Send it on',
    'announceSubject' => 'Subject',
    'announceSubjectHint' => 'Email only. An SMS has no subject line.',
    'announceBody' => 'Message',
    'announceBodyHint' => 'You can use {buyer} and {site}. {event} works when you write to one event\'s buyers.',
    'announceReach' => ':people people · :messages messages',
    'announceReachNobody' => 'Nobody matches that yet.',
    'announceSend' => 'Send it',
    'announceConfirm' => 'Send this announcement?',
    'announceConfirmBody' => 'It goes to :messages messages and cannot be unsent.',
    'announceSent' => 'On its way.',
    'announceNone' => 'Nothing has been announced yet',
    'announceNoneHint' => 'An announcement goes to the people who bought, on the channels you choose.',
    'announceStatusDraft' => 'Draft',
    'announceStatusSending' => 'Sending',
    'announceStatusSent' => 'Sent',
    'announceProgress' => ':sent of :total sent',
    'announceFailed' => ':count could not be delivered',
    'announceWhen' => 'Written',
    'announceOnlyPaid' => 'Only people whose order was paid for are written to.',
    'audienceOneEvent' => 'People who bought for one event',
    'audienceSegmentGone' => 'A saved audience, since deleted',

    /*
     * Saved audiences.
     *
     * The audience an organiser wants is almost never "everybody": it is "the people who came last
     * season and have not booked this one". These words are for a screen that has to say what a
     * saved list means without listing the people in it.
     */
    'segmentsHeading' => 'Saved audiences',
    'segmentsIntro' => 'Who to write to, saved and named: the people who came last season and have not booked this one, the regulars, the ones who actually turned up.',
    'segmentNew' => 'New audience',
    'segmentMeans' => 'What it means',
    'segmentsNone' => 'No saved audiences yet',
    'segmentsNoneHint' => 'A saved audience holds rules, never a list of people — it is worked out again every time you use it.',
    'segmentDescription' => 'A note to yourself',
    'segmentBoughtLabel' => 'Bought for any of these',
    'segmentNotBoughtLabel' => 'And none of these',
    'segmentNotBoughtHint' => 'The second half of "came last season and has not booked this one".',
    'segmentCategoriesLabel' => 'Bought anything in these categories',
    'segmentSinceLabel' => 'Bought since',
    'segmentUntilLabel' => 'Bought until',
    'segmentMinOrdersLabel' => 'Bought at least this many times',
    'segmentMinSpendLabel' => 'Spent at least',
    'segmentCurrencyLabel' => 'In currency',
    'segmentAttendedLabel' => 'Actually turned up and was scanned in',
    'segmentReach' => 'This reaches :count people.',
    'segmentSaved' => 'Audience saved.',
    'segmentDelete' => 'Delete this audience?',
    'segmentDeleteBody' => 'The list goes; what was already sent to it stays, with its own record of what it reached.',
    'segmentDeleted' => 'Audience deleted.',
    'segmentBought' => 'bought for :events',
    'segmentNotBought' => 'and not :events',
    'segmentCategories' => 'in :list',
    'segmentSince' => 'since :when',
    'segmentUntil' => 'until :when',
    'segmentMinOrders' => 'bought :count times or more',
    'segmentMinSpend' => 'spent :amount or more',
    'segmentAttended' => 'turned up',
    'segmentEverybody' => 'everybody who has bought',
    'unsubscribeLine' => 'Do not want these? Tell us to stop: :link',
    'announceNotAsked' => ':count have not been asked',
    'announceService' => 'About a booking they hold: everybody who bought for this event is written to.',
    'announceNews' => 'News: only people who agreed to hear from you are written to, and a way out is added at the bottom.',
    'sender' => [
        'title' => 'Who this comes from',
        'description' => 'What a buyer sees at the top of every email you send them.',
        'ours' => 'Our address',
        'replies' => 'Replies reach you',
        'fully' => 'Your own address',
        'preview' => 'Buyers see: :name <:address>',
        'replyTo' => 'A reply goes to :address.',
        'name' => 'Name in the From line',
        'email' => 'Address replies should reach',
        'awaiting' => 'We sent a six-digit code to :address. Type it here to confirm the address is yours.',
        'verify' => 'Confirm',
        'again' => 'Send it again',
        'domainHint' => 'Your name appears immediately. The address becomes the reply address once you confirm it — and becomes the From address too, if the operator of this platform has set up your domain to send from here. Doing that without the DNS in place is how confirmations end up in spam folders.',
        'clear' => 'Use the platform’s own',
        'saved' => 'Saved.',
        'codeSent' => 'A code is on its way to that address.',
        'verified' => 'Confirmed. Replies will reach you.',
        'cleared' => 'Back to the platform’s own address.',
    ],
];
