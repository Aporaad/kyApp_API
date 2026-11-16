<?php

use App\Models\User;
use App\Support\ArabicEncoding;
use App\Support\MessageQueueWriter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * اختبارات استفسارات الدعم — المسار العام ومسارَي الإدارة
 * Support-inquiry tests — the public submit route and the two administrative routes.
 *
 * كل صفّ يُنشأ هنا يُحذف في نهاية اختباره بـ `ticketId` العائد من الاستجابة، فلا يبقى أثر في
 * جدول التذاكر. وفروع الرفض (401/403/422) ترجع قبل أي كتابة أصلاً.
 * Every row created here is deleted at the end of its test using the `ticketId` the response
 * returned, so nothing is left behind. The refusal branches (401/403/422) return before any write.
 *
 * الإدراج في طابور الرسائل (sms/whatsapp) لا يُختبَر بالمسار السعيد عمداً: مهمة `SEND_MSG`
 * تلتهم الطابور كل ثلاث ثوانٍ وترسل رسالة حقيقية إلى الرقم المرفق — نفس فلسفة
 * `ServicesSectionTest`. ما يُختبر هو فروع الرفض والتحقق، وقاعدة معرّف الطابور ووجهة الإرسال
 * بالقراءة فقط.
 * The message-queue insert is deliberately not tested on its happy path: the SEND_MSG job drains
 * the queue every three seconds and really transmits to the attached number — the same philosophy
 * as ServicesSectionTest. What is covered: the refusal/validation branches, and the queue id
 * rule and the send destination via read-only queries.
 *
 * يتطلب اتصال `oracle` حياً مع تنفيذ 08_app_support_tickets.sql وبذر 02_ibac_seed.php.
 * Needs a live Oracle connection with 08_app_support_tickets.sql applied and 02_ibac_seed.php seeded.
 */

/**
 * مدير النظام الحيّ أو تخطٍّ بلطف
 * The live system administrator, or a graceful skip
 */
function supportAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم بلا منح ⇒ رفض افتراضي (نفس نمط اختبارات IBAC)
 * A user with no grants ⇒ deny by default (same pattern as the IBAC tests)
 */
function supportGhostUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);

    return $user;
}

/**
 * حذف تذكرة أنشأها الاختبار
 * Delete a ticket the test created
 */
function supportDrop(int $ticketId): void
{
    DB::connection('oracle')->delete(
        'DELETE FROM ACCOUNTS.APP_SUPPORT_TICKETS WHERE TICKET_ID = :ticket_id',
        ['ticket_id' => $ticketId]
    );
}

/**
 * قراءة عددية من أوراكل مع تخطٍّ لطيف عند غياب الاتصال
 * A numeric Oracle read with a graceful skip when the connection is absent
 */
function supportScalarOrSkip(string $sql): int
{
    try {
        $row = array_change_key_case((array) DB::connection('oracle')->selectOne($sql), CASE_LOWER);

        return (int) (reset($row) ?: 0);
    } catch (Throwable) {
        test()->markTestSkipped('قاعدة oracle غير متاحة / Oracle database is unavailable.');
    }
}

test('a guest inquiry is refused when the name or the message is missing', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    // الرفض يقع قبل الإدراج: لا تذكرة تصل القاعدة بنص فارغ أو باسم مجهول
    // The refusal happens before the insert: no ticket reaches the database with no text or no name.
    $this->postJson('/api/support/inquiries', [])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['name', 'phone', 'message']);

    $this->postJson('/api/support/inquiries', ['name' => 'زائر', 'phone' => '123', 'message' => 'قصير'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone', 'message']);
});

test('a guest submission stores a ticket with no user id and readable Arabic', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $message = 'أرجو المساعدة في تحديث بيانات حسابي المالي.';

    $response = $this->postJson('/api/support/inquiries', [
        'name' => 'زائر اختبار',
        'phone' => '777 123 456',
        'message' => $message,
        'app_version' => '1.1.0',
        'platform' => 'android',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'new');

    $ticketId = (int) $response->json('data.ticket_id');
    expect($ticketId)->toBeGreaterThan(0);

    // الصف يُقرأ معكوساً: العمود يخزّن بايتات CP1256، و`fixMojibake` تعيده نصاً عربياً سليماً.
    // Round-trip check: the column holds CP1256 bytes, and `fixMojibake` brings Arabic back intact.
    $row = (array) DB::connection('oracle')->selectOne(
        'SELECT USER_ID, CONTACT_NAME, CONTACT_PHONE, MESSAGE, STATUS
           FROM ACCOUNTS.APP_SUPPORT_TICKETS WHERE TICKET_ID = :ticket_id',
        ['ticket_id' => $ticketId]
    );
    $row = array_change_key_case($row, CASE_LOWER);

    expect($row['user_id'])->toBeNull()
        ->and($row['status'])->toBe('new')
        // الهاتف يُطبَّع إلى أرقام فقط قبل الحفظ
        // The phone is normalised to digits only before it is stored.
        ->and((string) $row['contact_phone'])->toBe('777123456')
        ->and(ArabicEncoding::fixMojibake((string) $row['contact_name']))->toBe('زائر اختبار')
        ->and(ArabicEncoding::fixMojibake((string) $row['message']))->toBe($message);

    supportDrop($ticketId);
});

