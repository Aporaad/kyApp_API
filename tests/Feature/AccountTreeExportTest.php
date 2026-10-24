<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات تصدير الشجرة المسطّح ودليل عملات النموذج.
 * Tests for the flat tree-export endpoint and the account-form currency picker.
 *
 * السبب في وجود هذين المسارين: كانت المطبوعة تُبنى مما عرضته الشاشة، والشاشة كسولة —
 * فأي شجرة لم يوسّعها المستخدم تُطبع برؤوس أقسامها فقط. التصدير يسأل القاعدة الآن.
 * Both endpoints exist because export read the scroll position instead of the database,
 * and because the only currency list (`/currencies`) is gated by a grant the account
 * form's user does not hold.
 *
 * يتطلب اتصال oracle حيّاً للأسباب نفسها في AccountTreeLazyTest.
 */
function accountExportAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحقيقية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/** عدّاد صفوف لكل مستوى داخل استجابة التصدير / per-level row counts of an export body. */
function accountExportLevels(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        $counts[$row['level']] = ($counts[$row['level']] ?? 0) + 1;
    }

    return $counts;
}

test('export and currency-option endpoints are sealed behind auth', function () {
    // الرفض يسبق المتحكم: بلا جلسة لا يُسحب صف من CHART_ACC
    $this->getJson('/api/accounts/export-tree')->assertStatus(401);
    $this->getJson('/api/accounts/currencies')->assertStatus(401);
});

test('export without levels emits every level, not just the main accounts', function () {
    Sanctum::actingAs(accountExportAdminUser(), ['*']);

    $response = $this->getJson('/api/accounts/export-tree');
    $response->assertStatus(200)->assertJson(['success' => true]);

    $rows = $response->json('data');
    $counts = accountExportLevels($rows);

    // هذا هو جوهر الشكوى: كان التصدير يعطي الحسابات الرئيسيه فقط.
    // The reported bug was "only main accounts are printed" — so every level must be
    // present, and the financial leaves must be the bulk of the payload.
    expect($counts)->toHaveKeys(['account', 'main', 'sub', 'group', 'chart'])
        ->and($counts['account'])->toBe(4)
        ->and($counts['main'])->toBe(24)
        ->and($counts['sub'])->toBe(95)
        ->and($counts['chart'])->toBeGreaterThan(0);

    // meta.level_counts يجب أن يطابق الصفوف المرسلة فعلياً، لا أن يروّج رقماً آخر
    foreach ($response->json('meta.level_counts') as $level => $count) {
        expect((int) $count)->toBe($counts[$level] ?? 0);
    }
    expect((int) $response->json('meta.total'))->toBe(count($rows));
});

test('export honours the levels the user selected', function () {
    Sanctum::actingAs(accountExportAdminUser(), ['*']);

    $only = $this->getJson('/api/accounts/export-tree?levels=account,sub');
    $only->assertStatus(200);

    $counts = accountExportLevels($only->json('data'));
    expect(array_keys($counts))->toBe(['account', 'sub'])
        ->and($counts['sub'])->toBe(95);

    // مستوى غير معروف يُتجاهل: يبقى المطلوب صالحاً ولا تنفجر المطبوعة
    $partial = $this->getJson('/api/accounts/export-tree?levels=account,bogus');
    $partial->assertStatus(200);
    expect(accountExportLevels($partial->json('data')))->toHaveKey('account')
        ->and($partial->json('meta.levels'))->toBe(['account']);

    // ولا مستوى صالح واحد = كل المستويات، لأن نطاقاً فارغاً كان يعطي ملفاً فارغاً
    $fallback = $this->getJson('/api/accounts/export-tree?levels=bogus,stillnotalevel');
    $fallbackCounts = accountExportLevels($fallback->json('data'));
    expect($fallback->json('meta.levels'))->toBe(['account', 'main', 'sub', 'group', 'chart'])
        ->and($fallbackCounts)->toHaveKeys(['account', 'main', 'sub', 'group', 'chart']);
});

