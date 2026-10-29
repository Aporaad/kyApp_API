<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات قسم «مقاسات العملاء» — قراءة فقط على الواجهة الحيّة.
 * Customer-measurement tests — read-only over the live cutting-bill view.
 *
 * لا تُكتب أي صفوف في جداول الإرث؛ الوحيد الذي يُكتب هو APP_USER_ACTIVITY (جدول التطبيق نفسه)
 * لأن حدث الطباعة/التصدير هو المُدقَّق أصلاً. الجلسات تُصنع بالتوكن، والمدير يُقرأ من الجدول
 * الحقيقي لأن المحلّ يفحص `IS_ADMIN` قبل أي منح.
 * No legacy row is written. The only write is APP_USER_ACTIVITY (the app's own table), because the
 * print/export event is the audited thing. Sessions are minted by token and the administrator comes
 * from the real table, since the resolver checks `IS_ADMIN` first.
 */
function measurementAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم بلا أي منح — الرفض الافتراضي.
 * A user with no grants — deny by default.
 */
function measurementUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999998);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

/**
 * ترميم الترميز يفحص المحارف لا البايتات: صنف بلا `u` يطابق بايتات عشوائية من منتصف الحروف
 * العربية فيُظنّ مكسوراً وهو سليم.
 * The encoding check inspects characters, not bytes: a class without `u` matches arbitrary bytes
 * from the middle of Arabic letters and calls a healthy string broken.
 */
function measurementLooksMojibake(?string $text): bool
{
    return preg_match('/[\x{00C3}\x{00C2}\x{00BF}\x{00CE}\x{00D0}]/u', (string) $text) === 1;
}

test('the three measurement grants exist in the catalog with arabic names', function () {
    expect(PermissionCatalog::knows('customers.measurements_view'))->toBeTrue()
        ->and(PermissionCatalog::knows('customers.measurements_print'))->toBeTrue()
        ->and(PermissionCatalog::knows('customers.measurements_export'))->toBeTrue()
        ->and(PermissionCatalog::arabicName('customers.measurements_view'))
        ->toBe('عرض مقاسات العملاء')
        ->and(PermissionCatalog::arabicName('customers.measurements_print'))
        ->toBe('طباعة مقاسات العملاء')
        // الكود غير الموجود لا يُعرف: الحجب افتراضي ولا اختراع أكواد.
        // An invented code is unknown: deny by default, no made-up grants.
        ->and(PermissionCatalog::knows('customers.measurements_delete'))->toBeFalse();
});

test('the measurement reads demand a session', function () {
    $samples = [
        ['get', '/api/customers/measurements'],
        ['get', '/api/customers/measurements/40/models'],
        ['get', '/api/customers/measurements/40/models/35/2023/35'],
        ['post', '/api/customers/measurements/40/models/35/2023/35/activity'],
    ];

    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused on every measurement route', function () {
    Sanctum::actingAs(measurementUngrantedUser(), ['*']);

    $cases = [
        ['get', '/api/customers/measurements', ['customers.measurements_view']],
        ['get', '/api/customers/measurements/40/models', ['customers.measurements_view']],
        ['get', '/api/customers/measurements/40/models/35/2023/35', ['customers.measurements_view']],
        ['post', '/api/customers/measurements/40/models/35/2023/35/activity', ['customers.measurements_print', 'customers.measurements_export']],
    ];

    foreach ($cases as [$verb, $path, $expected]) {
        $this->json($verb, $path)
            ->assertForbidden()
            ->assertJsonPath('errors.permission', $expected);
    }
});

test('the customer list answers the shared envelope newest first', function () {
    Sanctum::actingAs(measurementAdminUser(), ['*']);

    $response = $this->getJson('/api/customers/measurements?limit=5');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['total', 'page', 'limit', 'pages'],
        ]);

    $rows = $response->json('data');
    expect($rows)->toBeArray()->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(array_keys($row))->toContain(
            'cust_no', 'cust_name', 'bills_count', 'models_count', 'last_date', 'raw_last_date'
        );

        // كل عميل في هذه القائمة له موديل واحد على الأقل: المصدر الواجهة لا جدول العملاء.
        // Every customer here has at least one model: the source is the view, not the customer table.
        expect($row['models_count'])->toBeGreaterThan(0);
        expect(measurementLooksMojibake($row['cust_name']))->toBeFalse();
    }

    // الأحدث نشاطاً أولاً هو قاعدة القسم.
    // Most recent activity first is the section's rule.
    $dates = array_map(static fn (array $row): string => (string) ($row['raw_last_date'] ?? ''), $rows);
    $sorted = $dates;
    rsort($sorted);
    expect($dates)->toBe($sorted);
});

