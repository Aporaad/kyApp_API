<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\PermissionCatalog;
use App\Support\PermissionGrantSync;
use App\Support\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * متحكم كتالوج الصلاحيات ومنحها — الشاشة التي تُغذّي «التحكم بصلاحيات المستخدم»
 * Permission catalog & grants controller — feeds the "manage user permissions" screen.
 *
 * الصلاحيات تُمنح للمستخدم مباشرة (نموذج IBAC بلا أدوار)، لذلك هذا المتحكم هو محرّك المنح:
 * يقرأ الكتالوج مرتّباً حسب الأقسام، ويعيد منح مستخدم، ويستبدلها ذرّياً مع حاجزين أساسيين:
 * Permissions are granted directly to users (IBAC, no roles), so this controller is the grant engine:
 * it serves the ordered catalog, reads a user's grants, and replaces them atomically under two guards.
 *  1) لا تصعيد: غير المدير لا يمنح إلا ما يملك، ولا يحرّر منح نفسه.
 *     No escalation: a non-admin grants only what it holds, and never edits its own grants.
 *  2) لا قفل: منح أو سحب صلاحيات يُنهي جلسات المستخدم كي تسري الحالة الجديدة فوراً.
 *     No stale access: changing grants ends the user's sessions so the new state applies immediately.
 */
class PermissionController extends Controller
{
    /**
     * نوع المستند المخزّن في جدول التوكنات لمستخدمي التطبيق
     * The polymorphic type stored on token rows for application users
     */
    private const TOKENABLE_TYPE = 'App\\Models\\User';

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly PermissionGrantSync $grants,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * الكتالوج كاملاً مرتّباً حسب الأقسام، مع معرّفات القاعدة لكل صلاحية
     * The full catalog grouped by section, with each permission's database id
     *
     * تُقرأ المعرّفات من `APP_PERMISSIONS` لأن الواجهة لاحقاً ترسل المعرّفات لا الأكواد.
     * Ids come from `APP_PERMISSIONS` because the client submits ids, not codes.
     */
    public function index(): JsonResponse
    {
        try {
            $ids = $this->permissionIds();
            $grouped = [];

            foreach (PermissionCatalog::grouped() as $section) {
                $permissions = [];

                foreach ($section['permissions'] as $permission) {
                    $code = $permission['permission_code'];

                    $permissions[] = [
                        'permission_id' => $ids[$code] ?? null,
                        'permission_code' => $code,
                        'action_code' => $permission['action_code'],
                        'name_ar' => $permission['name_ar'],
                        'name_en' => $permission['name_en'],
                        'is_core' => (int) $permission['is_core'],
                        'sort_order' => (int) $permission['sort_order'],
                    ];
                }

                $grouped[] = [
                    'module_code' => $section['module_code'],
                    'name_ar' => $section['name_ar'],
                    'permissions' => $permissions,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الصلاحيات / Permissions catalog loaded.',
                'data' => $grouped,
                'meta' => [
                    'total' => PermissionCatalog::count(),
                    'modules' => count($grouped),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب كتالوج الصلاحيات', $e);
        }
    }

    /**
     * منح مستخدم واحد: الأكواد والمعرّفات وحالة المدير
     * One user's grants: codes, ids, and its admin state
     */
    public function forUser(int $userId): JsonResponse
    {
        $row = $this->findUser($userId);

        if ($row === null) {
            return $this->notFound($userId);
        }

        try {
            $current = $this->grants->currentFor($userId);
            $isAdmin = $this->permissions->isAdmin($userId);

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'user_name_en' => ArabicEncoding::fixMojibake($this->stringOf($row, 'USER_NAME_EN')),
                    'user_name_ar' => ArabicEncoding::fixMojibake($this->stringOf($row, 'USER_NAME_AR')),
                    'is_admin' => $isAdmin,
                    // المدير لا يحتاج منحاً: صلاحياته كاملة بحكم `IS_ADMIN` خارج جدول المنح
                    // An admin needs no grants: IS_ADMIN bypasses the grant table entirely.
                    'effective_permissions' => $isAdmin ? PermissionCatalog::codes() : $current['codes'],
                    'granted_codes' => $current['codes'],
                    'granted_ids' => $current['ids'],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب صلاحيات المستخدم', $e);
        }
    }

    /**
     * استبدال منح مستخدم بالكامل (ذرّية: حذف ثم إدراج)
     * Replace a user's grants completely (atomic: delete then re-insert)
     *
     * قائمة فارغة معتمدة وتعني «سحب كل الصلاحيات»؛ الغياب الكامل للمعامل خطأ تحققي.
     * An explicit empty list is valid and means "revoke everything"; omitting the key is a validation error.
     */
    public function syncUser(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $row = $this->findUser($userId);

        if ($row === null) {
            return $this->notFound($userId);
        }

        $validator = Validator::make($request->all(), [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'max:60'],
            'logout_others' => ['nullable', 'boolean'],
        ], [
            'permissions.required' => 'قائمة الصلاحيات مطلوبة (وأقلها قائمة فارغة للسحب الكامل) / The permissions list is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة / Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // تحرير منح الذات مرفوض لغير المدير: هو طريق مختصر لانتحال صلاحية جديدة
        // Non-admins may not edit their own grants: that is the shortest path to self-escalation.
        if ($actor !== null && $this->idOf($actor) === $userId && ! $this->permissions->isAdmin($actor)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك تعديل صلاحيات حسابك الحالي / You cannot edit your own permissions.',
            ], 403);
        }

        $codes = array_values(array_filter(
            $request->input('permissions', []),
            static fn ($code): bool => is_string($code) && trim($code) !== ''
        ));

        $resolved = $this->grants->resolve($codes);

        if ($resolved['unknown'] !== []) {
            return response()->json([
                'success' => false,
                'message' => 'بعض أكواد الصلاحيات غير معروفة / Unknown permission codes',
                'errors' => ['unknown_codes' => $resolved['unknown']],
            ], 422);
        }

        if ($resolved['inactive'] !== []) {
            return response()->json([
                'success' => false,
                'message' => 'بعض الصلاحيات معطّلة ولا يمكن منحها / Inactive permissions cannot be granted',
                'errors' => ['inactive_codes' => $resolved['inactive']],
            ], 422);
        }

        if ($actor !== null && ! $this->permissions->isAdmin($actor)) {
            $notHeld = [];

            foreach ($this->cleanCodes($codes) as $code) {
                if (! $this->permissions->can($actor, $code)) {
                    $notHeld[] = $code;
                }
            }

            if ($notHeld !== []) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يمكنك منح صلاحيات لا تملكها / You cannot grant permissions you do not hold.',
                    'errors' => ['not_held' => $notHeld],
                ], 403);
            }
        }

