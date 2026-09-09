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
    'subtitle' => 'Ce qu’on dit à l’acheteur, par quels canaux, dans sa langue.',
    'kinds' => [
        'order_confirmed' => [
            'name' => 'Commande confirmée',
            'description' => 'Part dès que le paiement aboutit. Le seul message auquel un acheteur a droit.',
        ],
        'order_cancelled' => [
            'name' => 'Commande annulée',
            'description' => 'Part à l’annulation ou au remboursement.',
        ],
        'event_reminder' => [
            'name' => 'Rappel',
            'description' => 'La veille, à tous les détenteurs de billets. Désactivé tant que vous ne l’activez pas.',
        ],
    ],
    'channels' => [
        'email' => 'E-mail',
    ],
    'defaults' => [
        'order_confirmed' => [
            'subject' => 'Vos billets pour {event}',
            'body' => "{buyer}, vos billets sont réservés.\n\n{event}\n{venue}\n{starts}\nPlaces : {seats}\nTotal : {total}\n\nRéférence {reference}.",
        ],
        'order_cancelled' => [
            'subject' => 'Votre réservation {reference} est annulée',
            'body' => "{buyer}, votre réservation pour {event} a été annulée.\n\nRéférence {reference}.",
        ],
        'event_reminder' => [
            'subject' => '{event}, c’est demain',
            'body' => "{buyer}, {event} a lieu demain.\n\n{venue}\n{starts}\nPlaces : {seats}\n\nRéférence {reference}.",
        ],
    ],
    'template' => 'Formulation',
    'subject' => 'Objet',
    'body' => 'Message',
    'locale' => 'Langue',
    'usingDefault' => 'Notre formulation s’applique. Écrivez la vôtre pour la remplacer.',
    'placeholders' => 'Disponible : :list',
    'save' => 'Enregistrer',
    'saved' => 'Formulation enregistrée.',
    'reset' => 'Revenir à notre formulation',
    'resetDone' => 'Retour à notre formulation.',
    'channelsOn' => 'Envoyé par',
    'channelHint' => 'Sans adresse pour un canal, l’acheteur ne reçoit simplement pas celui-là.',
    'required' => 'Toujours envoyé',
    'log' => 'Journal d’envoi',
    'noLog' => 'Rien n’a encore été envoyé',
    'noLogHint' => 'Les confirmations apparaissent ici dès le premier achat.',
    'status' => [
        'queued' => 'En file',
        'sent' => 'Envoyé',
        'refused' => 'Refusé',
        'unavailable' => 'Injoignable',
    ],
    'recipient' => 'À',
    'when' => 'Quand',
    'attempts' => 'Tentatives',
    'reason' => 'Motif',
    'test' => 'Envoyer un test',
    'testTo' => 'Envoyer à',
    'testSent' => 'Test envoyé. Regardez le journal ci-dessous.',
    'testHint' => 'Utilise votre formulation avec des valeurs d’exemple, pour voir ce que voit l’acheteur.',
    'filterAll' => 'Tous les statuts',
    'preview' => 'Aperçu',
    'example' => [
        'buyer' => 'Alex Dupont',
        'event' => 'Première',
        'venue' => 'Théâtre Northgate',
        'seats' => 'Orchestre A 12, Orchestre A 13',
    ],
];
