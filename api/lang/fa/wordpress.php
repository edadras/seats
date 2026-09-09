<?php

/**
 * What the WordPress plugin says on its own.
 *
 * Keyed by the English string rather than by a symbolic name, because in gettext the English string
 * *is* the key: the plugin's source says `__( 'Total', 'seatmap-connect' )` and WordPress looks the
 * catalogue up by exactly those words. `tools/sync-wordpress-strings.mjs` turns this file into the
 * `.po` and `.mo` the plugin ships, and CI fails if the two have drifted.
 *
 * The seat picker's own vocabulary is *not* here. It lives under `picker` in site.php and the tool
 * matches it by key, so a buyer choosing a seat on a WooCommerce shop reads the same words as a
 * buyer on a hosted site. Duplicating them here would be two translations of one component.
 *
 * A plural keeps both shapes in one entry, separated by a pipe.
 */
return [
    '%1$d place in %2$s' => '%1$d جای ایستاده در %2$s|%1$d جای ایستاده در %2$s',
    '%1$s, row %2$s, seat %3$s' => '%1$s، ردیف %2$s، صندلی %3$s',
    'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.' => 'یک محصول سادهٔ مجازی که به‌عنوان سطر سبد خرید برای هر صندلی به کار می‌رود. قیمت خودش نادیده گرفته می‌شود — قیمت همیشه از پاسخ امضاشدهٔ Seatmap می‌آید.',
    'API URL' => 'نشانی API',
    'Check the key ID and secret, and that the key has not been revoked.' => 'شناسهٔ کلید و رمز را بررسی کنید، و اینکه کلید باطل نشده باشد.',
    'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.' => 'اعتبارنامه‌ها و ساعت را بررسی می‌کند. اگر ساعت این سرور بیش از پنج دقیقه با ساعت API فاصله داشته باشد، درخواست‌های امضاشده رد می‌شوند.',
    'Choose at least one seat or place.' => 'دست‌کم یک صندلی یا جایگاه انتخاب کنید.',
    'Choosing seats needs JavaScript. Please enable it, or contact the box office.' => 'انتخاب صندلی به جاوااسکریپت نیاز دارد. لطفاً فعالش کنید، یا با گیشه تماس بگیرید.',
    'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.' => 'این فروشگاه را به حساب Seatmap خود وصل کنید. صندلی‌ها، قیمت‌ها و موجودی از Seatmap می‌آیند؛ سبد خرید، پرداخت و بازپرداخت همین‌جا در ووکامرس می‌مانند.',
    'Connected. Credentials and clock are good.' => 'متصل شد. اعتبارنامه‌ها و ساعت درست‌اند.',
    'Connected.' => 'متصل شد.',
    'Connection' => 'اتصال',
    'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.' => 'این را از رویداد در پنل Seatmap خود رونویسی کنید. شکلش مثل evt_xxxxxxxx است.',
    'Customers will pick their seats here.' => 'مشتریان صندلی‌شان را همین‌جا انتخاب می‌کنند.',
    'Enter the event ID to show its seating plan.' => 'شناسهٔ رویداد را وارد کنید تا نقشهٔ صندلی‌اش نشان داده شود.',
    'Event public ID' => 'شناسهٔ عمومی رویداد',
    'Every five minutes (Seatmap)' => 'هر پنج دقیقه (Seatmap)',
    'Fill in the API URL, key ID and secret first.' => 'اول نشانی API، شناسهٔ کلید و رمز را پر کنید.',
    'Key ID' => 'شناسهٔ کلید',
    'Let customers pick their seats for a Seatmap event.' => 'بگذارید مشتریان برای یک رویداد Seatmap صندلی‌شان را انتخاب کنند.',
    'No event was selected for this seat map.' => 'برای این نقشهٔ صندلی رویدادی انتخاب نشده است.',
    'Not permitted.' => 'مجاز نیست.',
    'Reserve and add to cart' => 'رزرو و افزودن به سبد خرید',
    'Seat booking is not available right now.' => 'رزرو صندلی همین حالا در دسترس نیست.',
    'Seat map' => 'نقشهٔ صندلی',
    'Seat product' => 'محصول صندلی',
    'Seat' => 'صندلی',
    'Seatmap %1$s failed: %2$s' => '%1$s در Seatmap ناموفق بود: %2$s',
    'Seatmap Connect is not configured yet.' => 'Seatmap Connect هنوز پیکربندی نشده است.',
    'Seatmap Connect needs WooCommerce to be installed and active.' => 'Seatmap Connect به ووکامرسِ نصب‌شده و فعال نیاز دارد.',
    'Seatmap Connect' => 'Seatmap Connect',
    'Seatmap' => 'Seatmap',
    'Seatmap: could not confirm the seats yet. This will be retried automatically.' => 'Seatmap: هنوز نتوانستیم صندلی‌ها را قطعی کنیم. این کار خودکار دوباره تلاش می‌شود.',
    'Seatmap: reconciliation found this order already confirmed.' => 'Seatmap: تطبیق نشان داد این سفارش پیش‌تر قطعی شده بود.',
    'Seatmap: seats allocated and tickets issued.' => 'Seatmap: صندلی‌ها تخصیص یافت و بلیت‌ها صادر شد.',
    'Seats allocated and tickets issued.' => 'صندلی‌ها تخصیص یافت و بلیت‌ها صادر شد.',
    'Seats are held, awaiting payment.' => 'صندلی‌ها نگه داشته شده‌اند، در انتظار پرداخت.',
    'Secret' => 'رمز',
    'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.' => 'Seatmap آن را هنگام ساختن کلید یک بار نشان داده است. برای نگه‌داشتن رمز فعلی، مقدار پوشیده را دست نزنید.',
    'Standing' => 'ایستاده',
    'Test connection' => 'آزمایش اتصال',
    'Testing…' => 'در حال آزمایش…',
    'The API URL must use HTTPS. Requests carry your API credentials.' => 'نشانی API باید HTTPS باشد. درخواست‌ها اعتبارنامه‌های API شما را حمل می‌کنند.',
    'The seating plan for this event is not published yet.' => 'نقشهٔ صندلی این رویداد هنوز منتشر نشده است.',
    'The seating service returned status %d.' => 'سرویس صندلی وضعیت %d را برگرداند.',
    'The test request itself failed.' => 'خودِ درخواست آزمایشی ناموفق بود.',
    'This event could not be loaded.' => 'این رویداد بارگذاری نشد.',
    'This server\'s clock is out of step with the API. Fix NTP on this host.' => 'ساعت این سرور با API هماهنگ نیست. NTP را روی این میزبان درست کنید.',
    'This store is not finished setting up seat sales yet.' => 'راه‌اندازی فروش صندلی در این فروشگاه هنوز تمام نشده است.',
    'Waiting to reach the seating service. This retries automatically every five minutes.' => 'در انتظار رسیدن به سرویس صندلی. هر پنج دقیقه خودکار دوباره تلاش می‌شود.',
    'Without the /v1 suffix.' => 'بدون پسوند ‎/v1.',
    'You do not have permission to manage these settings.' => 'شما اجازهٔ مدیریت این تنظیمات را ندارید.',
    'Your seat reservation ran out and the seats were released. Please choose your seats again.' => 'رزرو صندلی شما منقضی شد و صندلی‌ها آزاد شدند. لطفاً دوباره صندلی انتخاب کنید.',
    'Your seats could not be added to your cart.' => 'صندلی‌های شما به سبد خرید افزوده نشد.',
];
