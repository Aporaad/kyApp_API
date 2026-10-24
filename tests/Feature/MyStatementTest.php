<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * اختبار الكشوفات الشخصية — مالي وعمل — (المرحلة 6.1)
 * Personal statements tests (financial + work) — Phase 6.1.
 *
 * طبقتان:
 *  1) حرس الصلاحيات (401/403): يعمل بلا بيانات، لأن الرفض يسبق المتحكم.
 *     The permission gate (401/403) is data-free: denial runs ahead of the controller.
 *  2) مسارات البيانات (422/200): تتطلب أوراكل حياً وحساباً مرتبطاً؛ تُتخطّى بأناقة عند
 *     غياب البنية، لأن تعبئة `ACC_NO` (المرحلة 0.2) يدوية بطبيعتها.
 *     The data paths (422/200) need live Oracle and a linked account; they skip gracefully
 *     when that setup is absent, since ACC_NO linking (Phase 0.2) is inherently manual.
 */

/**
 * مستخدم حقيقي بلا منح: كائن عابر، لا يمسّ جداول الإرث.
 * A real-looking ungranted user: a transient object that writes nothing to legacy tables.
 */
function myStatementUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999997);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

/**
 * مدير نظام حقيقي من الجدول الحي — يتجاوز حرس الصلاحيات عبر PermissionResolver.
 * A live system admin read from the real table — it bypasses the gate via PermissionResolver.
 */
function myStatementAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب اتصال oracle الحياً: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

test('personal statements refuse an unauthenticated caller', function () {
    // المساران خلف `auth:sanctum` → 401 قبل أي منطق عمل.
    // Both routes sit behind auth:sanctum, so a bare request is refused with 401.
    $this->getJson('/api/my-statement/financial')->assertUnauthorized();
    $this->getJson('/api/my-statement/work')->assertUnauthorized();
});

test('financial statement requires its own permission', function () {
    Sanctum::actingAs(myStatementUngrantedUser(), ['*']);

    // صلاحية مستقلة `my_statement.financial` — لا تكفي صلاحية الكشوف العامة.
    // The grant is independent: a general statement permission does not cover it.
    $this->getJson('/api/my-statement/financial')
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['my_statement.financial']);
});

test('work statement requires its own permission', function () {
    Sanctum::actingAs(myStatementUngrantedUser(), ['*']);

    $this->getJson('/api/my-statement/work')
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['my_statement.work']);
});

test('financial statement answers 422 when no account is linked', function () {
    $admin = myStatementAdminUser();
    Sanctum::actingAs($admin, ['*']);

    $accNo = $admin->getAttribute('acc_no') ?? $admin->getAttribute('ACC_NO');

    if ($accNo) {
        test()->markTestSkipped('المدير الحالي مرتبط بحساب مالي — لا يمكن اختبار حالة absence.');

        return;
    }

    // لا يُقبل أي بارامتر معرّف من العميل: المستخدم بلا ACC_NO يسمع رسالة الربط.
    // No identifier is accepted from the client; an admin with no ACC_NO gets the linking hint.
    $this->getJson('/api/my-statement/financial')
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('financial statement returns the shared envelope when an account is linked', function () {
    $withAcc = User::query()
        ->whereNotNull('ACC_NO')
        ->where('IS_ADMIN', 1)
        ->first();

    if (! $withAcc) {
        test()->markTestSkipped('لا مستخدم مرتبط بـ ACC_NO — تعبئة المرحلة 0.2 لم تُنفّذ بعد.');

        return;
    }

    Sanctum::actingAs($withAcc, ['*']);

    $this->getJson('/api/my-statement/financial')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => ['account' => ['acc_no', 'acc_name'], 'transactions', 'summary'],
            'meta' => ['total', 'page', 'limit'],
        ]);
});

test('work statement auto-detects tailor or cutter for a linked worker', function () {
    $withAcc = User::query()
        ->whereNotNull('ACC_NO')
        ->where('IS_ADMIN', 1)
        ->first();

    if (! $withAcc) {
        test()->markTestSkipped('لا مستخدم مرتبط بـ ACC_NO — لا يمكن التحقق من كشف العمل.');

        return;
    }

    Sanctum::actingAs($withAcc, ['*']);

    $response = $this->getJson('/api/my-statement/work');

    // إمّا سجل عمل مرتبط (200 بترويسة نوع)، أو لا سجل → 422. الاثنان صحيحان حسب البيانات.
    // Either a linked work record (200 with a type header) or none at all (422). Both are
    // legitimate depending on the legacy data; the shape is asserted only on success.
    if ($response->getStatusCode() === 200) {
        $response
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'worker' => ['name', 'acc_no', 'type', 'type_label'],
                'data',
                'summary' => ['total_rows', 'total_count', 'total_amount', 'currency'],
            ]);

        expect(['tailors', 'cutters'])->toContain($response->json('worker.type'));
    } else {
        $response->assertStatus(422)->assertJsonPath('success', false);
    }
});
