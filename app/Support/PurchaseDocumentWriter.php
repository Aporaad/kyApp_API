<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * كاتب مستندات المشتريات — التحقق من السطور وإثراؤها وإدراج الرؤوس والسطور
 * Purchase document writer — line validation, enrichment, and header/line persistence.
 *
 * ── قرارات مثبتة من المخطط الحيّ:
 *    D1: `SANF_DNO` **إلزامي** هنا (NOT NULL + مفتاح أجنبي على SANFS_D)، خلافاً للمبيعات التي
 *        تتركه فارغاً. لذلك يمر كل سطر عبر `InventoryLineGuard` الذي يتحقق من التبعية.
 *    D2: `SANF_NUMS` إلزامي وقيمته **1** في كل صفوف النظام القديم الـ663 رغم تعدد السطور ⇒
 *        تُكتب 1 حرفياً ولا تُكتب عدد السطور.
 *    D3: `STK_COST = SANF_PRICE × CUR_PRICE1` و`STK_TOT = TOTAL × CUR_PRICE1` بالريال. إغفالها
 *        يقوّم المخزون بالعملة الأجنبية (الواجهة تقرأ `NVL(STK_COST, SANF_PRICE)`).
 *    D4: `SEQ` لا يُعاد استبداله في التعديل: مردودات المشتريات تربط سطرها بسطر الفاتورة عبر
 *        `SEQ_SALES`، فإعادة الإدراج تقطع الرابط.
 * ── Pinned decisions: sub-item is mandatory (unlike sales), SANF_NUMS is literally 1,
 *    the riyal cost columns are always written, and invoice line SEQ is preserved on update so
 *    return lines keep pointing at their invoice line.
 */
final class PurchaseDocumentWriter
{
    private const CHUNK = 400;

    /**
     * التحقق من السطور وإثراؤها ببيانات الصنف والصنف الفرعي
     * Validate the lines and enrich them with item / sub-item data.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @param  string  $kind  receive (فاتورة) أو issue (مردود)
     * @param  bool  $allowNegative  تجاوز المتاح لمن يملك الصلاحية الشاملة
     * @return array{ok: bool, message: ?string, errors: array<string, mixed>, lines: array<int, array<string, mixed>>}
     */
    public static function normalizeLines(array $rawLines, string $kind, int $storeNo, bool $allowNegative = false): array
    {
        if (count($rawLines) > PurchasePolicy::MAX_LINES) {
            return self::fail('عدد سطور المستند أكبر من الحد المسموح / Too many lines in one document.');
        }

        // الحارس المشترك يتحقق من الشكل والتبعية والرصيد المتاح ويكمل بيانات الصنف
        // The shared guard validates shape, sub-item ownership and availability, and enriches.
        $guarded = InventoryLineGuard::normalize($rawLines, $kind, $storeNo, $allowNegative);

        if (! ($guarded['ok'] ?? false)) {
            return $guarded;
        }

        $lines = [];

        foreach ($guarded['lines'] as $index => $line) {
            $raw = $rawLines[$index] ?? [];

            $qty = (float) $line['qty'];
            $price = round((float) $line['price'], 2);
            $cap = (float) $line['cap'] > 0 ? (float) $line['cap'] : 1.0;
            $total = round($qty * $price, 2);
            $rate = round((float) ($raw['discount_rate'] ?? 0), 2);

            if ($rate < 0 || $rate > 100) {
                return self::fail('السطر '.($index + 1).': نسبة الخصم بين 0 و100 / Discount rate must be 0..100.');
            }

            $lines[] = [
                'seq' => (int) ($raw['seq'] ?? 0) > 0 ? (int) $raw['seq'] : null,
                'sanf_no' => (int) $line['sanf_no'],
                'sanf_dno' => (int) $line['sanf_dno'],
                'sanf_name' => (string) ($line['sanf_name'] ?? ''),
                'sub_name' => (string) ($line['sub_name'] ?? ''),
                'qty' => $qty,
                'price' => $price,
                'cap' => $cap,
                'pcs' => round($qty * $cap, 3),
                'total' => $total,
                'discount_rate' => $rate,
                'discount' => round($total * $rate / 100, 2),
                'size_frm' => (int) ($raw['size_frm'] ?? 0),
                'size_to' => (int) ($raw['size_to'] ?? 0),
                'color_no' => (int) ($line['color_no'] ?? 0),
                'category' => (string) ($line['category'] ?? ''),
                'cat_no' => (int) ($line['cat_no'] ?? 0),
                'style_no' => (int) ($line['style_no'] ?? 0),
                'seq_order' => $index + 1,
                'seq_sales' => (int) ($raw['seq_sales'] ?? 0) > 0 ? (int) $raw['seq_sales'] : null,
            ];
        }

        return ['ok' => true, 'message' => null, 'errors' => [], 'lines' => $lines];
    }

