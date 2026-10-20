<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * كتابة منح الصلاحيات — المسار الوحيد الذي يعدّل جدول APP_USERS_PERMISSIONS
 * Permission grant writer — the only code path that mutates APP_USERS_PERMISSIONS.
 *
 * لماذا فصل الكتابة عن `PermissionResolver`؟ المحلّل للقراءة فقط (can/codes/cache)، وفصل الكتابة
 * يجعل التحقق من الصلاحية ومنع التصعيد decisions صريحة في المتحكمات، ويُبقي إبطال الكاش في مكان
 * واحد لا يتكرر.
 * Why split from `PermissionResolver`: the resolver is read-only. Keeping writes here means cache
 * invalidation lives in exactly one place and escalation guards stay explicit in the controllers.
 *
 * كل الاستعلامات مربوطة بالكامل؛ لا تلخيص نصي في أي معامَل.
 * Every statement is fully bound; nothing is string-interpolated.
 */
class PermissionGrantSync
{
    /**
     * الاستعلام المرجعي لتحويل الأكواد إلى معرفات مع حالة النشاط
     * Canonical lookup turning permission codes into ids, with their active flag
     */
    private const LOOKUP_SQL = <<<'SQL'
        SELECT PERMISSION_ID, PERMISSION_CODE, IS_ACTIVE
          FROM ACCOUNTS.APP_PERMISSIONS
         WHERE PERMISSION_CODE IN (__CODES__)
        SQL;

    public function __construct(private readonly PermissionResolver $resolver) {}

    /**
     * أكواد المستخدم الحالية مع معرفاتها، مرتّبة حسب المنح
     * The user's current grants as codes with ids, in grant order
     *
     * @return array{codes: list<string>, ids: list<int>}
     */
    public function currentFor(int $userId): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT up.ID, up.PERMISSION_ID, p.PERMISSION_CODE
               FROM ACCOUNTS.APP_USERS_PERMISSIONS up
               JOIN ACCOUNTS.APP_PERMISSIONS p ON p.PERMISSION_ID = up.PERMISSION_ID
              WHERE up.USER_ID = :user_id
                AND up.IS_ACTIVE = 1
                AND (up.EXPIRES_ON IS NULL OR up.EXPIRES_ON > SYSDATE)
              ORDER BY up.ID',
            ['user_id' => $userId]
        );

        $codes = [];
        $ids = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $codes[] = (string) ($row['PERMISSION_CODE'] ?? $row['permission_code'] ?? '');
            $ids[] = (int) ($row['PERMISSION_ID'] ?? $row['permission_id'] ?? 0);
        }

        return ['codes' => $codes, 'ids' => $ids];
    }

    /**
     * التحقق من الأكواد وتحويلها إلى معرفات صالحة
     * Validate the codes and map them to active permission ids.
     *
     * الأكواد المرفوضة نوعان: غير معروفة (لا وجود لها في الجدول) وغير نشطة (موجودة لكن
     * `IS_ACTIVE = 0`) — والتمييز بينهما يجعل رسالة الخطأ مفهومة للمستخدم.
     * Rejected codes split into unknown (not in the table) and inactive (`IS_ACTIVE = 0`) so the
     * error message tells the admin what is actually wrong.
     *
     * @param  list<string>  $codes
     * @return array{ids: list<int>, unknown: list<string>, inactive: list<string>}
     */
    public function resolve(array $codes): array
    {
        $codes = $this->unique($codes);

        if ($codes === []) {
            return ['ids' => [], 'unknown' => [], 'inactive' => []];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($codes as $index => $code) {
            $placeholders[] = ':code_'.$index;
            $bindings['code_'.$index] = $code;
        }

        // قائمة `IN` مربوطة بالكامل: عدد المربوطات من الأكواد نفسها لا من نصها
        // A fully bound IN-list: the placeholder count comes from the codes, never from their text.
        $sql = str_replace('__CODES__', implode(', ', $placeholders), self::LOOKUP_SQL);

        $rows = DB::connection('oracle')->select($sql, $bindings);

        $ids = [];
        $unknown = $codes;
        $inactive = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $code = (string) ($row['PERMISSION_CODE'] ?? $row['permission_code'] ?? '');
            $isActive = (int) ($row['IS_ACTIVE'] ?? $row['is_active'] ?? 0) === 1;

            $unknown = array_values(array_diff($unknown, [$code]));

            if (! $isActive) {
                $inactive[] = $code;

                continue;
            }

            $ids[] = (int) ($row['PERMISSION_ID'] ?? $row['permission_id'] ?? 0);
        }

        return ['ids' => $ids, 'unknown' => $unknown, 'inactive' => $inactive];
    }

    /**
     * استبدال كامل منح مستخدم بذرّية واحدة: حذف ثم إدراج ثم إبطال الكاش
     * Atomically replace a user's grants: delete, insert, then drop the permission cache.
     *
     * `GRANTED_ON` و `IS_ACTIVE` لهما قيم افتراضية في الجدول، و `ID` يملؤه المحفِّز
     * `APP_USERS_PERMISSIONS_TRG`، فلا يحتاج الإدراج سوى الأعمدة المنطقية الثلاثة.
     * The table defaults `GRANTED_ON`/`IS_ACTIVE` and its trigger fills `ID`, so only the three
     * meaningful columns are inserted.
     *
     * @param  list<int>  $permissionIds
     * @return int عدد المنح بعد الاستبدال / grant count after the replacement
     */
    public function replace(int $userId, array $permissionIds, ?int $grantedBy): int
    {
        $permissionIds = array_values(array_unique(array_map(intval(...), $permissionIds)));

        DB::connection('oracle')->transaction(function () use ($userId, $permissionIds, $grantedBy): void {
            DB::connection('oracle')->statement(
                'DELETE FROM ACCOUNTS.APP_USERS_PERMISSIONS WHERE USER_ID = :user_id',
                ['user_id' => $userId]
            );

            foreach ($permissionIds as $permissionId) {
                if ($permissionId <= 0) {
                    continue;
                }

                DB::connection('oracle')->insert(
                    'INSERT INTO ACCOUNTS.APP_USERS_PERMISSIONS
                        (USER_ID, PERMISSION_ID, GRANTED_BY)
                     VALUES (:user_id, :permission_id, :granted_by)',
                    ['user_id' => $userId, 'permission_id' => $permissionId, 'granted_by' => $grantedBy]
                );
            }
        });

        // المنح الجديدة تسري على الطلب التالي مباشرة، فلا مكان لنسخة قديمة في الكاش
        // New grants must apply to the next request, so no stale copy may survive.
        $this->resolver->forget($userId);

        return count($permissionIds);
    }

    /**
     * قائمة فريدة ومحفوظة الترتيب من الأكواد غير الفارغة
     * De-duplicated, order-preserving list of non-empty codes
     *
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    private function unique(array $codes): array
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
}
