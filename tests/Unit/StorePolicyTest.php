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

/**
 * الأنواع الثلاثة ليست متطابقة — كل نوع يعلن حقوله ويقرأ أعمدة جدوله
 * The three kinds are not identical — each declares its own fields and reads its own columns.
 *
 * ── لماذا هذا الاختبار؟ لأن أحد أكبر أعطال القسم كان عموداً موجوداً في التوريد وغير موجود في
 *    الصرف. التعريف يمنع التكرار، وهذا الاختبار يمنع أن يتساوى التعريفان مرة أخرى بالخطأ.
 * ── Why this test: one of the module's worst defects was a column that exists on receive and
 *    not on issue. The declaration prevents duplication, and this prevents the two from
 *    accidentally becoming equal again.
 */
test('each document kind declares its own fields and they differ', function () {
    $receive = StorePolicy::fieldsOf(StorePolicy::KIND_RECEIVE);
    $issue = StorePolicy::fieldsOf(StorePolicy::KIND_ISSUE);
    $transfer = StorePolicy::fieldsOf(StorePolicy::KIND_TRANSFER);

    // التوريد: نوع فاتورة + مرجع نصي قابل للكتابة + طرف + خصم + عملة لكل سطر + عبوة
    // Receive: bill type, a writable textual reference, a party, discount, per-line currency, pack.
    expect($receive['bill_type'])->toBeTrue()
        ->and($receive['reference_column'])->toBe('PROV_BILL')
        ->and($receive['reference_writable'])->toBeTrue()
        ->and($receive['party'])->toBeTrue()
        ->and($receive['discount'])->toBeTrue()
        ->and($receive['line_currency'])->toBeTrue()
        ->and($receive['line_pack'])->toBeTrue()
        ->and($receive['line_cost'])->toBeFalse()
        ->and($receive['target_store'])->toBeFalse();

    // الصرف: مرجعه SEQBILL للقراءة فقط، ويطبع التكلفة ولا عبوة
    // Issue: its reference is the read-only SEQBILL, it prints cost and no pack.
    expect($issue['reference_column'])->toBe('SEQBILL')
        ->and($issue['reference_writable'])->toBeFalse()
        ->and($issue['line_cost'])->toBeTrue()
        ->and($issue['line_currency'])->toBeFalse()
        ->and($issue['line_pack'])->toBeFalse()
        ->and($issue['bill_type'])->toBeTrue();

    // التحويل: بلا نوع فاتورة ولا خصم ولا طرف، وبمخزنين ومسلسلين
    // Transfer: no bill type, no discount, no party — but two stores and two serials.
    expect($transfer['bill_type'])->toBeFalse()
        ->and($transfer['discount'])->toBeFalse()
        ->and($transfer['party'])->toBeFalse()
        ->and($transfer['currency_pair'])->toBeFalse()
        ->and($transfer['target_store'])->toBeTrue()
        ->and($transfer['serials'])->toBeTrue()
        ->and($transfer['line_cost'])->toBeTrue()
        ->and($transfer['line_pack'])->toBeFalse();

    // ولا تعريفان متساويان
    // And no two declarations are equal.
    expect($receive)->not->toBe($issue)
        ->and($issue)->not->toBe($transfer)
        ->and($receive)->not->toBe($transfer);
});

test('the client-facing profiles expose the same distinctions', function () {
    $profiles = StorePolicy::documentProfiles();

    expect(array_keys($profiles))->toBe(['receive', 'issue', 'transfer'])
        ->and($profiles['receive']['title'])->toBe('فاتورة توريد مخزني')
        ->and($profiles['issue']['title'])->toBe('منصرف مخزني')
        ->and($profiles['transfer']['title'])->toBe('تحويل مخزني')
        // ما يراه العميل من أعلام يجب أن يطابق ما تقرأه المتحكمات
        // What the client sees must be the same flags the controllers read.
        ->and($profiles['receive']['fields'])->toBe(StorePolicy::fieldsOf('receive'))
        ->and($profiles['transfer']['fields'])->toBe(StorePolicy::fieldsOf('transfer'));
});
