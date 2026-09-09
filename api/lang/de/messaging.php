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
        'event_reminder' => [
            'name' => 'Erinnerung',
            'description' => 'Am Vortag, an alle mit Ticket. Aus, bis Sie sie einschalten.',
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
        'event_reminder' => [
            'subject' => '{event} ist morgen',
            'body' => "{buyer}, {event} ist morgen.\n\n{venue}\n{starts}\nPlätze: {seats}\n\nBuchungsnummer {reference}.",
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
    'announceEvent' => 'Veranstaltung',
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
