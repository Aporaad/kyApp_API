<?php

use App\Models\User;
use App\Support\ArabicEncoding;
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
