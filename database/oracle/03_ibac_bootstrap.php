<?php

/**
 * تهيئة IBAC النهائية (مرحلة 1): تعليقات الجداول والأعمدة + تفعيل حساب المدير العام
 * Final IBAC bootstrap (Phase 1): schema comments + making the first administrator.
 *
 * التشغيل / Run (من داخل kyApp_API):
 *   php artisan tinker database/oracle/03_ibac_bootstrap.php
 *
 * السكربت idempotent: التعليق يُكتب مرة تلو الأخرى بلا تكرار، ومنح system.* لا يتكرر،
 * ولا يُمنح مدير جديد إذا كان هناك مدير بالفعل (حماية من فقدان الوصول).
 * Safe to re-run: comments are re-applied, the system.* grant is not duplicated, and no new
 * administrator is promoted when one already exists (anti-lockout guard).
 */

use App\Support\ArabicEncoding;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;

$oracle = DB::connection('oracle');

/**
 * نص ثنائي اللغة جاهز للتضمين في SQL معتمر بالنص (COMMENT ON لا يقبل bind variables)
 * Build a bilingual literal safe for COMMENT ON (which accepts no bind variables)
 */
$literal = static function (string $text): string {
    // تحويل UTF-8 إلى محارف لاتينية بكود بايتات CP1256 ثم تهريب الاقتباس المفرد
    // Map UTF-8 to Latin-1 code points of the CP1256 bytes, then escape single quotes
    return "'".str_replace("'", "''", ArabicEncoding::toOracleSearchChars($text))."'";
};

/**
 * تعليقات الجداول / Table comments
 *
 * @var array<string, string>
 */
$tableComments = [
    'APP_PERMISSIONS' => 'كتالوج صلاحيات النظام (IBAC) — مصدره PermissionCatalog في التطبيق',
    'APP_USERS_PERMISSIONS' => 'منح الصلاحيات مباشرة للمستخدمين بدون أدوار — جدول IBAC الأساسي',
    'APP_SETTINGS' => 'إعدادات الأمان والجلسات القابلة للتعديل من شاشة إعدادات الأمان',
    'APP_USER_ACTIVITY' => 'سجل نشاط وتدقيق مستخدمي التطبيق (دخول، إدارة مستخدمين، جلسات، إعدادات)',
];

/**
 * تعليقات الأعمدة / Column comments
 *
 * @var array<string, array<string, string>>
 */
