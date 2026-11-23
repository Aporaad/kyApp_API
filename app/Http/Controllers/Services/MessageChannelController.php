<?php

namespace App\Http\Controllers\Services;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\MessageQueueWriter;
use App\Support\Messaging\ChannelSpec;
use App\Support\Messaging\MessageActions;
use App\Support\Messaging\MessageQuery;
use App\Support\Messaging\MessageStatus;
use App\Support\Messaging\MessageWriter;
use App\Support\Messaging\WhatsAppDirectSender;
use App\Support\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * فئة مجردة لقنوات الرسائل (SMS وواتساب) — كل الكتابة «إدراج في الطابور» أو إرسال مباشر صريح
 * Abstract message-channel controller (SMS & WhatsApp) — every write is either an enqueue or an
 * explicit direct send.
 *
 * التصميم الجوهري لم يتغيّر: الإرسال الفعلي يحدث داخل مهمة أوراكل/Java التي تلتهم الطابور، والـAPI
 * لا يرسل تلقائياً. الاستثناء الوحيد هو «الإرسال المباشر» الذي يطلبه المستخدم صراحةً، وهو يمرّ عبر
 * نفس دوال النقل والأرشفة (انظر `WhatsAppDirectSender`).
 * The core design is unchanged: the Oracle/Java job transmits, and the API never sends on its own.
 * The single exception is the user-requested direct send, which still goes through the same transport
 * and archiving functions.
 */
abstract class MessageChannelController extends ServiceBaseController
{
    /** مواصفات القناة (الجداول/الوحدة/الحقول الخاصة) / the channel's spec */
    abstract protected function spec(): ChannelSpec;

    public function __construct(
        protected readonly PermissionResolver $permissions,
        protected readonly ActivityLogger $activity,
    ) {}

    /**
     * قائمة الرسائل — فلاتر متقدمة + فرز + تقسيم + عدّادات (كلها خادمياً)
     * The message list — advanced filters, sorting, paging, and counts, all server-side.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['nullable', Rule::in(array_merge(['all'], MessageStatus::all()))],
            'source' => ['nullable', Rule::in(['all', 'queue', 'archive'])],
            'sort' => ['nullable', Rule::in(['created_at', 'id', 'mobile', 'attempts', 'status'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'provider_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'min_attempts' => ['nullable', 'integer', 'min:0'],
            'has_file' => ['nullable', 'boolean'],
            'error_code' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            $spec = $this->spec();
            $filters = [
                'status' => (string) $request->input('status', 'all'),
                'source' => (string) $request->input('source', 'all'),
                'search' => trim((string) $request->input('search', '')),
                'from_date' => $request->input('from'),
                'to_date' => $request->input('to'),
                'provider_id' => $request->input('provider_id'),
                'service_id' => $request->input('service_id'),
                'error_code' => $request->input('error_code'),
                'min_attempts' => $request->input('min_attempts'),
                'has_file' => $request->has('has_file') ? $request->boolean('has_file') : null,
                'sort' => (string) $request->input('sort', 'created_at'),
                'dir' => (string) $request->input('dir', 'desc'),
                'page' => (int) $request->input('page', 1),
                'limit' => (int) $request->input('limit', 50),
            ];

            $query = new MessageQuery($spec);
            $page = $query->paginate($filters);
            $limit = $query->attemptLimit();
            $can = $this->canCallback($request);

            $rows = array_map(function (array $row) use ($spec, $can, $limit): array {
                $row['attempt_limit'] = $limit;
                $row['actions'] = MessageActions::forRow(
                    $row['status'],
                    $row['source'],
                    $spec->module,
                    $can,
                    $spec->supportsWorkerActions()
                );

                return $row;
            }, $page['rows']);

            return response()->json([
                'success' => true,
                'data' => $rows,
                'meta' => [
                    'total' => $page['total'],
                    'page' => $page['page'],
                    'limit' => $page['limit'],
                    'pages' => $page['limit'] > 0 ? (int) ceil($page['total'] / $page['limit']) : 0,
                    'counts' => $page['counts'],
                    'filters_applied' => [
                        'status' => $filters['status'],
                        'source' => $filters['source'],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب سجل الرسائل', $e);
        }
    }

    /**
     * تفاصيل رسالة بالنص الكامل — تُبحث في الطابور ثم الأرشيف
     * Message detail with the full text — queue first, then archive.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $spec = $this->spec();
            $row = (new MessageQuery($spec))->find($id);

            if ($row === null) {
                return $this->notFound($id);
            }

            $row['attempt_limit'] = (new MessageQuery($spec))->attemptLimit();
            $row['actions'] = MessageActions::forRow(
                $row['status'],
                $row['source'],
                $spec->module,
                $this->canCallback(request()),
                $spec->supportsWorkerActions()
            );

            return response()->json(['success' => true, 'data' => $row]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب تفاصيل الرسالة', $e);
        }
    }

    /**
     * إنشاء رسالة — إدراج في الطابور، أو إرسال مباشر عند الطلب الصريح
     * Create a message — enqueue, or send directly when explicitly requested.
     */
    public function store(Request $request): JsonResponse
    {
        $spec = $this->spec();

        $rules = [
            'msg_text' => ['required', 'string', 'max:4000'],
            'provider_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:250'],
            'mode' => ['nullable', Rule::in(['queue', 'direct'])],
            'mobile_no' => ['required_without:mobile_nos', 'nullable'],
            'mobile_nos' => ['required_without:mobile_no', 'nullable', 'array', 'max:50'],
            'mobile_nos.*' => ['required', 'string'],
        ];

