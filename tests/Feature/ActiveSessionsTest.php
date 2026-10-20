<?php

use App\Models\User;
use App\Support\ArabicEncoding;
use App\Support\PermissionCatalog;
use App\Support\PermissionResolver;
use App\Support\SessionPolicy;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات الجلسات النشطة (مرحلة 4)
 * Active sessions tests (phase 4).
 *
 * كل الحالات قراءة محضة أو فروع رفض تعود قبل أي عبارة كتابة: لا تُنهى جلسة حقيقية على قاعدة الإرث،
 * لأن إنهاء جلسة مستخدم حقيقي يطرد جهازه من التطبيق. مسارات `DELETE` تُختبر بمعرّفات غير موجودة.
 * Everything reads or hits a refusal branch that returns before any write — terminating a real
 * session would evict a live device. The DELETE verbs are exercised with ids that do not exist.
 *
 * يتطلب الاتصال بقاعدة `oracle` كما في بقية اختبارات IBAC؛ غيابه ظرف بيئي لا انحدار في الكود.
 * Needs a reachable Oracle connection, like the rest of the IBAC tests; absence is environmental.
 */

/**
 * مدير النظام الحيّ أو تخطٍّ بلطف
 * The live system administrator, or a graceful skip
 */
function sessionAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم لا وجود له ولا منح له ⇒ رفض افتراضي في كل مسار
 * A user id with no grants anywhere ⇒ deny-by-default on every route
 */
function sessionGhostUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);

    return $user;
}

/**
 * عدد صفوف من أوراكل بأمان على حالة اسم العمود (قد تكون UPPER أو lower)
 * Count Oracle rows tolerating either column-key case
 */
function sessionCount(string $sql, array $bindings = []): int
{
    $row = (array) DB::connection('oracle')->selectOne($sql, $bindings);

    return (int) ($row['TOTAL'] ?? $row['total'] ?? 0);
}

test('the session routes require a live session', function () {
    $this->getJson('/api/sessions')->assertUnauthorized();
    $this->deleteJson('/api/sessions/1')->assertUnauthorized();
    $this->deleteJson('/api/sessions/user/1')->assertUnauthorized();
});

test('an ungranted user cannot see or end any session', function () {
    Sanctum::actingAs(sessionGhostUser(), ['*']);

    $this->getJson('/api/sessions')->assertForbidden()->assertJsonPath('errors.permission', ['sessions.view']);
    $this->deleteJson('/api/sessions/1')->assertForbidden()->assertJsonPath('errors.permission', ['sessions.terminate']);
    $this->deleteJson('/api/sessions/user/1')->assertForbidden()->assertJsonPath('errors.permission', ['sessions.terminate']);
});

test('the session list answers with the admin meta shape and never leaks a token', function () {
    Sanctum::actingAs(sessionAdminUser(), ['*']);

    $response = $this->getJson('/api/sessions')->assertOk()->assertJsonStructure([
        'success',
        'message',
        'data' => [['token_id', 'user_id', 'status', 'status_label', 'is_current', 'created_at', 'last_activity_at']],
        'meta' => ['total', 'page', 'limit', 'pages'],
    ]);

    $rows = $response->json('data');

    foreach ($rows as $row) {
        // لا بصمة التوكن ولا `abilities`: الحقلان حساسان أو CLOB بلا فائدة للواجهة
        // Neither the token secret nor abilities reach the client.
        expect($row)->not->toHaveKey('token')->not->toHaveKey('abilities');
        expect(['active', 'expired', 'disabled'])->toContain($row['status']);
    }
});

test('the current-device flag stays off for a transient token', function () {
    $admin = sessionAdminUser();
    Sanctum::actingAs($admin, ['*']);

    $rows = $this->getJson('/api/sessions')->assertOk()->json('data');

    // `actingAs` يصدر توكناً عارضاً بلا معرّف في القاعدة، فلا صفّ يطابق «هذا الجهاز»
    // actingAs issues a transient token with no stored id, so no row can claim "this device".
    expect(array_filter($rows, static fn (array $row): bool => $row['is_current']))->toBe([])
        ->and($rows)->toBeArray();
});

test('the sessions are ordered by real last activity and the page size is capped', function () {
    Sanctum::actingAs(sessionAdminUser(), ['*']);

    $page = $this->getJson('/api/sessions?limit=3')->assertOk();
    $rows = $page->json('data');

    expect(count($rows))->toBeLessThanOrEqual(3)
        ->and($page->json('meta.limit'))->toBe(3);

    $times = array_map(static fn (array $row): string => (string) $row['last_activity_at'], $rows);
    $sorted = $times;
    rsort($sorted);

    expect($times)->toBe($sorted);

    // السقف ممنوع: 5000 يجب أن ينزل إلى 200 صفاً على الأكثر لا أن ينفجر أو يفرّغ الجدول
    // The cap holds: an absurd limit degrades to at most MAX_LIMIT rows instead of erroring.
    expect(count($this->getJson('/api/sessions?limit=5000')->assertOk()->json('data')))
        ->toBeLessThanOrEqual(200);
});

test('status and user filters return only what they claim', function () {
    $admin = sessionAdminUser();
    Sanctum::actingAs($admin, ['*']);
    $adminId = (int) $admin->getAttribute('USER_ID');

    $active = $this->getJson('/api/sessions?status=active')->assertOk()->json('data');

    foreach ($active as $row) {
        expect($row['status'])->toBe('active')->and($row['is_activated'])->toBe(1);
    }

    $mine = $this->getJson('/api/sessions?user_id='.$adminId)->assertOk()->json('data');

    foreach ($mine as $row) {
        expect($row['user_id'])->toBe($adminId);
    }

    // فلتر غير معروف يعامل كـ «الكل» بدل أن يرجع قائمة فارغة بخلسة
    // An unknown status falls back to "all" rather than silently returning nothing.
    expect($this->getJson('/api/sessions?status=nonsense')->assertOk()->json('meta')['total'])
        ->toBe($this->getJson('/api/sessions')->assertOk()->json('meta')['total']);
});

