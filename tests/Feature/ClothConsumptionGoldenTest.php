<?php

use App\Support\ArabicEncoding;
use App\Support\Tailoring\ClothConsumption;
use App\Support\Tailoring\TailoringPolicy;
use Illuminate\Support\Facades\DB;

/**
 * البوابة الذهبية لاستهلاك القماش — إعادة حساب 100 قطعة تاريخية ومقارنتها بالقيم المخزنة
 * The cloth-consumption golden gate — recompute 100 historical pieces against stored values.
 *
 * ── TYPENO للاستهلاك ليس SUBSTR(STYL_NO,1,3) بل تعيين TYPES.CALC_TYPE على رقم العائلة:
 *    قطريA/B/C/جاهز (4/5/6/9) تحسب بقواعد قطري 301 — قيس حيّاً على مئات القطع.
 * ── The consumption TYPENO is NOT SUBSTR(STYL_NO,1,3) but the TYPES.CALC_TYPE mapping of
 *    the family index: QatariA/B/C/Ready (4/5/6/9) consume by the plain Qatari rules (301).
 *
 * كل الحسابات قراءة فقط: لا صف يُكتب في أي جدول إرث. الشرط المعتمد: تطابق ≥ 99% قبل
 * تفعيل الحفظ (خطة §9.6) — وأي فرق يُطبع بالتفصيل ليُفحص قبل النشر.
 * Everything is read-only: no legacy row is written. The ratified gate is ≥ 99% exact
 * matches before the save flow activates (plan §9.6) — every mismatch is dumped for review.
 */
test('cloth consumption reproduces 100 historical pieces at 99% or better', function (): void {
    $pieces = DB::connection('oracle')->select(
        'SELECT * FROM (
            SELECT m.SANF_NO, m.CLOTH_TYPE, m.CONSUMED_CLOTH_QTY_HINSH AS EXPECTED,
                   m.CONSUMED_CLOTH_QTY_HINSH1 AS EXPECTED1, m.SHARKH AS EXPECTED_SHARKH,
                   s.L1, s.L2, s.L3, s.L5, s.L8,
                   s.COM_CABAK_TYPE, s.POCKET_OR_WITHOUT,
                   s.SHAPE_STYLE_NO AS FAMILY,
                   TO_NUMBER(SUBSTR(TO_CHAR(s.STYL_NO), 1, 3)) AS ITEM_TYPE_NO,
                   m.BILL_NO, m.YEAR, m.SEQ_NO1
              FROM ACCOUNTS.MULTI_CLOTHS m
              JOIN ACCOUNTS.SALES_DETAILES_SHAPES s
                ON s.BILL_NO = m.BILL_NO AND s.YEAR = m.YEAR AND s.SEQ_NO = m.SEQ_NO
             WHERE m.CONSUMED_CLOTH_QTY_HINSH IS NOT NULL
               AND m.YEAR >= 2023
               AND s.STYL_NO IS NOT NULL
             ORDER BY m.YEAR DESC, m.BILL_NO DESC
         ) WHERE ROWNUM <= 100'
    );

    if ($pieces === []) {
        markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا عينة قطع.');

        return;
    }

    // خريطة مجموعات المواصفات مسبقة التحميل — نفس منطق ClothConsumption::proGroup
    // The prefetched spec-group map — the same logic as ClothConsumption::proGroup.
    $groupMap = goldenPrefetchGroups();

    $match = 0;
    $compared = 0;
    $mismatches = [];

    foreach ($pieces as $row) {
        $data = (array) $row;
        $expected = goldenNum($data, 'EXPECTED');

        if ($expected === null) {
            continue;
        }

        $compared++;

        $clothType = goldenText($data, 'CLOTH_TYPE');
        $g2 = goldenGroupOf($groupMap, 2, goldenText($data, 'COM_CABAK_TYPE'));
        $g4 = goldenGroupOf($groupMap, 4, goldenText($data, 'POCKET_OR_WITHOUT'));

        // نوع الاحتساب من تعيين CALC_TYPE على رقم العائلة — ليس SUBSTR(STYL_NO,1,3)
        // The consumption type from the CALC_TYPE mapping — not SUBSTR(STYL_NO,1,3).
        $family = (int) (goldenNum($data, 'FAMILY') ?? 1);
        $consumptionTypeno = TailoringPolicy::consumptionTypeNo($family);

        $result = ClothConsumption::calculate([
            'typeno' => $consumptionTypeno,
            'cloth_type' => $clothType,
            'l1' => goldenNum($data, 'L1'),
            'l2' => goldenNum($data, 'L2'),
            'l3' => goldenNum($data, 'L3'),
            'l5' => goldenNum($data, 'L5'),
            'l8' => goldenNum($data, 'L8'),
            'pro_group2' => $g2,
            'pro_group4' => $g4,
            'qty' => 1,
        ]);

        $hinshOk = $result['hinsh'] !== null && abs((float) $result['hinsh'] - (float) $expected) <= 0.01;

        // الشرخ والهنش المزدوج يُقارنان فقط حيث خزّن النظام القديم قيمة
        // Sharkh and the doubled hinsh are compared only where the legacy stored one.
        $sharkhOk = true;
        $expectedSharkh = goldenText($data, 'EXPECTED_SHARKH');

        if ($expectedSharkh !== null) {
            $sharkhOk = $result['sharkh'] !== null
                && $result['sharkh'] === ArabicEncoding::fixMojibake($expectedSharkh);
        }

        $expected1 = goldenNum($data, 'EXPECTED1');

        if ($expected1 !== null) {
            $sharkhOk = $sharkhOk && $result['hinsh1'] !== null
                && abs((float) $result['hinsh1'] - (float) $expected1) <= 0.01;
        }

        if ($hinshOk && $sharkhOk) {
            $match++;
        } else {
            $mismatches[] = [
                'bill' => goldenNum($data, 'BILL_NO').'/'.goldenNum($data, 'YEAR'),
                'cloth' => $clothType,
                'family' => $family,
                'ctypeno' => $consumptionTypeno,
                'l1' => goldenNum($data, 'L1'), 'l3' => goldenNum($data, 'L3'),
                'l5' => goldenNum($data, 'L5'), 'l8' => goldenNum($data, 'L8'),
                'g2' => $g2, 'g4' => $g4,
                'expected' => $expected,
                'got' => $result['hinsh'],
            ];
        }
    }

    $rate = $compared === 0 ? 0.0 : $match / $compared;

    // تقرير الفروق للفحص اليدوي قبل أي نشر / the mismatch report for manual pre-release review.
    if ($mismatches !== []) {
        dump('CONSUMPTION MISMATCHES ('.count($mismatches).'): '.json_encode(
            array_slice($mismatches, 0, 20),
            JSON_UNESCAPED_UNICODE
        ));
    }

    expect($compared)->toBeGreaterThan(50)
        ->and($rate)->toBeGreaterThanOrEqual(0.99);
});

