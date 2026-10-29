<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * القراءة الموحّدة من ACCOUNTS.CUTTING_SALES_BILL VIEW وجداولها المكمّلة
 * Shared reader helpers for ACCOUNTS.CUTTING_SALES_BILL_VIEW and its companion tables.
 *
 * وُجد هذا الصنف لأن مصدر المقاسات واحد: متحكم كروت التفصيل ومتحكم مقاسات العملاء يقرآن
 * الواجهة نفسها والأعمدة نفسها، فترك المنطق مكرّراً في مكانين يعني أن إصلاح ترميز أو
 * إضافة مقاس L13 سيكون نصفه مفقوداً.
 * This class exists because the measurement source is one thing: the cutting-bill controller
 * and the customer-measurements controller read the same view and the same columns. Duplicating
 * the logic in both would mean a future encoding fix or an L13 label landing in only one place.
 *
 * كل ما هنا قراءة فقط ولا يكتب أي جدول إرثي، وكل قيمة مربوطة عند استخدامها.
 * Everything here is read-only, writes no legacy table, and binds every value at use.
 *
 * ملاحظة حالة المفاتيح: أوراكل/oci8 قد يعيد الأعمدة بحروف كبيرة أو صغيرة حسب المسار، لذلك
 * `value()` هي النقطة الوحيدة التي تعرف هذا التبديل.
 * Key case: oci8 may return column names upper- or lower-case depending on the path, so
 * `value()` is the single place that knows about that swap.
 */
final class CuttingViewReader
{
    /** واجهة الفواتير المُسندة إلى قصّاص وخيّاط / the assigned cutting-bill view */
    public const VIEW_BILL = 'ACCOUNTS.CUTTING_SALES_BILL_VIEW';

    /** صف الفاتورة الوحيد — لما لا تحمله الواجهة (عنوان، هاتف ثانٍ، ملاحظات) */
    /** The single bill row — for what the view does not carry (address, second phone, notes) */
    public const TABLE_SALES_BILL = 'ACCOUNTS.SALES_BILL';

    /** صفوف الموديلات — المواصفات النصية غير الموجودة في الواجهة إطلاقاً */
    /** Model rows — the spec texts the view never exposes */
    public const TABLE_SHAPES = 'ACCOUNTS.SALES_DETAILES_SHAPES';

    /** قاموس الأشكال / the shape dictionary */
    public const TABLE_STYLE = 'ACCOUNTS.STYLE';

    /** قاموس أنواع الموديلات / the model-type dictionary */
    public const TABLE_TYPES = 'ACCOUNTS.TYPES';

    /** قاموس حالات سير الطلب / the workflow-stage dictionary */
    public const TABLE_ORDSTATE = 'ACCOUNTS.ORDSTATE';

    /** سجل حركة القطع / the piece movement log */
    public const TABLE_TRANSD = 'ACCOUNTS.CUTTING_TRANSD';

    /** قاموس الأقسام / the department dictionary */
    public const TABLE_DEPTS = 'ACCOUNTS.DEPTS';

    /** تسميات المقاسات L1..L12 / the L1..L12 measurement labels */
    public const TABLE_LABELS = 'ACCOUNTS.GENERAL_LABELS_CODING';

    /** القيمة الحرفية التي يكتبها النظام القديم بدل ترك خانة الخيّاط فارغة */
    /** The literal the legacy system writes instead of leaving the tailor empty */
    private const NO_TAILOR_SENTINEL = 'لا يوجد';

    /** عدد خانات المقاسات في الواجهة — ثابت لأن الأعمدة L1..L12 ثابتة في القاعدة */
    /** The number of measurement slots — fixed because L1..L12 are fixed view columns */
    public const MEASURE_SLOTS = 12;

    /**
     * صنف ثابت بالكامل — لا حالة ولا إنشاء
     * Fully static class — no state, no instances
     */
    private function __construct()
    {
        // منع الإنشاء: كل الدوال ثابتة / creation is meaningless here
    }

