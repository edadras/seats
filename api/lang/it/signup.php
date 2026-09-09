<?php

/**
 * Signing yourself up.
 *
 * The verification email is here rather than in a Blade template because it is four lines long and
 * says one thing. Its placeholders are Laravel's `:name` — unlike the messaging templates, this is
 * not a string an organiser edits.
 */
return [
    'title' => 'Crea un account',
    'subtitle' => 'Una sala, un sito e una biglietteria. Niente da installare.',
    'name' => 'Il tuo nome',
    'organisation' => 'Sede o società',
    'email' => [
        'subject' => 'Il tuo codice di verifica Seatmap',
        'body' => "Ciao :name,\n\nIl tuo codice di verifica è :code.\n\nVale un giorno. Se non hai creato un account ignora questo messaggio — senza il codice non succede nulla.",
    ],
    'password' => 'Password',
    'passwordHint' => 'Almeno dodici caratteri. Protegge una biglietteria.',
    'plan' => 'Piano',
    'create' => 'Crea l’account',
    'haveAccount' => 'Hai già un account? Accedi',
    'newAccount' => 'Crea un account',
    'creating' => 'Preparazione in corso…',
    'welcome' => 'Benvenuto. Il tuo sito è pronto da modificare.',
    'verifyTitle' => 'Controlla la posta',
    'verifyBody' => 'Abbiamo inviato un codice di sei cifre a :email. Scrivilo qui — intanto puoi continuare.',
    'code' => 'Codice',
    'verify' => 'Verifica',
    'verified' => 'E-mail verificata. Grazie.',
    'resend' => 'Invia di nuovo',
    'resent' => 'Inviato. Può metterci un minuto.',
    'unverifiedNotice' => 'Verifica l’indirizzo e-mail per pubblicare un sito su internet.',
    'free' => 'Gratis',
    'perMonth' => 'al mese',
    'perYear' => 'all’anno',
    'limits' => [
        'events' => ':count eventi',
        'seats' => ':count posti per pianta',
        'sites' => ':count siti',
        'unlimited' => 'Illimitato',
    ],
];
