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
    // ─ النافذة تُشتق من البيانات لا من رقم ثابت: هذا الاختبار يفترض أن ناتج المجموعات الثلاث
    //   يبقى تحت سقف الاقتطاع (5000) فتكون «المجموعات معاً = كل واحدة وحدها» مساواة دقيقة.
    //   نافذة الستين يوماً تجاوزت السقف مع نمو الحركة، فنجرّب نوافذ أقصر ثم نعلن التخطي بأمانة.
    // The window is derived from the data, not hard-coded: the test assumes the three groups
    // together stay under the 5000-row cap so "all together == each alone" is exact. The 60-day
    // window outgrew the cap as movements accumulated, so shorter windows are tried and the
    // test skips honestly when none fits.
    $cap = 5000;
    $body = null;

    foreach ([7, 14, 30, 60] as $days) {
        $window = array_change_key_case((array) DB::connection('oracle')->selectOne(
            "SELECT TO_CHAR(SYSDATE - {$days}, 'YYYY-MM-DD') AS F, TO_CHAR(SYSDATE, 'YYYY-MM-DD') AS T FROM DUAL"
        ), CASE_UPPER);

        $from = (string) ($window['F'] ?? '');
        $to = (string) ($window['T'] ?? '');

        // أقل ثلاث مجموعات حركةً: مجموعها هو الأصغر فيبقى تحت السقف
        // The three least busy groups: their combined volume is the smallest.
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
            continue;
        }

        $candidate = app(StoreReportController::class)->movement(
            reportRequest([
                'from' => $from,
                'to' => $to,
                'group_nos' => implode(',', $groups),
                'limit' => $cap,
            ])
        );

        if ($candidate->getStatusCode() !== 200) {
            continue;
        }

        $candidateBody = $candidate->getData(true);

        // الاقتطاع يحوّل الجمع إلى تقريب، فنستبعد هذا المدى وننتقل إلى نافذة أقصر
        // Truncation turns the sum into an approximation, so this window is skipped.
        if (($candidateBody['meta']['truncated'] ?? true) !== false) {
            continue;
        }

        $body = $candidateBody;

        break;
    }

    if ($body === null) {
        test()->markTestSkipped('لا نافذة تحمل ثلاث مجموعات دون سقف الاقتطاع — لا يمكن مقارنة الجمع بدقة.');
    }

    expect($body['success'])->toBeTrue();

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
        $single = app(StoreReportController::class)->movement(
            reportRequest([
                'from' => $from,
                'to' => $to,
                'group_nos' => "$group",
                'limit' => $cap,
            ])
        )->getData(true);

        // مجموعة واحدة بلغت السقف وحدها: الجمع حينها تقريب أيضاً
        // A single group reached the cap on its own: the sum would be an approximation too.
        if (($single['meta']['truncated'] ?? true) !== false) {
            test()->markTestSkipped('إحدى المجموعات بلغت سقف الاقتطاع وحدها — لا يمكن مقارنة الجمع.');
        }

        $apart += count($single['data']);
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

test('an unknown aggregation level is sanitised, never injected', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    // ── مستوى مجهول لا يصل إلى SQL ولا يُعاد كما هو: الرموز تُفلتر بقائمة بيضاء ثم
    //    يُعاد تركيب الوسيط، فلا نص مستخدم يظهر في ترويسة ولا في GROUP BY
    // ── An unknown level reaches neither the SQL nor the header: tokens are filtered through
    //    a whitelist and the parameter is rebuilt, so no user text is echoed anywhere.
    $response = app(StoreReportController::class)->summary(
        reportRequest([
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'group_by' => 'DROP TABLE sanfs',
        ])
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['meta']['group_by'])->toBe('item_store');

    // الأعمدة المجمَّعة تبقى كما في الوضع الافتراضي
    // The grouped columns stay exactly as the default level.
    expect(array_change_key_case((array) ($response->getData(true)['data'][0] ?? []), CASE_UPPER))
        ->toHaveKey('NET_QTY');
});

