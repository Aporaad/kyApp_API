<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * حرس انحدار: الكشوفات تُعيد **كل** حركات الفترة المختارة، لا أول 50 سطراً.
 * Regression guard: statements return EVERY movement of the period, never the
 * first 50 rows.
 *
 * كان `limit` الافتراضي 50 في متحكمَي الكشف المالي، فكانت الفترة المختارة تبدو
 * كأنها مُتجاهَلة بعد الحركة الخمسين. هذه الاختبارات تثبّت العقد الجديد:
 *   - غياب `limit` (أو limit=0) ⇒ كل الصفوف في الفترة.
 *   - قيمة موجبة ⇒ ترقيم صريح فقط عند الطلب.
 * The old default of 50 rows made a selected period look ignored after the 50th
 * movement. These tests pin the new contract: absent/0 limit returns every row;
 * a positive limit pages only when asked.
 */
function statementFullPeriodAdmin(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب اتصال oracle الحياً: لا يوجد مستخدم مدير.');
    }

    return $user;
}

/**
 * حساب حقيقي بحركات كثيرة — الأكثر حركةً في ENTRY_SUB.
 * A real account with many movements — the busiest one in ENTRY_SUB.
 */
function statementBusyAccountNo(): int
{
    try {
        $row = DB::connection('oracle')->selectOne(
            'SELECT ACC_NO FROM (
                 SELECT ACC_NO FROM ACCOUNTS.ENTRY_SUB GROUP BY ACC_NO ORDER BY COUNT(*) DESC
             ) WHERE ROWNUM = 1'
        );
    } catch (Throwable $e) {
        test()->markTestSkipped('لا اتصال بأوراكل: '.$e->getMessage());
    }

    $arr = $row ? (array) $row : [];
    $accNo = (int) ($arr['ACC_NO'] ?? $arr['acc_no'] ?? 0);

    if ($accNo === 0) {
        test()->markTestSkipped('لا توجد حركات في ENTRY_SUB لاختبار الكشف.');
    }

    return $accNo;
}

// فترة مفتوحة على السجل كله — الافتراضي (30 يوماً) يخفي الحجم الحقيقي.
// A wide-open period — the 30-day default would hide the real row count.
const STATEMENT_FULL_PERIOD = 'from_date=01/01/2000&to_date=31/12/2099';

test('account statement returns every movement of the period when limit is absent', function () {
    $accNo = statementBusyAccountNo();
    Sanctum::actingAs(statementFullPeriodAdmin(), ['*']);

    $response = $this->getJson("/api/accounts/chart/{$accNo}/statement?".STATEMENT_FULL_PERIOD);

    $response->assertOk()->assertJsonPath('success', true);

    $total = $response->json('meta.total');
    $returned = $response->json('meta.returned');

    // لا سقف افتراضي: المُعاد = الإجمالي تماماً، وهذا ما كان يفشل عند 50 سطراً.
    // No implicit cap: returned === total, which is exactly what failed at 50 rows.
    expect($response->json('meta.limit'))->toBe(0);
    expect($total)->toBeGreaterThan(0);
    expect($returned)->toBe($total);
    expect(count($response->json('data.transactions')))->toBe($total);
    expect($response->json('meta.has_more'))->toBeFalse();
});

test('account statement pages only when a positive limit is explicit', function () {
    $accNo = statementBusyAccountNo();
    Sanctum::actingAs(statementFullPeriodAdmin(), ['*']);

    $response = $this->getJson("/api/accounts/chart/{$accNo}/statement?".STATEMENT_FULL_PERIOD.'&page=1&limit=25');

    $response->assertOk();

    $total = $response->json('meta.total');

    expect($response->json('meta.limit'))->toBe(25);
    expect(count($response->json('data.transactions')))->toBe(min(25, $total));
    expect($response->json('meta.has_more'))->toBe($total > 25);
});

test('personal financial statement returns every movement when limit is absent', function () {
    $accNo = statementBusyAccountNo();

    // المدير يتجاوز جدول المنح، وACC_NO يُسنَد في الذاكرة فقط — لا كتابة في قاعدة البيانات.
    // The admin bypasses the grant table; ACC_NO is assigned in-memory only — no DB write.
    $user = statementFullPeriodAdmin();
    $user->ACC_NO = $accNo;

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/my-statement/financial?'.STATEMENT_FULL_PERIOD);

    $response->assertOk()->assertJsonPath('success', true);

    $total = $response->json('meta.total');

    expect($response->json('meta.limit'))->toBe(0);
    expect($response->json('meta.returned'))->toBe($total);
    expect(count($response->json('data.transactions')))->toBe($total);
    expect($response->json('meta.has_more'))->toBeFalse();
});
