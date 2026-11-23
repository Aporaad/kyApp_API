<?php

namespace App\Support\Messaging;

use App\Support\ActivityLogger;
use App\Support\MessageQueueWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * الإرسال المباشر لرسالة واتساب — تجاوز دورة الطابور دون تجاوز خطّ الإرسال
 * Direct WhatsApp send — skip the queue *schedule*, never the send pipeline.
 *
 * التصميم: النقل يتم عبر دوال أوراكل نفسها التي يستخدمها المحرّك (`SEND_TEXT_RID` للنص،
 * `SendWhatsappAPI.sendFile` للملف)، فتبقى الترويسات وبصمة `clientRequestId` متطابقة تماماً،
 * والأرشفة عبر `SendWhatsappAPI.INS_W_MSG_ARCHIVE` وسطر المحاولة في `W_MSG_ATTEMPT_LOG`.
 * البديل — إعادة تجميع جسم حزمة `W_MSG_DISPATCHER` الحيّة — مرفوض: نصوصها العربية مخزَّنة
 * بترميز `WE8MSWIN1252`، وخطر إتلاف قناة الإرسال الوحيدة أعلى من أي ربح.
 * Transport goes through the same Oracle functions the engine uses, so headers and the
 * `clientRequestId` fingerprint stay identical; archiving and the attempt line follow the engine's
 * own procedures. Recompiling the live dispatcher package was rejected: its Arabic text is stored
 * as WE8MSWIN1252 and the risk to the only working channel outweighs any gain.
 *
 * قاعدة الترميز: نص الرسالة يُقرأ من القاعدة ويُرسل **كما هو** بلا `toOracleSearchChars`؛ إعادة
 * الترميز تحوّل العربية إلى `bf,bf,bf`. النص الوارد من العميل يُرمَّز مرة واحدة عند الإدراج فقط.
 * Encoding rule: the stored text is sent verbatim; re-encoding corrupts Arabic to `bf,bf,bf`.
 */
final class WhatsAppDirectSender
{
    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * إرسال رسالة طابور الآن
     * Send one queued message right now.
     *
     * @return array{ok: bool, code: int, status: string, message: string, http?: int|null, provider_msg_id?: string|null}
     */
    public function sendNow(int $id, Request $request, ?string $actor): array
    {
        $row = $this->row($id);
        if ($row === null) {
            return $this->fail(404, 'not_found', "الرسالة رقم [{$id}] غير موجودة في الطابور / Message [{$id}] not found in queue.");
        }

        $status = strtoupper((string) ($row['STATUS'] ?? ''));

        if (MessageStatus::isBusy($row)) {
            return $this->fail(409, 'busy', 'الرسالة قيد الإرسال الآن؛ لا يمكن إرسالها مرة أخرى / The message is being sent right now.');
        }

        if (! in_array($status, ['NEW', 'PAUSED', 'DEAD', 'UNKNOWN'], true)) {
            return $this->fail(409, 'state', 'حالة الرسالة لا تسمح بالإضافة الفورية / This message state cannot be sent now.');
        }

        $subscription = $this->subscription($row);
        if ($subscription === null) {
            return $this->fail(422, 'no_subscription', 'لا يوجد اشتراك إرسال نشط لهذه القناة / No active send subscription for this channel.');
        }

        // فحص الجلسة أولاً: هو نفس ما يفعله المحرّك في بداية كل دورة، وبدونه نرسل إلى بوابة
        // ترفض كل الطلبات (401) فنحرق محاولة من رصيد الرسالة بلا داعٍ.
        // Check the session first — exactly what the engine does at the top of every cycle.
        $readiness = $this->readiness($subscription);
        if ($readiness !== null) {
            $this->attempt($id, (int) ($row['ATTEMPTS'] ?? 0) + 1, 'SESSION', 'NOT_READY', null, null, 0, $readiness);

            return $this->fail(409, 'session', 'البوابة غير جاهزة: '.$readiness);
        }

        // الاستيلاء: قفل الصف ثم تنفيذ الإرسال — أي طلب آخر يجد `SEND_LOCK='Y'` فينسحب
        // Claim: lock the row, then send; a concurrent request sees SEND_LOCK='Y' and backs off.
        $claimed = DB::connection('oracle')->table('ACCOUNTS.W_MSG_QUEUE')
            ->where('ID', $id)
            ->where('STATUS', $status)
            ->update([
                'IS_SENT' => 'P',
                'STATUS' => 'SENDING',
                'SENDING_AT' => DB::raw('SYSDATE'),
                'SEND_LOCK' => 'Y',
                'ATTEMPTS' => DB::raw('NVL(ATTEMPTS,0) + 1'),
                'CLAIMED_BY' => 'api:'.($request->user()?->getAttribute('USER_ID') ?? '0'),
                'CLAIMED_AT' => DB::raw('SYSDATE'),
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $actor,
            ]);

        if ($claimed !== 1) {
            return $this->fail(409, 'race', 'تغيّرت حالة الرسالة أثناء الطلب؛ أعد المحاولة / The message changed meanwhile.');
        }

        $this->flush();

        $started = microtime(true);
        $result = $this->transmit($row, $subscription);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->attempt(
            $id,
            (int) ($row['ATTEMPTS'] ?? 0) + 1,
            'DIRECT',
            $result['code_name'],
            $result['http'],
            $result['provider_msg_id'],
            $durationMs,
            $result['detail']
        );

        return $this->settle($row, $result, $request, $actor);
    }

