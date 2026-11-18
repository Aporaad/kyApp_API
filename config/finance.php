<?php

/**
 * إعدادات قسم القيود والسندات
 * Entries & vouchers module settings.
 *
 * ── بادئة رقم قيد سند القبض البنكي ليست ثابتة في البيانات القديمة: أغلب السندات تُرقَّم بـ 1
 *    (1YYxxxxxxx) وبعض المستخدمين بـ 3. القيمة الافتراضية 1 وتُغيَّر من البيئة دون تعديل الكود.
 * ── The bank-receipt entry prefix is not uniform in the legacy data: most receipts use 1
 *    (1YYxxxxxxx), a few users use 3. Default 1, overridable from the environment.
 */
return [
    'bank_receive_prefix' => (int) env('FINANCE_BANK_RECEIVE_PREFIX', 1),
];
