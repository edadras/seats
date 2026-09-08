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