test('a signed-in submitter is bound to the account and needs no name', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $user = supportAdminUser();
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/support/inquiries', [
        'message' => 'استفسار من حساب مسجّل بلا اسم أو هاتف مكتوب.',
    ])->assertOk()
        ->assertJsonPath('success', true);

    $ticketId = (int) $response->json('data.ticket_id');

    $row = array_change_key_case(
        (array) DB::connection('oracle')->selectOne(
            'SELECT USER_ID, CONTACT_NAME FROM ACCOUNTS.APP_SUPPORT_TICKETS WHERE TICKET_ID = :ticket_id',
            ['ticket_id' => $ticketId]
        ),
        CASE_LOWER
    );

    expect((int) $row['user_id'])->toBe((int) $user->getAuthIdentifier())
        // الاسم مُشتقّ من الحساب، فلا يُقبل استفسار مجهول من داخل الجلسة
        // The name is derived from the account, so a signed-in inquiry is never anonymous.
        ->and((string) $row['contact_name'])->not->toBe('');

    supportDrop($ticketId);
});

test('the inquiry list needs the support.view grant', function () {
    $this->getJson('/api/support/inquiries')->assertUnauthorized();

    Sanctum::actingAs(supportGhostUser(), ['*']);

    $this->getJson('/api/support/inquiries')
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['support.view']);

    Sanctum::actingAs(supportAdminUser(), ['*']);

    $this->getJson('/api/support/inquiries?limit=2')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['total', 'page', 'limit', 'pages'],
        ]);

    // الفلاتر غير الصالحة تُتجاهل بدل أن تُسقط الطلب: `status=all` و`status=bogus` تعنيان «بلا فلترة»
    // An invalid filter is ignored rather than fatal: `status=all` and `status=bogus` both mean "no filter".
    $this->getJson('/api/support/inquiries?status=bogus&page=1&limit=1')
        ->assertOk()
        ->assertJsonPath('meta.limit', 1);
});

