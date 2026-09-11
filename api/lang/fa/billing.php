<?php

/**
 * The platform's own bill.
 *
 * Read by an organiser looking at what they owe for the software, and written onto the invoice they
 * forward to whoever pays it. Their own takings are `panel.settlement`; this is the other side of
 * the same relationship, and the only place in this platform where the platform is the merchant.
 */
return [
    'invoiceDescription' => 'صورتحساب :number',

    'lines' => [
        'plan' => 'طرح :plan',
        'commission' => 'کارمزد :rate٪ بر آنچه فروخته‌اید',
    ],

    'errors' => [
        'pay_by_transfer' => 'این صورتحساب با حواله پرداخت می‌شود. چیزی برداشت نشد.',
        'no_card_on_file' => 'برای این حساب کارتی ثبت نشده است.',
        'card_refused' => 'کارت پذیرفته نشد (:code).',
        'needs_the_cardholder' => 'بانک می‌خواهد دارندهٔ کارت این پرداخت را تأیید کند. صفحهٔ صورتحساب را باز کنید و همان‌جا پرداخت کنید.',
        'unreachable' => 'سرویس پرداخت در دسترس نبود، پس چیزی برداشت نشد. دوباره تلاش خواهد شد.',
    ],
];