    /**
     * المقاسات L1..L12 كما هي — التسمية تأتي من GENERAL_LABELS_CODING لا من الشاشة
     * Measurements L1..L12 exactly as stored; their labels come from GENERAL_LABELS_CODING.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, float|int|null>
     */
    public static function measurements(array $row): array
    {
        $measures = [];

        for ($i = 1; $i <= self::MEASURE_SLOTS; $i++) {
            $measures['L'.$i] = self::num($row, 'L'.$i);
        }

        return $measures;
    }

    /**
     * المواصفات النصية والأكواد الرقمية كما هي بلا ترجمة مخترعة
     * Spec texts and numeric codes as stored, with no invented translation.
     *
     * الأعمدة من SALES_DETAILES_SHAPES، وهي غير موجودة في الواجهة؛ القيم الرقمية
     * (SHAPE_*_NO) تترك أكواداً لأن قاموسها لم يُعتمد بعد في المشروع.
     * These columns come from SALES_DETAILES_SHAPES (absent from the view); the numeric
     * SHAPE_*_NO values stay codes because no dictionary for them is ratified yet.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function specs(array $row): array
    {
        return [
            'neck_type' => self::txt($row, 'NECK_TYPE'),
            'tipper_type' => self::txt($row, 'TIPPER_TYPE'),
            'com_cabak_type' => self::txt($row, 'COM_CABAK_TYPE'),
            'pocket_or_without' => self::txt($row, 'POCKET_OR_WITHOUT'),
            'gabzor_type' => self::txt($row, 'GABZOR_TYPE'),
            'button_type' => self::txt($row, 'BUTTON_TYPE'),
            'button_no' => self::txt($row, 'BUTTON_NO'),
            'mobile_pocket' => self::txt($row, 'MOBILE_POCKET'),
            'pen_pocket' => self::txt($row, 'PEN_POCKET'),
            'hegazi_type' => self::txt($row, 'HEGAZI_TYPE'),
            'notes' => self::txt($row, 'NOTES2'),
            'neck_shape_no' => self::num($row, 'NECK_SHAPE_NO'),
            'shape_gabzor_no' => self::num($row, 'SHAPE_GABROZ_NO'),
            'shape_com_cabak_no' => self::num($row, 'SHAPE_COM_CABAK_NO'),
            'shape_pocket_up_no' => self::num($row, 'SHAPE_POCKET_UP_NO'),
            'shape_pocket_down_no' => self::num($row, 'SHAPE_POCKET_DOWN_NO'),
        ];
    }

    /**
     * تسميات المقاسات الحقيقية من GENERAL_LABELS_CODING (L1..L12)
     * The real measurement labels from GENERAL_LABELS_CODING (L1..L12).
     *
     * فشل القراءة لا يكسر الشاشة: المفاتيح تعود `null` والواجهة تعرض الاسم الحرفي L11/L12
     * عند غياب التسمية، فتبقى المقاسات نفسها معروضة مهما حدث للقاموس.
     * A failed read never breaks the screen: keys stay null and the UI falls back to the raw
     * key, so the measurements themselves survive any dictionary trouble.
     *
     * @return array<string, string|null>
     */
    public static function measureLabels(): array
    {
        $labels = [];

        for ($i = 1; $i <= self::MEASURE_SLOTS; $i++) {
            $labels['L'.$i] = null;
        }

        try {
            $row = DB::connection('oracle')->table(self::TABLE_LABELS)->first();

            if ($row) {
                foreach ((array) $row as $key => $value) {
                    $upper = strtoupper((string) $key);
                    if (preg_match('/^L([0-9]{1,2})$/', $upper) === 1) {
                        $labels[$upper] = ArabicEncoding::normalizeStatementText($value);
                    }
                }
            }
        } catch (\Throwable $e) {
            // القاموس تزييني: لا يُفشل العرض لأجله
            // The dictionary is decoration: never fail the screen for it
            report($e);
        }

        return $labels;
    }

    /**
     * اسم حالة السير الحالية من قاموس ORDSTATE، أو null عند غيابها
     * The current workflow stage name from ORDSTATE, or null when it is absent.
     */
    public static function orderStateName(mixed $ordsdid): ?string
    {
        if ($ordsdid === null || $ordsdid === '') {
            return null;
        }

        try {
            $row = DB::connection('oracle')
                ->table(self::TABLE_ORDSTATE)
                ->where('ORDSID', (int) $ordsdid)
                ->first();

            if (! $row) {
                return null;
            }

            return ArabicEncoding::fixMojibake((string) ((array) $row)['ORDSNAME']);
        } catch (\Throwable $e) {
            // اسم الحالة تزييني: فشله لا يُفشل الصف
            // The stage name is decoration: its failure must not fail the row
            report($e);

            return null;
        }
    }