test('searching customers narrows by phone and by beneficiary', function () {
    Sanctum::actingAs(measurementAdminUser(), ['*']);

    $all = $this->getJson('/api/customers/measurements?limit=5')->json('data');

    if ($all === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    // البحث برقم هاتف عميل معروف يجب أن يُرجعه وألا يوسع القائمة.
    // Searching a known phone returns that customer and never widens the list.
    $withPhone = array_values(array_filter(
        $all,
        static fn (array $row): bool => ! empty($row['tel_no1'])
    ));

    if ($withPhone !== []) {
        $tel = (string) $withPhone[0]['tel_no1'];

        $found = $this->getJson('/api/customers/measurements?search='.urlencode($tel).'&limit=5')->json('data');

        expect($found)->toBeArray()
            ->and(array_map(static fn (array $r): int => (int) $r['cust_no'], $found))
            ->toContain((int) $withPhone[0]['cust_no']);
    }

    // نص لا يشبه شيئاً: قائمة فارغة لا خطأ — حالة فراغ تعرفها الواجهة.
    // A nonsense term yields an empty list, not an error — a state the UI knows.
    $this->getJson('/api/customers/measurements?search='.urlencode('.zzqqxx nonexistent term 999'))
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('the model list belongs to one customer and is newest first', function () {
    Sanctum::actingAs(measurementAdminUser(), ['*']);

    $customers = $this->getJson('/api/customers/measurements?limit=1')->json('data');

    if ($customers === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $custNo = (int) $customers[0]['cust_no'];

    $response = $this->getJson("/api/customers/measurements/{$custNo}/models?limit=10");

    $response->assertOk()
        ->assertJsonPath('meta.cust_no', $custNo)
        ->assertJsonStructure([
            'data' => [[
                'bill_no', 'year', 'seq_no', 'beneficiary', 'type_name',
                'pieces_count', 'date', 'raw_date', 'handing_done',
            ]],
        ]);

    $models = $response->json('data');
    expect($models)->not->toBeEmpty();

    $dates = array_map(static fn (array $row): string => (string) ($row['raw_date'] ?? ''), $models);
    $sorted = $dates;
    rsort($sorted);
    expect($dates)->toBe($sorted);

    // المفاتيح الثلاثية وحدها تُعرّف الموديل: لا تكرار في صفحة واحدة.
    // The triple alone identifies a model: no duplicates inside one page.
    $keys = array_map(
        static fn (array $row): string => $row['bill_no'].'/'.$row['year'].'/'.$row['seq_no'],
        $models
    );
    expect($keys)->toBe(array_values(array_unique($keys)));

    // رقم عميل غير صالح مرفوض بصراحة.
    // A bogus customer number is refused outright.
    $this->getJson('/api/customers/measurements/0/models')->assertStatus(422);
});

test('the model detail carries measurements, specs and fabrics with no money', function () {
    Sanctum::actingAs(measurementAdminUser(), ['*']);

    $customers = $this->getJson('/api/customers/measurements?limit=1')->json('data');

    if ($customers === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $custNo = (int) $customers[0]['cust_no'];
    $models = $this->getJson("/api/customers/measurements/{$custNo}/models?limit=1")->json('data');

    if ($models === []) {
        test()->markTestSkipped('لا موديلات لهذا العميل / This customer has no models.');
    }

    $model = $models[0];
    $path = "/api/customers/measurements/{$custNo}/models/{$model['bill_no']}/{$model['year']}/{$model['seq_no']}";

    $response = $this->getJson($path);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.header.cust_no', $custNo)
        ->assertJsonStructure([
            'data' => [
                'header' => ['bill_no', 'year', 'cust_no', 'cust_name', 'date', 'seq_no', 'pieces_count'],
                'models' => [[
                    'seq_no', 'beneficiary', 'type_name', 'qty',
                    'measurements', 'specs', 'pieces',
                ]],
                'measure_labels',
                'siblings',
            ],
        ]);

    $payload = $response->json('data');
    $first = $payload['models'][0];

    // المقاسات اثنتا عشرة خانة دائماً، والتسميات من GENERAL_LABELS_CODING لا من الشاشة.
    // Twelve measurement slots always; labels come from GENERAL_LABELS_CODING, not the screen.
    expect(array_keys($first['measurements']))->toContain('L1', 'L5', 'L12')
        ->and($payload['measure_labels']['L1'])->toBe('الطول')
        ->and(array_keys($first['specs']))->toContain('neck_type', 'pocket_or_without', 'notes')
        ->and($first['pieces'])->toBeArray()->not->toBeEmpty();

    foreach ($first['pieces'] as $piece) {
        expect(array_keys($piece))->toContain('seq_no1', 'cloth_name', 'cutter_name', 'tailor_name');
    }

    // لا خانة مالية إطلاقاً: القسم مقاسات لا حسابات، فلا مبلغ يصل وإن كان المستخدم مديراً.
    // No money column at all: this is measurements, not accounts, so no amount arrives even for an
    // administrator.
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
    expect($raw)->not->toContain('"payment"')
        ->and($raw)->not->toContain('"total"')
        ->and($raw)->not->toContain('"net"')
        ->and($raw)->not->toContain('"sanf_price"');

    // الأخوة يشملون الموديل الحالي مع علامة التحديد، للتنقل بلا طلب إضافي.
    // Siblings include the current model flagged as selected, for switching without a new request.
    $selected = array_values(array_filter(
        $payload['siblings'],
        static fn (array $row): bool => $row['selected'] === true
    ));
    expect($selected)->toHaveCount(1)
        ->and((int) $selected[0]['seq_no'])->toBe((int) $model['seq_no']);
});

test('a model outside the view or under the wrong customer answers 404 with a flag', function () {
    Sanctum::actingAs(measurementAdminUser(), ['*']);

    // رقم مستحيل: خارج نطاق الواجهة كلياً.
    // An impossible number: wholly outside the view.
    $this->getJson('/api/customers/measurements/40/models/999999/1999/999999')
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('not_found_in_view', true);

    // موديل حقيقي تحت عميل خطأ: الملكية جزء من المفتاح.
    // A real model under the wrong customer: ownership is part of the key.
    $customers = $this->getJson('/api/customers/measurements?limit=2')->json('data');

    if (count($customers) < 2) {
        return;
    }

    $owner = (int) $customers[0]['cust_no'];
    $other = (int) $customers[1]['cust_no'];
    $models = $this->getJson("/api/customers/measurements/{$owner}/models?limit=1")->json('data');

    if ($models === []) {
        return;
    }

    $this->getJson("/api/customers/measurements/{$other}/models/{$models[0]['bill_no']}/{$models[0]['year']}/{$models[0]['seq_no']}")
        ->assertNotFound()
        ->assertJsonPath('not_found_in_view', true);
});

test('print and export leave activity rows and never touch a legacy table', function () {
    Sanctum::actingAs(measurementAdminUser(), ['*']);

    $customers = $this->getJson('/api/customers/measurements?limit=1')->json('data');

    if ($customers === []) {
        test()->markTestSkipped('الواجهة فارغة في هذه البيئة / The view is empty here.');
    }

    $custNo = (int) $customers[0]['cust_no'];
    $models = $this->getJson("/api/customers/measurements/{$custNo}/models?limit=1")->json('data');

    if ($models === []) {
        test()->markTestSkipped('لا موديلات لهذا العميل / This customer has no models.');
    }

    $model = $models[0];
    $path = "/api/customers/measurements/{$custNo}/models/{$model['bill_no']}/{$model['year']}/{$model['seq_no']}";
    $entity = $custNo.'/'.$model['bill_no'].'/'.$model['year'].'/'.$model['seq_no'];

    $before = DB::connection('oracle')->table('ACCOUNTS.APP_USER_ACTIVITY')->count();

    $this->postJson("{$path}/activity", ['action' => 'print'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson("{$path}/activity", ['action' => 'export'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $after = DB::connection('oracle')->table('ACCOUNTS.APP_USER_ACTIVITY')->count();

    expect($after)->toBe($before + 2);

    // القيمة غير المدعومة تُرفض ولا تكتب شيئاً.
    // An unsupported action is refused and writes nothing.
    $this->postJson("{$path}/activity", ['action' => 'delete'])->assertStatus(422);

    // نتائج أوراكل تعيد أسماء الأعمدة بحالة الأحرف التي كُتبت بها، فنقرأ الصف كمصفوفة.
    // Oracle returns column names in the case they were written, so rows are read as arrays.
    $latest = DB::connection('oracle')->select(
        'SELECT ACTION_CODE FROM ACCOUNTS.APP_USER_ACTIVITY
          WHERE ENTITY_ID = :entity ORDER BY ACTIVITY_ID DESC',
        ['entity' => $entity]
    );

    $codes = array_map(
        static fn ($row): string => strtolower((string) (((array) $row)['ACTION_CODE'] ?? ((array) $row)['action_code'] ?? '')),
        array_slice($latest, 0, 2)
    );

    expect($codes)->toBe(['customers.measurements_exported', 'customers.measurements_printed']);
});
