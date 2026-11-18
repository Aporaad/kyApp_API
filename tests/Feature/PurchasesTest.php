<?php

use App\Models\User;
use App\Support\PurchasePolicy;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات قسم المشتريات — القراءة والرفض والتحقق
 * Purchases module tests — reads, refusals and validation.
 *
 * ── كل شيء هنا قراءة أو رفض قبل الكتابة، إلا اختبار ترحيل واحد يجري داخل معاملة تُتراجَع.
 *    لا يُنشأ صف مالي حقيقي في جداول الإرث.
 * ── Everything here reads or is refused before writing, except one posting test that runs inside
 *    a rolled-back transaction. No real financial row is created in the legacy tables.
 *
 * ── يتطلب اتصال `oracle` حياً لأن المدير يُقرأ من `APP_USERS`.
 * ── Needs a live Oracle connection because the admin is read from APP_USERS.
 */
function purchasesAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

function purchasesUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999998);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('purchase routes refuse an unauthenticated caller', function () {
    $samples = [
        ['get', '/api/purchases/reference'],
        ['get', '/api/purchases/items'],
        ['get', '/api/purchases/cash-accounts'],
        ['get', '/api/purchases/invoices'],
        ['get', '/api/purchases/invoices/1/2026'],
        ['post', '/api/purchases/invoices'],
        ['put', '/api/purchases/invoices/1/2026'],
        ['delete', '/api/purchases/invoices/1/2026'],
        ['get', '/api/purchases/invoices/1/2026/returnable'],
        ['get', '/api/purchases/returns'],
        ['post', '/api/purchases/returns'],
        ['get', '/api/purchases/reports'],
        ['get', '/api/purchases/suppliers'],
        ['get', '/api/purchases/suppliers/1'],
        ['get', '/api/purchases/suppliers/1/statement'],
        ['post', '/api/purchases/suppliers'],
    ];

    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused with the named purchase permission', function () {
    Sanctum::actingAs(purchasesUngrantedUser(), ['*']);

    $cases = [
        ['get', '/api/purchases/invoices', 'purchases.view'],
        ['post', '/api/purchases/invoices', 'purchases.create'],
        ['put', '/api/purchases/invoices/1/2026', 'purchases.update'],
        ['delete', '/api/purchases/invoices/1/2026', 'purchases.delete'],
        ['get', '/api/purchases/returns', 'purchases.returns_view'],
        ['post', '/api/purchases/returns', 'purchases.returns_create'],
        ['get', '/api/purchases/reports', 'purchases.reports_view'],
        ['get', '/api/purchases/suppliers', 'purchases.suppliers_view'],
        ['get', '/api/purchases/suppliers/1/statement', 'purchases.suppliers_statement'],
        ['post', '/api/purchases/suppliers', 'purchases.suppliers_create'],
    ];

    foreach ($cases as [$verb, $path, $code]) {
        $this->json($verb, $path)->assertForbidden()->assertJsonPath('errors.permission', [$code]);
    }
});

test('the administrator reads every purchase surface', function () {
    Sanctum::actingAs(purchasesAdminUser(), ['*']);

    $this->getJson('/api/purchases/reference')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/purchases/cash-accounts')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/purchases/items?limit=3')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/purchases/invoices?limit=2')->assertOk()
        ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['total', 'page', 'limit', 'pages']]);
    $this->getJson('/api/purchases/invoices/filters')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/purchases/returns?limit=2')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/purchases/returns/filters')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/purchases/reports')->assertOk()
        ->assertJsonStructure(['data' => ['summary', 'monthly', 'suppliers', 'types', 'currencies', 'top_items', 'documents']]);
    $this->getJson('/api/purchases/suppliers?limit=2')->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'limit', 'pages']]);
    $this->getJson('/api/purchases/suppliers/options')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/purchases/suppliers/filters')->assertOk()->assertJsonPath('success', true);
});

test('one invoice and one supplier can be read by key', function () {
    Sanctum::actingAs(purchasesAdminUser(), ['*']);

    $bill = DB::connection('oracle')->selectOne(
        'SELECT * FROM (SELECT BILL_NO, YEAR FROM ACCOUNTS.BUY_BILL ORDER BY YEAR DESC, BILL_NO DESC) WHERE ROWNUM = 1'
    );

    if ($bill === null) {
        test()->markTestSkipped('لا توجد فواتير مشتريات في القاعدة.');
    }

    $bill = array_change_key_case((array) $bill, CASE_UPPER);

    $this->getJson('/api/purchases/invoices/'.$bill['BILL_NO'].'/'.$bill['YEAR'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.bill_no', (int) $bill['BILL_NO'])
        ->assertJsonStructure(['data' => ['lines', 'totals', 'returns']]);

    $this->getJson('/api/purchases/invoices/'.$bill['BILL_NO'].'/'.$bill['YEAR'].'/returnable')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['lines', 'store_is_stock']]);

    $supplier = DB::connection('oracle')->selectOne('SELECT PROV_NO FROM ACCOUNTS.PROV WHERE ROWNUM = 1');

    if ($supplier !== null) {
        $provNo = (int) (array_change_key_case((array) $supplier, CASE_UPPER)['PROV_NO'] ?? 0);

        $this->getJson('/api/purchases/suppliers/'.$provNo)->assertOk()->assertJsonPath('success', true);
        $this->getJson('/api/purchases/suppliers/'.$provNo.'/statement')->assertOk()
            ->assertJsonStructure(['data' => ['supplier', 'rows', 'closing_balance', 'shared_account']]);
    }
});

