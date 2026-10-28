<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * اختبار ختم مسارات الأعمال (مرحلة 7 — القرار D3)
 * Business-route sealing tests (phase 7 — decision D3).
 *
 * كل الحالات قراءة فقط أو رفض قبل القراءة: لا تُنشئ صفاً في جداول الإرث. المسارات التي تكتب
 * تُغطّى برفض الصلاحيات (403) ورفض الجلسة (401) لأن الرقابتين تسبقان المتحكم أصلاً.
 * Everything here either reads or is refused before it reads: no legacy row is written. The
 * writing verbs are covered by the 403/401 refusals, both of which run ahead of the controller.
 *
 * يتطلب اتصال `oracle` حياً لأن المستخدم المدير يُقرأ من الجدول الحقيقي.
 * Needs a live Oracle connection because the admin user is read from the real table.
 */
function sealedAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم حقيقي بلا منح: موجود في `APP_USERS` ولا صلاحيات له في جدول المنح
 * A real user with no grants: present in `APP_USERS`, nothing in the grant table.
 */
function sealedUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('no business route is public any more', function () {
    // القائمة صارت عقداً: ما عدا الدخولَين وفحص الاتصال ونقطتَي تهيئة التطبيق العامتين (قراءة
    // الحمولة بلا سر، وتحقق كلمة سر API مقيّد المعدل)، ومسار إرسال استفسار الدعم العام (مقيّد
    // المعدل أيضاً كي يعمل النموذج قبل الدخول)، كل مسار أعمال يتطلب جلسة.
    // The public surface is a contract: apart from the two sign-in verbs, the connectivity probe,
    // the two public app-config routes (the secret-free payload read and the rate-limited API-password
    // verify), and the rate-limited public support-inquiry submit (so the form works before login),
    // every business route demands a session.
    $public = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        if (! in_array('auth:sanctum', $middleware, true)) {
            $public[] = $route->uri();
        }
    }

    $found = array_values(array_unique($public));
    sort($found);

    $allowed = [
        'api/app-config',
        'api/app-config/verify-api-password',
        'api/auth/biometric/login',
        'api/auth/login',
        'api/hello',
        'api/support/inquiries',
    ];

    expect($found)->toBe($allowed);
});

test('every sealed business route also carries a permission guard', function () {
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        if (! in_array('auth:sanctum', $middleware, true)) {
            continue; // عامة بالتصميم، مغطّاة في الاختبار السابق
        }

        // مسارات الجلسة الذاتية بلا صلاحية مقصود: معرفة المرء بمنح نفسه لا يجوز أن تكون منحاً
        // Self-session routes deliberately need no grant: learning one's own permissions must not
        // itself be a permission, or a user with none could never discover that fact.
        $selfService = [
            'api/auth/logout',
            'api/auth/me',
            'api/auth/permissions',
            'api/user',
            'api/auth/biometric/register',
            'api/auth/biometric/revoke',
        ];

        if (in_array($uri, $selfService, true)) {
            continue;
        }

        $hasPermission = (bool) array_filter(
            $middleware,
            static fn (string $m): bool => str_starts_with($m, 'permission:')
        );

        if (! $hasPermission) {
            $missing[] = $uri;
        }
    }

    expect($missing)->toBe([]);
});