test('replying needs support.update, refuses an invalid status, and saves a valid one', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    Sanctum::actingAs(supportGhostUser(), ['*']);

    $this->putJson('/api/support/inquiries/1', ['status' => 'answered', 'reply' => 'رد'])
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['support.update']);

    Sanctum::actingAs(supportAdminUser(), ['*']);

    $created = $this->postJson('/api/support/inquiries', [
        'name' => 'زائر اختبار',
        'phone' => '777123456',
        'message' => 'استفسار مخصص لاختبار الرد على التذاكر.',
    ])->assertOk();

    $ticketId = (int) $created->json('data.ticket_id');

    // حالة خارج الثلاث المعلَنة ⇒ 422، وحالة «تم الرد» بلا نص ⇒ 422 أيضاً
    // A status outside the three declared ones is a 422, and so is "answered" with no reply text.
    $this->putJson("/api/support/inquiries/{$ticketId}", ['status' => 'pending'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $this->putJson("/api/support/inquiries/{$ticketId}", ['status' => 'answered'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reply']);

    $this->putJson("/api/support/inquiries/{$ticketId}", [
        'status' => 'answered',
        'reply' => 'تمت معالجة استفسارك.',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'answered');

    $row = array_change_key_case(
        (array) DB::connection('oracle')->selectOne(
            'SELECT STATUS, ADMIN_REPLY, REPLIED_BY, REPLIED_ON
               FROM ACCOUNTS.APP_SUPPORT_TICKETS WHERE TICKET_ID = :ticket_id',
            ['ticket_id' => $ticketId]
        ),
        CASE_LOWER
    );

    expect($row['status'])->toBe('answered')
        ->and(ArabicEncoding::fixMojibake((string) $row['admin_reply']))->toBe('تمت معالجة استفسارك.')
        ->and($row['replied_by'])->not->toBeNull()
        ->and($row['replied_on'])->not->toBeNull();

    // تذكرة غير موجودة ⇒ 404 لا 200 كاذبة
    // A missing ticket is a 404, not a false success.
    $this->putJson('/api/support/inquiries/999999999', ['status' => 'closed'])
        ->assertNotFound();

    supportDrop($ticketId);
});

test('the public submit route is rate limited', function () {
    // نتحقق بنيةً أن المسار العام عليه `throttle:5,1` — دليل ثابت بلا اقتران بحالة الكاش
    // Assert structurally that the public submit route carries throttle:5,1 — stable evidence that
    // does not couple the suite to shared rate-limiter cache state across runs.
    $route = collect(Route::getRoutes())
        ->first(static fn ($r): bool => $r->uri() === 'api/support/inquiries'
            && in_array('POST', $r->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:5,1');
});

test('replying with an unknown send channel is refused before any write', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    Sanctum::actingAs(supportAdminUser(), ['*']);

    $created = $this->postJson('/api/support/inquiries', [
        'name' => 'زائر اختبار',
        'phone' => '777123456',
        'message' => 'استفسار مخصص لاختبار قناة إرسال غير معروفة.',
    ])->assertOk();

    $ticketId = (int) $created->json('data.ticket_id');

    // قناة غير معلنة ⇒ 422 على الحقل نفسه — الرفض يقع قبل أي كتابة في التذكرة أو الطابور
    // An undeclared channel is a 422 on the field itself — the refusal precedes any write to the
    // ticket or the queue.
    $this->putJson("/api/support/inquiries/{$ticketId}", [
        'status' => 'answered',
        'reply' => 'رد تجريبي لا يجب أن يُحفظ.',
        'send_via' => 'telegram',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['send_via']);

    supportDrop($ticketId);
});

test('a send channel without reply text is refused', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    Sanctum::actingAs(supportAdminUser(), ['*']);

    // التحقق يسبق لمس القاعدة، فمعرّف التذكرة هنا لا يُنشئ ولا يقرأ شيئاً
    // Validation precedes any database touch, so the ticket id here creates and reads nothing.
    // قناة بلا نص ⇒ 422 على `reply` — لا رسالة فارغة تصل الطابور أبداً
    // A channel without text is a 422 on `reply` — no empty message ever reaches the queue.
    $this->putJson('/api/support/inquiries/1', [
        'status' => 'answered',
        'send_via' => 'sms',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['reply']);
});

test('answering with a send channel on a phoneless ticket is refused with no partial save', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    Sanctum::actingAs(supportAdminUser(), ['*']);

    // صاحب الجلسة بلا هاتف ⇒ تذكرة بلا CONTACT_PHONE
    // A signed-in submitter without a phone ⇒ a ticket with no CONTACT_PHONE.
    $created = $this->postJson('/api/support/inquiries', [
        'message' => 'استفسار من حساب بلا رقم هاتف لاختبار رفض الإرسال.',
    ])->assertOk();

    $ticketId = (int) $created->json('data.ticket_id');

    $this->putJson("/api/support/inquiries/{$ticketId}", [
        'status' => 'answered',
        'reply' => 'رد لن يجد هاتفاً يصل إليه.',
        'send_via' => 'sms',
    ])->assertStatus(422)
        ->assertJsonPath('success', false);

    // لا حفظ جزئي: التذكرة ما زالت جديدة بلا رد — الرفض وقع قبل المعاملة كلها
    // No partial save: the ticket is still new with no reply — the refusal happened before the
    // whole transaction.
    $row = array_change_key_case(
        (array) DB::connection('oracle')->selectOne(
            'SELECT STATUS, ADMIN_REPLY FROM ACCOUNTS.APP_SUPPORT_TICKETS WHERE TICKET_ID = :ticket_id',
            ['ticket_id' => $ticketId]
        ),
        CASE_LOWER
    );

    expect($row['status'])->toBe('new')
        ->and($row['admin_reply'])->toBeNull();

    supportDrop($ticketId);
});

test('the queue id rule keeps the next id ahead of both the queue and the archive', function () {
    // القاعدة المطلوبة صراحةً: المعرف التالي = أكبر تسلسل بين جدول الطابور وجدول الأرشيف + 1.
    // فحص قراءة فقط لا يدرج شيئاً؛ المهام الزمنية قد ترسل بين قراءتين لكن الدليل الثابت يصمد:
    // الأرشفة تنسخ الصف بنفس المعرّف فلا ينكمش أعلى معرف تحت ما قُرئ سابقاً، وتفريغ الطابور
    // يرفع الأرشيف معه — فيبقى المعرّف التالي أكبر من القيمتين المقروءتين مهما تخلل.
    // The explicit rule: next id = the highest sequence across queue and archive, + 1.
    // A read-only probe that inserts nothing; jobs may transmit between reads, yet the invariant
    // holds: archiving copies the row under the same id so no observed maximum shrinks below what
    // was read, and draining the queue raises the archive with it — the next id stays ahead of
    // both readings no matter the interleaving.
    $maxQueueSms = supportScalarOrSkip('SELECT NVL(MAX(ID), 0) AS M FROM ACCOUNTS.MSG_QUEUE');
    $maxArchiveSms = supportScalarOrSkip('SELECT NVL(MAX(ID), 0) AS M FROM ACCOUNTS.MSG_ARCHIVE');
    $nextSms = MessageQueueWriter::nextId(MessageQueueWriter::CHANNEL_SMS);

    expect($nextSms)->toBeGreaterThan($maxQueueSms)
        ->and($nextSms)->toBeGreaterThan($maxArchiveSms);

    $maxQueueWhatsapp = supportScalarOrSkip('SELECT NVL(MAX(ID), 0) AS M FROM ACCOUNTS.W_MSG_QUEUE');
    $maxArchiveWhatsapp = supportScalarOrSkip('SELECT NVL(MAX(ID), 0) AS M FROM ACCOUNTS.W_MSG_ARCHIVE');
    $nextWhatsapp = MessageQueueWriter::nextId(MessageQueueWriter::CHANNEL_WHATSAPP);

    expect($nextWhatsapp)->toBeGreaterThan($maxQueueWhatsapp)
        ->and($nextWhatsapp)->toBeGreaterThan($maxArchiveWhatsapp);
});

test('the resolved send destination matches an active subscription of the channel service', function () {
    // وجهة الإرسال (SERVICE_ID/PROVIDER_ID) يجب أن تطابق اشتراكاً نشطاً في PROVIDERS_DTL لخدمة
    // القناة، وإلا علقت الرسالة في الطابور بلا إرسال أبداً. فحص قراءة فقط يثبت الثابتين:
    // إما أن الوجهة المحلَّلة تطابق اشتراكاً نشطاً، وإما لا اشتراك نشط أصلاً وحينها null صحيحة.
    // The send destination (SERVICE_ID/PROVIDER_ID) must match an active PROVIDERS_DTL
    // subscription of the channel's service, or the message hangs in the queue forever. A
    // read-only probe proving both invariants: either the resolved destination matches an active
    // subscription, or none exists at all — and then null is the correct answer.
    $channels = [
        MessageQueueWriter::CHANNEL_SMS => "('URL', 'FIREBASE')",
        MessageQueueWriter::CHANNEL_WHATSAPP => "('WHATSAPP')",
    ];

    foreach ($channels as $channel => $types) {
        // القراءة أولاً كي يقع التخطي اللطيف قبل أي نداء مباشر للكاتب
        // The read runs first so the graceful skip happens before any direct writer call.
        $activeCount = supportScalarOrSkip(
            "SELECT COUNT(*) AS C FROM ACCOUNTS.PROVIDERS_DTL d
              JOIN ACCOUNTS.SERVICES s ON s.SERVICE_ID = d.SERVICE_ID
             WHERE s.SERVICE_TYPE IN {$types} AND d.STATUS = 1"
        );

        $destination = MessageQueueWriter::resolveDestination($channel);

        if ($activeCount === 0) {
            expect($destination)->toBeNull();

            continue;
        }

        expect($destination)->not->toBeNull();

        $matched = supportScalarOrSkip(
            "SELECT COUNT(*) AS C FROM ACCOUNTS.PROVIDERS_DTL d
              JOIN ACCOUNTS.SERVICES s ON s.SERVICE_ID = d.SERVICE_ID
             WHERE s.SERVICE_TYPE IN {$types} AND d.STATUS = 1
               AND d.PROVIDER_ID = {$destination['provider_id']}
               AND d.SERVICE_ID = {$destination['service_id']}"
        );

        expect($matched)->toBeGreaterThan(0);
    }
});
