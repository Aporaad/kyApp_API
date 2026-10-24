<?php

use App\Http\Middleware\AppendSessionExpiry;
use App\Http\Middleware\EnsurePermission;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\PermissionResolver;
use App\Support\SessionPolicy;
use App\Support\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * اختبارات نواة IBAC (مرحلة 2)
 * Tests for the IBAC core introduced in phase 2.
 *
 * مثل `TableQueryTest`، بعض هذه الاختبارات يقرأ من أوراكل مباشرة لأن الطبقات مرتبطة بالاتصال
 * `oracle` بحصر، فهي تفشل في بيئة بلا قاعدة متاحة — وهذا ظرفي وليس انحداراً في الكود.
 * Like TableQueryTest, several cases read live Oracle because these classes are bound to the
 * `oracle` connection. They fail where the database is unreachable; that is environmental, not a regression.
 *
 * لا تكتب هذه الاختبارات بياناتٍ في القاعدة: مسار التجديد الانزلاقي يُختبر بجلسة داخل الذاكرة
 * لانهاية قريبة فتتخطى `SessionPolicy::slide()` الكتابة أصلاً. الاستثناء واحد وهو اختبار الكنس،
 * ولا يمسح إلا ترويسات ميتة أصلاً (منتهية المهلة أو خاملة بعدها) — وهو سلوك الطلب نفسه لا بيانات
 * تجربة؛ لا يُنشئ صفاً ولا يلمس جدول أعمال.
 * These cases write no rows: the sliding path runs on an in-memory token whose expiry already sits
 * beyond the renewal horizon, so `slide()` skips its write. The single exception is the sweep test,
 * which deletes only headers that are already dead (past expiry, or idle beyond the window) — the
 * production behaviour itself, not fixture data. It creates no row and touches no ERP table.
 */

/**
 * مدير النظام الفعلي من القاعدة الحيّة، أو تخطّي الاختبار بلطف
 * The live system administrator, or a graceful skip
 */
function ibacAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

test('catalog gives every module its core actions in fixed order', function () {
    // 132 = 127 السابقة + 5 صافية: إزالة 4 أكواد كشف عامة (accounts/employees/cutters/tailors.statement)
    // وإضافة 9 دقيقة (3 للحسابات + كشفان لكل سجل عامل).
    // 132 = the prior 127 plus a net 5: four broad statement codes retired, nine precise ones added.
    expect(PermissionCatalog::count())->toBe(132);

    foreach (PermissionCatalog::grouped() as $module) {
        $coreCodes = array_map(
            static fn (array $row): string => $row['action_code'],
            array_filter($module['permissions'], static fn (array $row): bool => $row['is_core'] === 1)
        );

        // الأساسية تظهر أولاً وبنفس ترتيب view → create → update → delete
        // Core actions come first, always in the same order.
        expect(array_values($coreCodes))->toBe(array_values(array_intersect(PermissionCatalog::CORE_ACTIONS, $coreCodes)));
    }
});

test('catalog validates permission code shapes', function () {
    expect(PermissionCatalog::isValidCode('customers.view'))->toBeTrue()
        ->and(PermissionCatalog::isValidCode('customers.*'))->toBeTrue()
        ->and(PermissionCatalog::isValidCode('system.*'))->toBeTrue()
        ->and(PermissionCatalog::isValidCode('Customers.view'))->toBeFalse()
        ->and(PermissionCatalog::isValidCode('customers'))->toBeFalse()
        ->and(PermissionCatalog::isValidCode('customers.view.extra'))->toBeFalse()
        ->and(PermissionCatalog::isValidCode('unknown_module.view'))->toBeFalse()
        ->and(PermissionCatalog::knows('customers.delete'))->toBeTrue()
        ->and(PermissionCatalog::knows('customers.drop_table'))->toBeFalse()
        ->and(PermissionCatalog::wildcardFor('customers'))->toBe('customers.*')
        ->and(PermissionCatalog::arabicName('customers.delete'))->toBe('حذف العميل');
});

