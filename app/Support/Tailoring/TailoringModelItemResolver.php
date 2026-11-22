<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * محلل أصناف الموديل — يحلّ صنف النمط في SANFS وصنف المقاس في SANFS_D، ويُنشئ المقاس
 * إن لم يوجد (كما يفعل النموذج القديم — القرار المعتمد من المستخدم).
 * The model-item resolver — resolves the style item in SANFS and the size item in SANFS_D,
 * creating the size item when missing (as the legacy form does — the approved decision).
 *
 * ── «صنف الموديل» ليس القماش: SHAPES.SANF_NO/SANF_DNO يحملان صنف النمط وصنف المقاس
 *    (مثال مقيس: 1927 / 3010101883)، وأقمشة القطع في MULTI_CLOTHS فقط.
 * ── The model item is NOT the fabric: SHAPES.SANF_NO/SANF_DNO carry the style item and the
 *    size item (measured: 1927 / 3010101883); piece fabrics live in MULTI_CLOTHS only.
 *
 * ── قالب إنشاء صنف المقاس مقيس من صفوف SANFS_D الحيّة:
 *    SANF_DNO = STYL_NO || '01' || عدّاد(3) · SANF_DNAME = الاسم + (L1-L5) ·
 *    SANF_COCE = PART_NO · BARACOCE = SANF_DNO · PROD_ID = SANF_NO ·
 *    GROUPNO من SANFS · CAP=1 · UNIT_NO=1 · CUR_NO=1 · ACTIVE=1.
 * ── The size-item creation template measured from live SANFS_D rows (fields as above).
 *
 * ── القيد الفريد (SANF_NO, LEN, WID, COLOR_NO) يمنع النسخة الثانية، فالبحث أولاً ثم
 *    الإنشاء مع إعادة فحص عند ORA-00001 (سماحية عشرية للتقريب 6,2).
 * ── The unique key (SANF_NO, LEN, WID, COLOR_NO) forbids a second copy, so look first,
 *    then create with an ORA-00001 re-check (tolerance for the NUMBER(6,2) rounding).
 */
final class TailoringModelItemResolver
{
    /** سماحية مطابقة المقاس بعد تقريب العمود NUMBER(5,2) / match tolerance for NUMBER(5,2) */
    private const SIZE_TOLERANCE = 0.01;

