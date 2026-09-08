<?php

/**
 * The modules screen, and the strings of every first-party module.
 *
 * First-party module strings live here rather than beside their code so that the same CI check
 * that guards the rest of the six languages guards them too. A third-party module registers its
 * own translation namespace — see docs/MODULES.md.
 */
return [
    'title' => 'Modules',
    'subtitle' => 'What this account can do beyond the basics. Switch one on, configure it, and it appears where it belongs.',
    'installed' => 'Available',
    'enabled' => 'On',
    'disabled' => 'Off',
    'enable' => 'Turn on',
    'disable' => 'Turn off',
    'configure' => 'Settings',
    'secretSet' => 'Set — replace to change',
    'secretUnset' => 'Not set',
    'byVendor' => 'by :vendor',
    'firstParty' => 'Built in',
    'health' => 'Health',
    'healthy' => 'Working',
    'failing' => ':count problems in the last day',
    'autoDisabled' => 'Switched off automatically after repeated failures: :reason',
    'lastFailure' => 'Last problem :when',
    'noFailures' => 'Nothing has gone wrong.',
    'extends' => [
        'payments' => 'Payments',
        'messaging' => 'Messaging',
        'reports' => 'Reports',
        'blocks' => 'Page blocks',
        'themes' => 'Themes',
        'panel' => 'Panel screens',
        'events' => 'Automations',
    ],
    'errors' => [
        'required' => 'This is needed before the module can be turned on.',
        'invalid' => 'That value is not one this setting accepts.',
        'not_installed' => 'That module is not installed on this server.',
        'not_configured' => 'Fill in what this module needs before turning it on.',
    ],
    'seatmap' => [
        'offline_payments' => [
            'name' => 'Pay at the box office',
            'description' => 'Let buyers reserve online and pay when they arrive. Seats are held and tickets issued exactly as for a card sale; only the money is collected elsewhere.',
            'settings' => [
                'instructions' => 'What to tell the buyer',
                'instructions_hint' => 'Shown at checkout instead of the default wording. Say where and when they can pay.',
            ],
        ],
    ],
];
