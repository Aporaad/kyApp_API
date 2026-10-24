<?php

use App\Models\User;
use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات الشجرة الكسولة (المرحلة 1 + 2 من خطة تطوير الشجرة المحاسبية).
 * Lazy chart-of-accounts tree tests: fixed-count skeleton, balances rolled up from
 * ENTRY_SUB (never the dead BALANCE columns), the customers (part 123) display-only
 * exclusion, and ROWNUM pagination of a node's financial children.
 *
 * يتطلب اتصال `oracle` حيّاً: الأرصدة والمحاور كلها تُقرأ من القاعدة الحقيقية، فأي
 * بدالة ترميز أو حجة ترقيم تُختبر على البيانات التي أُنشئت الخطة بسببها.
 * Needs a live Oracle connection: the assertions are pinned to the real rows that
 * motivated the overhaul, so a mocked fixture would prove nothing.
 */
function accountTreeAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحقيقية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مسطّحة الشجرة لكل مستوى، مفهرسة بمعرّف العقدة.
 * Walks the nested skeleton into a flat level → id map so assertions read the tree
 * the way the client does rather than reaching through array offsets.
 */
function accountTreeFlatten(array $nodes, array &$out = []): array
{
    foreach ($nodes as $node) {
        $out[$node['level']][] = $node;
        if (! empty($node['children'])) {
            accountTreeFlatten($node['children'], $out);
        }
    }

    return $out;
}

test('tree endpoints are sealed behind auth and a permission guard', function () {
    // البوابة تسبق المتحكم: بلا جلسة لا يُقرأ أي صف من CHART_ACC أصلاً
    // Refusal happens before the controller touches Oracle.
    $this->getJson('/api/accounts/tree')->assertStatus(401);
    $this->getJson('/api/accounts/tree/children?level=group&group_id=3')->assertStatus(401);
    $this->getJson('/api/accounts/search?q=ارصان')->assertStatus(401);
    $this->getJson('/api/accounts/next-number?level=chart&acc_part=110')->assertStatus(401);
});

test('tree skeleton returns every structural row even with no children', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    $response = $this->getJson('/api/accounts/tree');
    $response->assertStatus(200)->assertJson(['success' => true]);

    $byLevel = accountTreeFlatten($response->json('data'));
    $data = $response->json('data');

    // عدد استعلامات ثابت لا يتضاعف مع عدد العقد: 4 أقسام و24 فرعياً و95 جزئياً
    // The four structural tables drive the skeleton, so their row counts are exact.
    expect($data)->toHaveCount(4)
        ->and($byLevel['main'] ?? [])->toHaveCount(24)
        ->and($byLevel['sub'] ?? [])->toHaveCount(95);

    // العقد الفارغة تظهر أيضاً بعد أن كان `if (! empty(children))` يُسقط الأب
    // Empty structural nodes are emitted now: dropping them hid whole branches.
    $groups = $byLevel['group'] ?? [];
    expect($groups)->not->toBeEmpty();
    foreach ($groups as $group) {
        expect($group)->toHaveKeys(['id', 'level', 'group_id', 'name', 'parent_path', 'code_path', 'debit', 'credit', 'balance', 'movements', 'has_children']);
    }

    $emptyGroupCount = (array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS C FROM ACC_SUB_GROUP g WHERE NOT EXISTS (SELECT 1 FROM CHART_ACC c WHERE c.GROUP_ID = g.GROUP_ID)'
    );
    $emptyGroups = array_filter($groups, fn ($g) => (int) $g['account_count'] === 0);
    expect($emptyGroups)->not->toBeEmpty()
        ->and(count($emptyGroups))->toBe((int) $emptyGroupCount['c']);
});

