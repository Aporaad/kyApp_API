<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * أصناف المخزن — الحراس الذي يثبت أن أعمدة الصنف المسماة في الكود موجودة فعلاً في المخطط
 * Store items — guards proving every column the code names actually exists in the schema.
 *
 * ── لماذا هذا الملف؟ لأن عطلاً واحداً تكرر ثلاث مرات: الكود طلب `MAIN_COLOR` وهو عمود غير
 *    موجود في `ACCOUNTS.SANFS` (الموجود `MCOLOR`)، فسقط تفصيل الصنف بـ`ORA-00904` عند اختيار
 *    أي صنف فرعي، وكان سيسقط معه الإنشاء والتعديل. اختبارات الصلاحيات لا ترى هذا إطلاقاً: هي
 *    تتحقق من البوابة (401/403) لا من جسم الاستعلام.
 * ── Why this file: one defect repeated three times — the code asked for `MAIN_COLOR`, which does
 *    not exist on `ACCOUNTS.SANFS` (`MCOLOR` does), so the item detail died with ORA-00904 the
 *    moment a sub item was tapped, and create/update would have died with it. Permission tests
 *    never see this: they check the gate (401/403), not the statement body.
 *
 * ── سلامة القاعدة: كل كتابة داخل معاملة تُلغى في النهاية، فلا يبقى صنف اختباري.
 * ── Database safety: every write runs inside a transaction rolled back at the end, so no test
 *    item survives.
 */
beforeEach(function (): void {
    $admin = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if ($admin === null) {
        $this->markTestSkipped('لا يوجد مستخدم مدير مفعّل / No activated administrator exists.');
    }

    Sanctum::actingAs($admin, ['*']);

    $this->oracle = DB::connection('oracle');
    $this->oracle->beginTransaction();
});

afterEach(function (): void {
    if (isset($this->oracle)) {
        $this->oracle->rollBack();
    }
});

/**
 * صنف حقيقي بمرجعياته — مصدر القيم الصحيحة للمجموعة والنوع والنمط والوحدة
 * A real item with its references — the source of valid group/type/style/unit values.
 *
 * @return array<string, mixed>|null
 */
function storeItemSample(): ?array
{
    $row = DB::connection('oracle')->selectOne(
        'SELECT SANF_NO, GROUPNO, TYPE_NO, STYL_NO, UNIT_NO, CAT_NO
           FROM ACCOUNTS.SANFS
          WHERE NVL(ACTIVE, 1) = 1
            AND GROUPNO IS NOT NULL
            AND TYPE_NO IS NOT NULL
            AND STYL_NO IS NOT NULL
            AND UNIT_NO IS NOT NULL
            AND ROWNUM <= 1'
    );

    if ($row === null) {
        return null;
    }

    $row = array_change_key_case((array) $row, CASE_UPPER);

    return [
        'sanf_no' => (int) $row['SANF_NO'],
        'group_no' => (int) $row['GROUPNO'],
        'type_no' => (int) $row['TYPE_NO'],
        'styl_no' => (int) $row['STYL_NO'],
        'unit_no' => (int) $row['UNIT_NO'],
        'cat_no' => array_key_exists('CAT_NO', $row) && $row['CAT_NO'] !== null
            ? (int) $row['CAT_NO']
            : null,
    ];
}

test('the item list answers with real rows', function () {
    $response = $this->getJson('/api/stores/items?limit=5');

    $response->assertStatus(200)->assertJson(['success' => true]);
    expect($response->json('data'))->toBeArray();
});

