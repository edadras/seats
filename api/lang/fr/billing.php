<?php

/**
 * The platform's own bill.
 *
 * Read by an organiser looking at what they owe for the software, and written onto the invoice they
 * forward to whoever pays it. Their own takings are `panel.settlement`; this is the other side of
 * the same relationship, and the only place in this platform where the platform is the merchant.
 */
return [
    'invoiceDescription' => 'Facture :number',

    'lines' => [
        'plan' => 'Formule :plan',
        'commission' => 'Commission de :rate % sur vos ventes',
    ],

    'errors' => [
        'pay_by_transfer' => 'Cette facture se règle par virement. Rien n’a été prélevé.',
        'no_card_on_file' => 'Aucune carte n’est enregistrée pour ce compte.',
        'card_refused' => 'La carte a été refusée (:code).',
        'needs_the_cardholder' => 'La banque veut que le titulaire de la carte valide ce paiement. Ouvrez votre page de facturation et payez-le là.',
        'unreachable' => 'Le service de paiement était injoignable, rien n’a été prélevé. Une nouvelle tentative aura lieu.',
    ],
];
