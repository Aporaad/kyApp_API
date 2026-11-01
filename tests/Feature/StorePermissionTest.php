<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * حجب قسم المخازن — لا وصول بلا منح، والحجب افتراضي
 * Sealing the stores module — no access without a grant, denial is the default.
 *
 * ── ما يحرسه هذا الملف: أن كل مسار في القسم يحمل وسيط `permission:` فعلاً. الواجهة تخفي
 *    الأزرار، لكن الحقيقة أن من يملك التوكن يستطيع نداء المسار مباشرة؛ فالحجب الحقيقي هو هذا.
 * ── What this guards: every route in the module really carries the `permission:` middleware.
 *    The UI hides buttons, but a token holder can call the path directly — this is the real seal.
 */
function storesAdmin(): ?User
{
    return User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();
}

/**
 * مستخدم عادي بلا أي منح قسم مخازن — لإثبات الرفض
 * A regular user holding no stores grant — to prove the denial.
 */
function storesUserWithoutGrants(): ?User
{
    $row = array_change_key_case(
        (array) DB::connection('oracle')->selectOne(
            "SELECT u.USER_ID
               FROM ACCOUNTS.APP_USERS u
              WHERE NVL(u.IS_ADMIN, 0) = 0
                AND u.IS_ACTIVATED = 1
                AND NOT EXISTS (
                      SELECT 1
                        FROM ACCOUNTS.APP_USERS_PERMISSIONS up
                        JOIN ACCOUNTS.APP_PERMISSIONS p ON p.PERMISSION_ID = up.PERMISSION_ID
                       WHERE up.USER_ID = u.USER_ID
                         AND up.IS_ACTIVE = 1
                         AND (up.EXPIRES_ON IS NULL OR up.EXPIRES_ON > SYSDATE)
                         AND p.PERMISSION_CODE LIKE 'stores.%'
                    )
                AND ROWNUM <= 1"
        ),
        CASE_UPPER
    );

    $id = (int) ($row['USER_ID'] ?? 0);

    return $id > 0 ? User::query()->find($id) : null;
}

test('the stores routes refuse an anonymous request', function () {
    if (storesAdmin() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $this->getJson('/api/stores')->assertStatus(401);
    $this->getJson('/api/stores/items')->assertStatus(401);
    $this->getJson('/api/stores/reports/movement')->assertStatus(401);
});

test('a user with no stores grant is refused on every stores entry point', function () {
    $user = storesUserWithoutGrants();

    if ($user === null) {
        test()->markTestSkipped('لا يوجد مستخدم بلا منح مخازن لإثبات الرفض.');
    }

    Sanctum::actingAs($user, ['*']);

    // كل نقطة دخول في القسم — القراءة أولاً لأنها أخفّ ما قد يُمنح
    // Every entry point, reading first because it is the lightest thing one might grant.
    $paths = [
        '/api/stores',
        '/api/stores/keepers',
        '/api/stores/reference',
        '/api/stores/catalogs/groups',
        '/api/stores/items',
        '/api/stores/receive',
        '/api/stores/issue',
        '/api/stores/transfer',
        '/api/stores/balances',
        '/api/stores/reports/movement',
        '/api/stores/reports/summary',
        '/api/stores/stock-sheet',
    ];

    foreach ($paths as $path) {
        $this->getJson($path)->assertStatus(403);
    }
});

test('a system administrator passes the same gates', function () {
    $admin = storesAdmin();

    if ($admin === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($admin, ['*']);

    $this->withoutExceptionHandling();

    $this->getJson('/api/stores')->assertStatus(200);
    $this->getJson('/api/stores/reference')->assertStatus(200);
    $this->getJson('/api/stores/items')->assertStatus(200);
    $this->getJson('/api/stores/stock-sheet')->assertStatus(200);
});

test('every stores route is registered behind a permission middleware', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/stores'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('auth:sanctum');

        $sealed = collect($middleware)->contains(
            static fn (string $name): bool => str_starts_with($name, 'permission:')
                || str_starts_with($name, 'App\Http\Middleware\EnsurePermission')
        );

        expect($sealed)->toBeTrue("المسار [{$route->uri()}] بلا وسيط صلاحية.");
    }
});
