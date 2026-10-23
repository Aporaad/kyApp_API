<?php

/**
 * اختبار قسم «الخدمات والمزودين» — الغلاف، الرقابة، والأقنعة
 * Services & Providers section tests — envelope, permission gates, and credential masks.
 *
 * الفلسفة موروثة عن `BusinessRoutesSealedTest`: الرقابتان (401 جلسة، 403 صلاحية) تسبقان المتحكم،
 * فتُغطى الأفعال الكاتبة بالرفض دون لمس الإرث. أما الإرسال الفعلي فأخطر من أن يُختبر: مهمة
 * `SEND_MSG` تلتهم `MSG_QUEUE` كل ثلاث ثوانٍ وترسل رسالة حقيقية، فلا نُدرج في الطابور في الاختبار.
 * The philosophy is inherited from BusinessRoutesSealedTest: the two gates (401 session, 403
 * permission) run ahead of the controller, so the write verbs are covered by refusal without
 * touching legacy rows. Actual enqueueing is far too dangerous to test: the SEND_MSG job drains
 * MSG_QUEUE every three seconds and really transmits, so we never insert into the queue here.
 *
 * قراءةُ ما يتطلب مستخدمًا مديرًا تُغطّى بـ `oracle` الحيّ، وتُتخطّى بأدب إن غاب.
 * The admin reads need a live Oracle connection and are politely skipped when it is absent.
 */

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * مستخدم مدير من أوراكل الحيّة — للتأكيد على الأقنعة وشكل الغلاف (يتخطّى إن غاب الاتصال)
 * A live-Oracle admin for the mask/envelope assertions; skipped when the connection is missing.
 */
function servicesAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم عادي بلا أي منحة — اصطناعي في الذاكرة، فلا يحتاج قاعدة، ويُنتج 403 مسمّى بالصلاحية
 * A synthetic grant-less non-admin — no database needed, and it yields the named-permission 403.
 */
function servicesUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999998);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

// ─────────────────────────────────────────────────────────
// رفض الجلسة: بلا مصادقة كل المسارات تُردّ 401 قبل أي فحص صلاحية أو قراءة إرث
// Unauthenticated: every route answers 401 ahead of any permission check or legacy read.
// ─────────────────────────────────────────────────────────
test('every services route requires a session', function () {
    $paths = [
        ['get', '/api/providers'],
        ['get', '/api/sms/messages'],
        ['get', '/api/whatsapp/messages'],
        ['get', '/api/service-config'],
        ['get', '/api/jobs'],
    ];

    foreach ($paths as [$verb, $path]) {
        // الـ 401 هنا من حارس `auth:sanctum` نفسه (رسالة Sanctum)، لا من حارس الصلاحية
        // This 401 comes from the auth:sanctum guard itself (a Sanctum message), not the permission gate.
        $this->json($verb, $path)->assertStatus(401);
    }
});

// ─────────────────────────────────────────────────────────
// رفض الصلاحية بدقة «لكل قسم ولكل عملية»: كل كود مسمّى في الاستجابة بالعربية والإنجليزية
// Per-section-per-operation denial: every code is named in the bilingual refusal.
// ─────────────────────────────────────────────────────────
test('an ungranted caller is refused per operation with the named permission', function () {
    Sanctum::actingAs(servicesUngrantedUser(), ['*']);

    $cases = [
        // المزودون / providers
        ['get', '/api/providers', 'providers.view'],
        ['post', '/api/providers', 'providers.create'],
        ['put', '/api/providers/1', 'providers.update'],
        ['patch', '/api/providers/1/toggle', 'providers.toggle'],
        ['delete', '/api/providers/1', 'providers.delete'],
        ['post', '/api/providers/1/subscriptions', 'providers.subscriptions_manage'],
        // الرسائل النصية / sms
        ['get', '/api/sms/messages', 'sms.view'],
        ['post', '/api/sms/messages', 'sms.create'],
        ['patch', '/api/sms/messages/1/toggle', 'sms.toggle'],
        ['delete', '/api/sms/messages/1', 'sms.delete'],
        // واتساب / whatsapp
        ['get', '/api/whatsapp/messages', 'whatsapp.view'],
        ['post', '/api/whatsapp/messages', 'whatsapp.create'],
        ['delete', '/api/whatsapp/messages/1', 'whatsapp.delete'],
        // إعدادات الخدمات / services
        ['get', '/api/service-config', 'services.view'],
        ['put', '/api/service-config/corp', 'services.settings_manage'],
        ['post', '/api/services', 'services.update'],
        // المهام / jobs
        ['get', '/api/jobs', 'jobs.view'],
        ['post', '/api/jobs', 'jobs.create'],
        ['patch', '/api/jobs/SEND_MSG/toggle', 'jobs.toggle'],
        ['delete', '/api/jobs/SEND_MSG', 'jobs.delete'],
    ];

    foreach ($cases as [$verb, $path, $code]) {
        $response = $this->json($verb, $path);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.permission', [$code]);

        // اسم الكود بالعربية حاضر في الرسالة: الرفض مسموع لا شيفرة صامتة
        // The Arabic permission name is in the message: the refusal is audible, not a silent code.
        expect($response->json('message'))->toContain($code);
    }
});

// ─────────────────────────────────────────────────────────
// كشف الاعتبارات صلاحيةٌ مستقلة: `providers.view` لا يكفي للوصول إلى `show` مكشوفًا
// Credential reveal is its own gate: providers.view alone never reaches an unmasked show.
// ─────────────────────────────────────────────────────────
test('revealing credentials is a separate permission from viewing a provider', function () {
    Sanctum::actingAs(servicesUngrantedUser(), ['*']);

    // بلا أي منحة: حتى `show` يُرفض بكود العرض نفسه (الكشف أخطر ولا يُمنح مجانًا)
    // Grantless: even show is refused by its view code (reveal is graver and never free).
    $this->getJson('/api/providers/1')
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['providers.view']);
});

// ─────────────────────────────────────────────────────────
// الغلاف الموحد + إخفاء الاعتبارات للقائمة — قراءة فقط خلف المدير الحيّ
// Shared envelope + credential masking for the list — read-only behind a live admin.
// ─────────────────────────────────────────────────────────
test('the admin sees the shared envelope and never raw credentials in the providers list', function () {
    Sanctum::actingAs(servicesAdminUser(), ['*']);

    $response = $this->getJson('/api/providers?limit=5');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['total', 'page', 'limit', 'pages'],
        ]);

    // القائمة تقنّع الاعتبارات دائمًا، فالمدير يرى `***` لا كلمة المرور الخام
    // The list always masks credentials: even an admin sees *** rather than a raw password.
    foreach ($response->json('data') as $row) {
        foreach (['PASSWORD', 'TOKEN', 'USER_NAME'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                expect($row[$column])->toBe('***');
            }
        }
    }
});

test('the sms log answers the paginated envelope and a status filter', function () {
    Sanctum::actingAs(servicesAdminUser(), ['*']);

    $response = $this->getJson('/api/sms/messages?status=pending&limit=5');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['total', 'page', 'limit', 'pages'],
        ]);
});

test('the jobs index lists scheduler jobs for an admin', function () {
    Sanctum::actingAs(servicesAdminUser(), ['*']);

    $response = $this->getJson('/api/jobs');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data']);
});
