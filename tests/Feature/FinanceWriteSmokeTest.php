<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * فحص كتابة اختياري لقسم القيود والسندات — كل شيء داخل معاملة خارجية تُلغى في النهاية.
 * Opt-in write smoke for entries & vouchers — everything runs inside an outer transaction that is
 * rolled back at the end, so no row survives. Enable with FINANCE_WRITE_SMOKE=1.
 *
 * المعاملات الداخلية في المتحكمات تصبح نقاط حفظ (savepoints) فلا تُثبَّت شيئاً.
 * The controllers' inner transactions become savepoints, so nothing is committed.
 */
beforeEach(function () {
    if (getenv('FINANCE_WRITE_SMOKE') !== '1') {
        $this->markTestSkipped('فحص الكتابة معطّل — FINANCE_WRITE_SMOKE=1 لتفعيله.');
    }

    DB::connection('oracle')->beginTransaction();
});

afterEach(function () {
    if (DB::connection('oracle')->transactionLevel() > 0) {
        DB::connection('oracle')->rollBack(0);
    }
});

function financeSmokeAdmin(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('لا يوجد مستخدم مدير.');
    }

    return $user;
}

/** @return array{0: int, 1: int} صندوق محلي وحساب محلي آخر / a local cash box and another local account */
function financeSmokeAccounts(): array
{
    $rows = DB::connection('oracle')->select(
        "SELECT ACC_NO FROM (SELECT ACC_NO FROM ACCOUNTS.CHART_ACC
          WHERE NVL(STOPPING_ACC,0) <> 1 AND SUBSTR(ACC_NO,4,1) = '1' AND ACC_NO BETWEEN 12110000 AND 12119999
          ORDER BY ACC_NO) WHERE ROWNUM <= 2"
    );

    if (count($rows) < 2) {
        test()->markTestSkipped('لا حسابان محليان للفحص.');
    }

    return [(int) reset($rows[0]), (int) reset($rows[1])];
}

test('a temporary entry is created, edited, posted and the posted entry deleted', function () {
    Sanctum::actingAs(financeSmokeAdmin(), ['*']);
    [$a, $b] = financeSmokeAccounts();
    $lines = [['acc_no' => $a, 'debit' => 10, 'sub_details' => 'فحص'], ['acc_no' => $b, 'credit' => 10]];

    $entryNo = $this->postJson('/api/finance/temp-entries', ['date' => date('Y-m-d'), 'details' => 'قيد فحص', 'lines' => $lines])
        ->assertCreated()->json('data.entry_no');

    $this->putJson("/api/finance/temp-entries/{$entryNo}", ['date' => date('Y-m-d'), 'details' => 'قيد فحص معدل', 'periodic' => true, 'lines' => $lines])
        ->assertOk();

    $temp = $this->getJson("/api/finance/temp-entries/{$entryNo}")->assertOk()->json('data');
    expect($temp['details'])->toBe('قيد فحص معدل')->and($temp['lines'])->toHaveCount(2);

    $this->postJson("/api/finance/temp-entries/{$entryNo}/post")->assertOk();
    $posted = $this->getJson("/api/finance/entries/{$entryNo}")->assertOk()->json('data');
    expect($posted['type_no'])->toBe(1)->and($posted['editable'])->toBeTrue()
        ->and($posted['audit']['created_by_name'])->not->toBeNull();

    $this->deleteJson("/api/finance/entries/{$entryNo}")->assertOk();
    $this->getJson("/api/finance/entries/{$entryNo}")->assertNotFound();
});

test('a closing entry is saved posted with type 100', function () {
    Sanctum::actingAs(financeSmokeAdmin(), ['*']);
    [$a, $b] = financeSmokeAccounts();

    $res = $this->postJson('/api/finance/entries', [
        'date' => date('Y-m-d'), 'details' => 'قيد إقفال فحص', 'kind' => 'closing',
        'lines' => [['acc_no' => $a, 'debit' => 5], ['acc_no' => $b, 'credit' => 5]],
    ])->assertCreated();

    expect($res->json('data.type_no'))->toBe(100);
});

test('a cash receipt and a multi cash payment are written with their entries', function () {
    Sanctum::actingAs(financeSmokeAdmin(), ['*']);
    [$box, $other] = financeSmokeAccounts();

    $receipt = $this->postJson('/api/finance/receipts', [
        'source' => 'cash', 'date' => date('Y-m-d'), 'details' => 'سند قبض فحص', 'box_acc_no' => $box,
        'lines' => [['acc_no' => $other, 'amount' => 1500]],
    ])->assertCreated()->json('data');

    $view = $this->getJson("/api/finance/receipts/cash/{$receipt['no']}/{$receipt['year']}")->assertOk()->json('data');
    expect($view['amount'])->toEqual(1500)
        ->and($view['amount_words'])->toContain('ألف')
        ->and($view['entry']['lines'])->toHaveCount(2)
        ->and($view['editable'])->toBeTrue();

    $payment = $this->postJson('/api/finance/payments', [
        'source' => 'cash', 'date' => date('Y-m-d'), 'details' => 'سند صرف متعدد فحص', 'box_acc_no' => $box,
        'lines' => [['acc_no' => $other, 'amount' => 100, 'sub_details' => 'أ'], ['acc_no' => $other, 'amount' => 200, 'sub_details' => 'ب']],
    ])->assertCreated()->json('data');

    $multi = $this->getJson("/api/finance/payments/cash/{$payment['no']}/{$payment['year']}")->assertOk()->json('data');
    expect($multi['is_multi'])->toBeTrue()->and($multi['amount'])->toEqual(300)
        ->and($multi['voucher_lines'])->toHaveCount(2);

    $this->putJson("/api/finance/payments/cash/{$payment['no']}/{$payment['year']}", [
        'source' => 'cash', 'date' => date('Y-m-d'), 'details' => 'معدل', 'box_acc_no' => $box,
        'lines' => [['acc_no' => $other, 'amount' => 250]],
    ])->assertOk();

    $this->deleteJson("/api/finance/payments/cash/{$payment['no']}/{$payment['year']}")->assertOk();
    $this->deleteJson("/api/finance/receipts/cash/{$receipt['no']}/{$receipt['year']}")->assertOk();
});