$columnComments = [
    'APP_USERS' => [
        'IS_ADMIN' => 'مدير النظام: 1 = يملك كل الصلاحيات ويتجاوز فحص المنح',
        'USER_PASSWORD' => 'كلمة المرور (نص صريح إرثياً، موسّعة لتستوعب التجزئة لاحقاً)',
    ],
    'APP_PERMISSIONS' => [
        'PERMISSION_ID' => 'المعرّف الداخلي للصلاحية (تسلسل SEQ_APP_PERMISSIONS)',
        'PERMISSION_CODE' => 'الرمز الفريد للنمط module.action أو module.*',
        'MODULE_CODE' => 'رمز الوحدة/القسم الذي تنتمي إليه الصلاحية',
        'ACTION_CODE' => 'رمز الفعل، وعلامة النجمة تعني منحاً عاماً للوحدة',
        'NAME_AR' => 'الاسم العربي الظاهر في واجهة اختيار الصلاحيات',
        'NAME_EN' => 'الاسم الإنجليزي الوصفي',
        'IS_CORE' => '1 = صلاحية أساسية (عرض/إضافة/تعديل/حذف) تسبق غيرها في العرض',
        'SORT_ORDER' => 'ترتيب العرض داخل الوحدة: الأساسية ثم الإضافية',
        'IS_ACTIVE' => '0 = صلاحية معزولة تُحجب عن المنح الجديد دون حذف سجل منحها السابق',
        'DESCRIPTION_AR' => 'وصف تفصيلي عربي للصلاحية',
    ],
    'APP_USERS_PERMISSIONS' => [
        'ID' => 'المعرّف الداخلي لسطر المنح (تسلسل SEQ_APP_USERS_PERM)',
        'USER_ID' => 'المستخدم الممنوح — يرتبط بـ APP_USERS.USER_ID',
        'PERMISSION_ID' => 'الصلاحية الممنوحة — ترتبط بـ APP_PERMISSIONS',
        'GRANTED_BY' => 'المستخدم الذي نفّذ المنح (للتدقيق)',
        'GRANTED_ON' => 'تاريخ المنح',
        'EXPIRES_ON' => 'تاريخ انتهاء المنح؛ فراغ = دائم',
        'IS_ACTIVE' => '1 = منح فعّال، 0 = مسحوب مع الإبقاء على الأثر',
        'NOTE' => 'ملاحظة مختصرة عن سبب المنح',
    ],
    'APP_SETTINGS' => [
        'SETTING_ID' => 'المعرّف الداخلي للإعداد (تسلسل SEQ_APP_SETTINGS)',
        'SETTING_KEY' => 'مفتاح الإعداد كما يستخدمه الكود، مثل session_idle_minutes',
        'SETTING_VALUE' => 'القيمة المخزّنة كنص وتُفسَّر حسب VALUE_TYPE',
        'VALUE_TYPE' => 'نوع القيمة: int أو bool أو string أو json',
        'NAME_AR' => 'اسم الإعداد بالعربية للعرض في الواجهة',
        'DESCRIPTION_AR' => 'شرح الإعداد وحدوده بالعربية',
        'MIN_VALUE' => 'الحد الأدنى المسموح للقيم الرقمية',
        'MAX_VALUE' => 'الحد الأقصى المسموح للقيم الرقمية',
        'IS_LOCKABLE' => '1 = إعداد حساس لا يعدَّل من الواجهة',
        'SORT_ORDER' => 'ترتيب الظهور في شاشة إعدادات الأمان',
        'UPDATED_BY' => 'المستخدم الذي آخر تعديل (للتدقيق)',
        'UPDATED_ON' => 'تاريخ آخر تعديل',
    ],
    'APP_USER_ACTIVITY' => [
        'ACTIVITY_ID' => 'المعرّف الداخلي للصف (تسلسل SEQ_APP_ACTIVITY)',
        'USER_ID' => 'المستخدم الفاعل؛ فارغ عند محاولة دخول باسم غير معروف',
        'TARGET_USER_ID' => 'المستخدم المستهدف بالإجراء (إن وُجد)',
        'ACTION_CODE' => 'رمز الإجراء مثل login و users.create و sessions.terminate',
        'MODULE_CODE' => 'الوحدة المرتبطة بالإجراء',
        'ENTITY_TYPE' => 'نوع الكيان المتأثر: app_user أو session أو setting',
        'ENTITY_ID' => 'معرّف الكيان المتأثر',
        'DESCRIPTION_AR' => 'وصف عربي جاهز للعرض في سجل النشاط',
        'STATUS' => 'نجاح أو فشل (success / failed)',
        'IP_ADDRESS' => 'عنوان IP للطلب (يدعم IPv6)',
        'USER_AGENT' => 'نص وكالة المستخدم الذي يعرّف الجهاز أو المتصفح',
        'PLATFORM' => 'نظام الجهاز: android أو ios أو web أو windows',
        'CREATED_ON' => 'توقيت الحدث',
    ],
];

$commentCount = 0;

foreach ($tableComments as $table => $text) {
    $oracle->statement("COMMENT ON TABLE ACCOUNTS.$table IS ".$literal($text));
    $commentCount++;
}

foreach ($columnComments as $table => $columns) {
    foreach ($columns as $column => $text) {
        $oracle->statement("COMMENT ON COLUMN ACCOUNTS.$table.$column IS ".$literal($text));
        $commentCount++;
    }
}

/**
 * قراءة عمود واحد من نتيجة أوراكل مع تحمّل اختلاف حالة الأحرف (C / c، NAME / name)
 * Read a single column tolerating Oracle's inconsistent key case
 */
$scalar = static function (string $sql, array $binds = []) use ($oracle) {
    $row = (array) $oracle->selectOne($sql, $binds);

    return $row === [] ? null : reset($row);
};

/**
 * الخطوة الأولى: المدير العام. لا يُعيَّن مدير جديد إذا كان هناك مدير فعّال بالفعل.
 * Step 1: the first administrator. Never promote a second admin when one already exists.
 */
