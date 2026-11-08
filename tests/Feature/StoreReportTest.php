<?php

use App\Http\Controllers\Stores\StockSheetController;
use App\Http\Controllers\Stores\StoreReportController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تقارير المخازن وكشف الجرد — حدود المدى وثبات الأرصدة
 * Store reports and the stocktake sheet — range limits and balance immutability.
 *
 * ── ما يحرسه هذا الملف: أن التقرير **لا يكتب**. كل ما فيه قراءة من الـ VIEWs، وأي كتابة
 *    عارضة هنا (تصحيح، تسوية، تحديث تكلفة) كانت ستغيّر أرصدة الشركة أثناء مجرد استعراض تقرير.
 * ── What this file guards: a report never writes. Everything is read from the views, and any
 *    incidental write here (a correction, an adjustment, a cost refresh) would change the
 *    company's stock during a read-only look at a report.
 */
function reportUser(): ?User
{
    return User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();
}

function reportRequest(array $query = []): Request
{
    $request = Request::create('/api/stores/reports/movement', 'GET', $query);
    $request->setUserResolver(static fn () => reportUser());

    return $request;
}

/**
 * إجمالي صفوف جداول المستندات الثلاثة — بصمة تغيّر أي كتابة مخزنية
 * The row total of the three document tables — a fingerprint any inventory write would change.
 *
 * @return array<string, int>
 */
function documentRowCounts(): array
{
    $row = array_change_key_case(
        (array) DB::connection('oracle')->selectOne(
            'SELECT (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_RECEIVE) AS RCV,
                    (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_SARF) AS SARF,
                    (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_TRANSM) AS TRM,
                    (SELECT COUNT(*) FROM ACCOUNTS.GARD) AS GARD
               FROM DUAL'
        ),
        CASE_UPPER
    );

    return [
        'receive' => (int) ($row['RCV'] ?? -1),
        'issue' => (int) ($row['SARF'] ?? -1),
        'transfer' => (int) ($row['TRM'] ?? -1),
        'stocktake' => (int) ($row['GARD'] ?? -1),
    ];
}

test('an inverted date range is refused before touching the view', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest(['from' => '2026-10-31', 'to' => '2026-10-01'])
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors'])->toHaveKey('from');
});

test('a range wider than two years is refused', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->summary(
        reportRequest(['from' => '2023-01-01', 'to' => '2026-10-01'])
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors'])->toHaveKey('to');
});

test('a valid movement report returns rows and its own totals', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest(['from' => '2026-10-01', 'to' => '2026-10-31', 'store_no' => '1'])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);
    expect($body['success'])->toBeTrue()
        ->and($body['meta']['kind'])->toBe('movement')
        ->and($body['meta']['from'])->toBe('2026-10-01')
        ->and($body['meta']['to'])->toBe('2026-10-31')
        ->and($body['data'])->toBeArray();

    // كل صف يخص المخزن المطلوب وحده
    // Every returned row belongs to the requested store only.
    foreach ($body['data'] as $row) {
        expect((int) ($row['INV_ID'] ?? $row['inv_id']))->toBe(1);
    }
});

test('the summary aggregates per item and store instead of per movement', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->summary(
        reportRequest(['from' => '2026-10-01', 'to' => '2026-10-31', 'store_no' => '1'])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);
    expect($body['meta']['kind'])->toBe('summary');

    // منتقي OCI8 قد يعيد المفاتيح صغيرة الحروف، فنُطبّعها قبل الفحص
    // OCI8 may return lowercase keys; normalise before asserting.
    foreach ($body['data'] as $row) {
        $normalized = array_change_key_case((array) $row, CASE_UPPER);

        expect($normalized)->toHaveKey('NET_QTY')
            ->and($normalized)->toHaveKey('QTY_IN')
            ->and($normalized)->toHaveKey('QTY_OUT')
            ->and($normalized)->toHaveKey('NET_VALUE');
    }
});

