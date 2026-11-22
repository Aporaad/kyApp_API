<?php

use App\Models\User;
use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات إنشاء فاتورة التفصيل — الرفض قبل أي كتابة، والكتابة داخل تراجع بمفتاح صريح
 * The cutting-bill creation tests — refusals before any write, and the write inside a
 * rollback keyed by an explicit switch.
 *
 * ── الفروع الآمنة دائماً (قراءة/رفض): 401 بلا جلسة، 403 بلا منح، 422 لحمولات ناقصة،
 *    409 لاسم عميل مكرر — كلها تعيد قبل أي INSERT في جداول الإرث.
 * ── Always-safe branches (reads/refusals): 401, 403, 422 and 409 — each returns before any
 *    legacy INSERT.
 *
 * ── اختبار الكتابة الحقيقي يُشغَّل فقط بـ TAILORING_CREATE_SMOKE=1 وداخل معاملة خارجية
 *    تُلغى في النهاية فلا يبقى أثر (نفس نمط FinanceWriteSmokeTest)، ويحتاج موافقة صريحة
 *    لأنه يلمس الجداول الحية.
 * ── The real write test runs only with TAILORING_CREATE_SMOKE=1 inside an outer transaction
 *    rolled back at the end (the FinanceWriteSmokeTest pattern) — explicit approval required.
 */
beforeEach(function () {
    if (getenv('TAILORING_CREATE_SMOKE') === '1') {
        DB::connection('oracle')->beginTransaction();
    }
});

afterEach(function () {
    if (DB::connection('oracle')->transactionLevel() > 0) {
        DB::connection('oracle')->rollBack(0);
    }
});

function tailoringCreateAdmin(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');
    }

    return $user;
}

test('every creation route demands a session', function () {
    $samples = [
        ['get', '/api/tailoring/create/reference'],
        ['get', '/api/tailoring/create/next-numbers'],
        ['get', '/api/tailoring/create/customers?search=x'],
        ['get', '/api/tailoring/create/customers/1/phones'],
        ['get', '/api/tailoring/create/customers/1/previous-models'],
        ['get', '/api/tailoring/create/workers'],
        ['get', '/api/tailoring/create/fabrics'],
        ['get', '/api/tailoring/create/fabrics/1/variants'],
        ['post', '/api/tailoring/create/quote'],
        ['post', '/api/tailoring/create/bills'],
    ];

    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused on every creation route with tailoring.create', function () {
    Sanctum::actingAs(tailoringUngrantedUser(), ['*']);

    $samples = [
        ['get', '/api/tailoring/create/reference'],
        ['get', '/api/tailoring/create/next-numbers'],
        ['get', '/api/tailoring/create/customers?search=x'],
        ['get', '/api/tailoring/create/customers/1/phones'],
        ['get', '/api/tailoring/create/customers/1/previous-models'],
        ['get', '/api/tailoring/create/workers'],
        ['get', '/api/tailoring/create/fabrics'],
        ['get', '/api/tailoring/create/fabrics/1/variants'],
        ['post', '/api/tailoring/create/quote'],
        ['post', '/api/tailoring/create/bills'],
    ];

    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)
            ->assertForbidden()
            ->assertJsonPath('errors.permission', ['tailoring.create']);
    }
});

test('the creation reference carries the dictionaries and the estimated numbers', function () {
    Sanctum::actingAs(tailoringCreateAdmin(), ['*']);

    $response = $this->getJson('/api/tailoring/create/reference');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'next', 'bill_kinds', 'bill_type', 'payment_methods', 'measure_labels',
                'model_families', 'specs', 'default_requests', 'default_measurements',
                'width_types', 'colors', 'accounts', 'limits',
            ],
        ]);

    $data = $response->json('data');

    // الفئة الوحيدة المقبولة في هذه المرحلة هي 1 عادية (القرار المعتمد)
    // The only accepted kind this phase is 1 (normal) — the approved decision.
    expect($data['bill_kinds'])->toHaveCount(1)
        ->and($data['bill_kinds'][0]['value'])->toBe(1)
        ->and($data['bill_type']['value'])->toBe(2)
        ->and($data['default_measurements'])->not->toBeEmpty()
        ->and(count($data['model_families']))->toBe(12)
        ->and($data['specs'])->toHaveKey('button_type');
});

