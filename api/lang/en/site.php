<?php

/**
 * Strings a hosted event site shows a buyer.
 *
 * The picker's own vocabulary lives under `picker` and is handed to it as one object, because the
 * picker is shared with the WordPress plugin and takes every string from whoever boots it.
 *
 * The placeholders under `picker` are `%s` / `%d` / `%1$s` rather than Laravel's `:name`: the
 * picker substitutes them itself, in the browser, and it learned that shape from WordPress.
 * Changing it would mean two versions of the picker, which is the thing tools/sync-seat-picker.sh
 * exists to prevent. Everything outside `picker` uses `:name`.
 */
return [
    'picker' => [
        'selectSeats' => 'Select your seats',
        'available' => 'Available',
        'unavailable' => 'Unavailable',
        'selected' => 'Selected',
        'yourSelection' => 'Your selection',
        'noneSelected' => 'No seats selected yet.',
        'total' => 'Total',
        'addToCart' => 'Reserve these seats',
        'working' => 'Reserving…',
        'seatTaken' => 'Sorry, one of those seats was just taken. It has been removed from your selection.',
        'genericError' => 'Something went wrong. Please try again.',
        'maxSeats' => 'You can select up to %d seats.',
        'seatLabel' => '%1$s, row %2$s, seat %3$s — %4$s',
        'seatUnavailable' => '%1$s, row %2$s, seat %3$s — unavailable',
        'zoomIn' => 'Zoom in',
        'zoomOut' => 'Zoom out',
        'resetView' => 'Reset view',
        'held' => 'Seats held until %s',
        'expired' => 'Your reservation expired. Please choose your seats again.',
        'stage' => 'Stage',
        'standingAreas' => 'Standing and tables',
        'placesLeft' => '%d left',
        'soldOut' => 'Sold out',
        'addOne' => 'Add one place in %s',
        'removeOne' => 'Remove one place in %s',
        'areaFull' => 'That area filled up while you were choosing. Please pick a different number of places.',
        'floors' => 'Floor',
        // Block view: the plan of sections a buyer sees before zooming into one.
        'chooseSection' => 'Choose an area',
        'backToPlan' => 'Back to the whole venue',
        'sectionFrom' => 'From %s',
        'sectionSeatsLeft' => '%d seats left',
        'sectionSoldOut' => 'Sold out',
        'openSection' => 'Show seats in %s',
        'inSection' => 'In %s',
    ],

    /* The block vocabulary, as the panel's block picker names it (Blocks::describe). */
    'blocks' => [
        'heading' => 'Heading',
        'richText' => 'Text',
        'image' => 'Image',
        'buttons' => 'Buttons',
        'eventList' => 'What’s on',
        'eventDetail' => 'Event and seat picker',
        'faq' => 'Questions',
        'venueMap' => 'Finding us',
        'divider' => 'Divider',
        'html' => 'Custom HTML',
    ],

    /* The starter site every new account is given, written in that site's own language. */
    'seed' => [
        'home' => 'Home',
        'event' => 'Event',
        'visiting' => 'Visiting',
        'welcome' => 'Welcome. Tickets for everything we’ve got coming up are below.',
        'whatsOn' => 'What’s on',
        'findingUs' => 'Finding us',
        'beforeYouCome' => 'Before you come',
        'doorsQuestion' => 'When do doors open?',
        'doorsAnswer' => 'Usually half an hour before the start time.',
        'refundQuestion' => 'Can I get a refund?',
        'refundAnswer' => 'Tell your customers your policy here.',
        'header' => 'Header',
        'footer' => 'Footer',
    ],

    // --- Site chrome ------------------------------------------------------------------------
    'skipToContent' => 'Skip to content',
    'mainNav' => 'Main',
    'footerNav' => 'Footer',
    'language' => 'Language',

    // --- Event listings and detail ----------------------------------------------------------
    'whatsOn' => 'What’s on',
    'noEvents' => 'Nothing is on sale at the moment.',
    'bookNow' => 'Book now',
    'from' => 'From :price',
    'soldOut' => 'Sold out',
    'doorsOpen' => 'Doors open at :time',
    'eventDate' => ':date at :time',

    // --- Checkout ---------------------------------------------------------------------------
    'checkout' => 'Checkout',
    'whoFor' => 'Who are the tickets for?',
    'name' => 'Name',
    'email' => 'Email',
    'emailHint' => 'Your tickets are sent here.',
    'phone' => 'Phone',
    'optional' => '(optional)',
    'howToPay' => 'How would you like to pay?',
    'confirmBooking' => 'Confirm booking',
    'yourSeats' => 'Your seats',
    'total' => 'Total',
    'heldUntil' => 'Held until :time.',
    'holdExpired' => 'Your reservation expired. Please choose your seats again.',

    // --- Confirmation -----------------------------------------------------------------------
    'orderConfirmed' => 'You’re going',
    'orderReference' => 'Booking :reference',
    'ticketsSentTo' => 'Your tickets are on their way to :email.',
    'ticketsBelow' => 'They are also below — show one of these at the door.',
    'payAtDoor' => 'Pay at the box office when you arrive.',
    'addToCalendar' => 'Add to calendar',
    'printTickets' => 'Print tickets',
    'seat' => 'Seat',
    'section' => 'Section',
    'row' => 'Row',
    'admits' => 'Admits one',

    // --- Visiting ---------------------------------------------------------------------------
    'findingUs' => 'Finding us',
    'questions' => 'Questions',

    // --- Confirmation page and empty states ------------------------------------------------
    'bookedHeading' => 'You’re booked',
    'orderLine' => 'Booking :reference · :event',
    'showCodeAtDoor' => 'Show a code at the door — one for each seat. We’ve emailed them to you as well.',
    'codesByEmail' => 'Your tickets are on their way by email. The codes are shown only once here, so check your inbox.',
    'bookingStatus' => 'This booking is :status. If that looks wrong, contact the box office and quote :reference.',
    'standing' => 'Standing',
    'emptyPage' => 'This page has nothing on it yet.',
    'nothingOnSale' => 'Nothing on sale just now. Check back soon.',
    'book' => 'Book',
    'status' => [
        'pending' => 'awaiting payment',
        'confirmed' => 'confirmed',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded',
        'partially_refunded' => 'partly refunded',
    ],
    'closed' => [
        'cancelled' => 'This performance has been cancelled.',
        'closed' => 'Booking for this performance has closed.',
        'notYet' => 'Tickets for this performance are not on sale yet.',
    ],
];