test('permission resolver denies by default', function () {
    $resolver = app(PermissionResolver::class);

    $ghost = new User;
    $ghost->setAttribute('USER_ID', 999999);

    expect($resolver->can($ghost, 'customers.view'))->toBeFalse()
        ->and($resolver->codesFor($ghost))->toBe([]);
});

test('an empty permission list never means full access', function () {
    expect(app(PermissionResolver::class)->canAny(ibacAdminUser(), []))->toBeFalse()
        ->and(app(PermissionResolver::class)->canAll(ibacAdminUser(), []))->toBeTrue();
});

test('system administrator bypasses grant checks', function () {
    $admin = ibacAdminUser();
    $resolver = app(PermissionResolver::class);

    expect($admin->isAdmin())->toBeTrue()
        ->and($resolver->isAdmin($admin))->toBeTrue()
        ->and($resolver->can($admin, 'users.permissions_manage'))->toBeTrue()
        ->and($resolver->can($admin, 'settings.security_manage'))->toBeTrue()
        ->and($resolver->payloadFor($admin))->toHaveKeys(['user_id', 'is_admin', 'permissions', 'modules'])
        ->and($resolver->payloadFor($admin)['is_admin'])->toBeTrue();
});

test('settings store returns typed session values', function () {
    $settings = app(SettingsStore::class);

    expect($settings->int('session_idle_minutes', 0))->toBeGreaterThan(0)
        ->and($settings->int('session_absolute_days', 0))->toBeGreaterThan(0)
        ->and($settings->bool('biometric_login_enabled', false))->toBeBool()
        // مفتاح غير معروف لا يكسر المسار السريع: يرجع الافتراضي
        ->and($settings->int('no_such_setting', 7))->toBe(7)
        // 24 = 10 إعدادات أمان + 14 صفاً لتهيئة التطبيق (بذر app_config + صفّا الاسم/العنوان بالإنجليزي)
        // 24 = the 10 security settings plus the 14 app-configuration rows (seed + the two English branding rows).
        ->and($settings->definitions())->toHaveCount(24);
});

test('settings store refuses an unknown key', function () {
    expect(fn () => app(SettingsStore::class)->set('unknown_key', 'x'))
        ->toThrow(InvalidArgumentException::class);
});

test('settings store refuses a value above the maximum', function () {
    $this->expectException(InvalidArgumentException::class);

    app(SettingsStore::class)->set('session_idle_minutes', 999999);
});

test('settings store refuses a wrongly typed value', function () {
    $this->expectException(InvalidArgumentException::class);

    app(SettingsStore::class)->set('session_idle_minutes', 'not-a-number');
});

test('permission middleware returns the bilingual envelope', function () {
    $middleware = app(EnsurePermission::class);
    $next = static fn () => new JsonResponse(['passed' => true]);

    // بلا مصادقة ⇒ 401
    $anonymous = $middleware->handle(Request::create('/api/admin/users', 'GET'), $next, 'users.view');
    expect($anonymous->getStatusCode())->toBe(401);

    // مستخدم غير ممنوح ⇒ 403 مع اسم الصلاحية بالعربية
    $ghost = new User;
    $ghost->setAttribute('USER_ID', 999999);

    $deniedRequest = Request::create('/api/admin/users', 'GET');
    $deniedRequest->setUserResolver(static fn () => $ghost);

    $denied = $middleware->handle($deniedRequest, $next, 'users.delete');
    expect($denied->getStatusCode())->toBe(403)
        ->and(json_decode($denied->getContent(), true)['errors'])->toBe(['permission' => ['users.delete']]);

    // مدير النظام يمر
    $adminRequest = Request::create('/api/admin/users', 'GET');
    $adminRequest->setUserResolver(static fn () => ibacAdminUser());

    expect($middleware->handle($adminRequest, $next, 'users.delete')->getContent())->toBe('{"passed":true}');
});