        if ($spec->hasFileFields) {
            $rules += [
                'file_path' => ['nullable', 'string', 'max:1000'],
                'file_name' => ['nullable', 'string', 'max:255'],
                'file_type' => ['nullable', 'string', 'max:50'],
                'caption' => ['nullable', 'string', 'max:4000'],
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'msg_text.required' => 'نص الرسالة مطلوب / Message text is required.',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $mode = (string) $request->input('mode', 'queue');

        if ($mode === 'direct' && ! $spec->supportsWorkerActions()) {
            return response()->json([
                'success' => false,
                'message' => 'الإرسال المباشر غير مدعوم لقناة SMS / Direct send is not supported for the SMS channel.',
            ], 422);
        }

        $numbers = $this->resolveNumbers($request);
        if ($numbers === []) {
            return response()->json([
                'success' => false,
                'message' => 'رقم جوال واحد على الأقل مطلوب / At least one mobile number is required.',
            ], 422);
        }

        if ($mode === 'direct' && count($numbers) > 20) {
            return response()->json([
                'success' => false,
                'message' => 'الإرسال المباشر يقبل 20 رقماً كحد أقصى؛ استخدم الإدراج في الطابور للأعداد الكبيرة / Direct send accepts at most 20 numbers.',
            ], 422);
        }

        try {
            $actor = $this->actor($request);
            $text = ArabicEncoding::toOracleSearchChars((string) $request->input('msg_text'));
            $customerName = $request->filled('customer_name')
                ? ArabicEncoding::toOracleSearchChars(trim((string) $request->input('customer_name')))
                : null;

            $destination = $this->destination($request, $spec);
            if ($destination === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد اشتراك إرسال نشط لهذه القناة / No active send subscription for this channel.',
                ], 422);
            }

            $ids = [];

            foreach ($numbers as $mobile) {
                // المعرّف = أكبر تسلسل بين الطابور والأرشيف + 1 — القاعدة الصريحة في هذا المشروع
                // The id: highest sequence across queue and archive + 1 — this project's explicit rule.
                $id = MessageQueueWriter::nextId($spec->key);

                $row = [
                    'ID' => $id,
                    'MSG_TEXT' => $text,
                    'MOBILE_NO' => $mobile,
                    'IS_SENT' => 'N',
                    'SERVICE_ID' => $destination['service_id'],
                    'PROVIDER_ID' => $destination['provider_id'],
                    'CUSTOMER_NAME' => $customerName,
                    'SEND_ATTEMPT' => 0,
                    'CREATED_AT' => DB::raw('SYSDATE'),
                    'CREATED_BY' => $actor,
                ];

                if ($spec->hasWorkerState) {
                    $row += [
                        'STATUS' => 'NEW',
                        'ATTEMPTS' => 0,
                        'NEXT_ATTEMPT_AT' => DB::raw('SYSDATE'),
                        'SEND_LOCK' => 'N',
                    ];
                }

                if ($spec->hasFileFields) {
                    foreach (['file_path' => 'FILE_PATH', 'file_name' => 'FILE_NAME', 'file_type' => 'FILE_TYPE', 'caption' => 'CAPTION'] as $key => $column) {
                        $value = trim((string) $request->input($key, ''));
                        $row[$column] = $value !== '' ? ArabicEncoding::toOracleSearchChars($value) : null;
                    }
                }

                DB::connection('oracle')->table($spec->queue)->insert($row);
                $ids[] = $id;
            }

            $this->activity->success($spec->module.'.create', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => $spec->module,
                'entity_type' => $spec->queue,
                'entity_id' => $ids[0] ?? null,
                'description_ar' => ($mode === 'direct' ? 'إرسال مباشر' : 'إدراج في الطابور').' لـ'.count($ids).' رسالة',
            ], $request);