test('export balances equal the tree balances for the same node', function () {
    Sanctum::actingAs(accountExportAdminUser(), ['*']);

    // الجزء 123: أكبر تجميع في القاعدة، ومطابقته مع الشجرة تُلغي احتمال نسختين مختلفتين
    $tree = $this->getJson('/api/accounts/tree')->assertStatus(200)->json('data');

    $treeSub = null;
    $walk = function (array $nodes) use (&$walk, &$treeSub) {
        foreach ($nodes as $node) {
            if (($node['level'] ?? null) === 'sub' && (int) $node['acc_part'] === 123) {
                $treeSub = $node;
            }
            if (! empty($node['children'])) {
                $walk($node['children']);
            }
        }
    };
    $walk($tree);
    expect($treeSub)->not->toBeNull();

    $export = $this->getJson('/api/accounts/export-tree?levels=sub')->assertStatus(200);
    $expSub = null;
    foreach ($export->json('data') as $row) {
        if ((int) $row['acc_part'] === 123) {
            $expSub = $row;
        }
    }
    expect($expSub)->not->toBeNull()
        ->and(abs((float) $expSub['debit'] - (float) $treeSub['debit']))->toBeLessThan(0.01)
        ->and(abs((float) $expSub['credit'] - (float) $treeSub['credit']))->toBeLessThan(0.01)
        ->and(abs((float) $expSub['balance'] - (float) $treeSub['balance']))->toBeLessThan(0.01)
        ->and((int) $expSub['movements'])->toBe((int) $treeSub['movements']);
});

test('customers accounts are excluded by default and included on request', function () {
    Sanctum::actingAs(accountExportAdminUser(), ['*']);

    // الافتراضي: جزء العملاء يظهر مجمّعاً لكن حسابه الكبودي فقط هو المسحوب
    $default = $this->getJson('/api/accounts/export-tree?levels=sub,chart')->assertStatus(200);
    $chart = array_filter($default->json('data'), fn ($r) => $r['level'] === 'chart');
    $customers = array_filter($chart, fn ($r) => substr((string) $r['acc_no'], 0, 3) === '123');

    expect($customers)->toHaveCount(1)
        ->and((int) reset($customers)['acc_no'])->toBe(12310000);

    // الطلب الصريح يعيد كل حسابات العملاء (أكثر من عشرين ألفاً)
    $with = $this->getJson('/api/accounts/export-tree?levels=chart&include_customers=1');
    $with->assertStatus(200);

    $real = (int) ((array) DB::connection('oracle')->selectOne(
        "SELECT COUNT(*) AS C FROM CHART_ACC WHERE SUBSTR(TO_CHAR(ACC_NO), 1, 3) = '123'"
    ))['c'];
    $all = $with->json('data');
    // قاعدة البادئة لا القسمة على 100000: حسابات العملاء ثمانية وتسعة أرقام معاً، والقسمة
    // كانت تُسقط العشرة آلاف حساب الطويلة وتوهم بأن التصدير ناقص.
    $under123 = array_filter($all, fn ($r) => substr((string) $r['acc_no'], 0, 3) === '123');

    expect(count($under123))->toBe($real)
        ->and((int) $with->json('meta.include_customers'))->toBe(1)
        // الكبودي يبقى موجوداً في الحالتين: لا يُفقد لأنه يمثل المجموعة في العرض
        ->and(array_column($all, 'acc_no'))->toContain(12310000);
});

