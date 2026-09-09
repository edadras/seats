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
    '%1$d place in %2$s' => '%1$d مكان في %2$s|%1$d أماكن في %2$s',
    '%1$s, row %2$s, seat %3$s' => '%1$s، الصف %2$s، المقعد %3$s',
    'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.' => 'منتج بسيط افتراضي يُستعمل سطراً في السلة لكل مقعد. سعره الخاص يُتجاهَل — السعر يأتي دائماً من ردّ Seatmap الموقَّع.',
    'API URL' => 'عنوان API',
    'Check the key ID and secret, and that the key has not been revoked.' => 'تحقّق من معرّف المفتاح والسرّ، ومن أن المفتاح لم يُبطَل.',
    'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.' => 'يفحص بيانات الاعتماد والساعة. تُرفض الطلبات الموقَّعة إذا زاد فارق ساعة هذا الخادم عن ساعة الـAPI عن خمس دقائق.',
    'Choose at least one seat or place.' => 'اختر مقعداً أو مكاناً واحداً على الأقل.',
    'Choosing seats needs JavaScript. Please enable it, or contact the box office.' => 'اختيار المقاعد يحتاج جافاسكربت. فعّله من فضلك، أو اتصل بشبّاك التذاكر.',
    'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.' => 'اربط هذا المتجر بحسابك في Seatmap. المقاعد والأسعار والتوافر تأتي من Seatmap؛ أما السلة والدفع والاسترداد فتبقى هنا في ووكومرس.',
    'Connected. Credentials and clock are good.' => 'تمّ الاتصال. بيانات الاعتماد والساعة سليمة.',
    'Connected.' => 'تمّ الاتصال.',
    'Connection' => 'الاتصال',
    'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.' => 'انسخ هذا من الفعالية في لوحة Seatmap. شكله مثل evt_xxxxxxxx.',
    'Customers will pick their seats here.' => 'سيختار الزبائن مقاعدهم هنا.',
    'Enter the event ID to show its seating plan.' => 'أدخل معرّف الفعالية لعرض مخطّط مقاعدها.',
    'Event public ID' => 'المعرّف العام للفعالية',
    'Every five minutes (Seatmap)' => 'كل خمس دقائق (Seatmap)',
    'Fill in the API URL, key ID and secret first.' => 'املأ أولاً عنوان API ومعرّف المفتاح والسرّ.',
    'Key ID' => 'معرّف المفتاح',
    'Let customers pick their seats for a Seatmap event.' => 'دع الزبائن يختارون مقاعدهم لفعالية في Seatmap.',
    'No event was selected for this seat map.' => 'لم تُختَر فعالية لخريطة المقاعد هذه.',
    'Not permitted.' => 'غير مسموح.',
    'Reserve and add to cart' => 'احجز وأضِف إلى السلة',
    'Seat booking is not available right now.' => 'حجز المقاعد غير متاح الآن.',
    'Seat map' => 'خريطة المقاعد',
    'Seat product' => 'منتج المقعد',
    'Seat' => 'مقعد',
    'Seatmap %1$s failed: %2$s' => 'فشل %1$s في Seatmap: %2$s',
    'Seatmap Connect is not configured yet.' => 'لم تُضبَط إعدادات Seatmap Connect بعد.',
    'Seatmap Connect needs WooCommerce to be installed and active.' => 'يحتاج Seatmap Connect إلى ووكومرس مثبَّتاً ومفعَّلاً.',
    'Seatmap Connect' => 'Seatmap Connect',
    'Seatmap' => 'Seatmap',
    'Seatmap: could not confirm the seats yet. This will be retried automatically.' => 'Seatmap: تعذّر تأكيد المقاعد بعد. ستُعاد المحاولة تلقائياً.',
    'Seatmap: reconciliation found this order already confirmed.' => 'Seatmap: وجدت المطابقة أن هذا الطلب مؤكَّد سلفاً.',
    'Seatmap: seats allocated and tickets issued.' => 'Seatmap: خُصّصت المقاعد وصدرت التذاكر.',
    'Seats allocated and tickets issued.' => 'خُصّصت المقاعد وصدرت التذاكر.',
    'Seats are held, awaiting payment.' => 'المقاعد محجوزة بانتظار الدفع.',
    'Secret' => 'السرّ',
    'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.' => 'عرضه Seatmap مرّة واحدة عند إنشاء المفتاح. اترك القيمة المخفية كما هي للإبقاء على السرّ الحالي.',
    'Standing' => 'وقوف',
    'Test connection' => 'اختبر الاتصال',
    'Testing…' => 'جارٍ الاختبار…',
    'The API URL must use HTTPS. Requests carry your API credentials.' => 'يجب أن يستعمل عنوان API بروتوكول HTTPS. فالطلبات تحمل بيانات اعتمادك.',
    'The seating plan for this event is not published yet.' => 'مخطّط مقاعد هذه الفعالية لم يُنشر بعد.',
    'The seating service returned status %d.' => 'أعادت خدمة المقاعد الحالة %d.',
    'The test request itself failed.' => 'فشل طلب الاختبار نفسه.',
    'This event could not be loaded.' => 'تعذّر تحميل هذه الفعالية.',
    'This server\'s clock is out of step with the API. Fix NTP on this host.' => 'ساعة هذا الخادم غير متوافقة مع الـAPI. أصلح NTP على هذا المضيف.',
    'This store is not finished setting up seat sales yet.' => 'لم يكتمل بعد إعداد بيع المقاعد في هذا المتجر.',
    'Waiting to reach the seating service. This retries automatically every five minutes.' => 'بانتظار الوصول إلى خدمة المقاعد. تُعاد المحاولة تلقائياً كل خمس دقائق.',
    'Without the /v1 suffix.' => 'دون اللاحقة ‎/v1.',
    'You do not have permission to manage these settings.' => 'لا تملك صلاحية إدارة هذه الإعدادات.',
    'Your seat reservation ran out and the seats were released. Please choose your seats again.' => 'انتهى حجز مقاعدك وأُفرِج عنها. اختر مقاعدك من جديد من فضلك.',
    'Your seats could not be added to your cart.' => 'تعذّرت إضافة مقاعدك إلى السلة.',
];