test('sealed business verbs refuse an unauthenticated caller', function () {
    $samples = [
        ['get', '/api/customers'],
        ['get', '/api/customers/1'],
        ['get', '/api/customers/1/statement'],
        ['post', '/api/customers'],
        ['put', '/api/customers/1'],
        ['delete', '/api/customers/1'],
        ['get', '/api/workers/employees'],
        ['get', '/api/workers/cutters'],
        ['get', '/api/workers/tailors'],
        ['get', '/api/workers/cutters/1/work-report'],
        ['get', '/api/workers/tailors/1/work-report'],
        ['get', '/api/workers/cutters/group-work-report'],
        ['get', '/api/workers/tailors/group-work-report'],
        ['patch', '/api/workers/employees/1/toggle'],
        ['get', '/api/accounts/tree'],
        ['get', '/api/accounts/chart'],
        ['post', '/api/accounts/chart'],
        ['get', '/api/accounts/statement-detail'],
        ['get', '/api/accounts/group-statement'],
        ['get', '/api/currencies'],
        ['get', '/api/currencies/1/prices'],
        ['get', '/api/invoices'],
        ['get', '/api/invoices/1'],
        ['get', '/api/tailoring/bills'],
        ['get', '/api/tailoring/bills/1/2026'],
        ['get', '/api/tailoring/bills/1/2026/movements?seq_nos=1'],
        ['get', '/api/tailoring/reference'],
        ['post', '/api/tailoring/bills/1/2026/activity'],
        ['get', '/api/my-statement/financial'],
        ['get', '/api/my-statement/work'],
        ['get', '/api/support/inquiries'],
        ['put', '/api/support/inquiries/1'],
        ['get', '/api/selectFromTable?table_name=CURRENCY&limit=1'],
    ];

    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused per module with the named permission', function () {
    Sanctum::actingAs(sealedUngrantedUser(), ['*']);

    // الصلاحية المطلوبة تُذكر بالاسم في الاستجابة: من يقرأ الرفض يعرف ما ينقصه بلا تصوير الشاشة
    // The required code is named in the response, so whoever reads the refusal knows what is
    // missing without a screenshot of the client.
    // البند الثالث نصّ (صلاحية واحدة) أو قائمة (مسار «أيٍّ من» متعدد الأكواد)؛ والرفض يعيد كل
    // الأكواد المطلوبة كما هي، فالاختبار يطابق القائمة كاملة لا عنصراً واحداً.
    // The third element is a single code or a list (any-of routes); the refusal echoes every
    // required code, so the test matches the whole list, not one element.
    $cases = [
        ['get', '/api/customers', 'customers.view'],
        ['post', '/api/customers', 'customers.create'],
        ['delete', '/api/customers/1', 'customers.delete'],
        ['get', '/api/customers/1/statement', 'customers.statement'],
        ['get', '/api/workers/employees', 'employees.view'],
        ['get', '/api/workers/cutters/1/statement', 'cutters.statement_financial'],
        ['get', '/api/workers/cutters/1/work-report', 'cutters.statement_work'],
        ['get', '/api/workers/tailors/1/work-report', 'tailors.statement_work'],
        ['get', '/api/workers/cutters/group-work-report', 'cutters.group_work_report'],
        ['get', '/api/workers/tailors/group-work-report', 'tailors.group_work_report'],
        ['patch', '/api/workers/tailors/1/toggle', 'tailors.toggle'],
        ['get', '/api/accounts/tree', [
            'accounts.view',
            'accounts.structure_manage',
            'accounts.statement_group',
            'accounts.statement_detail',
        ]],
        ['post', '/api/accounts/sub-group', 'accounts.structure_manage'],
        ['get', '/api/accounts/chart/1/statement', 'accounts.statement_financial'],
        ['get', '/api/accounts/group-statement', 'accounts.statement_group'],
        ['get', '/api/currencies/1/prices', 'currencies.prices_view'],
        ['put', '/api/currencies/1', 'currencies.update'],
        ['get', '/api/invoices', 'invoices.view'],
        ['get', '/api/tailoring/bills', 'tailoring.view'],
        ['get', '/api/tailoring/bills/1/2026', 'tailoring.view'],
        ['get', '/api/tailoring/bills/1/2026/movements?seq_nos=1', 'tailoring.view'],
        ['get', '/api/tailoring/reference', ['tailoring.view', 'tailoring.create']],
        ['post', '/api/tailoring/bills/1/2026/activity', ['tailoring.print', 'tailoring.export']],
        ['get', '/api/my-statement/financial', 'my_statement.financial'],
        ['get', '/api/my-statement/work', 'my_statement.work'],
        ['get', '/api/support/inquiries', 'support.view'],
        ['put', '/api/support/inquiries/1', 'support.update'],
        ['get', '/api/selectFromTable?table_name=CURRENCY&limit=1', 'system.*'],
    ];

    foreach ($cases as [$verb, $path, $code]) {
        $expected = is_array($code) ? $code : [$code];
        $response = $this->json($verb, $path);

        $response->assertForbidden()
            ->assertJsonPath('errors.permission', $expected);
    }
});

test('the system administrator still reaches the sealed reads', function () {
    Sanctum::actingAs(sealedAdminUser(), ['*']);

    // تجاوز المدير ليس ثغرة في الختم: هو البند الذي يمنع قفل النظام على نفسه عند أول إغلاق
    // The admin bypass is not a hole in the seal: it is the clause that keeps a fresh seal from
    // locking the system out of itself.
    $this->getJson('/api/customers?limit=1')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/accounts/chart?limit=1')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/currencies')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/invoices?limit=1')->assertOk()->assertJsonPath('success', true);
    $this->getJson('/api/selectFromTable?table_name=CURRENCY&limit=1')->assertOk();
});

test('invoices now answer the shared envelope instead of a raw collection', function () {
    Sanctum::actingAs(sealedAdminUser(), ['*']);

    $response = $this->getJson('/api/invoices?limit=2');

    // كان المسار يعيد مصفوفة عارية: لا `success` ولا ترميم عربية، فالاسم يصل بايتات مكسورة
    // The endpoint used to hand back a bare collection: no `success` key and no encoding repair,
    // so Arabic customer names arrived as raw broken bytes.
    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['total', 'page', 'limit', 'pages'],
        ]);

    // البنية الداخلية للصفوف تُفحص عند وجود صفّ واحد على الأقل: جدول الإرث قد يكون فارغاً في بيئة
    // ما، والاختبار حينها يثبت الغلاف لا يطالب ببيانات لا يملكها.
    // Row shape is asserted only when a row exists: the legacy table may legitimately be empty in
    // some environment, and the test proves the envelope, not the data.
    $rows = $response->json('data');
    expect($rows)->toBeArray();

    if ($rows !== []) {
        $upper = array_map('strtoupper', array_keys($rows[0]));

        // المفتاح المطلوب لا ترتيبه: أوراكل قد تعيد الأسماء بأي حالة أحرف حسب مسار القراءة
        // The required keys, not their order: Oracle hands names in either case per read path.
        foreach (['BILL_NO', 'CUST_NAME', 'NET', 'AMOUNT_PAID', 'REMAINING'] as $key) {
            expect($upper)->toContain($key);
        }
    }

    foreach ($rows as $row) {
        $name = (string) ($row['CUST_NAME'] ?? '');

        if ($name !== '') {
            expect($name)->not->toMatch('/[ÃÂ¿ÎÐ]/');
        }
    }
});
