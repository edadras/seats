<?php

/**
 * The platform's own bill.
 *
 * Read by an organiser looking at what they owe for the software, and written onto the invoice they
 * forward to whoever pays it. Their own takings are `panel.settlement`; this is the other side of
 * the same relationship, and the only place in this platform where the platform is the merchant.
 */
return [
    'invoiceDescription' => 'الفاتورة :number',

    'lines' => [
        'plan' => 'خطة :plan',
        'commission' => 'عمولة :rate٪ على ما بعته',
    ],

    'errors' => [
        'pay_by_transfer' => 'تُسدَّد هذه الفاتورة بحوالة. لم يُخصم شيء.',
        'no_card_on_file' => 'لا توجد بطاقة مسجَّلة لهذا الحساب.',
        'card_refused' => 'رُفضت البطاقة (:code).',
        'needs_the_cardholder' => 'يطلب البنك موافقة حامل البطاقة على هذه الدفعة. افتح شاشة الفواتير وادفعها من هناك.',
        'unreachable' => 'تعذّر الوصول إلى خدمة الدفع، فلم يُخصم شيء. وستُعاد المحاولة.',
    ],
];
