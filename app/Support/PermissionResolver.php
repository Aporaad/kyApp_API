<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * محلّل الصلاحيات — قلب نموذج IBAC
 * Permission resolver — the heart of the IBAC model.
 *
 * القواعد (تُقيَّم بالترتيب، والرفض هو الافتراضي):
 * Rules, evaluated in order, deny-by-default:
 *  1) `IS_ADMIN = 1`                  ⇒ كل الصلاحيات (تجاوز كامل — D1)
 *  2) منح `system.*`                  ⇒ كل الصلاحيات (دلالة احتياطية)
 *  3) منح الكود صراحة                 ⇒ قبول
 *  4) منح `module.*` لوحدة الكود      ⇒ قبول
 *  5) غير ذلك                         ⇒ رفض
 *
 * المنح الصالح هو: `up.IS_ACTIVE = 1 AND p.IS_ACTIVE = 1 AND (EXPIRES_ON IS NULL OR EXPIRES_ON > SYSDATE)`
 * A grant counts only when the grant row and the permission row are both active and unexpired.
 *
 * ملاحظة ترميز: لا تُرسل أي نصوص عربية هنا؛ الأكواد لاتينية بالكامل فلا تحتاج طبقة الإصلاح.
 * Encoding note: only ASCII codes flow through this class, so no mojibake repair is needed.
 */
class PermissionResolver
{
    /**
     * مدة صلاحية نسخة الصلاحيات في الكاش (ثوانٍ)
     * Permission cache lifetime in seconds
     */
    private const CACHE_TTL = 300;

    /**
     * أقصى حجم آمن لقيمة الكاش (بايت): مخزن `CACHE` على أوراكل يكتب عبر MERGE من dual
     * ويرفض أي مربوط أطول من 4000 بايت (ORA-01461). المستخدم الممنوح بكل الكتالوج (93 كوداً)
     * يقترب من هذا الحد، فتتجاوز القراءة من القاعدة بدل الكاش حين يتجاوزه.
     * Safe cache payload bound: the Oracle database cache store writes through a MERGE-select-from-dual
     * and rejects bound values longer than 4000 bytes (ORA-01461). A user granted the whole catalog
     * approaches that ceiling, so oversized sets skip the cache instead of failing the request.
     */
    private const MAX_CACHE_BYTES = 3000;

    /**
     * استعلام المنح الصالحة لمستخدم واحد
     * SQL that returns a user's effective permission codes
     */
    private const GRANTS_SQL = <<<'SQL'
        SELECT p.PERMISSION_CODE
          FROM ACCOUNTS.APP_USERS_PERMISSIONS up
          JOIN ACCOUNTS.APP_PERMISSIONS p ON p.PERMISSION_ID = up.PERMISSION_ID
         WHERE up.USER_ID = :user_id
           AND up.IS_ACTIVE = 1
           AND p.IS_ACTIVE = 1
           AND (up.EXPIRES_ON IS NULL OR up.EXPIRES_ON > SYSDATE)
         ORDER BY up.ID
        SQL;

    /**
     * كل الأكواد الممنوحة للمستخدم، مرتّبة كما مُنحت
     * Every permission code granted to the user, in grant order
     *
     * @return list<string>
     */
    public function codesFor(User|int $user): array
    {
        $userId = $this->userId($user);

        if ($userId <= 0) {
            return [];
        }

        $key = $this->cacheKey($userId);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var list<string> $cached */
            return $cached;
        }

        $codes = $this->fetchCodes($userId);

        if (strlen(serialize($codes)) <= self::MAX_CACHE_BYTES) {
            Cache::put($key, $codes, self::CACHE_TTL);
        }

