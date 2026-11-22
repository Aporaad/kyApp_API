<?php

use App\Support\Tailoring\DefaultMeasurements;

/**
 * البوابة الذهبية للمقاسات الافتراضية — الـ138 صفاً بنسبة 100% (خطة §9.6)
 * The default-measurements golden gate — all 138 rows at 100% (plan §9.6).
 *
 * ── لكل صف من DEFAULT_MEASURMENT تُحاكي مدخلاته (L1, L5) ويُتوقع ملء L2..L10 من قيم
 *    الصف نفسه أو من قاعدة SQL مستقلة تطابق الإجراء القديم (MIN أو صف واحد أو لا ملء).
 *    تطبيق PHP يُقارن مع تنفيذ SQL مستقل — فحص حقيقي لا دوري.
 * ── Each row's (L1, L5) is simulated and the L2..L10 fill is compared against an
 *    independent SQL implementation of the legacy procedure — a real check, not tautology.
 */
test('default measurements fill matches the SQL oracle for all 138 rows', function (): void {
    $rows = DefaultMeasurements::loadRows();

    if ($rows === []) {
        markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا صفوف المقاسات الافتراضية.');

        return;
    }

    $match = 0;
    $total = 0;
    $mismatches = [];

    foreach ($rows as $row) {
        if ($row['L1'] === null || $row['L5'] === null) {
            continue;
        }

        $total++;

        // مدخل فارغ L2..L10 — التعبئة يجب أن تعيد قيم الصف المطابق
        // Blank L2..L10 input — the fill must reproduce the matching row's values.
        $filled = DefaultMeasurements::fill([
            'L1' => $row['L1'],
            'L5' => $row['L5'],
            'L2' => null, 'L3' => null, 'L4' => null,
            'L6' => null, 'L7' => null, 'L8' => null, 'L9' => null, 'L10' => null,
        ], $rows);

        // التوقع المستقل من SQL: نفس استعلامات الإجراء القديم حرفياً
        // The independent SQL oracle: the legacy procedure's own queries, verbatim.
        $expected = sqlExpected((float) $row['L1'], (float) $row['L5'], $rows);

        $ok = true;

        foreach (['L2', 'L3', 'L4', 'L6', 'L7', 'L8', 'L9', 'L10'] as $field) {
            $got = $filled[$field];
            $want = $expected[$field];

            if ($want === null) {
                $ok = $ok && $got === null;
            } else {
                $ok = $ok && $got !== null && abs((float) $got - (float) $want) <= 0.001;
            }
        }

        if ($ok) {
            $match++;
        } else {
            $mismatches[] = ['l1' => $row['L1'], 'l5' => $row['L5'], 'want' => $expected, 'got' => array_intersect_key($filled, $expected)];
        }
    }

    if ($mismatches !== []) {
        dump('DEFAULT MEASUREMENT MISMATCHES ('.count($mismatches).'): '.json_encode(
            array_slice($mismatches, 0, 10),
            JSON_UNESCAPED_UNICODE
        ));
    }

    expect($total)->toBeGreaterThan(100)
        ->and($match)->toBe($total);
});

/**
 * توقع SQL المستقل لحالة واحدة — حرفياً كإجراء النموذج القديم
 * The independent SQL expectation for one case — literally the legacy procedure's queries.
 *
 * @param  array<int, array<string, float|int|null>>  $rows
 * @return array<string, float|int|null>
 */
function sqlExpected(float $l1, float $l5, array $rows): array
{
    $r1 = (int) round($l1, 0, PHP_ROUND_HALF_UP);
    $r5 = (int) round($l5, 0, PHP_ROUND_HALF_UP);

    $fields = ['L2', 'L3', 'L4', 'L6', 'L7', 'L8', 'L9', 'L10'];
    $empty = array_fill_keys($fields, null);

    // الفرع الأول: L1 >= 50 و L5 < 17 ⇒ MIN لكل حقل على نفس ROUND(L1)
    // First branch: L1 >= 50 and L5 < 17 ⇒ MIN per field over the same ROUND(L1).
    if ($l1 >= 50 && $l5 < 17) {
        $matching = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['L1'] !== null && (int) round((float) $row['L1'], 0, PHP_ROUND_HALF_UP) === $r1
        ));

        if ($matching === []) {
            return $empty;
        }

        $out = [];

        foreach ($fields as $field) {
            $values = [];

            foreach ($matching as $row) {
                if ($row[$field] !== null) {
                    $values[] = (float) $row[$field];
                }
            }

            $out[$field] = $values === [] ? null : min($values);
        }

        return $out;
    }

    // الفرع الثاني: L1 < 50 ⇒ صف واحد حيث ROUND(L1) — التعدد ⇒ لا ملء (TOO_MANY_ROWS)
    // Second branch: L1 < 50 ⇒ a single row by ROUND(L1) — multiplicity ⇒ no fill.
    if ($l1 < 50) {
        $matching = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['L1'] !== null && (int) round((float) $row['L1'], 0, PHP_ROUND_HALF_UP) === $r1
        ));

        return count($matching) === 1 ? array_intersect_key($matching[0], $empty) : $empty;
    }

    // الفرع الثالث: صف واحد حيث (ROUND(L1), ROUND(L5)) — التعدد ⇒ لا ملء
    // Third branch: one row by (ROUND(L1), ROUND(L5)) — multiplicity ⇒ no fill.
    $matching = array_values(array_filter(
        $rows,
        fn (array $row): bool => $row['L1'] !== null && $row['L5'] !== null
            && (int) round((float) $row['L1'], 0, PHP_ROUND_HALF_UP) === $r1
            && (int) round((float) $row['L5'], 0, PHP_ROUND_HALF_UP) === $r5
    ));

    return count($matching) === 1 ? array_intersect_key($matching[0], $empty) : $empty;
}
