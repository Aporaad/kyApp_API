<?php

use App\Models\User;
use App\Support\Finance\ArabicTafkeet;
use App\Support\Finance\EntryLedger;
use App\Support\Finance\FinancePolicy;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات قسم القيود والسندات — قراءة فقط على قاعدة أوراكل الحيّة.
 * Entries & vouchers tests — read-only over the live Oracle database.
 *
 * لا حالة هنا تكتب صفاً: حالات الرفض تُرفض قبل أي كتابة (الحارس يسبق المعاملة)، والقيد غير
 * المتوازن يُرفض في التحقق. تُتخطّى الاختبارات الحيّة بلا قاعدة.
 * No case writes a row: refusals happen before any write (the guard precedes the transaction),
 * and the unbalanced entry is refused in validation. Live cases skip without a database.
 */
function financeAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');
    }

    return $user;
}

function financeUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('the finance permissions are part of the catalog', function () {
    // 240 = 217 + 23 للمشتريات (22 فعلاً + صف منح عام) / 240 = 217 plus 23 purchases rows
    expect(PermissionCatalog::count())->toBe(240);

    foreach ([
        'journal.view', 'journal.create', 'journal.update', 'journal.delete', 'journal.closing_create',
        'journal.print', 'journal.export',
        'journal_temp.view', 'journal_temp.create', 'journal_temp.update', 'journal_temp.delete',
        'journal_temp.post', 'journal_temp.print', 'journal_temp.export',
        'receipts.view', 'receipts.create', 'receipts.update', 'receipts.delete', 'receipts.print', 'receipts.export',
        'payments.view', 'payments.create', 'payments.update', 'payments.delete', 'payments.print', 'payments.export',
        'fin_search.view', 'wages.view', 'wages.calculate',
    ] as $code) {
        expect(PermissionCatalog::knows($code))->toBeTrue("{$code} is missing from the catalog");
    }
});

