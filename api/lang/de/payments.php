<?php

/**
 * Was Käuferinnen und Käufer über jede Zahlungsart lesen.
 *
 * Der Name eines Anbieters wird nicht übersetzt; der Satz, der erklärt, was die Wahl bedeutet,
 * schon – denn dieser Satz entscheidet, ob jemand beruhigt darauf klickt.
 */
return [
    'offline' => [
        'label' => 'An der Abendkasse zahlen',
        'description' => 'Ihre Plätze sind jetzt reserviert. Bezahlen Sie beim Abholen der Tickets.',
    ],
    'zarinpal' => [
        'label' => 'Zarinpal',
        'description' => 'Mit iranischer Bankkarte über Zarinpal bezahlen.',
    ],
    'idpay' => [
        'label' => 'IDPay',
        'description' => 'Mit iranischer Bankkarte über IDPay bezahlen.',
    ],
    'nextpay' => [
        'label' => 'NextPay',
        'description' => 'Mit iranischer Bankkarte über NextPay bezahlen.',
    ],
    'stripe' => [
        'label' => 'Karte',
        'description' => 'Mit Karte bezahlen. Ihre Kartendaten gehen direkt an Stripe und berühren diese Seite nie.',
    ],
    'paypal' => [
        'label' => 'PayPal',
        'description' => 'Mit PayPal-Guthaben oder Karte bezahlen, über PayPal.',
    ],
    'orderDescription' => 'Tickets — Bestellung :reference',
    'errors' => [
        'declined' => ':gateway konnte die Zahlung nicht ausführen (Code :code). Es wurde nichts abgebucht.',
        'currency_not_supported' => ':gateway nimmt keine Zahlungen in :currency an.',
        'not_configured' => ':gateway ist noch nicht eingerichtet. Bitte anders bezahlen.',
        'no_reference' => 'Diese Zahlung ist nicht auffindbar. Falls Geld abgebucht wurde, wenden Sie sich mit Ihrer Bestellnummer an die Kasse.',
        'cancelled_by_buyer' => 'Die Zahlung wurde abgebrochen. Ihre Plätze sind wieder frei.',
        'refund_by_hand' => 'Diese Zahlung lässt sich nicht automatisch zurücksenden. Die Plätze sind frei, und das Geld wird der Käuferin persönlich geschuldet.',
        'refund_unreachable' => 'Der Zahlungsdienst war nicht erreichbar, es wurde nichts erstattet. Bitte erneut versuchen.',
        'nothing_to_refund' => 'Es gibt nichts zurückzusenden: diese Buchung wurde nicht mit Geld bezahlt.',
    ],
];
