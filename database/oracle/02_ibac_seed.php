<?php

/**
 * بذر كتالوج الصلاحيات وإعدادات الأمان في أوراكل (مرحلة 1 — IBAC)
 * Seed the IBAC permission catalog and security settings into Oracle (Phase 1).
 *
 * التشغيل / Run (من داخل kyApp_API):
 *   php artisan tinker database/oracle/02_ibac_seed.php
 *
 * ملاحظات مهمة / Important notes:
 * 1) العربية تُكتب عبر ArabicEncoding::toOracleSearchChars() وليس encodeForOracle():
 *    اتصال التطبيق بترميز عميل AL32UTF8 وقاعدة البيانات WE8MSWIN1252، فإرسال بايتات
 *    Windows-1256 الخام يستبدلها أوراكل بعلامة استبدال (0xBF) ويُتلف النص. التحويل
 *    الصحيح هو جعل كل بايت CP1256 محرفاً لاتينياً بكود الصفحة (0xD6 ⇒ U+00D6) فيستقر
 *    البايت 0xD6 في القاعدة كما تفعل أنظمة الشركة القديمة، ثم يصلحه fixMojibake عند القراءة.
 *    Arabic must be written through toOracleSearchChars(): the app connects as an AL32UTF8
 *    client to a WE8MSWIN1252 database, so raw CP1256 bytes get replaced with 0xBF. Mapping
 *    every CP1256 byte to the Latin-1 code point of the same value lands the byte correctly,
 *    exactly like the legacy system, and fixMojibake() repairs it on read.
 * 2) السكربت idempotent: يمكن إعادة تشغيله بأمان؛ يحدّث ما هو موجود ويدرج الجديد فقط.
 *    Safe to re-run: existing rows are updated, missing rows inserted.
 * 3) تُستخدم استعلامات مربوطة (bind) حصراً — لا لصق نصي داخل SQL.
 *    Bound statements only — never string interpolation.
 */

use App\Support\AppConfig;
use App\Support\ArabicEncoding;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$oracle = DB::connection('oracle');

/**
 * إدراج أو تحديث صف صلاحية واحدة
 * Insert or update a single permission row
 */
$upsertPermission = static function (array $row) use ($oracle): string {
    $existing = $oracle->select(
        'SELECT PERMISSION_ID FROM ACCOUNTS.APP_PERMISSIONS WHERE PERMISSION_CODE = :code',
        ['code' => $row['permission_code']]
    );

    if (! empty($existing)) {
        $oracle->update(
            'UPDATE ACCOUNTS.APP_PERMISSIONS
                SET MODULE_CODE      = :module,
                    ACTION_CODE      = :action,
                    NAME_AR          = :name_ar,
                    NAME_EN          = :name_en,
                    IS_CORE          = :is_core,
                    SORT_ORDER       = :sort_order,
                    DESCRIPTION_AR   = :desc_ar,
                    LAST_UPDATED_ON  = SYSDATE
              WHERE PERMISSION_CODE  = :code',
            [
                'module' => $row['module_code'],
                'action' => $row['action_code'],
                'name_ar' => ArabicEncoding::toOracleSearchChars($row['name_ar']),
                'name_en' => $row['name_en'],
                'is_core' => $row['is_core'],
                'sort_order' => $row['sort_order'],
                'desc_ar' => ArabicEncoding::toOracleSearchChars($row['description_ar']),
                'code' => $row['permission_code'],
            ]
        );

        return 'updated';
    }

    $oracle->insert(
        'INSERT INTO ACCOUNTS.APP_PERMISSIONS
            (PERMISSION_CODE, MODULE_CODE, ACTION_CODE, NAME_AR, NAME_EN,
             IS_CORE, SORT_ORDER, IS_ACTIVE, DESCRIPTION_AR, CREATED_ON, CREATED_BY)
         VALUES
            (:code, :module, :action, :name_ar, :name_en,
             :is_core, :sort_order, 1, :desc_ar, SYSDATE, NULL)',
        [
            'code' => $row['permission_code'],
            'module' => $row['module_code'],
            'action' => $row['action_code'],
            'name_ar' => ArabicEncoding::toOracleSearchChars($row['name_ar']),
            'name_en' => $row['name_en'],
            'is_core' => $row['is_core'],
            'sort_order' => $row['sort_order'],
            'desc_ar' => ArabicEncoding::toOracleSearchChars($row['description_ar']),
        ]
    );

    return 'inserted';
};

/**
 * قائمة إعدادات الأمان الافتراضية (D7 / D8) — مصدرها الوحيد هو هذا الملف حتى تبقى
 * القيم والحدود والنصوص العربية متطابقة مع شاشة «إعدادات الأمان».
 * Default security settings (D7 / D8). This list is the single source of truth so that
 * keys, ranges and Arabic labels stay aligned with the security-settings screen.
 *
 * @var array<int, array{key:string, value:string, type:string, name_ar:string, desc_ar:string, min:?int, max:?int, lockable:int, sort:int}>
 */
