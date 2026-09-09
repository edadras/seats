<?php

/**
 * Messages, and the screen an organiser writes them on.
 *
 * `defaults` is the wording this platform sends when an organiser has written none — which is
 * every account on its first day. It is a real message in every language rather than a placeholder,
 * because the alternative to a good default is not a better message; it is a blank one.
 *
 * The braces are placeholders substituted at send time (App\Domain\Messaging\MessageRenderer), not
 * Laravel's `:name`: an organiser edits these strings in the panel, and one syntax is enough.
 */
return [
    'title' => 'پیام‌ها',
    'subtitle' => 'به خریدار چه گفته می‌شود، از چه کانالی، و به زبان خودش.',
    'kinds' => [
        'order_confirmed' => [
            'name' => 'تأیید سفارش',
            'description' => 'به‌محض تسویهٔ پرداخت فرستاده می‌شود. این تنها پیامی است که حق خریدار است.',
        ],
        'order_cancelled' => [
            'name' => 'لغو سفارش',
            'description' => 'وقتی سفارشی لغو یا بازپرداخت شود فرستاده می‌شود.',
        ],
        'event_reminder' => [
            'name' => 'یادآوری رویداد',
            'description' => 'یک روز پیش‌تر، برای همهٔ بلیت‌داران. تا روشنش نکنید خاموش است.',
        ],
    ],
    'channels' => [
        'email' => 'ایمیل',
    ],
    'defaults' => [
        'order_confirmed' => [
            'subject' => 'بلیت‌های شما برای {event}',
            'body' => "{buyer}، بلیت‌های شما رزرو شد.\n\n{event}\n{venue}\n{starts}\nصندلی‌ها: {seats}\nجمع: {total}\n\nکد پیگیری {reference}.",
        ],
        'order_cancelled' => [
            'subject' => 'رزرو {reference} لغو شد',
            'body' => "{buyer}، رزرو شما برای {event} لغو شد.\n\nکد پیگیری {reference}.",
        ],
        'event_reminder' => [
            'subject' => '{event} فرداست',
            'body' => "{buyer}، {event} فرداست.\n\n{venue}\n{starts}\nصندلی‌ها: {seats}\n\nکد پیگیری {reference}.",
        ],
    ],
    'template' => 'متن پیام',
    'subject' => 'موضوع',
    'body' => 'متن',
    'locale' => 'زبان',
    'usingDefault' => 'متن پیش‌فرض ما استفاده می‌شود. متن خودتان را بنویسید تا جایگزین شود.',
    'placeholders' => 'در دسترس: :list',
    'save' => 'ذخیرهٔ متن',
    'saved' => 'متن ذخیره شد.',
    'reset' => 'بازگشت به متن ما',
    'resetDone' => 'به متن ما بازگشت.',
    'channelsOn' => 'ارسال از طریق',
    'channelHint' => 'اگر خریدار نشانی آن کانال را نداشته باشد، آن پیام برایش فرستاده نمی‌شود.',
    'required' => 'همیشه فرستاده می‌شود',
    'log' => 'گزارش ارسال',
    'noLog' => 'هنوز چیزی فرستاده نشده',
    'noLogHint' => 'به‌محض نخستین خرید، تأییدها اینجا می‌آیند.',
    'status' => [
        'queued' => 'در صف',
        'sent' => 'فرستاده شد',
        'refused' => 'رد شد',
        'unavailable' => 'در دسترس نبود',
    ],
    'recipient' => 'گیرنده',
    'when' => 'زمان',
    'attempts' => 'تلاش‌ها',
    'reason' => 'علت',
    'test' => 'ارسال آزمایشی',
    'testTo' => 'ارسال به',
    'testSent' => 'پیام آزمایشی فرستاده شد. در گزارش پایین ببینید.',
    'testHint' => 'متن خودتان را با مقادیر نمونه می‌فرستد تا ببینید خریدار چه می‌بیند.',
    'filterAll' => 'همهٔ وضعیت‌ها',
    'preview' => 'پیش‌نمایش',
    'example' => [
        'buyer' => 'سارا احمدی',
        'event' => 'شب افتتاحیه',
        'venue' => 'تئاتر نورث‌گیت',
        'seats' => 'طبقهٔ همکف A ۱۲، طبقهٔ همکف A ۱۳',
    ],
];