    /**
     * مجاميع المستند من السطور + خصم رأسي إضافي (لا يُقبل رصيد سالب)
     * Document totals from the lines plus an extra header discount (never a negative net).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{total: float, discount: float, net: float, count: int, rate: float}
     */
    public static function totals(array $lines, float $extraDiscount = 0.0): array
    {
        $total = 0.0;
        $lineDiscount = 0.0;

        foreach ($lines as $line) {
            $total += (float) $line['total'];
            $lineDiscount += (float) $line['discount'];
        }

        $total = round($total, 2);
        $discount = round($lineDiscount + max(0.0, $extraDiscount), 2);

        if ($discount > $total) {
            $discount = $total;
        }

        $net = round($total - $discount, 2);
        $rate = $total > 0 ? round($discount / $total * 100, 2) : 0.0;

        return [
            'total' => $total,
            'discount' => $discount,
            'net' => $net,
            'count' => count($lines),
            'rate' => $rate,
        ];
    }

    /**
     * إدراج رأس فاتورة مشتريات
     * Insert the purchase invoice header.
     *
     * @param  array<string, mixed>  $h
     */
    public static function insertInvoice(array $h): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.BUY_BILL
                 (BILL_NO, YEAR, BILL_TYPE, DAT, PROV_NO, TOTAL, CUR_NO, CUR_PRICE, PROV_BILL,
                  SANF_NUMS, DETAILES, STORE_NO, ENTRY_NO, PROV_NAME, DIS_RATE, DISCOUNT, NET,
                  CUR_NO1, CUR_PRICE1, SEQ_PRICE1, SELECT1, ACC_NO, ACC_NO1, MANUAL_BILL_NO,
                  CREATED_BY_NAME, LAST_UPDATED_BY_NAME, BRANCH_NO, CREATED_ON, CREATED_BY,
                  THE_TIME, TR_TYPE_ID, SEQBIL)
             VALUES
                 (:bill_no, :year, :bill_type, TO_DATE(:dat, \'YYYY-MM-DD\'), :prov_no, :total,
                  :cur_no, :cur_price, :prov_bill, :sanf_nums, :details, :store_no, :entry_no,
                  :prov_name, :dis_rate, :discount, :net, :cur_no1, :cur_price1, :seq_price1,
                  :select1, :acc_no, :acc_no1, :manual_no, :created_by_name, :updated_by_name,
                  :branch_no, SYSDATE, :created_by, :the_time, :tr_type_id, :seq_bil)',
            self::bindings([
                'bill_no' => $h['bill_no'],
                'year' => $h['year'],
                'bill_type' => $h['bill_type'],
                'dat' => $h['date'],
                'prov_no' => $h['prov_no'],
                'total' => $h['total'],
                'cur_no' => $h['cur_no'],
                'cur_price' => $h['cur_price'],
                'prov_bill' => self::clip($h['prov_bill'] ?? null, PurchasePolicy::PROV_BILL_LEN),
                'sanf_nums' => 1,
                'details' => self::clip($h['details'] ?? null, PurchasePolicy::DETAILES_LEN),
                'store_no' => $h['store_no'],
                'entry_no' => $h['entry_no'],
                'prov_name' => self::clip($h['prov_name'] ?? null, PurchasePolicy::PROV_NAME_LEN),
                'dis_rate' => $h['dis_rate'],
                'discount' => $h['discount'],
                'net' => $h['net'],
                'cur_no1' => $h['cur_no1'],
                'cur_price1' => $h['cur_price1'],
                'seq_price1' => $h['seq_price1'],
                'select1' => PurchasePolicy::SELECT1,
                'acc_no' => $h['acc_no'],
                'acc_no1' => $h['acc_no1'],
                'manual_no' => $h['manual_no'],
                'created_by_name' => self::clip($h['created_by_name'] ?? null, PurchasePolicy::CREATED_BY_NAME_LEN),
                'updated_by_name' => self::clip($h['updated_by_name'] ?? null, PurchasePolicy::CREATED_BY_NAME_LEN),
                'branch_no' => $h['branch_no'],
                'created_by' => $h['created_by'],
                'the_time' => self::clip($h['the_time'] ?? null, PurchasePolicy::THE_TIME_LEN),
                'tr_type_id' => PurchasePolicy::TR_TYPE_ID,
                'seq_bil' => $h['seq_bil'],
            ])
        );
    }

    /**
     * إدراج سطور فاتورة مشتريات — `SEQ` عدّاد سنوي، والمفتاح (رقم، سنة، تسلسل)
     * Insert the invoice lines — SEQ counts within a year and the key is (no, year, seq).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function insertInvoiceLines(int $billNo, int $year, array $lines, float $rate): void
    {
        $seq = PurchasePolicy::nextDetailSeq($year);

        foreach ($lines as $line) {
            self::insertInvoiceLine($billNo, $year, $seq, $line, $rate);
            $seq++;
        }
    }

    /**
     * تحديث سطور فاتورة مع الحفاظ على `SEQ` القائم — إضافة وتعديل وحذف لكل سطر
     * Update invoice lines while preserving existing SEQ — add, edit and delete per line.
     *
     * ── الحذف في النهاية لا في البداية: لو حُذفت السطور أولاً لانقطع `SEQ_SALES` في المردودات
     *    المرتبطة، وهو الرابط الوحيد بين سطر المردود وسطر الفاتورة.
     * ── Deletion happens last: deleting first would sever SEQ_SALES, the only link a return line
     *    has to its invoice line.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function syncInvoiceLines(int $billNo, int $year, array $lines, float $rate): void
    {
        $existing = [];

        foreach (DB::connection('oracle')->select(
            'SELECT SEQ FROM ACCOUNTS.BUY_DETAILES WHERE BILL_NO = :bill_no AND YEAR = :year',
            ['bill_no' => $billNo, 'year' => $year]
        ) as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            $existing[(int) $row['SEQ']] = true;
        }

        $nextSeq = PurchasePolicy::nextDetailSeq($year);
        $kept = [];

        foreach ($lines as $line) {
            $seq = (int) ($line['seq'] ?? 0);

            if ($seq > 0 && isset($existing[$seq])) {
                self::updateInvoiceLine($billNo, $year, $seq, $line, $rate);
                $kept[$seq] = true;

                continue;
            }

            // تسلسل جديد قد يتصادم مع سطر محفوظ لم يُمرَّر بعد؛ نرفع العدّاد حتى يخلو
            // A fresh sequence can collide with a stored line not yet seen; advance until free.
            while (isset($existing[$nextSeq]) || isset($kept[$nextSeq])) {
                $nextSeq++;
            }

            self::insertInvoiceLine($billNo, $year, $nextSeq, $line, $rate);
            $kept[$nextSeq] = true;
            $nextSeq++;
        }

        foreach (array_keys($existing) as $seq) {
            if (! isset($kept[$seq])) {
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.BUY_DETAILES WHERE BILL_NO = :bill_no AND YEAR = :year AND SEQ = :seq',
                    ['bill_no' => $billNo, 'year' => $year, 'seq' => $seq]
                );
            }
        }
    }

    /**
     * إدراج رأس مردود مشتريات
     * Insert the purchase return header.
     *
     * @param  array<string, mixed>  $h
     */
    public static function insertReturn(array $h): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.RETURN_BUY
                 (RETURN_NO, YEAR, RET_DATE, RETURN_TOTAL, BILL_NO, DETAILES, BILL_YEAR, STORE_NO,
                  PROV_NO, BILL_TYPE, ENTRY_NO, DIS_RATE, DISCOUNT, NET, PROV_NAME, ACC_NO,
                  CUR_NO, CUR_PRICE, SEQ_PRICE, ACC_NO1, CUR_NO1, CUR_PRICE1, SELECT1,
                  MANUAL_RETURN_NO, BRANCH_NO, CREATED_ON, CREATED_BY, CREATED_BY_NAME,
                  LAST_UPDATED_BY, LAST_UPDATED_BY_NAME, THE_TIME)
             VALUES
                 (:return_no, :year, TO_DATE(:ret_date, \'YYYY-MM-DD\'), :return_total, :bill_no,
                  :details, :bill_year, :store_no, :prov_no, :bill_type, :entry_no, :dis_rate,
                  :discount, :net, :prov_name, :acc_no, :cur_no, :cur_price, :seq_price, :acc_no1,
                  :cur_no1, :cur_price1, :select1, :manual_no, :branch_no, SYSDATE, :created_by,
                  :created_by_name, :updated_by, :updated_by_name, :the_time)',
            self::bindings([
                'return_no' => $h['return_no'],
                'year' => $h['year'],
                'ret_date' => $h['date'],
                'return_total' => $h['total'],
                'bill_no' => $h['bill_no'],
                'details' => self::clip($h['details'] ?? null, PurchasePolicy::DETAILES_LEN),
                'bill_year' => $h['bill_year'],
                'store_no' => $h['store_no'],
                'prov_no' => $h['prov_no'],
                'bill_type' => $h['bill_type'],
                'entry_no' => $h['entry_no'],
                'dis_rate' => $h['dis_rate'],
                'discount' => $h['discount'],
                'net' => $h['net'],
                'prov_name' => self::clip($h['prov_name'] ?? null, PurchasePolicy::PROV_NAME_LEN),
                'acc_no' => $h['acc_no'],
                'cur_no' => $h['cur_no'],
                'cur_price' => $h['cur_price'],
                'seq_price' => $h['seq_price'],
                'acc_no1' => $h['acc_no1'],
                'cur_no1' => $h['cur_no1'],
                'cur_price1' => $h['cur_price1'],
                'select1' => PurchasePolicy::SELECT1,
                'manual_no' => $h['manual_no'],
                'branch_no' => $h['branch_no'],
                'created_by' => $h['created_by'],
                'created_by_name' => self::clip($h['created_by_name'] ?? null, PurchasePolicy::RETURN_BY_NAME_LEN),
                'updated_by' => $h['updated_by'],
                'updated_by_name' => self::clip($h['updated_by_name'] ?? null, PurchasePolicy::RETURN_BY_NAME_LEN),
                'the_time' => self::clip($h['the_time'] ?? null, PurchasePolicy::THE_TIME_LEN),
            ])
        );
    }

    /**
     * إدراج سطور مردود مشتريات
     * Insert the purchase return lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function insertReturnLines(int $returnNo, int $year, array $lines, float $rate): void
    {
        $seq = PurchasePolicy::nextReturnDetailSeq($year);

        foreach ($lines as $line) {
            self::insertReturnLine($returnNo, $year, $seq, $line, $rate);
            $seq++;
        }
    }

    /**
     * إدراج سطر مردود واحد
     * Insert a single return line.
     *
     * @param  array<string, mixed>  $line
     */
    public static function insertReturnLine(int $returnNo, int $year, int $seq, array $line, float $rate): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.RETURN_BUY_DETAILES
                 (RETURN_NO, YEAR, SANF_NO, RET_QTY, CAP, PCS, SANF_PRICE, TOTAL, SEQ,
                  DISCOUNT_RATE, DISCOUNT1, SANF_DNO, CAT_NO, STYLE_NO, COLOR_NO,
                  SANF_COST, STK_COST, SIZE_FRM, SIZE_TO, SEQ_SALES, SEQ_NO, CATEGORY)
             VALUES
                 (:return_no, :year, :sanf_no, :qty, :cap, :pcs, :price, :total, :seq,
                  :rate, :discount, :sanf_dno, :cat_no, :style_no, :color_no,
                  :cost, :cost2, :size_frm, :size_to, :seq_sales, :order_no, :category)',
            self::bindings([
                'return_no' => $returnNo,
                'year' => $year,
                'sanf_no' => $line['sanf_no'],
                'qty' => $line['qty'],
                'cap' => $line['cap'],
                'pcs' => $line['pcs'],
                'price' => $line['price'],
                'total' => $line['total'],
                'seq' => $seq,
                'rate' => $line['discount_rate'],
                'discount' => $line['discount'],
                'sanf_dno' => $line['sanf_dno'],
                'cat_no' => $line['cat_no'],
                'style_no' => $line['style_no'],
                'color_no' => $line['color_no'],
                'cost' => round((float) $line['price'] * $rate, 2),
                'cost2' => round((float) $line['price'] * $rate, 2),
                'size_frm' => $line['size_frm'],
                'size_to' => $line['size_to'],
                'seq_sales' => $line['seq_sales'],
                'order_no' => $line['seq_order'],
                'category' => self::clip($line['category'] ?? null, 20),
            ])
        );
    }

    /**
     * حذف سطور مستند
     * Delete a document's lines.
     */
    public static function deleteLines(string $table, string $keyColumn, int $docNo, int $year): void
    {
        DB::connection('oracle')->delete(
            'DELETE FROM ACCOUNTS.'.$table.' WHERE '.$keyColumn.' = :doc_no AND YEAR = :year',
            ['doc_no' => $docNo, 'year' => $year]
        );
    }

    /**
     * تطبيع نصوص الحمولة: العربية إلى صيغة الكتابة، والنصوص إلى سقف أعمدتها
     * Normalize payload text: Arabic to the Oracle write form, strings to their column capacity.
     *
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    public static function bindings(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if (is_string($value) && $value !== '') {
                $bindings[$key] = (string) ArabicEncoding::toOracleSearchChars($value);
            }
        }

        return $bindings;
    }

    /**
     * قصّ نص عربي إلى سعة عموده (المحارف لا البايتات)
     * Clip an Arabic string to its column capacity (characters, not bytes).
     */
    public static function clip(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }

    /**
     * فشل موحّد
     * Uniform failure payload.
     *
     * @return array{ok: bool, message: ?string, errors: array<string, mixed>, lines: array<int, array<string, mixed>>}
     */
    public static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'errors' => [], 'lines' => []];
    }

    /**
     * إدراج سطر فاتورة واحد مع أعمدة التكلفة بالريال
     * Insert one invoice line with the riyal cost columns.
     *
     * @param  array<string, mixed>  $line
     */
    private static function insertInvoiceLine(int $billNo, int $year, int $seq, array $line, float $rate): void
    {
        $cost = round((float) $line['price'] * $rate, 2);

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.BUY_DETAILES
                 (BILL_NO, YEAR, SANF_NO, SANF_PRICE, QTY, CAP, PCS, TOTAL, SEQ, DISCOUNT_RATE,
                  DISCOUNT1, COLOR_NO, SANF_DNO, CAT_NO, STYLE_NO, SIZE_FRM, SIZE_TO, CATEGORY,
                  STK_COST, SANF_COST, STK_TOT, CURNO, CURPRICE, SEQ_NO)
             VALUES
                 (:bill_no, :year, :sanf_no, :price, :qty, :cap, :pcs, :total, :seq, :rate,
                  :discount, :color_no, :sanf_dno, :cat_no, :style_no, :size_frm, :size_to,
                  :category, :cost, :cost2, :stk_tot, 1, 1, :order_no)',
            self::bindings([
                'bill_no' => $billNo,
                'year' => $year,
                'sanf_no' => $line['sanf_no'],
                'price' => $line['price'],
                'qty' => $line['qty'],
                'cap' => $line['cap'],
                'pcs' => $line['pcs'],
                'total' => $line['total'],
                'seq' => $seq,
                'rate' => $line['discount_rate'],
                'discount' => $line['discount'],
                'color_no' => $line['color_no'],
                'sanf_dno' => $line['sanf_dno'],
                'cat_no' => $line['cat_no'],
                'style_no' => $line['style_no'],
                'size_frm' => $line['size_frm'],
                'size_to' => $line['size_to'],
                'category' => self::clip($line['category'] ?? null, 20),
                'cost' => $cost,
                'cost2' => $cost,
                'stk_tot' => round((float) $line['total'] * $rate, 2),
                'order_no' => $line['seq_order'],
            ])
        );
    }

    /**
     * تحديث سطر فاتورة قائم بالـ `SEQ` نفسه
     * Update an existing invoice line under the same SEQ.
     *
     * @param  array<string, mixed>  $line
     */
    private static function updateInvoiceLine(int $billNo, int $year, int $seq, array $line, float $rate): void
    {
        $cost = round((float) $line['price'] * $rate, 2);

        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.BUY_DETAILES
                SET SANF_NO = :sanf_no, SANF_PRICE = :price, QTY = :qty, CAP = :cap, PCS = :pcs,
                    TOTAL = :total, DISCOUNT_RATE = :rate, DISCOUNT1 = :discount,
                    COLOR_NO = :color_no, SANF_DNO = :sanf_dno, CAT_NO = :cat_no,
                    STYLE_NO = :style_no, SIZE_FRM = :size_frm, SIZE_TO = :size_to,
                    CATEGORY = :category, STK_COST = :cost, SANF_COST = :cost2,
                    STK_TOT = :stk_tot, CURNO = 1, CURPRICE = 1, SEQ_NO = :order_no
              WHERE BILL_NO = :bill_no AND YEAR = :year AND SEQ = :seq',
            self::bindings([
                'sanf_no' => $line['sanf_no'],
                'price' => $line['price'],
                'qty' => $line['qty'],
                'cap' => $line['cap'],
                'pcs' => $line['pcs'],
                'total' => $line['total'],
                'rate' => $line['discount_rate'],
                'discount' => $line['discount'],
                'color_no' => $line['color_no'],
                'sanf_dno' => $line['sanf_dno'],
                'cat_no' => $line['cat_no'],
                'style_no' => $line['style_no'],
                'size_frm' => $line['size_frm'],
                'size_to' => $line['size_to'],
                'category' => self::clip($line['category'] ?? null, 20),
                'cost' => $cost,
                'cost2' => $cost,
                'stk_tot' => round((float) $line['total'] * $rate, 2),
                'order_no' => $line['seq_order'],
                'bill_no' => $billNo,
                'year' => $year,
                'seq' => $seq,
            ])
        );
    }
}
