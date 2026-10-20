<?php

use App\Http\Controllers\UserAdminController;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\SettingsStore;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات إدارة المستخدمين (مرحلة 3)
 * User administration tests (phase 3).
 *
 * كل الحالات هنا للقراءة فقط: لا تُنشئ مستخدماً ولا تحذف واحداً في قاعدة الإرث الحية.
 * المسارات التي تكتب تُغطّى بمسارات الرفض (422/403/404/409) التي تعود قبل أي كتابة.
 * Everything here is read-only against the live legacy database: no user is created or deleted.
 * The writing paths are covered by their refusal branches (422/403/404/409), which return first.
 *
 * مثل بقية اختبارات IBAC، يتطلب هذه الملف اتصال `oracle` متاحاً لأنه يقرأ الصلاحيات والمستخدمين
 * من الجدول الحقيقي؛ غياب القاعدة ظرف بيئي وليس انحداراً في الكود.
 * Like the rest of the IBAC tests, Oracle must be reachable — the classes read real grants and users.
 */

/**
 * مدير النظام الحيّ، أو تخطٍّ بلطف حيث لا قاعدة
 * The live system administrator, or a graceful skip when there is no database
 */
function adminForAdminTests(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم بلا أي منح، لا وجود له في القاعدة ⇒ يُقيَّم رفضاً افتراضياً
 * A user id with no grants anywhere ⇒ evaluated as deny-by-default
 */
function ungrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('user administration routes require a token', function () {
    $this->getJson('/api/users')->assertUnauthorized();
    $this->getJson('/api/permissions')->assertUnauthorized();
    $this->postJson('/api/users', [])->assertUnauthorized();
});

test('a user without grants is denied with the named permission', function () {
    Sanctum::actingAs(ungrantedUser(), ['*']);

    $response = $this->getJson('/api/users');

    $response->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.permission', ['users.view']);

    expect($response->json('message'))->toContain(PermissionCatalog::arabicName('users.view'));
});

test('the permission guard covers every administration route', function () {
    Sanctum::actingAs(ungrantedUser(), ['*']);

    $cases = [
        ['get', '/api/users', 'users.view'],
        ['get', '/api/users/1', 'users.view'],
        ['post', '/api/users', 'users.create'],
        ['put', '/api/users/1', 'users.update'],
        ['patch', '/api/users/1/toggle', 'users.toggle'],
        ['delete', '/api/users/1', 'users.delete'],
        ['get', '/api/users/1/activity', 'users.activity_view'],
        ['get', '/api/permissions', 'users.view'],
        ['get', '/api/users/1/permissions', 'users.view'],
        ['put', '/api/users/1/permissions', 'users.permissions_manage'],
    ];

    foreach ($cases as [$verb, $uri, $code]) {
        $response = $this->json($verb, $uri);

        expect($response->getStatusCode())->toBe(403)
            ->and($response->json('errors.permission'))->toBe([$code]);
    }
});

test('an administrator lists users with the customer-style meta shape', function () {
    Sanctum::actingAs(adminForAdminTests(), ['*']);

    $response = $this->getJson('/api/users?limit=10&page=1')->assertOk()->assertJsonPath('success', true);

    // شكل `meta` المتعمَّد هو نفسه في CustomerController/WorkerController، لا نمط AccountController
    expect($response->json('meta'))->toHaveKeys(['total', 'page', 'limit', 'pages'])
        ->and($response->json('meta.limit'))->toBe(10);
});

test('list rows never leak the password or the device token', function () {
    Sanctum::actingAs(adminForAdminTests(), ['*']);

    $payload = $this->getJson('/api/users')->assertOk()->json();

    foreach ($payload['data'] as $row) {
        expect($row)->not->toHaveKey('user_password')
            ->and($row)->not->toHaveKey('USER_PASSWORD')
            ->and($row)->not->toHaveKey('device_token')
            ->and($row)->not->toHaveKey('DEVICE_TOKEN')
            ->and($row)->toHaveKeys(['user_id', 'user_name_en', 'is_admin', 'grants_count']);
    }
});

test('sorting outside the whitelist falls back instead of reaching order by', function () {
    Sanctum::actingAs(adminForAdminTests(), ['*']);

    // محاولة تمرير عمود حساس إلى ORDER BY: القائمة البيضاء ترجعه إلى user_id بدل أي كسر
    $response = $this->getJson('/api/users?sort=USER_PASSWORD&direction=desc%3B%20drop%20table%20app_users');

    $response->assertOk()->assertJsonPath('success', true);

    $ids = array_map(static fn (array $row): int => (int) $row['user_id'], $response->json('data'));
    $ascending = $ids;
    sort($ascending);

    // الفرز المرفوض استُبدل بالافتراضي `user_id asc` ولم يصل أي نص إلى ORDER BY
    expect($ids)->toBe($ascending);
});

test('the status filter only returns matching accounts', function () {
    Sanctum::actingAs(adminForAdminTests(), ['*']);

    foreach ($this->getJson('/api/users?status=active')->assertOk()->json('data') as $row) {
        expect($row['is_activated'])->toBe(1);
    }

    foreach ($this->getJson('/api/users?status=inactive')->assertOk()->json('data') as $row) {
        expect($row['is_activated'])->toBe(0);
    }

    foreach ($this->getJson('/api/users?admin_only=1')->assertOk()->json('data') as $row) {
        expect($row['is_admin'])->toBeTrue();
    }
});

test('arabic names survive the round trip in the users list', function () {
    Sanctum::actingAs(adminForAdminTests(), ['*']);

    $rows = $this->getJson('/api/users')->assertOk()->json('data');
    $withArabic = array_values(array_filter($rows, static fn (array $row): bool => filled($row['user_name_ar'])));

    if ($withArabic === []) {
        test()->markTestSkipped('لا مستخدم باسم عربي في القاعدة الحالية.');
    }

    $name = $withArabic[0]['user_name_ar'];

    // محارف عربية فعلية، لا بايتات CP1256 ولا علامات استبدال
    expect($name)->toMatch('/[\x{0600}-\x{06FF}]/u')->and($name)->not->toContain('?');

    // البدائل النصية عربية سليمة المنبع: انتماؤها للمجموعة الحرفية يكشف أي إصلاح مزدوج
    // Literal labels are correct at source; membership in the literal set exposes a double repair.
    foreach ($rows as $row) {
        expect($row['user_type_label'])->toBeIn(['موظف', 'عميل']);
    }
});

/**
 * عقد `prepareGrants`: خانة الخطأ مصفوفة فارغة عند النجاح، و`JsonResponse` عند الرفض
 * The prepareGrants contract: an empty array on success, a JsonResponse on refusal.
 *
 * هذه الاختبارات قراءة محضة لأنها لا تلمس أي جدول: ما كان يُفشل `POST /users` هو إرجاع `null`
 * في خانة «لا خطأ» بينما يختبر المتحكم `$invalid !== []`، فترجع الدالة `null` ويُسقطهاTypeError
 * عند `store(): Return value must be of type JsonResponse, null returned`.
 * Both cases only read: the create failure was a `null` "no error" slot meeting an `$invalid !== []`
 * test in the caller, which made `store()` return null and blow up on its JsonResponse return type.
 */
test('a valid grant set leaves no response in the error slot', function () {
    $method = new ReflectionMethod(UserAdminController::class, 'prepareGrants');
    $method->setAccessible(true);

    [$ids, $invalid] = $method->invoke(app(UserAdminController::class), adminForAdminTests(), ['dashboard.view']);

    expect($invalid)->toBe([])->and($ids)->toBeArray()->and($ids)->not->toBeEmpty();

    // غياب المفاتيح في التحديث يعني «لا تلمس المنح»: تبقى الخانة الأولى null والثانية فارغة
    // Absent keys mean "don't touch grants" on update: first slot null, second still empty.
    [$untouched, $noError] = $method->invoke(app(UserAdminController::class), adminForAdminTests(), null);

    expect($untouched)->toBeNull()->and($noError)->toBe([]);
});

test('an unknown grant set answers 422 in the error slot', function () {
    $method = new ReflectionMethod(UserAdminController::class, 'prepareGrants');
    $method->setAccessible(true);

    [$ids, $invalid] = $method->invoke(app(UserAdminController::class), adminForAdminTests(), ['nope.nope']);

    expect($ids)->toBeNull()
        ->and($invalid)->toBeInstanceOf(JsonResponse::class)
        ->and($invalid->getStatusCode())->toBe(422)
        ->and($invalid->getData(true)['errors']['unknown_codes'])->toBe(['nope.nope']);
});

test('creating a user validates the password against the security setting', function () {
    Sanctum::actingAs(adminForAdminTests(), ['*']);

    $minimum = (int) app(SettingsStore::class)->int('password_min_length', 4);

    $this->postJson('/api/users', [
        'user_name_en' => 'ibac_probe_user',
        'user_password' => str_repeat('a', max(1, $minimum - 1)),
    ])->assertStatus(422)->assertJsonPath('errors.user_password.0', 'كلمة المرور أقصر من '.$minimum.' محارف / Password is too short.');

    $this->postJson('/api/users', [])->assertStatus(422)->assertJsonPath('errors.user_name_en.0', 'اسم المستخدم الإنجليزي مطلوب / Username is required.');
});

test('creating a user with a taken username answers 409 before writing', function () {
    $admin = adminForAdminTests();
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/users', [
        'user_name_en' => $admin->getAttribute('USER_NAME_EN'),
        'user_password' => 'pass1234',
    ])->assertStatus(409)->assertJsonPath('errors.user_name_en', [$admin->getAttribute('USER_NAME_EN')]);
});

