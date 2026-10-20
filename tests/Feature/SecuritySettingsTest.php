<?php

use App\Models\User;
use App\Support\AppConfig;
use App\Support\ArabicEncoding;
use App\Support\PermissionCatalog;
use App\Support\PermissionResolver;
use App\Support\SettingsStore;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات إعدادات الأمان (مرحلة 4)
 * Security settings tests (phase 4).
 *
 * لا حفظ في هذه الملفات: `PUT /settings` يُغطى من فروع التحقق والرفض التي ترجع قبل أي عبارة كتابة،
 * لأن تعديل `session_idle_minutes` مثلاً يغيّر متى تسقط جلسات حقيقية على أجهزة مستخدمين حقيقيين.
 * Nothing is saved here: PUT is covered through validation and refusal branches that return before
 * any write, because changing e.g. the idle window would drop live sessions on real devices.
 *
 * يتطلب اتصال `oracle` متاحاً كبقية اختبارات IBAC.
 * Needs a reachable Oracle connection, like the rest of the IBAC tests.
 */

/**
 * مدير النظام الحيّ أو تخطٍّ بلطف
 * The live system administrator, or a graceful skip
 */
function settingsAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم بلا منح ⇒ رفض افتراضي
 * A user with no grants ⇒ deny by default
 */
function settingsGhostUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);

    return $user;
}

/**
 * قيمة مفتاح كما هي في القاعدة الآن (بلا كاش) — مرجع مقارنات «لم تتغير»
 * A key's value straight from Oracle (uncached) — the reference for "it did not change"
 */
function rawSetting(string $key): ?string
{
    $row = (array) DB::connection('oracle')->selectOne(
        'SELECT SETTING_VALUE FROM ACCOUNTS.APP_SETTINGS WHERE SETTING_KEY = :key',
        ['key' => $key]
    );

    $value = $row['SETTING_VALUE'] ?? $row['setting_value'] ?? null;

    return $value === null ? null : (string) $value;
}

/**
 * عدد صفوف من أوراكل بتحمّل حالة اسم العمود
 * Count Oracle rows, tolerating either column-key case
 */
function settingsRowCount(string $sql, array $bindings = []): int
{
    $row = (array) DB::connection('oracle')->selectOne($sql, $bindings);

    return (int) ($row['TOTAL'] ?? $row['total'] ?? 0);
}

test('the settings routes require a live session', function () {
    $this->getJson('/api/settings')->assertUnauthorized();
    $this->putJson('/api/settings', ['items' => [['key' => 'session_idle_minutes', 'value' => 60]]])
        ->assertUnauthorized();
});

test('an ungranted user can neither read nor write a setting', function () {
    Sanctum::actingAs(settingsGhostUser(), ['*']);

    $this->getJson('/api/settings')->assertForbidden()->assertJsonPath('errors.permission', ['settings.view']);

    $this->putJson('/api/settings', ['items' => [['key' => 'session_idle_minutes', 'value' => 60]]])
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['settings.update']);
});

