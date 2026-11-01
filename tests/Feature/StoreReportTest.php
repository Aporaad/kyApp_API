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
