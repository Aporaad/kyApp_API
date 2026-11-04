<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * قراءات تبويب الأصناف وكشف الحركة — بيانات حقيقية لا غلاف فارغ
 * The items-tab reads and the movement statement — real data, not an empty envelope.
 *
 * ── ما يحرسه هذا الملف: أن كميات الأصناف الفرعية تُشتق من `ITEM_MOVEMENT_D` عبر `P_CODE`
 *    (لأن `AVVG` لا تعرف الأصناف الفرعية)، وأن كشف الحركة يحمل مجاميعه من الخادم. الغلاف
 *    `success: true` وحده لا يثبت أن الأرقام موجودة — والاختبار موجود لهذا الفرق.
 * ── What this guards: a branch quantity is derived from ITEM_MOVEMENT_D via P_CODE (because
 *    AVVG knows no sub items), and the movement statement carries its totals from the server.
 *    A `success: true` envelope alone does not prove the numbers exist — that gap is the point.
 */
function itemsReader(): ?User
{
    return User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();
}

test('the reference bundle carries real identifiers and flags archived stores', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/stores/reference')->assertStatus(200);

    $groups = $response->json('data.groups');
    $types = $response->json('data.types');
    $units = $response->json('data.units');

    expect($groups)->toBeArray()->not->toBeEmpty()
        ->and($types)->toBeArray()->not->toBeEmpty()
        ->and($units)->toBeArray()->not->toBeEmpty();

    // ── المفتاح العلوي ورقمه حقيقي: هذا ما كان يفشل. منتقي OCI8 كان يعيد `group_no` صغيرة
    //    فيقرأها العميل 0، فتُطلب أصناف المجموعة صفر وتظهر القائمة فارغة.
    // ── The key is upper-case and its number is real: this is what failed. The OCI8 driver
    //    returned lowercase `group_no`, the client read 0, and group-zero items came back empty.
    expect($groups[0])->toHaveKey('GROUP_NO')
        ->and((int) $groups[0]['GROUP_NO'])->toBeGreaterThan(0)
        ->and((string) $groups[0]['GROUP_NAME'])->not->toBe('');

    expect($types[0])->toHaveKey('TYPE_NO')
        ->and((int) $types[0]['TYPE_NO'])->toBeGreaterThan(0)
        ->and($types[0])->toHaveKey('GROUP_NO');

    expect((int) $units[0]['UNIT_NO'])->toBeGreaterThan(0);

    // المخازن الأرشيفية تحمل وسمها: بدونه يستطيع المستخدم إنشاء مستند على مخزن لا يظهر في الأرصدة
    // Archived stores carry their flag: without it a document could be created on a store that
    // never appears in any balance.
    $stores = $response->json('data.stores');
    $archived = array_filter($stores, static fn (array $row): bool => ($row['ARCHIVED'] ?? 0) == 1);

    expect($archived)->not->toBeEmpty();

    foreach ($archived as $row) {
        expect(in_array((int) $row['STORE_NO'], [2, 11, 12], true))->toBeTrue();
    }
});