test('the settings screen gets every key with its arabic label and typed value', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    $response = $this->getJson('/api/settings')->assertOk()->assertJsonStructure([
        'success',
        'message',
        'data' => [[
            'setting_id', 'key', 'name_ar', 'description_ar', 'value_type', 'value',
            'raw_value', 'min_value', 'max_value', 'is_lockable', 'sort_order',
        ]],
        'meta' => ['total'],
    ]);

    $settings = app(SettingsStore::class);

    // شاشة إعدادات الأمان تعرض مفاتيح الأمان وحدها: مفاتيح تهيئة التطبيق (AppConfig::keys) معزولة
    // في شاشتها ومتحكمها الخاص، فالعدد المتوقع هو تعريفات الأمان بعد استبعاد مفاتيح التهيئة.
    // The security screen lists only security keys; app-config keys live in their own screen and
    // controller, so the expected total is the definitions after excluding the app-config keys.
    $securityCount = count(array_filter(
        $settings->definitions(),
        static fn (array $definition): bool => ! in_array($definition['key'], AppConfig::keys(), true)
    ));

    expect($response->json('meta.total'))->toBe($securityCount);

    $byKey = [];

    foreach ($response->json('data') as $row) {
        $byKey[$row['key']] = $row;
    }

    // المفاتيح التي تقرأها سياسة الجلسة يجب أن تظهر بأسمائها الصحيحة، لا بصيغتها المفككة
    // The keys the session policy depends on must be present under their real names.
    expect($byKey)->toHaveKeys([
        'session_idle_minutes', 'session_absolute_days', 'session_touch_threshold_minutes',
        'password_min_length', 'biometric_login_enabled',
    ])->and($byKey['session_idle_minutes']['value'])->toBeInt()
        ->and($byKey['biometric_login_enabled']['value'])->toBeBool();

    // الإصلاح مرة واحدة هو العقد: `fixMojibake` لا تُطبَّق على عربية سليمة بل تُنتج رموزاً
    // (مثلاً «موظف» تصبح «ظ…ظˆط¸ظپ»)، فمطابقة ما أرسله المتحكم لنسخة المستودع المُصلَحة مرة واحدة
    // هي الوحيدة القادرة على كشف الإصلاح المزدوج —Regex العربية وحدها لا تفضحه لأن الناتج عربي أيضاً
    // One repair is the contract: fixMojibake on already-correct Arabic yields symbols, so the only
    // assertion that catches a double repair is equality with the store's single-repaired value.
    $source = [];

    foreach ($settings->definitions() as $definition) {
        $source[$definition['key']] = ['name_ar' => $definition['name_ar'], 'description_ar' => $definition['description_ar']];
    }

    foreach ($byKey as $key => $row) {
        expect($row['name_ar'])->toBe($source[$key]['name_ar'])
            ->and($row['description_ar'])->toBe($source[$key]['description_ar']);
    }

    $raw = (array) DB::connection('oracle')->selectOne(
        "SELECT NAME_AR FROM ACCOUNTS.APP_SETTINGS WHERE SETTING_KEY = 'session_idle_minutes'"
    );

    expect($byKey['session_idle_minutes']['name_ar'])->toBe(
        ArabicEncoding::fixMojibake((string) ($raw['NAME_AR'] ?? $raw['name_ar'] ?? ''))
    );
});

test('a saved setting stays inside the bounds the table declares', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    foreach ($this->getJson('/api/settings')->assertOk()->json('data') as $row) {
        if ($row['value_type'] !== 'int' || $row['value'] === null) {
            continue;
        }

        if ($row['min_value'] !== null) {
            expect($row['value'])->toBeGreaterThanOrEqual($row['min_value']);
        }

        if ($row['max_value'] !== null) {
            expect($row['value'])->toBeLessThanOrEqual($row['max_value']);
        }
    }
});

test('a settings batch must be a non-empty list of keyed items', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    $this->putJson('/api/settings', [])->assertStatus(422)
        ->assertJsonPath('errors.items.0', 'قائمة الإعدادات مطلوبة / The settings list is required.');

    $this->putJson('/api/settings', ['items' => []])->assertStatus(422)
        ->assertJsonPath('errors.items.0', 'إعداد واحد على الأقل / At least one setting is required.');

    // مفتاح الخطأ هنا يحتوي نقاطاً نصية (`items.0.key`) لأن التحقق على عناصر مصفوفة،
    // فلا يمكن الوصول إليه بمسار `data_get` — يُقرأ من المصفوفة نفسها.
    // Array-item rules key the error bag with literal dots ("items.0.key"), so data_get paths
    // cannot reach it; the message is read out of the errors array directly.
    $missingKey = $this->putJson('/api/settings', ['items' => [['value' => 10]]])->assertStatus(422)->json('errors');

    expect($missingKey)->toHaveKey('items.0.key')
        ->and($missingKey['items.0.key'][0])->toBe('مفتاح الإعداد مطلوب / The setting key is required.');
});

