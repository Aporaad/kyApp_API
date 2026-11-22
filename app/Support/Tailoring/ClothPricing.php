<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * التسعيرة — نقل حرفي لكتلة حساب التكلفة في النموذج القديم، مع حماية السعر الصفري
 * Pricing — a literal port of the legacy cost block, guarded against the zero price.
 *
 * ── الصيغة الحرفية (المثبتة حسابياً على فاتورة 22096):
 *    SUM = STK_COST × YARDS × R1 + ESTIMATED_COST_TOTAL
 *    SANF_COST = ROUND(SUM × R2 / 500) × 500
 *    SANFPRICE = ROUND(SANF_COST × R3, -3)
 * ── The literal formula, arithmetically proved on bill 22096.
 *
 * ── معاملات الفئة من أنماط LIKE الحرفية في النموذج — الاستخراج النصي شوّه محارفها،
 *    فحُدّدت بالدلالة المثبتة من تعليقات الأصل والبيانات الحيّة (ياباني/تايلندي/كوري/
 *    سنغافور/صيني بالاحتواء/اندونيسي)، وتُطابق في صيغة أوراكل (CP1256 كمحارف Latin-1)
 *    وفي الصيغة العربية الصحيحة معاً.
 * ── The per-category factors come from the form's LIKE patterns whose characters were
 *    corrupted by the text extraction, so they are pinned by the source comments and live
 *    data (Japan/Thailand/Korea/Singapore/China-by-contains/Indonesia) and matched in both
 *    the Oracle byte form and the proper Arabic form.
 *
 * ── حماية القرار D4: فئة غير معروفة أو تكلفة مفقودة ⇒ pricing_status يدوي — لا سعر صفر.
 *    (النظام القديم كان يحسب 0 للفئة غير المعروفة عبر R=0، وهذا مرفوض هنا.)
 * ── Decision D4 guard: unknown category or missing cost ⇒ a manual pricing status — never
 *    a zero price. (The legacy computed 0 via R=0 for unknown categories; that is refused.)
 */
final class ClothPricing
{
    /** حالات التسعير التي تعرفها الواجهة / the pricing statuses the UI knows */
    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_MANUAL_REQUIRED = 'manual_required';

    public const STATUS_CATEGORY_UNKNOWN = 'category_unknown';

    public const STATUS_COST_MISSING = 'cost_missing';

    /**
     * معاملات الفئات (R1, R2, R3) بترتيب فحص الأصل: اليابان، تايلاند، كوريا، سنغافور،
     * الصين (بالاحتواء — تشمل صيني وشتوي صيني وريتشي صيني)، إندونيسيا.
     * The category factors in the original check order: Japan, Thailand, Korea, Singapore,
     * China (by containment — covers صيني/شتوي صيني/ريتشي صيني), Indonesia.
     *
     * @var array<string, array<float, float, float>>
     */
    private const CATEGORY_FACTORS = [
        'ياباني' => [1.5, 1.3, 1.4],     // JAPAN
        'تايلندي' => [1.5, 1.2, 1.3],    // TAILAND
        'كوري' => [1.5, 1.2, 1.4],       // KOREA
        'سنغافور' => [1.5, 1.5, 1.4],    // SINGAFOR
        'صيني' => [1.5, 1.3, 1.3],       // CHINA — contains
        'اندونيسي' => [1.5, 1.2, 1.3],   // INDONISIA
    ];

    /**
     * حساب سعر قطعة — يُرجع الحقول كما تُعرض في الواجهة
     * Price one piece — returns the fields the UI displays.
     *
     * @param  array{part_no: string|null, styl_no: int|null, yards: float|null, from_store: int|null}  $in
     * @return array{stk_cost: float|null, estimated_cost: float|null, sanf_cost: float|null,
     *               default_price: float|null, pricing_status: string, pricing_message: string|null}
     */
    public static function price(array $in): array
    {
        $fromStore = (int) ($in['from_store'] ?? TailoringPolicy::CLOTH_FROM_SHOP);
        $yards = $in['yards'];

        // STK_COST = MAX(LAST_COST.AV1) WHERE PART_NO — من العمل خلافاً للصفر عند قماش الزبون
        // STK_COST = MAX(LAST_COST.AV1) by the fabric's PART_NO — zero for customer cloth.
        $stkCost = null;

        if ($fromStore === TailoringPolicy::CLOTH_FROM_SHOP) {
            $stkCost = self::lastCost((string) ($in['part_no'] ?? ''));
        }

        $estimatedCost = self::estimatedCost($in['styl_no'] === null ? null : (int) $in['styl_no']);
        $category = self::category((string) ($in['part_no'] ?? ''));

        $missing = [];

        if ($stkCost === null) {
            $missing[] = 'last_cost';
        }

        if ($estimatedCost === null) {
            $missing[] = 'estimated_cost';
        }

        if ($yards === null) {
            $missing[] = 'consumption';
        }

        if ($missing !== []) {
            return [
                'stk_cost' => $stkCost,
                'estimated_cost' => $estimatedCost,
                'sanf_cost' => null,
                'default_price' => null,
                'pricing_status' => self::STATUS_COST_MISSING,
                'pricing_message' => self::missingMessage($missing, $stkCost === null),
            ];
        }

        $factors = self::factorsFor($category);

        if ($factors === null) {
            return [
                'stk_cost' => $stkCost,
                'estimated_cost' => $estimatedCost,
                'sanf_cost' => null,
                'default_price' => null,
                'pricing_status' => self::STATUS_CATEGORY_UNKNOWN,
                'pricing_message' => $category === null
                    ? 'فئة القماش غير محددة — أدخل السعر يدوياً'
                    : ('فئة القماش ['.$category.'] غير معروفة للتسعير الآلي — أدخل السعر يدوياً'),
            ];
        }

        [$r1, $r2, $r3] = $factors;

        // الصيغة الحرفية: ROUND نصف بعيداً عن الصفر مثل أوراكل، وROUND(x,-3) لأقرب ألف.
        // The literal formula: half-away-from-zero rounding like Oracle, ROUND(x,-3) to the 1000.
        $sum = (float) $stkCost * (float) $yards * $r1 + (float) $estimatedCost;
        $sanfCost = self::oracleRound($sum * $r2 / 500) * 500;
        $sanfPrice = self::oracleRound($sanfCost * $r3 / 1000) * 1000;

        if ($sanfPrice <= 0) {
            return [
                'stk_cost' => $stkCost,
                'estimated_cost' => $estimatedCost,
                'sanf_cost' => $sanfCost,
                'default_price' => null,
                'pricing_status' => self::STATUS_MANUAL_REQUIRED,
                'pricing_message' => 'الحساب الآلي أنتج سعراً غير صالح — أدخل السعر يدوياً',
            ];
        }

        return [
            'stk_cost' => $stkCost,
            'estimated_cost' => $estimatedCost,
            'sanf_cost' => $sanfCost,
            'default_price' => $sanfPrice,
            'pricing_status' => self::STATUS_CALCULATED,
            'pricing_message' => null,
        ];
    }