    /**
     * حل صنف النمط من SANFS بـ (TYPE_NO, STYL_NO) — مثال مقيس: 301/30101 ⇒ 1927
     * Resolve the style item by (TYPE_NO, STYL_NO) — measured example: 301/30101 ⇒ 1927.
     *
     * @return array{sanf_no: int, sanf_name: string, part_no: ?string, styl_no: int, type_no: int, group_no: ?string}|null
     */
    public static function resolveStyleItem(int $typeNo, int $stylNo): ?array
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT SANF_NO, SANF_NAME, PART_NO, STYL_NO, TYPE_NO, GROUPNO
               FROM ACCOUNTS.SANFS
              WHERE TYPE_NO = :type_no AND STYL_NO = :styl_no',
            ['type_no' => $typeNo, 'styl_no' => $stylNo]
        );

        if ($row === [] || reset($row) === null) {
            return null;
        }

        $sanfNo = self::pickNum($row, 'SANF_NO');

        if ($sanfNo === null || (int) $sanfNo <= 0) {
            return null;
        }

        return [
            'sanf_no' => (int) $sanfNo,
            'sanf_name' => (string) (ArabicEncoding::fixMojibake(
                self::pickText($row, 'SANF_NAME')
            ) ?? ''),
            'part_no' => self::pickText($row, 'PART_NO'),
            'styl_no' => (int) (self::pickNum($row, 'STYL_NO') ?? $stylNo),
            'type_no' => (int) (self::pickNum($row, 'TYPE_NO') ?? $typeNo),
            'group_no' => self::pickText($row, 'GROUPNO'),
        ];
    }

    /**
     * حل صنف المقاس بـ (SANF_NO, L1, L5, COLOR_NO=0) أو إنشاؤه إن لم يوجد
     * Resolve the size item by (SANF_NO, L1, L5, COLOR_NO=0) or create it when missing.
     *
     * @param  array{sanf_no: int, sanf_name: string, part_no: ?string, styl_no: int, type_no: int, group_no: ?string}  $styleItem
     * @param  float  $l1  الطول — LEN في SANFS_D / the length — LEN on SANFS_D
     * @param  float  $l5  العرض — WID في SANFS_D / the width — WID on SANFS_D
     * @return array{sanf_dno: int, created: bool}
     */
    public static function resolveSizeItem(array $styleItem, float $l1, float $l5): array
    {
        $existing = self::findSizeItem((int) $styleItem['sanf_no'], $l1, $l5);

        if ($existing !== null) {
            return ['sanf_dno' => $existing, 'created' => false];
        }

        $sanfDno = TailoringNumbering::nextSanfDno((int) $styleItem['sanf_no'], (int) $styleItem['styl_no']);
        $name = $styleItem['sanf_name'].' ('.self::num($l1).'-'.self::num($l5).')';

        try {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.SANFS_D
                     (SANF_DNO, SANF_NO, TYPE_NO, PART_NO, SANF_COCE, BARACOCE, SANF_NAME, SANF_DNAME,
                      LEN, WID, COLOR_NO, PROD_ID, GROUPNO, STYL_NO, CAP, UNIT_NO, CUR_NO, ACTIVE)
                 VALUES (:sanf_dno, :sanf_no, :type_no, :part_no, :sanf_coce, :baracoce, :sanf_name, :sanf_dname,
                         :len, :wid, 0, :prod_id, :group_no, :styl_no, 1, 1, 1, 1)',
                [
                    'sanf_dno' => $sanfDno,
                    'sanf_no' => (int) $styleItem['sanf_no'],
                    'type_no' => (int) $styleItem['type_no'],
                    'part_no' => $styleItem['part_no'],
                    'sanf_coce' => $styleItem['part_no'],
                    'baracoce' => (string) $sanfDno,
                    'sanf_name' => (string) ArabicEncoding::toOracleSearchChars((string) $styleItem['sanf_name']),
                    'sanf_dname' => (string) ArabicEncoding::toOracleSearchChars($name),
                    'len' => round($l1, 2),
                    'wid' => round($l5, 2),
                    'prod_id' => (int) $styleItem['sanf_no'],
                    'group_no' => $styleItem['group_no'],
                    'styl_no' => (int) $styleItem['styl_no'],
                ]
            );

            return ['sanf_dno' => $sanfDno, 'created' => true];
        } catch (\Throwable $e) {
            // ORA-00001: جلسة أخرى أنشأت نفس المقاس أثناء الحفظ — تُعاد القراءة وتُقبل صفّها.
            // ORA-00001: another session created the same size mid-flight — re-read and adopt it.
            if (self::isUniqueViolation($e)) {
                $existing = self::findSizeItem((int) $styleItem['sanf_no'], $l1, $l5);

                if ($existing !== null) {
                    return ['sanf_dno' => $existing, 'created' => false];
                }
            }

            throw $e;
        }
    }

    /**
     * البحث عن صنف المقاس بنفس المفتاح الفريد (SANF_NO, LEN, WID, 0)
     * Find the size item by the same unique key (SANF_NO, LEN, WID, 0).
     */
    public static function findSizeItem(int $sanfNo, float $l1, float $l5): ?int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT SANF_DNO
               FROM ACCOUNTS.SANFS_D
              WHERE SANF_NO = :sanf
                AND COLOR_NO = 0
                AND ABS(LEN - :len) <= :tol
                AND ABS(WID - :wid) <= :tol
              ORDER BY SANF_DNO',
            [
                'sanf' => $sanfNo,
                'len' => round($l1, 2),
                'wid' => round($l5, 2),
                'tol' => self::SIZE_TOLERANCE,
            ]
        );

        if ($row === []) {
            return null;
        }

        $dno = self::pickNum($row, 'SANF_DNO');

        return $dno === null ? null : (int) $dno;
    }

    /**
     * هل الخطأ تعارض مفتاح فريد؟ / Is this a unique-key violation?
     */
    private static function isUniqueViolation(\Throwable $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $code === 1 || str_contains($e->getMessage(), 'ORA-00001');
    }

    /**
     * قراءة نص بحالة غير حساسة / case-insensitive text read
     *
     * @param  array<string, mixed>  $row
     */
    private static function pickText(array $row, string $key): ?string
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null) {
                return (string) $v;
            }
        }

        return null;
    }

    /**
     * قراءة رقم بحالة غير حساسة / case-insensitive numeric read
     *
     * @param  array<string, mixed>  $row
     */
    private static function pickNum(array $row, string $key): float|int|null
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null && is_numeric($v)) {
                $f = (float) $v;

                return floor($f) === $f ? (int) $f : $f;
            }
        }

        return null;
    }

    /**
     * صياغة رقم بلا أصفار زائدة / format a number without trailing zeros
     */
    private static function num(float $value): string
    {
        $rounded = round($value, 2);
        $formatted = number_format($rounded, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
