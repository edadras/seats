<?php

/**
 * Ticket email.
 *
 * This is the one message that has to survive being read on a bus, on a phone, six weeks after it
 * arrived — so it says the event, the date, the seats, and nothing that needs a network to load.
 */
return [
    'title' => 'Your tickets',
    'subject' => 'Your tickets for :event',
    'intro' => 'Thank you. Show a code below at the door — one for each seat. They work from this email or from your booking page.',
    'standing' => 'Standing',
    'keepThis' => 'Booking reference :reference. Keep this email — anyone holding a code can use it to come in.',
    'sender' => [
        'subject' => 'Confirm this address for :venue',
        'body' => "Somebody at :venue asked for ticket emails to be answerable at this address.\n\nType this code into the messaging screen to confirm it:\n\n:code\n\nThe code lasts a day. If this was not you, ignore this email — nothing changes until the code is typed.",
    ],
];