$settings = [
    [
        'key' => 'session_idle_minutes',
        'value' => '240',
        'type' => 'int',
        'name_ar' => 'مهلة خمول الجلسة (بالدقائق)',
        'desc_ar' => 'مدة غياب النشاط التي بعدها تنتهي جلسة المستخدم تلقائياً',
        'min' => 5,
        'max' => 10080,
        'lockable' => 0,
        'sort' => 10,
    ],
    [
        'key' => 'session_absolute_days',
        'value' => '14',
        'type' => 'int',
        'name_ar' => 'العمر الأقصى المطلق للجلسة (بالأيام)',
        'desc_ar' => 'أقصى عمر للجلسة منذ إنشائها مهما كان المستخدم نشطاً',
        'min' => 1,
        'max' => 365,
        'lockable' => 0,
        'sort' => 20,
    ],
    [
        'key' => 'session_touch_threshold_minutes',
        'value' => '5',
        'type' => 'int',
        'name_ar' => 'حد تجديد الجلسة (بالدقائق)',
        'desc_ar' => 'أقل مدة بين عمليتَي تجديد تلقائي للجلسة لتقليل كتابات قاعدة البيانات',
        'min' => 1,
        'max' => 60,
        'lockable' => 0,
        'sort' => 30,
    ],
    [
        'key' => 'single_active_session_per_device',
        'value' => '0',
        'type' => 'bool',
        'name_ar' => 'جلسة واحدة نشطة لكل مستخدم',
        'desc_ar' => 'عند التفعيل إنهاء جلسات المستخدم القديمة تلقائياً عند كل دخول جديد',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 40,
    ],
    [
        'key' => 'password_min_length',
        'value' => '4',
        'type' => 'int',
        'name_ar' => 'الحد الأدنى لطول كلمة المرور',
        'desc_ar' => 'أقل عدد محارف مسموح في كلمة مرور جديدة (4 للتوافق مع البيانات القديمة)',
        'min' => 4,
        'max' => 32,
        'lockable' => 0,
        'sort' => 50,
    ],
    [
        'key' => 'max_failed_logins',
        'value' => '0',
        'type' => 'int',
        'name_ar' => 'الحد الأقصى لمحاولات الدخول الفاشلة',
        'desc_ar' => 'صفر يعني تعطيل قفل الحساب؛ التفعيل يتطلب أعمدة عدّاد إضافية',
        'min' => 0,
        'max' => 20,
        'lockable' => 0,
        'sort' => 60,
    ],
    [
        'key' => 'lockout_minutes',
        'value' => '15',
        'type' => 'int',
        'name_ar' => 'مدة قفل الحساب بعد المحاولات الفاشلة (بالدقائق)',
        'desc_ar' => 'تُطبَّق فقط عندما تكون محاولات الدخول الفاشلة مفعّلة',
        'min' => 1,
        'max' => 1440,
        'lockable' => 0,
        'sort' => 70,
    ],
    [
        'key' => 'biometric_login_enabled',
        'value' => '1',
        'type' => 'bool',
        'name_ar' => 'تفعيل الدخول بالبصمة',
        'desc_ar' => 'إتاحة الدخول بالبصمة على مستوى النظام لجميع المستخدمين',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 80,
    ],
    [
        'key' => 'activity_log_retention_days',
        'value' => '180',
        'type' => 'int',
        'name_ar' => 'مدة الاحتفاظ بسجل النشاط (بالأيام)',
        'desc_ar' => 'عدد الأيام الذي يُحفظ سجل نشاط المستخدمين قبل أرشفته أو حذفه',
        'min' => 30,
        'max' => 3650,
        'lockable' => 0,
        'sort' => 90,
    ],
    [
        'key' => 'maintenance_message',
        'value' => '',
        'type' => 'string',
        'name_ar' => 'رسالة الصيانة',
        'desc_ar' => 'رسالة اختيارية تظهر للمستخدمين عند إيقاف الخدمة مؤقتاً',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 100,
    ],

    // ---------------------------------------------------------------------
    // تهيئة التطبيق: هوية الشركة والمظهر وكلمة سر إعدادات اتصال API
    // Application configuration: company identity, theming, and the API-settings password.
    //
    // القيم الافتراضية مرجعها `AppConfig::DEFAULTS` حتى لا يتباعد البذر عن احتياطي الكود، والنصوص
    // العربية تُمرَّر عبر `toOracleSearchChars()` لأنها تُكتب خام في عمود القيمة (لا يصلحها المغلق).
    // Defaults reference AppConfig::DEFAULTS so the seed never drifts from the code fallback, and
    // Arabic text passes through toOracleSearchChars() because the value column is written raw.
    //
    // ⚠️ `api_settings_password` يُبذر كمجزّأة bcrypt للكلمة الافتراضية ولا يُخزَّن نصاً صريحاً أبداً؛
    // وعلى المشغّل تغييرها من التطبيق بعد أول دخول. المفتاح مقفل (IS_LOCKABLE=1) فلا يُعدَّل إلا
    // من `PUT /app-config/api-password` خلف `permission:app_config.api_manage`.
    // api_settings_password is seeded as a bcrypt digest of the default password, never plaintext;
    // the operator should change it in-app after first login. It is locked, editable only via
    // PUT /app-config/api-password behind permission:app_config.api_manage.
    // ---------------------------------------------------------------------
    [
        'key' => AppConfig::COMPANY_NAME,
        'value' => ArabicEncoding::toOracleSearchChars(AppConfig::DEFAULTS[AppConfig::COMPANY_NAME]),
        'type' => 'string',
        'name_ar' => 'اسم الشركة',
        'desc_ar' => 'اسم الشركة الظاهر في التطبيق وشاشة الدخول والتقارير',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 110,
    ],
    [
        'key' => AppConfig::COMPANY_ADDRESS,
        'value' => AppConfig::DEFAULTS[AppConfig::COMPANY_ADDRESS],
        'type' => 'string',
        'name_ar' => 'عنوان الشركة',
        'desc_ar' => 'العنوان الرسمي للشركة',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 120,
    ],
    [
        'key' => AppConfig::COMPANY_WEBSITE,
        'value' => AppConfig::DEFAULTS[AppConfig::COMPANY_WEBSITE],
        'type' => 'string',
        'name_ar' => 'الموقع الإلكتروني للشركة',
        'desc_ar' => 'رابط الموقع الرسمي للشركة',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 130,
    ],
    [
        'key' => AppConfig::COMPANY_PHONE,
        'value' => AppConfig::DEFAULTS[AppConfig::COMPANY_PHONE],
        'type' => 'string',
        'name_ar' => 'هاتف الشركة',
        'desc_ar' => 'رقم هاتف التواصل مع الشركة',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 140,
    ],
    [
        'key' => AppConfig::APP_NAME,
        'value' => ArabicEncoding::toOracleSearchChars(AppConfig::DEFAULTS[AppConfig::APP_NAME]),
        'type' => 'string',
        'name_ar' => 'اسم التطبيق',
        'desc_ar' => 'الاسم الظاهر في عنوان التطبيق',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 150,
    ],
    [
        'key' => AppConfig::THEME_PRIMARY_COLOR,
        'value' => AppConfig::DEFAULTS[AppConfig::THEME_PRIMARY_COLOR],
        'type' => 'string',
        'name_ar' => 'اللون الأساسي',
        'desc_ar' => 'اللون الأساسي لهوية التطبيق بصيغة #RRGGBB',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 160,
    ],
    [
        'key' => AppConfig::THEME_SECONDARY_COLOR,
        'value' => AppConfig::DEFAULTS[AppConfig::THEME_SECONDARY_COLOR],
        'type' => 'string',
        'name_ar' => 'اللون الثانوي',
        'desc_ar' => 'اللون الثانوي لهوية التطبيق بصيغة #RRGGBB',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 170,
    ],
    [
        'key' => AppConfig::THEME_BACKGROUND_COLOR,
        'value' => AppConfig::DEFAULTS[AppConfig::THEME_BACKGROUND_COLOR],
        'type' => 'string',
        'name_ar' => 'لون الخلفية',
        'desc_ar' => 'لون خلفية الشاشات بصيغة #RRGGBB',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 180,
    ],
    [
        'key' => AppConfig::THEME_TEXT_PRIMARY_COLOR,
        'value' => AppConfig::DEFAULTS[AppConfig::THEME_TEXT_PRIMARY_COLOR],
        'type' => 'string',
        'name_ar' => 'لون الخط الرئيسي',
        'desc_ar' => 'لون العناوين والنصوص الرئيسية بصيغة #RRGGBB',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 190,
    ],
    [
        'key' => AppConfig::THEME_TEXT_SECONDARY_COLOR,
        'value' => AppConfig::DEFAULTS[AppConfig::THEME_TEXT_SECONDARY_COLOR],
        'type' => 'string',
        'name_ar' => 'لون الخط العادي',
        'desc_ar' => 'لون النصوص الثانوية بصيغة #RRGGBB',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 200,
    ],
    [
        'key' => AppConfig::THEME_FONT_FAMILY,
        'value' => AppConfig::DEFAULTS[AppConfig::THEME_FONT_FAMILY],
        'type' => 'string',
        'name_ar' => 'خط التطبيق',
        'desc_ar' => 'عائلة الخط المستخدمة في التطبيق ضمن القائمة المسموحة',
        'min' => null,
        'max' => null,
        'lockable' => 0,
        'sort' => 210,
    ],
    [
        'key' => AppConfig::API_SETTINGS_PASSWORD,
        'value' => Hash::make('A1.b2.c3.d4'),
        'type' => 'string',
        'name_ar' => 'كلمة سر إعدادات اتصال API',
        'desc_ar' => 'كلمة سر ثابتة لفتح إعدادات اتصال الخادم (مجزّأة bcrypt ومقفلة)',
        'min' => null,
        'max' => null,
        'lockable' => 1,
        'sort' => 220,
    ],
];

