<?php

namespace App\Http\Controllers\Services;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * متحكّم إعدادات الخدمات — صف `ACCOUNTS.CORP` الوحيد وقاموس `ACCOUNTS.SERVICES`
 * Service-configuration controller — the single CORP row and the SERVICES dictionary.
 *
 * `SERVICE_TYPE` ليس نصاً حراً: محرّك SMS يفرّع على قيمته الحرفية (`URL` / `FIREBASE` / `WHATSAPP`)،
 * فأي قيمة أخرى تعني رسائل لا تُرسل بصمت. لذلك هي قائمة مغلقة هنا وفي العميل.
 * `SERVICE_TYPE` is not free text: the SMS engine branches on its literal value, so any other value
 * means messages that silently never send. It is a closed list here and in the client.
 *
 * حذف خدمة مستخدمة في اشتراك يصطدم بـ`PROVIDERS_DTL_SRV_FK`؛ نُعيد 409 برسالة مفهومة لا 500.
 * Deleting a service referenced by a subscription hits PROVIDERS_DTL_SRV_FK; we answer 409, not 500.
 */
class ServiceConfigController extends ServiceBaseController
{
    private const TABLE_CORP = 'ACCOUNTS.CORP';

    private const TABLE_SERVICES = 'ACCOUNTS.SERVICES';

    /** الأنواع التي يفهمها محرّك الإرسال / the types the send engine understands */
    private const SERVICE_TYPES = ['URL', 'FIREBASE', 'WHATSAPP'];

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * حالة الإعدادات: صف CORP + قاموس SERVICES مع عدد اشتراكات كل خدمة
     * Config state: the CORP row plus the SERVICES dictionary with each service's subscription count.
     */
    public function show(): JsonResponse
    {
        try {
            $corp = DB::connection('oracle')->table(self::TABLE_CORP)->first();
            $services = DB::connection('oracle')->table(self::TABLE_SERVICES)
                ->orderBy('SERVICE_ID')
                ->get();

            $usageRows = DB::connection('oracle')->select(
                'SELECT SERVICE_ID, COUNT(*) AS CNT FROM ACCOUNTS.PROVIDERS_DTL GROUP BY SERVICE_ID'
            );
            $usage = [];
            foreach ($usageRows as $row) {
                $arr = array_change_key_case((array) $row, CASE_UPPER);
                $usage[(int) $arr['SERVICE_ID']] = (int) $arr['CNT'];
            }

            return response()->json([
                'success' => true,
                'corp' => $corp ? ArabicEncoding::cleanData((array) $corp) : null,
                'services' => array_map(function ($r) use ($usage): array {
                    $clean = ArabicEncoding::cleanData((array) $r);
                    $upper = array_change_key_case($clean, CASE_UPPER);
                    $clean['usage_count'] = $usage[(int) ($upper['SERVICE_ID'] ?? 0)] ?? 0;

                    return $clean;
                }, $services->all()),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب إعدادات الخدمات', $e);
        }
    }

    /**
     * خدمة واحدة + عدد الاشتراكات التي تستخدمها
     * One service plus the number of subscriptions using it.
     */
    public function showService(int $serviceId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE_SERVICES)
                ->where('SERVICE_ID', $serviceId)
                ->first();

            if (! $row) {
                return $this->serviceNotFound($serviceId);
            }

            $data = ArabicEncoding::cleanData((array) $row);
            $data['usage_count'] = DB::connection('oracle')->table('ACCOUNTS.PROVIDERS_DTL')
                ->where('SERVICE_ID', $serviceId)->count();

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب الخدمة', $e);
        }
    }

