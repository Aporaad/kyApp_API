<?php

namespace App\Support\Messaging;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\MessageQueueWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * كاتب سجل الرسائل — كل كتابة على الطابور/الأرشيف تمرّ من هنا
 * The message-log writer — every write to the queue/archive goes through here.
 *
 * ثلاث قواعد لا تُخترق:
 *  1) **لا ترميز لنص مقروء من القاعدة.** إعادة إرسال صف تنسخ `MSG_TEXT` كما هو؛ تمريره على
 *     `toOracleSearchChars()` ثانيةً يحوّل العربية إلى `bf,bf,bf` (انظر AGENTS.md §6).
 *  2) **المعرّف = أكبر تسلسل بين الطابور والأرشيف + 1** عبر `MessageQueueWriter::nextId()`
 *     — لا `MAX(الطابور)` وحده، لأن الطابور يُفرَّغ بعد الأرشفة فيتصادم المعرّف مع صف مؤرشف.
 *  3) **الحالة تُفحص قبل الكتابة**، والصف المشغول (`SENDING/PROCESSING` أو `SEND_LOCK='Y'`) لا يُلمس.
 * Three invariants: (1) never re-encode text read from the database — a resend copies `MSG_TEXT`
 * verbatim; (2) the id is the highest sequence across queue **and** archive + 1 via
 * `MessageQueueWriter::nextId()`; (3) the status is checked before any write and a busy row is never
 * touched.
 */
