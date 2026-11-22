<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use App\Support\CuttingViewReader;
use Illuminate\Support\Facades\DB;

/**
 * مرجع إنشاء فاتورة التفصيل — كل القواميس والتسميات والأرقام التالية في حمولة واحدة
 * The cutting-bill creation reference — every dictionary, label and next number in one payload.
 *
 * ── القواميس تُقرأ حيّة من القاعدة: PRO_DTYPE (المواصفات الست)، DEFAULT_REQUESTS
 *    (الاقتراحات الافتراضية)، DEFAULT_MEASURMENT (138 صفاً)، SANFS_COLOR (الألوان)،
 *    وGENERAL_LABELS_CODING عبر CuttingViewReader::measureLabels().
 * ── Dictionaries are read live: PRO_DTYPE (the six spec families), DEFAULT_REQUESTS (the
 *    smart suggestions), DEFAULT_MEASURMENT (138 rows), SANFS_COLOR (colors) and the
 *    measurement labels through CuttingViewReader::measureLabels().
 *
 * ── القاموس المواصفاتي ملوّث تاريخياً (R7): تكرارات وقيم معطّلة، فتُفلتر ACTIVE=1 وتُزال
 *    التكرارات بـ (PROMID, PRODANE) مع تفضيل أول قيمة تحمل مجموعة (PRO_GROUP).
 * ── The spec dictionary is historically polluted (R7): duplicates and disabled rows, so it
 *    filters ACTIVE=1 and dedupes by (PROMID, PRODANE) preferring rows with a PRO_GROUP.
 *
 * ── معاينة OHID تقديرية فقط: `YY || 2 || 1 || MANUAL` — الزناد هو المرجع الفعلي.
 * ── The OHID preview is an estimate only: `YY || 2 || 1 || MANUAL` — the trigger decides.
 */
final class TailoringReference
{
    /**
     * الحمولة الكاملة لمرجع الإنشاء / the full creation-reference payload.
     *
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $year = TailoringPolicy::dbYear();
        $billNo = TailoringNumbering::nextBillNo($year);
        $manualNo = TailoringNumbering::nextManualNo($year);

        return [
            'next' => [
                'bill_no' => $billNo,
                'manual_bill_no' => $manualNo,
                'year' => $year,
                // معاينة الطلب — تقديرية لأن الزناد يركّب OHID فعلياً عند الإدراج
                // The OHID preview — an estimate because the trigger composes the real one.
                'ohid_preview' => self::ohidPreview($year, $manualNo),
            ],
            'bill_kind' => ['value' => TailoringPolicy::BILL_KIND, 'label' => 'عادية'],
            'bill_kinds' => [['value' => 1, 'label' => 'عادية']],
            'bill_type' => ['value' => TailoringPolicy::BILL_TYPE, 'label' => TailoringPolicy::BILL_TYPE_LABEL],
            'payment_methods' => TailoringPolicy::PAYMENT_METHODS,
            'measure_labels' => CuttingViewReader::measureLabels(),
            'model_families' => TailoringPolicy::reference()['model_families'],
            'specs' => self::specs(),
            'default_requests' => self::defaultRequests(),
            'default_measurements' => self::defaultMeasurements(),
            'width_types' => TailoringPolicy::reference()['width_types'],
            'colors' => self::colors(),
            'accounts' => TailoringPolicy::accounts(),
            'limits' => [
                'max_models' => TailoringPolicy::MAX_MODELS,
                'max_pieces' => TailoringPolicy::MAX_PIECES,
                'max_text' => TailoringPolicy::MAX_TEXT,
            ],
        ];
    }

    /**
     * الأرقام التالية وحدها — لإعادة التحيين بعد بقاء النموذج مفتوحاً
     * The next numbers alone — for a refresh after the form sat open.
     *
     * @return array<string, mixed>
     */
    public static function nextNumbers(): array
    {
        $year = TailoringPolicy::dbYear();
        $billNo = TailoringNumbering::nextBillNo($year);
        $manualNo = TailoringNumbering::nextManualNo($year);

        return [
            'bill_no' => $billNo,
            'manual_bill_no' => $manualNo,
            'year' => $year,
            'ohid_preview' => self::ohidPreview($year, $manualNo),
        ];
    }

