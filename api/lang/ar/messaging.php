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
    'title' => 'الرسائل',
    'subtitle' => 'ما يُقال للمشتري، وعبر أي قناة، وبلغته هو.',
    'kinds' => [
        'order_confirmed' => [
            'name' => 'تأكيد الطلب',
            'description' => 'تُرسل لحظة تسوية الدفع. هذه الرسالة الوحيدة التي يستحقها المشتري.',
        ],
        'order_cancelled' => [
            'name' => 'إلغاء الطلب',
            'description' => 'تُرسل عند إلغاء طلب أو ردّه.',
        ],
        'event_reminder' => [
            'name' => 'تذكير بالفعالية',
            'description' => 'قبل يوم، لكل حامل تذكرة. مطفأة حتى تشغّلها.',
        ],
    ],
    'channels' => [
        'email' => 'البريد',
    ],
    'defaults' => [
        'order_confirmed' => [
            'subject' => 'تذاكرك لـ {event}',
            'body' => "{buyer}، حُجزت تذاكرك.\n\n{event}\n{venue}\n{starts}\nالمقاعد: {seats}\nالإجمالي: {total}\n\nرقم الحجز {reference}.",
        ],
        'order_cancelled' => [
            'subject' => 'أُلغي حجزك {reference}',
            'body' => "{buyer}، أُلغي حجزك لـ {event}.\n\nرقم الحجز {reference}.",
        ],
        'event_reminder' => [
            'subject' => '{event} غدًا',
            'body' => "{buyer}، {event} غدًا.\n\n{venue}\n{starts}\nالمقاعد: {seats}\n\nرقم الحجز {reference}.",
        ],
    ],
    'template' => 'الصياغة',
    'subject' => 'الموضوع',
    'body' => 'النص',
    'locale' => 'اللغة',
    'usingDefault' => 'تُستخدم صياغتنا. اكتب صياغتك لتحلّ محلها.',
    'placeholders' => 'المتاح: :list',
    'save' => 'احفظ الصياغة',
    'saved' => 'حُفظت الصياغة.',
    'reset' => 'العودة إلى صياغتنا',
    'resetDone' => 'عادت إلى صياغتنا.',
    'channelsOn' => 'تُرسل عبر',
    'channelHint' => 'من لا عنوان له على قناة لا تصله رسالتها.',
    'required' => 'تُرسل دائمًا',
    'log' => 'سجل الإرسال',
    'noLog' => 'لم يُرسل شيء بعد',
    'noLogHint' => 'تظهر التأكيدات هنا لحظة أول شراء.',
    'status' => [
        'queued' => 'في الانتظار',
        'sent' => 'أُرسلت',
        'refused' => 'رُفضت',
        'unavailable' => 'تعذّر الوصول',
    ],
    'recipient' => 'إلى',
    'when' => 'الوقت',
    'attempts' => 'المحاولات',
    'reason' => 'السبب',
    'test' => 'أرسل تجربة',
    'testTo' => 'أرسلها إلى',
    'testSent' => 'أُرسلت التجربة. انظر السجل أدناه.',
    'testHint' => 'يستخدم صياغتك بقيم نموذجية لترى ما يراه المشتري.',
    'filterAll' => 'كل الحالات',
    'preview' => 'معاينة',
    'example' => [
        'buyer' => 'سارة أحمد',
        'event' => 'ليلة الافتتاح',
        'venue' => 'مسرح نورثغيت',
        'seats' => 'الصالة A ١٢، الصالة A ١٣',
    ],
];