final class MessageWriter
{
    public function __construct(
        private readonly ChannelSpec $spec,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * صف خام (بلا أي إصلاح عربي) — مطلوب للنسخ عند إعادة الإرسال
     * A raw row (no Arabic repair at all) — required for a byte-faithful resend copy.
     *
     * @return array<string, mixed>|null
     */
    public function raw(int $id, string $source): ?array
    {
        $table = $source === 'archive' ? $this->spec->archive : $this->spec->queue;
        $row = DB::connection('oracle')->table($table)->where('ID', $id)->first();

        return $row ? array_change_key_case((array) $row, CASE_UPPER) : null;
    }

    /**
     * تحديد مصدر الصف: الطابور أولاً ثم الأرشيف
     * Locate the row's source: queue first, then archive.
     *
     * @return array{source: string, row: array<string, mixed>}|null
     */
    public function locate(int $id): ?array
    {
        foreach (['queue', 'archive'] as $source) {
            $row = $this->raw($id, $source);
            if ($row !== null) {
                return ['source' => $source, 'row' => $row];
            }
        }

        return null;
    }

    /**
     * تعديل رسالة — الطابور مباشرة، والأرشيف عبر «حفظ وإعادة الإرسال»
     * Edit a message — in place for the queue, as a save-and-resend for the archive.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, code: int, message: string, new_id?: int}
     */
    public function update(int $id, array $input, Request $request, ?string $actor): array
    {
        $found = $this->locate($id);
        if ($found === null) {
            return $this->fail(404, "الرسالة رقم [{$id}] غير موجودة / Message [{$id}] not found.");
        }

        ['source' => $source, 'row' => $row] = $found;
        $status = MessageStatus::of($row + ['SRC' => $source]);

        if (! MessageActions::isAllowed(MessageActions::EDIT, $status, $source, $this->spec->supportsWorkerActions())) {
            return $this->fail(409, 'لا يمكن تعديل رسالة بهذه الحالة / This message cannot be edited in its current state.');
        }

        if ($source === 'queue' && MessageStatus::isBusy($row)) {
            return $this->fail(409, 'الرسالة قيد الإرسال الآن؛ لا يمكن تعديلها / The message is being sent right now.');
        }

        $data = $this->payload($input);

        if ($source === 'archive') {
            // الأرشيف نهائي: التعديل يعني إرجاع الصف نفسه إلى الطابور بالتعديلات (نفس المعرّف)
            // The archive is final: editing means moving the same row back to the queue with the
            // edits, keeping its id.
            return $this->restoreToQueue($row, $data, $request, $actor);
        }

        $data['UPDATED_AT'] = DB::raw('SYSDATE');
        $data['UPDATED_BY'] = $actor;
        if ($this->spec->hasErrorColumn) {
            $data['ERROR_MSG'] = null;
            $data['LAST_ERROR_CODE'] = null;
        }

        // تعديل رسالة فاشلة/مجهولة يُعيدها إلى المسار: التعديل بلا إعادة إرسال يتركها ميتة
        // Editing a failed/unknown row requeues it: an edit that leaves it dead is a lie to the user.
        if ($this->spec->hasWorkerState && in_array($status, [MessageStatus::FAILED, MessageStatus::UNKNOWN], true)) {
            $data['STATUS'] = 'NEW';
            $data['IS_SENT'] = 'N';
            $data['SEND_LOCK'] = 'N';
            $data['ATTEMPTS'] = 0;
            $data['NEXT_ATTEMPT_AT'] = DB::raw('SYSDATE');
            $data['CLAIMED_BY'] = null;
            $data['CLAIMED_AT'] = null;
            $data['SENDING_AT'] = null;
        }

        DB::connection('oracle')->table($this->spec->queue)->where('ID', $id)->update($data);

        $this->activity->success($this->spec->module.'.update', [
            'user_id' => $request->user()?->getAttribute('USER_ID'),
            'module_code' => $this->spec->module,
            'entity_type' => $this->spec->queue,
            'entity_id' => $id,
            'description_ar' => "تعديل رسالة #{$id}",
        ], $request);

        return ['ok' => true, 'code' => 200, 'message' => 'تم تعديل الرسالة / Message updated.'];
    }

    /**
     * حذف رسالة — الطابور يُحذف، والأرشيف يُسلسَل كاملاً في سجل النشاط قبل حذفه
     * Delete a message — the queue row is removed; an archive row is fully serialised into the
     * activity trail before deletion so the record survives outside the messages table.
     *
     * @return array{ok: bool, code: int, message: string}
     */
    public function delete(int $id, Request $request): array
    {
        $found = $this->locate($id);
        if ($found === null) {
            return $this->fail(404, "الرسالة رقم [{$id}] غير موجودة / Message [{$id}] not found.");
        }

        ['source' => $source, 'row' => $row] = $found;

        if ($source === 'queue' && MessageStatus::isBusy($row)) {
            return $this->fail(409, 'الرسالة قيد الإرسال الآن؛ لا يمكن حذفها / The message is being sent right now.');
        }

        $table = $source === 'archive' ? $this->spec->archive : $this->spec->queue;

        $this->activity->success($this->spec->module.'.delete', [
            'user_id' => $request->user()?->getAttribute('USER_ID'),
            'module_code' => $this->spec->module,
            'entity_type' => $table,
            'entity_id' => $id,
            'description_ar' => $this->deletionNote($id, $source, $row),
        ], $request);

        DB::connection('oracle')->table($table)->where('ID', $id)->delete();

        return ['ok' => true, 'code' => 200, 'message' => 'تم حذف الرسالة / Message deleted.'];
    }

    /**
     * إيقاف مؤقت — `STATUS='PAUSED'` وهو ما لا يستلمه المحرّك (يقرأ `NEW` فقط)
     * Pause — `STATUS='PAUSED'` is invisible to the engine, which only claims `NEW`.
     *
     * @return array{ok: bool, code: int, message: string}
     */
    public function pause(int $id, Request $request, ?string $actor): array
    {
        $row = $this->raw($id, 'queue');
        if ($row === null) {
            return $this->fail(404, "الرسالة رقم [{$id}] غير موجودة في الطابور / Message [{$id}] not found in queue.");
        }

        if (MessageStatus::isBusy($row)) {
            return $this->fail(409, 'الرسالة قيد الإرسال الآن؛ لا يمكن إيقافها / The message is being sent right now.');
        }

        if (strtoupper((string) ($row['STATUS'] ?? '')) !== 'NEW') {
            return $this->fail(409, 'الإيقاف المؤقت متاح للرسائل المنتظرة فقط / Only pending messages can be paused.');
        }

        $affected = DB::connection('oracle')->table($this->spec->queue)
            ->where('ID', $id)
            ->where('STATUS', 'NEW')
            ->update([
                'STATUS' => 'PAUSED',
                'IS_SENT' => 'N',
                'SEND_LOCK' => 'N',
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $actor,
            ]);

        if ($affected !== 1) {
            return $this->fail(409, 'تغيّرت حالة الرسالة أثناء الطلب؛ أعد المحاولة / The message changed meanwhile.');
        }

        $this->audit($request, $id, 'إيقاف رسالة #'.$id.' مؤقتاً');

        return ['ok' => true, 'code' => 200, 'message' => 'أُوقف إرسال الرسالة مؤقتاً / Message paused.'];
    }

    /**
     * استئناف الإرسال — إعادة الصف إلى مسار المحرّك
     * Resume — hand the row back to the engine's claim path.
     *
     * @return array{ok: bool, code: int, message: string}
     */
    public function resume(int $id, Request $request, ?string $actor): array
    {
        $row = $this->raw($id, 'queue');
        if ($row === null) {
            return $this->fail(404, "الرسالة رقم [{$id}] غير موجودة في الطابور / Message [{$id}] not found in queue.");
        }

        if (strtoupper((string) ($row['STATUS'] ?? '')) !== 'PAUSED') {
            return $this->fail(409, 'الرسالة ليست موقوفة مؤقتاً / The message is not paused.');
        }

        $affected = DB::connection('oracle')->table($this->spec->queue)
            ->where('ID', $id)
            ->where('STATUS', 'PAUSED')
            ->update([
                'STATUS' => 'NEW',
                'IS_SENT' => 'N',
                'SEND_LOCK' => 'N',
                'NEXT_ATTEMPT_AT' => DB::raw('SYSDATE'),
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $actor,
            ]);

        if ($affected !== 1) {
            return $this->fail(409, 'تغيّرت حالة الرسالة أثناء الطلب؛ أعد المحاولة / The message changed meanwhile.');
        }

        $this->audit($request, $id, 'استئناف رسالة #'.$id);

        return ['ok' => true, 'code' => 200, 'message' => 'استُؤنف إرسال الرسالة / Message resumed.'];
    }

    /**
     * إعادة إرسال **الرسالة نفسها** — لا نسخة جديدة
     * Resend **the same message** — never a copy.
     *
     * في الطابور: يُعاد الصف إلى حالة الانتظار بنفس معرّفه (ATTEMPTS=0 وموعد فوري).
     * في الأرشيف: يُنقل الصف إلى الطابور بنفس معرّفه ثم يُحذف من الأرشيف (والنتيجة الأصلية تُسجَّل
     * في سجل النشاط). هكذا تبقى هوية الرسالة واحدة كما يتوقع المستخدم.
     * In the queue: the row returns to NEW with the same id, zeroed attempts, and an immediate slot.
     * In the archive: the row moves back to the queue with the same id and leaves the archive, with
     * its original outcome written to the activity trail.
     *
     * @return array{ok: bool, code: int, message: string, message_id?: int, new_id?: int}
     */
    public function resend(int $id, Request $request, ?string $actor): array
    {
        $found = $this->locate($id);
        if ($found === null) {
            return $this->fail(404, "الرسالة رقم [{$id}] غير موجودة / Message [{$id}] not found.");
        }

        ['source' => $source, 'row' => $row] = $found;
        $status = MessageStatus::of($row + ['SRC' => $source]);

        if (! MessageActions::isAllowed(MessageActions::RESEND, $status, $source, $this->spec->supportsWorkerActions())) {
            return $this->fail(409, 'لا يمكن إعادة إرسال رسالة بهذه الحالة / This message cannot be resent in its current state.');
        }

        if ($source === 'queue') {
            if (MessageStatus::isBusy($row)) {
                return $this->fail(409, 'الرسالة قيد الإرسال الآن؛ لا يمكن إعادتها / The message is being sent right now.');
            }

            $this->resetQueueRow($id, $actor);
            $this->audit($request, $id, "إعادة إرسال الرسالة #{$id}");

            return [
                'ok' => true,
                'code' => 200,
                'message' => "أُعيدت الرسالة #{$id} نفسها إلى الطابور / Message #{$id} was requeued.",
                'message_id' => $id,
                'new_id' => $id,
            ];
        }

        return $this->restoreToQueue($row, [], $request, $actor);
    }

    /**
     * تنفيذ إجراء على مجموعة معرّفات — كل نتيجة مستقلة، وفشل صف لا يُسقط الدفعة
     * Run an action over a set of ids — every result is independent; one failure never sinks the batch.
     *
     * @param  list<int>  $ids
     * @return array{requested: int, done: int, results: list<array<string, mixed>>}
     */
    public function bulk(string $action, array $ids, Request $request, ?string $actor): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_slice($ids, 0, 100);

        $results = [];
        $done = 0;

        foreach ($ids as $id) {
            try {
                $res = match ($action) {
                    MessageActions::RESEND => $this->resend($id, $request, $actor),
                    MessageActions::PAUSE => $this->pause($id, $request, $actor),
                    MessageActions::DELETE => $this->delete($id, $request),
                    default => $this->fail(422, 'إجراء غير معروف / Unknown bulk action.'),
                };
            } catch (Throwable $e) {
                $res = $this->fail(500, 'خطأ في تنفيذ الإجراء / Action failed: '.$e->getMessage());
            }

            if ($res['ok']) {
                $done++;
            }
            $results[] = ['id' => $id, 'status' => $res['ok'] ? 'ok' : 'failed', 'message' => $res['message']] + $res;
        }

        return ['requested' => count($ids), 'done' => $done, 'results' => $results];
    }

