<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * حارس سطور مستندات المخازن — التحقق الموسّع قبل أي إدراج
 * Inventory line guard — the enriched validation that runs before any insert.
 *
 * ── لماذا وحدة مستقلة؟ لأن الـ VIEW `ITEM_MOVE_D` تربط السطر بالصنف بشرطين صارمين:
 *    1. `SANF_DNO` يجب أن يوجد ويطابق `SANFS_D.SANF_DNO` (توريد/صرف)،
 *    2. في التحويل تحديداً: `SIZE_FRM = SANFS_D.LEN` و`SIZE_TO = SANFS_D.WID`.
 *    إغفال أي شرط لا يعطي خطأً — يعطي مستنداً **صامتاً لا أثر له في الرصيد**، وهو أسوأ
 *    من الفشل. لذلك كل سطر يُفحص ويُثرى هنا قبل الكتابة.
 * ── Why a separate unit? The legacy view ties a line to its item with strict conditions
 *    (sub-item must exist, and a transfer must copy LEN/WID into SIZE_FRM/SIZE_TO).
 *    Missing one produces no error at all — just a document with no effect on any balance,
 *    which is worse than failing. Every line is therefore checked and enriched here first.
 */
final class InventoryLineGuard
{
    /** أقصى عدد أصناف في استعلام واحد (سقف قائمة IN) / max items per IN list */
    private const CHUNK = 500;

    /**
     * فحص السطور وإثراؤها ببيانات الصنف والصنف الفرعي والوحدة
     * Validate the lines and enrich them with item / sub-item / unit data.
     *
     * @param  array<int, array<string, mixed>>  $rawLines  السطور كما وصلت من العميل
     * @param  string  $kind  receive · issue · transfer
     * @param  int  $storeNo  المخزن (المصدر في التحويل)
     * @param  bool  $allowNegative  هل يُسمح بتجاوز المتاح (منح شامل فقط)
     * @return array{ok: bool, message: ?string, errors: array<string, mixed>, lines: array<int, array<string, mixed>>}
     */
    public static function normalize(array $rawLines, string $kind, int $storeNo, bool $allowNegative = false): array
    {
        if (count($rawLines) === 0) {
            return self::fail('يجب إضافة سطر واحد على الأقل / At least one line is required.');
        }

        // 1) التحقق الشكلي أولاً — بلا قاعدة بيانات، لأنه أرخص وأوضح للمستخدم
        //    Shape validation first: no database round-trip, cheapest and clearest.
        $shapeErrors = [];

        foreach ($rawLines as $index => $line) {
            $lineNo = $index + 1;
            $sanfNo = (int) ($line['sanf_no'] ?? 0);
            $sanfDno = (int) ($line['sanf_dno'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            $price = (float) ($line['price'] ?? 0);

            if ($sanfNo <= 0) {
                $shapeErrors["lines.{$index}.sanf_no"] = "السطر {$lineNo}: الصنف مطلوب / Line {$lineNo}: item is required.";
            }

            if ($sanfDno <= 0) {
                $shapeErrors["lines.{$index}.sanf_dno"] =
                    "السطر {$lineNo}: الصنف الفرعي (الباركود) مطلوب — بدونه لا يُحتسب السطر في الرصيد "
                    .'/ Line '.$lineNo.': a sub item (barcode) is required — without it the line never reaches the balance.';
            }

            if ($qty <= 0) {
                $shapeErrors["lines.{$index}.qty"] = "السطر {$lineNo}: الكمية يجب أن تكون أكبر من صفر / Line {$lineNo}: quantity must be greater than zero.";
            }

            if ($price < 0) {
                $shapeErrors["lines.{$index}.price"] = "السطر {$lineNo}: السعر لا يكون سالباً / Line {$lineNo}: price cannot be negative.";
            }
        }

        if ($shapeErrors !== []) {
            return [
                'ok' => false,
                'message' => 'بيانات سطور المستند غير صالحة / The document lines are invalid.',
                'errors' => $shapeErrors,
                'lines' => [],
            ];
        }

        // 2) إثراء السطور من قاعدة البيانات — استعلام واحد لكل حزمة أصناف
        //    Enrich from the database — one query per chunk of items.
        $sanfNos = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['sanf_no'],
            $rawLines
        )));

        $items = self::loadItems($sanfNos);

        if ($items === []) {
            return self::fail('لم يُعثر على أي صنف مطابق / No matching item was found.');
        }

        $lines = [];

        foreach ($rawLines as $index => $line) {
            $sanfNo = (int) $line['sanf_no'];
            $sanfDno = (int) $line['sanf_dno'];
            $key = $sanfNo.'|'.$sanfDno;

            if (! isset($items[$key])) {
                return self::fail(
                    'السطر '.($index + 1).": الصنف الفرعي [{$sanfDno}] لا يتبع الصنف [{$sanfNo}] "
                    .'/ Line '.($index + 1).": sub item [{$sanfDno}] does not belong to item [{$sanfNo}]."
                );
            }

            $item = $items[$key];
            $qty = (float) $line['qty'];
            $price = (float) $line['price'];

            $lines[] = [
                'sanf_no' => $sanfNo,
                'sanf_dno' => $sanfDno,
                'sanf_name' => $item['SANF_NAME'],
                'part_no' => (string) ($item['PART_NO'] ?? ''),
                'unit_no' => (int) ($item['UNIT_NO'] ?? 1),
                'unit_name' => (string) ($item['UNIT_NAME'] ?? ''),
                'cap' => (float) ($item['CAP'] ?? 1) === 0.0 ? 1.0 : (float) ($item['CAP'] ?? 1),
                'len' => (float) ($item['LEN'] ?? 0),
                'wid' => (float) ($item['WID'] ?? 0),
                'color_no' => (int) ($item['COLOR_NO'] ?? 0),
                'qty' => $qty,
                'price' => $price,
                'total' => round($qty * $price, 2),
                'barcode' => (string) ($item['BARACOCE'] ?? ''),
            ];
        }