        return $codes;
    }

    /**
     * هل يملك المستخدم هذه الصلاحية؟
     * Does the user hold this permission?
     */
    public function can(User|int $user, string $code): bool
    {
        $userId = $this->userId($user);

        if ($userId <= 0) {
            return false;
        }

        // المدير العام يتجاوز فحص المنح تماماً
        // The system administrator bypasses grant checks entirely
        if ($this->isAdmin($userId)) {
            return true;
        }

        $codes = $this->codesFor($userId);

        if (in_array(PermissionCatalog::SYSTEM_WILDCARD, $codes, true)) {
            return true;
        }

        if (in_array($code, $codes, true)) {
            return true;
        }

        // منح عام على مستوى الوحدة: customers.* يشمل customers.view
        // Module wildcard: customers.* covers customers.view
        $dot = strpos($code, '.');

        if ($dot !== false) {
            $wildcard = substr($code, 0, $dot).'.'.PermissionCatalog::WILDCARD_ACTION;

            if (in_array($wildcard, $codes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * يملك واحدة على الأقل من الأكواد (قائمة فارغة = رفض اتباعاً للرفض الافتراضي)
     * Holds at least one code. An empty list denies, consistent with deny-by-default.
     *
     * @param  list<string>  $codes
     */
    public function canAny(User|int $user, array $codes): bool
    {
        if ($codes === []) {
            return false;
        }

        foreach ($codes as $code) {
            if ($this->can($user, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * يملك كل الأكواد
     * Holds every one of the codes
     *
     * @param  list<string>  $codes
     */
    public function canAll(User|int $user, array $codes): bool
    {
        foreach ($codes as $code) {
            if (! $this->can($user, $code)) {
                return false;
            }
        }

        return true;
    }

    /**
     * حالة الصلاحيات التي تُرسل للعميل في الدخول و /auth/me و /auth/permissions
     * Permission payload consumed by the Flutter client
     *
     * @return array{user_id: int, is_admin: bool, permissions: list<string>, modules: list<string>}
     */
    public function payloadFor(User|int $user): array
    {
        $userId = $this->userId($user);
        $isAdmin = $userId > 0 && $this->isAdmin($userId);
        $codes = $this->codesFor($userId);

        // الوحدات المشتقة من الأكواد الممنوحة — تُغني العميل عن منطق تكراري
        // Modules derived from the granted codes — saves the client from re-deriving them
        $modules = [];

        foreach ($codes as $code) {
            $dot = strpos($code, '.');
            $module = $dot === false ? $code : substr($code, 0, $dot);
            $modules[$module] = true;
        }

        return [
            'user_id' => $userId,
            'is_admin' => $isAdmin,
            'permissions' => $codes,
            'modules' => array_keys($modules),
        ];
    }

    /**
     * هل المستخدم مدير نظام؟ (يقرأ IS_ADMIN مباشرة، بلا كاش)
     * Is the user a system administrator? Reads IS_ADMIN directly, uncached.
     */
    public function isAdmin(User|int $user): bool
    {
        if ($user instanceof User) {
            return (int) ($user->getAttribute('IS_ADMIN') ?? 0) === 1;
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT IS_ADMIN FROM ACCOUNTS.APP_USERS WHERE USER_ID = :id',
            ['id' => $user]
        );

        return (int) ($row['IS_ADMIN'] ?? $row['is_admin'] ?? 0) === 1;
    }

    /**
     * إسقاط نسخة صلاحيات مستخدم بعد أي تعديل على منحَه
     * Drop a user's cached grants after any change to them
     */
    public function forget(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    /**
     * جلب الأكواد من أوراكل مع تحمّل اختلاف حالة أسماء الأعمدة
     * Fetch codes from Oracle, tolerating column-name case differences
     *
     * @return list<string>
     */
    private function fetchCodes(int $userId): array
    {
        $rows = DB::connection('oracle')->select(self::GRANTS_SQL, ['user_id' => $userId]);

        $codes = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $code = $row['PERMISSION_CODE'] ?? $row['permission_code'] ?? null;

            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * معرّف المستخدم من كائن أو رقم
     * Resolve a user id from a model or a scalar
     */
    private function userId(User|int $user): int
    {
        if ($user instanceof User) {
            return (int) ($user->getAttribute('USER_ID') ?? 0);
        }

        return $user;
    }

    /**
     * مفتاح الكاش الخاص بمستخدم
     * Per-user cache key
     */
    private function cacheKey(int $userId): string
    {
        return 'ky_ibac_perms_'.$userId;
    }
}