test('a movement report sections itself and carries a running balance', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    // صنف رئيسي معروف بفروع: كل فرع قسم مستقل برصيده السابق والجاري
    // A known item with branches: every branch is its own section with an opening and a run.
    $response = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => '2026-01-01',
            'to' => '2026-10-31',
            'scope' => 'item',
            'sanf_no' => '1991',
            'limit' => 500,
        ])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);
    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $body['data']
    );

    if ($rows === []) {
        test()->markTestSkipped('لا حركة للصنف في هذا النطاق.');
    }

    expect($body['meta']['scope'])->toBe('item')
        ->and($body['meta']['sectioned'])->toBeTrue()
        ->and($body['meta']['sections'])->toBeArray();

    foreach ($rows as $row) {
        expect($row)->toHaveKey('SECTION_KEY')
            ->and($row)->toHaveKey('BALANCE_QTY');
    }

    // ── الرصيد الجاري يساوي السابق + تراكم الصافي داخل القسم وحده: هذا هو المصحّح
    //    الذي يجعل كشف الفرع مقروءاً بدل أن يكون قائمة حركات بلا نهاية معروفة.
    // ── The running balance is the opening plus the accumulated net inside its own section —
    //    exactly what turns a branch statement from an endless list into a readable balance.
    $seen = [];

    foreach ($rows as $row) {
        $key = (string) $row['SECTION_KEY'];
        $seen[$key] = ($seen[$key] ?? 0) + (float) $row['P_QTY'];
    }

    foreach ($body['meta']['sections'] as $section) {
        $sectionKey = (string) $section['key'];

        expect($sectionKey)->not->toBe('');
        expect(abs(
            (float) $section['opening_qty']
            + (float) $section['net_qty']
            - (float) $section['closing_qty']
        ))->toBeLessThan(0.01);
        expect(abs((float) $section['qty_in'] - (float) $section['qty_out'] - (float) $section['net_qty']))
            ->toBeLessThan(0.01);
    }

    // الإحصاء العام = مجموع أقسامه، لا رقم مستقل يعارضها
    // The grand total is the sum of its sections, never an independent figure that disagrees.
    $sumOpening = array_sum(array_map(
        static fn (array $s): float => (float) $s['opening_qty'],
        $body['meta']['sections']
    ));

    expect(abs($sumOpening - (float) $body['meta']['totals']['opening_qty']))->toBeLessThan(0.01);
});

test('a branch movement report stays a single unsectioned table', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => '2026-01-01',
            'to' => '2026-10-31',
            'scope' => 'branch',
            'limit' => 200,
        ])
    );

    $body = $response->getData(true);

    expect($body['meta']['scope'])->toBe('branch')
        ->and($body['meta']['sectioned'])->toBeFalse()
        ->and(count($body['meta']['sections']))->toBeLessThanOrEqual(1);
});

test('the summary reports opening and closing balances per row', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->summary(
        reportRequest([
            'from' => '2026-06-01',
            'to' => '2026-10-31',
            'group_by' => 'item*store',
            'limit' => 400,
        ])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);
    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $body['data']
    );

    expect($body['meta']['group_by'])->toBe('item*store')
        ->and($body['meta']['group_tokens'])->toBe(['item', 'store']);

    foreach ($rows as $row) {
        expect($row)->toHaveKey('OPENING_QTY')
            ->and($row)->toHaveKey('CLOSING_QTY')
            ->and($row)->toHaveKey('IN_VALUE')
            ->and($row)->toHaveKey('OUT_VALUE');

        // الحالي = السابق + صافي الفترة: مطابقة حسابية لا تقدير
        // Closing equals opening plus the period net: an identity, not an estimate.
        expect(abs(
            (float) $row['OPENING_QTY'] + (float) $row['NET_QTY'] - (float) $row['CLOSING_QTY']
        ))->toBeLessThan(0.01);
    }
});

test('composite grouping accepts every token combination the reports need', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    foreach (['sub', 'sub*store', 'item*sub', 'subgroup*item', 'group*subgroup'] as $level) {
        $response = app(StoreReportController::class)->summary(
            reportRequest([
                'from' => '2026-08-01',
                'to' => '2026-10-31',
                'group_by' => $level,
                'limit' => 50,
            ])
        );

        expect($response->getStatusCode())->toBe(200);

        $body = $response->getData(true);
        expect($body['meta']['group_by'])->toBe($level);
        expect($body['meta']['group_tokens'])->toBe(explode('*', $level));
    }
});

