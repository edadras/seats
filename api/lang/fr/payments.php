<?php

/**
 * Ce que l'acheteur lit à propos de chaque moyen de paiement.
 *
 * Le nom d'une passerelle ne se traduit pas ; la phrase qui explique ce que ce choix implique, si —
 * car c'est elle qui décide si quelqu'un clique sereinement.
 */
return [
    'offline' => [
        'label' => 'Payer au guichet',
        'description' => 'Vos places sont réservées dès maintenant. Vous paierez en retirant vos billets.',
    ],
    'zarinpal' => [
        'label' => 'Zarinpal',
        'description' => 'Payer par carte bancaire iranienne via Zarinpal.',
    ],
    'idpay' => [
        'label' => 'IDPay',
        'description' => 'Payer par carte bancaire iranienne via IDPay.',
    ],
    'nextpay' => [
        'label' => 'NextPay',
        'description' => 'Payer par carte bancaire iranienne via NextPay.',
    ],
    'stripe' => [
        'label' => 'Carte',
        'description' => 'Payer par carte. Vos données de carte vont directement à Stripe et ne passent jamais par ce site.',
    ],
    'paypal' => [
        'label' => 'PayPal',
        'description' => 'Payer avec votre solde PayPal ou une carte, via PayPal.',
    ],
    'orderDescription' => 'Billets — commande :reference',
    'errors' => [
        'declined' => ':gateway n’a pas pu encaisser le paiement (code :code). Rien n’a été débité.',
        'currency_not_supported' => ':gateway n’accepte pas les paiements en :currency.',
        'not_configured' => ':gateway n’est pas encore configuré. Choisissez un autre moyen de paiement.',
        'no_reference' => 'Ce paiement est introuvable. Si de l’argent a quitté votre compte, contactez la billetterie avec votre numéro de commande.',
        'cancelled_by_buyer' => 'Le paiement a été annulé. Vos places ont été libérées.',
        'refund_by_hand' => 'Ce paiement ne peut pas être remboursé automatiquement. Les places sont libérées et l’argent est dû à l’acheteur en personne.',
        'refund_unreachable' => 'La passerelle de paiement était injoignable ; rien n’a été remboursé. Réessayez.',
        'nothing_to_refund' => 'Il n’y a rien à rembourser : cette réservation n’a pas été payée en argent.',
    ],
];
