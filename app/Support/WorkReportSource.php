<?php

namespace App\Support;

/**
 * مصدر كشف العمل: المرحَّل / الغير مرحَّل / الغير منجز.
 * Work-report source: posted / unposted / not-done.
 *
 * الأنواع الثلاثة تقرأ نفس أعمدة كشف العمل، لكن تختلف في الجدول والشرط وعمود التاريخ:
 * - `calc`   (المرحَّل، الافتراضي): `*_FROM_CALC` بلا شرط صفوف — كما كان.
 * - `uncalc` (الغير مرحَّل): `*_FROM_UNCALC` بشرط `NVL(UN,0) = 0`؛ أي كمية أُنجزت
 *   ولم تُرحَّل بعد. الفلترة والترتيب على تاريخ العمل (`TAILOR_DATE`/`CUTTING_DATE`).
 * - `undone` (الغير منجز): `*_FROM_UNCALC` بشرط `NVL(UN,0) <> 0`؛ أي كمية متبقية
 *   لم تُنجز بعد. هذه السطور لا تحمل تاريخ عمل إطلاقاً، فتاريخها الوحيد هو `DDATE`.
 *
 * The three kinds share the work columns but differ in table, row condition and the
 * date that drives filtering/ordering:
 * - `calc`   (posted, default): `*_FROM_CALC`, no row condition — unchanged.
 * - `uncalc` (unposted): `*_FROM_UNCALC` where `NVL(UN,0) = 0` — finished but not
 *   posted yet; dates use the work column (`TAILOR_DATE`/`CUTTING_DATE`).
 * - `undone` (not done): `*_FROM_UNCALC` where `NVL(UN,0) <> 0` — the remaining
 *   quantity. Such rows never carry a work date, so `DDATE` is their only date.
 *
 * كل الشروط تُكتب باسم الجدول المستعار `w` لأن الاستعلامات الثلاثة (سطر عامل،
 * مجموعة عمال، كشف شخصي) تستخدم هذا الاستعار نفسه.
 * Every condition is written against the `w` alias because all three queries (single
 * worker, group, personal statement) use that same alias.
 */
final class WorkReportSource
{
    public const POSTED = 'calc';

    public const UNPOSTED = 'uncalc';

    public const UNDONE = 'undone';

    /** الأنواع المقبولة — أي قيمة خارجها تعود إلى `calc` كما كان. */
    /** Accepted kinds — anything else falls back to `calc`, exactly as before. */
    public const ALL = [self::POSTED, self::UNPOSTED, self::UNDONE];

    /**
     * تطبيع قيمة `report_type` القادمة من العميل (قائمة بيضاء قبل بناء الاستعلام).
     * Normalises the incoming `report_type` (whitelisted before any query is built).
     */
    public static function resolve(mixed $raw): string
    {
        $value = strtolower(trim((string) $raw));

        return in_array($value, self::ALL, true) ? $value : self::POSTED;
    }

    /** التسمية العربية المعروضة في شريط البيانات وملف الـ PDF. */
    /** Arabic label shown in the meta bar and the printed PDF. */
    public static function label(string $type): string
    {
        return match ($type) {
            self::UNPOSTED => 'الغير مرحل',
            self::UNDONE => 'الغير منجز',
            default => 'المرحل',
        };
    }

    /** هل يقرأ النوع جدول `*_FROM_UNCALC` بدل `*_FROM_CALC`؟ */
    /** Does the kind read `*_FROM_UNCALC` instead of `*_FROM_CALC`? */
    public static function usesUnpostedTable(string $type): bool
    {
        return $type === self::UNPOSTED || $type === self::UNDONE;
    }

    /**
     * شرط الصفوف على جدول UNCALC — `null` في الكشف المرحَّل (كل الصفوف).
     * Row condition on the UNCALC table — `null` for the posted report (every row).
     */
    public static function rowCondition(string $type): ?string
    {
        return match ($type) {
            self::UNPOSTED => 'NVL(w.UN, 0) = 0',
            self::UNDONE => 'NVL(w.UN, 0) <> 0',
            default => null,
        };
    }

    /**
     * عمود التاريخ الذي تُفلتر به الفترة ويُرتَّب عليه: تاريخ العمل، و`DDATE` للغير منجز.
     * The date column driving the period filter and the ordering: the work date, or
     * `DDATE` for the not-done report (its rows carry no work date at all).
     */
    public static function dateExpression(string $type, string $workDateColumn): string
    {
        return $type === self::UNDONE ? 'w.DDATE' : 'w.'.$workDateColumn;
    }

    /**
     * تعبير الكمية: `POST` (الكمية المنجزة) للمرحَّل وغير المرحَّل، و`UN`
     * (الكمية المتبقية) للغير منجز — فسطور غير المنجز تحمل `POST = 0` دائماً.
     * Quantity expression: `POST` (finished pieces) for posted/unposted, and `UN`
     * (remaining pieces) for not-done, whose rows always carry `POST = 0`.
     */
    public static function quantityExpression(string $type, string $workQuantityColumn): string
    {
        $column = $type === self::UNDONE ? 'UN' : $workQuantityColumn;

        return 'NVL(w.'.$column.', 0)';
    }

    /**
     * تعبير المبلغ: عمود إجمالي العمل، وصفر للغير منجز لأن الكمية المتبقية
     * لم تُنجز بعد فلا مستحقات لها (`AMT` فارغ أصلاً في هذه السطور).
     * Amount expression: the work total column, and zero for not-done because the
     * remaining pieces have not been delivered yet (`AMT` is null on those rows anyway).
     */
    public static function totalExpression(string $type, string $workTotalColumn): string
    {
        return $type === self::UNDONE ? '0' : 'NVL(w.'.$workTotalColumn.', 0)';
    }
}
