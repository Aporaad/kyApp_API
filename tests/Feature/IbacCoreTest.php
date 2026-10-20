<?php

use App\Http\Middleware\EnsurePermission;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\PermissionResolver;
use App\Support\SessionPolicy;
use App\Support\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * لا تكتب هذه الاختبارات شيئاً في القاعدة: مسار التجديد الانزلاقي يُختبر بجلسة داخل الذاكرة
 * لانهاية قريبة فتتخطى `SessionPolicy::slide()` الكتابة أصلاً.
 * Nothing is written: the sliding path is exercised with an in-memory token whose expiry already
 * sits beyond the renewal horizon, so `slide()` skips its write.
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
    // 97 = 93 سابقة + 4 لوحدة `app_config` (منح عام + view + update + api_manage)
    // 97 = the prior 93 plus 4 for the app_config module (wildcard + view + update + api_manage).
    expect(PermissionCatalog::count())->toBe(97);

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
        // 22 = 10 إعدادات أمان + 12 صفاً لتهيئة التطبيق (بذر app_config)
        // 22 = the 10 security settings plus the 12 app-configuration rows.
        ->and($settings->definitions())->toHaveCount(22);
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
