<?php

use App\Http\Controllers\Stores\IssueOrderController;
use App\Http\Controllers\Stores\ReceiveOrderController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * كتابة أوامر المخازن — الاختبار الذي يثبت أن المستند يحرّك الرصيد فعلاً
 * Writing inventory orders — the test that proves a document actually moves stock.
 *
 * ── لماذا هذا الاختبار أهم من أي اختبار واجهة؟ لأن الخطر الحقيقي في هذا المخطط ليس خطأً
 *    يظهر للمستخدم، بل **مستند يُحفظ ولا أثر له**: لو غاب `SANF_DNO` الصحيح أو أُغفل نسخ
 *    `LEN/WID` إلى `SIZE_FRM/SIZE_TO` في التحويل، يقبل الخادم الأمر ويبقى الرصيد كما هو.
 *    لذلك كل حالة أدناه تنظر إلى `ITEM_MOVEMENT_D` و`AVVG` مباشرة.
 * ── Why this matters more than any UI test: the real hazard in this schema is not an error the
 *    user sees, it is a document that saves with no effect. A wrong SANF_DNO, or a transfer that
 *    forgets to copy LEN/WID into SIZE_FRM/SIZE_TO, is accepted silently and leaves stock
 *    untouched. Every case below therefore reads ITEM_MOVEMENT_D and AVVG directly.
 *
 * ── سلامة القاعدة: كل الكتابة تجري داخل معاملة تُلغى في النهاية، فلا يبقى صف واحد بعد
 *    الاختبار — لا في المستندات ولا في الأثر المحاسبي.
 * ── Database safety: every write runs inside a transaction that is rolled back at the end, so
 *    no row survives — neither a document nor its accounting effect.
 */
function storesAdminUser(): ?User
{
    return User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();
}

/**
 * صنف حقيقي له صنف فرعي ورصيد في المخزن 1 — الشروط التي تجعله مرئياً في الـ VIEW
 * A real item with a sub item and stock in store 1 — the conditions that make it visible
 * inside the movement view.
 *
 * @return array{sanf_no:int, sanf_dno:int, name:string, unit_no:int, qty:float}|null
 */
function storesSampleItem(): ?array
{
    $row = DB::connection('oracle')->selectOne(
        'SELECT s.SANF_NO, d.SANF_DNO, s.SANF_NAME, s.UNIT_NO,
                (SELECT SUM(a.SUM_TP) FROM ACCOUNTS.AVVG a
                  WHERE a.I_CODE = s.SANF_NO AND a.INV_ID = 1) AS QTY
           FROM ACCOUNTS.SANFS s
           JOIN ACCOUNTS.SANFS_D d ON d.SANF_NO = s.SANF_NO
          WHERE NVL(s.ACTIVE, 1) = 1
            AND NVL(d.ACTIVE, 1) = 1
            AND s.STYL_NO IS NOT NULL
            AND s.SANF_NO IN (
                  SELECT I_CODE FROM ACCOUNTS.AVVG WHERE INV_ID = 1 AND SUM_TP > 5
            )
            AND ROWNUM <= 1'
    );

    if ($row === null) {
        return null;
    }

    $row = array_change_key_case((array) $row, CASE_UPPER);

    return [
        'sanf_no' => (int) $row['SANF_NO'],
        'sanf_dno' => (int) $row['SANF_DNO'],
        'name' => (string) $row['SANF_NAME'],
        'unit_no' => (int) $row['UNIT_NO'],
        'qty' => (float) $row['QTY'],
    ];
}

/**
 * طلب مصادق عليه كمدير النظام
 * An authenticated request as the system administrator.
 */
function storesRequest(array $payload, ?User $user): Request
{
    $request = Request::create('/api/stores/receive', 'POST', $payload);
    $request->setUserResolver(static fn () => $user);

    return $request;
}

/**
 * رصيد صنف في مخزن من `AVVG` — نفس الرقم الذي تعرضه الشاشة
 * An item's quantity in one store from AVVG — the same number the screen shows.
 */
function storesQty(int $sanfNo, int $storeNo): float
{
    $row = array_change_key_case(
        (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(SUM(SUM_TP), 0) AS Q FROM ACCOUNTS.AVVG
              WHERE I_CODE = :i AND INV_ID = :s',
            ['i' => $sanfNo, 's' => $storeNo]
        ),
        CASE_UPPER
    );

    return (float) ($row['Q'] ?? 0);
}