test('session policy accepts a live session', function () {
    $live = new PersonalAccessToken(['name' => 'ky_test', 'token' => str_repeat('a', 64)]);
    $live->created_at = now()->subMinutes(5);
    // نهاية بعيدة عن أفق التجديد ⇒ لا كتابة في القاعدة أثناء الاختبار
    $live->expires_at = now()->addMinutes(600);
    $live->setRelation('tokenable', ibacAdminUser());

    expect(app(SessionPolicy::class)->validate($live, true))->toBeTrue();
});

test('the policy clock is the database clock, so a deadline means one thing everywhere', function () {
    // القراءة وحدها: `SYSDATE` من القاعدة مقابل `dbNow()` من PHP
    // Read-only: SYSDATE from the database against dbNow() from PHP.
    $row = (array) DB::connection('oracle')->selectOne(
        "SELECT TO_CHAR(SYSDATE, 'YYYY-MM-DD HH24:MI:SS') AS DB_NOW FROM DUAL"
    );

    $sysdate = SessionPolicy::toDate($row['DB_NOW'] ?? $row['db_now'] ?? null);

    expect($sysdate)->not->toBeNull()
        // الفرق بالثواني لا بالدقائق: هذه هي invariant الخطأ — العمود يُكتب ويُقارَن على ساعة
        // القاعدة، فلو انزلقت `dbNow()` إلى ساعة PHP لبدت كل جلسة منتهية قبل أوانها بثلاث ساعات
        // Seconds, not minutes: this is the broken invariant. The column is written and read on the
        // database clock; had dbNow() slipped back to PHP's UTC clock, every session looked expired.
        ->and(abs($sysdate->diffInSeconds(SessionPolicy::dbNow(), false)))->toBeLessThanOrEqual(2);
});

test('session policy rejects expired, ancient, disabled and invalid tokens', function () {
    $policy = app(SessionPolicy::class);
    $admin = ibacAdminUser();

    $expired = new PersonalAccessToken(['name' => 'ky_test', 'token' => str_repeat('b', 64)]);
    $expired->created_at = now()->subDay();
    $expired->expires_at = now()->subMinute();
    $expired->setRelation('tokenable', $admin);

    $ancient = new PersonalAccessToken(['name' => 'ky_test', 'token' => str_repeat('c', 64)]);
    $ancient->created_at = now()->subDays(400);
    $ancient->expires_at = now()->addDay();
    $ancient->setRelation('tokenable', $admin);

    $disabledUser = clone $admin;
    $disabledUser->setAttribute('IS_ACTIVATED', 0);

    $deactivated = new PersonalAccessToken(['name' => 'ky_test', 'token' => str_repeat('d', 64)]);
    $deactivated->created_at = now();
    $deactivated->expires_at = now()->addDay();
    $deactivated->setRelation('tokenable', $disabledUser);

    expect($policy->validate($expired, true))->toBeFalse()
        // العمر المطلق يسري حتى داخل نافذة الخمول
        ->and($policy->validate($ancient, true))->toBeFalse()
        // الحساب المعطّل يفقد صلاحيته فوراً مهما كان التوكن صحيحاً
        ->and($policy->validate($deactivated, true))->toBeFalse()
        ->and($policy->validate(null, true))->toBeFalse()
        // نتيجة Sanctum السلبية لا يملك الخطاف قلبها
        ->and($policy->validate($expired, false))->toBeFalse();
});

