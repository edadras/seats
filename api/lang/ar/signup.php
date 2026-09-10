<?php

/**
 * Signing yourself up.
 *
 * The verification email is here rather than in a Blade template because it is four lines long and
 * says one thing. Its placeholders are Laravel's `:name` — unlike the messaging templates, this is
 * not a string an organiser edits.
 */
return [
    'title' => 'أنشئ حسابًا',
    'subtitle' => 'قاعة وموقع وشبّاك تذاكر. بلا تثبيت شيء.',
    'name' => 'اسمك',
    'organisation' => 'القاعة أو الشركة',
    'email' => [
        'subject' => 'رمز تأكيد Seatmap',
        'body' => "مرحبًا :name،\n\nرمز التأكيد هو :code.\n\nصالح ليوم واحد. إن لم تنشئ حسابًا فتجاهل هذه الرسالة — لا يحدث شيء بغير الرمز.",
    ],
    'password' => 'كلمة المرور',
    'passwordHint' => 'اثنا عشر حرفًا على الأقل. هذه تحمي شبّاك تذاكر.',
    'plan' => 'الخطة',
    'create' => 'أنشئ الحساب',
    'haveAccount' => 'لديك حساب؟ سجّل الدخول',
    'newAccount' => 'أنشئ حسابًا',
    'creating' => 'يجري التجهيز…',
    'welcome' => 'أهلًا. موقعك جاهز للتحرير.',
    'verifyTitle' => 'راجع بريدك',
    'verifyBody' => 'أرسلنا رمزًا من ستة أرقام إلى :email. اكتبه هنا — ويمكنك المتابعة في هذه الأثناء.',
    'code' => 'الرمز',
    'verify' => 'تأكيد',
    'verified' => 'تأكّد البريد. شكرًا.',
    'resend' => 'أرسله ثانية',
    'resent' => 'أُرسل. قد يستغرق وصوله دقيقة.',
    'free' => 'مجانًا',
    'perMonth' => 'شهريًا',
    'perYear' => 'سنويًا',
    'limits' => [
        'events' => ':count فعالية',
        'seats' => ':count مقعد لكل مخطط',
        'sites' => ':count موقعًا',
        'unlimited' => 'بلا حدود',
    ],
];