    /**
     * STK_COST من LAST_COST — MAX(AV1) حرفياً كالأصل
     * The stock cost from LAST_COST — literally MAX(AV1) as the original.
     */
    public static function lastCost(string $partNo): ?float
    {
        if ($partNo === '') {
            return null;
        }

        try {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT MAX(AV1) AS C FROM ACCOUNTS.LAST_COST WHERE PART_NO = :part_no',
                ['part_no' => $partNo]
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $value = reset($row);

        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }

    /**
     * إجمالي التكلفة التقديرية من ESTIMATED_COST بـ STYLE_NO (STYL_NO للموديل)
     * The estimated cost total from ESTIMATED_COST by the model's STYL_NO.
     */
    public static function estimatedCost(?int $stylNo): ?float
    {
        if ($stylNo === null || $stylNo <= 0) {
            return null;
        }

        try {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT TOTAL FROM ACCOUNTS.ESTIMATED_COST WHERE STYLE_NO = :styl_no',
                ['styl_no' => $stylNo]
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($row === []) {
            return null;
        }

        $value = reset($row);

        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }

    /**
     * فئة القماش من SANFS بـ PART_NO كما يقرأ الأصل
     * The fabric category from SANFS by PART_NO, as the original reads it.
     */
    public static function category(string $partNo): ?string
    {
        if ($partNo === '') {
            return null;
        }

        try {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT CATEGORY FROM ACCOUNTS.SANFS WHERE PART_NO = :part_no',
                ['part_no' => $partNo]
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($row === []) {
            return null;
        }

        $value = reset($row);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return ArabicEncoding::fixMojibake(trim((string) $value));
    }

    /**
     * معاملات الفئة بمطابقة الصيغتين (المخزّن المعوجّر والمُدخل الصحيح)
     * The category factors matching both byte forms (stored mojibake and proper input).
     *
     * ترتيب الفحص كالأصل، والصيني بالاحتواء (نمط %????% الحرفي).
     * The check order follows the original; China matches by containment (the literal %????%).
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function factorsFor(?string $category): ?array
    {
        if ($category === null) {
            return null;
        }

        $category = trim($category);
        $oracleForm = ArabicEncoding::toOracleSearchChars($category);

        foreach (self::CATEGORY_FACTORS as $name => $factors) {
            // المطابقة في الصيغتين: المخزون بايتات CP1256 وقد تكون بعض الصفوف مكتوبة سليمة.
            // Match in both forms: storage holds CP1256 bytes, but some rows are written clean.
            $candidates = [$name, ArabicEncoding::toOracleSearchChars($name) ?? $name];

            foreach ($candidates as $needle) {
                if ($needle === null) {
                    continue;
                }

                if ($name === 'صيني') {
                    // الاحتواء كما في النمط الحرفي '%????%' — يشمل شتوي صيني وريتشي صيني
                    // Containment as in the literal '%????%' — covers شتوي صيني and ريتشي صيني.
                    $haystacks = [$category, $oracleForm ?? $category];

                    foreach ($haystacks as $haystack) {
                        if ($haystack !== null && str_contains($haystack, $needle)) {
                            return $factors;
                        }
                    }

                    continue;
                }

                if ($category === $needle || $category === $name || ($oracleForm !== null && $oracleForm === $needle)) {
                    return $factors;
                }
            }
        }

        return null;
    }

    /**
     * تقريب أوراكل ROUND(x, 0) — نصف بعيداً عن الصفر
     * Oracle ROUND(x, 0) — half away from zero.
     */
    private static function oracleRound(float $value): float
    {
        return round($value, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * رسالة النقص المركّبة / the composed missing-input message.
     */
    private static function missingMessage(array $missing, bool $costMissing): string
    {
        $parts = [];

        if (in_array('last_cost', $missing, true)) {
            $parts[] = 'لا توجد تكلفة أخيرة للصنف';
        }

        if (in_array('estimated_cost', $missing, true)) {
            $parts[] = 'لا توجد تكلفة تقديرية للنمط';
        }

        if (in_array('consumption', $missing, true)) {
            $parts[] = 'لم يُحسب الاستهلاك';
        }

        return implode('، ', $parts).' — أدخل السعر يدوياً';
    }
}
