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
        'zarinpal' => [
            'name' => 'Zarinpal',
            'description' => 'Iranische Bankkarten über Zarinpal annehmen. Preise in Rial oder Toman — sagen Sie welche, der Unterschied ist Faktor zehn.',
            'settings' => [
                'merchant_id' => 'Händler-ID',
                'merchant_id_hint' => 'Die UUID aus Ihrem Zarinpal-Panel.',
                'amount_unit' => 'Ihre Preise sind in',
                'amount_unit_hint' => 'Zarinpal rechnet in Rial ab. Wer in Toman auszeichnet, wählt Toman, und wir multiplizieren.',
                'sandbox' => 'Sandbox verwenden',
                'sandbox_hint' => 'Testzahlungen. Es fließt kein Geld.',
            ],
        ],
        'idpay' => [
            'name' => 'IDPay',
            'description' => 'Iranische Bankkarten über IDPay annehmen.',
            'settings' => [
                'api_key' => 'API-Schlüssel',
                'api_key_hint' => 'Aus Ihrem IDPay-Dashboard, für diese Website.',
                'amount_unit' => 'Ihre Preise sind in',
                'amount_unit_hint' => 'IDPay rechnet in Rial ab. Wer in Toman auszeichnet, wählt Toman.',
                'sandbox' => 'Sandbox verwenden',
                'sandbox_hint' => 'Sendet den Sandbox-Header. Es fließt kein Geld.',
            ],
        ],
        'nextpay' => [
            'name' => 'NextPay',
            'description' => 'Iranische Bankkarten über NextPay annehmen.',
            'settings' => [
                'api_key' => 'API-Schlüssel',
                'api_key_hint' => 'Aus Ihrem NextPay-Panel.',
                'amount_unit' => 'Ihre Preise sind in',
                'amount_unit_hint' => 'NextPay rechnet in Rial ab. Wer in Toman auszeichnet, wählt Toman.',
            ],
        ],
        'stripe' => [
            'name' => 'Stripe',
            'description' => 'Karten weltweit über Stripe Checkout annehmen. Kartendaten gehen an Stripe, nie an diesen Server.',
            'settings' => [
                'secret_key' => 'Secret Key',
                'secret_key_hint' => 'Beginnt mit sk_live_ oder sk_test_. Nur schreibbar: verschlüsselt gespeichert, nie wieder angezeigt.',
                'statement_descriptor' => 'Auf dem Kontoauszug',
                'statement_descriptor_hint' => 'Bis zu 22 Zeichen nach Ihrem Firmennamen. Leer nimmt Ihre Stripe-Vorgabe.',
            ],
        ],
        'paypal' => [
            'name' => 'PayPal',
            'description' => 'PayPal-Guthaben und Karten über PayPal Orders annehmen.',
            'settings' => [
                'client_id' => 'Client-ID',
                'client_id_hint' => 'Aus Ihren PayPal-App-Zugangsdaten.',
                'client_secret' => 'Client Secret',
                'client_secret_hint' => 'Nur schreibbar: verschlüsselt gespeichert, nie wieder angezeigt.',
                'sandbox' => 'Sandbox verwenden',
                'sandbox_hint' => 'Spricht mit PayPals Sandbox statt der Live-API.',
            ],
        ],
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