        try {
            $count = $this->grants->replace($userId, $resolved['ids'], $actor ? $this->idOf($actor) : null);

            // إنهاء الجلسات افتراضي: المنح تُقيَّم في كل طلب لكن العميل يخزّن نسخة من الأكواد
            // Ending sessions is the default: grants are evaluated per request, but the client caches codes
            $sessionsEnded = $request->input('logout_others', true) ? $this->terminateSessions($userId) : 0;

            $this->activity->success('users.permissions_sync', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'target_user_id' => $userId,
                'module_code' => 'users',
                'entity_type' => 'APP_USERS_PERMISSIONS',
                'entity_id' => $userId,
                'description_ar' => 'تحديث صلاحيات المستخدم «'.$this->stringOf($row, 'USER_NAME_EN').'»: '.$count.' صلاحية',
            ], $request);

            $current = $this->grants->currentFor($userId);

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ صلاحيات المستخدم / Permissions saved.',
                'data' => [
                    'user_id' => $userId,
                    'granted_codes' => $current['codes'],
                    'granted_ids' => $current['ids'],
                    'sessions_ended' => $sessionsEnded,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حفظ صلاحيات المستخدم', $e);
        }
    }

    // ---------------------------------------------------------------------
    // المساعدات الداخلية / internal helpers
    // ---------------------------------------------------------------------

    /**
     * خريطة الكود ⇒ المعرّف من جدول الصلاحيات
     * Code-to-id map read from APP_PERMISSIONS (the catalog and the table share one source)
     *
     * @return array<string, int>
     */
    private function permissionIds(): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT PERMISSION_ID, PERMISSION_CODE FROM ACCOUNTS.APP_PERMISSIONS ORDER BY SORT_ORDER'
        );

        $ids = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $code = $this->stringOf($row, 'PERMISSION_CODE');

            if ($code !== null) {
                $ids[$code] = (int) $this->columnOf($row, 'PERMISSION_ID');
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUser(int $userId): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT USER_ID, USER_NAME_EN, USER_NAME_AR, IS_ADMIN, IS_ACTIVATED
               FROM ACCOUNTS.APP_USERS
              WHERE USER_ID = :user_id',
            ['user_id' => $userId]
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * إنهاء جلسات المستخدم بدفع انتهاء التوكن إلى الماضي
     * End the user's sessions by pushing each token's expiry into the past
     */
    private function terminateSessions(int $userId): int
    {
        return DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.PERSONAL_ACCESS_TOKENS
                SET EXPIRES_AT = SYSDATE - 1, UPDATED_AT = SYSDATE
              WHERE TOKENABLE_ID = :user_id
                AND TOKENABLE_TYPE = :type
                AND (EXPIRES_AT IS NULL OR EXPIRES_AT > SYSDATE)',
            ['user_id' => $userId, 'type' => self::TOKENABLE_TYPE]
        );
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    private function cleanCodes(array $codes): array
    {
        $clean = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = trim($code);

            if ($code !== '' && ! in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function columnOf(array $row, string $column): mixed
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        foreach ($row as $key => $value) {
            if (strtolower((string) $key) === strtolower($column)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringOf(array $row, string $column): ?string
    {
        $value = $this->columnOf($row, $column);

        return $value === null ? null : (string) $value;
    }

    private function idOf(User $user): int
    {
        return (int) ($user->getAttribute('USER_ID') ?? $user->getKey());
    }

    private function notFound(int $userId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "المستخدم رقم [{$userId}] غير موجود / User [{$userId}] not found.",
        ], 404);
    }

    private function errorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