test('the default filter combination returns items, not zero', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    // أول مجموعة وأول فرعية منها كما يختارهما العميل افتراضياً
    // The first group and its first sub group, exactly as the client picks them by default.
    $groups = $this->getJson('/api/stores/reference')->json('data.groups');
    $types = $this->getJson('/api/stores/reference')->json('data.types');

    $groupNo = (int) $groups[0]['GROUP_NO'];
    $firstType = null;

    foreach ($types as $type) {
        if ((int) $type['GROUP_NO'] === $groupNo) {
            $firstType = (int) $type['TYPE_NO'];

            break;
        }
    }

    $query = '/api/stores/items?page=1&limit=50&active=1&group_no='.$groupNo
        .($firstType === null ? '' : '&type_no='.$firstType);

    $response = $this->getJson($query)->assertStatus(200);

    expect($response->json('success'))->toBeTrue();

    // ── هذا هو الاختبار الذي كان يجب أن يفشل قبل الإصلاح: التركيبة الافتراضية تُرجع أصنافاً
    //    حقيقية لا `total: 0`.
    // ── This is the assertion that should have failed before the fix: the default combination
    //    returns real items, not `total: 0`.
    expect((int) $response->json('meta.total'))->toBeGreaterThan(0);
    expect($response->json('data'))->toBeArray()->not->toBeEmpty();

    // ── الرصيد موجود في قائمة الأصناف نفسها: مجموع فروعه، ومصدره الحركة لا `AVVG`. لو عاد
    //    المصدر إلى `AVVG` لتجاوز الطلب مهلة العميل (١٥ ثانية) وظهرت القائمة فارغة.
    // ── The balance is on the items list itself: the sum of its branches, sourced from movement,
    //    not AVVG. Going back to AVVG would exceed the 15s client timeout and empty the list.
    $sanfNo = (int) $response->json('data.0.SANF_NO');
    $listedQty = $response->json('data.0.QTY');

    expect($listedQty)->not->toBeNull();

    // الرصيد المعروض = مجموع أرصدة فروع نفس الصنف، وهو تعريف المستخدم للرصيد
    // The displayed balance equals the sum of that item's branches — the user's definition.
    $subs = $this->getJson('/api/stores/items/'.$sanfNo)->json('data.subs');

    $sumOfBranches = array_reduce(
        is_array($subs) ? $subs : [],
        static fn (float $carry, array $row): float => $carry + (float) ($row['QTY'] ?? 0),
        0.0
    );

    expect(abs((float) $listedQty - $sumOfBranches))
        ->toBeLessThan(0.001);
});

test('the sub items tab never repeats a branch', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    // ── العطل المبلَّغ: «يتم تكرار الصنف الفرعي مرتين». التجميع بـ`SANF_DNO` في الخادم يمنع
    //    ذلك بنيوياً، وهذا الاختبار يغلق الباب: عدد الصفوف = عدد المعرّفات المميزة.
    // ── The reported defect: "the sub item is repeated twice". Grouping by SANF_DNO on the server
    //    makes doubling structurally impossible; this test keeps that door shut:
    //    row count equals distinct-id count.
    $rows = $this->getJson('/api/stores/items/sub?limit=50')->json('data');

    expect($rows)->toBeArray()->not->toBeEmpty();

    $ids = array_map(static fn (array $row): int => (int) $row['SANF_DNO'], $rows);

    expect(count($ids))->toBe(count(array_unique($ids)));

    // وكل فرع يحمل رصيده هو، لا رصيد صنفه الأب
    // And every branch carries its own balance, not its parent's.
    expect($rows[0])->toHaveKey('QTY');
});

test('the sub items tab answers a paged envelope with real branches', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/stores/items/sub?limit=5')->assertStatus(200);

    $response->assertJsonPath('success', true);

    $rows = $response->json('data');

    expect($rows)->toBeArray()->not->toBeEmpty();

    // كل سطر فرع حقيقي: رقمه واسم أبيه ورمزه
    // Every row is a real branch: its number, its parent's name and its code.
    $first = $rows[0];

    expect($first)->toHaveKey('SANF_DNO')
        ->and((int) $first['SANF_DNO'])->toBeGreaterThan(0)
        ->and($first)->toHaveKey('SANF_NAME')
        ->and((int) $first['SANF_NO'])->toBeGreaterThan(0);

    // ── الفرع يُعرض بتفاصيله هو: سعره ووحدته وعبوته أعمدة في `SANFS_D` وليست من أبيه،
    //    وبلاها تظهر شاشة الفرع حقولاً فارغة.
    // ── A branch is shown with its own columns: price, unit and pack live on SANFS_D, not on
    //    the parent. Without them the branch screen would render empty fields.
    expect($first)->toHaveKey('SANF_PRICE')
        ->and($first)->toHaveKey('CAP')
        ->and($first)->toHaveKey('QTY')
        ->and($first)->toHaveKey('VALUE');

    expect($response->json('meta.total'))->toBeGreaterThan(0);
});

