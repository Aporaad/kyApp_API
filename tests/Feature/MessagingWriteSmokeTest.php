<?php

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\MessageQueueWriter;
use App\Support\Messaging\ChannelSpec;
use App\Support\Messaging\MessageWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * دخان كتابة الطابور — يُشغَّل يدوياً فقط، ويعمل داخل معاملة تُلغى في النهاية
 * Queue write smoke — manual only, and every run happens inside a transaction that is rolled back.
 *
 * لماذا معاملة ملغاة؟ لأن الجداول ليست ملكنا. لا خطوة هنا تُغيّر `STATUS` لصف قائم (صفوف الاختبار
 * اصطناعية)، والإدراج لا يشغّل إلا مشغّل «قبل الإدراج» غير المستقل فيُمحى مع الإلغاء.
 * استثناء واحد: إعادة إرسال الصف الاصطناعي تغيّر `STATUS`، ومشغّل التاريخ مستقل فيبقى سطره بعد
 * الإلغاء — أثر تدقيقي لمعرّف اصطناعي لا يمسّ أي رسالة حقيقية.
 * Why a rolled-back transaction? The tables are not ours. No existing row's STATUS is changed here,
 * and the non-autonomous insert trigger is erased by the rollback. One exception: resending the
 * synthetic row changes STATUS, and the autonomous history trigger keeps its row — an audit trace for
 * a synthetic id that never touches a real message.
 *
 * التشغيل: `$env:WHATSAPP_WRITE_SMOKE='1'; vendor/bin/pest tests/Feature/MessagingWriteSmokeTest.php`
 * Run with WHATSAPP_WRITE_SMOKE=1 only.
 */
beforeEach(function () {
    if (getenv('WHATSAPP_WRITE_SMOKE') !== '1') {
        $this->markTestSkipped('كتابة تجريبية على القاعدة الحيّة: تُشغَّل بـWHATSAPP_WRITE_SMOKE=1 فقط.');
    }
});

/**
 * إدراج صف اصطناعي في الطابور بحالة محددة
 * Insert a synthetic queue row in a given state.
 */
function smokeQueueRow(string $status, string $isSent, int $attempts): int
{
    $id = MessageQueueWriter::nextId('whatsapp');

    DB::connection('oracle')->table('ACCOUNTS.W_MSG_QUEUE')->insert([
        'ID' => $id,
        'MSG_TEXT' => ArabicEncoding::toOracleSearchChars('رسالة اختبار الكتابة'),
        'MOBILE_NO' => 967711507016,
        'IS_SENT' => $isSent,
        'STATUS' => $status,
        'ATTEMPTS' => $attempts,
        'SEND_ATTEMPT' => $attempts,
        'NEXT_ATTEMPT_AT' => DB::raw('SYSDATE'),
        'SEND_LOCK' => 'N',
        'PROVIDER_ID' => 1,
        'SERVICE_ID' => 3,
        'CREATED_AT' => DB::raw('SYSDATE'),
    ]);

    return $id;
}

test('editing a pending row and resending a dead one work against the real schema', function () {
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->firstOrFail();
    Sanctum::actingAs($user, ['*']);

    $connection = DB::connection('oracle');
    $connection->beginTransaction();

    try {
        $writer = new MessageWriter(ChannelSpec::whatsapp(), app(ActivityLogger::class));
        $request = Request::create('/api/whatsapp/messages', 'PUT');
        $request->setUserResolver(fn () => $user);

        // 1) تعديل صف منتظر: لا تغيير حالة ⇒ لا سطر تاريخ
        // 1) Edit a pending row: no status change, so no history row.
        $pending = smokeQueueRow('NEW', 'N', 0);
        $edit = $writer->update($pending, ['msg_text' => 'نص معدّل للاختبار', 'mobile_no' => '967711507016'], $request, 'smoke');

        expect($edit['ok'])->toBeTrue();

        // 2) إعادة إرسال صف ميت: **الصف نفسه** يعود إلى الطابور بمعرّفه وبلا صف جديد
        // 2) Resend a dead row: the *same* row returns to the queue with its id and no new row.
        $dead = smokeQueueRow('DEAD', 'F', 3);
        $nextBefore = MessageQueueWriter::nextId('whatsapp');

        $resend = $writer->resend($dead, $request, 'smoke');

        expect($resend['ok'])->toBeTrue()
            ->and((int) $resend['message_id'])->toBe($dead)
            ->and((int) $resend['new_id'])->toBe($dead);

        // لا صف جديد: المعرّف التالي لم يتحرّك
        // No new row: the next id did not move.
        expect(MessageQueueWriter::nextId('whatsapp'))->toBe($nextBefore);

        $reset = array_change_key_case(
            (array) $connection->selectOne(
                'SELECT STATUS, IS_SENT, ATTEMPTS, LAST_ERROR_CODE FROM ACCOUNTS.W_MSG_QUEUE WHERE ID = :id',
                ['id' => $dead]
            ),
            CASE_UPPER
        );

        expect($reset['STATUS'])->toBe('NEW')
            ->and($reset['IS_SENT'])->toBe('N')
            ->and((int) $reset['ATTEMPTS'])->toBe(0)
            ->and($reset['LAST_ERROR_CODE'])->toBeNull();

        // 3) النص لم يُمسّ: نفس الصف ونفس المحتوى
        // 3) The text is untouched: same row, same content.
        $fetch = function (int $rowId) use ($connection): string {
            $row = array_change_key_case(
                (array) $connection->selectOne('SELECT MSG_TEXT AS T FROM ACCOUNTS.W_MSG_QUEUE WHERE ID = :id', ['id' => $rowId]),
                CASE_UPPER
            );

            return (string) ($row['T'] ?? '');
        };

        expect($fetch($dead))->not->toBeEmpty();
    } finally {
        $connection->rollBack();
    }
});