    /**
     * «لا يوجد» قيمة حرفية يكتبها النظام القديم حين لا يُسند خيّاط — تُعرض «غير مسند»
     * "لا يوجد" is a literal the legacy system writes when no tailor is assigned.
     */
    public static function normalizeTailorName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        if ($trimmed === '' || $trimmed === self::NO_TAILOR_SENTINEL) {
            return null;
        }

        return $trimmed;
    }

    /**
     * قراءة حقل يُحتمل وروده بالحالتين (oci8 يعيد المفاتيح صغيرة أحياناً)
     * Read a field that may arrive in either case (oci8 sometimes returns lowercase keys).
     *
     * @param  array<string, mixed>  $row
     */
    public static function value(array $row, string $key): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $lower = strtolower($key);

        return $row[$lower] ?? null;
    }

    /**
     * حقل نصي مُصلَّح الترميز، أو null عند الفراغ
     * A text field with its encoding repaired, or null when blank.
     *
     * @param  array<string, mixed>  $row
     */
    public static function txt(array $row, string $key): ?string
    {
        $value = self::value($row, $key);

        if ($value === null || $value === '') {
            return null;
        }

        $fixed = ArabicEncoding::normalizeStatementText($value);

        return $fixed === '' ? null : $fixed;
    }

    /**
     * حقل رقمي — أرقام أوراكل قد تصل نصوصاً
     * A numeric field — Oracle numbers may arrive as strings.
     *
     * الأعداد الصحيحة تبقى صحيحة كي لا يُطبع «400.0» في خانة رقم فاتورة.
     * Whole numbers stay integers so a bill number never prints as "400.0".
     *
     * @param  array<string, mixed>  $row
     */
    public static function num(array $row, string $key): float|int|null
    {
        $value = self::value($row, $key);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float ? (int) $float : $float;
    }

    /**
     * قيمة صف SALES_BILL بحروف صغيرة مُصلَّحة الترميز
     * A SALES_BILL value with its text repaired.
     *
     * @param  array<string, mixed>  $row
     */
    public static function lower(array $row, string $key): string|int|float|null
    {
        $value = self::value($row, $key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            $float = (float) $value;

            return floor($float) === $float ? (int) $float : $float;
        }

        return ArabicEncoding::normalizeStatementText($value);
    }

    /**
     * تاريخ للعرض (يوم/شهر/سنة) — يقبل عمود DATE والنص المسبقة التنسيق
     * A display date (DD/MM/YYYY) — accepts both a DATE column and pre-formatted text.
     *
     * السنة دون 1900 تعني فراغاً في النظام القديم لا تاريخاً حقيقياً، فتُترك null.
     * A year below 1900 is the legacy system's way of storing "empty", so it stays null.
     *
     * @param  array<string, mixed>  $row
     */
    public static function dateTxt(array $row, string $key): ?string
    {
        $raw = self::value($row, $key);

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $date = $raw instanceof \DateTimeInterface
                ? Carbon::instance($raw)
                : Carbon::parse((string) $raw);

            return $date->year < 1900 ? null : $date->format('d/m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * مفاتيح صفحة واحدة كمزيج (رقم، سنة) — يُستخدم في استعلامات التجميع بالـ IN
     * One page of composite (bill, year) keys — used by the batched aggregate `IN` lists.
     *
     * أوراكل 11g لا تعرف FETCH/OFFSET، فتُقرأ المفاتيح بـ ROWNUM ثم تُجمَّع دفعة واحدة.
     * Oracle 11g has no OFFSET/FETCH, so keys come back through ROWNUM and aggregate in one batch.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, array{bill_no: float|int|null, year: float|int|null}>
     */
    public static function billKeys(array $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $keys[] = [
                'bill_no' => self::num((array) $row, 'BILL_NO'),
                'year' => self::num((array) $row, 'YEAR'),
            ];
        }

        return $keys;
    }
}