        // 3) كفاية الرصيد: الصرف والتحويل يخرجان من المخزن، والتوريد يدخل إليه
        //    Stock sufficiency: issue and transfer leave the store, receive enters it.
        if ($kind !== StorePolicy::KIND_RECEIVE && ! $allowNegative) {
            $needed = [];

            foreach ($lines as $line) {
                $sanfNo = $line['sanf_no'];
                $needed[$sanfNo] = ($needed[$sanfNo] ?? 0) + $line['qty'];
            }

            $available = self::availableQuantities($storeNo, array_keys($needed));
            $shortages = [];

            foreach ($needed as $sanfNo => $qty) {
                $onHand = $available[$sanfNo] ?? 0.0;

                if (round($onHand - $qty, 3) < 0) {
                    $shortages[] = "الصنف [{$sanfNo}]: المتاح ".self::qty($onHand).' والمطلوب '.self::qty($qty);
                }
            }

            if ($shortages !== []) {
                return [
                    'ok' => false,
                    'message' => 'الكمية المطلوبة تتجاوز الرصيد المتاح / The requested quantity exceeds the available stock.',
                    'errors' => ['stock' => $shortages],
                    'lines' => [],
                ];
            }
        }

        return ['ok' => true, 'message' => null, 'errors' => [], 'lines' => $lines];
    }

    /**
     * تحميل الأصناف الفرعية المطلوبة بمفاتيح `sanf_no|sanf_dno`
     * Load the requested sub items keyed by `sanf_no|sanf_dno`.
     *
     * @param  array<int, int>  $sanfNos
     * @return array<string, array<string, mixed>>
     */
    private static function loadItems(array $sanfNos): array
    {
        $result = [];

        foreach (array_chunk($sanfNos, self::CHUNK) as $chunk) {
            $binds = [];
            $placeholders = [];

            foreach ($chunk as $i => $sanfNo) {
                $binds['s'.$i] = $sanfNo;
                $placeholders[] = ':s'.$i;
            }

            $sql = 'SELECT s.SANF_NO, s.SANF_NAME, s.PART_NO, s.UNIT_NO, s.CAP, s.SANF_PRICE,
                           s.GROUPNO, s.TYPE_NO, s.STYL_NO, s.CAT_NO, s.CUR_NO,
                           d.SANF_DNO, d.LEN, d.WID, d.COLOR_NO, d.SANF_COCE, d.BARACOCE,
                           u.UNIT_NAME
                      FROM ACCOUNTS.SANFS s
                      JOIN ACCOUNTS.SANFS_D d ON d.SANF_NO = s.SANF_NO
                      LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = s.UNIT_NO
                     WHERE s.SANF_NO IN ('.implode(',', $placeholders).')';

            foreach (DB::connection('oracle')->select($sql, $binds) as $row) {
                $row = (array) $row;
                // مفاتيح منتقي OCI8 قد تعود صغيرة الحروف — نطبّعها مرة واحدة هنا.
                // OCI8 may return lowercase keys; normalize once, here.
                $row = array_change_key_case($row, CASE_UPPER);
                $result[$row['SANF_NO'].'|'.$row['SANF_DNO']] = $row;
            }
        }

        return $result;
    }

    /**
     * الكميات المتاحة لكل صنف في مخزن واحد، من `AVVG` نفسها التي يعرضها التطبيق
     * On-hand quantity per item in one store, straight from the same AVVG the UI shows.
     *
     * @param  array<int, int>  $sanfNos
     * @return array<int, float>
     */
    public static function availableQuantities(int $storeNo, array $sanfNos): array
    {
        if ($sanfNos === []) {
            return [];
        }

        $available = [];

        foreach (array_chunk($sanfNos, self::CHUNK) as $chunk) {
            $binds = ['store' => $storeNo];
            $placeholders = [];

            foreach ($chunk as $i => $sanfNo) {
                $binds['i'.$i] = $sanfNo;
                $placeholders[] = ':i'.$i;
            }

            $sql = 'SELECT I_CODE, SUM(SUM_TP) QTY
                      FROM ACCOUNTS.AVVG
                     WHERE INV_ID = :store
                       AND I_CODE IN ('.implode(',', $placeholders).')
                     GROUP BY I_CODE';

            foreach (DB::connection('oracle')->select($sql, $binds) as $row) {
                $row = array_change_key_case((array) $row, CASE_UPPER);
                $available[(int) $row['I_CODE']] = (float) ($row['QTY'] ?? 0);
            }
        }

        return $available;
    }

    /**
     * رسالة فشل موحّدة
     * Uniform failure payload.
     *
     * @return array{ok: bool, message: ?string, errors: array<string, mixed>, lines: array<int, array<string, mixed>>}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'errors' => [], 'lines' => []];
    }

    /**
     * تنسيق كمية للرسائل بلا أصفار زائدة
     * Format a quantity for messages without trailing zeros.
     */
    private static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