    /**
     * تصنيف رد البوابة/الطبقة — دالة نقية قابلة للاختبار بلا قاعدة
     * Classify a gateway/Oracle-layer response — a pure function, testable without a database.
     *
     * الصيغ الواردة من `SENDWHATSAPPAPI`:
     *   `OK:{"messageId":"...","timestamp":1794104822}`
     *   `ERROR:HTTP_409:{...}` · `ERROR:NET_ERR:Connection refused`
     *
     * @return array{outcome: string, code_name: string, http: int|null, provider_msg_id: string|null, detail: string}
     */
    public static function classify(?string $raw): array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return ['outcome' => 'unknown', 'code_name' => 'EMPTY_RESPONSE', 'http' => null, 'provider_msg_id' => null, 'detail' => 'empty response'];
        }

        if (str_starts_with($raw, 'OK:')) {
            $providerId = preg_match('/messageId"\s*:\s*"([^"]+)"/', $raw, $m) === 1 ? $m[1] : null;

            return [
                'outcome' => $providerId !== null ? 'sent' : 'unknown',
                'code_name' => $providerId !== null ? 'OK' : 'OK_WITHOUT_ID',
                'http' => 201,
                'provider_msg_id' => $providerId,
                'detail' => self::clip($raw),
            ];
        }

        if (str_starts_with($raw, 'ERROR:NET_ERR') || str_starts_with($raw, 'ERROR:NET')) {
            return ['outcome' => 'network', 'code_name' => 'TRANSIENT_NET', 'http' => null, 'provider_msg_id' => null, 'detail' => self::clip($raw)];
        }

        $http = null;
        if (preg_match('/^ERROR:HTTP_(\d{3}):/', $raw, $m) === 1) {
            $http = (int) $m[1];
        }

        $outcome = match (true) {
            $http === 409 => 'duplicate',
            in_array($http, [400, 404, 413, 414], true) => 'permanent',
            in_array($http, [401, 403], true) => 'auth',
            $http === 429 => 'retry',
            $http !== null && $http >= 500 => 'unknown',
            $http !== null && $http >= 400 => 'permanent',
            default => 'unknown',
        };

        return [
            'outcome' => $outcome,
            'code_name' => $http !== null ? 'HTTP_'.$http : 'UNPARSED',
            'http' => $http,
            'provider_msg_id' => null,
            'detail' => self::clip($raw),
        ];
    }

    /**
     * حسم مصير الصف بعد الإرسال: أرشفة، أو إعادة، أو انتظار قرار بشري
     * Settle the row after the attempt: archive, retry, or wait for a human decision.
     *
     * @param  array<string, mixed>  $row
     * @param  array{outcome: string, code_name: string, http: int|null, provider_msg_id: string|null, detail: string}  $result
     * @return array{ok: bool, code: int, status: string, message: string, http?: int|null, provider_msg_id?: string|null}
     */
    private function settle(array $row, array $result, Request $request, ?string $actor): array
    {
        $id = (int) $row['ID'];
        $attempts = (int) ($row['ATTEMPTS'] ?? 0) + 1;

        return match ($result['outcome']) {
            'sent' => $this->archive($row, 'Y', $result, $request, $actor),
            'duplicate' => $this->archive($row, 'DUP', $result, $request, $actor),
            'permanent' => $this->archive($row, 'N', $result, $request, $actor),
            'retry', 'network' => $this->requeue($id, $result, $attempts, $actor),
            // 401/403: لم تُرسل شيئاً وبقاء الصف في الطابور آمن؛ لا نورث الخطأ للرسالة
            // 401/403: nothing was sent and keeping the row queued is safe.
            'auth' => $this->requeue($id, $result, $attempts, $actor, markUnknown: false),
            default => $this->markUnknown($id, $result, $actor),
        };
    }

    /**
     * أرشفة الصف (Y/N/DUP) ثم حذفه من الطابور
     * Archive the row (Y/N/DUP), then delete it from the queue.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     * @return array{ok: bool, code: int, status: string, message: string, http?: int|null, provider_msg_id?: string|null}
     */
    private function archive(array $row, string $isSent, array $result, Request $request, ?string $actor): array
    {
        $id = (int) $row['ID'];
        $error = $result['outcome'] === 'sent' ? null : $result['code_name'].' '.$result['detail'];

        try {
            DB::connection('oracle')->statement(
                'BEGIN SendWhatsappAPI.INS_W_MSG_ARCHIVE(
                    P_ID => :id, P_MSG_TEXT => :msg_text, P_MOBILE_NO => :mobile_no,
                    P_IS_SENT => :is_sent, P_CREATED_AT => TO_DATE(:created_at, \'YYYY-MM-DD HH24:MI:SS\'),
                    P_CREATED_BY => :created_by, P_SERVICE_ID => :service_id, P_PROVIDER_ID => :provider_id,
                    P_CUSTOMER_NAME => :customer_name, P_SEND_ATTEMPT => :send_attempt,
                    P_FILE_PATH => :file_path, P_FILE_NAME => :file_name, P_FILE_TYPE => :file_type,
                    P_CAPTION => :caption, P_ERROR_MSG => :error_msg
                ); END;',
                [
                    'id' => $id,
                    'msg_text' => $row['MSG_TEXT'],
                    'mobile_no' => (int) $row['MOBILE_NO'],
                    'is_sent' => $isSent,
                    'created_at' => $row['CREATED_AT_TXT'] ?? null,
                    'created_by' => $row['CREATED_BY'] ?? $actor,
                    'service_id' => $row['SERVICE_ID'] !== null ? (int) $row['SERVICE_ID'] : null,
                    'provider_id' => $row['PROVIDER_ID'] !== null ? (int) $row['PROVIDER_ID'] : null,
                    'customer_name' => $row['CUSTOMER_NAME'],
                    'send_attempt' => (int) ($row['ATTEMPTS'] ?? $row['SEND_ATTEMPT'] ?? 0),
                    'file_path' => $row['FILE_PATH'] ?? null,
                    'file_name' => $row['FILE_NAME'] ?? null,
                    'file_type' => $row['FILE_TYPE'] ?? null,
                    'caption' => $row['CAPTION'] ?? null,
                    'error_msg' => $error,
                ]
            );
        } catch (Throwable $e) {
            // لم نستطع الأرشفة: نُبقي الصف في الطابور بحالة UNKNOWN بدل ادّعاء نتيجة
            // Archiving failed: keep the row as UNKNOWN instead of claiming an outcome.
            $this->markUnknown($id, ['code_name' => 'ARCHIVE_FAILED', 'detail' => $e->getMessage()], $actor);

            return $this->fail(502, 'archive', 'تعذّرت أرشفة نتيجة الإرسال / Could not archive the send result.');
        }

        DB::connection('oracle')->table('ACCOUNTS.W_MSG_QUEUE')->where('ID', $id)->delete();

        $this->activity->success('whatsapp.send_now', [
            'user_id' => $request->user()?->getAttribute('USER_ID'),
            'module_code' => 'whatsapp',
            'entity_type' => 'W_MSG_QUEUE',
            'entity_id' => $id,
            'description_ar' => "إرسال فوري لرسالة #{$id} → {$isSent}",
        ], $request);

        return [
            'ok' => $isSent !== 'N',
            'code' => $isSent === 'N' ? 502 : 200,
            'status' => match ($isSent) {
                'Y' => MessageStatus::SENT,
                'DUP' => MessageStatus::DUPLICATE,
                default => MessageStatus::FAILED,
            },
            'http' => $result['http'],
            'provider_msg_id' => $result['provider_msg_id'],
            'message' => match ($isSent) {
                'Y' => 'أُرسلت الرسالة الآن / Message sent.',
                'DUP' => 'البوابة اعتبرتها مكررة فلم تُرسل / The gateway treated it as a duplicate.',
                default => 'فشل الإرسال نهائياً: '.$result['code_name'],
            },
        ];
    }

    /**
     * إعادة الصف إلى الطابور مع تأخير تصاعدي
     * Put the row back in the queue with a back-off delay.
     *
     * @param  array<string, mixed>  $result
     * @return array{ok: bool, code: int, status: string, message: string, http?: int|null, provider_msg_id?: string|null}
     */
    private function requeue(int $id, array $result, int $attempts, ?string $actor, bool $markUnknown = false): array
    {
        $delay = match ($attempts) {
            1 => 1,
            2 => 5,
            3 => 15,
            4 => 60,
            default => 180,
        };

        DB::connection('oracle')->table('ACCOUNTS.W_MSG_QUEUE')->where('ID', $id)->update([
            'STATUS' => $markUnknown ? 'UNKNOWN' : 'NEW',
            'IS_SENT' => $markUnknown ? 'F' : 'N',
            'SEND_LOCK' => 'N',
            'NEXT_ATTEMPT_AT' => DB::raw($markUnknown ? 'SYSDATE' : 'SYSDATE + '.$delay.'/1440'),
            'LAST_ERROR_CODE' => $result['code_name'],
            'ERROR_MSG' => mb_substr((string) $result['detail'], 0, 1000),
            'CLAIMED_BY' => null,
            'CLAIMED_AT' => null,
            'SENDING_AT' => null,
            'UPDATED_AT' => DB::raw('SYSDATE'),
            'UPDATED_BY' => $actor,
        ]);

        return [
            'ok' => false,
            'code' => 502,
            'status' => $markUnknown ? MessageStatus::UNKNOWN : MessageStatus::PENDING,
            'http' => $result['http'],
            'provider_msg_id' => null,
            'message' => $markUnknown
                ? 'لم تُرسل الرسالة وبقيت للمراجعة: '.$result['code_name']
                : 'تعذّر الإرسال الآن وأُعيدت الرسالة إلى الطابور: '.$result['code_name'],
        ];
    }

    /**
     * وسم الصف UNKNOWN — قد يكون أُرسل، ولا إعادة تلقائية
     * Mark the row UNKNOWN — it may have been delivered; no automatic retry.
     *
     * @param  array<string, mixed>  $result
     * @return array{ok: bool, code: int, status: string, message: string}
     */
    private function markUnknown(int $id, array $result, ?string $actor): array
    {
        DB::connection('oracle')->table('ACCOUNTS.W_MSG_QUEUE')->where('ID', $id)->update([
            'STATUS' => 'UNKNOWN',
            'IS_SENT' => 'F',
            'SEND_LOCK' => 'N',
            'LAST_ERROR_CODE' => $result['code_name'],
            'ERROR_MSG' => mb_substr((string) $result['detail'], 0, 1000),
            'CLAIMED_BY' => null,
            'CLAIMED_AT' => null,
            'UPDATED_AT' => DB::raw('SYSDATE'),
            'UPDATED_BY' => $actor,
        ]);

        return [
            'ok' => false,
            'code' => 502,
            'status' => MessageStatus::UNKNOWN,
            'message' => 'نتيجة الإرسال غير مؤكدة؛ تحتاج مراجعة يدوية / Delivery is uncertain and needs review.',
        ];
    }

    /**
     * تنفيذ النقل عبر دالة أوراكل المناسبة
     * Transmit through the matching Oracle function.
     *
     * @param  array<string, mixed>  $row
     * @param  array{url: string, session_id: string, api_key: string}  $subscription
     * @return array{outcome: string, code_name: string, http: int|null, provider_msg_id: string|null, detail: string}
     */
    private function transmit(array $row, array $subscription): array
    {
        try {
            $isFile = $this->isFile($row);
            $chat = $this->chatId((string) $row['MOBILE_NO']);

            if ($isFile) {
                $meta = (array) DB::connection('oracle')->selectOne(
                    'SELECT SendWhatsappAPI.GET_FILE_URL(:path, :name) AS FILE_URL,
                            SendWhatsappAPI.GET_MIME_TYPE(:name2) AS MIME
                       FROM DUAL',
                    ['path' => (string) $row['FILE_PATH'], 'name' => (string) $row['FILE_NAME'], 'name2' => (string) $row['FILE_NAME']]
                );
                $meta = array_change_key_case($meta, CASE_UPPER);

                $caption = $row['CAPTION'];
                if ($caption === null || $caption === '') {
                    $caption = $row['MSG_TEXT'];
                }

                $raw = $this->scalar(
                    'SELECT SendWhatsappAPI.sendFile(:url, :sid, :key, :chat, :file_url, :file_name, :mime, :caption, :is_image) AS RES FROM DUAL',
                    [
                        'url' => $subscription['url'],
                        'sid' => $subscription['session_id'],
                        'key' => $subscription['api_key'],
                        'chat' => $chat,
                        'file_url' => (string) ($meta['FILE_URL'] ?? $row['FILE_PATH']),
                        'file_name' => (string) ($row['FILE_NAME'] ?? $row['FILE_PATH']),
                        'mime' => (string) ($meta['MIME'] ?? 'application/octet-stream'),
                        'caption' => (string) $caption,
                        'is_image' => $this->isImage($row) ? 'Y' : 'N',
                    ]
                );

                return self::classify($raw);
            }

            $requestId = $this->requestIdEnabled() ? (string) ($row['REQUEST_ID'] ?? '') : '';

            $raw = $requestId !== ''
                ? $this->scalar(
                    'SELECT SEND_TEXT_RID(:url, :sid, :key, :chat, :text, :req) AS RES FROM DUAL',
                    [
                        'url' => $subscription['url'],
                        'sid' => $subscription['session_id'],
                        'key' => $subscription['api_key'],
                        'chat' => $chat,
                        'text' => (string) $row['MSG_TEXT'],
                        'req' => $requestId,
                    ]
                )
                : $this->scalar(
                    'SELECT SendWhatsappAPI.sendText(:url, :sid, :key, :chat, :text) AS RES FROM DUAL',
                    [
                        'url' => $subscription['url'],
                        'sid' => $subscription['session_id'],
                        'key' => $subscription['api_key'],
                        'chat' => $chat,
                        'text' => (string) $row['MSG_TEXT'],
                    ]
                );

            return self::classify($raw);
        } catch (Throwable $e) {
            return [
                'outcome' => 'network',
                'code_name' => 'TRANSIENT_NET',
                'http' => null,
                'provider_msg_id' => null,
                'detail' => mb_substr($e->getMessage(), 0, 900),
            ];
        }
    }

    /**
     * جاهزية الجلسة — يعيد نص الخطأ إن لم تكن جاهزة، وnull إن كانت `ready`
     * Session readiness — the error text when not ready, null when `ready`.
     *
     * @param  array{url: string, session_id: string, api_key: string}  $subscription
     */
    private function readiness(array $subscription): ?string
    {
        try {
            $raw = (string) $this->scalar(
                'SELECT SendWhatsappAPI.checkApiStatus(:url, :sid, :key) AS RES FROM DUAL',
                [
                    'url' => $subscription['url'],
                    'sid' => $subscription['session_id'],
                    'key' => $subscription['api_key'],
                ]
            );
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 300);
        }

        if (! str_starts_with($raw, 'OK:')) {
            return mb_substr($raw, 0, 300);
        }

        return preg_match('/status[^a-z]+([a-z_]+)/', strtolower($raw), $m) === 1 && $m[1] === 'ready'
            ? null
            : mb_substr($raw, 0, 300);
    }

    /**
     * صف الطابور الخام + تاريخ الإنشاء كنص منسّق (لا نعتمد على NLS_DATE_FORMAT)
     * The raw queue row plus the creation date as formatted text (never rely on NLS_DATE_FORMAT).
     *
     * @return array<string, mixed>|null
     */
    private function row(int $id): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            "SELECT ID, MSG_TEXT, MOBILE_NO, IS_SENT, STATUS, ATTEMPTS, SEND_ATTEMPT, SEND_LOCK,
                    REQUEST_ID, PROVIDER_ID, SERVICE_ID, CUSTOMER_NAME,
                    FILE_PATH, FILE_NAME, FILE_TYPE, CAPTION, CREATED_BY,
                    TO_CHAR(CREATED_AT,'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT_TXT
               FROM ACCOUNTS.W_MSG_QUEUE WHERE ID = :id",
            ['id' => $id]
        );

        return $row ? array_change_key_case((array) $row, CASE_UPPER) : null;
    }

    /**
     * الاشتراك النشط الذي يخدم وجهة الصف
     * The active subscription that serves the row's destination.
     *
     * @param  array<string, mixed>  $row
     * @return array{url: string, session_id: string, api_key: string}|null
     */
    private function subscription(array $row): ?array
    {
        $query = DB::connection('oracle')->table('ACCOUNTS.PROVIDERS_DTL')
            ->where('STATUS', 1);

        if ($row['PROVIDER_ID'] !== null && $row['SERVICE_ID'] !== null) {
            $copy = DB::connection('oracle')->table('ACCOUNTS.PROVIDERS_DTL')
                ->where('STATUS', 1)
                ->where('PROVIDER_ID', (int) $row['PROVIDER_ID'])
                ->where('SERVICE_ID', (int) $row['SERVICE_ID'])
                ->first();

            if ($copy) {
                return $this->asSubscription((array) $copy);
            }
        }

        $destination = MessageQueueWriter::resolveDestination('whatsapp');
        if ($destination === null) {
            return null;
        }

        $copy = $query->where('PROVIDER_ID', $destination['provider_id'])
            ->where('SERVICE_ID', $destination['service_id'])
            ->first();

        return $copy ? $this->asSubscription((array) $copy) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{url: string, session_id: string, api_key: string}|null
     */
    private function asSubscription(array $row): ?array
    {
        $row = array_change_key_case($row, CASE_UPPER);
        $url = trim((string) ($row['URL'] ?? ''));
        $sid = trim((string) ($row['PASSWORD'] ?? ''));
        $key = trim((string) ($row['TOKEN'] ?? ''));

        if ($url === '' || $sid === '' || $key === '') {
            return null;
        }

        return ['url' => $url, 'session_id' => $sid, 'api_key' => $key];
    }

    /**
     * سطر محاولة — لا يُفشل الإرسال إن فشل تسجيله
     * An attempt line — its own failure never fails the send.
     */
    private function attempt(int $msgId, int $attemptNo, string $stage, string $code, ?int $http, ?string $providerId, int $durationMs, ?string $error): void
    {
        try {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.W_MSG_ATTEMPT_LOG
                    (LOG_ID, MSG_ID, ATTEMPT_NO, AT, STAGE, RESULT_CODE, HTTP_CODE, PROVIDER_MSG_ID, DURATION_MS, ERROR_TEXT)
                 VALUES
                    (W_MSG_ATTEMPT_LOG_SEQ.NEXTVAL, :msg_id, :attempt_no, SYSDATE, :stage, :code, :http, :provider_id, :duration, :error)',
                [
                    'msg_id' => $msgId,
                    'attempt_no' => $attemptNo,
                    'stage' => $stage,
                    'code' => $code,
                    'http' => $http,
                    'provider_id' => $providerId,
                    'duration' => $durationMs,
                    'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
                ]
            );
        } catch (Throwable) {
            // التشخيص ثانوي: لا نُفشل طلب المستخدم من أجله
            // Diagnostics are secondary: never fail the user's request for them.
        }
    }

    /**
     * هل `REQUEST_ID_ENABLED` مفعّل في `W_MSG_CONFIG`؟
     * Is REQUEST_ID_ENABLED on in W_MSG_CONFIG?
     */
    private function requestIdEnabled(): bool
    {
        $value = $this->scalar(
            "SELECT CVAL AS VAL FROM ACCOUNTS.W_MSG_CONFIG WHERE CKEY = 'REQUEST_ID_ENABLED'",
            []
        );

        return strtoupper(trim((string) $value)) === 'Y';
    }

    /** @param array<string, mixed> $row */
    private function isFile(array $row): bool
    {
        $type = strtoupper((string) ($row['FILE_TYPE'] ?? 'TEXT'));
        $path = strtolower(trim((string) ($row['FILE_PATH'] ?? '')));

        return ! in_array($type, ['TEXT', 'TXT', 'NONE', ''], true)
            && $path !== ''
            && ! in_array($path, ['not file', 'none', 'null'], true);
    }

    /** @param array<string, mixed> $row */
    private function isImage(array $row): bool
    {
        return in_array(strtoupper((string) ($row['FILE_TYPE'] ?? '')), ['IMAGE', 'JPG', 'JPEG', 'PNG', 'WEBP', 'GIF', 'BMP'], true);
    }

    private function chatId(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        return $digits.'@c.us';
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function scalar(string $sql, array $bindings): mixed
    {
        $row = (array) DB::connection('oracle')->selectOne($sql, $bindings);
        $upper = array_change_key_case($row, CASE_UPPER);

        return $upper['RES'] ?? $upper['VAL'] ?? null;
    }

    /**
     * إظهار الاستيلاء للجلسات الأخرى قبل الإرسال (autocommit غالباً كافٍ، والاستدعاء احتياط)
     * Publish the claim to other sessions before sending (autocommit usually suffices).
     */
    private function flush(): void
    {
        try {
            DB::connection('oracle')->getPdo()->commit();
        } catch (Throwable) {
            // لا معاملة مفتوحة — autocommit تكفّل بالأمر
            // No open transaction — autocommit already handled it.
        }
    }

    private static function clip(string $value): string
    {
        return mb_substr($value, 0, 900);
    }

    /**
     * @return array{ok: false, code: int, status: string, message: string}
     */
    private function fail(int $code, string $status, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'status' => $status, 'message' => $message];
    }
}