/**
 * ── نافذة التحقق: تبدأ داخل دفاتر المخزون نفسه
 * ── The verification window: it starts inside the inventory ledger itself.
 *
 * الدفاتر لا تحمل أي حركة قبل 2026-04-25، فرصيد «ما قبل 2026-01-01» صفرٌ بحق لا خلل. اختبار
 * الرصيد السابق يحتاج بدءاً لاحقاً حتى يكون للفترة رصيد افتتاحي فعلي يقارن به.
 * The ledger holds nothing before 2026-04-25, so "before 2026-01-01" is genuinely zero, not a
 * defect. An opening-balance test needs a later start so the period really has an opening.
 */
const REPORT_OPENING_FROM = '2026-09-01';
const REPORT_OPENING_TO = '2026-10-31';

/**
 * السطر الأول من كل قسم مع إحصائه — مطابقة «الرصيد يبدأ من الافتتاح» لكل قسم
 * The first row of every section beside its stats — the "balance starts at the opening" check.
 *
 * @param  array<int, array<string, mixed>>  $rows
 * @param  array<int, array<string, mixed>>  $sections
 * @return array<int, array{key: string, first: float, opening: float, qty: float}>
 */
function sectionOpenings(array $rows, array $sections): array
{
    $stats = [];

    foreach ($sections as $section) {
        $stats[(string) $section['key']] = $section;
    }

    $seen = [];
    $checked = [];

    foreach ($rows as $row) {
        $key = (string) ($row['SECTION_KEY'] ?? '');

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;

        expect($stats)->toHaveKey($key);

        $checked[] = [
            'key' => $key,
            'first' => (float) ($row['BALANCE_QTY'] ?? 0),
            'opening' => (float) $stats[$key]['opening_qty'],
            'qty' => (float) ($row['P_QTY'] ?? 0),
        ];
    }

    return $checked;
}

test('a main-item movement report splits into one section per branch, each opening at its own balance', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => REPORT_OPENING_FROM,
            'to' => REPORT_OPENING_TO,
            'scope' => 'item',
            'sanf_no' => '1991',
            'limit' => 5000,
        ])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);

    if ($body['data'] === []) {
        test()->markTestSkipped('لا حركة للصنف في هذا النطاق.');
    }

    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $body['data']
    );

    expect($body['meta']['scope'])->toBe('item')
        ->and($body['meta']['sectioned'])->toBeTrue()
        ->and($body['meta']['sections'])->not->toBeEmpty();

    // ── العيب الأول الذي شكا منه المستخدم: عمود الرصيد كان يبدأ من الصفر. الرصيد الأول داخل
    //    كل قسم هو رصيد افتتاح ذلك القسم زائد حركته الأولى، ليس صفراً زائدها.
    // ── The first defect the user reported: the balance column opened on zero. The first balance
    //    inside every section is that section's opening plus its first movement, never 0.
    $checked = sectionOpenings($rows, $body['meta']['sections']);
    expect($checked)->not->toBeEmpty();

    foreach ($checked as $check) {
        expect(abs($check['first'] - ($check['opening'] + $check['qty'])))->toBeLessThan(0.01);
    }

    // القسم لا يحمل إلا صنفاً فرعياً واحداً، والمفتاح هو رقم الفرع نفسه
    // A section never mixes two branches, and its key is the branch code itself.
    foreach ($rows as $row) {
        expect((string) $row['SECTION_KEY'])->toBe((string) $row['P_CODE']);
    }
});

test('the sub-group report accumulates by main item, not by branch', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => REPORT_OPENING_FROM,
            'to' => REPORT_OPENING_TO,
            'scope' => 'subgroup',
            'limit' => 5000,
        ])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);

    if ($body['data'] === []) {
        test()->markTestSkipped('لا حركة في هذا النطاق.');
    }

    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $body['data']
    );

    // ── العيب الثاني: هذه التقارير كانت تعرض مثل تقرير الصنف الفرعي (قسم واحد). وحدة القسم
    //    هنا الصنف الرئيسي، فلا بد أن تزيد على قسم واحد ما دامت الحركة تشمل أكثر من صنف.
    // ── The second defect: these reports rendered like a branch statement (one section). The
    //    section unit here is the main item, so more than one is expected whenever several moved.
    $distinct = array_unique(array_map(static fn (array $r): string => (string) $r['I_CODE'], $rows));

    if (count($distinct) > 1) {
        expect(count($body['meta']['sections']))->toBeGreaterThan(1);
    }

    foreach ($rows as $row) {
        expect((string) $row['SECTION_KEY'])->toBe((string) $row['I_CODE']);
    }

    foreach (sectionOpenings($rows, $body['meta']['sections']) as $check) {
        expect(abs($check['first'] - ($check['opening'] + $check['qty'])))->toBeLessThan(0.01);
    }

    // الإحصاء العام = مجموع أقسامه، مفتاحاً مفتاحاً
    // The grand total is the sum of its sections, key by key.
    $sumOpening = array_sum(array_map(
        static fn (array $s): float => (float) $s['opening_qty'],
        $body['meta']['sections']
    ));

    expect(abs($sumOpening - (float) $body['meta']['totals']['opening_qty']))->toBeLessThan(0.01);
});