test('export can be scoped to one section and can hide zero balances', function () {
    Sanctum::actingAs(accountExportAdminUser(), ['*']);

    // القسم الذي يحوي الجزء 110 معروف بأنه مليء بالحركات، فالنطاق عليه له معنى
    $one = (int) ((array) DB::connection('oracle')->selectOne(
        'SELECT m.ACC_NO AS A FROM ACC_MAIN m WHERE m.ACC_SUB = (SELECT ACC_SUB FROM ACC_SUB WHERE ACC_PART = 110)'
    ))['a'];
    expect($one)->toBeGreaterThan(0);

    $scoped = $this->getJson('/api/accounts/export-tree?section='.$one)->assertStatus(200);
    expect($scoped->json('data'))->not->toBeEmpty();
    foreach ($scoped->json('data') as $row) {
        // كل صف داخل القسم المطلوب: acc_no يعني رقم الحساب المالي في مستوى الأوراق، لذا
        // المقارنة تتم على root_acc_no وحده — التسرّب كان يعني مطبوعة قسم آخر.
        expect((int) $row['root_acc_no'])->toBe($one);
    }
    expect((int) $scoped->json('meta.section'))->toBe($one);

    // قسم غير موجود يطبع شيئاً لا بيانات قسم آخر
    $none = $this->getJson('/api/accounts/export-tree?section=999999')->assertStatus(200);
    expect($none->json('data'))->toBeEmpty()
        ->and((int) $none->json('meta.total'))->toBe(0);

    // إخفاء الأرصدة الصفرية يحذف الصفوف المعروضة فقط، ولا يمسّ التجميع
    $hidden = $this->getJson('/api/accounts/export-tree?levels=sub&hide_zero=1')->assertStatus(200);
    $plain = $this->getJson('/api/accounts/export-tree?levels=sub')->assertStatus(200);

    $zeroes = array_filter($plain->json('data'), fn ($r) => abs((float) $r['balance']) < 0.005);
    foreach ($hidden->json('data') as $row) {
        expect(abs((float) $row['balance']))->toBeGreaterThanOrEqual(0.005);
    }
    expect(count($hidden->json('data')))->toBe(count($plain->json('data')) - count($zeroes))
        ->and((bool) $hidden->json('meta.hide_zero'))->toBeTrue();
});

test('every exported row carries the fields the document needs', function () {
    Sanctum::actingAs(accountExportAdminUser(), ['*']);

    $rows = $this->getJson('/api/accounts/export-tree?levels=account,chart')
        ->assertStatus(200)->json('data');

    foreach ($rows as $row) {
        expect($row)->toHaveKeys([
            'depth', 'level', 'code', 'name', 'parent_path', 'code_path',
            'debit', 'credit', 'balance', 'movements', 'account_count', 'root_acc_no',
        ]);
        // العمق يقل الترتيب الهرمي: 0 للقسم و4 للحساب المالي داخل مجموعة
        expect((int) $row['depth'])->toBeGreaterThanOrEqual(0);
        if ($row['level'] === 'chart') {
            expect($row)->toHaveKeys(['acc_no', 'seq', 'limited_balance', 'stopping_acc', 'acc_date', 'cur_no'])
                ->and((int) $row['depth'])->toBeGreaterThan(0);
        }
    }
});

test('currency options return the directory the form needs', function () {
    Sanctum::actingAs(accountExportAdminUser(), ['*']);

    $response = $this->getJson('/api/accounts/currencies');
    $response->assertStatus(200)->assertJson(['success' => true]);

    $data = $response->json('data');
    expect($data)->not->toBeEmpty()
        ->and((int) $response->json('meta.total'))->toBe(count($data));

    $real = (int) ((array) DB::connection('oracle')->selectOne('SELECT COUNT(*) AS C FROM CURRENCY'))['c'];
    expect(count($data))->toBe($real);

    foreach ($data as $row) {
        expect($row)->toHaveKeys(['cur_no', 'cur_name'])
            ->and($row['cur_no'])->toBeInt()
            ->and($row['cur_name'])->toBeString()->not->toBe('')
            // الاسم العربي Must not come back as CP1256 bytes: the picker would render mojibake
            ->and($row['cur_name'])->not->toMatch('/[ÂÃÍ]/u');
    }

    // ريال أولاً: الترتيب بالرقم لا بالاسم، لأن CUR_NO هو ما يُخزَّن في الحساب
    $numbers = array_column($data, 'cur_no');
    $sorted = $numbers;
    sort($sorted);
    expect($numbers)->toBe($sorted);
});
