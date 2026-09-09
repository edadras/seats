<?php

/**
 * The door scanner.
 *
 * This catalogue is the source; the app carries a generated copy of it (ADR-0005). That is the one
 * place on the platform where a second copy of a translation is correct rather than a mistake: the
 * scanner has to work with no network, and a catalogue it fetches is a catalogue it cannot fetch at
 * the moment it is needed. `tools/sync-checkin-strings.mjs` regenerates the copy and CI fails if it
 * has drifted, so there is still only one file anybody edits.
 *
 * Every string here is read at arm's length, in the dark, by somebody who is also talking to a
 * person in a queue. Short words, and the answer before the explanation.
 */
return [
    'title' => 'Seatmap check-in',
    /* Shown by the page itself, before the app has loaded a single frame. */
    'boot' => 'Loading the scanner…',

    'language' => 'Language',
    'cancel' => 'Cancel',
    'defaultDeviceName' => 'Door scanner',
    'thisDevice' => 'This device',

    /* Between a section, a row and a seat. A middle dot reads as a digit in Persian and Arabic. */
    'listSeparator' => ' · ',

    'pair' => [
        'title' => 'Pair this scanner',
        'subtitle' => 'Get a pairing code from the organiser panel. It works once, and it expires.',
        'address' => 'Seatmap address',
        'code' => 'Pairing code',
        'deviceName' => 'Name this device',
        'deviceNameHint' => 'Shown in the panel, so staff know which door is which.',
        'submit' => 'Pair',
        'incomplete' => 'Fill in the address and the pairing code.',
        'unreachable' => 'Could not reach that address. Check the venue Wi-Fi and the address above.',
    ],

    'events' => [
        'title' => 'Choose an event',
        'refresh' => 'Refresh',
        'queuedOne' => '1 scan still to send',
        'queuedMany' => ':count scans still to send',
        'unpair' => 'Unpair this device',
        'emptyTitle' => 'This device is not allowed to scan anything yet.',
        'emptyBody' => 'Give it an event in the organiser panel, then refresh.',
    ],

    'unpair' => [
        'title' => 'Unpair this device?',
        // Said plainly: those are people who are already inside.
        'withQueue' => 'There are still :count scans waiting to be sent. They are kept, but this device will need pairing again before it can send them.',
        'clean' => 'You will need a new pairing code to use this scanner again.',
        'confirm' => 'Unpair',
    ],

    'scan' => [
        'typeTitle' => 'Type the code',
        'ticketCode' => 'Ticket code',
        'check' => 'Check',
        'typeCode' => 'Type a code',
        'torch' => 'Torch',
        'switchCamera' => 'Switch camera',
        'countedIn' => ':count in',
        'stillToCome' => ':count still to come',
        'noCameraTitle' => 'No camera here',
        'noCameraBody' => 'Allow camera access in the browser, or type codes in by hand.',
        'next' => 'Next',
        'firstScanned' => 'First scanned at :time',
        'firstScannedBy' => 'First scanned at :time · :by',
        'row' => 'row :row',
        'seat' => 'seat :seat',
    ],

    /* One word each, because that is what gets read. The detail is for the second glance. */
    'result' => [
        'valid' => ['headline' => 'Come in', 'detail' => 'Admitted.'],
        'alreadyUsed' => ['headline' => 'Already used', 'detail' => 'Someone has already come in on this ticket.'],
        'cancelled' => ['headline' => 'Cancelled', 'detail' => 'This booking was cancelled.'],
        'refunded' => ['headline' => 'Refunded', 'detail' => 'This booking was refunded.'],
        'wrongEvent' => ['headline' => 'Wrong event', 'detail' => 'This ticket is for another performance.'],
        'invalid' => ['headline' => 'Not a ticket', 'detail' => 'This code is not one of ours.'],
        'queued' => ['headline' => 'Saved offline', 'detail' => 'No connection. It will be sent when you are back online.'],
    ],

    'status' => [
        'online' => 'Online',
        'offline' => 'Offline — scans are being saved',
        'waiting' => ':count waiting',
    ],

    'failure' => [
        'offline' => 'No connection.',
        'generic' => 'That did not work.',
    ],

    /*
     * Month names, in the calendar this language's readers use — Persian for Persian, Gregorian for
     * everyone else, exactly as ADR-0005 §5 decides it. The scanner shows a date so that a
     * volunteer picks the right performance, and a date they have to convert is a date that gets
     * picked wrong.
     */
    'months' => [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'May',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Aug',
        9 => 'Sep',
        10 => 'Oct',
        11 => 'Nov',
        12 => 'Dec',
    ],
    'dateTime' => ':day :month :year · :time',
];
