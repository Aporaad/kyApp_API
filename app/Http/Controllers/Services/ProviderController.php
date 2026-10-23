<?php

namespace App\Http\Controllers\Services;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكّم إدارة المزودين — جدول ACCOUNTS.PROVIDERS واشتراكاتهم ACCOUNTS.PROVIDERS_DTL
 * Provider management controller — Oracle ACCOUNTS.PROVIDERS and their PROVIDERS_DTL subscriptions.
 *
 * حساسيتان مصمّمتان هنا:
 *  1) بيانات الاعتماد (PASSWORD/TOKEN/USER_NAME) تُقنَّع `***` في القائمة والتفاصيل، ولا تُكشف
 *     في `show` إلا لمن يملك `providers.reveal_credentials`، مع توثيق الكشف في ActivityLogger.
 *  2) توليد المعرّف لا يعتمد sequence (لا يوجد على هذه الجداول) بل `NVL(MAX,0)+1`؛ التسابق مقبول
 *     لأن هذا سجل منخفض التزامن تماماً كالأنظمة القديمة، ويُلخَّص في تعليق الدالة.
 * Two deliberate safeguards:
 *  1) Credentials are masked `***` in list/show; shown only under providers.reveal_credentials and
 *     every reveal is written to the activity log.
 *  2) IDs use NVL(MAX,0)+1 (no sequence on these tables); the race is acceptable for this low-
 *     concurrency registry, mirroring the legacy systems.
 */
class ProviderController extends ServiceBaseController
{
    private const TABLE = 'ACCOUNTS.PROVIDERS';

    private const TABLE_DTL = 'ACCOUNTS.PROVIDERS_DTL';

    /** أعمدة الاعتماد الحساسة التي تُقنَّع افتراضياً / sensitive credential columns */
    private const CREDENTIAL_COLUMNS = ['PASSWORD', 'TOKEN', 'USER_NAME'];

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * قائمة المزودين (مع أقنعة للاعتبارات)
     * Provider list with credential masks
     */
    public function index(Request $request): JsonResponse
    {
        [$page, $limit, $offset] = $this->pagination($request);
        $search = trim((string) ($request->input('search') ?? ''));

        try {
            $query = DB::connection('oracle')->table(self::TABLE);

            if ($search !== '') {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $query->where(function ($q) use ($search, $oracleSearch) {
                    $q->where('PROVIDER_NAME', 'LIKE', '%'.$oracleSearch.'%')
                        ->orWhere('PROVIDER_NAME', 'LIKE', '%'.$search.'%')
                        ->orWhere('TYPE', 'LIKE', '%'.$search.'%');
                });
            }

            $total = $query->count();
            $rows = $query->orderBy('PROVIDER_ID')->offset($offset)->limit($limit)->get();

            $data = array_map(
                fn (array $row): array => $this->mask(ArabicEncoding::cleanData($row)),
                array_map(static fn ($r): array => (array) $r, $rows->all())
            );

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => $this->meta($total, $page, $limit),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة المزودين', $e);
        }
    }

    /**
     * تفاصيل مزود — الاعتبارات تُقنَّع إلا مع صلاحية الكشف
     * Provider detail — credentials masked unless the reveal permission is held
     */
    public function show(Request $request, int $providerId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE)
                ->where('PROVIDER_ID', $providerId)
                ->first();

            if (! $row) {
                return $this->notFound($providerId);
            }

            $data = ArabicEncoding::cleanData((array) $row);
            $canReveal = $this->permissions->can($request->user(), 'providers.reveal_credentials');

