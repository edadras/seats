<?php

/**
 * What a buyer reads about each way of paying.
 *
 * A gateway's own name is not translated — Zarinpal is Zarinpal everywhere — but the sentence
 * explaining what choosing it means is, because that sentence is the one that decides whether
 * somebody is comfortable clicking it.
 */
return [
    'offline' => [
        'label' => 'Pay at the box office',
        'description' => 'Your seats are reserved now. Pay when you collect your tickets.',
    ],
    'zarinpal' => [
        'label' => 'Zarinpal',
        'description' => 'Pay by Iranian bank card through Zarinpal.',
    ],
    'idpay' => [
        'label' => 'IDPay',
        'description' => 'Pay by Iranian bank card through IDPay.',
    ],
    'nextpay' => [
        'label' => 'NextPay',
        'description' => 'Pay by Iranian bank card through NextPay.',
    ],
    'stripe' => [
        'label' => 'Card',
        'description' => 'Pay by card. Your card details go straight to Stripe and never touch this site.',
    ],
    'paypal' => [
        'label' => 'PayPal',
        'description' => 'Pay with your PayPal balance or a card, through PayPal.',
    ],
    'orderDescription' => 'Tickets — order :reference',
    'errors' => [
        'declined' => ':gateway could not take the payment (code :code). Nothing has been charged.',
        'currency_not_supported' => ':gateway cannot take payments in :currency.',
        'not_configured' => ':gateway has not been set up yet. Choose another way to pay.',
        'no_reference' => 'We could not find this payment. If money left your account, contact the box office and quote your order number.',
        'cancelled_by_buyer' => 'The payment was cancelled. Your seats have been released.',
        'refund_by_hand' => 'This payment cannot be sent back automatically. The seats are released and the money is owed to the buyer in person.',
        'refund_unreachable' => 'The payment gateway could not be reached, so nothing has been refunded. Try again.',
        'nothing_to_refund' => 'There is nothing to send back: this booking was not paid for with money.',
    ],
    'rehearsal' => [
        'label' => 'A rehearsal — no money moves',
        'description' => 'Your booking goes through exactly as a real one would. Nothing is charged to anybody.',
    ],
];