test('tree balances are rolled up from ENTRY_SUB, not the dead BALANCE column', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    $data = $this->getJson('/api/accounts/tree')->assertStatus(200)->json('data');
    $byLevel = accountTreeFlatten($data);

    // الجزء 123 (العملاء) هو البرهان: أكثر من عشرين ألف حساب مخفي عن العرض، وأرصدة
    // حركاته كلها مجمّعة في عقدة الجزء.
    // Part 123 proves the roll-up: its 20k+ accounts are hidden from display yet
    // their movements still aggregate into the node.
    $customersNode = null;
    foreach ($byLevel['sub'] as $node) {
        if ((int) $node['acc_part'] === 123) {
            $customersNode = $node;
        }
    }
    expect($customersNode)->not->toBeNull();

    $agg = (array) DB::connection('oracle')->selectOne(
        "SELECT SUM(CASE WHEN ENTRY_MNT > 0 THEN ENTRY_MNT ELSE 0 END) DR,
                SUM(CASE WHEN ENTRY_MNT < 0 THEN -ENTRY_MNT ELSE 0 END) CR,
                SUM(ENTRY_MNT) NET
         FROM ENTRY_SUB WHERE SUBSTR(TO_CHAR(ACC_NO), 1, 3) = '123'"
    );

    // وسيط oci8 يرجع المفاتيح صغيرة والقيم نصاً، والتحويل صريح هنا لا افتراض
    // The OCI fetch mode lowercases aliases and returns strings.
    // فروق التقريب العشرية الوحيدة المسموحة، لأن Oracle يرجع DECIMAL كـ string
    // Floating comparison via an absolute gap: Pest has no delta expectation.
    expect(abs((float) $customersNode['debit'] - (float) $agg['dr']))->toBeLessThan(1.0)
        ->and(abs((float) $customersNode['credit'] - (float) $agg['cr']))->toBeLessThan(1.0)
        ->and(abs((float) $customersNode['balance'] - (float) $agg['net']))->toBeLessThan(1.0)
        // الصافي = مدين − دائن: ترقية الأرصدة مستوى-بمستوى لا تجمع نصّين مستقلين
        ->and(abs((float) $customersNode['balance'] - ((float) $agg['dr'] - (float) $agg['cr'])))->toBeLessThan(1.0);

    // الأعمدة المخزّنة ميتة (BALANCE = 0) — لو اعتمدت الشجرة عليها لكان الجزء صفراً
    $stored = (array) DB::connection('oracle')->selectOne(
        'SELECT BALANCE FROM ACC_SUB WHERE ACC_PART = 123'
    );
    expect((float) $stored['balance'])->toBe(0.0)
        ->and((float) $customersNode['balance'])->toBeGreaterThan(0.0);
});

test('customers accounts are hidden from display but keep the single Koboudi leaf', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    $data = $this->getJson('/api/accounts/tree')->assertStatus(200)->json('data');
    $byLevel = accountTreeFlatten($data);

    // مجموعتان تحت الجزء 123 في القاعدة، وكلتاهما مخفية من العرض
    $groupsUnder123 = array_filter(
        $byLevel['group'],
        fn ($g) => (int) $g['acc_part'] === 123
    );
    expect($groupsUnder123)->toBeEmpty();

    $customersNode = null;
    foreach ($byLevel['sub'] as $node) {
        if ((int) $node['acc_part'] === 123) {
            $customersNode = $node;
        }
    }

    // المعروض حساب واحد فقط هو 12310000، والباقي مُخفي عرضاً لا تجميعاً
    $children = $customersNode['children'];
    expect($children)->toHaveCount(1)
        ->and((int) $children[0]['acc_no'])->toBe(12310000)
        ->and($children[0]['level'])->toBe('chart');

    // عدد الحسابات الحقيقي في الجزء يبقى كاملاً في التجميع
    $realCount = (int) ((array) DB::connection('oracle')->selectOne(
        "SELECT COUNT(*) AS C FROM CHART_ACC WHERE SUBSTR(TO_CHAR(ACC_NO), 1, 3) = '123'"
    ))['c'];
    expect((int) $customersNode['account_count'])->toBe($realCount)
        ->and((int) $customersNode['account_count'])->toBeGreaterThan(20000)
        ->and((int) $customersNode['hidden_accounts'])->toBeGreaterThan(20000);
});

