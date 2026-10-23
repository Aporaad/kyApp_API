<?php

namespace App\Http\Controllers\Services;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * فئة مجردة لقنوات الرسائل (SMS وواتساب) — كل الكتابة «إدراج في الطابور» فقط
 * Abstract message-channel controller (SMS & WhatsApp) — every write is enqueue-only.
 *
 * التصميم الجوهري: الإرسال الفعلي يحدث داخل مهمة أوراكل/Java (`SEND_MSG`/`W_SEND_MSG`) تلتهم
 * `*_QUEUE` كل ثوانٍ؛ الـ API لا يرسل شبكياً أبلاً، فيدرج الصف بـ `IS_SENT='N'` ويترك المهمة ترسل.
 * دلالاة الحالة: `N` منتظرة (في `*_QUEUE`)، `Y` مُرسلة و`F` فاشلة (في `*_ARCHIVE`).
 * Core design: real sending happens inside the Oracle/Java jobs that drain *_QUEUE; the API never
 * sends synchronously — it enqueues with IS_SENT='N'. Status: N pending (queue), Y/F final (archive).
 */
abstract class MessageChannelController extends ServiceBaseController
{
    /** الجدول الطابور (منتظرة) / queue table (pending) */
    abstract protected function queueTable(): string;

    /** جدول الأرشفة (مُرسلة/فاشلة) / archive table (sent/failed) */
    abstract protected function archiveTable(): string;

    /** كود الوحدة للصلاحيات والتدقيق / module code for grants & audit */
    abstract protected function module(): string;

    /** الحقول الإضافية الخاصة بالقناة (واتساب: ملفات/توضيح) / channel-specific extra columns */
    abstract protected function extraColumns(): array;

    /** عمود رسالة الخطأ إن وُجد (واتساب) / optional error column (whatsapp) */
    protected function hasErrorColumn(): bool
    {
        return false;
    }

    /** الأعمدة الأساسية المشتركة / shared base columns */
    protected function baseColumns(): array
    {
        return ['ID', 'MSG_TEXT', 'MOBILE_NO', 'IS_SENT', 'CREATED_AT', 'CREATED_BY',
            'UPDATED_AT', 'UPDATED_BY', 'SERVICE_ID', 'PROVIDER_ID', 'CUSTOMER_NAME', 'SEND_ATTEMPT'];
    }

    /**
     * قائمة الرسائل حسب الحالة مع ترقيم صفحي وProjection للنص
     * Message list by status, server-paginated, with a text preview projection
     */
    public function index(Request $request): JsonResponse
    {
        [$page, $limit, $offset] = $this->pagination($request);
        $status = strtolower((string) ($request->input('status') ?? 'all'));
        $search = trim((string) ($request->input('search') ?? ''));

        try {
            // الحالة → مصدر البيانات: منتظرة من الطابور، مُرسلة/فاشلة من الأرشفة
            // Status → source: pending from queue, sent/failed from archive.
            $sources = match ($status) {
                'pending' => [['table' => $this->queueTable(), 'sent' => null]],
                'sent' => [['table' => $this->archiveTable(), 'sent' => 'Y']],
                'failed' => [['table' => $this->archiveTable(), 'sent' => 'F']],
                default => [
                    ['table' => $this->queueTable(), 'sent' => null],
                    ['table' => $this->archiveTable(), 'sent' => null],
                ],
            };

            $total = 0;
            $rows = collect();

            foreach ($sources as $src) {
                $query = DB::connection('oracle')->table($src['table'])
                    ->selectRaw("ID, SUBSTR(MSG_TEXT,1,80) AS MSG_TEXT_PREVIEW, MOBILE_NO, IS_SENT, TO_CHAR(CREATED_AT,'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT, SERVICE_ID, PROVIDER_ID, CUSTOMER_NAME, SEND_ATTEMPT");

                if ($src['sent'] !== null) {
                    $query->where('IS_SENT', $src['sent']);
                }

                if ($search !== '') {
                    $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                    $query->where(function ($q) use ($search, $oracleSearch) {
                        $q->where('CUSTOMER_NAME', 'LIKE', '%'.$oracleSearch.'%')
                            ->orWhere('CUSTOMER_NAME', 'LIKE', '%'.$search.'%')
                            ->orWhere('MOBILE_NO', 'LIKE', '%'.$search.'%');
                    });
                }

                $total += $query->count();
                $rows = $rows->merge($query->orderByDesc('ID')->get());
            }

            // دمج المصادر (all = queue + archive) ثم إعادة الترقيم على الدمج
            // For the merged `all` case, re-paginate across both sources.
            // mلاحظة: أوراكل/yajra يُرجع الأعمدة بحروف صغيرة (`id`)، فالقراءة بـ `['ID']` كانت تُفشل
            // الاستعلام عند status=all («Undefined array key "ID"»). نقرأ الصغير ثم الكبير احتياطاً.
            if (count($sources) > 1) {
                $sorted = $rows->sortByDesc(function ($r): int {
                    $arr = (array) $r;

                    return (int) ($arr['id'] ?? $arr['ID'] ?? 0);
                })->values();
                $rows = $sorted->slice($offset, $limit);
            } else {
                $rows = $rows->slice(0, $limit);
            }

            $data = array_map(static fn ($r): array => ArabicEncoding::cleanData((array) $r), $rows->all());

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => $this->meta($total, $page, $limit),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب سجل الرسائل', $e);
        }
    }