            if ($mode === 'queue') {
                return response()->json([
                    'success' => true,
                    'message' => 'تمت إضافة الرسائل إلى طابور الإرسال / Messages enqueued successfully.',
                    'ids' => $ids,
                    'queued' => count($ids),
                ], 201);
            }

            // الإرسال المباشر: الصف أُنشئ أولاً (فلا رسالة «شبح») ثم يُرسل الآن
            // Direct send: the row exists first (no ghost message), then it is sent now.
            $sender = new WhatsAppDirectSender($this->activity);
            $results = [];

            foreach ($ids as $id) {
                $results[] = ['id' => $id] + $sender->sendNow($id, $request, $actor);
            }

            $sent = count(array_filter($results, static fn (array $r): bool => ($r['status'] ?? '') === MessageStatus::SENT));
            $failed = array_values(array_filter(
                $results,
                static fn (array $r): bool => ($r['status'] ?? '') !== MessageStatus::SENT
            ));

            // رسالة الفشل تحمل **سبب الخادم الفعلي** لا عبارة عامة: المستخدم يجب أن يعرف أن
            // المفتاح منتهٍ أو الجلسة غير جاهزة بقراءة الإشعار نفسه.
            // The failure message carries the server's actual reason, not a generic phrase.
            $reason = (string) ($failed[0]['message'] ?? 'خطأ غير معروف');

            $message = match (true) {
                $sent === count($ids) => 'أُرسلت الرسائل مباشرة / Messages sent directly.',
                $sent === 0 => 'فشل الإرسال — '.$reason,
                default => "أُرسل {$sent} من ".count($ids).' — '.$reason,
            };

