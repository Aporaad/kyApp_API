<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات قسم التفصيل — قراءة فقط على الواجهة الحيّة
 * Tailoring feature tests — read-only over the live cutting-bill view.
 *
 * كل الحالات قراءة: لا صف يُكتب في أي جدول إرث. الجلسة تُصنع بالتوكن لا بكلمة سر، والمستخدم
 * المدير يُقرأ من الجدول الحقيقي لأن المحلّ يفحص `IS_ADMIN` قبل أي منح.
 * Every case reads: no legacy row is written. Sessions are minted by token rather than password,
 * and the administrator is read from the real table because the resolver checks `IS_ADMIN` first.
 */
function tailoringAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم حقيقي بلا أي منح — الرفض الافتراضي
 * A real user with no grants — deny by default.
 */
function tailoringUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('the tailoring permissions are part of the catalog and nowhere near the old count', function () {
    // ثلاث صلاحيات جديدة: عرض التفاصيل المالية، التصدير، ومتابعة السير.
    // Three new grants: payment details, export, and progress tracking.
    expect(PermissionCatalog::count())->toBe(144)
        ->and(PermissionCatalog::knows('tailoring.payments_view'))->toBeTrue()
        ->and(PermissionCatalog::knows('tailoring.export'))->toBeTrue()
        ->and(PermissionCatalog::knows('tailoring.progress'))->toBeTrue()
        ->and(PermissionCatalog::arabicName('tailoring.payments_view'))->toBe('عرض تفاصيل دفع فاتورة التفصيل');
});

test('the tailoring reads demand a session', function () {
    $samples = [
        ['get', '/api/tailoring/bills'],
        ['get', '/api/tailoring/bills/21394/2026'],
        ['get', '/api/tailoring/bills/21394/2026/movements?seq_nos=136054'],
        ['get', '/api/tailoring/reference'],
        ['post', '/api/tailoring/bills/21394/2026/activity'],
    ];

    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused on every tailoring route', function () {
    Sanctum::actingAs(tailoringUngrantedUser(), ['*']);

    $cases = [
        ['get', '/api/tailoring/bills', ['tailoring.view']],
        ['get', '/api/tailoring/bills/21394/2026', ['tailoring.view']],
        ['get', '/api/tailoring/bills/21394/2026/movements?seq_nos=136054', ['tailoring.view']],
        ['get', '/api/tailoring/reference', ['tailoring.view', 'tailoring.create']],
        ['post', '/api/tailoring/bills/21394/2026/activity', ['tailoring.print', 'tailoring.export']],
    ];

    foreach ($cases as [$verb, $path, $expected]) {
        $this->json($verb, $path)
            ->assertForbidden()
            ->assertJsonPath('errors.permission', $expected);
    }
});

test('the bill list answers the shared envelope with newest first', function () {
    Sanctum::actingAs(tailoringAdminUser(), ['*']);

    $response = $this->getJson('/api/tailoring/bills?limit=3');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['total', 'page', 'limit', 'pages'],
        ]);

    $rows = $response->json('data');
    expect($rows)->toBeArray();

    if ($rows === []) {
        return;
    }

    // الترتيب تنازلي بالتاريخ: قاعدة القسم أن الأحدث يظهر أولاً.
    // Newest first is the section's rule.
    $dates = array_map(static fn (array $row): string => (string) ($row['raw_date'] ?? ''), $rows);
    $sorted = $dates;
    rsort($sorted);
    expect($dates)->toBe($sorted);

    // الأسماء العربية تمرّ عبر ترميم الترميز، فلا تُعرض بايتات CP1256 مكسورة.
    // Arabic names pass through the encoding repair, never raw CP1256 bytes.
    //
    // الفحص على المحارف لا على البايتات: صنفٌ بلا `u` يطابق بايتات عشوائية من منتصف الحروف
    // العربية (0x82 مثلاً بايت ثانٍ في «ق») فيفشل الاختبار على نصّ سليم.
    // The check is on characters, not bytes: a class without `u` matches stray continuation
    // bytes inside valid Arabic letters and fails on correct text.
    foreach ($rows as $row) {
        $name = (string) ($row['cust_name'] ?? '');

        if ($name !== '') {
            expect(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
                ->and(preg_match('/[\x{00C3}\x{00C2}\x{00BF}\x{00CE}\x{00D0}]/u', $name))->toBe(0);
        }
    }
});