test('purchase writes refuse invalid payloads before touching the database', function () {
    Sanctum::actingAs(purchasesAdminUser(), ['*']);

    // نوع فاتورة غير مدعوم / unsupported bill type
    $this->postJson('/api/purchases/invoices', ['bill_type' => 3])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    // فاتورة بلا سطور / an invoice without lines
    $this->postJson('/api/purchases/invoices', [
        'bill_type' => 1,
        'date' => '2026-01-01',
        'store_no' => 11,
        'acc_no1' => 12210001,
        'lines' => [],
    ])->assertStatus(422);

    // مخزن غير مخزوني (11) لفاتورة جديدة / a non-stock store for a new invoice
    $this->postJson('/api/purchases/invoices', [
        'bill_type' => 1,
        'date' => '2026-01-01',
        'store_no' => 11,
        'acc_no1' => 12210001,
        'lines' => [['sanf_no' => 1, 'sanf_dno' => 1, 'qty' => 1, 'price' => 1]],
    ])->assertStatus(422);

    // فاتورة آجلة بحساب صندوق / a credit invoice on a cash account
    $this->postJson('/api/purchases/invoices', [
        'bill_type' => 2,
        'date' => '2026-01-01',
        'store_no' => 1,
        'acc_no1' => 12210001,
        'lines' => [['sanf_no' => 1, 'sanf_dno' => 1, 'qty' => 1, 'price' => 1]],
    ])->assertStatus(422);

    // مردود بلا فاتورة أصل / a return without its source invoice
    $this->postJson('/api/purchases/returns', [
        'bill_no' => 999999,
        'bill_year' => 2026,
        'date' => '2026-01-01',
        'lines' => [['sanf_no' => 1, 'sanf_dno' => 1, 'cap' => 1, 'qty' => 1, 'price' => 1]],
    ])->assertStatus(422);

    // مردود يتجاوز المتاح: يُرفض بالتحقق قبل أي إدراج
    // A return above what remains is refused by validation before any insert.
    $bill = DB::connection('oracle')->selectOne(
        'SELECT * FROM (SELECT BILL_NO, YEAR FROM ACCOUNTS.BUY_BILL ORDER BY YEAR DESC, BILL_NO DESC) WHERE ROWNUM = 1'
    );

    if ($bill !== null) {
        $bill = array_change_key_case((array) $bill, CASE_UPPER);
        $line = DB::connection('oracle')->selectOne(
            'SELECT SANF_NO, SANF_DNO, CAP FROM ACCOUNTS.BUY_DETAILES WHERE BILL_NO = :b AND YEAR = :y AND ROWNUM = 1',
            ['b' => (int) $bill['BILL_NO'], 'y' => (int) $bill['YEAR']]
        );

        if ($line !== null) {
            $line = array_change_key_case((array) $line, CASE_UPPER);

            $this->postJson('/api/purchases/returns', [
                'bill_no' => (int) $bill['BILL_NO'],
                'bill_year' => (int) $bill['YEAR'],
                'date' => '2026-01-01',
                'lines' => [[
                    'sanf_no' => (int) $line['SANF_NO'],
                    'sanf_dno' => (int) $line['SANF_DNO'],
                    'cap' => (float) $line['CAP'],
                    'qty' => 999999,
                    'price' => 1,
                ]],
            ])->assertStatus(422);
        }
    }
});

