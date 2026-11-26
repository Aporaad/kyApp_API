<?php

use App\Models\User;
use App\Support\MessageQueueWriter;
use Laravel\Sanctum\Sanctum;

/**
 * مسارات سجل الرسائل — الختم، الغلاف، الفلاتر، والرفض قبل أي كتابة
 * The message-log routes — sealing, envelope, filters, and refusals before any write.
 *
 * كل استدعاء هنا للقراءة أو للرفض فقط: لا صف يُدرَج ولا يُحذف ولا تُرسل رسالة. المسارات التي تكتب
 * تُغطّى بفروعها التي تعود قبل الكتابة (403/404/422/409).
 * Every call here reads or is refused: nothing is inserted, deleted, or sent. Writing paths are
 * covered by the branches that return before the write.
 */
function messagingAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

/**
 * مستخدم غير موجود في جدول المنح ⇒ رفض افتراضي
 * A user with no grants anywhere ⇒ deny-by-default.
 */
function messagingUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('the message log requires a session', function () {
    $this->getJson('/api/whatsapp/messages')->assertUnauthorized();
    $this->getJson('/api/sms/messages')->assertUnauthorized();
    $this->postJson('/api/whatsapp/messages/1/send-now')->assertUnauthorized();
});

test('an ungranted caller is refused with the missing permission named', function () {
    Sanctum::actingAs(messagingUngrantedUser(), ['*']);

    $this->getJson('/api/whatsapp/messages')
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['whatsapp.view']);

    $this->getJson('/api/sms/messages')
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['sms.view']);
});

test('the whatsapp log answers the shared envelope with counts and per-row actions', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $response = $this->getJson('/api/whatsapp/messages?limit=5');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'source', 'status', 'status_label', 'message_text', 'actions', 'attempt_limit'],
            ],
            'meta' => ['total', 'page', 'limit', 'pages', 'counts'],
        ]);

    $counts = $response->json('meta.counts');

    expect($counts)->toHaveKeys(['all', 'pending', 'paused', 'sending', 'failed', 'unknown', 'sent', 'invalid', 'duplicate']);

    // لا سرّ في أي استجابة سجل — حتى لو حمل الصف مزوّداً
    // No secret in any log response, even when the row carries a provider.
    foreach ($response->json('data') as $row) {
        expect($row)->not->toHaveKey('PASSWORD')
            ->not->toHaveKey('TOKEN')
            ->not->toHaveKey('password');
    }
});

test('the live queue rows are reported as pending, not failed', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $response = $this->getJson('/api/whatsapp/messages?status=pending&source=queue&limit=50');
    $response->assertOk();

    foreach ($response->json('data') as $row) {
        // الصف المنتظر قد يحمل IS_SENT='F' — والدلالة تُقرأ من STATUS لا من الراية
        // A pending row may carry IS_SENT='F'; the meaning comes from STATUS, not the flag.
        expect($row['status'])->toBe('pending')
            ->and($row['actions'])->toContain('view');
    }
});

test('unknown filters and sorts are rejected instead of silently ignored', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $this->getJson('/api/whatsapp/messages?status=whatever')->assertStatus(422);
    $this->getJson('/api/whatsapp/messages?sort=password')->assertStatus(422);
    $this->getJson('/api/whatsapp/messages?dir=sideways')->assertStatus(422);
    $this->getJson('/api/whatsapp/messages?from=22-11-2026')->assertStatus(422);
});

test('a message that does not exist is a 404, not an empty success', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $missing = 987654321;

    $this->getJson("/api/whatsapp/messages/{$missing}")->assertNotFound();
    $this->getJson("/api/whatsapp/messages/{$missing}/attempts")->assertNotFound();
    $this->getJson("/api/whatsapp/messages/{$missing}/history")->assertNotFound();
    $this->postJson("/api/whatsapp/messages/{$missing}/pause")->assertNotFound();
    $this->postJson("/api/whatsapp/messages/{$missing}/resume")->assertNotFound();
    $this->postJson("/api/whatsapp/messages/{$missing}/send-now")->assertNotFound();
    $this->postJson("/api/whatsapp/messages/{$missing}/resend")->assertNotFound();
});