            return response()->json([
                'success' => $sent > 0,
                'message' => $message,
                'ids' => $ids,
                'queued' => 0,
                'results' => $results,
            ], $sent > 0 ? 200 : 502);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إضافة الرسالة', $e);
        }
    }

    /**
     * تعديل رسالة — الطابور مباشرة، والأرشيف عبر إنشاء صف جديد بالتعديلات
     * Edit a message — in place for the queue, as a new row for the archive.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $rules = [
            'msg_text' => ['required', 'string', 'max:4000'],
            'mobile_no' => ['nullable'],
            'customer_name' => ['nullable', 'string', 'max:250'],
            'provider_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
        ];

        if ($this->spec()->hasFileFields) {
            $rules += [
                'file_path' => ['nullable', 'string', 'max:1000'],
                'file_name' => ['nullable', 'string', 'max:255'],
                'file_type' => ['nullable', 'string', 'max:50'],
                'caption' => ['nullable', 'string', 'max:4000'],
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'msg_text.required' => 'نص الرسالة مطلوب / Message text is required.',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            $result = (new MessageWriter($this->spec(), $this->activity))
                ->update($id, $request->all(), $request, $this->actor($request));

            return $this->writeResponse($result);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل الرسالة', $e);
        }
    }

    /**
     * حذف رسالة (طابور أو أرشيف) — المؤرشف يُسلسَل في سجل النشاط قبل حذفه
     * Delete a message (queue or archive) — archived rows are serialised into the activity trail.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $result = (new MessageWriter($this->spec(), $this->activity))->delete($id, $request);

            return $this->writeResponse($result);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف الرسالة', $e);
        }
    }

    /**
     * إيقاف مؤقت (واتساب فقط)
     * Pause (WhatsApp only).
     */
    public function pause(Request $request, int $id): JsonResponse
    {
        if (! $this->spec()->supportsWorkerActions()) {
            return $this->unsupported();
        }

        try {
            return $this->writeResponse(
                (new MessageWriter($this->spec(), $this->activity))->pause($id, $request, $this->actor($request))
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إيقاف الرسالة', $e);
        }
    }

    /**
     * استئناف الإرسال (واتساب فقط)
     * Resume (WhatsApp only).
     */
    public function resume(Request $request, int $id): JsonResponse
    {
        if (! $this->spec()->supportsWorkerActions()) {
            return $this->unsupported();
        }

        try {
            return $this->writeResponse(
                (new MessageWriter($this->spec(), $this->activity))->resume($id, $request, $this->actor($request))
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في استئناف الرسالة', $e);
        }
    }

    /**
     * إرسال فوري يتجاوز دورة الطابور (واتساب فقط)
     * Send now, bypassing the queue schedule (WhatsApp only).
     */
    public function sendNow(Request $request, int $id): JsonResponse
    {
        if (! $this->spec()->supportsWorkerActions()) {
            return $this->unsupported();
        }

        try {
            $result = (new WhatsAppDirectSender($this->activity))->sendNow($id, $request, $this->actor($request));

            return $this->writeResponse($result);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في الإرسال الفوري', $e);
        }
    }

    /**
     * إعادة إرسال رسالة واحدة — صف جديد بمعرّف جديد
     * Resend one message — a new row with a new id.
     */
    public function resend(Request $request, int $id): JsonResponse
    {
        try {
            return $this->writeResponse(
                (new MessageWriter($this->spec(), $this->activity))->resend($id, $request, $this->actor($request))
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إعادة إرسال الرسالة', $e);
        }
    }

    /**
     * إعادة إرسال مجموعة
     * Resend a batch.
     */
    public function bulkResend(Request $request): JsonResponse
    {
        return $this->bulk($request, MessageActions::RESEND);
    }

    /**
     * إيقاف مجموعة (واتساب فقط)
     * Pause a batch (WhatsApp only).
     */
    public function bulkPause(Request $request): JsonResponse
    {
        if (! $this->spec()->supportsWorkerActions()) {
            return $this->unsupported();
        }

        return $this->bulk($request, MessageActions::PAUSE);
    }

    /**
     * حذف مجموعة
     * Delete a batch.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        return $this->bulk($request, MessageActions::DELETE);
    }

    /**
     * سجل المحاولات (واتساب فقط)
     * Attempt trail (WhatsApp only).
     */
    public function attempts(int $id): JsonResponse
    {
        if (! $this->spec()->supportsWorkerActions()) {
            return $this->unsupported();
        }

        try {
            $query = new MessageQuery($this->spec());
            if ($query->find($id) === null) {
                return $this->notFound($id);
            }

            return response()->json(['success' => true, 'data' => $query->attempts($id)]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب محاولات الرسالة', $e);
        }
    }

    /**
     * تاريخ حالات الرسالة (واتساب فقط)
     * Status history (WhatsApp only).
     */
    public function history(int $id): JsonResponse
    {
        if (! $this->spec()->supportsWorkerActions()) {
            return $this->unsupported();
        }

        try {
            $query = new MessageQuery($this->spec());
            if ($query->find($id) === null) {
                return $this->notFound($id);
            }

            return response()->json(['success' => true, 'data' => $query->history($id)]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب تاريخ الرسالة', $e);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * تنفيذ إجراء جماعي مع تحقق موحّد
     * Run a bulk action with shared validation.
     */
    private function bulk(Request $request, string $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ], [
            'ids.required' => 'اختر رسالة واحدة على الأقل / Select at least one message.',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            $result = (new MessageWriter($this->spec(), $this->activity))
                ->bulk($action, (array) $request->input('ids'), $request, $this->actor($request));

            return response()->json([
                'success' => $result['done'] > 0,
                'message' => "نجح {$result['done']} من {$result['requested']} / {$result['done']} of {$result['requested']} done.",
                'requested' => $result['requested'],
                'done' => $result['done'],
                'results' => $result['results'],
            ], $result['done'] > 0 ? 200 : 409);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تنفيذ الإجراء الجماعي', $e);
        }
    }

    /**
     * تحويل نتيجة طبقة الكتابة إلى استجابة
     * Turn a writer result into an HTTP response.
     *
     * @param  array{ok: bool, code: int, message: string, new_id?: int, status?: string, http?: int|null, provider_msg_id?: string|null}  $result
     */
    private function writeResponse(array $result): JsonResponse
    {
        $payload = [
            'success' => (bool) $result['ok'],
            'message' => (string) $result['message'],
        ];

        foreach (['new_id', 'message_id', 'status', 'http', 'provider_msg_id', 'ids'] as $key) {
            if (array_key_exists($key, $result)) {
                $payload[$key] = $result[$key];
            }
        }

        return response()->json($payload, (int) $result['code']);
    }

    /**
     * دالة الإذن المغلَّفة على `PermissionResolver` — مصدر `actions` في كل صف
     * The can() callback wrapped over PermissionResolver — the source of every row's `actions`.
     */
    private function canCallback(Request $request): callable
    {
        $user = $request->user();

        return fn (string $code): bool => $this->permissions->can($user, $code);
    }

    /**
     * الوجهة المطلوبة: من الطلب إن حُدّدت، وإلا أول اشتراك نشط للقناة
     * The requested destination: from the request when given, else the channel's first active sub.
     *
     * @return array{provider_id: int, service_id: int}|null
     */
    private function destination(Request $request, ChannelSpec $spec): ?array
    {
        $providerId = $request->input('provider_id');
        $serviceId = $request->input('service_id');

        if ($providerId && $serviceId) {
            $exists = DB::connection('oracle')->table('ACCOUNTS.PROVIDERS_DTL')
                ->where('PROVIDER_ID', (int) $providerId)
                ->where('SERVICE_ID', (int) $serviceId)
                ->where('STATUS', 1)
                ->exists();

            if ($exists) {
                return ['provider_id' => (int) $providerId, 'service_id' => (int) $serviceId];
            }
        }

        return MessageQueueWriter::resolveDestination($spec->key);
    }

    /**
     * @return list<int>
     */
    private function resolveNumbers(Request $request): array
    {
        $raw = [];

        if ($request->filled('mobile_no')) {
            $raw[] = $request->input('mobile_no');
        }

        foreach ((array) $request->input('mobile_nos', []) as $n) {
            $raw[] = $n;
        }

        $numbers = [];
        foreach ($raw as $n) {
            $digits = preg_replace('/\D/', '', (string) $n) ?? '';
            if ($digits === '') {
                continue;
            }
            $value = (int) $digits;
            if (strlen($digits) < 9 || strlen($digits) > 15 || $value === 0) {
                continue;
            }
            $numbers[] = $value;
        }

        return array_values(array_unique($numbers));
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'بيانات الرسالة غير صالحة / Validation failed.',
            'errors' => $errors,
        ], 422);
    }

    private function unsupported(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'هذا الإجراء غير مدعوم لقناة الرسائل النصية / This action is not supported for the SMS channel.',
        ], 422);
    }

    private function notFound(int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "الرسالة رقم [{$id}] غير موجودة / Message [{$id}] not found.",
        ], 404);
    }
}
