<?php

use App\Models\User;
use App\Support\AppConfig;
use App\Support\SettingsStore;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات تهيئة التطبيق وإعدادات اتصال API (إدارة إعدادات API + التهيئة الديناميكية)
 * Application-configuration and API-settings tests (secure API config + dynamic branding).
 *
 * لا كتابة على البيانات الحيّة هنا إلا ما يعادل نفسه: اختبار الحفظ المرخَّص يُعيد القيمة الحالية
 * كما هي فتمرّ العبارة لكن لا يتغيّر شيء فعلياً، وكل فروع الرفض (401/403/422) ترجع قبل أي كتابة.
 * Nothing meaningfully mutates live data: the authorized-save test re-submits the current value, so
 * the statement runs but nothing changes, and every refusal branch (401/403/422) returns before a write.
 *
 * يتطلب اتصال `oracle` حياً مع بذر `02_ibac_seed.php` مطبَّقاً (كلمة السر الافتراضية مجزّأة هناك).
 * Needs a live Oracle connection with 02_ibac_seed.php applied (the default password is hashed there).
 */

/**
 * مدير النظام الحيّ أو تخطٍّ بلطف
 * The live system administrator, or a graceful skip
 */
function appConfigAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم بلا منح ⇒ رفض افتراضي (نفس نمط اختبارات IBAC)
 * A user with no grants ⇒ deny by default (same pattern as the IBAC tests)
 */
function appConfigGhostUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);

    return $user;
}

test('the public config endpoint answers without a session and hides the secret', function () {
    $response = $this->getJson('/api/app-config')->assertOk()->assertJsonPath('success', true);

    $data = $response->json('data');

    // الحقول غير الحساسة حاضرة، وكلمة سر API غائبة تماماً عن الحمولة العامة
    // The non-sensitive fields are present; the API password never appears in the public payload.
    expect($data)->toHaveKeys([
        AppConfig::COMPANY_NAME,
        AppConfig::APP_NAME,
        AppConfig::THEME_PRIMARY_COLOR,
        AppConfig::THEME_FONT_FAMILY,
    ])->and($data)->not->toHaveKey(AppConfig::API_SETTINGS_PASSWORD);

    // القيم العامة صالحة للرسم: لون بصيغة صحيحة وخط ضمن القائمة المسموحة
    // Public values must be paintable: a well-formed color and an allowed font.
    expect(AppConfig::isValidColor((string) $data[AppConfig::THEME_PRIMARY_COLOR]))->toBeTrue()
        ->and(AppConfig::isValidFont((string) $data[AppConfig::THEME_FONT_FAMILY]))->toBeTrue();
});

test('writing app config requires a session then the update grant', function () {
    // بلا توكن ⇒ 401، قبل أي منطق متحكم
    // No token ⇒ 401, ahead of any controller logic.
    $this->putJson('/api/app-config', [AppConfig::COMPANY_NAME => 'x'])->assertUnauthorized();

    Sanctum::actingAs(appConfigGhostUser(), ['*']);

    // توكن بلا صلاحية ⇒ 403 مسمّى بالصلاحية الناقصة
    // A token with no grant ⇒ 403, naming the missing permission.
    $this->putJson('/api/app-config', [AppConfig::COMPANY_NAME => 'x'])
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['app_config.update']);
});

test('an authorized branding save round-trips the current value without changing it', function () {
    Sanctum::actingAs(appConfigAdminUser(), ['*']);

    // نُعيد إرسال الاسم الحالي كما يقدّمه المسار العام: المسار المرخَّص يعمل ولا تتغيّر البيانات الحيّة
    // We re-submit the current name exactly as the public route serves it: the authorized path works
    // end-to-end while leaving the live value untouched (a no-op write of the identical bytes).
    $current = $this->getJson('/api/app-config')->assertOk()->json('data');

    $this->putJson('/api/app-config', [AppConfig::COMPANY_NAME => $current[AppConfig::COMPANY_NAME]])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.changed', 0);
});

