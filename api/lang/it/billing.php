<?php

/**
 * The platform's own bill.
 *
 * Read by an organiser looking at what they owe for the software, and written onto the invoice they
 * forward to whoever pays it. Their own takings are `panel.settlement`; this is the other side of
 * the same relationship, and the only place in this platform where the platform is the merchant.
 */
return [
    'invoiceDescription' => 'Fattura :number',

    'lines' => [
        'plan' => 'Piano :plan',
        'commission' => 'Commissione del :rate% su quanto avete venduto',
    ],

    'errors' => [
        'pay_by_transfer' => 'Questa fattura si paga con bonifico. Non è stato addebitato nulla.',
        'no_card_on_file' => 'Per questo account non c’è nessuna carta registrata.',
        'card_refused' => 'La carta è stata rifiutata (:code).',
        'needs_the_cardholder' => 'La banca vuole che il titolare della carta approvi questo pagamento. Aprite la schermata di fatturazione e pagatelo lì.',
        'unreachable' => 'Il servizio di pagamento non era raggiungibile, quindi non è stato addebitato nulla. Si riproverà.',
    ],
];