test('a bill with missing required fields is refused 422 before any write', function () {
    Sanctum::actingAs(tailoringCreateAdmin(), ['*']);

    // بلا موديلات إطلاقاً / no models at all
    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 1],
        'finance' => ['paid' => 0, 'credit_account' => 41210001],
    ])->assertStatus(422);

    // بلا L1/L5 / no L1/L5
    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 1],
        'models' => [[
            'shape_style_no' => 1,
            'specs' => ['button_type' => 'طقطق'],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 1000]],
        ]],
        'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
    ])->assertStatus(422);

    // بلا نوع الزر (BUTTON_TYPE NOT NULL في القاعدة) / no button type
    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 1],
        'models' => [[
            'shape_style_no' => 1,
            'measurements' => ['l1' => 38, 'l5' => 14],
            'specs' => [],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 1000]],
        ]],
        'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
    ])->assertStatus(422);

    // سعر صفر مرفوض / a zero price is refused
    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 1],
        'models' => [[
            'shape_style_no' => 1,
            'measurements' => ['l1' => 38, 'l5' => 14],
            'specs' => ['button_type' => 'طقطق'],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 0]],
        ]],
        'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
    ])->assertStatus(422);

    // عربون صفر بلا عميل معروف وبلا عامل / zero deposit without a known customer or worker
    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 1],
        'models' => [[
            'shape_style_no' => 1,
            'measurements' => ['l1' => 38, 'l5' => 14],
            'specs' => ['button_type' => 'طقطق'],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 1000]],
        ]],
        'finance' => ['paid' => 0, 'credit_account' => 41210001],
    ])->assertStatus(422);

    // عربون > 0 بلا حساب صندوق / a deposit without a cash account
    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 1],
        'models' => [[
            'shape_style_no' => 1,
            'measurements' => ['l1' => 38, 'l5' => 14],
            'specs' => ['button_type' => 'طقطق'],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 1000]],
        ]],
        'finance' => ['paid' => 500, 'credit_account' => 41210001],
    ])->assertStatus(422);

    // فئة غير عادية مرفوضة في هذه المرحلة / a non-normal kind is refused this phase
    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 2],
        'models' => [[
            'shape_style_no' => 1,
            'measurements' => ['l1' => 38, 'l5' => 14],
            'specs' => ['button_type' => 'طقطق'],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 1000]],
        ]],
        'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
    ])->assertStatus(422);
});

test('a duplicated customer name is answered 409 with the existing row and nothing is written', function () {
    Sanctum::actingAs(tailoringCreateAdmin(), ['*']);

    // اسم عميل موجود فعلاً من القاعدة الحية / a name that exists on the live database
    $row = (array) DB::connection('oracle')->selectOne(
        'SELECT CUST_NO, CUST_NAME FROM ACCOUNTS.CUST WHERE ROWNUM <= 1'
    );

    if ($row === []) {
        $this->markTestSkipped('لا يوجد أي عميل على القاعدة الحية.');

        return;
    }

    $name = ArabicEncoding::fixMojibake(
        (string) ($row['CUST_NAME'] ?? $row['cust_name'] ?? '')
    );

    $before = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS N FROM ACCOUNTS.CUST'
    );
    $countBefore = (int) reset($before);

    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'new', 'name' => $name],
        'header' => ['bill_kind' => 1],
        'models' => [[
            'shape_style_no' => 1,
            'measurements' => ['l1' => 38, 'l5' => 14],
            'specs' => ['button_type' => 'طقطق'],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 1000]],
        ]],
        'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
    ])
        ->assertStatus(409)
        ->assertJsonPath('data.existing_customer.cust_no', (int) ($row['CUST_NO'] ?? $row['cust_no']));

    $after = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS N FROM ACCOUNTS.CUST'
    );

    expect((int) reset($after))->toBe($countBefore);
});

test('the beneficiaries endpoint serves the customer list and the system-wide list', function () {
    Sanctum::actingAs(tailoringCreateAdmin(), ['*']);

    // قائمة العميل / the customer's list.
    $this->getJson('/api/tailoring/create/customers/1784/beneficiaries')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.scope', 'customer');

    // كل مستفيدي النظام بـ scope=all / every beneficiary in the system with scope=all.
    $this->getJson('/api/tailoring/create/customers/1784/beneficiaries?scope=all')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.scope', 'all');
});