test('an unknown setting key is refused before any write', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    $before = settingsRowCount('SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.APP_SETTINGS');

    $this->putJson('/api/settings', ['items' => [['key' => 'no_such_setting', 'value' => 5]]])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    // المفتاح غير المعروف لا يُنشأ ولا يُخمَّن: عدد الصفوف كما كان تماماً (بلا رقم ثابت يتقادم)
    // An unknown key is neither created nor cached: the row count is unchanged (no brittle literal).
    expect(settingsRowCount('SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.APP_SETTINGS'))->toBe($before);
});

test('a value outside the declared bounds is refused and the row is untouched', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    $before = rawSetting('session_idle_minutes');

    $this->putJson('/api/settings', ['items' => [['key' => 'session_idle_minutes', 'value' => 999999]]])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(rawSetting('session_idle_minutes'))->toBe($before)
        ->and(app(SettingsStore::class)->int('session_idle_minutes', 0))->toBe((int) $before);
});

test('a wrongly typed value is refused for its declared type', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    $before = rawSetting('biometric_login_enabled');

    $this->putJson('/api/settings', ['items' => [['key' => 'biometric_login_enabled', 'value' => 'maybe']]])
        ->assertStatus(422);

    $this->putJson('/api/settings', ['items' => [['key' => 'session_idle_minutes', 'value' => 'not-a-number']]])
        ->assertStatus(422);

    expect(rawSetting('biometric_login_enabled'))->toBe($before);
});

test('a rejected batch does not poison the cached settings copy', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    // الحفظ الفاشل بعد قراءة مخمَّنة: يجب أن يبقى الكاش موافقاً للقاعدة لا لنصف تطبيق.
    // العنصر المرفوض أولاً عمداً: النوع والحدود يُرفضان داخل `normalize()` قبل أي عبارة كتابة،
    // فلا تلمس المعاملة قاعدة إرث حيّة أصلاً — لا نعوّل على التراجع بل نتجنّب الكتابة من أساسها.
    // A failed save after a cached read must leave the cache agreeing with Oracle. The invalid item
    // comes first on purpose: type/bound checks throw inside normalize() before any UPDATE statement,
    // so the live legacy database is never touched — we avoid the write rather than trust rollback.
    $store = app(SettingsStore::class);
    $store->all();

    $before = [
        'password_min_length' => rawSetting('password_min_length'),
        'session_idle_minutes' => rawSetting('session_idle_minutes'),
    ];

    $this->putJson('/api/settings', ['items' => [
        ['key' => 'session_idle_minutes', 'value' => 999999],
        ['key' => 'password_min_length', 'value' => 8],
    ]])->assertStatus(422);

    $store->flush();

    expect(rawSetting('password_min_length'))->toBe($before['password_min_length'])
        ->and(rawSetting('session_idle_minutes'))->toBe($before['session_idle_minutes'])
        ->and($store->int('password_min_length', 0))->toBe((int) $before['password_min_length'])
        ->and($store->int('session_idle_minutes', 0))->toBe((int) $before['session_idle_minutes']);
});

test('no setting is locked today, and the locked gate is still a known permission', function () {
    Sanctum::actingAs(settingsAdminUser(), ['*']);

    // لا يوجد الآن أي `IS_LOCKABLE=1`: البوابة موجودة لسيفرضه البذر لاحقاً، ورمزها معروف
    // Nothing is flagged lockable yet; the gate exists for when seeding marks a key, and its
    // permission code is part of the catalog so the 403 is nameable.
    foreach ($this->getJson('/api/settings')->assertOk()->json('data') as $row) {
        expect($row['is_lockable'])->toBeFalse();
    }

    expect(PermissionCatalog::knows('settings.security_manage'))->toBeTrue()
        ->and(app(PermissionResolver::class)->can(settingsAdminUser(), 'settings.security_manage'))->toBeTrue();
});