test('the item detail reads every column it names, including the main colour', function () {
    $sample = storeItemSample();

    if ($sample === null) {
        $this->markTestSkipped('لا توجد أصناف في القاعدة / No items exist.');
    }

    $response = $this->getJson("/api/stores/items/{$sample['sanf_no']}");

    // 500 هنا هو ما كان يحدث: ORA-00904 على عمود مسمّى خطأً
    // A 500 here is exactly what used to happen: ORA-00904 on a wrongly named column.
    $response->assertStatus(200)->assertJson(['success' => true]);

    $item = array_change_key_case((array) $response->json('data.item'), CASE_UPPER);

    // الحقول التي تتغذى من الأعمدة المسمّاة في الاستعلام
    // The fields fed by the columns named in the statement.
    foreach ([
        'SANF_NO', 'PART_NO', 'SANF_NAME', 'SANF_CODE', 'GROUPNO', 'GROUP_NAME',
        'TYPE_NO', 'TYPE_NAME', 'UNIT_NO', 'UNIT_NAME', 'SANF_PRICE', 'BUY_PRICE',
        'CAP', 'MCOLOR', 'ACTIVE', 'STYL_NO', 'STYLE_NAME',
    ] as $key) {
        expect($item)->toHaveKey($key);
    }

    expect($response->json('data.subs'))->toBeArray();
    expect($response->json('data.units'))->toBeArray();
    expect($response->json('data.balances'))->toBeArray();
});

test('the item movements read for a real article', function () {
    $sample = storeItemSample();

    if ($sample === null) {
        $this->markTestSkipped('لا توجد أصناف في القاعدة / No items exist.');
    }

    $this->getJson("/api/stores/items/{$sample['sanf_no']}/movements")
        ->assertStatus(200)
        ->assertJson(['success' => true]);
});

test('an item with its sub item can be created, read back, updated and deleted', function () {
    $sample = storeItemSample();

    if ($sample === null) {
        $this->markTestSkipped('لا توجد أصناف في القاعدة / No items exist.');
    }

    $suffix = substr((string) uniqid(), -6);
    $payload = [
        'part_no' => 'TST'.$suffix,
        'sanf_name' => 'TEST ITEM '.$suffix,
        'sanf_price' => 1500,
        'buy_price' => 1000,
        'unit_no' => $sample['unit_no'],
        'group_no' => $sample['group_no'],
        'type_no' => $sample['type_no'],
        'styl_no' => $sample['styl_no'],
        'cat_no' => $sample['cat_no'],
        'sanf_code' => 'T'.$suffix,
        'mcolor' => 'A',
        'cap' => 1,
    ];

    $created = $this->postJson('/api/stores/items', $payload);
    $created->assertStatus(201)->assertJson(['success' => true]);

    $sanfNo = (int) $created->json('data.sanf_no');
    expect($sanfNo)->toBeGreaterThan(0);

    // القراءة بعد الإنشاء تمر على نفس الاستعلام الذي سقط سابقاً
    // Reading it back walks the statement that used to fail.
    $shown = $this->getJson("/api/stores/items/{$sanfNo}");
    $shown->assertStatus(200)->assertJson(['success' => true]);

    $item = array_change_key_case((array) $shown->json('data.item'), CASE_UPPER);
    expect((int) $item['SANF_NO'])->toBe($sanfNo);
    expect($item['MCOLOR'])->toBe('A');

    // ── الصنف الفرعي: المسار الذي يسلكه المستخدم عند اختيار صنف فرعي
    // ── The sub item: the path a user takes when picking a sub item.
    $sub = $this->postJson("/api/stores/items/{$sanfNo}/sub", [
        'len' => 55,
        'wid' => 17,
        'barcode' => 'BAR'.$suffix,
        'price' => 1500,
    ]);
    $sub->assertStatus(201)->assertJson(['success' => true]);

    $sanfDno = (int) $sub->json('data.sanf_dno');
    expect($sanfDno)->toBeGreaterThan(0);

    // ويظهر في تفصيل الصنف مباشرةً بعد إضافته
    // And it must show up in the item detail right after being added.
    $subs = $this->getJson("/api/stores/items/{$sanfNo}")->json('data.subs');
    expect($subs)->toBeArray();

    $dnos = array_map(
        static fn ($row): int => (int) (((array) $row)['SANF_DNO']
            ?? ((array) $row)['sanf_dno']
            ?? 0),
        $subs
    );
    expect($dnos)->toContain($sanfDno);

    $updated = $this->putJson("/api/stores/items/{$sanfNo}", array_merge($payload, [
        'sanf_price' => 1750,
    ]));
    $updated->assertStatus(200)->assertJson(['success' => true]);

    $this->deleteJson("/api/stores/items/{$sanfNo}")->assertSuccessful();
});