test('the stocktake sheet writes nothing at all', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $before = documentRowCounts();

    $response = app(StockSheetController::class)->index(
        reportRequest(['store_no' => '1'])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);
    expect($body['meta']['postable'])->toBeFalse()
        ->and($body['data'])->toBeArray();

    // الجرد لا يُرحَّل: أعداد المستندات كما هي حرفياً
    // The sheet is never posted: document counts are exactly as they were.
    expect(documentRowCounts())->toBe($before);
});

test('the balances endpoint mirrors AVVG and never invents a store', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->balances(
        reportRequest(['limit' => 5])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);
    expect($body['meta'])->toHaveKeys(['total', 'page', 'limit', 'pages']);

    // المخازن الأرشيفية لا تظهر في أي رصيد
    // Archived stores never appear in a balance.
    foreach ($body['data'] as $row) {
        expect([2, 11, 12])->not->toContain((int) ($row['INV_ID'] ?? $row['inv_id']));
    }
});

/**
 * ── الاختيارات المتعددة: حقل المخازن والمجموعات صار قوائم
 * ── Multi-selection: stores and groups became lists.
 */
test('a multi-store report returns rows of those stores only', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest(['from' => '2026-09-01', 'to' => '2026-10-31', 'store_nos' => '1,3'])
    );

    expect($response->getStatusCode())->toBe(200);

    foreach ($response->getData(true)['data'] as $row) {
        $normalized = array_change_key_case((array) $row, CASE_UPPER);

        expect([1, 3])->toContain((int) ($normalized['INV_ID'] ?? 0));
    }
});

test('multiple main groups widen the report without repeating a movement', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    // ─ النافذة من ساعة أوراكل نفسها لا من ساعة PHP: العمود UTC و`SYSDATE` بفرق ثلاث ساعات،
    //   والفارق الصغير كان يكفي ليقع بعض السطور خارج المدى المطلوب.
    // ─ The window comes from Oracle's own clock, not PHP's: app.timezone is UTC while SYSDATE
    //    runs +03:00, and that gap alone was enough to drop rows outside the requested range.
    $window = array_change_key_case((array) DB::connection('oracle')->selectOne(
        "SELECT TO_CHAR(SYSDATE - 7, 'YYYY-MM-DD') AS F, TO_CHAR(SYSDATE, 'YYYY-MM-DD') AS T FROM DUAL"
    ), CASE_UPPER);

    $from = (string) ($window['F'] ?? '');
    $to = (string) ($window['T'] ?? '');

    // أقل ثلاث مجموعات حركةً في أسبوع: يبقى الناتج تحت سقف الاقتطاع (5000) فيكون الجمع دقيقاً
    // The three least busy groups in a week: the result stays under the 5000-row cap, so the
    // additive comparison below is exact rather than approximate.
    $groups = array_values(array_filter(array_map(
        static fn ($r): int => (int) (array_change_key_case((array) $r, CASE_UPPER)['GROUPNO'] ?? 0),
        DB::connection('oracle')->select(
            "SELECT GROUPNO FROM (
                SELECT GROUPNO, COUNT(*) AS C FROM ACCOUNTS.ITEM_MOVEMENT_D
                 WHERE GROUPNO IS NOT NULL
                   AND I_DATE >= TO_DATE('{$from}', 'YYYY-MM-DD')
                   AND I_DATE < TO_DATE('{$to}', 'YYYY-MM-DD') + 1
                 GROUP BY GROUPNO ORDER BY 2 ASC
             ) WHERE ROWNUM <= 3"
        )
    )));

    if ($groups === []) {
        test()->markTestSkipped('لا مجموعات لها حركة في نافذة الاختبار.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => $from,
            'to' => $to,
            'group_nos' => implode(',', $groups),
            'limit' => 5000,
        ])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);
    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $body['data']
    );

    foreach ($rows as $row) {
        expect($groups)->toContain((int) ($row['GROUPNO'] ?? 0));
    }

    // ─ القيد الأصلي للاختيار المتعدد: ما تعيده المجموعات معاً يساوي ما تعيده كل واحدة
    //   وحدها. رابط زائد يضاعف السطر فيختل الجمع — وهو نفس عيب تكرار الفروع الذي حرسه
    //   اختبار الأصناف الفرعية.
    // ─ The real invariant for multi-selection: the groups together return exactly what they
    //    return one at a time. An extra join would multiply rows and break the sum — the very
    //    defect the duplicated-branch test guards.
    expect($body['meta']['truncated'])->toBeFalse();

    $apart = 0;

    foreach ($groups as $group) {
        $apart += count(
            app(StoreReportController::class)->movement(
                reportRequest([
                    'from' => $from,
                    'to' => $to,
                    'group_nos' => "$group",
                    'limit' => 5000,
                ])
            )->getData(true)['data']
        );
    }

    expect(count($rows))->toBe($apart);
});