test('deleting an invoice that has a return is refused', function () {
    Sanctum::actingAs(purchasesAdminUser(), ['*']);

    $row = DB::connection('oracle')->selectOne(
        'SELECT * FROM (SELECT BILL_NO, BILL_YEAR AS YEAR FROM ACCOUNTS.RETURN_BUY WHERE BILL_NO IS NOT NULL ORDER BY YEAR DESC) WHERE ROWNUM = 1'
    );

    if ($row === null) {
        test()->markTestSkipped('لا يوجد مردود مرتبط بفاتورة في هذه القاعدة.');
    }

    $row = array_change_key_case((array) $row, CASE_UPPER);

    $this->deleteJson('/api/purchases/invoices/'.$row['BILL_NO'].'/'.$row['YEAR'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('posting an invoice produces a balanced entry inside a rolled-back transaction', function () {
    Sanctum::actingAs(purchasesAdminUser(), ['*']);

    $store = DB::connection('oracle')->selectOne(
        'SELECT STORE_NO FROM ACCOUNTS.STORES WHERE STORE_NO NOT IN (11, 12, 2) AND ROWNUM = 1'
    );
    $line = DB::connection('oracle')->selectOne(
        'SELECT * FROM (
            SELECT d.SANF_NO, d.SANF_DNO, s.SANF_PRICE
              FROM ACCOUNTS.BUY_DETAILES d
              JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
             WHERE d.SANF_DNO IS NOT NULL
             ORDER BY d.YEAR DESC
         ) WHERE ROWNUM = 1'
    );
    $supplier = DB::connection('oracle')->selectOne('SELECT PROV_NO, ACC_NO FROM ACCOUNTS.PROV WHERE ACC_NO IS NOT NULL AND ROWNUM = 1');

    if ($store === null || $line === null || $supplier === null) {
        test()->markTestSkipped('بيانات كافية غير متوفرة لاختبار الترحيل.');
    }

    $store = array_change_key_case((array) $store, CASE_UPPER);
    $line = array_change_key_case((array) $line, CASE_UPPER);
    $supplier = array_change_key_case((array) $supplier, CASE_UPPER);

    $before = (int) (array_change_key_case((array) DB::connection('oracle')->selectOne('SELECT COUNT(*) AS C FROM ACCOUNTS.ENTRY_MAIN'), CASE_UPPER)['C'] ?? 0);

    DB::connection('oracle')->beginTransaction();

    try {
        $response = $this->postJson('/api/purchases/invoices', [
            'bill_type' => PurchasePolicy::BILL_CREDIT,
            'date' => '2026-01-05',
            'store_no' => (int) $store['STORE_NO'],
            'prov_no' => (int) $supplier['PROV_NO'],
            'acc_no1' => (int) $supplier['ACC_NO'],
            'cur_price' => 140,
            'lines' => [[
                'sanf_no' => (int) $line['SANF_NO'],
                'sanf_dno' => (int) $line['SANF_DNO'],
                'qty' => 2,
                'price' => 10,
            ]],
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $entryNo = (int) $response->json('data.entry_no');
        expect($entryNo)->toBeGreaterThan(0);

        $head = array_change_key_case((array) DB::connection('oracle')->selectOne(
            'SELECT * FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :e',
            ['e' => $entryNo]
        ), CASE_UPPER);

        expect((int) $head['ENTRY_TYPE_NO'])->toBe(PurchasePolicy::ENTRY_TYPE_INVOICE);
        expect((int) strpos((string) $head['ENTRY_NO'], (string) PurchasePolicy::ENTRY_PREFIX_INVOICE))->toBe(0);

        $lines = DB::connection('oracle')->select(
            'SELECT ACC_NO, ENTRY_MNT, SEQ_PRICE FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :e ORDER BY SEQ',
            ['e' => $entryNo]
        );

        expect($lines)->toHaveCount(2);

        $billRow = array_change_key_case((array) DB::connection('oracle')->selectOne(
            'SELECT NET, CUR_PRICE1 FROM ACCOUNTS.BUY_BILL WHERE BILL_NO = :b AND YEAR = :y',
            ['b' => (int) $response->json('data.bill_no'), 'y' => (int) $response->json('data.year')]
        ), CASE_UPPER);

        $net = (float) ($billRow['NET'] ?? 0);
        $rate = (float) ($billRow['CUR_PRICE1'] ?? 1);

        $debitRow = array_change_key_case((array) $lines[0], CASE_UPPER);
        $creditRow = array_change_key_case((array) $lines[1], CASE_UPPER);

        $debit = (float) ($debitRow['ENTRY_MNT'] ?? 0);
        $credit = (float) ($creditRow['ENTRY_MNT'] ?? 0);

        // مدين بالريال = الصافي × سعر الصرف، ودائن بعملة المورد = −الصافي، والطرفان متوازنان
        // The debit is the riyal equivalent (net × rate); the credit is −net in the party currency.
        expect(round($debit, 2))->toBe(round($net * $rate, 2));
        expect(round($credit, 2))->toBe(round(-$net, 2));
        expect(round($debit + $credit * $rate, 2))->toBe(0.0);
    } finally {
        DB::connection('oracle')->rollBack();
    }

    // التراجع يُعيد العدد كما كان / the rollback restores the original count
    $after = (int) (array_change_key_case((array) DB::connection('oracle')->selectOne('SELECT COUNT(*) AS C FROM ACCOUNTS.ENTRY_MAIN'), CASE_UPPER)['C'] ?? 0);

    expect($after)->toBe($before);
});
