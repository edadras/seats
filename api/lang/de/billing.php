<?php

/**
 * The platform's own bill.
 *
 * Read by an organiser looking at what they owe for the software, and written onto the invoice they
 * forward to whoever pays it. Their own takings are `panel.settlement`; this is the other side of
 * the same relationship, and the only place in this platform where the platform is the merchant.
 */
return [
    'invoiceDescription' => 'Rechnung :number',

    'lines' => [
        'plan' => 'Tarif :plan',
        'commission' => 'Provision von :rate% auf Ihre Verkäufe',
    ],

    'errors' => [
        'pay_by_transfer' => 'Diese Rechnung wird per Überweisung bezahlt. Es wurde nichts abgebucht.',
        'no_card_on_file' => 'Für dieses Konto ist keine Karte hinterlegt.',
        'card_refused' => 'Die Karte wurde abgelehnt (:code).',
        'needs_the_cardholder' => 'Die Bank möchte, dass der Karteninhaber diese Zahlung bestätigt. Öffnen Sie Ihre Rechnungsseite und zahlen Sie dort.',
        'unreachable' => 'Der Zahlungsdienst war nicht erreichbar, es wurde nichts abgebucht. Es wird erneut versucht.',
    ],
];
