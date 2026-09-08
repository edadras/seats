<?php

/**
 * Die Modul-Ansicht und die Texte jedes eingebauten Moduls.
 *
 * Die Texte eingebauter Module stehen hier statt neben ihrem Code, damit dieselbe CI-Prüfung sie
 * schützt, die auch die übrigen sechs Sprachen schützt. Ein Fremdmodul registriert seinen eigenen
 * Übersetzungsnamensraum — siehe docs/MODULES.md.
 */
return [
    'title' => 'Module',
    'subtitle' => 'Was dieses Konto über die Grundlagen hinaus kann. Einschalten, einrichten, und es erscheint dort, wo es hingehört.',
    'installed' => 'Verfügbar',
    'enabled' => 'An',
    'disabled' => 'Aus',
    'enable' => 'Einschalten',
    'disable' => 'Ausschalten',
    'configure' => 'Einstellungen',
    'secretSet' => 'Gesetzt — zum Ändern ersetzen',
    'secretUnset' => 'Nicht gesetzt',
    'byVendor' => 'von :vendor',
    'firstParty' => 'Eingebaut',
    'health' => 'Zustand',
    'healthy' => 'Läuft',
    'failing' => ':count Probleme am letzten Tag',
    'autoDisabled' => 'Nach wiederholten Fehlern automatisch abgeschaltet: :reason',
    'lastFailure' => 'Letztes Problem :when',
    'noFailures' => 'Nichts ist schiefgegangen.',
    'extends' => [
        'payments' => 'Zahlungen',
        'messaging' => 'Nachrichten',
        'reports' => 'Berichte',
        'blocks' => 'Seitenblöcke',
        'themes' => 'Designs',
        'panel' => 'Panel-Ansichten',
        'events' => 'Automatisierungen',
    ],
    'errors' => [
        'required' => 'Das wird gebraucht, bevor das Modul eingeschaltet werden kann.',
        'invalid' => 'Diesen Wert nimmt die Einstellung nicht an.',
        'not_installed' => 'Dieses Modul ist auf diesem Server nicht installiert.',
        'not_configured' => 'Tragen Sie ein, was dieses Modul braucht, bevor Sie es einschalten.',
    ],
    'seatmap' => [
        'offline_payments' => [
            'name' => 'An der Abendkasse zahlen',
            'description' => 'Online reservieren, bei Ankunft bezahlen. Plätze werden reserviert und Tickets ausgestellt wie bei einer Kartenzahlung; nur das Geld wird anderswo eingenommen.',
            'settings' => [
                'instructions' => 'Was der Käuferin oder dem Käufer gesagt wird',
                'instructions_hint' => 'Erscheint an der Kasse statt des Standardtexts. Nennen Sie, wo und wann bezahlt werden kann.',
            ],
        ],
    ],
];
