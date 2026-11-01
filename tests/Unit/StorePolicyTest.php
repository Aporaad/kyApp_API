<?php

use App\Support\PermissionCatalog;
use App\Support\StorePolicy;

/**
 * سياسة المخازن — الاختبارات التي لا تحتاج قاعدة بيانات
 * Stores policy — the cases that need no database.
 *
 * كل ما يلي ثوابت مقروءة من مخطط `ACCOUNTS` الحيّ ومكتوبة في `StorePolicy`؛ الاختبار يحرسها
 * من أن تُغيَّر سهواً، لأن أي تغيير فيها يجعل الكتابة في الجداول القديمة تفقد أثرها على الرصيد.
 * Everything below is a constant read from the live ACCOUNTS schema and pinned in StorePolicy.
 * The test guards them from accidental drift, because a drift here makes a write to the legacy
 * tables silently lose its effect on stock.
 */
test('the archived stores match the exclusion written inside the movement view', function () {
    // الـ VIEW `ITEM_MOVEMENT_D` تحتوي حرفياً: store_no NOT IN (11, 12, 2)
    // ITEM_MOVEMENT_D literally filters: store_no NOT IN (11, 12, 2).
    expect(StorePolicy::excludedStores())->toBe([2, 11, 12]);

    foreach ([2, 11, 12] as $archived) {
        expect(StorePolicy::isStockStore($archived))->toBeFalse();
    }

    foreach ([1, 3, 4, 13] as $stocked) {
        expect(StorePolicy::isStockStore($stocked))->toBeTrue();
    }

    // الغياب يعني «لا مخزن» لا «المخزن صفر»
    // Absence means "no store", not "store zero".
    expect(StorePolicy::isStockStore(null))->toBeFalse();
});

test('every document kind carries its typeno, sequence and party column', function () {
    $documents = StorePolicy::documents();

    expect(array_keys($documents))->toBe([
        StorePolicy::KIND_RECEIVE,
        StorePolicy::KIND_ISSUE,
        StorePolicy::KIND_TRANSFER,
    ]);

    foreach ($documents as $kind => $document) {
        expect($document['master'])->toStartWith('ACCOUNTS.')
            ->and($document['details'])->toStartWith('ACCOUNTS.')
            ->and($document['sequence'])->toStartWith('SEQ_STORES_')
            ->and($document['typeno'])->toBeInt()
            ->and($document['bill_type'])->toBeInt();
    }

    // أنواع الحركة كما تربطها الـ VIEW: 12 توريد · 13 صرف · 5/4 تحويل
    // The typenos the view joins on: 12 receive · 13 issue · 5/4 transfer.
    expect($documents[StorePolicy::KIND_RECEIVE]['typeno'])->toBe(12)
        ->and($documents[StorePolicy::KIND_ISSUE]['typeno'])->toBe(13)
        ->and($documents[StorePolicy::KIND_TRANSFER]['typeno'])->toBe(5);

    // الإشارة: التوريد يزيد والصرف ينقص — وهي إشارة الـ VIEW نفسها
    // Sign convention: receive adds, issue subtracts — the view's own sign.
    expect($documents[StorePolicy::KIND_RECEIVE]['inout'])->toBe(1)
        ->and($documents[StorePolicy::KIND_ISSUE]['inout'])->toBe(-1);

    expect(StorePolicy::document('unknown'))->toBeNull();
});

test('the transfer is the only kind without a party column', function () {
    $documents = StorePolicy::documents();

    expect($documents[StorePolicy::KIND_RECEIVE]['party'])->toBe('PROV_NAME')
        ->and($documents[StorePolicy::KIND_ISSUE]['party'])->toBe('CUST_NAME')
        ->and($documents[StorePolicy::KIND_TRANSFER]['party'])->toBe('DETAILES');
});

test('the report cap is two years and no more', function () {
    // الـ VIEW UNION ALL على سبعة جداول مستندات؛ مدى مفتوح يعني مسحاً كاملاً
    // The view is a UNION ALL over seven document tables; an open range means a full scan.
    expect(StorePolicy::MAX_REPORT_DAYS)->toBe(731);
});

test('the movement type labels cover every type the modules write', function () {
    $labels = StorePolicy::movementTypeLabels();

    foreach ([12, 13, 4, 5, 0, 2, 17] as $typeno) {
        expect($labels)->toHaveKey($typeno);
    }

    // الأسماء عربية غير فارغة — تُعرض في منتقي نوع الحركة
    // Arabic, non-empty: they are shown in the movement-type picker.
    foreach ($labels as $label) {
        expect($label)->toBeString()->not->toBe('');
    }
});

test('the stores module contributes twenty-six codes to the catalog', function () {
    $storesCodes = array_values(array_filter(
        PermissionCatalog::codes(),
        static fn (string $code): bool => str_starts_with($code, 'stores.')
    ));

    // 1 منح شامل + 4 أساسية + 26 إضافية = 31
    // 1 wildcard + 4 core + 26 extras = 31.
    expect($storesCodes)->toHaveCount(31)
        ->and($storesCodes)->toContain('stores.*')
        ->and($storesCodes)->toContain('stores.receiving_create')
        ->and($storesCodes)->toContain('stores.issuing_delete')
        ->and($storesCodes)->toContain('stores.transfer_update')
        ->and($storesCodes)->toContain('stores.items_create')
        ->and($storesCodes)->toContain('stores.catalogs_manage')
        ->and($storesCodes)->toContain('stores.reports_export')
        ->and($storesCodes)->toContain('stores.stocktake_print');
});