test('a branch keeps a quantity derived from the movement view', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    // صنف 567 له أكثر من مئة فرع وحركات حقيقية — حالة اختبار صريحة
    // Item 567 has over a hundred branches and real movement — an explicit test case.
    $response = $this->getJson('/api/stores/items/567')->assertStatus(200);

    $subs = $response->json('data.subs');

    expect($subs)->toBeArray()->not->toBeEmpty();

    $withQty = array_filter($subs, static fn (array $row): bool => ($row['QTY'] ?? 0) != 0);

    // لو غاب الربط بـ`P_CODE` لخرجت كل الكميات صفراً — وهذا ما يثبته هذا الاختبار
    // Without the P_CODE join every quantity would be zero — which is what this proves is not.
    expect($withQty)->not->toBeEmpty();

    foreach ($subs as $row) {
        expect($row)->toHaveKey('QTY')->and($row)->toHaveKey('SANF_DNO');
    }
});

test('the movement statement returns server-side totals for a main item', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/stores/items/567/movements?limit=20')->assertStatus(200);

    $response->assertJsonPath('success', true);

    $rows = $response->json('data');

    expect($rows)->toBeArray()->not->toBeEmpty();

    // السطر يحمل فرعه: كشف الصنف الرئيسي «بكامل فروعه»
    // The row carries its branch: the main item's statement across all its branches.
    expect($rows[0])->toHaveKey('RCRD_NO');

    $totals = $response->json('meta.totals');

    expect($totals)->toBeArray()
        ->and($totals)->toHaveKey('QTY_IN')
        ->and($totals)->toHaveKey('QTY_OUT')
        ->and($totals)->toHaveKey('LINES');

    // الأسطر الكلية تتجاوز حدود الصفحة: هذا سبب أخذ المجاميع من الخادم
    // Total lines exceed the page limit — the reason the totals come from the server.
    expect((int) $totals['LINES'])->toBeGreaterThan(count($rows));
});

test('the item detail never repeats a branch and its balance equals the sum of branches', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    // ── العطل الذي يحرّسه هذا الاختبار: `SANFS_COLOR` فيه 54 صفاً لـ 27 معرّف لون، فأي
    //    `LEFT JOIN` عليه يضاعف كل فرع مضاعفة ظاهرة — 10 صفوف لخمسة فروع — فيرى المستخدم
    //    الفروع مكررة، ويرى رصيد الصنف **مضاعفاً** لأن مجموع الفروع يحسب كل فرع مرتين.
    // ── The defect guarded here: SANFS_COLOR holds 54 rows for 27 colour ids, so any LEFT JOIN
    //    on it doubles every branch — 10 rows for 5 branches. The user saw repeated branches and
    //    a DOUBLED item balance, because summing branches counted each one twice.
    $items = $this->getJson('/api/stores/items?limit=6&active=1&group_no=1&type_no=101')
        ->assertStatus(200)->json('data');

    expect($items)->toBeArray()->not->toBeEmpty();

    foreach ($items as $row) {
        $r = array_change_key_case((array) $row, CASE_UPPER);
        $sanfNo = (int) $r['SANF_NO'];

        $subs = $this->getJson('/api/stores/items/'.$sanfNo)->json('data.subs') ?? [];

        $ids = array_map(
            static fn (array $s): int => (int) (array_change_key_case((array) $s, CASE_UPPER)['SANF_DNO'] ?? 0),
            $subs
        );

        expect(count($ids))->toBe(count(array_unique($ids)));

        // رصيد القائمة = مجموع أرصدة الفروع، وهو تعريف المستخدم الحرفي
        // The listed balance equals the sum of its branch balances — the user's own definition.
        $sum = array_reduce(
            $subs,
            static fn (float $carry, array $s): float => $carry + (float) (array_change_key_case((array) $s, CASE_UPPER)['QTY'] ?? 0),
            0.0
        );

        expect(abs((float) ($r['QTY'] ?? 0) - $sum))->toBeLessThan(0.001);
    }
});

test('a branch filter narrows the statement to that branch only', function () {
    $user = itemsReader();

    if ($user === null) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية.');
    }

    Sanctum::actingAs($user, ['*']);

    $subs = $this->getJson('/api/stores/items/567')->json('data.subs');

    if (empty($subs)) {
        test()->markTestSkipped('لا أصناف فرعية لهذا الصنف.');
    }

    $sanfDno = (int) $subs[0]['SANF_DNO'];

    $response = $this->getJson('/api/stores/items/567/movements?sub_dno='.$sanfDno)
        ->assertStatus(200);

    foreach ($response->json('data') as $row) {
        expect((int) ($row['RCRD_NO'] ?? 0))->toBe($sanfDno);
    }
});