$existingAdmins = (int) $scalar('SELECT COUNT(*) AS C FROM ACCOUNTS.APP_USERS WHERE IS_ADMIN = 1');

$adminNote = 'none';

if ($existingAdmins === 0) {
    $oracle->update(
        'UPDATE ACCOUNTS.APP_USERS
            SET IS_ADMIN = 1, USER_TYPE = NVL(USER_TYPE, 1)
          WHERE USER_ID = 1 AND NVL(IS_ACTIVATED, 0) = 1'
    );

    $adminNote = 'promoted user_id=1';
} else {
    $adminNote = "skipped (admins=$existingAdmins)";
}

/**
 * الخطوة الثانية: منح شامل صريح للنظام (دلالة مكمّلة لـ IS_ADMIN).
 * Step 2: an explicit system.* grant (complements IS_ADMIN).
 */
$systemPermissionId = (int) $scalar(
    'SELECT PERMISSION_ID FROM ACCOUNTS.APP_PERMISSIONS WHERE PERMISSION_CODE = :code',
    ['code' => PermissionCatalog::SYSTEM_WILDCARD]
);

$grantNote = 'skipped (system.* row missing)';

if ($systemPermissionId > 0) {
    $alreadyGranted = $oracle->select(
        'SELECT ID FROM ACCOUNTS.APP_USERS_PERMISSIONS
          WHERE USER_ID = 1 AND PERMISSION_ID = :pid',
        ['pid' => $systemPermissionId]
    );

    if (empty($alreadyGranted)) {
        $oracle->insert(
            'INSERT INTO ACCOUNTS.APP_USERS_PERMISSIONS
                (USER_ID, PERMISSION_ID, GRANTED_BY, GRANTED_ON, IS_ACTIVE, NOTE)
             VALUES
                (1, :pid, NULL, SYSDATE, 1, :note)',
            [
                'pid' => $systemPermissionId,
                'note' => ArabicEncoding::toOracleSearchChars('منح أولي عند تهيئة النظام'),
            ]
        );

        $grantNote = 'system.* granted to user_id=1';
    } else {
        $grantNote = 'already granted';
    }
}

/**
 * الخطوة الثالثة: صفحة تدقيق لتهيئة النظام (تُتجاهل عند الفشل — السجل لا يُفشل التهيئة)
 * Step 3: one audit row for the bootstrap itself; failures are swallowed on purpose.
 */
try {
    $alreadyLogged = $oracle->select(
        "SELECT ACTIVITY_ID FROM ACCOUNTS.APP_USER_ACTIVITY WHERE ACTION_CODE = 'system.bootstrap'"
    );

    if (empty($alreadyLogged)) {
        $oracle->insert(
            'INSERT INTO ACCOUNTS.APP_USER_ACTIVITY
                (USER_ID, TARGET_USER_ID, ACTION_CODE, MODULE_CODE, ENTITY_TYPE, ENTITY_ID,
                 DESCRIPTION_AR, STATUS, CREATED_ON)
             VALUES
                (1, 1, :action, :module, :entity, :entity_id, :descr, :status, SYSDATE)',
            [
                'action' => 'system.bootstrap',
                'module' => 'system',
                'entity' => 'app_user',
                'entity_id' => '1',
                'descr' => ArabicEncoding::toOracleSearchChars(
                    'تهيئة نظام الصلاحيات: تفعيل صفة مدير النظام ومنح شامل للنظام'
                ),
                'status' => 'success',
            ]
        );
    }
} catch (Throwable $e) {
    echo 'ACTIVITY_LOG_SKIPPED: '.$e->getMessage()."\n";
}

$adminFlag = $scalar('SELECT IS_ADMIN FROM ACCOUNTS.APP_USERS WHERE USER_ID = 1');
$grantCount = (int) $scalar(
    'SELECT COUNT(*) AS C FROM ACCOUNTS.APP_USERS_PERMISSIONS WHERE USER_ID = 1'
);

echo 'COMMENTS       applied='.$commentCount."\n";
echo 'ADMIN          '.$adminNote.' (user_id=1 IS_ADMIN='.(int) $adminFlag.")\n";
echo 'GRANT          '.$grantNote." | user_1_grants=$grantCount\n";
