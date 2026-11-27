<?php

use App\Http\Controllers\Tailoring\TailoringCreateController;
use App\Models\User;
use App\Support\ArabicEncoding;
use App\Support\Tailoring\TailoringModelItemResolver;
use App\Support\Tailoring\TailoringPolicy;
use App\Support\Tailoring\TailoringPosting;
use Illuminate\Http\Request;
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

/** مستخدم بلا منح — الرفض الافتراضي / a user with no grants — deny by default. */
function tailoringCreateUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

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
    Sanctum::actingAs(tailoringCreateUngrantedUser(), ['*']);

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
        $response = $this->json($verb, $path)->assertForbidden();
        expect($response->json('errors.permission'))->toContain('tailoring.create');
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

test('prepared pieces keep the habna_duabl key expected by the writer', function (bool $habnaDuabl) {
    $request = Request::create('/api/tailoring/create/bills', 'POST', [
        'customer' => ['mode' => 'existing', 'cust_no' => 25566, 'phone' => '776422777'],
        'header' => ['bill_kind' => 1, 'measurements_by' => 'محمد السريحي'],
        'models' => [[
            'shape_style_no' => 2,
            'measurements' => ['l1' => 56, 'l5' => 18],
            'specs' => ['button_type' => 'طقطق', 'tipper_type' => 'A'],
            'pieces' => [[
                'sanf_no' => 2193,
                'sanf_dno' => 10101063005,
                'cloth_type' => 'عرضين',
                'price' => 15000,
                'habna_duabl' => $habnaDuabl,
                'button_no' => 5,
            ]],
        ]],
        'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
    ]);
    $request->setUserResolver(fn () => tailoringCreateAdmin());

    $prepared = app(TailoringCreateController::class)->prepareWritePayload($request, true, false);

    expect($prepared)->toBeArray()
        ->and($prepared['models'][0]['pieces'][0]['habna_duabl'])->toBe($habnaDuabl);
})->with([true, false]);

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

    // مقاسات خارج الحدود المعتمدة: الطول 2-70 والعرض 10-31.5 والكتف 1-23
    // Out-of-bounds measures: length 2-70, width 10-31.5, shoulder 1-23.
    $badMeasures = [
        ['l1' => 1, 'l5' => 14],
        ['l1' => 71, 'l5' => 14],
        ['l1' => 38, 'l5' => 9],
        ['l1' => 38, 'l5' => 32],
        ['l1' => 38, 'l5' => 14, 'l2' => 24],
        ['l1' => 38, 'l5' => 14, 'l10' => 14],
    ];

    foreach ($badMeasures as $measurements) {
        $this->postJson('/api/tailoring/create/bills', [
            'customer' => ['mode' => 'existing', 'cust_no' => 1],
            'header' => ['bill_kind' => 1],
            'models' => [[
                'shape_style_no' => 1,
                'measurements' => $measurements,
                'specs' => ['button_type' => 'طقطق'],
                'pieces' => [['sanf_no' => 1986, 'sanf_dno' => 10101051801, 'cloth_type' => 'عرضين', 'price' => 1000]],
            ]],
            'finance' => ['paid' => 0, 'cust_known' => true, 'credit_account' => 41210001],
        ])->assertStatus(422);
    }
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
    $reported = getenv('TAILORING_CREATE_REPORTED_PAYLOAD') === '1';

    if ($reported) {
        $custNo = 26322;
    }

    if ($reported) {
        // الحمولة المبلّغ عنها تستخدم قماشها المحدد، فلا تعتمد على وجود قماش آخر متوفر
        // The reported payload uses its exact fabric and never depends on an unrelated one.
        $fabricNo = 1517;
        $fabricDno = 10101038504;
    } else {
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

        $fabricNo = (int) ($fabric['sanf_no'] ?? $fabric['SANF_NO']);
        $fabricDno = (int) ($fabric['sanf_dno'] ?? $fabric['SANF_DNO']);
    }
    $family = $reported ? 1 : 2;
    $l1 = $reported ? 57.5 : 38;
    $l5 = $reported ? 16.5 : 14;
    $l3 = $reported ? 23.5 : 16;
    $measurements = ['l1' => $l1, 'l5' => $l5, 'l3' => $l3];
    $specs = [
        'button_type' => 'طقطق',
        'com_cabak_type' => $reported ? 'كبك قماش' : 'ساده',
        'neck_type' => 'قلاب',
        'pocket_or_without' => 'جيوب سيار',
        'gabzor_type' => $reported ? 'باين حشوة' : 'سحاااااب',
        'mobile_pocket' => 'عادي+قلم',
    ];

    if ($reported) {
        $measurements += [
            'l2' => 16.5,
            'l4' => 14.5,
            'l6' => 9.0,
            'l7' => 5.75,
            'l8' => 28.0,
            'l9' => 16.0,
            'l10' => 8.5,
        ];
    }

    // صنف النمط من حل الخادم نفسه — لا نكرر صيغة TYPE_NO/STYL_NO في الاختبار
    // The style item through the server resolver — no duplicated type/style formula.
    $typeNo = 300 + $family;
    $styleNo = $typeNo * 100 + TailoringPolicy::sizeBand($l1);
    $styleItem = TailoringModelItemResolver::resolveStyleItem($typeNo, $styleNo);

    if ($styleItem === null) {
        $this->markTestSkipped("صنف النمط [{$typeNo}/{$styleNo}] غير موجود.");

        return;
    }

    $sizeItem = TailoringModelItemResolver::findSizeItem($styleItem['sanf_no'], $l1, $l5);

    $before = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS N FROM ACCOUNTS.SALES_BILL WHERE YEAR = :y AND CUTTING_OR_SLAES = 1',
        ['y' => TailoringPolicy::dbYear()]
    );

    $response = $this->postJson('/api/tailoring/create/bills', [
        'customer' => ['mode' => 'existing', 'cust_no' => $custNo],
        'header' => array_filter([
            'bill_kind' => 1,
            'urgent' => ! $reported,
            // المستعجل: التاريخ والوقت نصاً في مقدمة ملاحظة الاستعجال
            // Urgent: the date and time as text at the head of the urgent note.
            'giving_note' => $reported ? null : date('d/m/Y', strtotime('+7 days')).' 10:30 - ضروري',
            'measurements_by' => $reported ? 'محمد السريحي' : null,
            'details' => $reported ? 'عليكم فاتورة تفصيل آجلة رقم 15465' : null,
        ], static fn ($value): bool => $value !== null),
        'models' => [array_filter([
            'shape_style_no' => $family,
            'gabzor_hand' => false,
            'notes' => $reported ? 'تركيبه رسمي مطفى' : null,
            'measurements' => $measurements,
            // BUTTONS_NUM اختياري عمداً — هذا Regression لخطأ Undefined array key
            // BUTTONS_NUM is deliberately omitted — regression for the undefined-key failure.
            'specs' => $specs,
            'pieces' => [[
                'gabzor_hand' => false,
                'tatryz_in' => false,
                'sanf_no' => $fabricNo,
                'sanf_dno' => $fabricDno,
                'cloth_type' => 'عرضين',
                'price' => 12000,
                'from_store' => 1,
                'habna_duabl' => true,
            ]],
        ], static fn ($value): bool => $value !== null),
        ],
        'finance' => array_filter([
            'paid' => 1000,
            'cust_known' => true,
            'credit_account' => 41210001,
            'cash_account' => 12210002,
            'transfer_no' => $reported ? '155555555555555' : null,
        ], static fn ($value): bool => $value !== null),
    ]);

    $response->assertCreated()->assertJsonPath('success', true);

    $after = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS N FROM ACCOUNTS.SALES_BILL WHERE YEAR = :y AND CUTTING_OR_SLAES = 1',
        ['y' => TailoringPolicy::dbYear()]
    );

    expect((int) reset($after))->toBe((int) reset($before) + 1);

    $data = $response->json('data');

    expect($data['bill_no'])->toBeInt()
        ->and($data['manual_bill_no'])->toBeInt()
        ->and($data['ohid'])->toBeInt()
        ->and($data['entry_no'])->toBeInt()
        ->and((float) $data['total'])->toBe(12000.0)
        ->and((float) $data['paid'])->toBe(1000.0)
        ->and((float) $data['remaining'])->toBe(11000.0)
        ->and($data['pieces_count'])->toBe(1);

    // الرأس موجود بالهوية المركبة / the header exists by its composite identity
    $header = (array) DB::connection('oracle')->selectOne(
        'SELECT BILL_NO, YEAR, MANUAL_BILL_NO, OHID, ENTRY_NO, TOTAL, NET, AMOUNT_PAID,
               BILLS_KINDS, CUTTING_OR_SLAES, TEL_NO1, ADDRESS, VOUCHER_NO, FIRST_TRANSFER,
               MAIN_BILL, THE_TIME, AM_PM, GIVING_TIME,
               TO_CHAR(GIVING_DATE, \'YYYY-MM-DD\') AS GIVING_DATE_S,
               TO_CHAR(DAT, \'YYYY-MM-DD\') AS DAT_S
           FROM ACCOUNTS.SALES_BILL
          WHERE BILL_NO = :b AND YEAR = :y',
        ['b' => $data['bill_no'], 'y' => $data['year']]
    );

    expect($header)->not->toBeEmpty()
        ->and((int) ($header['BILLS_KINDS'] ?? $header['bills_kinds']))->toBe(1)
        ->and((int) ($header['CUTTING_OR_SLAES'] ?? $header['cutting_or_slaes']))->toBe(1)
        ->and((int) ($header['OHID'] ?? $header['ohid']))->toBe($data['ohid'])
        // القيم الثابتة المطلوبة: ORDSID=0، CUR_NO1/CUR_PRICE1=1، ولا طريقة دفع،
        // وMAIN_BILL=0 صريح، وTHE_TIME فارغ، وGIVING_DATE = تاريخ حفظ الفاتورة كما في القديم
        // The required constants: ORDSID = 0, CUR_NO1/CUR_PRICE1 = 1, no payment method,
        // MAIN_BILL an explicit 0, THE_TIME empty, and GIVING_DATE = the bill's save
        // date exactly like the legacy rows.
        ->and((int) ($header['ORDSID'] ?? $header['ordsid']))->toBe(0)
        ->and((int) ($header['CUR_NO1'] ?? $header['cur_no1']))->toBe(1)
        ->and((int) ($header['CUR_PRICE1'] ?? $header['cur_price1']))->toBe(1)
        ->and($header['PAYMENT_METHOD'] ?? $header['payment_method'])->toBeNull()
        ->and((int) ($header['MAIN_BILL'] ?? $header['main_bill']))->toBe(0)
        ->and($header['THE_TIME'] ?? $header['the_time'])->toBeNull()
        ->and($header['GIVING_TIME'] ?? $header['giving_time'])->toBeNull()
        ->and($header['AM_PM'] ?? $header['am_pm'])->toBe(ArabicEncoding::toOracleSearchChars('1-صباحاً'))
        ->and((string) ($header['GIVING_DATE_S'] ?? $header['giving_date_s']))
        ->toBe((string) ($header['DAT_S'] ?? $header['dat_s']));

    if ($reported) {
        expect($header['VOUCHER_NO'] ?? $header['voucher_no'])->toBe(ArabicEncoding::toOracleSearchChars('محمد السريحي'))
            ->and($header['FIRST_TRANSFER'] ?? $header['first_transfer'])->toBe('155555555555555');
    }

    // OHDID للموديل الأول = OHID + '1' (ربط نصي) / the first model OHDID = OHID + '1'
    $shape = (array) DB::connection('oracle')->selectOne(
        'SELECT SEQ_NO, OHDID, STYL_NO, BUTTON_TYPE, BUTTONS_NUM, SANF_NO, SANF_DNO
           FROM ACCOUNTS.SALES_DETAILES_SHAPES
          WHERE BILL_NO = :b AND YEAR = :y
          ORDER BY SEQ_NO',
        ['b' => $data['bill_no'], 'y' => $data['year']]
    );

    $shapeSanfNo = (int) ($shape['SANF_NO'] ?? $shape['sanf_no']);
    $shapeSanfDno = (int) ($shape['SANF_DNO'] ?? $shape['sanf_dno']);

    expect($shape)->not->toBeEmpty()
        ->and((int) ($shape['OHDID'] ?? $shape['ohdid']))->toBe((int) ((string) $data['ohid'].'1'))
        ->and((int) ($shape['RQUEST_NO'] ?? $shape['rquest_no']))->toBe(1)
        ->and($shapeSanfNo)->toBe($styleItem['sanf_no'])
        ->and($shapeSanfDno)->toBeGreaterThan(0)
        ->and($shape['BUTTONS_NUM'] ?? $shape['buttons_num'])->toBeNull();

    if ($sizeItem !== null) {
        expect($shapeSanfDno)->toBe($sizeItem);
    }

    $piece = (array) DB::connection('oracle')->selectOne(
        'SELECT SEQ_NO1, SEQ_NO, OHDID, SANF_NO, SANF_DNO, SANFPRICE, SANF_COST, STK_COST,
               CONSUMED_CLOTH_QTY_YARDS
           FROM ACCOUNTS.MULTI_CLOTHS
          WHERE BILL_NO = :b AND YEAR = :y AND SEQ_NO = :s',
        ['b' => $data['bill_no'], 'y' => $data['year'], 's' => $shape['SEQ_NO'] ?? $shape['seq_no']]
    );

    expect($piece)->not->toBeEmpty()
        ->and((int) ($piece['SANF_NO'] ?? $piece['sanf_no']))->toBe($fabricNo)
        ->and((int) ($piece['SANF_DNO'] ?? $piece['sanf_dno']))->toBe($fabricDno)
        // PROD_NO = رقم صنف المقاس للموديل، وORDSDID = 0، ولا لون ثانٍ
        // PROD_NO is the model's size item, ORDSDID = 0, and no second colour.
        ->and((int) ($piece['PROD_NO'] ?? $piece['prod_no']))->toBe((int) ($shape['SANF_DNO'] ?? $shape['sanf_dno']))
        ->and((int) ($piece['ORDSDID'] ?? $piece['ordsdid']))->toBe(0)
        ->and($piece['CLOTHS_COLOR2'] ?? $piece['cloths_color2'])->toBeNull()
        ->and((float) ($piece['SANFPRICE'] ?? $piece['sanfprice']))->toBe(12000.0);

    // القيد: عربون 1000 على إجمالي 12000 ⇒ 4 سطور / the entry: 4 lines with a deposit
    $lines = DB::connection('oracle')->select(
        'SELECT ACC_NO, ENTRY_MNT FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :e ORDER BY SEQ',
        ['e' => $data['entry_no']]
    );

    expect(count($lines))->toBe(4);

    $lineSum = 0.0;

    foreach ($lines as $row) {
        $line = (array) $row;
        $lineSum += (float) ($line['ENTRY_MNT'] ?? $line['entry_mnt']);
    }

    expect($lineSum)->toBe(0.0);

    // سطر بيانات الفاتورة وصف الصلاحيات موجودان — لا فاتورة بدونهما
    // The bill's detail row and privilege row exist — no bill without them.
    $detail = (array) DB::connection('oracle')->selectOne(
        'SELECT SANF_NO, SANF_PRICE, QTY, CAP, PCS, TOTAL, SEQ, IS_LOCAL, STK_COST, OHID
           FROM ACCOUNTS.SALES_DETAILES WHERE BILL_NO = :b AND YEAR = :y',
        ['b' => $data['bill_no'], 'y' => $data['year']]
    );

    $pieceStkCost = (float) ($piece['STK_COST'] ?? $piece['stk_cost']);

    expect($detail)->not->toBeEmpty()
        ->and((int) ($detail['SANF_NO'] ?? $detail['sanf_no']))->toBe(1)
        ->and((int) ($detail['QTY'] ?? $detail['qty']))->toBe($data['pieces_count'])
        ->and((int) ($detail['CAP'] ?? $detail['cap']))->toBe(1)
        ->and((int) ($detail['SEQ'] ?? $detail['seq']))->toBeGreaterThan(0)
        ->and((int) ($detail['IS_LOCAL'] ?? $detail['is_local']))->toBe(1)
        // SANF_PRICE = تكلفة الصنف STK_COST كما يكتبها القديم (وليس سعر القطعة)، وبلا OHID
        // SANF_PRICE is the item's STK_COST as the legacy writes it (not the piece
        // price), and OHID stays empty like the legacy rows.
        ->and((float) ($detail['SANF_PRICE'] ?? $detail['sanf_price']))->toBe($pieceStkCost)
        ->and($detail['OHID'] ?? $detail['ohid'])->toBeNull();

    $privilege = (array) DB::connection('oracle')->selectOne(
        'SELECT SEQ, BILL_NO, YEAR FROM ACCOUNTS.SALES_BILL_PRIVILAGE WHERE BILL_NO = :b AND YEAR = :y',
        ['b' => $data['bill_no'], 'y' => $data['year']]
    );

    expect($privilege)->not->toBeEmpty()
        ->and((int) ($privilege['SEQ'] ?? $privilege['seq']))->toBeGreaterThan(0);

    // ENTRY_SUB: رقم العميل في كل الأطراف، وبيان فرعي لكل طرف بصياغة القديم
    // ENTRY_SUB: the customer number on every line, with a per-side legacy sub-detail.
    $texts = TailoringPosting::lineTexts(
        (int) $data['manual_bill_no'],
        (string) $data['cust_name'],
        (int) $data['pieces_count']
    );

    foreach ($lines as $row) {
        $line = (array) $row;
        expect((int) ($line['GIVING'] ?? $line['giving']))->toBe((int) $data['cust_no']);
    }

    $customerLines = DB::connection('oracle')->select(
        'SELECT ACC_NO, ENTRY_MNT, SUB_DETAILS FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :e ORDER BY SEQ',
        ['e' => $data['entry_no']]
    );

    $fixed = array_map(
        static fn ($row): string => ArabicEncoding::fixMojibake((string) (((array) $row)['SUB_DETAILS'] ?? ((array) $row)['sub_details'])),
        $customerLines
    );

    expect($fixed[0])->toContain($texts['sale'])
        ->and($fixed[1])->toContain($texts['revenue'])
        ->and($fixed[2])->toContain($texts['cash'])
        ->and($fixed[3])->toContain($texts['deposit']);

    // ITEM_MOVE_D VIEW مشتق من الفاتورة والقطع ⇒ صف واحد لكل قطعة من المحل
    // ITEM_MOVE_D is derived from the bill and pieces ⇒ one row per shop-sourced piece.
    $stock = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS N FROM ACCOUNTS.ITEM_MOVE_D
          WHERE BILL_NO = :b AND YEAR = :y AND TR_TYPE_ID = 13',
        ['b' => $data['bill_no'], 'y' => $data['year']]
    );

    expect((int) reset($stock))->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// عقد اسم النوع في STYLE_NAME — الاسم وحده لا وصف الشريحة الحجمية (بلا قاعدة)
// The STYLE_NAME type-name contract — the label alone, not the band description (no DB).
// ─────────────────────────────────────────────────────────────────────────────

test('the model type name written to STYLE_NAME is the family label alone', function () {
    expect(TailoringPolicy::styleName(1))->toBe('قطري')
        ->and(TailoringPolicy::styleName(4))->toBe('قطريA')
        ->and(TailoringPolicy::styleName(12))->toBe('معوز وشميز')
        // عائلة غير معروفة: يبقى الاسم الاحتياطي كما هو، وبلا احتياطي «موديل»
        // An unknown family keeps the fallback, and «موديل» without one.
        ->and(TailoringPolicy::styleName(99, 'قطري رجالي من 50 الى 70'))->toBe('قطري رجالي من 50 الى 70')
        ->and(TailoringPolicy::styleName(99))->toBe('موديل');
});

// ─────────────────────────────────────────────────────────────────────────────
// عقد البيان الفرعي للقيد — لكل طرف صيغته (بلا قاعدة)
// The entry sub-detail contract — one wording per side (no DB).
// ─────────────────────────────────────────────────────────────────────────────

test('every entry side carries its own sub-detail wording', function () {
    $texts = TailoringPosting::lineTexts(15464, 'عميل وهمي للتجارب', 2);

    expect($texts['sale'])->toBe('عليكم فاتورة تفصيل رقم 15464 ل عميل وهمي للتجارب عدد 2')
        ->and($texts['revenue'])->toBe('فاتورة مبيعات رقم 15464 - عميل وهمي للتجارب')
        ->and($texts['cash'])->toBe('واصل من /عميل وهمي للتجارب فاتورة رقم 15464')
        ->and($texts['deposit'])->toBe('واصل من فاتورة رقم 15464 - عميل وهمي للتجارب');
});