test('the bill list filters by date range and by free-text search', function () {
    Sanctum::actingAs(tailoringAdminUser(), ['*']);

    $page = $this->getJson('/api/tailoring/bills?limit=1')->json('data');

    if ($page === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $bill = $page[0];
    $date = (string) $bill['raw_date'];

    // نطاق يوم واحد يضمّ الفاتورة نفسها: الفلترة تحترم الوقت داخل اليوم.
    // A single-day window must still contain the bill: the filter respects the time of day.
    $sameDay = $this->getJson('/api/tailoring/bills?from_date='.$date.'&to_date='.$date.'&limit=50')
        ->assertOk()
        ->json('data');

    $numbers = array_map(static fn (array $row): string => $row['bill_no'].'/'.$row['year'], $sameDay);
    expect($numbers)->toContain($bill['bill_no'].'/'.$bill['year']);

    // البحث بالرقم الداخلي يعيد نفس الفاتورة.
    // Searching by the internal number returns the same bill.
    $byNumber = $this->getJson('/api/tailoring/bills?search='.$bill['bill_no'])
        ->assertOk()
        ->json('data');

    $again = array_map(static fn (array $row): string => $row['bill_no'].'/'.$row['year'], $byNumber);
    expect($again)->toContain($bill['bill_no'].'/'.$bill['year']);
});

test('the bill detail folds pieces into models and carries measurements', function () {
    Sanctum::actingAs(tailoringAdminUser(), ['*']);

    $page = $this->getJson('/api/tailoring/bills?limit=1')->json('data');

    if ($page === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $billNo = $page[0]['bill_no'];
    $year = $page[0]['year'];

    $response = $this->getJson("/api/tailoring/bills/{$billNo}/{$year}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.header.bill_no', $billNo)
        ->assertJsonStructure([
            'data' => [
                'header' => [
                    'bill_no', 'year', 'manual_bill_no', 'date', 'cust_name',
                    'ordsdid', 'models_count', 'pieces_count', 'urgent',
                ],
                'payment',
                'models' => [['seq_no', 'beneficiary', 'type_name', 'qty', 'measurements', 'specs', 'pieces']],
                'measure_labels',
            ],
        ]);

    $models = $response->json('data.models');
    expect($models)->toBeArray()->not->toBeEmpty();

    foreach ($models as $model) {
        expect(array_keys($model['measurements']))->toContain('L1', 'L5', 'L12')
            ->and(array_keys($model['specs']))->toContain('neck_type', 'button_type', 'notes')
            ->and($model['pieces'])->toBeArray()->not->toBeEmpty();

        foreach ($model['pieces'] as $piece) {
            expect(array_keys($piece))->toContain('seq_no1', 'cloth_name', 'cutter_name', 'tailor_name', 'cutting_done');
        }
    }

    // التسميات تأتي من GENERAL_LABELS_CODING لا من ثوابت في الشاشة.
    // The labels come from GENERAL_LABELS_CODING, not from constants in the screen.
    expect($response->json('data.measure_labels.L1'))->toBe('الطول')
        ->and($response->json('data.measure_labels.L2'))->toBe('الكتف')
        ->and($response->json('data.measure_labels.L10'))->toBe('طول الكبك');

    // المدير يملك كل الصلاحيات، فخانات المال تصل فعلاً.
    // The admin holds every grant, so the money fields do arrive.
    expect($response->json('data.payment'))->toBeArray()
        ->and(array_keys($response->json('data.payment')))->toContain('total', 'net', 'paid', 'remaining');
});

test('the money block is withheld from the server when the grant is missing', function () {
    // صلاحية view وحدها بلا payments_view: الفاتورة تُعرض والمال لا يصل.
    // `view` alone without `payments_view`: the bill is shown, the money never arrives.
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');
    }

    Sanctum::actingAs($user, ['*']);

    $page = $this->getJson('/api/tailoring/bills?limit=1')->json('data');

    if ($page === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $billNo = $page[0]['bill_no'];
    $year = $page[0]['year'];

    // نحاكي غياب الصلاحية بالمحلّ نفسه: هذه هي نقطة الحجب الحقيقية، وهي تُخدم للوسيط وللمتحكم
    // معاً فتظل بوابة `tailoring.view` مفتوحة ويُحجب المال وحده.
    // The missing grant is simulated at the resolver: that is the real gate point, and it serves
    // both the middleware and the controller, so `tailoring.view` stays open and only money closes.
    $resolver = Mockery::mock(PermissionResolver::class);
    $resolver->shouldReceive('canAny')->andReturnTrue();
    $resolver->shouldReceive('can')->andReturnUsing(
        static fn ($user, string $code): bool => $code !== 'tailoring.payments_view'
    );
    app()->instance(PermissionResolver::class, $resolver);

    $response = $this->getJson("/api/tailoring/bills/{$billNo}/{$year}")->assertOk();

    expect($response->json('data.payment'))->toBeNull()
        // والترويسة تبقى كاملة: الحجب على المال وحده لا على الفاتورة.
        // The header stays whole: only the money is withheld, not the bill.
        ->and($response->json('data.header.cust_name'))->not->toBeNull();
});

test('a bill outside the view answers 404 with an explicit flag', function () {
    Sanctum::actingAs(tailoringAdminUser(), ['*']);

    // رقم فاتورة لا وجود له في الواجهة إطلاقاً.
    // A bill number that does not exist in the view at all.
    $this->getJson('/api/tailoring/bills/999999/1999')
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('not_found_in_view', true);
});

test('the movement log answers the pieces the client already holds', function () {
    Sanctum::actingAs(tailoringAdminUser(), ['*']);

    $page = $this->getJson('/api/tailoring/bills?limit=1')->json('data');

    if ($page === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $billNo = $page[0]['bill_no'];
    $year = $page[0]['year'];
    $detail = $this->getJson("/api/tailoring/bills/{$billNo}/{$year}")->json('data');

    $seqNos = [];
    foreach ($detail['models'] as $model) {
        foreach ($model['pieces'] as $piece) {
            $seqNos[] = $piece['seq_no1'];
        }
    }

    $response = $this->getJson("/api/tailoring/bills/{$billNo}/{$year}/movements?seq_nos=".implode(',', $seqNos));

    $response->assertOk()->assertJsonPath('success', true);

    $movements = $response->json('data');
    expect($movements)->toBeArray();

    foreach ($movements as $movement) {
        expect(array_keys($movement))->toContain('tr_inv_d_id', 'date', 'ords_name', 'qty', 'posted')
            ->and($seqNos)->toContain((int) $movement['seq_no']);
    }

    // طلب بلا أرقام قطع يُرفض بصراحة بدل أن يعيد سجلاً لكل القطع.
    // A request without piece numbers is refused instead of returning the whole log.
    $this->getJson("/api/tailoring/bills/{$billNo}/{$year}/movements")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('the reference payload carries labels, types, shapes and stages', function () {
    Sanctum::actingAs(tailoringAdminUser(), ['*']);

    $response = $this->getJson('/api/tailoring/reference');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.measure_labels.L1', 'الطول')
        ->assertJsonStructure([
            'data' => [
                'measure_labels',
                'types' => [['type_no', 'type_name', 'active']],
                'styles' => [['shape_style_no', 'style_name']],
                'stages' => [['ordsid', 'ordsname']],
            ],
        ]);

    // مراحل السير تأتي من ORDSTATE نفسها فلا تُكتب في العميل قائمة مخترعة.
    // Workflow stages come from ORDSTATE, so the client never invents a list.
    $stages = $response->json('data.stages');
    expect($stages)->not->toBeEmpty();

    foreach ($stages as $stage) {
        expect(preg_match('/[\x{00C3}\x{00C2}\x{00BF}\x{00CE}\x{00D0}]/u', (string) $stage['ordsname']))->toBe(0);
    }
});

test('print and export leave one activity row and never touch a legacy table', function () {
    Sanctum::actingAs(tailoringAdminUser(), ['*']);

    $page = $this->getJson('/api/tailoring/bills?limit=1')->json('data');

    if ($page === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $billNo = $page[0]['bill_no'];
    $year = $page[0]['year'];

    $before = DB::connection('oracle')->table('ACCOUNTS.APP_USER_ACTIVITY')->count();

    $this->postJson("/api/tailoring/bills/{$billNo}/{$year}/activity", ['action' => 'print'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson("/api/tailoring/bills/{$billNo}/{$year}/activity", ['action' => 'export'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $after = DB::connection('oracle')->table('ACCOUNTS.APP_USER_ACTIVITY')->count();

    expect($after)->toBe($before + 2);

    // نتائج أوراكل تعيد أسماء الأعمدة بحالة الأحرف التي كُتبت بها، فنقرأ الصف كمصفوفة.
    // Oracle returns column names in the case they were written, so rows are read as arrays.
    $latest = DB::connection('oracle')
        ->select(
            'SELECT ACTION_CODE FROM ACCOUNTS.APP_USER_ACTIVITY
              WHERE ENTITY_ID = :entity ORDER BY ACTIVITY_ID DESC',
            ['entity' => $billNo.'/'.$year]
        );

    $codes = array_map(
        static fn ($row): string => strtolower((string) (((array) $row)['ACTION_CODE'] ?? ((array) $row)['action_code'] ?? '')),
        array_slice($latest, 0, 2)
    );

    expect($codes)->toBe(['tailoring.bills_exported', 'tailoring.bill_printed']);

    // القيمة غير المدعومة تُرفض ولا تكتب شيئاً.
    // An unsupported action is refused and writes nothing.
    $this->postJson("/api/tailoring/bills/{$billNo}/{$year}/activity", ['action' => 'delete'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});