test('a bad color or an unsupported font is refused with 422 before any write', function () {
    Sanctum::actingAs(appConfigAdminUser(), ['*']);

    $this->putJson('/api/app-config', [AppConfig::THEME_PRIMARY_COLOR => 'red'])->assertStatus(422);
    $this->putJson('/api/app-config', [AppConfig::THEME_PRIMARY_COLOR => '#GGG'])->assertStatus(422);
    $this->putJson('/api/app-config', [AppConfig::THEME_FONT_FAMILY => 'Comic Sans'])->assertStatus(422);

    // الرفض يقع داخل التحقق قبل المعاملة: القيمة المحفوظة لم تتغيّر
    // Refusal happens in validation, before the transaction: the stored value is unchanged.
    expect(AppConfig::isValidColor((string) app(SettingsStore::class)->get(AppConfig::THEME_PRIMARY_COLOR)))->toBeTrue();
});

test('the general config path refuses the locked api password key', function () {
    Sanctum::actingAs(appConfigAdminUser(), ['*']);

    // كلمة سر API لها مسارها المخصّص بصلاحية أرفع؛ المسار العام يرفضها ولو للمدير
    // The API password has its own higher-grant route; the general path refuses it even for an admin.
    $this->putJson('/api/app-config', [AppConfig::API_SETTINGS_PASSWORD => 'nope'])
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('verify api password accepts the seeded secret and rejects a wrong one', function () {
    // نتخطّى المقيّد هنا ليقيس الاختبار منطق التحقق وحده؛ تغطية الـ429 في اختبار مستقل
    // Skip the throttle here so this test measures verify logic alone; the 429 is covered separately.
    $this->withoutMiddleware(ThrottleRequests::class);

    // مجزّأة الكلمة الافتراضية `A1.b2.c3.d4` مبدّرة في 02_ibac_seed.php؛ الرد 200 في الحالتين
    // The default password A1.b2.c3.d4 is hashed by the seed; the response is 200 either way so the
    // client can tell a rejected password from an unreachable server.
    $this->postJson('/api/app-config/verify-api-password', ['password' => 'A1.b2.c3.d4'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson('/api/app-config/verify-api-password', ['password' => 'definitely-wrong'])
        ->assertOk()
        ->assertJsonPath('success', false);
});

test('the api password verify route is rate limited', function () {
    // نتحقق بنيةً أن المسار العامة عليه `throttle:5,1` — دليل ثابت بلا اقتران بحالة الكاش
    // Assert structurally that the public verify route carries throttle:5,1 — stable evidence that
    // does not couple the suite to shared rate-limiter cache state across runs.
    $route = collect(Route::getRoutes())
        ->first(static fn ($r): bool => $r->uri() === 'api/app-config/verify-api-password');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:5,1');
});

test('changing the api password requires the api_manage grant', function () {
    $this->putJson('/api/app-config/api-password', ['password' => 'abcd'])->assertUnauthorized();

    Sanctum::actingAs(appConfigGhostUser(), ['*']);

    $this->putJson('/api/app-config/api-password', ['password' => 'abcd'])
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['app_config.api_manage']);
});

test('a too-short api password is refused before any write', function () {
    Sanctum::actingAs(appConfigAdminUser(), ['*']);

    // أقصر من الحد الأدنى (4): يرجع 422 قبل التجزئة والكتابة، فلا تتغيّر التجزئة المحفوظة
    // Shorter than the 4-char floor: 422 returns before hashing or writing, so the stored digest is safe.
    $this->putJson('/api/app-config/api-password', ['password' => 'ab'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    // تنظيف أي أثر لمقيّد المعدل قد يكون تجمّع خلال هذه الطلبات على نفس المفتاح
    // Clear any rate-limiter residue accumulated across these requests sharing one key.
    Cache::flush();
});
