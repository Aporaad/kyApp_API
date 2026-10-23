<?php

namespace App\Http\Controllers\Services;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكّم إعدادات الخدمات — singleton جدول ACCOUNTS.CORP وقاموس ACCOUNTS.SERVICES
 * Service-configuration controller — the CORP singleton row and the SERVICES dictionary.
 *
 * `CORP` صف واحد بلا عمود مفتاح، فيُحدَّث بلا معرّف (نطاق هذا العمل) مع ترميز عربي صحيح عبر
 * `toOracleSearchChars`. تعديل `CORP` أخطر من قراءة `SERVICES`، لذلك يخلف `services.settings_manage`
 * بينما إدارة قاموس الخدمات خلف `services.update`.
 * CORP is a single keyless row updated in place; editing it (services.settings_manage) is riskier than
 * the SERVICES dictionary CRUD (services.update), so the two are separated.
 */
class ServiceConfigController extends ServiceBaseController
{
    private const TABLE_CORP = 'ACCOUNTS.CORP';

    private const TABLE_SERVICES = 'ACCOUNTS.SERVICES';

    /**
     * حالة الإعدادات: صف CORP + قائمة SERVICES
     * Config state: the CORP row plus the SERVICES list
     */
    public function show(): JsonResponse
    {
        try {
            $corp = DB::connection('oracle')->table(self::TABLE_CORP)->first();
            $services = DB::connection('oracle')->table(self::TABLE_SERVICES)
                ->orderBy('SERVICE_ID')
                ->get();

            return response()->json([
                'success' => true,
                'corp' => $corp ? ArabicEncoding::cleanData((array) $corp) : null,
                'services' => array_map(
                    static fn ($r): array => ArabicEncoding::cleanData((array) $r),
                    $services->all()
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب إعدادات الخدمات', $e);
        }
    }

    /**
     * تعديل صف CORP الوحيد (singleton)
     * Update the single CORP row
     */
    public function updateCorp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'corp_name' => ['nullable', 'string', 'max:100'],
            'corp_addr' => ['nullable', 'string', 'max:100'],
            'corp_tel1' => ['nullable', 'numeric'],
            'corp_tel2' => ['nullable', 'numeric'],
            'sanf_type' => ['nullable', 'integer'],
            'file_path' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الشركة غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $exists = DB::connection('oracle')->table(self::TABLE_CORP)->exists();

            $data = [
                'CORP_NAME' => $this->ar($request->input('corp_name')),
                'CORP_ADDR' => $this->ar($request->input('corp_addr')),
                'CORP_TEL1' => $request->filled('corp_tel1') ? (int) $request->input('corp_tel1') : null,
                'CORP_TEL2' => $request->filled('corp_tel2') ? (int) $request->input('corp_tel2') : null,
                'SANF_TYPE' => $request->has('sanf_type') ? (int) $request->input('sanf_type') : null,
                'FILE_PATH' => $request->input('file_path') ? trim((string) $request->input('file_path')) : null,
            ];

            if ($exists) {
                // singleton بلا مفتاح: UPDATE لكل الصف (صف وحيد)
                // Keyless singleton: update the whole (single) row.
                DB::connection('oracle')->table(self::TABLE_CORP)->update($data);
            } else {
                DB::connection('oracle')->table(self::TABLE_CORP)->insert($data);
            }

            app(ActivityLogger::class)->success('services.settings_manage', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'services',
                'entity_type' => 'CORP',
                'description_ar' => 'تعديل إعدادات الخدمات (CORP)',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ إعدادات الشركة / Corporate settings saved.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حفظ إعدادات الشركة', $e);
        }
    }

    /**
     * إضافة خدمة إلى القاموس
     * Create a SERVICES dictionary entry
     */
    public function storeService(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_name' => ['required', 'string', 'max:80'],
            'service_type' => ['nullable', 'string', 'max:250'],
        ], [
            'service_name.required' => 'اسم الخدمة مطلوب / Service name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الخدمة غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $id = (int) (DB::connection('oracle')->table(self::TABLE_SERVICES)->max('SERVICE_ID') ?? 0) + 1;

            DB::connection('oracle')->table(self::TABLE_SERVICES)->insert([
                'SERVICE_ID' => $id,
                'SERVICE_NAME' => ArabicEncoding::toOracleSearchChars(trim((string) $request->input('service_name'))),
                'SERVICE_TYPE' => $request->input('service_type')
                    ? ArabicEncoding::toOracleSearchChars(trim((string) $request->input('service_type')))
                    : null,
                'CREATED_AT' => DB::raw('SYSDATE'),
                'CREATED_BY' => $this->actor($request),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تمت إضافة الخدمة / Service created.',
                'service_id' => $id,
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إضافة الخدمة', $e);
        }
    }

    /**
     * تعديل خدمة
     * Update a SERVICES entry
     */
    public function updateService(Request $request, int $serviceId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_name' => ['required', 'string', 'max:80'],
            'service_type' => ['nullable', 'string', 'max:250'],
        ], [
            'service_name.required' => 'اسم الخدمة مطلوب / Service name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الخدمة غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if (! DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->exists()) {
                return $this->serviceNotFound($serviceId);
            }

            DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->update([
                'SERVICE_NAME' => ArabicEncoding::toOracleSearchChars(trim((string) $request->input('service_name'))),
                'SERVICE_TYPE' => $request->input('service_type')
                    ? ArabicEncoding::toOracleSearchChars(trim((string) $request->input('service_type')))
                    : null,
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $this->actor($request),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الخدمة / Service updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل الخدمة', $e);
        }
    }

    /**
     * حذف خدمة
     * Delete a SERVICES entry
     */
    public function destroyService(Request $request, int $serviceId): JsonResponse
    {
        try {
            if (! DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->exists()) {
                return $this->serviceNotFound($serviceId);
            }

            DB::connection('oracle')->table(self::TABLE_SERVICES)->where('SERVICE_ID', $serviceId)->delete();

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الخدمة / Service deleted.',
            ]);
        } catch (\Throwable $e) {
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
}
