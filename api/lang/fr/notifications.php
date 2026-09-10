<?php

/** Ce que la plateforme signale à l’organisateur. La phrase est composée à la lecture, pas à l’écriture. */

return [
    'kinds' => [
        'order_refunded' => [
            'title' => 'Commande remboursée',
            'body' => 'La réservation :reference pour :event a été remboursée — :seats place(s).',
        ],
        'refund_requested' => [
            'title' => 'Remboursement demandé',
            'body' => 'Commande :reference pour :event — l’acheteur demande à être remboursé. :reason',
        ],
        'event_sold_out' => [
            'title' => 'Complet',
            'body' => ':event a vendu toutes ses places : :seats en tout.',
        ],
        'announcement_finished' => [
            'title' => 'Annonce envoyée',
            'body' => '« :subject » est parti vers :sent messages sur :total.',
        ],
        'message_refused' => [
            'title' => 'Un message a été refusé',
            'body' => 'Le canal :channel a refusé un message et ne réessaiera pas : :reason. Vérifiez ses réglages — un acheteur n’a peut-être pas été prévenu.',
        ],
        'domain_verified' => [
            'title' => 'Adresse vérifiée',
            'body' => ':hostname est vérifié et peut servir :site.',
        ],
    ],
];