test('a receive order moves the balance and its removal moves it back', function () {
    $user = storesAdminUser();
    $item = storesSampleItem();

    if ($user === null || $item === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية بصنف له رصيد في المخزن 1.');
    }

    $oracle = DB::connection('oracle');
    $before = storesQty($item['sanf_no'], 1);
    $billNo = 0;

    $oracle->beginTransaction();

    try {
        $response = app(ReceiveOrderController::class)->store(storesRequest([
            'store_no' => 1,
            'dat' => '2026-11-01',
            'party_name' => 'اختبار آلي',
            'detailes' => 'اختبار كتابة أمر توريد',
            'lines' => [
                ['sanf_no' => $item['sanf_no'], 'sanf_dno' => $item['sanf_dno'], 'qty' => 2, 'price' => 10],
            ],
        ], $user));

        expect($response->getStatusCode())->toBe(201);

        $billNo = (int) ($response->getData(true)['data']['bill_no'] ?? 0);
        expect($billNo)->toBeGreaterThan(0);

        // 1) السطر ظهر في الـ VIEW، وليس في جدول المستند فقط. نوع الحركة في الـ VIEW
        //    هو BILL_DOC_TYPE لا TYPE_NO (الأخير يحمل نوع الصنف الفرعي).
        // The line reaches the view, not merely the document table. The movement type in the
        // view is BILL_DOC_TYPE; TYPE_NO carries the item's sub group.
        $movements = array_change_key_case(
            (array) $oracle->selectOne(
                'SELECT COUNT(*) AS C FROM ACCOUNTS.ITEM_MOVEMENT_D
                  WHERE INVOIC_ID = :b AND BILL_DOC_TYPE = 12 AND I_CODE = :i',
                ['b' => $billNo, 'i' => $item['sanf_no']]
            ),
            CASE_UPPER
        );

        expect((int) ($movements['C'] ?? 0))->toBeGreaterThan(0);

        // 2) الرصيد زاد بالمقدار نفسه
        // The balance rose by exactly the ordered quantity.
        expect(storesQty($item['sanf_no'], 1))->toBeGreaterThan($before);

        // 3) سجل التدقيق كُتب: قسم جديد بلا سجل نشاط لا يمكن مراجعته لاحقاً
        // An audit row exists: a new module with no activity trail cannot be reviewed later.
        $audit = array_change_key_case(
            (array) $oracle->selectOne(
                "SELECT COUNT(*) AS C FROM ACCOUNTS.APP_USER_ACTIVITY
                  WHERE ACTION_CODE = 'stores.receiving_created' AND ENTITY_ID = :id",
                ['id' => (string) $billNo]
            ),
            CASE_UPPER
        );

        expect((int) ($audit['C'] ?? 0))->toBeGreaterThan(0);

        // 3) الحذف الفيزيائي يعيد الرصيد إلى ما كان — لأن FLAG_CANCEL غير مقروء في الـ VIEW
        // Physical deletion restores the balance: FLAG_CANCEL is never read by the view.
        $deleted = app(ReceiveOrderController::class)->destroy(
            storesRequest([], $user),
            $billNo
        );

        expect($deleted->getStatusCode())->toBe(200);

        $afterDelete = array_change_key_case(
            (array) $oracle->selectOne(
                'SELECT COUNT(*) AS C FROM ACCOUNTS.ITEM_MOVEMENT_D WHERE INVOIC_ID = :b',
                ['b' => $billNo]
            ),
            CASE_UPPER
        );

        expect((int) ($afterDelete['C'] ?? 0))->toBe(0)
            ->and(storesQty($item['sanf_no'], 1))->toBe($before);
    } finally {
        $oracle->rollBack();
    }
});

test('an archived store is refused before anything is written', function () {
    $user = storesAdminUser();
    $item = storesSampleItem();

    if ($user === null || $item === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    // 11 مخزن أرشيفي: الـ VIEW تستثنيه، فحفظ أمر عليه يعني كمية مفقودة بلا رسالة
    // Store 11 is archived: the view excludes it, so saving there loses the quantity silently.
    $response = app(ReceiveOrderController::class)->store(storesRequest([
        'store_no' => 11,
        'dat' => '2026-11-01',
        'party_name' => 'اختبار آلي',
        'lines' => [
            ['sanf_no' => $item['sanf_no'], 'sanf_dno' => $item['sanf_dno'], 'qty' => 1, 'price' => 1],
        ],
    ], $user));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['success'])->toBeFalse();
});

test('a line whose sub item does not belong to the item is refused', function () {
    $user = storesAdminUser();
    $item = storesSampleItem();

    if ($user === null || $item === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    // صنف فرعي غير موجود: بلا SANF_DNO صحيح لا يظهر السطر في أي رصيد
    // A non-existent sub item: without a correct SANF_DNO the line reaches no balance.
    $response = app(ReceiveOrderController::class)->store(storesRequest([
        'store_no' => 1,
        'dat' => '2026-11-01',
        'party_name' => 'اختبار آلي',
        'lines' => [
            ['sanf_no' => $item['sanf_no'], 'sanf_dno' => 1, 'qty' => 1, 'price' => 1],
        ],
    ], $user));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['success'])->toBeFalse();
});

test('an issue order larger than the available stock is refused', function () {
    $user = storesAdminUser();
    $item = storesSampleItem();

    if ($user === null || $item === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $available = storesQty($item['sanf_no'], 1);

    $response = app(IssueOrderController::class)->store(storesRequest([
        'store_no' => 1,
        'dat' => '2026-11-01',
        'party_name' => 'قسم الاختبار',
        'lines' => [
            [
                'sanf_no' => $item['sanf_no'],
                'sanf_dno' => $item['sanf_dno'],
                'qty' => $available + 999999,
                'price' => 1,
            ],
        ],
    ], $user));

    expect($response->getStatusCode())->toBe(422);

    $body = $response->getData(true);
    expect($body['errors'])->toHaveKey('stock');
});
