<?php

/**
 * Ciò che chi acquista legge su ogni modo di pagare.
 *
 * Il nome di un gateway non si traduce; la frase che spiega cosa comporta sceglierlo sì, perché è
 * quella frase a decidere se una persona clicca con tranquillità.
 */
return [
    'offline' => [
        'label' => 'Paga al botteghino',
        'description' => 'I tuoi posti sono già prenotati. Paghi quando ritiri i biglietti.',
    ],
    'zarinpal' => [
        'label' => 'Zarinpal',
        'description' => 'Paga con carta bancaria iraniana tramite Zarinpal.',
    ],
    'idpay' => [
        'label' => 'IDPay',
        'description' => 'Paga con carta bancaria iraniana tramite IDPay.',
    ],
    'nextpay' => [
        'label' => 'NextPay',
        'description' => 'Paga con carta bancaria iraniana tramite NextPay.',
    ],
    'stripe' => [
        'label' => 'Carta',
        'description' => 'Paga con carta. I dati della carta vanno direttamente a Stripe e non toccano mai questo sito.',
    ],
    'paypal' => [
        'label' => 'PayPal',
        'description' => 'Paga con il saldo PayPal o con una carta, tramite PayPal.',
    ],
    'orderDescription' => 'Biglietti — ordine :reference',
    'errors' => [
        'declined' => ':gateway non è riuscito a incassare il pagamento (codice :code). Non è stato addebitato nulla.',
        'currency_not_supported' => ':gateway non accetta pagamenti in :currency.',
        'not_configured' => ':gateway non è ancora configurato. Scegli un altro modo di pagare.',
        'no_reference' => 'Non troviamo questo pagamento. Se è uscito denaro dal tuo conto, contatta la biglietteria con il numero d’ordine.',
        'cancelled_by_buyer' => 'Il pagamento è stato annullato. I tuoi posti sono stati liberati.',
        'refund_by_hand' => 'Questo pagamento non può essere restituito automaticamente. I posti sono liberati e il denaro è dovuto all’acquirente di persona.',
        'refund_unreachable' => 'Il gateway di pagamento non era raggiungibile, quindi non è stato rimborsato nulla. Riprova.',
        'nothing_to_refund' => 'Non c’è nulla da restituire: questa prenotazione non è stata pagata in denaro.',
    ],
];