$upsertSetting = static function (array $setting) use ($oracle): string {
    $existing = $oracle->select(
        'SELECT SETTING_ID FROM ACCOUNTS.APP_SETTINGS WHERE SETTING_KEY = :key',
        ['key' => $setting['key']]
    );

    $fields = [
        'value' => $setting['value'],
        'type' => $setting['type'],
        'name_ar' => ArabicEncoding::toOracleSearchChars($setting['name_ar']),
        'desc_ar' => ArabicEncoding::toOracleSearchChars($setting['desc_ar']),
        'min' => $setting['min'],
        'max' => $setting['max'],
        'lockable' => $setting['lockable'],
        'sort' => $setting['sort'],
    ];

    if (! empty($existing)) {
        // القيم الحالية المحرَّرة من الواجهة لا تُستبدل؛ نحدّث الوصف والحدود فقط
        // Keep operator-edited values; refresh only labels, ranges and ordering
        $oracle->update(
            'UPDATE ACCOUNTS.APP_SETTINGS
                SET VALUE_TYPE = :type,
                    NAME_AR = :name_ar,
                    DESCRIPTION_AR = :desc_ar,
                    MIN_VALUE = :min,
                    MAX_VALUE = :max,
                    IS_LOCKABLE = :lockable,
                    SORT_ORDER = :sort,
                    UPDATED_ON = SYSDATE
              WHERE SETTING_KEY = :key',
            [
                'type' => $fields['type'],
                'name_ar' => $fields['name_ar'],
                'desc_ar' => $fields['desc_ar'],
                'min' => $fields['min'],
                'max' => $fields['max'],
                'lockable' => $fields['lockable'],
                'sort' => $fields['sort'],
                'key' => $setting['key'],
            ]
        );

        return 'updated';
    }

    $oracle->insert(
        'INSERT INTO ACCOUNTS.APP_SETTINGS
            (SETTING_KEY, SETTING_VALUE, VALUE_TYPE, NAME_AR, DESCRIPTION_AR,
             MIN_VALUE, MAX_VALUE, IS_LOCKABLE, SORT_ORDER, UPDATED_ON)
         VALUES
            (:key, :value, :type, :name_ar, :desc_ar,
             :min, :max, :lockable, :sort, SYSDATE)',
        [
            'key' => $setting['key'],
            'value' => $fields['value'],
            'type' => $fields['type'],
            'name_ar' => $fields['name_ar'],
            'desc_ar' => $fields['desc_ar'],
            'min' => $fields['min'],
            'max' => $fields['max'],
            'lockable' => $fields['lockable'],
            'sort' => $fields['sort'],
        ]
    );

    return 'inserted';
};

