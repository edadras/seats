<?php

/** Quello che la piattaforma segnala all’organizzatore. La frase si compone alla lettura, non alla scrittura. */

return [
    'kinds' => [
        'order_refunded' => [
            'title' => 'Ordine rimborsato',
            'body' => 'La prenotazione :reference per :event è stata rimborsata — :seats posto/i.',
        ],
        'refund_requested' => [
            'title' => 'Rimborso richiesto',
            'body' => 'Ordine :reference per :event — l’acquirente ha chiesto il rimborso. :reason',
        ],
        'event_sold_out' => [
            'title' => 'Tutto esaurito',
            'body' => ':event ha venduto ogni posto: :seats in tutto.',
        ],
        'announcement_finished' => [
            'title' => 'Annuncio inviato',
            'body' => '«:subject» è arrivato a :sent messaggi su :total.',
        ],
        'message_refused' => [
            'title' => 'Un messaggio è stato rifiutato',
            'body' => 'Il canale :channel ha rifiutato un messaggio e non riproverà: :reason. Controllate le sue impostazioni — forse un acquirente non è stato avvisato.',
        ],
        'domain_verified' => [
            'title' => 'Indirizzo verificato',
            'body' => ':hostname è verificato e può servire :site.',
        ],
        'billing_invoiced' => [
            'title' => 'Fattura emessa',
            'body' => 'La fattura :number è pronta. Scade il :due.',
        ],
        'billing_payment_failed' => [
            'title' => 'Un pagamento non è andato a buon fine',
            'body' => 'Non è stato possibile addebitare la fattura :number: :reason. Si riproverà.',
        ],
        'billing_past_due' => [
            'title' => 'Questo account è scaduto',
            'body' => 'La fattura :number non è stata pagata e i tentativi sono finiti: :reason. Saldatela dalla schermata di fatturazione.',
        ],
    ],
];
