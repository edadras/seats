<?php

/** Was die Plattform der Veranstalterin meldet. Der Satz entsteht beim Lesen, nicht beim Schreiben. */

return [
    'kinds' => [
        'order_refunded' => [
            'title' => 'Bestellung erstattet',
            'body' => 'Buchung :reference für :event wurde erstattet — :seats Platz/Plätze.',
        ],
        'refund_requested' => [
            'title' => 'Erstattung angefragt',
            'body' => 'Buchung :reference für :event — der Käufer möchte sein Geld zurück. :reason',
        ],
        'event_sold_out' => [
            'title' => 'Ausverkauft',
            'body' => ':event hat jeden Platz verkauft: insgesamt :seats.',
        ],
        'announcement_finished' => [
            'title' => 'Ansage verschickt',
            'body' => '„:subject“ ging an :sent von :total Nachrichten.',
        ],
        'message_refused' => [
            'title' => 'Eine Nachricht wurde abgelehnt',
            'body' => 'Der Kanal :channel hat eine Nachricht abgelehnt und versucht es nicht erneut: :reason. Prüfen Sie seine Einstellungen — vielleicht wurde jemandem etwas nicht mitgeteilt.',
        ],
        'domain_verified' => [
            'title' => 'Adresse bestätigt',
            'body' => ':hostname ist bestätigt und kann :site ausliefern.',
        ],
        'billing_invoiced' => [
            'title' => 'Rechnung erstellt',
            'body' => 'Rechnung :number liegt vor. Sie ist am :due fällig.',
        ],
        'billing_payment_failed' => [
            'title' => 'Eine Zahlung ging nicht durch',
            'body' => 'Rechnung :number konnte nicht abgebucht werden: :reason. Es wird erneut versucht.',
        ],
        'billing_past_due' => [
            'title' => 'Dieses Konto ist überfällig',
            'body' => 'Rechnung :number ist unbezahlt und die Versuche sind ausgeschöpft: :reason. Bitte über die Rechnungsseite begleichen.',
        ],
    ],
];