    /**
     * تفاصيل رسالة — النص الكامل، تُبحث في الطابور ثم الأرشفة
     * Message detail — full text; looked up in queue then archive
     */
    public function show(int $id): JsonResponse
    {
        try {
            $cols = $this->baseColumns();

            $row = DB::connection('oracle')->table($this->queueTable())->whereIn('ID', [$id])->first();
            $table = $this->queueTable();

            if (! $row) {
                $row = DB::connection('oracle')->table($this->archiveTable())->select($cols)->where('ID', $id)->first();
                $table = $this->archiveTable();
            }

            if (! $row) {
                return $this->notFound($id);
            }

            unset($cols); // الأعمدة تُقرأ كاملة من الجدول / columns read fully per table

            return response()->json([
                'success' => true,
                'data' => ArabicEncoding::cleanData((array) $row),
                'source' => $table === $this->queueTable() ? 'queue' : 'archive',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب تفاصيل الرسالة', $e);
        }
    }

    /**
     * إنشاء رسالة — إدراج في الطابور فقط بـ IS_SENT='N' (يدعم قائمة أرقام)
     * Create message — enqueue only with IS_SENT='N' (supports a list of numbers)
     */
    public function store(Request $request): JsonResponse
    {
        $base = [
            'msg_text' => ['required', 'string', 'max:4000'],
            'provider_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:250'],
        ];

        $validator = Validator::make($request->all(), $base + [
            'mobile_no' => ['required_without:mobile_nos', 'nullable'],
            'mobile_nos' => ['required_without:mobile_no', 'nullable', 'array'],
            'mobile_nos.*' => ['required', 'string'],
        ], [
            'msg_text.required' => 'نص الرسالة مطلوب / Message text is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الرسالة غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $numbers = $this->resolveNumbers($request);
        if ($numbers === []) {
            return response()->json([
                'success' => false,
                'message' => 'رقم جوال واحد على الأقل مطلوب / At least one mobile number is required.',
            ], 422);
        }

        try {
            $actor = $this->actor($request);
            $msgText = ArabicEncoding::toOracleSearchChars((string) $request->input('msg_text'));
            $customerName = $request->input('customer_name')
                ? ArabicEncoding::toOracleSearchChars(trim((string) $request->input('customer_name')))
                : null;

            $inserted = [];

            foreach ($numbers as $mobile) {
                $id = $this->nextQueueId();

                $row = [
                    'ID' => $id,
                    'MSG_TEXT' => $msgText,
                    'MOBILE_NO' => (int) $mobile,
                    'IS_SENT' => 'N',
                    'PROVIDER_ID' => $request->input('provider_id') ? (int) $request->input('provider_id') : null,
                    'SERVICE_ID' => $request->input('service_id') ? (int) $request->input('service_id') : null,
                    'CUSTOMER_NAME' => $customerName,
                    'SEND_ATTEMPT' => 0,
                    'CREATED_AT' => DB::raw('SYSDATE'),
                    'CREATED_BY' => $actor,
                ];

                foreach ($this->extraColumns() as $col => $key) {
                    $val = $request->input($key);
                    $row[$col] = ($val !== null && $val !== '') ? trim((string) $val) : null;
                }

                DB::connection('oracle')->table($this->queueTable())->insert($row);
                $inserted[] = $id;
            }

            $this->activity($request, $this->module().'.create', count($inserted).' رسالة في الطابور');

            return response()->json([
                'success' => true,
                'message' => 'تمت إضافة الرسائل إلى طابور الإرسال / Messages enqueued successfully.',
                'ids' => $inserted,
                'queued' => count($inserted),
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إضافة الرسالة', $e);
        }
    }

    /**
     * تعديل رسالة — مسموح على المنتظرة في الطابور فقط
     * Update message — only pending rows in the queue may change
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'msg_text' => ['required', 'string', 'max:4000'],
            'mobile_no' => ['nullable'],
            'customer_name' => ['nullable', 'string', 'max:250'],
        ], [
            'msg_text.required' => 'نص الرسالة مطلوب / Message text is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الرسالة غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $row = DB::connection('oracle')->table($this->queueTable())
                ->select(['ID', 'IS_SENT'])
                ->where('ID', $id)
                ->first();

            if (! $row) {
                return $this->notFound($id);
            }

            $data = [
                'MSG_TEXT' => ArabicEncoding::toOracleSearchChars((string) $request->input('msg_text')),
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $this->actor($request),
            ];

            if ($request->filled('mobile_no')) {
                $data['MOBILE_NO'] = (int) $request->input('mobile_no');
            }
            if ($request->has('customer_name')) {
                $data['CUSTOMER_NAME'] = $request->input('customer_name')
                    ? ArabicEncoding::toOracleSearchChars(trim((string) $request->input('customer_name')))
                    : null;
            }

            DB::connection('oracle')->table($this->queueTable())->where('ID', $id)->update($data);

            $this->activity($request, $this->module().'.update', "تعديل رسالة #{$id}");

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الرسالة / Message updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل الرسالة', $e);
        }
    }

    /**
     * وسم حالة رسالة في الطابور (IS_SENT) — لإعادة المحاولة/الإلغاء
     * Tag a queued message status (IS_SENT) — for retry/cancel
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_sent' => ['required', 'string', 'in:N,Y,F'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'حالة غير صالحة / Invalid status.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if (! DB::connection('oracle')->table($this->queueTable())->where('ID', $id)->exists()) {
                return $this->notFound($id);
            }

            DB::connection('oracle')->table($this->queueTable())->where('ID', $id)->update([
                'IS_SENT' => strtoupper((string) $request->input('is_sent')),
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $this->actor($request),
            ]);

            $this->activity($request, $this->module().'.toggle', "وسم رسالة #{$id} بالحالة ".$request->input('is_sent'));

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث حالة الرسالة / Message status updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تحديث حالة الرسالة', $e);
        }
    }

    /**
     * حذف رسالة من الطابور
     * Delete a queued message
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            if (! DB::connection('oracle')->table($this->queueTable())->where('ID', $id)->exists()) {
                return $this->notFound($id);
            }

            DB::connection('oracle')->table($this->queueTable())->where('ID', $id)->delete();

            $this->activity($request, $this->module().'.delete', "حذف رسالة #{$id}");

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الرسالة / Message deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف الرسالة', $e);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * توحيد الأرقام المرسلة (مفرد أو قائمة) ورفض غير الرقمي
     * Normalise sent numbers (single or list), rejecting non-numeric
     *
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
            $digits = preg_replace('/\D/', '', (string) $n);
            if ($digits !== '') {
                $numbers[] = (int) $digits;
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * المعرّف التالي في الطابور: NVL(MAX(ID),0)+1
     * Next queue id (no sequence): NVL(MAX(ID),0)+1
     */
    private function nextQueueId(): int
    {
        $max = DB::connection('oracle')->table($this->queueTable())->max('ID');

        return (int) ($max ?? 0) + 1;
    }

    private function activity(Request $request, string $code, string $desc): void
    {
        app(ActivityLogger::class)->success($code, [
            'user_id' => $request->user()?->getAttribute('USER_ID'),
            'module_code' => $this->module(),
            'entity_type' => $this->queueTable(),
            'description_ar' => $desc,
        ], $request);
    }

    private function notFound(int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "الرسالة رقم [{$id}] غير موجودة في الطابور / Message [{$id}] not found in queue.",
        ], 404);
    }
}