    /**
     * تعديل صف CORP الوحيد — الحقول الثلاثة الإلزامية تُتحقَّق قبل الكتابة
     * Update the single CORP row — the three NOT NULL fields are validated before the write.
     */
    public function updateCorp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'corp_name' => ['required', 'string', 'max:100'],
            'corp_addr' => ['required', 'string', 'max:100'],
            'corp_tel1' => ['nullable', 'digits_between:6,10'],
            'corp_tel2' => ['nullable', 'digits_between:6,10'],
            'sanf_type' => ['required', 'integer', 'min:0', 'max:99'],
            'file_path' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed('بيانات الشركة غير صالحة / Validation failed.', $validator->errors()->toArray());
        }

        try {
            $exists = DB::connection('oracle')->table(self::TABLE_CORP)->exists();

            $data = [
                'CORP_NAME' => $this->ar($request->input('corp_name')),
                'CORP_ADDR' => $this->ar($request->input('corp_addr')),
                'CORP_TEL1' => $request->filled('corp_tel1') ? (int) $request->input('corp_tel1') : null,
                'CORP_TEL2' => $request->filled('corp_tel2') ? (int) $request->input('corp_tel2') : null,
                'SANF_TYPE' => (int) $request->input('sanf_type'),
                'FILE_PATH' => $request->filled('file_path') ? trim((string) $request->input('file_path')) : null,
            ];

            if ($exists) {
                // singleton بلا مفتاح: UPDATE لكل الصف (صف وحيد)
                // Keyless singleton: update the whole (single) row.
                DB::connection('oracle')->table(self::TABLE_CORP)->update($data);
            } else {
                DB::connection('oracle')->table(self::TABLE_CORP)->insert($data);
            }

            $this->activity->success('services.settings_manage', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'services',
                'entity_type' => 'CORP',
                'description_ar' => 'تعديل إعدادات الخدمات (CORP)',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ إعدادات الشركة / Corporate settings saved.',
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حفظ إعدادات الشركة', $e);
        }
    }

    /**
     * إضافة خدمة إلى القاموس — النوع من القائمة المغلقة
     * Create a SERVICES entry — the type comes from the closed list.
     */
    public function storeService(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_name' => ['required', 'string', 'max:80'],
            'service_type' => ['required', Rule::in(self::SERVICE_TYPES)],
        ], [
            'service_name.required' => 'اسم الخدمة مطلوب / Service name is required.',
            'service_type.required' => 'نوع الخدمة مطلوب / Service type is required.',
            'service_type.in' => 'نوع الخدمة يجب أن يكون URL أو FIREBASE أو WHATSAPP / Service type must be URL, FIREBASE or WHATSAPP.',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed('بيانات الخدمة غير صالحة / Validation failed.', $validator->errors()->toArray());
        }

        try {
            $id = (int) (DB::connection('oracle')->table(self::TABLE_SERVICES)->max('SERVICE_ID') ?? 0) + 1;

            DB::connection('oracle')->table(self::TABLE_SERVICES)->insert([
                'SERVICE_ID' => $id,
                'SERVICE_NAME' => ArabicEncoding::toOracleSearchChars(trim((string) $request->input('service_name'))),
                'SERVICE_TYPE' => strtoupper(trim((string) $request->input('service_type'))),
                'CREATED_AT' => DB::raw('SYSDATE'),
                'CREATED_BY' => $this->actor($request),
            ]);

            $this->activity->success('services.update', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'services',
                'entity_type' => 'SERVICES',
                'entity_id' => $id,
                'description_ar' => "إضافة خدمة #{$id}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تمت إضافة الخدمة / Service created.',
                'service_id' => $id,
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في إضافة الخدمة', $e);
        }
    }

    /**
     * تعديل خدمة — الاسم والنوع
     * Update a SERVICES entry — name and type.
     */
    public function updateService(Request $request, int $serviceId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_name' => ['required', 'string', 'max:80'],
            'service_type' => ['required', Rule::in(self::SERVICE_TYPES)],
        ], [
            'service_name.required' => 'اسم الخدمة مطلوب / Service name is required.',
            'service_type.in' => 'نوع الخدمة يجب أن يكون URL أو FIREBASE أو WHATSAPP / Service type must be URL, FIREBASE or WHATSAPP.',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed('بيانات الخدمة غير صالحة / Validation failed.', $validator->errors()->toArray());
        }

        try {
            if (! DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->exists()) {
                return $this->serviceNotFound($serviceId);
            }

            DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->update([
                'SERVICE_NAME' => ArabicEncoding::toOracleSearchChars(trim((string) $request->input('service_name'))),
                'SERVICE_TYPE' => strtoupper(trim((string) $request->input('service_type'))),
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $this->actor($request),
            ]);

            $this->activity->success('services.update', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'services',
                'entity_type' => 'SERVICES',
                'entity_id' => $serviceId,
                'description_ar' => "تعديل خدمة #{$serviceId}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الخدمة / Service updated.',
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تعديل الخدمة', $e);
        }
    }

    /**
     * حذف خدمة — يمنعه وجود اشتراكات تستخدمها (FK) برسالة 409
     * Delete a service — blocked by subscriptions using it (FK) with 409.
     */
    public function destroyService(Request $request, int $serviceId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->first();
            if (! $row) {
                return $this->serviceNotFound($serviceId);
            }

            $subs = DB::connection('oracle')->table('ACCOUNTS.PROVIDERS_DTL')
                ->where('SERVICE_ID', $serviceId)
                ->get(['ID', 'PROVIDER_ID'])
                ->all();

            if ($subs !== []) {
                $ids = implode('، ', array_map(static function ($s): string {
                    $arr = array_change_key_case((array) $s, CASE_UPPER);

                    return '#'.$arr['ID'];
                }, $subs));

                return response()->json([
                    'success' => false,
                    'message' => 'لا يمكن حذف الخدمة: مستخدمة في الاشتراكات '.$ids.' — عطّلها أو احذف اشتراكاتها أولاً / The service is still used by subscriptions.',
                    'subscriptions' => array_map(static fn ($s): array => array_change_key_case((array) $s, CASE_UPPER), $subs),
                ], 409);
            }

            DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->delete();

            $this->activity->success('services.update', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'services',
                'entity_type' => 'SERVICES',
                'entity_id' => $serviceId,
                'description_ar' => "حذف خدمة #{$serviceId}",
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الخدمة / Service deleted.',
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حذف الخدمة', $e);
        }
    }

    private function ar(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return ($value !== null && $value !== '')
            ? (ArabicEncoding::toOracleSearchChars($value) ?? $value)
            : null;
    }

    private function serviceNotFound(int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "الخدمة رقم [{$id}] غير موجودة / Service [{$id}] not found.",
        ], 404);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationFailed(string $message, array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }
}