    /**
     * إعادة صف طابور إلى الانتظار بنفس معرّفه
     * Return one queue row to pending, keeping its id.
     */
    private function resetQueueRow(int $id, ?string $actor): void
    {
        $data = [
            'IS_SENT' => 'N',
            'SEND_ATTEMPT' => 0,
            'UPDATED_AT' => DB::raw('SYSDATE'),
            'UPDATED_BY' => $actor,
        ];

        if ($this->spec->hasWorkerState) {
            $data += [
                'STATUS' => 'NEW',
                'ATTEMPTS' => 0,
                'NEXT_ATTEMPT_AT' => DB::raw('SYSDATE'),
                'SEND_LOCK' => 'N',
                'LAST_ERROR_CODE' => null,
                'ERROR_MSG' => null,
                'CLAIMED_BY' => null,
                'CLAIMED_AT' => null,
                'SENDING_AT' => null,
                'PROVIDER_MSG_ID' => null,
            ];
        }

        DB::connection('oracle')->table($this->spec->queue)->where('ID', $id)->update($data);
    }

    /**
     * نقل صف مؤرشف إلى الطابور بنفس المعرّف — جوهر «إعادة إرسال الرسالة الأصل»
     * Move an archived row into the queue with the same id — the core of "resend the original".
     *
     * @param  array<string, mixed>  $row  الصف الأرشيفي الخام / the raw archived row
     * @param  array<string, mixed>  $overrides  تعديلات المستخدم (أعمدة) / user edits (columns)
     * @return array{ok: bool, code: int, message: string, message_id?: int, new_id?: int}
     */
    private function restoreToQueue(array $row, array $overrides, Request $request, ?string $actor): array
    {
        $id = (int) ($row['ID'] ?? 0);
        $mobile = $overrides['MOBILE_NO'] ?? $row['MOBILE_NO'] ?? null;

        if ($id === 0 || $mobile === null || (int) $mobile === 0) {
            return $this->fail(422, 'بيانات الرسالة غير كافية لإعادتها / The message lacks the data needed to resend it.');
        }

        $destination = $this->destination($row, $overrides);
        if ($destination === null) {
            return $this->fail(422, 'لا يوجد اشتراك إرسال نشط لهذه القناة / No active send subscription for this channel.');
        }

        $originalOutcome = trim((string) ($row['IS_SENT'] ?? '').' '.($row['ERROR_MSG'] ?? ''));

        $data = [
            'ID' => $id,
            // النص يُنسخ خاماً بلا أي ترميز (القاعدة الأولى في وصف الصنف)
            // The text is copied raw with no encoding (the class doc's first invariant).
            'MSG_TEXT' => $overrides['MSG_TEXT'] ?? $row['MSG_TEXT'],
            'MOBILE_NO' => (int) $mobile,
            'IS_SENT' => 'N',
            'SERVICE_ID' => $destination['service_id'],
            'PROVIDER_ID' => $destination['provider_id'],
            'CUSTOMER_NAME' => $overrides['CUSTOMER_NAME'] ?? $row['CUSTOMER_NAME'],
            'SEND_ATTEMPT' => 0,
            'CREATED_AT' => DB::raw('SYSDATE'),
            'CREATED_BY' => $actor,
        ];

        if ($this->spec->hasWorkerState) {
            $data += [
                'STATUS' => 'NEW',
                'ATTEMPTS' => 0,
                'NEXT_ATTEMPT_AT' => DB::raw('SYSDATE'),
                'SEND_LOCK' => 'N',
                'LAST_ERROR_CODE' => null,
                'ERROR_MSG' => null,
                'FILE_PATH' => $overrides['FILE_PATH'] ?? $row['FILE_PATH'] ?? null,
                'FILE_NAME' => $overrides['FILE_NAME'] ?? $row['FILE_NAME'] ?? null,
                'FILE_TYPE' => $overrides['FILE_TYPE'] ?? $row['FILE_TYPE'] ?? null,
                'CAPTION' => $overrides['CAPTION'] ?? $row['CAPTION'] ?? null,
            ];
        }

        try {
            DB::connection('oracle')->table($this->spec->queue)->insert($data);
        } catch (Throwable $e) {
            return $this->fail(409, 'تعذّرت إعادة الرسالة إلى الطابور (تعارض معرّف؟) / Could not move the message back to the queue.');
        }

        DB::connection('oracle')->table($this->spec->archive)->where('ID', $id)->delete();

        $this->activity->success($this->spec->module.'.resend', [
            'user_id' => $request->user()?->getAttribute('USER_ID'),
            'module_code' => $this->spec->module,
            'entity_type' => $this->spec->queue,
            'entity_id' => $id,
            'description_ar' => "إعادة إرسال الرسالة المؤرشفة #{$id}".($originalOutcome !== '' ? " (النتيجة السابقة: {$originalOutcome})" : ''),
        ], $request);

        return [
            'ok' => true,
            'code' => 200,
            'message' => "أُعيدت الرسالة #{$id} نفسها إلى الطابور / Message #{$id} was requeued.",
            'message_id' => $id,
            'new_id' => $id,
        ];
    }