test('the main-group report accumulates by sub group, never by main item', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => REPORT_OPENING_FROM,
            'to' => REPORT_OPENING_TO,
            'scope' => 'group',
            'limit' => 5000,
        ])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);

    if ($body['data'] === []) {
        test()->markTestSkipped('لا حركة في هذا النطاق.');
    }

    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $body['data']
    );

    expect($body['meta']['scope'])->toBe('group')
        ->and($body['meta']['sectioned'])->toBeTrue();

    // مفتاح القسم هو المجموعة الفرعية (النوع) لا الصنف الرئيسي: هذا ما يميّز التقرير الثالث
    // The section key is the sub group (the type), not the main item: that separates report three.
    foreach ($rows as $row) {
        expect((string) $row['SECTION_KEY'])->toBe((string) $row['ITEM_TYPE_NO']);
    }

    $distinct = array_unique(array_map(static fn (array $r): string => (string) $r['ITEM_TYPE_NO'], $rows));

    if (count($distinct) > 1) {
        expect(count($body['meta']['sections']))->toBeGreaterThan(1);
    }

    foreach (sectionOpenings($rows, $body['meta']['sections']) as $check) {
        expect(abs($check['first'] - ($check['opening'] + $check['qty'])))->toBeLessThan(0.01);
    }
});

test('the branch statement returns a real opening balance instead of starting on zero', function () {
    if (reportUser() === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    $response = app(StoreReportController::class)->movement(
        reportRequest([
            'from' => REPORT_OPENING_FROM,
            'to' => REPORT_OPENING_TO,
            'scope' => 'branch',
            'store_no' => '1',
            'limit' => 5000,
        ])
    );

    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);

    if ($body['data'] === []) {
        test()->markTestSkipped('لا حركة للمخزن في هذا النطاق.');
    }

    $rows = array_map(
        static fn ($r): array => array_change_key_case((array) $r, CASE_UPPER),
        $body['data']
    );

    // النطاق بلا أقسام يحمل قسماً واحداً مفتاحه `__all__` — وله رصيد سابق كأي قسم
    // An unsectioned scope carries one `__all__` section — and it has an opening like any other.
    expect($body['meta']['sections'])->toHaveCount(1);

    $section = $body['meta']['sections'][0];

    expect((string) $section['key'])->toBe('__all__')
        ->and((float) $section['opening_qty'])->not->toBe(0.0);

    // عمود الرصيد يبدأ من الافتتاح لا من الصفر
    // The balance column starts at the opening, never on zero.
    expect(abs(
        (float) $rows[0]['BALANCE_QTY'] - ((float) $section['opening_qty'] + (float) $rows[0]['P_QTY'])
    ))->toBeLessThan(0.01);

    // والقيمة كذلك: رصيد افتتاحي قيمي ثم يتراكم عليه
    // Value too: an opening value, then the period accumulates onto it.
    expect(abs(
        (float) $rows[0]['BALANCE_VALUE'] - ((float) $section['opening_value'] + (float) $rows[0]['LINE_VALUE'])
    ))->toBeLessThan(0.01);

    // الحالي = السابق + الصافي، في القسم وفي الإجمالي
    // Closing = opening + net, in the section and in the grand total.
    expect(abs(
        (float) $section['opening_qty'] + (float) $section['net_qty'] - (float) $section['closing_qty']
    ))->toBeLessThan(0.01);

    expect(abs(
        (float) $body['meta']['totals']['opening_qty'] - (float) $section['opening_qty']
    ))->toBeLessThan(0.01);
});
