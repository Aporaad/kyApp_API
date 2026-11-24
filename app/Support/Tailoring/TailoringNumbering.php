<?php

namespace App\Support\Tailoring;

use Illuminate\Support\Facades\DB;

/**
 * ترقيم فواتير التفصيل — نفس قواعد النظام القديم المقيسة (MAX+1 بلا تسلسلات)
 * Tailoring numbering — the measured legacy rules (MAX+1, no sequences).
 *
 * ── الحقائق المقيسة من القاعدة الحيّة (المرحلة 0):
 *    لا يوجد أي تسلسل لـ SALES_BILL أو SALES_DETAILES_SHAPES أو MULTI_CLOTHS أو SANFS_D،
 *    لذلك كل عدّاد هنا MAX+1 داخل نطاقه، والحماية الفعلية هي مفاتيح PK/UK ثم إعادة
 *    المحاولة على ORA-00001 (3 محاولات في المتحكم).
 * ── Measured facts (Phase 0): no sequence exists for SALES_BILL, SALES_DETAILES_SHAPES,
 *    MULTI_CLOTHS or SANFS_D, so every counter here is MAX+1 within its scope; the real
 *    protection is the PK/UK keys plus the controller's ORA-00001 retry (3 attempts).
 *
 * ── `OHDID` ربط نصي لا حساب: `OHID || الترتيب` يبدأ من 1 بلا تبطين بأصفار.
 *    مثال مقيس: OHID=262112846 يُعطي القطعة 1 = 2621128461 والقطعة 39 = 26211284639.
 *    ضرب OHID في 100 أو تبطين الترتيب يُنتج قيماً خاطئة — الخطر R8.
 * ── OHDID is a string concatenation, not arithmetic: `OHID || order`, starting at 1 with no
 *    zero padding. Measured: OHID=262112846 gives piece 1 = 2621128461 and piece 39 =
 *    26211284639. Multiplying or padding produces wrong keys (risk R8).
 */