test('the finance routes demand a session', function () {
    $paths = [
        ['get', '/api/finance/reference'],
        ['get', '/api/finance/accounts'],
        ['get', '/api/finance/customers'],
        ['post', '/api/finance/activity'],
        ['get', '/api/finance/search?q=1'],
        ['get', '/api/finance/entries'],
        ['post', '/api/finance/entries'],
        ['get', '/api/finance/entries/1'],
        ['put', '/api/finance/entries/1'],
        ['delete', '/api/finance/entries/1'],
        ['post', '/api/finance/entries/1/activity'],
        ['get', '/api/finance/temp-entries'],
        ['post', '/api/finance/temp-entries'],
        ['get', '/api/finance/temp-entries/1'],
        ['put', '/api/finance/temp-entries/1'],
        ['delete', '/api/finance/temp-entries/1'],
        ['post', '/api/finance/temp-entries/1/post'],
        ['post', '/api/finance/temp-entries/1/activity'],
        ['get', '/api/finance/receipts'],
        ['post', '/api/finance/receipts'],
        ['get', '/api/finance/receipts/cash/1/2026'],
        ['put', '/api/finance/receipts/cash/1/2026'],
        ['delete', '/api/finance/receipts/cash/1/2026'],
        ['post', '/api/finance/receipts/cash/1/2026/activity'],
        ['get', '/api/finance/payments'],
        ['post', '/api/finance/payments'],
        ['get', '/api/finance/payments/bank/1/2026'],
        ['put', '/api/finance/payments/bank/1/2026'],
        ['delete', '/api/finance/payments/bank/1/2026'],
        ['post', '/api/finance/payments/bank/1/2026/activity'],
    ];

    foreach ($paths as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused on every finance route', function () {
    Sanctum::actingAs(financeUngrantedUser(), ['*']);

    $cases = [
        ['get', '/api/finance/search?q=1', ['fin_search.view']],
        ['get', '/api/finance/entries', ['journal.view']],
        ['post', '/api/finance/entries', ['journal.create', 'journal.closing_create']],
        ['get', '/api/finance/entries/1', ['journal.view']],
        ['put', '/api/finance/entries/1', ['journal.update']],
        ['delete', '/api/finance/entries/1', ['journal.delete']],
        ['post', '/api/finance/entries/1/activity', ['journal.print', 'journal.export']],
        ['get', '/api/finance/temp-entries', ['journal_temp.view']],
        ['post', '/api/finance/temp-entries', ['journal_temp.create']],
        ['put', '/api/finance/temp-entries/1', ['journal_temp.update']],
        ['delete', '/api/finance/temp-entries/1', ['journal_temp.delete']],
        ['post', '/api/finance/temp-entries/1/post', ['journal_temp.post']],
        ['get', '/api/finance/receipts', ['receipts.view']],
        ['post', '/api/finance/receipts', ['receipts.create']],
        ['put', '/api/finance/receipts/cash/1/2026', ['receipts.update']],
        ['delete', '/api/finance/receipts/cash/1/2026', ['receipts.delete']],
        ['get', '/api/finance/payments', ['payments.view']],
        ['post', '/api/finance/payments', ['payments.create']],
        ['put', '/api/finance/payments/bank/1/2026', ['payments.update']],
        ['delete', '/api/finance/payments/bank/1/2026', ['payments.delete']],
    ];

    foreach ($cases as [$verb, $path, $expected]) {
        $this->json($verb, $path)
            ->assertForbidden()
            ->assertJsonPath('errors.permission', $expected);
    }

    // المراجع والبحث عن حساب يكفيهما أيّ كود عرض/كتابة — لكن غير الممنوح يُرفض أيضاً
    // The references and lookups accept any view/write code — the ungranted caller is still refused.
    $this->getJson('/api/finance/reference')->assertForbidden();
    $this->getJson('/api/finance/accounts')->assertForbidden();
    $this->postJson('/api/finance/activity', ['module' => 'journal', 'action' => 'print'])->assertForbidden();
});

test('the finance policy derives currency, box kind and counters from the number', function () {
    expect(FinancePolicy::currencyOf(12110001))->toBe(1)
        ->and(FinancePolicy::currencyOf(12130001))->toBe(3)
        ->and(FinancePolicy::currencyOf(12220001))->toBe(2)
        ->and(FinancePolicy::currencyOf(12))->toBe(1)
        ->and(FinancePolicy::boxKind(12110001))->toBe('cash')
        ->and(FinancePolicy::boxKind(12210001))->toBe('bank')
        ->and(FinancePolicy::boxKind(31110001))->toBeNull()
        ->and(FinancePolicy::counterAfter(0, 426))->toBe(4260000001)
        ->and(FinancePolicy::counterAfter(4260000041, 426))->toBe(4260000042)
        ->and(FinancePolicy::isEditableType(1))->toBeTrue()
        ->and(FinancePolicy::isEditableType(100))->toBeTrue()
        ->and(FinancePolicy::isEditableType(2))->toBeFalse()
        ->and(FinancePolicy::isEditableType(null))->toBeFalse()
        ->and(FinancePolicy::voucherOfType(3))->toBe(['payment', 'cash'])
        ->and(FinancePolicy::voucherOfType(1))->toBeNull();
});

test('the server tafkeet spells fixed amounts exactly like the client', function () {
    expect(ArabicTafkeet::amount(0))->toBe('صفر ريال يمني')
        ->and(ArabicTafkeet::amount(1000))->toBe('ألف ريال يمني')
        ->and(ArabicTafkeet::amount(2500))->toBe('ألفان و خمسمائة ريال يمني')
        ->and(ArabicTafkeet::forCurrency(150, 2))->toContain('دولار أمريكي');
});

test('the next journal number spans the posted and temporary tables', function () {
    financeAdminUser();
    $year = (int) date('Y');
    $head = (int) sprintf('4%02d', $year % 100);
    $lo = $head * 10000000;

    $max = (array) DB::connection('oracle')->selectOne(
        'SELECT GREATEST(
                    NVL((SELECT MAX(ENTRY_NO) FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO BETWEEN :a AND :b), 0),
                    NVL((SELECT MAX(ENTRY_NO) FROM ACCOUNTS.ENTRY_MAIN2 WHERE ENTRY_NO BETWEEN :c AND :d), 0)) AS N FROM DUAL',
        ['a' => $lo, 'b' => $lo + 9999999, 'c' => $lo, 'd' => $lo + 9999999]
    );

    $next = FinancePolicy::nextJournalNo($year);
    expect($next)->toBeGreaterThan((int) reset($max))
        ->and(intdiv($next, 10000000))->toBe($head);
});