    /**
     * وجهة الإرسال: من الصف إن كانت مكتملة، وإلا أول اشتراك نشط للقناة
     * The send destination: from the row when complete, else the channel's first active subscription.
     *
     * @param  array<string, mixed>  $row
     * @return array{provider_id: int, service_id: int}|null
     */
    private function destination(array $row, array $overrides = []): ?array
    {
        $provider = $overrides['PROVIDER_ID'] ?? $row['PROVIDER_ID'] ?? null;
        $service = $overrides['SERVICE_ID'] ?? $row['SERVICE_ID'] ?? null;

        if ($provider !== null && $service !== null) {
            $exists = DB::connection('oracle')->table('ACCOUNTS.PROVIDERS_DTL')
                ->where('PROVIDER_ID', (int) $provider)
                ->where('SERVICE_ID', (int) $service)
                ->where('STATUS', 1)
                ->exists();

            if ($exists) {
                return ['provider_id' => (int) $provider, 'service_id' => (int) $service];
            }
        }

        return MessageQueueWriter::resolveDestination($this->spec->key);
    }

    /**
     * تحويل مدخلات المستخدم إلى أعمدة — مع ترميز العربية على الكتابة
     * Map user input to columns — Arabic is encoded on write.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payload(array $input): array
    {
        $data = [];

        if (array_key_exists('msg_text', $input) && $input['msg_text'] !== null) {
            $data['MSG_TEXT'] = ArabicEncoding::toOracleSearchChars(trim((string) $input['msg_text']));
        }
        if (! empty($input['mobile_no'])) {
            $data['MOBILE_NO'] = (int) preg_replace('/\D/', '', (string) $input['mobile_no']);
        }
        if (array_key_exists('customer_name', $input)) {
            $name = trim((string) ($input['customer_name'] ?? ''));
            $data['CUSTOMER_NAME'] = $name !== '' ? ArabicEncoding::toOracleSearchChars($name) : null;
        }
        if (array_key_exists('provider_id', $input) && $input['provider_id'] !== null && $input['provider_id'] !== '') {
            $data['PROVIDER_ID'] = (int) $input['provider_id'];
        }
        if (array_key_exists('service_id', $input) && $input['service_id'] !== null && $input['service_id'] !== '') {
            $data['SERVICE_ID'] = (int) $input['service_id'];
        }

        if ($this->spec->hasFileFields) {
            foreach (['file_path' => 'FILE_PATH', 'file_name' => 'FILE_NAME', 'file_type' => 'FILE_TYPE', 'caption' => 'CAPTION'] as $key => $column) {
                if (! array_key_exists($key, $input)) {
                    continue;
                }
                $value = trim((string) ($input[$key] ?? ''));
                $data[$column] = $value !== '' ? ArabicEncoding::toOracleSearchChars($value) : null;
            }
        }

        return $data;
    }

    /**
     * وصف تدقيق الحذف — يحفظ هوية الصف بعد اختفائه من جدول الرسائل
     * The delete audit note — keeps the row's identity after it leaves the messages table.
     *
     * @param  array<string, mixed>  $row
     */
    private function deletionNote(int $id, string $source, array $row): string
    {
        $mobile = (string) ($row['MOBILE_NO'] ?? '—');
        $text = (string) ($row['MSG_TEXT'] ?? '');
        $hash = substr(sha1($text), 0, 10);
        $len = mb_strlen($text);

        return "حذف رسالة #{$id} ({$source}) — الرقم {$mobile} · طول النص {$len} · بصمة {$hash}";
    }

    private function audit(Request $request, int $id, string $description): void
    {
        $this->activity->success($this->spec->module.'.toggle', [
            'user_id' => $request->user()?->getAttribute('USER_ID'),
            'module_code' => $this->spec->module,
            'entity_type' => $this->spec->queue,
            'entity_id' => $id,
            'description_ar' => $description,
        ], $request);
    }

    /**
     * @return array{ok: false, code: int, message: string}
     */
    private function fail(int $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
