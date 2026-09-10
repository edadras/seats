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
    'title' => 'Nachrichten',
    'subtitle' => 'Was einer Käuferin gesagt wird, über welche Kanäle, in ihrer eigenen Sprache.',
    'kinds' => [
        'order_confirmed' => [
            'name' => 'Bestellung bestätigt',
            'description' => 'Geht raus, sobald die Zahlung durch ist. Die eine Nachricht, auf die ein Käufer Anspruch hat.',
        ],
        'order_cancelled' => [
            'name' => 'Bestellung storniert',
            'description' => 'Bei Stornierung oder Rückerstattung.',
        ],
        'order_unfinished' => [
            'name' => 'Nicht beendete Buchung',
            'description' => 'Einmal, eine Stunde später, an jemanden, dessen Zahlung nie abgeschlossen wurde. Es geht um seine eigene Buchung und enthält eine Möglichkeit abzulehnen. Aus, bis Sie es einschalten.',
        ],
        'event_reminder' => [
            'name' => 'Erinnerung',
            'description' => 'Am Vortag, an alle mit Ticket. Aus, bis Sie sie einschalten.',
        ],
        'event_cancelled' => [
            'name' => "Veranstaltung abgesagt",
            'description' => "Geht an alle mit einem Ticket, wenn ein Abend ausfällt. Nicht abschaltbar — jemand hat für einen Platz bezahlt, den es nicht mehr gibt.",
        ],
        'event_moved' => [
            'name' => "Termin verlegt",
            'description' => "Geht raus, wenn sich ein Datum ändert. Dass das Ticket weiter gilt, steht zuerst darin.",
        ],
        'waitlist_available' => [
            'name' => 'Ein Platz ist frei geworden',
            'description' => 'Geht an die Nächsten auf einer Warteliste, wenn Plätze zurückkommen. Genau darum haben sie gebeten, also lässt es sich nicht abschalten.',
        ],
    ],
    'channels' => [
        'email' => 'E-Mail',
    ],
    'defaults' => [
        'system_notice' => [
            'subject' => 'Eine Meldung von {account}: {title}',
            'body' => "{title}\n\n{body}\n\nDies ist ein automatischer Hinweis aus Ihrem Seatmap-Konto.",
        ],
        'order_confirmed' => [
            'subject' => 'Ihre Tickets für {event}',
            'body' => "{buyer}, Ihre Tickets sind gebucht.\n\n{event}\n{venue}\n{starts}\nPlätze: {seats}\nGesamt: {total}\n\nBuchungsnummer {reference}.",
        ],
        'order_cancelled' => [
            'subject' => 'Ihre Buchung {reference} wurde storniert',
            'body' => "{buyer}, Ihre Buchung für {event} wurde storniert.\n\nBuchungsnummer {reference}.",
        ],
        'order_unfinished' => [
            'subject' => 'Ihre Buchung für {event} ist nicht abgeschlossen',
            'body' => "{buyer}, Ihre Zahlung für {event} ist nicht durchgegangen, die Buchung wurde also nie abgeschlossen.\n\n{venue}\n{starts}\nPlätze: {seats}\nSumme: {total}\n\nWenn Sie sie noch möchten, machen Sie hier weiter — wir versuchen, Ihnen dieselben Plätze zu geben:\n{link}\n\nWenn Sie es sich anders überlegt haben, ist keine Antwort nötig. Um zu dieser Buchung nichts mehr zu hören: {decline}",
        ],
        'event_reminder' => [
            'subject' => '{event} ist morgen',
            'body' => "{buyer}, {event} ist morgen.\n\n{venue}\n{starts}\nPlätze: {seats}\n\nBuchungsnummer {reference}.",
        ],
        'event_cancelled' => [
            'subject' => "Abgesagt: {event}",
            'body' => "{buyer}, leider müssen wir Ihnen mitteilen, dass {event} am {starts} im {venue} abgesagt wurde.\n\n{reason}\n\nIhre Buchung {reference} wurde erstattet. Es ist nichts offen, und Ihre Tickets sind nicht mehr gültig.",
        ],
        'event_moved' => [
            'subject' => "Neuer Termin: {event}",
            'body' => "{buyer}, {event} wurde von {was} auf {starts} im {venue} verlegt.\n\n{reason}\n\nIhre Tickets bleiben gültig — Buchung {reference}, {seats}. Sie müssen nichts tun.",
        ],
        'waitlist_available' => [
            'subject' => 'Für {event} ist ein Platz frei geworden',
            'body' => "{buyer}, für {event} ist ein Platz zurückgekommen.\n\n{venue}\n{starts}\n\nSie wollten {quantity}. Der Verkauf ist die nächsten {hours} Stunden offen, wer zuerst kommt:\n{link}\n\nVon dieser Liste abmelden: {leave}",
        ],
    ],
    'logKinds' => [
        'announcement' => 'Ansage',
        'system_notice' => 'Systemmeldung',
    ],
    'template' => 'Wortlaut',
    'subject' => 'Betreff',
    'body' => 'Nachricht',
    'locale' => 'Sprache',
    'usingDefault' => 'Es gilt unser Wortlaut. Schreiben Sie Ihren, um ihn zu ersetzen.',
    'placeholders' => 'Verfügbar: :list',
    'save' => 'Wortlaut speichern',
    'saved' => 'Wortlaut gespeichert.',
    'reset' => 'Zurück zu unserem Wortlaut',
    'resetDone' => 'Zurück zu unserem Wortlaut.',
    'channelsOn' => 'Versand über',
    'channelHint' => 'Wer für einen Kanal keine Adresse hat, bekommt darüber nichts.',
    'required' => 'Immer',
    'log' => 'Versandprotokoll',
    'noLog' => 'Noch nichts verschickt',
    'noLogHint' => 'Bestätigungen erscheinen hier, sobald jemand kauft.',
    'status' => [
        'queued' => 'In Warteschlange',
        'sent' => 'Verschickt',
        'refused' => 'Abgelehnt',
        'unavailable' => 'Nicht erreichbar',
    ],
    'recipient' => 'An',
    'when' => 'Wann',
    'attempts' => 'Versuche',
    'reason' => 'Grund',
    'test' => 'Test senden',
    'testTo' => 'Senden an',
    'testSent' => 'Test verschickt. Sehen Sie im Protokoll nach.',
    'testHint' => 'Nimmt Ihren Wortlaut mit Beispielwerten, damit Sie sehen, was ankommt.',
    'filterAll' => 'Alle Status',
    'preview' => 'Vorschau',
    'example' => [
        'buyer' => 'Alex Müller',
        'event' => 'Premiere',
        'venue' => 'Northgate Theater',
        'seats' => 'Parkett A 12, Parkett A 13',
    ],
    'announceHeading' => 'Ansagen',
    'announceIntro' => 'Schreiben Sie allen, die gekauft haben — eine verlegte Vorstellung, ein anderer Eingang, ein Dankeschön.',
    'announceNew' => 'Ansage schreiben',
    'announceAudience' => 'An wen',
    'audienceEveryone' => 'Alle, die bei Ihnen gekauft haben',
    'audienceEvent' => 'Käufer einer Veranstaltung',
    'announceChannels' => 'Verschicken über',
    'announceSubject' => 'Betreff',
    'announceSubjectHint' => 'Nur für E-Mail. Eine SMS hat keinen Betreff.',
    'announceBody' => 'Nachricht',
    'announceBodyHint' => 'Sie können {buyer} und {site} verwenden. {event} funktioniert, wenn Sie an die Käufer einer Veranstaltung schreiben.',
    'announceReach' => ':people Personen · :messages Nachrichten',
    'announceReachNobody' => 'Dazu passt noch niemand.',
    'announceSend' => 'Abschicken',
    'announceConfirm' => 'Diese Ansage verschicken?',
    'announceConfirmBody' => 'Sie geht an :messages Nachrichten und lässt sich nicht zurückholen.',
    'announceSent' => 'Unterwegs.',
    'announceNone' => 'Noch nichts angesagt',
    'announceNoneHint' => 'Eine Ansage geht an die Leute, die gekauft haben, über die Kanäle Ihrer Wahl.',
    'announceStatusDraft' => 'Entwurf',
    'announceStatusSending' => 'Wird verschickt',
    'announceStatusSent' => 'Verschickt',
    'announceProgress' => ':sent von :total verschickt',
    'announceFailed' => ':count nicht zustellbar',
    'announceWhen' => 'Geschrieben',
    'announceOnlyPaid' => 'Geschrieben wird nur an Leute, deren Bestellung bezahlt ist.',
];
