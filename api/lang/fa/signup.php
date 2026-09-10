<?php

/**
 * Signing yourself up.
 *
 * The verification email is here rather than in a Blade template because it is four lines long and
 * says one thing. Its placeholders are Laravel's `:name` — unlike the messaging templates, this is
 * not a string an organiser edits.
 */
return [
    'title' => 'ساخت حساب',
    'subtitle' => 'یک سالن، یک وب‌سایت و یک گیشه. بدون نصب چیزی.',
    'name' => 'نام شما',
    'organisation' => 'سالن یا شرکت',
    'email' => [
        'subject' => 'کد تأیید Seatmap شما',
        'body' => "سلام :name،\n\nکد تأیید شما :code است.\n\nتا یک روز معتبر است. اگر شما حسابی نساخته‌اید، این پیام را نادیده بگیرید — بدون کد هیچ اتفاقی نمی‌افتد.",
    ],
    'password' => 'گذرواژه',
    'passwordHint' => 'دست‌کم دوازده نویسه. این گذرواژه از یک گیشه محافظت می‌کند.',
    'plan' => 'اشتراک',
    'create' => 'ساختن حساب',
    'haveAccount' => 'حساب دارید؟ وارد شوید',
    'newAccount' => 'ساخت حساب',
    'creating' => 'در حال آماده‌سازی…',
    'welcome' => 'خوش آمدید. وب‌سایت شما آمادهٔ ویرایش است.',
    'verifyTitle' => 'ایمیلتان را ببینید',
    'verifyBody' => 'یک کد شش‌رقمی به :email فرستادیم. همین‌جا بنویسیدش — در این فاصله می‌توانید کارتان را ادامه دهید.',
    'code' => 'کد',
    'verify' => 'تأیید',
    'verified' => 'ایمیل تأیید شد. سپاسگزاریم.',
    'resend' => 'دوباره بفرست',
    'resent' => 'فرستاده شد. رسیدنش ممکن است یک دقیقه طول بکشد.',
    'free' => 'رایگان',
    'perMonth' => 'ماهانه',
    'perYear' => 'سالانه',
    'limits' => [
        'events' => ':count رویداد',
        'seats' => ':count صندلی در هر نقشه',
        'sites' => ':count وب‌سایت',
        'unlimited' => 'نامحدود',
    ],
];
