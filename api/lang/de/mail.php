<?php

/**
 * Ticket-E-Mail.
 *
 * Das ist die eine Nachricht, die im Bus, auf dem Handy und sechs Wochen nach ihrer Ankunft noch
 * lesbar sein muss – sie nennt also Veranstaltung, Datum und Plätze und nichts, was zum Anzeigen
 * ein Netz braucht.
 */
return [
    'title' => 'Ihre Tickets',
    'subject' => 'Ihre Tickets für :event',
    'intro' => 'Vielen Dank. Zeigen Sie am Einlass einen der Codes unten – einen je Platz. Sie funktionieren aus dieser E-Mail wie von Ihrer Buchungsseite.',
    'standing' => 'Stehplatz',
    'keepThis' => 'Buchungsnummer :reference. Bewahren Sie diese E-Mail auf – wer einen Code hat, kommt damit herein.',
    'sender' => [
        'subject' => 'Diese Adresse für :venue bestätigen',
        'body' => "Jemand bei :venue möchte, dass Antworten auf Ticket-E-Mails an diese Adresse gehen.\n\nGeben Sie diesen Code im Nachrichten-Bildschirm ein, um sie zu bestätigen:\n\n:code\n\nDer Code gilt einen Tag. Waren Sie das nicht, ignorieren Sie diese E-Mail — ohne den Code ändert sich nichts.",
    ],
];