test('an unbalanced entry is refused before any write', function () {
    financeAdminUser();
    $box = (array) DB::connection('oracle')->selectOne(
        'SELECT ACC_NO FROM ACCOUNTS.CHART_ACC WHERE ACC_NO BETWEEN 12110000 AND 12119999 AND NVL(STOPPING_ACC,0) <> 1 AND ROWNUM = 1'
    );

    if ($box === []) {
        test()->markTestSkipped('لا صندوق نقدي محلي في القاعدة الحيّة.');
    }

    $acc = (int) reset($box);
    $result = EntryLedger::normalizeLines([
        ['acc_no' => $acc, 'debit' => 100],
        ['acc_no' => $acc, 'credit' => 90],
    ]);

    expect($result['ok'])->toBeFalse()->and($result['errors'])->toHaveKey('balance');

    $both = EntryLedger::normalizeLines([
        ['acc_no' => $acc, 'debit' => 100, 'credit' => 100],
        ['acc_no' => $acc, 'credit' => 100],
    ]);
    expect($both['ok'])->toBeFalse();

    $single = EntryLedger::normalizeLines([['acc_no' => $acc, 'debit' => 100]]);
    expect($single['ok'])->toBeFalse()->and($single['errors'])->toHaveKey('lines');
});

test('the finance lists answer the shared envelope', function () {
    Sanctum::actingAs(financeAdminUser(), ['*']);

    foreach ([
        '/api/finance/entries?limit=3',
        '/api/finance/temp-entries?limit=3',
        '/api/finance/receipts?limit=3',
        '/api/finance/receipts?kind=multi&limit=3',
        '/api/finance/payments?kind=cash&limit=3',
        '/api/finance/payments?kind=bank&limit=3',
    ] as $path) {
        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['total', 'page', 'limit', 'pages']]);
    }

    $this->getJson('/api/finance/reference')
        ->assertOk()
        ->assertJsonStructure(['data' => ['entry_types', 'boxes', 'default_boxes', 'currencies', 'cheque_types', 'today']]);
});

test('a multi voucher has more than two entry lines', function () {
    Sanctum::actingAs(financeAdminUser(), ['*']);

    $rows = $this->getJson('/api/finance/payments?kind=multi&limit=1')->assertOk()->json('data');

    if ($rows === []) {
        test()->markTestSkipped('لا سندات صرف متعددة في القاعدة الحيّة.');
    }

    expect($rows[0]['is_multi'])->toBeTrue()
        ->and($rows[0]['lines_count'])->toBeGreaterThan(2);
});

test('a posted entry from another system cannot be edited or deleted', function () {
    Sanctum::actingAs(financeAdminUser(), ['*']);

    $row = (array) DB::connection('oracle')->selectOne(
        'SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_TYPE_NO NOT IN (1, 2, 3, 4, 5, 100) AND ROWNUM = 1'
    );

    if ($row === []) {
        test()->markTestSkipped('لا قيود من أنظمة أخرى في القاعدة الحيّة.');
    }

    $entryNo = (int) reset($row);
    $this->putJson("/api/finance/entries/{$entryNo}", ['date' => date('Y-m-d'), 'details' => 'x', 'lines' => []])
        ->assertStatus(422)->assertJsonPath('errors.entry', 'source');
    $this->deleteJson("/api/finance/entries/{$entryNo}")
        ->assertStatus(422)->assertJsonPath('errors.entry', 'source');

    $detail = $this->getJson("/api/finance/entries/{$entryNo}")->assertOk()->json('data');
    expect($detail['editable'])->toBeFalse()->and($detail['read_only_reason'])->toBe('source');
});

test('a voucher linked to an invoice cannot be edited', function () {
    Sanctum::actingAs(financeAdminUser(), ['*']);

    $row = (array) DB::connection('oracle')->selectOne(
        'SELECT C_NO, YEAR FROM ACCOUNTS.CASH_RECEIVE WHERE (BILL_NO IS NOT NULL OR INVOICE_NO IS NOT NULL) AND ROWNUM = 1'
    );

    if ($row === []) {
        test()->markTestSkipped('لا سندات قبض مرتبطة بفواتير.');
    }

    $row = array_change_key_case($row, CASE_UPPER);
    $this->deleteJson("/api/finance/receipts/cash/{$row['C_NO']}/{$row['YEAR']}")
        ->assertStatus(422)->assertJsonPath('errors.voucher', 'linked');
});

test('the financial search finds a voucher by its number', function () {
    Sanctum::actingAs(financeAdminUser(), ['*']);

    $this->getJson('/api/finance/search')->assertStatus(422);
    $this->getJson('/api/finance/search?q=abc&field=details')->assertStatus(422);

    $this->getJson('/api/finance/search?q=1&field=no')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data', 'meta' => ['total', 'scopes']]);
});