test('arabic names survive the session list', function () {
    Sanctum::actingAs(sessionAdminUser(), ['*']);

    $rows = $this->getJson('/api/sessions')->assertOk()->json('data');

    if ($rows === []) {
        test()->markTestSkipped('لا جلسة نشطة في القاعدة الحالية.');
    }

    // البدائل النصية تُكتب في PHP بالعربية السليمة، فأي إصلاح ثانوي فوقها يحوّلها إلى رموز؛
    // الانتماء للمجموعة الحرفية هو ما يكشف الإصلاح المزدوج الذي كانت تمرّ معه هذه الشاشة مكسورة
    // Literal labels are correct UTF-8 at source, so membership in the literal set is what exposes
    // a double repair — the earlier "is Arabic letters" assertion could not, since the garbage is
    // Arabic letters too.
    foreach ($rows as $row) {
        expect($row['user_type_label'])->toBeIn(['موظف', 'عميل'])
            ->and($row['status_label'])->toBeIn(['نشطة', 'منتهية المهلة', 'حساب معطّل']);
    }

    $withArabic = array_values(array_filter(
        $rows,
        static fn (array $row): bool => is_string($row['user_name_ar']) && $row['user_name_ar'] !== ''
    ));

    if ($withArabic === []) {
        test()->markTestSkipped('لا جلسة لمستخدم اسمه عربي في القاعدة الحالية.');
    }

    foreach ($withArabic as $row) {
        $raw = (array) DB::connection('oracle')->selectOne(
            'SELECT USER_NAME_AR FROM ACCOUNTS.APP_USERS WHERE USER_ID = :id',
            ['id' => $row['user_id']]
        );

        expect($row['user_name_ar'])->toBe(
            ArabicEncoding::fixMojibake((string) ($raw['USER_NAME_AR'] ?? $raw['user_name_ar'] ?? ''))
        );
    }
});

test('an unknown token or user cannot be terminated and nothing is written', function () {
    Sanctum::actingAs(sessionAdminUser(), ['*']);

    $before = sessionCount(
        'SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS
          WHERE TOKENABLE_ID = 999999 AND EXPIRES_AT IS NOT NULL'
    );

    $this->deleteJson('/api/sessions/999999')->assertNotFound();
    $this->deleteJson('/api/sessions/user/999999')->assertNotFound();

    $after = sessionCount(
        'SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS
          WHERE TOKENABLE_ID = 999999 AND EXPIRES_AT IS NOT NULL'
    );

    expect($after)->toBe($before);
});

test('termination is one write to expires_at, not a deleted row', function () {
    // العبارة التي يعتمد عليها الإنهاء تُختبر على نطاق لا يطابق أي صف حقيقي، فعدد الأسطر صفر
    // The statement behind termination is exercised against a scope matching no real row: zero rows.
    $affected = DB::connection('oracle')->update(
        'UPDATE ACCOUNTS.PERSONAL_ACCESS_TOKENS
            SET EXPIRES_AT = SYSDATE - 1, UPDATED_AT = SYSDATE
          WHERE TOKENABLE_ID = :user_id
            AND TOKENABLE_TYPE = :type
            AND (EXPIRES_AT IS NULL OR EXPIRES_AT > SYSDATE)',
        ['user_id' => 999999, 'type' => 'App\\Models\\User']
    );

    expect($affected)->toBe(0)
        ->and(sessionCount(
            'SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS WHERE TOKENABLE_ID = 999999'
        ))->toBe(0);
});

test('a pushed-into-the-past expiry is refused by the session policy', function () {
    // نفس الحالة التي يُنتجها الإنهاء: `EXPIRES_AT` في الماضي ⇒ رفض 401 لا استثناء
    // Exactly what termination produces: a past expiry is refused, not an exception.
    $token = new PersonalAccessToken(['name' => 'ky_test', 'token' => str_repeat('e', 64)]);
    $token->created_at = now()->subMinutes(10);
    $token->expires_at = now()->subMinute();
    $token->setRelation('tokenable', sessionAdminUser());

    expect(app(SessionPolicy::class)->validate($token, true))->toBeFalse();
});

test('the session policy reads an empty oracle date as never-expiring, not as a crash', function () {
    // رجوع العمود الفارغ `false`/`''` هو ما أفسد الطلبات قبل `SessionPolicy::toDate()`
    // Oracle returning false/empty for the NULL column is what broke requests before toDate().
    expect(SessionPolicy::toDate(null))->toBeNull()
        ->and(SessionPolicy::toDate(false))->toBeNull()
        ->and(SessionPolicy::toDate(''))->toBeNull()
        ->and(SessionPolicy::toDate('2026-12-01 10:00:00')?->year)->toBe(2026);
});

test('the termination verb is a known permission code, not a free-text audit code', function () {
    // الرمز نفسه يُكتب في `APP_USER_ACTIVITY.ACTION_CODE`: لو خرج عن الكتالوج صار سجلّاً يتعذّر فلترته
    // This string lands in ACTION_CODE: outside the catalog it becomes an unfilterable audit code.
    expect(PermissionCatalog::knows('sessions.terminate'))->toBeTrue()
        ->and(PermissionCatalog::knows('sessions.view'))->toBeTrue()
        ->and(app(PermissionResolver::class)->can(sessionAdminUser(), 'sessions.terminate'))->toBeTrue();
});
