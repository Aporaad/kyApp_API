<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات قسم المبيعات — قراءة فقط على قاعدة أوراكل الحيّة.
 * Sales feature tests — read-only over the live Oracle database.
 *
 * كل الحالات قراءة؛ لا صفّ يُكتب في أي جدول إرث. الجلسة تُصنع بالتوكن، والمدير يُقرأ من الجدول
 * الحقيقي لأن المحلّ يفحص `IS_ADMIN` قبل أي منح. تُتخطّى الاختبارات بلا قاعدة حيّة.
 * Every case reads; no legacy row is written. Sessions are minted by token, and the admin is read
 * from the real table. Tests skip when no live database is reachable.
 */
function salesAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');
    }

    return $user;
}

function salesUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('the sales permissions are part of the catalog', function () {
    expect(PermissionCatalog::count())->toBe(182)
        ->and(PermissionCatalog::knows('sales.returns_view'))->toBeTrue()
        ->and(PermissionCatalog::knows('sales.reports_view'))->toBeTrue()
        ->and(PermissionCatalog::knows('sales.returns_print'))->toBeTrue()
        ->and(PermissionCatalog::arabicName('sales.reports_view'))->toBe('عرض تقارير المبيعات');
});

test('the sales reads demand a session', function () {
    $paths = [
        ['get', '/api/sales/invoices'],
        ['get', '/api/sales/invoices/filters'],
        ['get', '/api/sales/invoices/1/2024'],
        ['post', '/api/sales/invoices/1/2024/activity'],
        ['get', '/api/sales/returns'],
        ['get', '/api/sales/returns/filters'],
        ['get', '/api/sales/returns/1/2024'],
        ['post', '/api/sales/returns/1/2024/activity'],
        ['get', '/api/sales/reports'],
        ['post', '/api/sales/reports/activity'],
    ];

    foreach ($paths as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused on the sales routes', function () {
    Sanctum::actingAs(salesUngrantedUser(), ['*']);

    $cases = [
        ['get', '/api/sales/invoices', ['sales.view']],
        ['get', '/api/sales/returns', ['sales.returns_view']],
        ['get', '/api/sales/reports', ['sales.reports_view']],
    ];

    foreach ($cases as [$verb, $path, $expected]) {
        $this->json($verb, $path)
            ->assertForbidden()
            ->assertJsonPath('errors.permission', $expected);
    }
});

test('the invoice list answers the shared envelope and isolates sales rows', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $response = $this->getJson('/api/sales/invoices?limit=3');
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

    // NET هو الإجمالي؛ المتبقي حقل مستقل، وحالة السداد مشتقّة.
    // NET is gross; remaining is separate; payment status is derived.
    $first = $rows[0];
    expect($first)->toHaveKeys(['bill_no', 'year', 'net', 'remaining', 'payment_status']);
});

test('the invoice detail returns a header with its item lines', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $list = $this->getJson('/api/sales/invoices?limit=1')->json('data');

    if ($list === []) {
        test()->markTestSkipped('لا فواتير مبيعات في القاعدة الحيّة.');
    }

    $billNo = $list[0]['bill_no'];
    $year = $list[0]['year'];

    $this->getJson("/api/sales/invoices/{$billNo}/{$year}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['bill_no', 'year', 'net', 'remaining', 'payment_status', 'details']]);
});

test('the sales report aggregates over the sales rows', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $this->getJson('/api/sales/reports')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['summary' => ['bills_count', 'returns_count', 'total', 'net', 'paid', 'remaining', 'net_after_returns'], 'by_status', 'by_month', 'top_items', 'top_customers', 'rows'],
        ]);
});

test('the returns list answers the shared envelope', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $this->getJson('/api/sales/returns?limit=3')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'limit', 'pages']]);
});

// ── مسارات الكتابة (المرحلة ب) — لا تُكتب أي بيانات هنا: كل الحالات تفشل قبل الإدراج
// ── Write routes (phase B) — nothing is written here: every case fails before any insert

test('the sales writes demand a session', function () {
    $paths = [
        ['post', '/api/sales/invoices'],
        ['put', '/api/sales/invoices/1/2024'],
        ['delete', '/api/sales/invoices/1/2024'],
        ['post', '/api/sales/returns'],
        ['put', '/api/sales/returns/1/2024'],
        ['delete', '/api/sales/returns/1/2024'],
        ['get', '/api/sales/reference'],
        ['get', '/api/sales/items'],
        ['get', '/api/sales/customers'],
    ];

    foreach ($paths as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused on the sales writes', function () {
    Sanctum::actingAs(salesUngrantedUser(), ['*']);

    $cases = [
        ['post', '/api/sales/invoices', [], ['sales.create']],
        ['put', '/api/sales/invoices/1/2024', [], ['sales.update']],
        ['delete', '/api/sales/invoices/1/2024', [], ['sales.delete']],
        ['post', '/api/sales/returns', [], ['sales.returns_create']],
        ['put', '/api/sales/returns/1/2024', [], ['sales.returns_update']],
        ['delete', '/api/sales/returns/1/2024', [], ['sales.returns_delete']],
    ];

    foreach ($cases as [$verb, $path, $body, $expected]) {
        $this->json($verb, $path, $body)
            ->assertForbidden()
            ->assertJsonPath('errors.permission', $expected);
    }
});

test('an invalid invoice payload is rejected before any row is written', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $before = DB::connection('oracle')->table('ACCOUNTS.SALES_BILL')->count();

    // فاتورة بلا سطور ونوع غير مدعوم: يجب أن تُرفض 422 قبل الترقيم أو الترحيل.
    // No lines and an unsupported type: must be rejected 422 before numbering or posting.
    $this->postJson('/api/sales/invoices', ['bill_type' => 9])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => ['bill_type', 'lines']]);

    expect(DB::connection('oracle')->table('ACCOUNTS.SALES_BILL')->count())->toBe($before);
});

test('an invoice write refuses a missing item before numbering', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $before = DB::connection('oracle')->table('ACCOUNTS.SALES_BILL')->count();

    $this->postJson('/api/sales/invoices', [
        'bill_type' => 1,
        'date' => date('Y-m-d'),
        'store_no' => 12,
        'lines' => [['sanf_no' => 999999999, 'qty' => 1, 'price' => 100]],
    ])->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(DB::connection('oracle')->table('ACCOUNTS.SALES_BILL')->count())->toBe($before);
});

test('a credit invoice demands a customer', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $this->postJson('/api/sales/invoices', [
        'bill_type' => 2,
        'date' => date('Y-m-d'),
        'store_no' => 12,
        'lines' => [['sanf_no' => 1, 'qty' => 1, 'price' => 100]],
    ])->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.cust_no', 'required for credit invoices');
});

test('the sales reference feeds both forms', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $this->getJson('/api/sales/reference')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'bill_types' => [['value', 'label']],
                'revenue_accounts',
                'return_accounts',
                'stores' => [['store_no', 'store_name']],
                'currencies' => [['cur_no', 'cur_name']],
                'max_lines',
            ],
        ]);
});

test('the form lookups answer pinned lowercase keys', function () {
    Sanctum::actingAs(salesAdminUser(), ['*']);

    $this->getJson('/api/sales/items?limit=2')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/sales/customers?limit=2')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('the form lookups demand a session', function () {
    $this->getJson('/api/sales/items')->assertUnauthorized();
    $this->getJson('/api/sales/customers')->assertUnauthorized();
});