test('an unknown fabric variant is refused 422 before any write', function () {
    Sanctum::actingAs(tailoringCreateAdmin(), ['*']);

    $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => 1],
        'header' => ['bill_kind' => 1],
        'models' => [[
            'shape_style_no' => 1,
            'measurements' => ['l1' => 38, 'l5' => 14],
            'specs' => ['button_type' => 'طقطق'],
            'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 999999999999, 'cloth_type' => 'عرضين', 'price' => 1000]],
        ]],
        'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
    ])->assertStatus(422);
});

test('a full bill is created inside a rolled-back transaction', function () {
    // يعمل فقط بـ TAILORING_CREATE_SMOKE=1 — كتابة حقيقية داخل معاملة تُلغى
    // Runs only with TAILORING_CREATE_SMOKE=1 — a real write inside a rolled-back transaction.
    if (getenv('TAILORING_CREATE_SMOKE') !== '1') {
        $this->markTestSkipped('اختبار الكتابة معطّل — TAILORING_CREATE_SMOKE=1 لتفعيله بموافقة صريحة.');
    }

    Sanctum::actingAs(tailoringCreateAdmin(), ['*']);

    // عميل موجود من القاعدة / an existing customer from the database
    $row = (array) DB::connection('oracle')->selectOne(
        'SELECT CUST_NO, CUST_NAME FROM ACCOUNTS.CUST WHERE ROWNUM <= 1'
    );

    if ($row === []) {
        $this->markTestSkipped('لا يوجد أي عميل على القاعدة الحية.');

        return;
    }

    $custNo = (int) ($row['CUST_NO'] ?? $row['cust_no']);

    // قماش متوفر فعلاً من مسار الأقمشة / a truly available fabric from the fabrics path
    $fabric = (array) DB::connection('oracle')->selectOne(
        'SELECT d.SANF_NO, d.SANF_DNO, d.SANF_DNAME, SUM(p.PAL) AS AVAILABLE
           FROM ACCOUNTS.SANFS_D d
           JOIN ACCOUNTS.SANFS_D_PAL p ON p.SANF_DNO = d.SANF_DNO AND p.STORE_NO NOT IN (11,12,2)
          WHERE d.SANF_NO IN (SELECT SANF_NO FROM ACCOUNTS.SANFS WHERE TYPE_NO = 101)
          GROUP BY d.SANF_NO, d.SANF_DNO, d.SANF_DNAME
         HAVING SUM(p.PAL) > 5 AND ROWNUM <= 1'
    );

    if ($fabric === []) {
        $this->markTestSkipped('لا يوجد قماش متوفر على القاعدة الحية.');

        return;
    }

    $before = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS N FROM ACCOUNTS.SALES_BILL WHERE YEAR = :y AND CUTTING_OR_SLAES = 1',
        ['y' => (int) date('Y')]
    );

    $response = $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => $custNo],
        'header' => ['bill_kind' => 1, 'urgent' => true, 'giving_date' => date('Y-m-d', strtotime('+7 days'))],
        'models' => [[
            'shape_style_no' => 2,
            'measurements' => ['l1' => 38, 'l5' => 14, 'l3' => 16],
            'specs' => [
                'button_type' => 'طقطق',
                'com_cabak_type' => 'ساده',
                'neck_type' => 'قلاب',
                'pocket_or_without' => 'جيوب سيار',
                'gabzor_type' => 'سحاااااب',
                'mobile_pocket' => 'عادي+قلم',
            ],
            'pieces' => [[
                'sanf_no' => (int) ($fabric['sanf_no'] ?? $fabric['SANF_NO']),
                'sanf_dno' => (int) ($fabric['sanf_dno'] ?? $fabric['SANF_DNO']),
                'cloth_type' => 'عرضين',
                'price' => 11000,
                'from_store' => 1,
                'habna_duabl' => true,
            ]],
        ]],
        'finance' => [
            'paid' => 5000,
            'cust_known' => false,
            'credit_account' => 41210001,
            'cash_account' => 12110005,
        ],
    ]);

    $response->assertCreated()->assertJsonPath('success', true);

    $data = $response->json('data');

    expect($data['bill_no'])->toBeInt()
        ->and($data['manual_bill_no'])->toBeInt()
        ->and($data['ohid'])->toBeInt()
        ->and($data['entry_no'])->toBeInt()
        ->and($data['total'])->toBe(11000.0)
        ->and($data['paid'])->toBe(5000.0)
        ->and($data['remaining'])->toBe(6000.0)
        ->and($data['pieces_count'])->toBe(1);

    // الرأس موجود بالهوية المركبة / the header exists by its composite identity
    $header = (array) DB::connection('oracle')->selectOne(
        'SELECT BILL_NO, YEAR, MANUAL_BILL_NO, OHID, ENTRY_NO, TOTAL, NET, AMOUNT_PAID, BILLS_KINDS, CUTTING_OR_SLAES
           FROM ACCOUNTS.SALES_BILL
          WHERE BILL_NO = :b AND YEAR = :y',
        ['b' => $data['bill_no'], 'y' => $data['year']]
    );

    expect($header)->not->toBeEmpty()
        ->and((int) ($header['BILLS_KINDS'] ?? $header['bills_kinds']))->toBe(1)
        ->and((int) ($header['CUTTING_OR_SLAES'] ?? $header['cutting_or_slaes']))->toBe(1)
        ->and((int) ($header['OHID'] ?? $header['ohid']))->toBe($data['ohid']);

    // الموديل والقطعة بحساب OHDID النصي / the model and piece with the concat OHDID
    $shape = (array) DB::connection('oracle')->selectOne(
        'SELECT SEQ_NO, OHDID, STYL_NO, BUTTON_TYPE FROM ACCOUNTS.SALES_DETAILES_SHAPES
          WHERE BILL_NO = :b AND YEAR = :y AND SEQ_NO = :s',
        ['b' => $data['bill_no'], 'y' => $data['year'], 's' => $data['ohid'] % 10 === 0 ? null : null]
    );

    // OHDID للموديل الأول = OHID + '1' (ربط نصي) / the first model OHDID = OHID + '1'
    $shape = (array) DB::connection('oracle')->selectOne(
        'SELECT SEQ_NO, OHDID, STYL_NO, BUTTON_TYPE, SANF_NO, SANF_DNO
           FROM ACCOUNTS.SALES_DETAILES_SHAPES
          WHERE BILL_NO = :b AND YEAR = :y AND OHDID = :o',
        ['b' => $data['bill_no'], 'y' => $data['year'], 'o' => $data['ohid']]
    );

    expect($shape)->not->toBeEmpty();

    $piece = (array) DB::connection('oracle')->selectOne(
        'SELECT SEQ_NO1, OHDID, SANFPRICE, SANF_COST, STK_COST, CONSUMED_CLOTH_QTY_YARDS
           FROM ACCOUNTS.MULTI_CLOTHS
          WHERE BILL_NO = :b AND YEAR = :y AND SEQ_NO1 = :s',
        ['b' => $data['bill_no'], 'y' => $data['year'], 's' => $shape['SEQ_NO1'] ?? $shape['seq_no1']]
    );

    expect($piece)->not->toBeEmpty()
        ->and((float) ($piece['SANFPRICE'] ?? $piece['sanfprice']))->toBe(11000.0);

    // القيد: سطران للعربون 5000 على إجمالي 11000 ⇒ 4 سطور / the entry: 4 lines with a deposit
    $lines = DB::connection('oracle')->select(
        'SELECT ACC_NO, ENTRY_MNT FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :e ORDER BY SEQ',
        ['e' => $data['entry_no']]
    );

    expect(count($lines))->toBe(4);

    // بلا أي صف مخزون — القرار المعتمد (الخصم مؤجل للقص) / no stock rows — the approved decision
    $stock = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS N FROM ACCOUNTS.ITEM_MOVE_D
          WHERE BILL_NO = :b AND YEAR = :y AND TR_TYPE_ID = 13',
        ['b' => $data['bill_no'], 'y' => $data['year']]
    );

    expect((int) reset($stock))->toBe(0);
});
