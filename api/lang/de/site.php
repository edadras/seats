<?php

/**
 * Texte, die eine gehostete Veranstaltungswebsite einer Käuferin oder einem Käufer zeigt.
 *
 * Der Wortschatz der Platzauswahl steht unter `picker` und wird ihr als ein Objekt übergeben: die
 * Auswahl teilt sich den Code mit dem WordPress-Plugin und bezieht jeden Text von dem, was sie
 * startet.
 *
 * Die Platzhalter unter `picker` sind %s / %d / %1$s statt :name – die Auswahl setzt sie selbst im
 * Browser ein. Außerhalb von `picker` gilt :name.
 */
return [
    'picker' => [
        'selectSeats' => 'Wählen Sie Ihre Plätze',
        'available' => 'Frei',
        'unavailable' => 'Belegt',
        'selected' => 'Ausgewählt',
        'yourSelection' => 'Ihre Auswahl',
        'noneSelected' => 'Noch keine Plätze ausgewählt.',
        'total' => 'Summe',
        'addToCart' => 'Diese Plätze reservieren',
        'working' => 'Wird reserviert…',
        'seatTaken' => 'Einer dieser Plätze wurde gerade vergeben und aus Ihrer Auswahl entfernt.',
        'genericError' => 'Etwas ist schiefgegangen. Bitte versuchen Sie es erneut.',
        'maxSeats' => 'Sie können bis zu %d Plätze wählen.',
        'seatLabel' => '%1$s, Reihe %2$s, Platz %3$s — %4$s',
        'seatUnavailable' => '%1$s, Reihe %2$s, Platz %3$s — belegt',
        'zoomIn' => 'Vergrößern',
        'zoomOut' => 'Verkleinern',
        'resetView' => 'Ansicht zurücksetzen',
        'held' => 'Plätze reserviert bis %s',
        'expired' => 'Ihre Reservierung ist abgelaufen. Bitte wählen Sie Ihre Plätze erneut.',
        'stage' => 'Bühne',
        'standingAreas' => 'Stehplätze und Tische',
        'placesLeft' => 'noch %d',
        'soldOut' => 'Ausverkauft',
        'addOne' => 'Einen Platz in %s hinzufügen',
        'removeOne' => 'Einen Platz in %s entfernen',
        'areaFull' => 'Dieser Bereich ist während Ihrer Auswahl voll geworden. Bitte wählen Sie eine andere Anzahl.',
        'floors' => 'Ebene',
        'chooseSection' => 'Bereich wählen',
        'backToPlan' => 'Zurück zum ganzen Saal',
        'sectionFrom' => 'Ab %s',
        'sectionSeatsLeft' => 'noch %d Plätze',
        'sectionSoldOut' => 'Ausverkauft',
        'openSection' => 'Plätze in %s anzeigen',
        'inSection' => 'In %s',
    ],

    // --- Rahmen der Website -----------------------------------------------------------------
    'skipToContent' => 'Zum Inhalt springen',
    'mainNav' => 'Hauptmenü',
    'footerNav' => 'Fußzeile',
    'language' => 'Sprache',

    // --- Programm und Veranstaltungsseite ---------------------------------------------------
    'whatsOn' => 'Programm',
    'noEvents' => 'Zurzeit ist nichts im Verkauf.',
    'bookNow' => 'Jetzt buchen',
    'from' => 'Ab :price',
    'soldOut' => 'Ausverkauft',
    'doorsOpen' => 'Einlass ab :time',
    'eventDate' => ':date um :time',

    // --- Kasse ------------------------------------------------------------------------------
    'checkout' => 'Kasse',
    'whoFor' => 'Für wen sind die Tickets?',
    'name' => 'Name',
    'email' => 'E-Mail',
    'emailHint' => 'Ihre Tickets gehen an diese Adresse.',
    'phone' => 'Telefon',
    'optional' => '(optional)',
    'howToPay' => 'Wie möchten Sie bezahlen?',
    'confirmBooking' => 'Buchung bestätigen',
    'yourSeats' => 'Ihre Plätze',
    'total' => 'Summe',
    'heldUntil' => 'Reserviert bis :time.',
    'holdExpired' => 'Ihre Reservierung ist abgelaufen. Bitte wählen Sie Ihre Plätze erneut.',

    // --- Bestätigung ------------------------------------------------------------------------
    'orderConfirmed' => 'Sie sind dabei',
    'orderReference' => 'Buchung :reference',
    'ticketsSentTo' => 'Ihre Tickets sind unterwegs an :email.',
    'ticketsBelow' => 'Sie stehen auch unten – zeigen Sie eines davon am Einlass.',
    'payAtDoor' => 'Bezahlen Sie bei Ihrer Ankunft an der Abendkasse.',
    'addToCalendar' => 'Zum Kalender hinzufügen',
    'printTickets' => 'Tickets drucken',
    'seat' => 'Platz',
    'section' => 'Bereich',
    'row' => 'Reihe',
    'admits' => 'Gültig für eine Person',

    // --- Anfahrt ----------------------------------------------------------------------------
    'findingUs' => 'So finden Sie uns',
    'questions' => 'Fragen',

    // --- Bestätigungsseite und leere Zustände ----------------------------------------------
    'bookedHeading' => 'Gebucht',
    'orderLine' => 'Buchung :reference · :event',
    'showCodeAtDoor' => 'Zeigen Sie am Einlass einen Code – einen je Platz. Wir haben sie Ihnen auch per E-Mail geschickt.',
    'codesByEmail' => 'Ihre Tickets sind per E-Mail unterwegs. Die Codes erscheinen hier nur dieses eine Mal, sehen Sie also in Ihr Postfach.',
    'bookingStatus' => 'Diese Buchung ist :status. Falls das falsch aussieht, wenden Sie sich an die Kasse und nennen Sie :reference.',
    'standing' => 'Stehplatz',
    'emptyPage' => 'Auf dieser Seite steht noch nichts.',
    'nothingOnSale' => 'Gerade ist nichts im Verkauf. Schauen Sie bald wieder vorbei.',
    'book' => 'Buchen',
    'status' => [
        'pending' => 'noch nicht bezahlt',
        'confirmed' => 'bestätigt',
        'cancelled' => 'storniert',
        'refunded' => 'erstattet',
        'partially_refunded' => 'teilweise erstattet',
    ],
    'closed' => [
        'cancelled' => 'Diese Vorstellung wurde abgesagt.',
        'closed' => 'Der Vorverkauf für diese Vorstellung ist geschlossen.',
        'notYet' => 'Tickets für diese Vorstellung sind noch nicht im Verkauf.',
    ],
];
