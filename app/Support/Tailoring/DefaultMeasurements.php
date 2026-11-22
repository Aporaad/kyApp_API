<?php

namespace App\Support\Tailoring;

use Illuminate\Support\Facades\DB;

/**
 * المقاسات الافتراضية — نقل حرفي لإجراء النموذج القديم DEFAULT_MEASURMENT (الخطة §9.3)
 * Default measurements — a literal port of the legacy form's DEFAULT_MEASURMENT procedure.
 *
 * ── الخوارزمية الحرفية على جدول ACCOUNTS.DEFAULT_MEASURMENT (138 صفاً، نمط واحد):
 *    • L1 >= 50 و L5 < 17 ⇒ MIN لكل حقل من صفوف نفس ROUND(L1).
 *    • L1 < 50 ⇒ صف واحد حيث ROUND(L1) — مع دلالة TOO_MANY_ROWS الأصلية: تعدد الصفوف
 *      المطابقة يرفع استثناء النظام القديم فيملأ **لا شيء** (حماية التطابق الحرفي).
 *    • غير ذلك (L1 >= 50 و L5 >= 17) ⇒ صف واحد حيث (ROUND(L1), ROUND(L5)) بنفس الدلالة.
 *    ثم كل حقل من L2..L10 يُملأ فقط إن كان NULL — المدخل اليدوي لا يُمسّ أبداً.
 * ── The literal algorithm with the original TOO_MANY_ROWS semantics (multiple matching rows
 *    ⇒ nothing filled), filling only the NULL fields — user input is never touched.
 */
final class DefaultMeasurements
{
    /** الحقول المعبأة الافتراضية كما في الأصل / the filled fields exactly as the original */
    private const FIELDS = ['L2', 'L3', 'L4', 'L6', 'L7', 'L8', 'L9', 'L10'];

    /**
     * تعبئة المقاسات الفارغة من الجدول الافتراضي — تعمل على قيم الواجهة الحية
     * Fill the null measurements from the default table — operates on live form values.
     *
     * @param  array<string, float|int|null>  $measures  مقاسات المدخل L1..L10 / the input L1..L10
     * @param  array<int, array<string, float|int|null>>|null  $rows  صفوف محمّلة مسبقاً للاختبار / preloaded rows for tests
     * @return array<string, float|int|null> المقاسات بعد التعبئة / the measures after filling
     */
    public static function fill(array $measures, ?array $rows = null): array
    {
        $l1 = $measures['L1'] ?? null;
        $l5 = $measures['L5'] ?? null;

        if ($l1 === null || $l5 === null) {
            return $measures;
        }

        $defaults = $rows ?? self::loadRows();

        if ($defaults === []) {
            return $measures;
        }

        // الفرع الحرفي: دقّة ROUND أحادية مثل أوراكل (نصف بعيداً عن الصفر)
        // The literal branch: single ROUND precision, half away from zero like Oracle.
        $r1 = self::r0((float) $l1);
        $r5 = self::r0((float) $l5);

        $picked = null;

        if ((float) $l1 >= 50 && (float) $l5 < 17) {
            // MIN لكل حقل على صفوف نفس ROUND(L1) — ولا تعبئة إن لم يوجد أي صف
            // MIN of each field over the rows of the same ROUND(L1) — no fill when none match.
            $matching = array_values(array_filter(
                $defaults,
                fn (array $row): bool => self::r0((float) $row['L1']) === $r1
            ));

            if ($matching !== []) {
                $picked = [];

                foreach (self::FIELDS as $field) {
                    $values = [];

                    foreach ($matching as $row) {
                        if ($row[$field] !== null) {
                            $values[] = (float) $row[$field];
                        }
                    }

                    // Oracle MIN يتجاهل NULL: قائمة فارغة تعني لا قيمة (تبقى NULL)
                    // Oracle MIN ignores NULL: an empty list means no value (stays NULL).
                    $picked[$field] = $values === [] ? null : min($values);
                }
            }
        } elseif ((float) $l1 < 50) {
            // صف واحد فقط — التعدد يستثنى كما يفعل SELECT INTO القديم
            // Exactly one row — multiplicity is excluded as the legacy SELECT INTO does.
            $matching = array_values(array_filter(
                $defaults,
                fn (array $row): bool => self::r0((float) $row['L1']) === $r1
            ));

            if (count($matching) === 1) {
                $picked = self::readRow($matching[0]);
            }
        } else {
            // صف واحد حيث (ROUND(L1), ROUND(L5)) — نفس دلالة التعدد
            // One row by (ROUND(L1), ROUND(L5)) — the same multiplicity rule.
            $matching = array_values(array_filter(
                $defaults,
                fn (array $row): bool => self::r0((float) $row['L1']) === $r1
                    && self::r0((float) $row['L5']) === $r5
            ));

            if (count($matching) === 1) {
                $picked = self::readRow($matching[0]);
            }
        }

        if ($picked === null) {
            return $measures;
        }

        $out = $measures;

        foreach (self::FIELDS as $field) {
            if (($out[$field] ?? null) === null && $picked[$field] !== null) {
                $out[$field] = $picked[$field];
            }
        }

        return $out;
    }

    /**
     * تحميل صفوف الجدول الافتراضي — تُستدعى مرة واحدة لكل حساب
     * Load the default-table rows — one read per calculation.
     *
     * @return array<int, array<string, float|int|null>>
     */
    public static function loadRows(): array
    {
        try {
            $dbRows = DB::connection('oracle')->select(
                'SELECT SHAPE_STYLE_NO, L1, L2, L3, L4, L5, L6, L7, L8, L9, L10
                   FROM ACCOUNTS.DEFAULT_MEASURMENT
                  ORDER BY SEQ'
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $rows = [];

        foreach ($dbRows as $dbRow) {
            $data = (array) $dbRow;
            $row = [];

            foreach (array_merge(['L1', 'L5'], self::FIELDS) as $field) {
                $value = null;

                foreach ($data as $k => $v) {
                    if (strcasecmp($k, $field) === 0) {
                        $value = $v;
                        break;
                    }
                }

                $row[$field] = $value === null || ! is_numeric($value) ? null : (float) $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * قراءة حقول صف واحد / read one row's fields.
     *
     * @param  array<string, float|int|null>  $row
     * @return array<string, float|int|null>
     */
    private static function readRow(array $row): array
    {
        $picked = [];

        foreach (self::FIELDS as $field) {
            $picked[$field] = $row[$field] ?? null;
        }

        return $picked;
    }

    /**
     * ROUND(x,0) بأوراكل — نصف بعيداً عن الصفر / Oracle ROUND(x,0) — half away from zero.
     */
    private static function r0(float $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_UP);
    }
}
