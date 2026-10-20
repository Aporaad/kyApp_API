<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * مستخدم مدير من قاعدة oracle الحية — المسار صار خلف `auth:sanctum` + `permission:system.*`
 * A system-admin user taken from the live oracle connection: the route now sits behind
 * `auth:sanctum` plus `permission:system.*`, so every positive case needs an authenticated caller.
 *
 * الـ actingAs لا يمرّ بحارس التوكن لكنه يضفي المستخدم، و`EnsurePermission` يقرأ `$request->user()`
 * مباشرة — فيكفي أن يكون المدير لأن المحلّ يفحص `IS_ADMIN` قبل أي منح.
 * actingAs bypasses the token guard but hydrates `$request->user()`, which is all the permission
 * middleware reads; admin is enough because the resolver short-circuits on `IS_ADMIN`.
 */
function tableQueryAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

test('selectFromTable requires authentication now that business routes are sealed', function () {
    // الرقابة تسبق التحقق من المدخلات: بلا جلسة لا يعرف الخادم اسم الجدول أصلاً
    // The gate runs ahead of input validation: without a session the server never looks at the name.
    $response = $this->getJson('/api/selectFromTable?table_name=NEW_USERS&limit=2');

    $response->assertStatus(401);
});

test('selectFromTable returns data successfully for existing table', function () {
    Sanctum::actingAs(tableQueryAdminUser(), ['*']);

    $response = $this->getJson('/api/selectFromTable?table_name=NEW_USERS&limit=2');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'table',
            'count',
            'limit',
            'redacted_columns',
            'data',
        ]);
});

test('selectFromTable returns 404 for non-existent table', function () {
    Sanctum::actingAs(tableQueryAdminUser(), ['*']);

    $response = $this->getJson('/api/selectFromTable?table_name=NON_EXISTENT_TABLE_XYZ&limit=5');

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
        ]);
});

test('selectFromTable validates table name and prevents invalid characters', function () {
    Sanctum::actingAs(tableQueryAdminUser(), ['*']);

    $response = $this->getJson('/api/selectFromTable?table_name=USERS;DROP%20TABLE&limit=5');

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

test('selectFromTable validates limit constraints', function () {
    Sanctum::actingAs(tableQueryAdminUser(), ['*']);

    $response = $this->getJson('/api/selectFromTable?table_name=NEW_USERS&limit=99999');

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

test('selectFromTable denies credential and session tables even to the system admin', function () {
    Sanctum::actingAs(tableQueryAdminUser(), ['*']);

    // قائمة الرفض قرار مستقل عن الصلاحية: المدير يمرّ من البوابة ثم يُرفض هنا
    // The deny list is independent of the grant: an admin clears the gate and is still refused here.
    $response = $this->getJson('/api/selectFromTable?table_name=APP_USERS&limit=5');

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'errors' => ['table_name' => ['restricted_table']],
        ]);

    $tokens = $this->getJson('/api/selectFromTable?table_name=PERSONAL_ACCESS_TOKENS&limit=5');
    $tokens->assertStatus(403);
});

test('selectFromTable never returns a password or token column from an allowed table', function () {
    Sanctum::actingAs(tableQueryAdminUser(), ['*']);

    $response = $this->getJson('/api/selectFromTable?table_name=CURRENCY&limit=5');

    $response->assertStatus(200);

    // لا يجوز أن يخرج أي عمود يحمل اسمه إحدى هذه الكلمات، والفحص يطابق الأسماء لا القيم
    // No outgoing column may carry one of these words in its name; the check matches names, not values.
    $body = $response->json();

    foreach ($body['data'] as $row) {
        foreach (array_keys($row) as $column) {
            $upper = strtoupper($column);
            expect($upper)->not->toMatch('/PASSWORD|TOKEN|SECRET/');
        }
    }

    expect($body['redacted_columns'])->toBeArray();
});