test('the sweep deletes dead sessions and leaves live ones alone', function () {
    // `PersonalAccessToken` بلا اتصال محدد، فيسير على الافتراضي (sqlite في الاختبارات). هنا
    // يُحوَّل الافتراضي إلى أوراكل ثم يُرجَع بلا شرط: لا يبقى إعداد ملوّثاً لبقية الملف.
    // PAT carries no connection, so it follows the default (sqlite under tests). The default is
    // pointed at Oracle here and restored unconditionally, leaving no polluted config behind.
    $default = config('database.default');
    config(['database.default' => 'oracle']);

    try {
        $idle = app(SettingsStore::class)->int('session_idle_minutes', 240);

        $liveBefore = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS WHERE EXPIRES_AT > SYSDATE'
        );

        app(SessionPolicy::class)->purgeExpired();

        $datedLeft = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS
              WHERE EXPIRES_AT IS NOT NULL AND EXPIRES_AT <= SYSDATE'
        );

        // فرع الجلسات القديمة بلا موعد: هي التراكم الذي يملأ الشاشة «نشطة» إلى الأبد
        // The NULL-deadline branch: this is the accumulation that filled the screen as "active" forever.
        $legacyLeft = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS
              WHERE EXPIRES_AT IS NULL AND COALESCE(LAST_USED_AT, CREATED_AT) < SYSDATE - :minutes/1440',
            ['minutes' => $idle]
        );

        $liveAfter = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS WHERE EXPIRES_AT > SYSDATE'
        );

        $countOf = static fn (array $row): int => (int) ($row['C'] ?? $row['c'] ?? -1);

        expect($countOf($datedLeft))->toBe(0)
            ->and($countOf($legacyLeft))->toBe(0)
            // لا جلسة عاملة تُطرد بالكنس: عددها قبل العملية وبعدها سيّان
            // No working session is swept: the live count is identical before and after.
            ->and($countOf($liveAfter))->toBe($countOf($liveBefore));
    } finally {
        config(['database.default' => $default]);
    }
});

test('an authenticated response carries the session deadline header', function () {
    $token = new PersonalAccessToken(['name' => 'ky_test', 'token' => str_repeat('e', 64)]);
    $deadline = SessionPolicy::dbNow()->addMinutes(30);
    $token->expires_at = $deadline;

    $request = Request::create('/api/auth/me', 'GET');
    $request->headers->set('Authorization', 'Bearer '.str_repeat('e', 64));
    $request->setUserResolver(static fn () => ibacAdminUser()->withAccessToken($token));

    $response = (new AppendSessionExpiry)->handle(
        $request,
        static fn () => new JsonResponse(['ok' => true])
    );

    // نفس النص الذي يرسله `payloadFor()` في `/auth/permissions`: صيغة ثابتة بلا منطقة زمنية
    // The exact text the permissions payload uses: fixed format, no timezone marker.
    expect($response->headers->get(AppendSessionExpiry::HEADER))
        ->toBe($deadline->toDateTimeString());
});

test('the deadline header is absent without a session or without a deadline', function () {
    $middleware = new AppendSessionExpiry;
    $pass = static fn () => new JsonResponse(['ok' => true]);

    // طلب عام بلا ترويسة مقارنة (مسار `/hello` والدخول): لا جلسة تُبلَّغ عنها
    // A public request with no Authorization header (`/hello`, login): no session to report.
    $anonymous = Request::create('/api/hello', 'GET');
    expect($middleware->handle($anonymous, $pass)->headers->get(AppendSessionExpiry::HEADER))->toBeNull();

    // جلسة قديمة بلا `EXPIRES_AT`: لا موعد معلوم، والعميل يكتفي برفض الخادم بدل أن يخترع مهلة
    // A legacy session with no deadline: nothing known, so the client waits for the server's own 401.
    $legacy = new PersonalAccessToken(['name' => 'ky_test', 'token' => str_repeat('f', 64)]);
    $legacy->expires_at = null;

    $legacyRequest = Request::create('/api/auth/me', 'GET');
    $legacyRequest->headers->set('Authorization', 'Bearer '.str_repeat('f', 64));
    $legacyRequest->setUserResolver(static fn () => ibacAdminUser()->withAccessToken($legacy));

    expect($middleware->handle($legacyRequest, $pass)->headers->get(AppendSessionExpiry::HEADER))->toBeNull();
});