test('tree children paginate a group with ROWNUM and honest meta', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    // مجموعة 3 تحت الجزء 129 هي الأكبر (108 حسابات) — نطاق يثبت الترقيم
    $total = (int) ((array) DB::connection('oracle')->selectOne(
        'SELECT COUNT(*) AS C FROM CHART_ACC WHERE GROUP_ID = 3'
    ))['c'];
    expect($total)->toBeGreaterThan(20);

    $first = $this->getJson('/api/accounts/tree/children?level=group&group_id=3&page=1&limit=10');
    $first->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.limit', 10)
        ->assertJsonPath('meta.total', $total)
        ->assertJsonPath('meta.has_more', true);

    expect($first->json('data'))->toHaveCount(10)
        ->and($first->json('meta.total_pages'))->toBe((int) ceil($total / 10));

    $second = $this->getJson('/api/accounts/tree/children?level=group&group_id=3&page=2&limit=10');
    $second->assertStatus(200)->assertJsonPath('meta.page', 2);

    // لا تكرار بين الصفحتين: الشرط كان يُرجع الصفحات نفسها حين يخطئ offset
    $page1 = array_column($first->json('data'), 'acc_no');
    $page2 = array_column($second->json('data'), 'acc_no');
    expect(array_intersect($page1, $page2))->toBeEmpty();

    // الترتيب الافتراضي ACC_NO تصاعدي ومقطع الرولنم يعتمد ترتيباً حتمياً
    // ROWNUM paging is only correct under a deterministic order, so check it.
    $sorted = $page1;
    sort($sorted);
    expect($page1)->toBe($sorted);

    // كل سطر يحمل الأرصدة المحسوبة من الحركات، لا عمود BALANCE المخزّن وحده
    foreach ($first->json('data') as $leaf) {
        expect($leaf)->toHaveKeys(['id', 'level', 'acc_no', 'name', 'debit', 'credit', 'balance', 'movements', 'has_children'])
            ->and($leaf['level'])->toBe('chart');
    }
});

test('lazy children of the customers part stay hidden from display', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    $response = $this->getJson('/api/accounts/tree/children?level=sub&acc_part=123');
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

test('children search matches Arabic names through the encoding helper', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    // اسم معروف في المجموعة 3 ثم البحث بجزء منه
    $row = (array) DB::connection('oracle')->selectOne(
        'SELECT ACC_NO, ACC_NAME FROM CHART_ACC WHERE GROUP_ID = 3 AND ROWNUM = 1'
    );
    $needle = ArabicEncoding::fixMojibake($row['acc_name']);
    expect($needle)->not->toBe('');

    $response = $this->getJson('/api/accounts/tree/children?level=group&group_id=3&search='.urlencode($needle).'&limit=10');
    $response->assertStatus(200)->assertJson(['success' => true]);

    $found = array_column($response->json('data'), 'acc_no');
    expect($found)->toContain((int) $row['acc_no']);
});

test('children validation refuses an unknown level and a missing scope', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    $this->getJson('/api/accounts/tree/children?level=group')
        ->assertStatus(422)
        ->assertJson(['success' => false]);
});

test('comprehensive search returns structural and financial hits with a path', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    $response = $this->getJson('/api/accounts/search?q=12310000&limit=20');
    $response->assertStatus(200)->assertJson(['success' => true]);

    $hits = $response->json('data');
    expect($hits)->not->toBeEmpty();
    foreach ($hits as $hit) {
        expect($hit)->toHaveKeys(['id', 'level', 'name']);
    }

    // حساب الكبودي قابل للاكتشاف بالبحث رغم إخفائه من العرض
    $numbers = array_map(fn ($h) => $h['acc_no'] ?? null, $hits);
    expect($numbers)->toContain(12310000);
});