    /**
     * معاينة OHID: `YY || BILL_TYPE || CUTTING_OR_SLAES || MANUAL_BILL_NO` (ربط نصي)
     * The OHID preview: `YY || BILL_TYPE || CUTTING_OR_SLAES || MANUAL_BILL_NO` (concat).
     */
    public static function ohidPreview(int $year, int $manualNo): int
    {
        return (int) (sprintf('%02d', $year % 100)
            .(string) TailoringPolicy::BILL_TYPE
            .(string) TailoringPolicy::CUTTING_OR_SLAES
            .(string) $manualNo);
    }

    /**
     * قواميس المواصفات الست من PRO_DTYPE — مفلترة ومعصومة من التكرار
     * The six spec dictionaries from PRO_DTYPE — filtered and deduplicated.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function specs(): array
    {
        // PROMID ⇒ مفتاح القاموس في الحمولة / PROMID ⇒ the payload dictionary key
        $keys = [
            1 => 'button_type',
            2 => 'com_cabak_type',
            3 => 'neck_type',
            4 => 'pocket_or_without',
            5 => 'gabzor_type',
            6 => 'mobile_pocket',
        ];

        $specs = [
            'button_type' => [],
            'com_cabak_type' => [],
            'neck_type' => [],
            'pocket_or_without' => [],
            'gabzor_type' => [],
            'mobile_pocket' => [],
        ];

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT PROMID, PRODANE, PRO_GROUP, ACTIVE
                   FROM ACCOUNTS.PRO_DTYPE
                  WHERE ACTIVE = 1
                  ORDER BY PROMID, PRODANE'
            );

            $seen = [];

            foreach ($rows as $row) {
                $data = (array) $row;
                $promid = (int) (self::pickNum($data, 'PROMID') ?? 0);
                $value = self::pickText($data, 'PRODANE');
                $group = self::pickNum($data, 'PRO_GROUP');

                if (! isset($keys[$promid]) || $value === null || $value === '') {
                    continue;
                }

                $value = ArabicEncoding::fixMojibake($value);

                // التكرار التاريخي: نفس القيمة أكثر من مرة — أول ورود يحمل القائمة، وإن جاء
                // ورودٌ لاحق بمجموعة (PRO_GROUP) و الأول بلا مجموعة فالأول يُمَم بلا مجموعة.
                // Historical duplicates: the same value appears twice — the first occurrence
                // owns the list entry; a later occurrence only fills a missing PRO_GROUP.
                $dedupeKey = $promid.'|'.$value;

                if (! isset($seen[$dedupeKey])) {
                    $seen[$dedupeKey] = count($specs[$keys[$promid]]);
                    $specs[$keys[$promid]][] = [
                        'value' => $value,
                        'group' => $group === null ? null : (int) $group,
                    ];
                } elseif ($group !== null) {
                    $index = $seen[$dedupeKey];

                    if ($specs[$keys[$promid]][$index]['group'] === null) {
                        $specs[$keys[$promid]][$index]['group'] = (int) $group;
                    }
                }
            }
        } catch (\Throwable $e) {
            // القواميس تزيينية على القراءة: فشلها لا يُفشل المرجع (الواجهة تعرض قوائم فارغة)
            // The dictionaries are read-time decoration: their failure never breaks the reference.
            report($e);
        }

        return $specs;
    }

    /**
     * الاقتراحات الافتراضية من DEFAULT_REQUESTS — مفهرسة بنوع النمط
     * The smart suggestions from DEFAULT_REQUESTS — indexed by the style type.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultRequests(): array
    {
        try {
            $rows = DB::connection('oracle')->select(
                'SELECT STYLE_NO, STYLE_NAME, TYPE_NO, TYPENO, BUTTON_TYPE, BUTTON_NO,
                        COM_CABAK_TYPE, NECK_TYPE, POCKET_OR_WITHOUT, GABZOR_TYPE,
                        MOBILE_POCKET, PEN_POCKET, HEGAZI_TYPE, TIPPER_TYPE
                   FROM ACCOUNTS.DEFAULT_REQUESTS
                  ORDER BY TYPENO'
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $typeno = self::pickNum($data, 'TYPENO') ?? self::pickNum($data, 'TYPE_NO');

            if ($typeno === null) {
                continue;
            }

            $out[] = [
                'typeno' => (int) $typeno,
                'style_no' => self::pickNum($data, 'STYLE_NO') === null ? null : (int) self::pickNum($data, 'STYLE_NO'),
                'style_name' => self::fixText($data, 'STYLE_NAME'),
                'button_type' => self::fixText($data, 'BUTTON_TYPE'),
                'button_no' => self::fixText($data, 'BUTTON_NO'),
                'com_cabak_type' => self::fixText($data, 'COM_CABAK_TYPE'),
                'neck_type' => self::fixText($data, 'NECK_TYPE'),
                'pocket_or_without' => self::fixText($data, 'POCKET_OR_WITHOUT'),
                'gabzor_type' => self::fixText($data, 'GABZOR_TYPE'),
                'mobile_pocket' => self::fixText($data, 'MOBILE_POCKET'),
                'pen_pocket' => self::fixText($data, 'PEN_POCKET'),
            ];
        }

        return $out;
    }

    /**
     * المقاسات الافتراضية الـ138 — تُحمّل كاملة لتحسب الواجهة التعبئة فورياً بلا شبكة
     * The 138 default measurements — loaded whole so the UI fills instantly, network-free.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultMeasurements(): array
    {
        try {
            $rows = DB::connection('oracle')->select(
                'SELECT SHAPE_STYLE_NO, L1, L2, L3, L4, L5, L6, L7, L8, L9, L10
                   FROM ACCOUNTS.DEFAULT_MEASURMENT
                  ORDER BY SEQ'
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $entry = [];

            foreach (['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7', 'L8', 'L9', 'L10'] as $key) {
                $value = self::pickNum($data, $key);
                $entry[strtolower($key)] = $value === null ? null : (float) $value;
            }

            $shape = self::pickNum($data, 'SHAPE_STYLE_NO');
            $entry['shape_style_no'] = $shape === null ? null : (int) $shape;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * قاموس الألوان من SANFS_COLOR / The color dictionary from SANFS_COLOR.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function colors(): array
    {
        try {
            $rows = DB::connection('oracle')->select(
                'SELECT COLOR_NO, COLOR_NAME FROM ACCOUNTS.SANFS_COLOR ORDER BY COLOR_NO'
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $no = self::pickNum($data, 'COLOR_NO');

            if ($no === null) {
                continue;
            }

            $out[] = [
                'value' => (int) $no,
                'label' => self::fixText($data, 'COLOR_NAME'),
            ];
        }

        return $out;
    }

    /**
     * نص مُصلَّح الترميز بحالة غير حساسة / case-insensitive encoding-repaired text
     *
     * @param  array<string, mixed>  $data
     */
    private static function fixText(array $data, string $key): ?string
    {
        $value = self::pickText($data, $key);

        if ($value === null || $value === '') {
            return null;
        }

        return ArabicEncoding::fixMojibake($value);
    }

    /**
     * قراءة نص بحالة غير حساسة / case-insensitive text read
     *
     * @param  array<string, mixed>  $data
     */
    private static function pickText(array $data, string $key): ?string
    {
        foreach ($data as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null) {
                return (string) $v;
            }
        }

        return null;
    }

    /**
     * قراءة رقم بحالة غير حساسة / case-insensitive numeric read
     *
     * @param  array<string, mixed>  $data
     */
    private static function pickNum(array $data, string $key): float|int|null
    {
        foreach ($data as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null && is_numeric($v)) {
                $f = (float) $v;

                return floor($f) === $f ? (int) $f : $f;
            }
        }

        return null;
    }
}
