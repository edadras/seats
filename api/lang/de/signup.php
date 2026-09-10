<?php

/**
 * Signing yourself up.
 *
 * The verification email is here rather than in a Blade template because it is four lines long and
 * says one thing. Its placeholders are Laravel's `:name` — unlike the messaging templates, this is
 * not a string an organiser edits.
 */
return [
    'title' => 'Konto anlegen',
    'subtitle' => 'Ein Haus, eine Website und eine Kasse. Nichts zu installieren.',
    'name' => 'Ihr Name',
    'organisation' => 'Spielstätte oder Firma',
    'email' => [
        'subject' => 'Ihr Seatmap-Bestätigungscode',
        'body' => "Hallo :name,\n\nIhr Bestätigungscode lautet :code.\n\nEr gilt einen Tag. Falls Sie kein Konto angelegt haben, ignorieren Sie diese Nachricht — ohne den Code passiert nichts.",
    ],
    'password' => 'Passwort',
    'passwordHint' => 'Mindestens zwölf Zeichen. Damit ist eine Kasse geschützt.',
    'plan' => 'Tarif',
    'create' => 'Konto anlegen',
    'haveAccount' => 'Schon ein Konto? Anmelden',
    'newAccount' => 'Konto anlegen',
    'creating' => 'Wird eingerichtet…',
    'welcome' => 'Willkommen. Ihre Website lässt sich jetzt bearbeiten.',
    'verifyTitle' => 'Sehen Sie in Ihr Postfach',
    'verifyBody' => 'Wir haben einen sechsstelligen Code an :email geschickt. Tragen Sie ihn hier ein — Sie können inzwischen weiterarbeiten.',
    'code' => 'Code',
    'verify' => 'Bestätigen',
    'verified' => 'E-Mail bestätigt. Danke.',
    'resend' => 'Nochmal senden',
    'resent' => 'Gesendet. Es kann eine Minute dauern.',
    'free' => 'Kostenlos',
    'perMonth' => 'im Monat',
    'perYear' => 'im Jahr',
    'limits' => [
        'events' => ':count Veranstaltungen',
        'seats' => ':count Plätze je Plan',
        'sites' => ':count Websites',
        'unlimited' => 'Unbegrenzt',
    ],
];