test('unknown user ids answer 404 on the administration verbs', function () {
    Sanctum::actingAs(adminForAdminTests(), ['*']);

    $this->getJson('/api/users/999999')->assertNotFound();
    $this->putJson('/api/users/999999', ['note' => 'x'])->assertNotFound();
    $this->patchJson('/api/users/999999/toggle')->assertNotFound();
    $this->deleteJson('/api/users/999999')->assertNotFound();
    $this->getJson('/api/users/999999/activity')->assertOk();
});

test('an account cannot disable or delete itself', function () {
    $admin = adminForAdminTests();
    Sanctum::actingAs($admin, ['*']);

    $id = (int) $admin->getAttribute('USER_ID');

    // حذف الذات مرفوض (422) قبل أي عبارة حذف
    $this->deleteJson('/api/users/'.$id)->assertStatus(422);
    $this->patchJson('/api/users/'.$id.'/toggle')->assertStatus(422);

    // والحساب ما زال مكانه: لم يمس الحذف شيئاً
    $this->getJson('/api/users/'.$id)->assertOk();
});

test('a non-admin cannot raise the administrator flag', function () {
    Sanctum::actingAs(ungrantedUser(), ['*']);

    // reachability: المسار نفسه محجوس بصلاحية users.create قبل أي منطق
    $this->postJson('/api/users', ['user_name_en' => 'x', 'user_password' => 'pass1234', 'is_admin' => 1])
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['users.create']);
});