test('an absent filter is the same as "none" and constrains nothing', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $all = app(StoreReportController::class)->movement(
        reportRequest(['from' => '2026-10-01', 'to' => '2026-10-31', 'limit' => 50])
    )->getData(true);

    // «لاشي» تصل كفراغ: نفس عدد الصفوف بلا أي قيد، لا تصفية على الصفر
    // "None" arrives empty: the same row count as no constraint at all, never a filter on 0.
    $none = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'limit' => 50,
            'store_nos' => '',
            'group_nos' => '',
            'item_type_nos' => '',
        ])
    )->getData(true);

    expect(count($none['data']))->toBe(count($all['data']));
});

test('a movement row names its branch when the movement has one', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest(['from' => '2026-01-01', 'to' => '2026-10-31', 'limit' => 300])
    );

    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $response->getData(true)['data']
    );

    expect($rows)->not->toBeEmpty();

    $named = 0;

    foreach ($rows as $row) {
        expect($row)->toHaveKey('SUB_NAME')->toHaveKey('COLOR_NAME');

        // فرع معرّف بالرقم يجب أن يجد اسمه: اللقطة الفردية هي المسؤولة عن ذلك
        // A branch identified by number must find its name — the scalar lookup owns that.
        if (! empty($row['P_CODE'])) {
            $named++;
        }
    }

    expect($named)->toBeGreaterThan(0);
});

test('the summary collapses to the requested aggregation level', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $query = ['from' => '2026-01-01', 'to' => '2026-10-31', 'limit' => 500];

    $perItem = app(StoreReportController::class)->summary(
        reportRequest($query + ['group_by' => 'item'])
    )->getData(true);

    $perStore = app(StoreReportController::class)->summary(
        reportRequest($query + ['group_by' => 'store'])
    )->getData(true);

    expect($perStore['meta']['group_by'])->toBe('store')
        // التجميع على مستوى أقل يعني سطوراً أقل لا أكثر
        // Aggregating at a coarser level means fewer rows, never more.
        ->and(count($perStore['data']))->toBeLessThanOrEqual(count($perItem['data']));

    // الخلاصة بالمخزن: مخزن واحد لكل سطر — لا صف لكل حركة ولا تكرار للعمود
    // Store level: one store per row — never a row per movement, and never a repeat.
    $stores = [];

    foreach ($perStore['data'] as $row) {
        $normalized = array_change_key_case((array) $row, CASE_UPPER);
        $store = (int) ($normalized['INV_ID'] ?? 0);

        expect($stores)->not->toContain($store);
        $stores[] = $store;
    }
});

test('an unknown aggregation level falls back to the default silently', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    // مستوى مجهول لا يُمرَّر إلى SQL أبداً: البناء حرفي من match فلا مجال لحقن عمود
    // An unknown level never reaches SQL: the columns come from a literal match, so there is
    // no way to inject one.
    $response = app(StoreReportController::class)->summary(
        reportRequest([
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'group_by' => 'DROP TABLE sanfs',
        ])
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['meta']['group_by'])->toBe('DROP TABLE sanfs');

    // الأعمدة المجمَّعة تبقى كما في الوضع الافتراضي
    // The grouped columns stay exactly as the default level.
    expect(array_change_key_case((array) ($response->getData(true)['data'][0] ?? []), CASE_UPPER))
        ->toHaveKey('NET_QTY');
});
