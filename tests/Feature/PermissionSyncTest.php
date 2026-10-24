<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\PermissionGrantSync;
use App\Support\PermissionResolver;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات كتالوج الصلاحيات ومنحها (مرحلة 3)
 * Permission catalog and grant tests (phase 3).
 *
 * لا تكتب هذه الحالات في قاعدة الإرث: مسارات الاستبدال تُختبر من فرعاتها الرافضة (404/422) التي
 * تعود قبل أي `DELETE`، وحالات النجاح في الاستبدال تتطلب مستخدم اختبار لا يُنشأ هنا عمداً.
 * Nothing is written to the legacy database: the replacement path is exercised through its refusal
 * branches (404/422) that return before any DELETE. A successful replace needs a scratch user,
 * which is deliberately not created here.
 */

/**
 * مدير النظام الحيّ أو تخطٍّ بلطف
 * The live administrator, or a graceful skip
 */
function adminForGrantTests(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

test('the grant writer maps known codes to their database ids', function () {
    $resolved = app(PermissionGrantSync::class)->resolve(['users.view', 'customers.delete']);

    expect($resolved['unknown'])->toBe([])
        ->and($resolved['inactive'])->toBe([])
        ->and($resolved['ids'])->toHaveCount(2);
});

test('the grant writer reports unknown codes instead of accepting them', function () {
    $resolved = app(PermissionGrantSync::class)->resolve(['users.view', 'users.drop_table', 'ghost.permission']);

    expect($resolved['ids'])->toHaveCount(1)
        ->and($resolved['unknown'])->toBe(['users.drop_table', 'ghost.permission']);
});

test('the grant writer ignores blanks and duplicates', function () {
    $resolved = app(PermissionGrantSync::class)->resolve(['users.view', 'users.view', '', '   ', null, 7]);

    expect($resolved['ids'])->toHaveCount(1)->and($resolved['unknown'])->toBe([]);
});

test('the grant writer reads a user current grants without touching them', function () {
    $admin = adminForGrantTests();
    $current = app(PermissionGrantSync::class)->currentFor((int) $admin->getAttribute('USER_ID'));

    // المنح الحالية كلها معروفة في الكتالوج، ولا معرّف صفرياً
    foreach ($current['codes'] as $code) {
        expect(PermissionCatalog::knows($code))->toBeTrue();
    }

    expect($current['ids'])->toBe(array_values(array_filter($current['ids'], static fn (int $id): bool => $id > 0)));
});

test('replacing grants drops the cached permission codes', function () {
    $ghostId = 999999;
    $resolver = app(PermissionResolver::class);

    // نُسخ نسخة الكاش ثم نتحقق أن `forget` يمسحها: هذا ما يضمنه المستبدل بعد كل كتابة
    $resolver->codesFor($ghostId);
    expect(Cache::has('ky_ibac_perms_'.$ghostId))->toBeTrue();

    $resolver->forget($ghostId);
    expect(Cache::has('ky_ibac_perms_'.$ghostId))->toBeFalse();
});

test('the catalog endpoint serves every module with its database ids', function () {
    Sanctum::actingAs(adminForGrantTests(), ['*']);

    $response = $this->getJson('/api/permissions')->assertOk()->assertJsonPath('success', true);

    // عدد الأكواد والوحدات يُقرأ من الكتالوج نفسه — لا أرقام صريحة تتقادم عند كل توسيع
    // Codes and module counts come from the catalog itself, so the assertions never go stale.
    expect($response->json('meta.total'))->toBe(PermissionCatalog::count())
        ->and($response->json('data'))->toHaveCount(count(PermissionCatalog::grouped()));

    $usersModule = collect($response->json('data'))->firstWhere('module_code', 'users');
    $codes = array_map(static fn (array $row): string => $row['permission_code'], $usersModule['permissions']);

    // المنح العام أولاً ثم الأساسية بنفس الترتيب ثم الإضافية — نفس ما تراه واجهة المنح
    expect($codes)->toBe(['users.*', 'users.view', 'users.create', 'users.update', 'users.delete',
        'users.toggle', 'users.permissions_manage', 'users.activity_view', 'users.admin_flag_manage'])
        ->and($usersModule['permissions'][1]['permission_id'])->toBeInt();
});

test('the catalog endpoint is denied for an ungranted user', function () {
    $ghost = new User;
    $ghost->setAttribute('USER_ID', 999999);
    Sanctum::actingAs($ghost, ['*']);

    $this->getJson('/api/permissions')->assertForbidden();
});

test('reading a user permissions reports the administrator state', function () {
    $admin = adminForGrantTests();
    Sanctum::actingAs($admin, ['*']);

    $data = $this->getJson('/api/users/'.$admin->getAttribute('USER_ID').'/permissions')
        ->assertOk()
        ->assertJsonPath('data.is_admin', true)
        ->json('data');

    // المدير يملك كل الكتالوج عملياً وإن لم تُمنح له صفوف في الجدول — العدد من الكتالوج مباشرة
    // The admin effectively holds the whole catalog; the count comes from the catalog itself.
    expect($data['effective_permissions'])->toHaveCount(PermissionCatalog::count())
        ->and($data)->toHaveKeys(['granted_codes', 'granted_ids']);
});

test('reading permissions of an unknown user answers 404', function () {
    Sanctum::actingAs(adminForGrantTests(), ['*']);

    $this->getJson('/api/users/999999/permissions')->assertNotFound();
    $this->putJson('/api/users/999999/permissions', ['permissions' => ['users.view']])->assertNotFound();
});

test('syncing grants requires an explicit permissions list', function () {
    $admin = adminForGrantTests();
    Sanctum::actingAs($admin, ['*']);

    // القائمة المرسلة لمدير على نفسه: التحقق يسبق أي حذف، فلا منح تضيع
    $this->putJson('/api/users/'.$admin->getAttribute('USER_ID').'/permissions', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.permissions.0', 'قائمة الصلاحيات مطلوبة (وأقلها قائمة فارغة للسحب الكامل) / The permissions list is required.');

    // والتحقق أثبت أن المنح لم تُمس
    expect(app(PermissionGrantSync::class)->currentFor((int) $admin->getAttribute('USER_ID'))['codes'])->not->toBe([]);
});

test('syncing grants rejects unknown codes before writing anything', function () {
    $admin = adminForGrantTests();
    Sanctum::actingAs($admin, ['*']);

    $before = app(PermissionGrantSync::class)->currentFor((int) $admin->getAttribute('USER_ID'));

    $this->putJson('/api/users/'.$admin->getAttribute('USER_ID').'/permissions', [
        'permissions' => ['users.view', 'users.nope_action'],
    ])->assertStatus(422)->assertJsonPath('errors.unknown_codes', ['users.nope_action']);

    expect(app(PermissionGrantSync::class)->currentFor((int) $admin->getAttribute('USER_ID')))->toBe($before);
});
