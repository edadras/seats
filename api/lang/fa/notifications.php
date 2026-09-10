<?php

/** آنچه پلتفرم به برگزارکننده می‌گوید. متن هنگام خوانده‌شدن ساخته می‌شود، نه هنگام ثبت. */

return [
    'kinds' => [
        'order_refunded' => [
            'title' => 'سفارش بازپرداخت شد',
            'body' => 'سفارش :reference برای :event بازپرداخت شد — :seats جایگاه.',
        ],
        'refund_requested' => [
            'title' => 'درخواست استرداد',
            'body' => 'سفارش :reference برای :event — خریدار درخواست استرداد کرده است. :reason',
        ],
        'event_sold_out' => [
            'title' => 'فروش کامل',
            'body' => 'همهٔ جایگاه‌های :event فروخته شد: در مجموع :seats.',
        ],
        'announcement_finished' => [
            'title' => 'اعلان فرستاده شد',
            'body' => '«:subject» به :sent از :total پیام رسید.',
        ],
        'message_refused' => [
            'title' => 'یک پیام رد شد',
            'body' => 'کانال :channel یک پیام را رد کرد و دوباره تلاش نمی‌کند: :reason. تنظیماتش را ببینید — ممکن است چیزی به گوش خریدار نرسیده باشد.',
        ],
        'domain_verified' => [
            'title' => 'نشانی تأیید شد',
            'body' => ':hostname تأیید شد و می‌تواند :site را نمایش دهد.',
        ],
    ],
];