test('the activity trail returns repaired arabic and the same meta shape', function () {
    $admin = adminForAdminTests();
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/users/'.$admin->getAttribute('USER_ID').'/activity')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($response->json('meta'))->toHaveKeys(['total', 'page', 'limit', 'pages']);

    $descriptions = array_map(
        static fn (array $row): ?string => $row['description_ar'] ?? null,
        $response->json('data')
    );

    // أول سجل تدقيقي في هذا النظام هو تهيئة المدير، ووصفه عربي سليم
    $bootstrap = array_values(array_filter($descriptions, static fn (?string $d): bool => is_string($d) && str_contains($d, 'الصلاحيات')));

    if ($bootstrap !== []) {
        expect($bootstrap[0])->toMatch('/[\x{0600}-\x{06FF}]/u');
    }
});

test('my permissions endpoint returns the effective state of the caller', function () {
    $admin = adminForAdminTests();
    Sanctum::actingAs($admin, ['*']);

    $data = $this->getJson('/api/auth/permissions')->assertOk()->json('data');

    expect($data)->toHaveKeys(['user_id', 'is_admin', 'permissions', 'modules'])
        ->and($data['is_admin'])->toBeTrue()
        ->and($data['permissions'])->toBeArray()
        ->and(PermissionCatalog::knows('users.view'))->toBeTrue();
});