final class TailoringNumbering
{
    /**
     * رقم الفاتورة التالي داخل السنة — عدّاد كثيف يبدأ من 1 والمفتاح الفريد (BILL_NO, YEAR)
     * The next bill number within a year — dense from 1; unique key is (BILL_NO, YEAR).
     */
    public static function nextBillNo(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(BILL_NO), 0) AS N FROM ACCOUNTS.SALES_BILL WHERE YEAR = :y',
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * الرقم اليدوي التالي (رقم الدفتر) لكل (سنة، آجل، تفصيل) — كما قيّس النظام القديم
     * The next manual (book) number per (year, credit, cutting) — the measured legacy scope.
     */
    public static function nextManualNo(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(MANUAL_BILL_NO), 0) AS N
               FROM ACCOUNTS.SALES_BILL
              WHERE YEAR = :y
                AND BILL_TYPE = :t
                AND CUTTING_OR_SLAES = :k',
            [
                'y' => $year,
                't' => TailoringPolicy::BILL_TYPE,
                'k' => TailoringPolicy::CUTTING_OR_SLAES,
            ]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * تسلسل الموديل التالي — عدّاد عام على كل SALES_DETAILES_SHAPES (PK)
     * The next model sequence — a global counter over SALES_DETAILES_SHAPES (its PK).
     */
    public static function nextShapeSeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ_NO), 0) AS N FROM ACCOUNTS.SALES_DETAILES_SHAPES'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * تسلسل القطعة التالي — عدّاد عام على كل MULTI_CLOTHS (PK)
     * The next piece sequence — a global counter over MULTI_CLOTHS (its PK).
     */
    public static function nextPieceSeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ_NO1), 0) AS N FROM ACCOUNTS.MULTI_CLOTHS'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * تسلسل سطر بيانات الفاتورة التالي — عدّاد عام على SALES_DETAILES (PK)
     * The next bill-detail sequence — a global counter over SALES_DETAILES (its PK).
     */
    public static function nextDetailSeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.SALES_DETAILES'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * تسلسل صلاحيات الفاتورة التالي — عدّاد عام على SALES_BILL_PRIVILAGE
     * The next bill-privilege sequence — a global counter over SALES_BILL_PRIVILAGE.
     */
    public static function nextPrivilegeSeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.SALES_BILL_PRIVILAGE'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * رقم القيد التالي: بادئة 5 + سنة(2) + عدّاد(7) — نفس صيغة SalesPolicy::nextEntryNo
     * The next entry number: prefix 5 + 2-digit year + 7-digit counter, same shape as sales.
     */
    public static function nextEntryNo(int $year): int
    {
        $head = (int) sprintf('%d%02d', TailoringPolicy::ENTRY_PREFIX, $year % 100);

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(ENTRY_NO), 0) AS N
               FROM ACCOUNTS.ENTRY_MAIN
              WHERE ENTRY_NO BETWEEN :lo AND :hi',
            ['lo' => $head * 10000000, 'hi' => $head * 10000000 + 9999999]
        );

        $max = (int) (reset($row) ?: 0);
        $counter = $max > 0 ? ($max % 10000000) + 1 : 1;

        return $head * 10000000 + $counter;
    }

    /**
     * تسلسل سطر القيد التالي — عدّاد عام مشترك مع النظام المكتبي
     * The next journal-line sequence — a global counter shared with the desktop.
     */
    public static function nextEntrySeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.ENTRY_SUB'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * OHID الفعلي بعد الإدراج — الزناد هو المرجع الوحيد، فلا نكرر صيغته هنا
     * The actual OHID after insert — the trigger is the single authority, so its formula
     * is never duplicated here.
     */
    public static function ohidOf(int $billNo, int $year): ?int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT OHID FROM ACCOUNTS.SALES_BILL WHERE BILL_NO = :b AND YEAR = :y',
            ['b' => $billNo, 'y' => $year]
        );

        $ohid = reset($row);

        return $ohid === null || $ohid === false ? null : (int) $ohid;
    }

    /**
     * OHDID للموديل: ربط نصي `OHID || ترتيب الموديل` يبدأ من 1 بلا تبطين
     * The model OHDID: string concat `OHID || model order`, from 1, no padding.
     */
    public static function modelOhdid(int $ohid, int $modelOrder): int
    {
        return (int) ((string) $ohid.(string) $modelOrder);
    }

    /**
     * OHDID للقطعة: ربط نصي `OHID || ترتيب القطعة` يبدأ من 1 بلا تبطين
     * The piece OHDID: string concat `OHID || piece order`, from 1, no padding.
     */
    public static function pieceOhdid(int $ohid, int $pieceOrder): int
    {
        return (int) ((string) $ohid.(string) $pieceOrder);
    }

    /**
     * رقم صنف المقاس التالي داخل صنف النمط — قالب النظام القديم:
     * `STYL_NO || '01' || عدّاد(3)`، ويُقرأ العدّاد من آخر صف في نفس العائلة الفرعية.
     * The next size-item number inside a style item — the legacy template:
     * `STYL_NO || '01' || counter(3)`, the counter read from the last row of that subfamily.
     *
     * مثال: STYL_NO=30101 ⇒ 3010101 + عدّاد ⇒ 3010101001, 3010101002 …
     * Example: STYL_NO = 30101 ⇒ 3010101 + counter ⇒ 3010101001, 3010101002 …
     */
    public static function nextSanfDno(int $sanfNo, int $stylNo): int
    {
        $prefix = (string) $stylNo.'01';

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(TO_NUMBER(SUBSTR(TO_CHAR(SANF_DNO), :plen))), 0) AS N
               FROM ACCOUNTS.SANFS_D
              WHERE SANF_NO = :sanf AND SUBSTR(TO_CHAR(SANF_DNO), 1, :plen2) = :prefix',
            [
                'sanf' => $sanfNo,
                'prefix' => $prefix,
                'plen' => strlen($prefix) + 1,
                'plen2' => strlen($prefix),
            ]
        );

        $counter = (int) (reset($row) ?: 0) + 1;

        return (int) ($prefix.str_pad((string) $counter, 3, '0', STR_PAD_LEFT));
    }
}
