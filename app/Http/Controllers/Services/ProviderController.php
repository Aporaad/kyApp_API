<?php

namespace App\Http\Controllers\Services;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\Messaging\SubscriptionProbe;
use App\Support\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * متحكّم إدارة المزودين — `ACCOUNTS.PROVIDERS` واشتراكاتهم `ACCOUNTS.PROVIDERS_DTL`
 * Provider management — ACCOUNTS.PROVIDERS and their PROVIDERS_DTL subscriptions.
 *
 * حساسيتان مصمّمتان هنا:
 *  1) بيانات الاعتماد تُقنَّع `***` في القائمة والتفاصيل دائماً؛ ولا تُكشف إلا عبر مسار مستقل
 *     (`credentials`) يملك صلاحية `providers.reveal_credentials` وكل كشف يُكتب في سجل النشاط.
 *  2) الحذف والتعطيل محروسان بقيود القاعدة: اشتراك تابع يمنع حذف المزود (409)، وآخر اشتراك نشط
 *     لقناة لا يُعطَّل ولا يُحذف (409) لأن ذلك يوقف الإرسال بصمت.
 * Two deliberate safeguards: credentials are always masked except on a dedicated, audited route; and
 * deletion/deactivation is guarded by the database's own FKs plus a last-active-subscription rule.
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
        private readonly SubscriptionProbe $probe,
    ) {}

    /**
     * قائمة المزودين (مع أقنعة للاعتبارات + عدد الاشتراكات ووجود سر)
     * Provider list with credential masks, subscription count, and credential presence flags.
     */
    public function index(Request $request): JsonResponse
    {
        [$page, $limit, $offset] = $this->pagination($request);
        $search = trim((string) ($request->input('search') ?? ''));
        $status = $request->input('status');

        try {
            $query = DB::connection('oracle')->table(self::TABLE);

            if ($search !== '') {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $query->where(function ($q) use ($search, $oracleSearch) {
                    $q->where('PROVIDER_NAME', 'LIKE', '%'.$oracleSearch.'%')
                        ->orWhere('PROVIDER_NAME', 'LIKE', '%'.$search.'%')
                        ->orWhere('TYPE', 'LIKE', '%'.$search.'%')
                        ->orWhere('URL', 'LIKE', '%'.$search.'%');
                });
            }

            if ($status !== null && $status !== '' && $status !== 'all') {
                $query->where('STATUS', (int) $status);
            }

            $total = $query->count();
            $rows = $query->orderBy('PROVIDER_ID')->offset($offset)->limit($limit)->get();

            $counts = DB::connection('oracle')->select(
                'SELECT PROVIDER_ID, COUNT(*) AS CNT FROM '.self::TABLE_DTL.' GROUP BY PROVIDER_ID'
            );
            $subsByProvider = [];
            foreach ($counts as $c) {
                $arr = array_change_key_case((array) $c, CASE_UPPER);
                $subsByProvider[(int) $arr['PROVIDER_ID']] = (int) $arr['CNT'];
            }

            $data = array_map(function ($row) use ($subsByProvider): array {
                $clean = ArabicEncoding::cleanData((array) $row);
                $upper = array_change_key_case($clean, CASE_UPPER);
                $masked = $this->mask($clean);

                $masked['subscriptions_count'] = $subsByProvider[(int) $upper['PROVIDER_ID']] ?? 0;
                $masked['credentials_configured'] = ($upper['USER_NAME'] ?? null) !== null
                    || ($upper['PASSWORD'] ?? null) !== null;

                return $masked;
            }, $rows->all());

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => $this->meta($total, $page, $limit),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة المزودين', $e);
        }
    }

    /**
     * تفاصيل مزود — الاعتبارات مقنّعة دائماً؛ الكشف له مساره المستقل
     * Provider detail — credentials are always masked here; revealing has its own route.
     */
    public function show(int $providerId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE)
                ->where('PROVIDER_ID', $providerId)
                ->first();

            if (! $row) {
                return $this->notFound($providerId);
            }

            $data = $this->mask(ArabicEncoding::cleanData((array) $row));
            $data['subscriptions_count'] = DB::connection('oracle')->table(self::TABLE_DTL)
                ->where('PROVIDER_ID', $providerId)->count();

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب بيانات المزود', $e);
        }
    }

    /**
     * كشف بيانات اعتماد مزود — مسار صريح، صلاحية مستقلة، وسطر تدقيق
     * Reveal a provider's credentials — an explicit route, its own permission, and an audit line.
     */
    public function revealCredentials(Request $request, int $providerId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE)
                ->where('PROVIDER_ID', $providerId)
                ->first();

            if (! $row) {
                return $this->notFound($providerId);
            }

            $this->activity->success('providers.reveal_credentials', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS',
                'entity_id' => $providerId,
                'description_ar' => "إظهار بيانات اعتماد المزود #{$providerId}",
            ], $request);

            $data = array_change_key_case(ArabicEncoding::cleanData((array) $row), CASE_UPPER);

            return response()->json([
                'success' => true,
                'data' => [
                    'provider_id' => $providerId,
                    'user_name' => $data['USER_NAME'] ?? null,
                    'password' => $data['PASSWORD'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في كشف بيانات اعتماد المزود', $e);
        }
    }

    /**
     * إضافة مزود — `TYPE` إلزامي (عمود NOT NULL في القاعدة)
     * Create a provider — `TYPE` is required (a NOT NULL column).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            $id = $this->nextId(self::TABLE, 'PROVIDER_ID');
            $actor = $this->actor($request);

            DB::connection('oracle')->table(self::TABLE)->insert($this->columns($request, $actor, creating: true) + [
                'PROVIDER_ID' => $id,
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
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في إضافة المزود', $e);
        }
    }

    /**
     * تعديل مزود — القيم المقنّعة `***` لا تُكتب أبداً
     * Update a provider — `***` masks are never written.
     */
    public function update(Request $request, int $providerId): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            if (! DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->exists()) {
                return $this->notFound($providerId);
            }

            $data = $this->columns($request, $this->actor($request), creating: false);
            $data['UPDATED_AT'] = DB::raw('SYSDATE');

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
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تعديل بيانات المزود', $e);
        }
    }

    /**
     * تبديل حالة المزود
     * Toggle the provider's status.
     */
    public function toggle(Request $request, int $providerId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE)
                ->where('PROVIDER_ID', $providerId)
                ->first();

            if (! $row) {
                return $this->notFound($providerId);
            }

            $arr = array_change_key_case((array) $row, CASE_UPPER);
            $current = (int) ($arr['STATUS'] ?? 0);
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
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تبديل حالة المزود', $e);
        }
    }

    /**
     * حذف مزود — يمنعه وجود اشتراكات تابعة (FK) برسالة 409 لا 500
     * Delete a provider — blocked by dependent subscriptions (FK) with 409, not 500.
     */
    public function destroy(Request $request, int $providerId): JsonResponse
    {
        try {
            if (! DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->exists()) {
                return $this->notFound($providerId);
            }

            $count = DB::connection('oracle')->table(self::TABLE_DTL)
                ->where('PROVIDER_ID', $providerId)->count();

            if ($count > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "لا يمكن حذف المزود: لديه {$count} اشتراكاً. احذف اشتراكاته أولاً / Provider has {$count} subscription(s).",
                    'subscriptions_count' => $count,
                ], 409);
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
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حذف المزود', $e);
        }
    }

    // ── اشتراكات المزود / PROVIDERS_DTL subscriptions ──────────────────────────

    /**
     * قائمة اشتراكات مزود — مع اسم الخدمة ونوعها (لا أرقام خامّة)
     * A provider's subscriptions — with the service's name and type (no bare ids).
     */
    public function subscriptions(int $providerId): JsonResponse
    {
        try {
            $rows = DB::connection('oracle')->table(self::TABLE_DTL.' as d')
                ->leftJoin('ACCOUNTS.SERVICES as s', 's.SERVICE_ID', '=', 'd.SERVICE_ID')
                ->select([
                    'd.ID', 'd.PROVIDER_ID', 'd.SERVICE_ID', 'd.STATUS', 'd.URL',
                    'd.USER_NAME', 'd.PASSWORD', 'd.TOKEN', 'd.BODY',
                    'd.SEND_ATTEMPT_CNT', 'd.SENT_ARCHIVE',
                    'd.CREATED_AT', 'd.CREATED_BY', 'd.UPDATED_AT', 'd.UPDATED_BY',
                    's.SERVICE_NAME', 's.SERVICE_TYPE',
                ])
                ->where('d.PROVIDER_ID', $providerId)
                ->orderBy('d.ID')
                ->get();

            $data = array_map(function ($row): array {
                $clean = ArabicEncoding::cleanData((array) $row);
                $masked = $this->mask($clean);
                $upper = array_change_key_case($clean, CASE_UPPER);

                $masked['has_session'] = ($upper['PASSWORD'] ?? null) !== null && $upper['PASSWORD'] !== '';
                $masked['has_token'] = ($upper['TOKEN'] ?? null) !== null && $upper['TOKEN'] !== '';

                return $masked;
            }, $rows->all());

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب اشتراكات المزود', $e);
        }
    }

    /**
     * تفاصيل اشتراك واحد (مقنَّعة) + اسم الخدمة + عدد رسائل نفس الوجهة
     * One subscription in detail (masked) plus its service and the destination's message count.
     */
    public function showSubscription(int $providerId, int $dtlId): JsonResponse
    {
        try {
            $row = $this->subscriptionRow($providerId, $dtlId);
            if ($row === null) {
                return $this->subscriptionNotFound($dtlId);
            }

            $data = $this->mask(ArabicEncoding::cleanData((array) $row));
            $upper = array_change_key_case((array) $row, CASE_UPPER);

            return response()->json([
                'success' => true,
                'data' => $data + [
                    'has_session' => ($upper['PASSWORD'] ?? null) !== null && $upper['PASSWORD'] !== '',
                    'has_token' => ($upper['TOKEN'] ?? null) !== null && $upper['TOKEN'] !== '',
                    'messages_count' => $this->messagesCount((int) $upper['PROVIDER_ID'], (int) $upper['SERVICE_ID']),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب تفاصيل الاشتراك', $e);
        }
    }

    /**
     * كشف أسرار الاشتراك — بصلاحية الكشف وبتدقيق
     * Reveal a subscription's secrets — behind the reveal grant and with an audit line.
     */
    public function revealSubscription(Request $request, int $providerId, int $dtlId): JsonResponse
    {
        try {
            $row = $this->subscriptionRow($providerId, $dtlId);
            if ($row === null) {
                return $this->subscriptionNotFound($dtlId);
            }

            $upper = array_change_key_case(ArabicEncoding::cleanData((array) $row), CASE_UPPER);

            $this->activity->success('providers.reveal_credentials', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS_DTL',
                'entity_id' => $dtlId,
                'description_ar' => "إظهار بيانات اعتماد الاشتراك #{$dtlId} (المزود #{$providerId})",
            ], $request);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $dtlId,
                    'provider_id' => $providerId,
                    'service_id' => $upper['SERVICE_ID'] !== null ? (int) $upper['SERVICE_ID'] : null,
                    'user_name' => $upper['USER_NAME'] ?? null,
                    'password' => $upper['PASSWORD'] ?? null,
                    'token' => $upper['TOKEN'] ?? null,
                    'body' => $upper['BODY'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في كشف بيانات اعتماد الاشتراك', $e);
        }
    }

    /**
     * إضافة اشتراك — يتحقق من وجود الخدمة (FK) ومن عدم تكرار اشتراك نشط
     * Create a subscription — validates the service exists (FK) and that no active duplicate exists.
     */
    public function storeSubscription(Request $request, int $providerId): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->subscriptionRules());

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            if (! DB::connection('oracle')->table(self::TABLE)->where('PROVIDER_ID', $providerId)->exists()) {
                return $this->notFound($providerId);
            }

            $serviceId = (int) $request->input('service_id');

            if (! DB::connection('oracle')->table('ACCOUNTS.SERVICES')->where('SERVICE_ID', $serviceId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => "الخدمة رقم [{$serviceId}] غير موجودة / Service [{$serviceId}] not found.",
                ], 422);
            }

            $duplicate = DB::connection('oracle')->table(self::TABLE_DTL)
                ->where('PROVIDER_ID', $providerId)
                ->where('SERVICE_ID', $serviceId)
                ->where('STATUS', 1)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'يوجد اشتراك نشط بالفعل لهذا المزود على نفس الخدمة — التكرار يسبب إرسالاً مزدوجاً / An active subscription already exists for this provider and service.',
                ], 409);
            }

            $id = $this->nextId(self::TABLE_DTL, 'ID');
            $actor = $this->actor($request);

            DB::connection('oracle')->table(self::TABLE_DTL)->insert($this->subscriptionColumns($request, $actor, creating: true) + [
                'ID' => $id,
                'PROVIDER_ID' => $providerId,
                'SERVICE_ID' => $serviceId,
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
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في إضافة اشتراك المزود', $e);
        }
    }

    /**
     * تعديل اشتراك — كل الحقول، والقيم المقنّعة لا تُكتب
     * Update a subscription — every field; masks are never written.
     */
    public function updateSubscription(Request $request, int $dtlId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'url' => ['nullable', 'string', 'max:1000'],
            'user_name' => ['nullable', 'string', 'max:1000'],
            'password' => ['nullable', 'string', 'max:1000'],
            'token' => ['nullable', 'string', 'max:4000'],
            'body' => ['nullable', 'string', 'max:4000'],
            'send_attempt_cnt' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'sent_archive' => ['sometimes', 'integer', 'in:0,1'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        try {
            $row = DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->first();
            if (! $row) {
                return $this->subscriptionNotFound($dtlId);
            }

            if ($request->has('service_id')) {
                $serviceId = (int) $request->input('service_id');
                if (! DB::connection('oracle')->table('ACCOUNTS.SERVICES')->where('SERVICE_ID', $serviceId)->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => "الخدمة رقم [{$serviceId}] غير موجودة / Service [{$serviceId}] not found.",
                    ], 422);
                }
            }

            if ($request->has('status') && (int) $request->input('status') === 0) {
                $guard = $this->lastActiveGuard((array) $row);
                if ($guard !== null) {
                    return $guard;
                }
            }

            $data = $this->subscriptionColumns($request, $this->actor($request), creating: false);
            $data['UPDATED_AT'] = DB::raw('SYSDATE');

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
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تعديل اشتراك المزود', $e);
        }
    }

    /**
     * تفعيل/تعطيل اشتراك — مع حارس «آخر اشتراك نشط»
     * Toggle a subscription — with the last-active-subscription guard.
     */
    public function toggleSubscription(Request $request, int $dtlId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->first();
            if (! $row) {
                return $this->subscriptionNotFound($dtlId);
            }

            $arr = array_change_key_case((array) $row, CASE_UPPER);
            $current = (int) ($arr['STATUS'] ?? 0);

            if ($current === 1) {
                $guard = $this->lastActiveGuard($arr);
                if ($guard !== null) {
                    return $guard;
                }
            }

            $new = $current === 1 ? 0 : 1;

            DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->update([
                'STATUS' => $new,
                'UPDATED_AT' => DB::raw('SYSDATE'),
                'UPDATED_BY' => $this->actor($request),
            ]);

            $this->activity->success('providers.subscriptions_manage', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS_DTL',
                'entity_id' => $dtlId,
                'description_ar' => "تبديل حالة الاشتراك #{$dtlId} إلى ".$new,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => $new === 1
                    ? 'تم تفعيل الاشتراك / Subscription enabled.'
                    : 'تم تعطيل الاشتراك / Subscription disabled.',
                'status' => $new,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تبديل حالة الاشتراك', $e);
        }
    }

    /**
     * فحص اتصال الاشتراك — لا يرسل رسالة، ويقرأ العنوان من الصف
     * Probe the subscription — sends nothing and reads its target from the stored row.
     */
    public function testSubscription(Request $request, int $dtlId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->first();
            if (! $row) {
                return $this->subscriptionNotFound($dtlId);
            }

            $arr = array_change_key_case((array) $row, CASE_UPPER);
            $serviceType = $this->serviceType((int) ($arr['SERVICE_ID'] ?? 0));

            $result = $this->probe->probe($arr, $serviceType);

            $this->activity->success('providers.subscriptions_manage', [
                'user_id' => $request->user()?->getAttribute('USER_ID'),
                'module_code' => 'providers',
                'entity_type' => 'PROVIDERS_DTL',
                'entity_id' => $dtlId,
                'description_ar' => "فحص اتصال الاشتراك #{$dtlId} → ".$result['verdict'],
            ], $request);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في فحص اتصال الاشتراك', $e);
        }
    }

    /**
     * حذف اشتراك — لا يُحذف آخر اشتراك نشط لقناة
     * Delete a subscription — never the last active one for a channel.
     */
    public function deleteSubscription(Request $request, int $dtlId): JsonResponse
    {
        try {
            $row = DB::connection('oracle')->table(self::TABLE_DTL)->where('ID', $dtlId)->first();
            if (! $row) {
                return $this->subscriptionNotFound($dtlId);
            }

            $guard = $this->lastActiveGuard(array_change_key_case((array) $row, CASE_UPPER));
            if ($guard !== null) {
                return $guard;
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
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حذف اشتراك المزود', $e);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'provider_name' => ['required', 'string', 'min:2', 'max:250'],
            'type' => ['required', 'string', 'max:50'],
            'url' => ['nullable', 'string', 'max:1000'],
            'user_name' => ['nullable', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:250'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionRules(): array
    {
        return [
            'service_id' => ['required', 'integer'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'url' => ['nullable', 'string', 'max:1000'],
            'user_name' => ['nullable', 'string', 'max:1000'],
            'password' => ['nullable', 'string', 'max:1000'],
            'token' => ['nullable', 'string', 'max:4000'],
            'body' => ['nullable', 'string', 'max:4000'],
            'send_attempt_cnt' => ['nullable', 'integer', 'min:0', 'max:20'],
            'sent_archive' => ['nullable', 'integer', 'in:0,1'],
        ];
    }

    /**
     * أعمدة المزود من الطلب — `TYPE` يُطبَّع بأحرف كبيرة، والسر لا يُستبدل بقناع
     * The provider's columns from the request — TYPE uppercased, and a mask never overwrites a secret.
     *
     * @return array<string, mixed>
     */
    private function columns(Request $request, ?string $actor, bool $creating): array
    {
        $data = [
            'PROVIDER_NAME' => ArabicEncoding::toOracleSearchChars(trim((string) $request->input('provider_name'))),
            'TYPE' => strtoupper(trim((string) $request->input('type'))),
            'URL' => $request->filled('url') ? trim((string) $request->input('url')) : null,
        ];

        if ($creating) {
            $data['STATUS'] = (int) ($request->input('status') ?? 1);
            $data['USER_NAME'] = $request->filled('user_name')
                ? ArabicEncoding::toOracleSearchChars(trim((string) $request->input('user_name')))
                : null;
            $data['PASSWORD'] = $request->filled('password')
                ? ArabicEncoding::toOracleSearchChars(trim((string) $request->input('password')))
                : null;
        } else {
            if ($request->has('status')) {
                $data['STATUS'] = (int) $request->input('status');
            }
            foreach (['user_name' => 'USER_NAME', 'password' => 'PASSWORD'] as $key => $column) {
                $value = $request->input($key);
                if ($value !== null && $value !== '' && $value !== '***') {
                    // الأسرار تمرّ على مُرمِّز أوراكل مثل أي نص آخر: الكتابة الخام لمستخدم/كلمة سر
                    // عربية تخزّن `¿` لأن الجدول WE8MSWIN1252 (والـASCII لا يتغيّر).
                    // Secrets go through the Oracle encoder like any other text: raw binding of an
                    // Arabic user/password stores `¿` because the column is WE8MS1252 (ASCII is
                    // unchanged by the encoder).
                    $data[$column] = ArabicEncoding::toOracleSearchChars(trim((string) $value));
                }
            }
            $data['UPDATED_BY'] = $actor;
        }

        return $data;
    }

    /**
     * أعمدة الاشتراك من الطلب
     * The subscription's columns from the request.
     *
     * @return array<string, mixed>
     */
    private function subscriptionColumns(Request $request, ?string $actor, bool $creating): array
    {
        $data = [];

        if ($request->has('service_id')) {
            $data['SERVICE_ID'] = (int) $request->input('service_id');
        }
        if ($request->has('status')) {
            $data['STATUS'] = (int) $request->input('status');
        } elseif ($creating) {
            $data['STATUS'] = 1;
        }
        if ($request->has('send_attempt_cnt')) {
            $data['SEND_ATTEMPT_CNT'] = (int) $request->input('send_attempt_cnt');
        } elseif ($creating) {
            $data['SEND_ATTEMPT_CNT'] = 3;
        }
        if ($request->has('sent_archive')) {
            $data['SENT_ARCHIVE'] = (int) $request->input('sent_archive');
        } elseif ($creating) {
            $data['SENT_ARCHIVE'] = 1;
        }
        if ($request->has('url')) {
            $value = trim((string) $request->input('url'));
            $data['URL'] = $value !== '' ? $value : null;
        }

        foreach (['user_name' => 'USER_NAME', 'password' => 'PASSWORD', 'token' => 'TOKEN'] as $key => $column) {
            $value = $request->input($key);
            if ($value !== null && $value !== '' && $value !== '***') {
                // نفس قاعدة النص: مُرمِّز أوراكل قبل الربط، وإلا صار السرّ غير المعرّف في الجدول `¿`
                // Same text rule: encode before binding, otherwise a non-ASCII secret lands as `¿`.
                $data[$column] = ArabicEncoding::toOracleSearchChars(trim((string) $value));
            }
        }

        if ($request->has('body')) {
            $body = trim((string) $request->input('body'));
            $data['BODY'] = $body !== '' ? ArabicEncoding::toOracleSearchChars($body) : null;
        }

        if (! $creating) {
            $data['UPDATED_BY'] = $actor;
        }

        return $data;
    }

    /**
     * حارس «آخر اشتراك نشط»: تعطيله/حذفه يوقف إرسال القناة بصمت
     * The last-active guard: disabling/deleting it silently stops the channel's sending.
     */
    private function lastActiveGuard(array $row): ?JsonResponse
    {
        if ((int) ($row['STATUS'] ?? 0) !== 1) {
            return null;
        }

        $serviceType = strtoupper((string) $this->serviceType((int) ($row['SERVICE_ID'] ?? 0)));
        $family = $serviceType === 'WHATSAPP' ? ['WHATSAPP'] : ['URL', 'FIREBASE'];

        $types = implode(', ', array_map(static fn (string $t): string => "'".$t."'", $family));

        $rowCount = array_change_key_case((array) DB::connection('oracle')->selectOne(
            "SELECT COUNT(*) AS CNT
               FROM ACCOUNTS.PROVIDERS_DTL d
               JOIN ACCOUNTS.SERVICES s ON s.SERVICE_ID = d.SERVICE_ID
              WHERE d.STATUS = 1 AND s.SERVICE_TYPE IN ({$types})"
        ), CASE_UPPER);
        $active = (int) ($rowCount['CNT'] ?? 0);

        if ($active > 1) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'هذا آخر اشتراك نشط للقناة؛ تعطيله أو حذفه يوقف كل الإرسال / This is the channel\'s last active subscription.',
        ], 409);
    }

    /**
     * صف اشتراك يخص المزود المذكور (يمنع الوصول عبر معرّف مزوّد آخر)
     * A subscription row that belongs to the given provider (blocks cross-provider access).
     *
     * @return array<string, mixed>|null
     */
    private function subscriptionRow(int $providerId, int $dtlId): ?array
    {
        $row = DB::connection('oracle')->table(self::TABLE_DTL.' as d')
            ->leftJoin('ACCOUNTS.SERVICES as s', 's.SERVICE_ID', '=', 'd.SERVICE_ID')
            ->select([
                'd.ID', 'd.PROVIDER_ID', 'd.SERVICE_ID', 'd.STATUS', 'd.URL', 'd.USER_NAME',
                'd.PASSWORD', 'd.TOKEN', 'd.BODY', 'd.SEND_ATTEMPT_CNT', 'd.SENT_ARCHIVE',
                'd.CREATED_AT', 'd.CREATED_BY', 'd.UPDATED_AT', 'd.UPDATED_BY',
                's.SERVICE_NAME', 's.SERVICE_TYPE',
            ])
            ->where('d.PROVIDER_ID', $providerId)
            ->where('d.ID', $dtlId)
            ->first();

        return $row ? (array) $row : null;
    }

    private function serviceType(int $serviceId): ?string
    {
        $row = DB::connection('oracle')->table('ACCOUNTS.SERVICES')
            ->where('SERVICE_ID', $serviceId)->first();

        if (! $row) {
            return null;
        }

        $arr = array_change_key_case((array) $row, CASE_UPPER);

        return $arr['SERVICE_TYPE'] ?? null;
    }

    /**
     * عدد رسائل نفس الوجهة (مزود/خدمة) عبر الطابور والأرشيف — يتعامل مع اختلاف أسماء الجداول
     * Message count for the same destination across queue and archive, per channel table names.
     */
    private function messagesCount(int $providerId, int $serviceId): int
    {
        $serviceType = strtoupper((string) $this->serviceType($serviceId));
        $tables = $serviceType === 'WHATSAPP'
            ? ['ACCOUNTS.W_MSG_QUEUE', 'ACCOUNTS.W_MSG_ARCHIVE']
            : ['ACCOUNTS.MSG_QUEUE', 'ACCOUNTS.MSG_ARCHIVE'];

        $total = 0;
        foreach ($tables as $table) {
            $total += (int) DB::connection('oracle')->table($table)
                ->where('PROVIDER_ID', $providerId)
                ->where('SERVICE_ID', $serviceId)
                ->count();
        }

        return $total;
    }

    /**
     * قناع أعمدة الاعتماد الحساسة بـ`***` إن كانت غير فارغة
     * Mask sensitive credential columns with `***` when non-empty.
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
     * توليد المعرّف التالي: NVL(MAX(col),0)+1 (لا تسلسل على هذين الجدولين الإرثيين)
     * Generate the next id: NVL(MAX(col),0)+1 (no sequence on these legacy tables).
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

    private function subscriptionNotFound(int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "الاشتراك رقم [{$id}] غير موجود / Subscription [{$id}] not found.",
        ], 404);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'بيانات غير صالحة / Validation failed.',
            'errors' => $errors,
        ], 422);
    }
}
