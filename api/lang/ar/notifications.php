<?php

/** ما تخبر به المنصة المنظّم. تُصاغ الجملة عند القراءة لا عند التسجيل. */

return [
    'kinds' => [
        'order_refunded' => [
            'title' => 'طلب مُعاد',
            'body' => 'أُعيد مبلغ الطلب :reference لـ :event — :seats مقعداً.',
        ],
        'event_sold_out' => [
            'title' => 'نفدت التذاكر',
            'body' => 'بيعت كل أماكن :event: :seats في المجموع.',
        ],
        'announcement_finished' => [
            'title' => 'أُرسل الإعلان',
            'body' => '«:subject» وصل إلى :sent من :total رسالة.',
        ],
        'message_refused' => [
            'title' => 'رُفضت رسالة',
            'body' => 'رفضت قناة :channel رسالة ولن تعيد المحاولة: :reason. راجع إعداداتها — ربما لم يُبلَّغ مشترٍ بشيء.',
        ],
        'domain_verified' => [
            'title' => 'تم توثيق العنوان',
            'body' => 'وُثّق :hostname ويمكنه عرض :site.',
        ],
    ],
];
