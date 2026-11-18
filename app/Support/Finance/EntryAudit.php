<?php

namespace App\Support\Finance;

use App\Support\ArabicEncoding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * سجل تدقيق القيود القديم ENTRY_MAIN_PRIVILAGE — من أنشأ القيد ومن عدّله آخر مرة
 * The legacy entry audit table ENTRY_MAIN_PRIVILAGE — who created and last updated an entry.
 *
 * ── مثبت من البيانات: صف واحد لكل قيد (3854 صفاً = 3854 قيداً مميزاً)، و SEQ_NO عدّاد MAX+1،
 *    و ENTRY_NO مفتاح أجنبي إلى ENTRY_MAIN فلا يُكتب للمؤقت. هذا السجل مكمّل لسجل نشاط التطبيق
 *    APP_USER_ACTIVITY لا بديل عنه: الأول يقرؤه النظام المكتبي، والثاني يقرؤه التطبيق.
 * ── Proved on live rows: one row per entry, SEQ_NO is MAX+1, and ENTRY_NO is a foreign key to
 *    ENTRY_MAIN (so temporary entries never get one). It complements APP_USER_ACTIVITY: the
 *    desktop reads this table, the app reads its own activity log.
 */
final class EntryAudit
{
    // ملاحظة: «:by» كلمة محجوزة في أوراكل كاسم ربط (ORA-01745) — لذا :user_id و :user_name.
    // Note: ":by" is reserved as an Oracle bind name (ORA-01745), hence :user_id / :user_name.

    /** تسجيل إنشاء قيد / record an entry's creation */
    public static function created(int $entryNo, Request $request): void
    {
        [$userId, $name] = self::who($request);

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_MAIN_PRIVILAGE (SEQ_NO, ENTRY_NO, CREATED_ON, CREATED_BY, CREATED_BY_NAME)
             VALUES ((SELECT NVL(MAX(SEQ_NO), 0) + 1 FROM ACCOUNTS.ENTRY_MAIN_PRIVILAGE), :entry_no, SYSDATE, :user_id, :user_name)',
            ['entry_no' => $entryNo, 'user_id' => $userId, 'user_name' => $name]
        );
    }

    /**
     * تسجيل تعديل قيد — يُحدَّث الصف الموجود أو يُنشأ صف بآخر تعديل فقط إن لم يوجد
     * Record an update — updates the existing row, or inserts one carrying only the update.
     */
    public static function updated(int $entryNo, Request $request): void
    {
        [$userId, $name] = self::who($request);

        $affected = DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.ENTRY_MAIN_PRIVILAGE
                SET LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :user_id, LAST_UPDATED_BY_NAME = :user_name
              WHERE ENTRY_NO = :entry_no',
            ['user_id' => $userId, 'user_name' => $name, 'entry_no' => $entryNo]
        );

        if ($affected === 0) {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.ENTRY_MAIN_PRIVILAGE
                     (SEQ_NO, ENTRY_NO, LAST_UPDATED_ON, LAST_UPDATED_BY, LAST_UPDATED_BY_NAME)
                 VALUES ((SELECT NVL(MAX(SEQ_NO), 0) + 1 FROM ACCOUNTS.ENTRY_MAIN_PRIVILAGE), :entry_no, SYSDATE, :user_id, :user_name)',
                ['entry_no' => $entryNo, 'user_id' => $userId, 'user_name' => $name]
            );
        }
    }

    /**
     * صف التدقيق للعرض / the audit row for display
     *
     * @return array<string, mixed>
     */
    public static function of(int $entryNo): array
    {
        $row = DB::connection('oracle')->selectOne(
            "SELECT CREATED_BY_NAME, TO_CHAR(CREATED_ON,'YYYY-MM-DD HH24:MI') AS CREATED_ON_TXT,
                    LAST_UPDATED_BY_NAME, TO_CHAR(LAST_UPDATED_ON,'YYYY-MM-DD HH24:MI') AS UPDATED_ON_TXT
               FROM ACCOUNTS.ENTRY_MAIN_PRIVILAGE WHERE ENTRY_NO = :e AND ROWNUM = 1",
            ['e' => $entryNo]
        );

        $r = $row === null ? [] : array_change_key_case((array) $row, CASE_UPPER);

        return [
            'created_by_name' => ArabicEncoding::normalizeStatementText($r['CREATED_BY_NAME'] ?? null) ?: null,
            'created_on' => $r['CREATED_ON_TXT'] ?? null,
            'updated_by_name' => ArabicEncoding::normalizeStatementText($r['LAST_UPDATED_BY_NAME'] ?? null) ?: null,
            'updated_on' => $r['UPDATED_ON_TXT'] ?? null,
        ];
    }

    /**
     * هوية المنفّذ: رقمه (NUMBER(3)) واسمه العربي بصيغة الكتابة
     * The actor: id (NUMBER(3)) and Arabic name in the write form.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function who(Request $request, int $nameLimit = 60): array
    {
        $user = $request->user();
        $id = $user?->getAuthIdentifier();
        $id = is_numeric($id) && (int) $id <= 999 ? (int) $id : null;
        $name = $user?->USER_NAME_AR ?? $user?->USER_NAME_EN ?? null;
        // الاسم يصل من APP_USERS بايتات CP1256 مشوّهة — يُصلَح أولاً ثم يُحوَّل لصيغة الكتابة،
        // وإلا تُرمَّز المحارف اللاتينية مرة ثانية فتتلف.
        // The name arrives as mojibake from APP_USERS: repair first, then encode for writing.
        $name = $name === null ? null : ArabicEncoding::fixMojibake(trim((string) $name));

        return [$id, EntryLedger::arabic(EntryLedger::clip($name === null ? null : (string) $name, $nameLimit)) ?: null];
    }
}