/**
 * خريطة مجموعات PRO_DTYPE بالصيغتين مسبقة التحميل
 * The prefetched PRO_DTYPE group map in both byte forms.
 *
 * @return array<string, int>
 */
function goldenPrefetchGroups(): array
{
    $rows = DB::connection('oracle')->select(
        'SELECT PROMID, PRODANE, PRO_GROUP FROM ACCOUNTS.PRO_DTYPE WHERE ACTIVE = 1 AND PRO_GROUP IS NOT NULL'
    );

    $map = [];

    foreach ($rows as $row) {
        $data = (array) $row;
        $promid = null;
        $prodane = null;
        $group = null;

        foreach ($data as $k => $v) {
            if (strcasecmp($k, 'PROMID') === 0) {
                $promid = (int) $v;
            } elseif (strcasecmp($k, 'PRODANE') === 0) {
                $prodane = $v === null ? null : (string) $v;
            } elseif (strcasecmp($k, 'PRO_GROUP') === 0 && $v !== null && is_numeric($v)) {
                $group = (int) $v;
            }
        }

        if ($promid === null || $prodane === null || $group === null) {
            continue;
        }

        $fixed = ArabicEncoding::fixMojibake(trim($prodane));
        $map[$promid.'|'.trim($prodane)] = $group;
        $map[$promid.'|'.$fixed] = $group;
    }

    return $map;
}

/**
 * مجموعة مواصفة من الخريطة / a spec group from the map.
 *
 * @param  array<string, int>  $map
 */
function goldenGroupOf(array $map, int $promid, ?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    return $map[$promid.'|'.trim($value)] ?? null;
}

/**
 * قراءة رقم بحالة غير حساسة / case-insensitive numeric read.
 *
 * @param  array<string, mixed>  $data
 */
function goldenNum(array $data, string $key): float|int|null
{
    foreach ($data as $k => $v) {
        if (strcasecmp($k, $key) === 0 && $v !== null && is_numeric($v)) {
            $f = (float) $v;

            return floor($f) === $f ? (int) $f : $f;
        }
    }

    return null;
}

/**
 * قراءة نص مُصلح بحالة غير حساسة / case-insensitive repaired text read.
 *
 * @param  array<string, mixed>  $data
 */
function goldenText(array $data, string $key): ?string
{
    foreach ($data as $k => $v) {
        if (strcasecmp($k, $key) === 0 && $v !== null && trim((string) $v) !== '') {
            return ArabicEncoding::fixMojibake(trim((string) $v));
        }
    }

    return null;
}