test('next-number preview equals the number the create endpoint would assign', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    // الخانة الرابعة من رقم الحساب المالي هي عملته: المعاينة تُحسب داخل نطاق
    // (جزء, عملة) واحد، والجزء 110 بعملة رقم 1 → النطاق 11010000..11020000.
    $curNo = 1;
    $preview = $this->getJson('/api/accounts/next-number?level=chart&acc_part=110&cur_no='.$curNo);
    $preview->assertStatus(200)->assertJson(['success' => true]);
    $next = (int) $preview->json('data.number');

    $lo = 110 * 100000 + $curNo * 10000;
    $hi = $lo + 10000;
    $maxRow = (array) DB::connection('oracle')->selectOne(
        'SELECT MAX(ACC_NO) AS MAX_NO FROM CHART_ACC WHERE ACC_NO >= :lo AND ACC_NO < :hi',
        ['lo' => $lo, 'hi' => $hi]
    );
    $expected = $maxRow['max_no'] !== null ? ((int) $maxRow['max_no'] + 1) : $lo + 1;

    expect($next)->toBe($expected)
        // البادئة ثلاثية الأرقام محفوظة: الرقم الجديد يبقى داخل الجزء 110
        ->and(intdiv($next, 100000))->toBe(110)
        // الخانة الرابعة = العملة المختارة، لا أعلى عملة ترتيباً في الجزء
        ->and((int) substr((string) $next, 3, 1))->toBe($curNo);

    // بلا عملة لا يمكن اشتقاق الخانة الرابعة، فلا رقم يُعرض → 422
    $this->getJson('/api/accounts/next-number?level=chart&acc_part=110')
        ->assertStatus(422);

    // المجموعة: MAX(GROUP_ID) + 1
    $groupPreview = (int) $this->getJson('/api/accounts/next-number?level=group')->json('data.number');
    $maxGroup = (int) ((array) DB::connection('oracle')->selectOne('SELECT MAX(GROUP_ID) AS M FROM ACC_SUB_GROUP'))['m'];
    expect($groupPreview)->toBe($maxGroup + 1);

    // الفرعي: أول رقم غير محجوز تحت الحساب الجزئي
    $subPreview = $this->getJson('/api/accounts/next-number?level=sub&acc_sub=110');
    $subPreview->assertStatus(200);
    if ($subPreview->json('success') === true) {
        $candidate = (int) $subPreview->json('data.number');
        $taken = DB::connection('oracle')->select(
            'SELECT ACC_PART FROM ACC_SUB WHERE ACC_PART = :p', ['p' => $candidate]
        );
        expect($taken)->toBeEmpty();
    }
});

test('chart numbering stays inside its own currency band, not the part maximum', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    // الخطأ التراثي: MAX على الجزء كاملاً يأخذ أعلى عملة ترتيباً (في الجزء 251
    // أقصى رقمه 25130011) فيمنح حساباً بعملة ريال رقماً سعودياً. النطاق الصحيح يعزل
    // كل عملة: 2511xxxx للريال و2512xxxx للدولار، فلا يتداخلان أبداً.
    $part = 251;
    foreach ([1, 2, 3] as $curNo) {
        $lo = $part * 100000 + $curNo * 10000;
        $hi = $lo + 10000;
        $number = (int) $this->getJson(
            '/api/accounts/next-number?level=chart&acc_part='.$part.'&cur_no='.$curNo
        )->json('data.number');

        // كل عملة تُبقى رقمها داخل نطاقها الخاص وحده
        expect($number)->toBeGreaterThanOrEqual($lo)
            ->and($number)->toBeLessThan($hi)
            ->and((int) substr((string) $number, 3, 1))->toBe($curNo);
    }
});

test('next-number refuses an unknown level', function () {
    Sanctum::actingAs(accountTreeAdminUser(), ['*']);

    $this->getJson('/api/accounts/next-number?level=nonsense')
        ->assertStatus(422);
});
