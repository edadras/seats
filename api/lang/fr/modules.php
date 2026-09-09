<?php

/**
 * L'écran des modules et les textes de chaque module intégré.
 *
 * Les textes des modules intégrés vivent ici plutôt qu'à côté de leur code, pour que la même
 * vérification CI qui garde les six langues les garde aussi. Un module tiers enregistre son propre
 * espace de traduction — voir docs/MODULES.md.
 */
return [
    'title' => 'Modules',
    'subtitle' => 'Ce que ce compte sait faire au-delà de l’essentiel. Activez-en un, réglez-le, et il apparaît là où il doit.',
    'installed' => 'Disponibles',
    'enabled' => 'Activé',
    'disabled' => 'Désactivé',
    'enable' => 'Activer',
    'disable' => 'Désactiver',
    'configure' => 'Réglages',
    'secretSet' => 'Défini — remplacez-le pour le changer',
    'secretUnset' => 'Non défini',
    'byVendor' => 'par :vendor',
    'firstParty' => 'Intégré',
    'health' => 'État',
    'healthy' => 'Fonctionne',
    'failing' => ':count problèmes depuis un jour',
    'autoDisabled' => 'Désactivé automatiquement après des échecs répétés : :reason',
    'lastFailure' => 'Dernier problème :when',
    'noFailures' => 'Rien n’a échoué.',
    'extends' => [
        'payments' => 'Paiements',
        'messaging' => 'Messages',
        'reports' => 'Rapports',
        'blocks' => 'Blocs de page',
        'themes' => 'Thèmes',
        'panel' => 'Écrans du panneau',
        'events' => 'Automatisations',
    ],
    'errors' => [
        'required' => 'Ceci est nécessaire avant d’activer le module.',
        'invalid' => 'Ce réglage n’accepte pas cette valeur.',
        'not_installed' => 'Ce module n’est pas installé sur ce serveur.',
        'not_configured' => 'Renseignez ce dont ce module a besoin avant de l’activer.',
    ],
    'seatmap' => [
        'zarinpal' => [
            'name' => 'Zarinpal',
            'description' => 'Accepter les cartes bancaires iraniennes via Zarinpal. Prix en rials ou en tomans — précisez-le, l’écart est d’un facteur dix.',
            'settings' => [
                'merchant_id' => 'Identifiant marchand',
                'merchant_id_hint' => 'L’UUID de votre panneau Zarinpal.',
                'amount_unit' => 'Vos prix sont en',
                'amount_unit_hint' => 'Zarinpal règle en rials. Si vous affichez en tomans, choisissez tomans et nous multiplions.',
                'sandbox' => 'Utiliser le bac à sable',
                'sandbox_hint' => 'Paiements de test. Aucun argent ne circule.',
            ],
        ],
        'idpay' => [
            'name' => 'IDPay',
            'description' => 'Accepter les cartes bancaires iraniennes via IDPay.',
            'settings' => [
                'api_key' => 'Clé d’API',
                'api_key_hint' => 'Depuis votre tableau de bord IDPay, pour ce site.',
                'amount_unit' => 'Vos prix sont en',
                'amount_unit_hint' => 'IDPay règle en rials. Si vous affichez en tomans, choisissez tomans.',
                'sandbox' => 'Utiliser le bac à sable',
                'sandbox_hint' => 'Envoie l’en-tête bac à sable. Aucun argent ne circule.',
            ],
        ],
        'nextpay' => [
            'name' => 'NextPay',
            'description' => 'Accepter les cartes bancaires iraniennes via NextPay.',
            'settings' => [
                'api_key' => 'Clé d’API',
                'api_key_hint' => 'Depuis votre panneau NextPay.',
                'amount_unit' => 'Vos prix sont en',
                'amount_unit_hint' => 'NextPay règle en rials. Si vous affichez en tomans, choisissez tomans.',
            ],
        ],
        'stripe' => [
            'name' => 'Stripe',
            'description' => 'Accepter les cartes dans le monde entier via Stripe Checkout. Les données de carte vont à Stripe, jamais à ce serveur.',
            'settings' => [
                'secret_key' => 'Clé secrète',
                'secret_key_hint' => 'Commence par sk_live_ ou sk_test_. En écriture seule : stockée chiffrée, jamais réaffichée.',
                'statement_descriptor' => 'Sur le relevé de l’acheteur',
                'statement_descriptor_hint' => 'Jusqu’à 22 caractères après votre raison sociale. Vide reprend votre réglage Stripe.',
            ],
        ],
        'paypal' => [
            'name' => 'PayPal',
            'description' => 'Accepter les soldes PayPal et les cartes via PayPal Orders.',
            'settings' => [
                'client_id' => 'Client ID',
                'client_id_hint' => 'Depuis les identifiants de votre application PayPal.',
                'client_secret' => 'Client secret',
                'client_secret_hint' => 'En écriture seule : stocké chiffré, jamais réaffiché.',
                'sandbox' => 'Utiliser le bac à sable',
                'sandbox_hint' => 'Parle au bac à sable PayPal plutôt qu’à l’API réelle.',
            ],
        ],
        'offline_payments' => [
            'name' => 'Payer au guichet',
            'description' => 'L’acheteur réserve en ligne et paie en arrivant. Les places sont retenues et les billets émis exactement comme pour un paiement par carte ; seul l’encaissement se fait ailleurs.',
            'settings' => [
                'instructions' => 'Ce qu’on dit à l’acheteur',
                'instructions_hint' => 'Affiché au paiement à la place du texte par défaut. Précisez où et quand payer.',
            ],
        ],
    ],
];