            if ($canReveal) {
                $this->activity->success('providers.reveal_credentials', [
                    'user_id' => $request->user()?->getAttribute('USER_ID'),
                    'module_code' => 'providers',
                    'entity_type' => 'PROVIDERS',
                    'entity_id' => $providerId,
                    'description_ar' => "إظهار بيانات اعتماد المزود #{$providerId}",
                ], $request);
            } else {
                $data = $this->mask($data);
            }

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب بيانات المزود', $e);
        }
    }

    /**
     * إضافة مزود جديد — المعرّف NVL(MAX,0)+1 (بلا sequence)
     * Create provider — NVL(MAX,0)+1 id (no sequence on the table)
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider_name' => ['required', 'string', 'max:250'],
            'type' => ['nullable', 'string', 'max:50'],
            'url' => ['nullable', 'string', 'max:1000'],
            'user_name' => ['nullable', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:250'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ], [
            'provider_name.required' => 'اسم المزود مطلوب / Provider name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المزود غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $id = $this->nextId(self::TABLE, 'PROVIDER_ID');
            $actor = $this->actor($request);

            DB::connection('oracle')->table(self::TABLE)->insert([
                'PROVIDER_ID' => $id,
                'PROVIDER_NAME' => ArabicEncoding::toOracleSearchChars(trim((string) $request->input('provider_name'))),
                'TYPE' => $request->input('type') ? trim((string) $request->input('type')) : null,
                'URL' => $request->input('url') ? trim((string) $request->input('url')) : null,
                'USER_NAME' => $request->input('user_name') ? trim((string) $request->input('user_name')) : null,
                'PASSWORD' => $request->input('password') ? trim((string) $request->input('password')) : null,
                'STATUS' => (int) ($request->input('status') ?? 1),
                'CREATED_AT' => DB::raw('SYSDATE'),
                'CREATED_BY' => $actor,
            ]);

            $this->activity->success('providers.create', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS',
                'entity_id' => $id,
                'description_ar' => "إضافة مزود #{$id}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة المزود بنجاح / Provider created successfully.',
                'provider_id' => $id,
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إضافة المزود', $e);
        }
    }

    /**
     * تعديل بيانات مزود
     * Update provider
     */
    public function update(Request $request, int $providerId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider_name' => ['required', 'string', 'max:250'],
            'type' => ['nullable', 'string', 'max:50'],
            'url' => ['nullable', 'string', 'max:1000'],
            'user_name' => ['nullable', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:250'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ], [
            'provider_name.required' => 'اسم المزود مطلوب / Provider name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المزود غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if (! DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->exists()) {
                return $this->notFound($providerId);
            }

            $data = [
                'PROVIDER_NAME' => ArabicEncoding::toOracleSearchChars(trim((string) $request->input('provider_name'))),
                'TYPE' => $request->input('type') ? trim((string) $request->input('type')) : null,
                'URL' => $request->input('url') ? trim((string) $request->input('url')) : null,
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $this->actor($request),
            ];

            if ($request->has('status')) {
                $data['STATUS'] = (int) $request->input('status');
            }

            // كلمة السر تُحدَّث فقط إذا أُرسلت (لا تُستبدل بأقنعة `***`)
            // Password is only overwritten when actually sent (never by a `***` mask).
            $password = $request->input('password');
            if ($password !== null && $password !== '' && $password !== '***') {
                $data['PASSWORD'] = trim((string) $password);
            }
            $userName = $request->input('user_name');
            if ($userName !== null && $userName !== '' && $userName !== '***') {
                $data['USER_NAME'] = trim((string) $userName);
            }

            DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->update($data);

            $this->activity->success('providers.update', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS',
                'entity_id' => $providerId,
                'description_ar' => "تعديل مزود #{$providerId}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل بيانات المزود بنجاح / Provider updated successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل بيانات المزود', $e);
        }
    }

    /**
     * تبديل حالة المزود (STATUS بين 1/0)
     * Toggle provider status (STATUS 1/0)
     */
    public function toggle(Request $request, int $providerId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE)
                ->select(['STATUS'])
                ->where('PROVIDER_ID', $providerId)
                ->first();

            if (! $row) {
                return $this->notFound($providerId);
            }

            // أوراكل/yajra يُرجع الأعمدة بحروف صغيرة (`status`)؛ القراءة بـ `['STATUS']` كانت تُفشل التبديل.
            // Oracle/yajra returns lowercase column names; read `status` first, `STATUS` as a fallback.
            $rowArr = (array) $row;
            $current = (int) ($rowArr['status'] ?? $rowArr['STATUS'] ?? 0);
            $new = $current === 1 ? 0 : 1;

            DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->update([
                'STATUS' => $new,
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $this->actor($request),
            ]);

            $this->activity->success('providers.toggle', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS',
                'entity_id' => $providerId,
                'description_ar' => "تبديل حالة المزود #{$providerId} إلى ".$new,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تبديل حالة المزود / Provider status toggled.',
                'status' => $new,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تبديل حالة المزود', $e);
        }
    }

    /**
     * حذف مزود
     * Delete provider
     */
    public function destroy(Request $request, int $providerId): JsonResponse
    {
        try {
            if (! DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->exists()) {
                return $this->notFound($providerId);
            }

            DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->delete();

            $this->activity->success('providers.delete', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS',
                'entity_id' => $providerId,
                'description_ar' => "حذف مزود #{$providerId}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف المزود بنجاح / Provider deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف المزود', $e);
        }
    }

    // ── اشتراكات المزود / PROVIDERS_DTL subscriptions ──────────────────────────

    /**
     * قائمة اشتراكات مزود (PROVIDERS_DTL) مع أقنعة للاعتبارات
     * List a provider's subscriptions (PROVIDERS_DTL) with credential masks
     */
    public function subscriptions(int $providerId): JsonResponse
    {
        try {
            $rows = DB::connection('oracle')->table(self::TABLE_DTL)
                ->where('PROVIDER_ID', $providerId)
                ->orderBy('ID')
                ->get();

            $data = array_map(
                fn (array $row): array => $this->mask($row),
                array_map(static fn ($r): array => ArabicEncoding::cleanData((array) $r), $rows->all())
            );

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب اشتراكات المزود', $e);
        }
    }

    /**
     * إضافة اشتراك لمزود
     * Create a provider subscription
     */
    public function storeSubscription(Request $request, int $providerId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_id' => ['required', 'integer'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'url' => ['nullable', 'string', 'max:1000'],
            'user_name' => ['nullable', 'string', 'max:1000'],
            'password' => ['nullable', 'string', 'max:1000'],
            'token' => ['nullable', 'string', 'max:4000'],
            'body' => ['nullable', 'string', 'max:4000'],
        ], [
            'service_id.required' => 'المعرف الخدمة مطلوب / Service id is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الاشتراك غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if (! DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->exists()) {
                return $this->notFound($providerId);
            }

            $id = $this->nextId(self::TABLE_DTL, 'ID');
            $actor = $this->actor($request);

            DB::connection('oracle')->table(self::TABLE_DTL)->insert([
                'ID' => $id,
                'PROVIDER_ID' => $providerId,
                'SERVICE_ID' => (int) $request->input('service_id'),
                'STATUS' => (int) ($request->input('status') ?? 1),
                'URL' => $request->input('url') ? trim((string) $request->input('url')) : null,
                'USER_NAME' => $request->input('user_name') ? trim((string) $request->input('user_name')) : null,
                'PASSWORD' => $request->input('password') ? trim((string) $request->input('password')) : null,
                'TOKEN' => $request->input('token') ? trim((string) $request->input('token')) : null,
                'BODY' => $request->input('body') ? (ArabicEncoding::toOracleSearchChars((string) $request->input('body'))) : null,
                'CREATED_AT' => DB::raw('SYSDATE'),
                'CREATED_BY' => $actor,
            ]);

            $this->activity->success('providers.subscriptions_manage', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS_DTL',
                'entity_id' => $id,
                'description_ar' => "إضافة اشتراك #{$id} للمزود #{$providerId}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة الاشتراك بنجاح / Subscription created successfully.',
                'id' => $id,
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إضافة اشتراك المزود', $e);
        }
    }

    /**
     * تعديل اشتراك
     * Update a subscription
     */
    public function updateSubscription(Request $request, int $dtlId): JsonResponse
    {
        try {
            if (! DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => "الاشتراك رقم [{$dtlId}] غير موجود / Subscription [{$dtlId}] not found.",
                ], 404);
            }

            $data = ['UPDATED_AT' => DB::raw('SYSDATE'), 'UPDATED_BY' => $this->actor($request)];

            foreach (['service_id' => 'SERVICE_ID', 'status' => 'STATUS', 'url' => 'URL'] as $key => $col) {
                if ($request->has($key)) {
                    $data[$col] = $key === 'url'
                        ? ($request->input('url') ? trim((string) $request->input('url')) : null)
                        : (int) $request->input($key);
                }
            }

            // القيم الحساسة: لا تُستبدل بأقنعة `***`
            // Sensitive values: never overwrite with `***` masks.
            foreach (['user_name' => 'USER_NAME', 'password' => 'PASSWORD', 'token' => 'TOKEN'] as $key => $col) {
                $v = $request->input($key);
                if ($v !== null && $v !== '' && $v !== '***') {
                    $data[$col] = trim((string) $v);
                }
            }
            if ($request->has('body')) {
                $data['BODY'] = ArabicEncoding::toOracleSearchChars((string) $request->input('body'));
            }

            DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->update($data);

            $this->activity->success('providers.subscriptions_manage', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS_DTL',
                'entity_id' => $dtlId,
                'description_ar' => "تعديل اشتراك #{$dtlId}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الاشتراك بنجاح / Subscription updated successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل اشتراك المزود', $e);
        }
    }

    /**
     * حذف اشتراك
     * Delete a subscription
     */
    public function deleteSubscription(Request $request, int $dtlId): JsonResponse
    {
        try {
            if (! DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => "الاشتراك رقم [{$dtlId}] غير موجود / Subscription [{$dtlId}] not found.",
                ], 404);
            }

            DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->delete();

            $this->activity->success('providers.subscriptions_manage', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS_DTL',
                'entity_id' => $dtlId,
                'description_ar' => "حذف اشتراك #{$dtlId}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الاشتراك بنجاح / Subscription deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف اشتراك المزود', $e);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * قناع أعمدة الاعتماد الحساسة بـ `***` إن كانت غير فارغة
     * Mask sensitive credential columns with `***` when non-empty
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mask(array $row): array
    {
        foreach (self::CREDENTIAL_COLUMNS as $col) {
            foreach ([$col, strtolower($col)] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                    $row[$key] = '***';
                }
            }
        }

        return $row;
    }

    /**
     * توليد المعرّف التالي: NVL(MAX(col),0)+1
     * Generate next id: NVL(MAX(col),0)+1 (no sequence on these legacy tables)
     *
     * ملاحظة تسابق / race note: read+insert غير ذريّين؛ التسابق مقبول لسجل منخفض التزامن. للحماية
     * الكاملة يُضاف sequence كقرار تشغيلي موثّق خارج نطاق هذا العمل.
     */
    private function nextId(string $table, string $column): int
    {
        $max = DB::connection('oracle')->table($table)->max($column);

        return (int) ($max ?? 0) + 1;
    }

    private function notFound(int $providerId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "المزود رقم [{$providerId}] غير موجود / Provider [{$providerId}] not found.",
        ], 404);
    }
}