$permissionResult = ['inserted' => 0, 'updated' => 0];

foreach (PermissionCatalog::rows() as $row) {
    $permissionResult[$upsertPermission($row)]++;
}

$settingResult = ['inserted' => 0, 'updated' => 0];

foreach ($settings as $setting) {
    $settingResult[$upsertSetting($setting)]++;
}

/**
 * قراءة عمود من نتيجة أوراكل بغضّ النظر عن حالة الأحرف (C / c)
 * Read a column from an Oracle result regardless of key case (C / c)
 */
$scalar = static function (string $sql) use ($oracle): int {
    $row = (array) $oracle->selectOne($sql);

    return (int) ($row['C'] ?? $row['c'] ?? 0);
};

$totalPermissions = $scalar('SELECT COUNT(*) AS C FROM ACCOUNTS.APP_PERMISSIONS');
$totalSettings = $scalar('SELECT COUNT(*) AS C FROM ACCOUNTS.APP_SETTINGS');

echo 'PERMISSIONS  catalog='.PermissionCatalog::count()
    ." inserted={$permissionResult['inserted']} updated={$permissionResult['updated']} total={$totalPermissions}\n";
echo 'SETTINGS     seeded='.count($settings)
    ." inserted={$settingResult['inserted']} updated={$settingResult['updated']} total={$totalSettings}\n";
