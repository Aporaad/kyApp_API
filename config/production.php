<?php

/**
 * إعدادات خط الإنتاج — الأقسام والمخازن وأنواع الحركة والحدود
 * Production-line settings — departments, movement types and limits.
 *
 * كل الأرقام هنا مقيسة من النظام القديم (CUTING_TRANSM/D وMULTI_CLOTHS)، ولا يجوز
 * تغييرها إلا بعد مراجعة أثرها على التقارير القديمة.
 * Every number here is measured from the legacy system; changing one silently changes
 * the meaning of existing movement rows.
 */
return [
    // أقسام/مخازن الحركة كما تظهر في CUTING_TRANSD
    'departments' => [
        'main' => 12,       // المخزن الرئيسي / المحل
        'delivery' => 14,   // مخزن التسليم
        'cutting' => 21,    // مخزن القص
        'tailoring' => 22,  // مخزن الخياطة
        'finishing' => 23,  // مخزن التجهيز
    ],

    // TRANS_TYPE: 4 توريد نقل مخزني، 5 صرف نقل مخزني
    'types' => [
        'in' => 4,
        'out' => 5,
    ],

    // حالات القطعة القابلة للعرض (الأرقام الفردية حركات مخزنية وسيطة)
    'statuses' => [
        'new' => 0,
        'approved' => 1,
        'at_cutter' => 2,
        'cut_done' => 3,
        'received_from_cut' => 4,
        'to_tailor' => 5,
        'at_tailor' => 6,
        'tailor_done' => 7,
        'received_from_tailor' => 8,
        'finishing' => 9,
        'ready' => 10,
        'delivered' => 11,
    ],

    // حدود الطلب: 200 قطعة كافية لعملية شاشة واحدة، وتمنع الاستعلامات الضخمة
    'limits' => [
        'page' => 20,
        'max_page' => 100,
        'max_pieces' => 200,
    ],

    // قفل الترقيم: مهلة الانتظار بالثواني قبل إرجاع 409
    'lock_wait_seconds' => 5,
];
