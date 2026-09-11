<?php

/**
 * The platform's own bill.
 *
 * Read by an organiser looking at what they owe for the software, and written onto the invoice they
 * forward to whoever pays it. Their own takings are `panel.settlement`; this is the other side of
 * the same relationship, and the only place in this platform where the platform is the merchant.
 */
return [
    'invoiceDescription' => 'Invoice :number',

    'lines' => [
        'plan' => ':plan plan',
        'commission' => 'Commission at :rate% on what you sold',
    ],

    'errors' => [
        'pay_by_transfer' => 'This invoice is paid by transfer. Nothing was charged.',
        'no_card_on_file' => 'There is no card on file for this account.',
        'card_refused' => 'The card was refused (:code).',
        'needs_the_cardholder' => 'The bank wants the cardholder to approve this payment. Open your billing screen and pay it there.',
        'unreachable' => 'The payment service could not be reached, so nothing was charged. It will be tried again.',
    ],
];