test('the sms channel refuses worker-only actions without touching anything', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $missing = 987654321;

    // معرّف غير موجود: الرفض يحدث قبل أي إدراج (إعادة إرسال صف حقيقي كانت ستُدرج فعلاً)
    // A missing id: the refusal happens before any insert (a real row would have been copied).
    $this->postJson("/api/sms/messages/{$missing}/resend")->assertNotFound();
    $this->postJson("/api/sms/messages/{$missing}/send-now")->assertNotFound();
});

test('bulk actions require a bounded list of ids', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $this->postJson('/api/whatsapp/messages/bulk/resend', [])->assertStatus(422);
    $this->postJson('/api/whatsapp/messages/bulk/delete', ['ids' => []])->assertStatus(422);

    $tooMany = array_fill(0, 101, 1);
    $this->postJson('/api/whatsapp/messages/bulk/pause', ['ids' => $tooMany])->assertStatus(422);
});

test('creating a message without text is refused', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $this->postJson('/api/whatsapp/messages', ['mobile_nos' => ['967711507016']])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('a live queue row opens with its full text and computed actions', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $row = array_change_key_case(
        (array) DB::connection('oracle')->selectOne('SELECT MAX(ID) AS MX FROM ACCOUNTS.W_MSG_QUEUE'),
        CASE_UPPER
    );
    $id = (int) ($row['MX'] ?? 0);

    if ($id === 0) {
        test()->markTestSkipped('الطابور فارغ حالياً.');

        return;
    }

    $response = $this->getJson("/api/whatsapp/messages/{$id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.source', 'queue');

    expect($response->json('data'))
        ->toHaveKeys(['status', 'actions', 'attempt_limit', 'message_text', 'request_id']);
});

test('the list sorts and filters by the send/update date as well as the creation date', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $this->getJson('/api/whatsapp/messages?sort=updated_at&dir=desc&limit=5')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/whatsapp/messages?updated_from=2026-11-01&updated_to=2026-11-30&limit=5')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/whatsapp/messages?created_from=2026-11-01&created_to=2026-11-30&limit=5')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('the tab counts follow the advanced filters, not the tab', function () {
    Sanctum::actingAs(messagingAdminUser(), ['*']);

    $all = $this->getJson('/api/whatsapp/messages?limit=1')->json('meta.counts');
    $none = $this->getJson('/api/whatsapp/messages?updated_from=2030-01-01&limit=1')->json('meta.counts');

    expect($all['all'])->toBeGreaterThan(0)
        ->and($none['all'])->toBe(0)
        ->and($none['sent'])->toBe(0)
        ->and($none['pending'])->toBe(0);

    // العدّادات لا تتغيّر بتبديل التبويبة: نفس الأرقام مع status=sent
    // The counts do not depend on the selected tab: identical numbers with status=sent.
    $withTab = $this->getJson('/api/whatsapp/messages?status=sent&updated_from=2030-01-01&limit=1')->json('meta.counts');

    expect($withTab)->toBe($none);
});

test('the queue id is the highest sequence across queue and archive, plus one', function () {
    // القاعدة المطلوبة صراحةً: أكبر رقم في جدولي الطابور والأرشيف + 1، لكل قناة على حدة.
    // The explicitly required rule: the highest id across queue *and* archive, + 1, per channel.
    foreach (['whatsapp', 'sms'] as $channel) {
        $tables = $channel === 'whatsapp'
            ? ['ACCOUNTS.W_MSG_QUEUE', 'ACCOUNTS.W_MSG_ARCHIVE']
            : ['ACCOUNTS.MSG_QUEUE', 'ACCOUNTS.MSG_ARCHIVE'];

        try {
            $row = (array) DB::connection('oracle')->selectOne(sprintf(
                'SELECT GREATEST(
                            (SELECT NVL(MAX(ID), 0) FROM %s),
                            (SELECT NVL(MAX(ID), 0) FROM %s)
                        ) AS EXPECTED FROM DUAL',
                $tables[0],
                $tables[1]
            ));
        } catch (Throwable $e) {
            test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: '.$e->getMessage());

            return;
        }

        $expected = (int) (array_change_key_case($row, CASE_UPPER)['EXPECTED'] ?? 0) + 1;

        expect(MessageQueueWriter::nextId($channel))
            ->toBe($expected, "قناة {$channel}: المعرّف التالي يجب أن يكون GREATEST(الطابور, الأرشيف)+1");
    }
});
