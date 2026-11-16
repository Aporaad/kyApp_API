<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * كاتب مستندات المبيعات — التحقق من السطور وإثراؤها وإدراج الرأس والسطور
 * Sales document writer — line validation, enrichment, and header/line inserts.
 *
 * ── قرارات مثبتة هنا:
 *    D1: المجاميع تُحسب من السطور على الخادم؛ لا يُقبل أي مبلغ محسوب من العميل.
 *    D2: `SANF_DNO` **ليس** إلزامياً في سطور المبيعات (النظام القديم يتركه فارغاً في كثير من
 *        الفواتير)، خلافاً لمستندات المخازن التي لا يُحتسب سطرها بلا صنف فرعي.
 *    D3: التكلفة تُنسخ من بطاقة الصنف (`SANFS.BUY_PRICE`) كما يفعل النظام المكتبي، وتُستخدم
 *        لحساب عكس التكلفة في قيد المردود.
 * ── Pinned decisions: totals are computed from the lines server-side and never accepted from the
 *    client; SANF_DNO is optional on sales lines (unlike store documents); cost is copied from the
 *    item card and used to reverse cost in a return entry.
 */
final class SalesDocumentWriter
{
    private const CHUNK = 400;

    /**
     * التحقق من سطور المستند وإثراؤها ببيانات الصنف
     * Validate the document lines and enrich them with the item master data.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array{ok: bool, message: ?string, errors: array<string, mixed>, lines: array<int, array<string, mixed>>}
     */
    public static function normalizeLines(array $rawLines): array
    {
        if (count($rawLines) === 0) {
            return self::fail('يجب إضافة سطر واحد على الأقل / At least one line is required.');
        }

        if (count($rawLines) > SalesPolicy::MAX_LINES) {
            return self::fail('عدد سطور المستند أكبر من الحد المسموح / Too many lines in one document.');
        }

        $errors = [];

        foreach ($rawLines as $index => $line) {
            $lineNo = $index + 1;

            if ((int) ($line['sanf_no'] ?? 0) <= 0) {
                $errors["lines.{$index}.sanf_no"] = "السطر {$lineNo}: الصنف مطلوب / Line {$lineNo}: item is required.";
            }

            if ((float) ($line['qty'] ?? 0) <= 0) {
                $errors["lines.{$index}.qty"] = "السطر {$lineNo}: الكمية يجب أن تكون أكبر من صفر / Line {$lineNo}: quantity must be greater than zero.";
            }

            if ((float) ($line['price'] ?? 0) < 0) {
                $errors["lines.{$index}.price"] = "السطر {$lineNo}: السعر لا يكون سالباً / Line {$lineNo}: price cannot be negative.";
            }

            $rate = (float) ($line['discount_rate'] ?? 0);

            if ($rate < 0 || $rate > 100) {
                $errors["lines.{$index}.discount_rate"] = "السطر {$lineNo}: نسبة الخصم بين 0 و100 / Line {$lineNo}: discount rate must be 0..100.";
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'بيانات سطور المستند غير صالحة / The document lines are invalid.', 'errors' => $errors, 'lines' => []];
        }

        $items = self::loadItems(array_map(static fn (array $l): int => (int) $l['sanf_no'], $rawLines));

        $lines = [];

        foreach ($rawLines as $index => $line) {
            $sanfNo = (int) $line['sanf_no'];

            if (! isset($items[$sanfNo])) {
                return self::fail('السطر '.($index + 1).": الصنف [{$sanfNo}] غير موجود / Line ".($index + 1).": item [{$sanfNo}] not found.");
            }

            $item = $items[$sanfNo];
            $qty = (float) $line['qty'];
            $price = round((float) $line['price'], 2);
            $rate = round((float) ($line['discount_rate'] ?? 0), 2);
            $total = round($qty * $price, 2);
            $discount = round($total * $rate / 100, 2);
            $cap = (float) ($line['cap'] ?? 0) > 0 ? (float) $line['cap'] : ((float) ($item['CAP'] ?? 1) ?: 1.0);

            $lines[] = [
                'sanf_no' => $sanfNo,
                'sanf_dno' => (int) ($line['sanf_dno'] ?? 0) > 0 ? (int) $line['sanf_dno'] : null,
                'sanf_name' => (string) ($item['SANF_NAME'] ?? ''),
                'qty' => $qty,
                'price' => $price,
                'cap' => $cap,
                'pcs' => round($qty * $cap, 3),
                'total' => $total,
                'discount_rate' => $rate,
                'discount' => $discount,
                'size_frm' => (int) ($line['size_frm'] ?? 0),
                'size_to' => (int) ($line['size_to'] ?? 0),
                'color_no' => (int) ($line['color_no'] ?? 0),
                'category' => (string) ($line['category'] ?? $item['CATEGORY'] ?? ''),
                'cat_no' => (int) ($item['CAT_NO'] ?? 0),
                'style_no' => (int) ($item['STYL_NO'] ?? 0),
                'cost' => round((float) ($line['cost'] ?? $item['BUY_PRICE'] ?? 0), 2),
                'remarks' => (string) ($line['remarks'] ?? ''),
                'seq_sales' => (int) ($line['seq_sales'] ?? 0) > 0 ? (int) $line['seq_sales'] : null,
            ];
        }

        return ['ok' => true, 'message' => null, 'errors' => [], 'lines' => $lines];
    }

    /**
     * مجاميع المستند من السطور + خصم رأسي إضافي (لا يُقبل رصيد سالب)
     * Document totals from the lines plus an extra header discount (never a negative net).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{total: float, discount: float, net: float, count: int, cost: float}
     */
    public static function totals(array $lines, float $extraDiscount = 0.0): array
    {
        $total = 0.0;
        $lineDiscount = 0.0;
        $cost = 0.0;

        foreach ($lines as $line) {
            $total += (float) $line['total'];
            $lineDiscount += (float) $line['discount'];
            $cost += (float) $line['cost'] * (float) $line['qty'];
        }

        $total = round($total, 2);
        $discount = round($lineDiscount + max(0.0, $extraDiscount), 2);

        if ($discount > $total) {
            $discount = $total;
        }

        return [
            'total' => $total,
            'discount' => $discount,
            'net' => round($total - $discount, 2),
            'count' => count($lines),
            'cost' => round($cost, 2),
        ];
    }

    /**
     * إدراج رأس فاتورة مبيعات — `OHID` يتركه الزناد `SEQ_BILL_TRG` لأنه يُركّبه
     * Insert the invoice header — OHID is left to the SEQ_BILL_TRG trigger, which composes it.
     *
     * @param  array<string, mixed>  $h
     */
    public static function insertInvoice(array $h): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.SALES_BILL
                 (BILL_NO, YEAR, BILL_TYPE, DAT, TOTAL, DETAILES, ENTRY_NO, CUST_NO, CUST_NAME,
                  DIS_RATE, DISCOUNT, NET, CUR_NO, CUR_PRICE, STORE_NO, ACC_NO, ACC_NO1,
                  MANUAL_BILL_NO, CUTTING_OR_SLAES, SELECT1, BRANCH_NO, CREATED_ON, CREATED_BY,
                  CREATED_BY_NAME, THE_TIME, PAYMENT_METHOD, TEL_NO1, ADDRESS, SANF_NUMS, AMOUNT_PAID)
             VALUES
                 (:bill_no, :year, :bill_type, TO_DATE(:dat, \'YYYY-MM-DD\'), :total, :details, :entry_no,
                  :cust_no, :cust_name, :dis_rate, :discount, :net, :cur_no, :cur_price, :store_no,
                  :acc_no, :acc_no1, :manual_no, :kind, 2, :branch_no, SYSDATE, :created_by,
                  :created_by_name, :the_time, :payment_method, :tel_no1, :address, :sanf_nums, :amount_paid)',
            self::bindings([
                'bill_no' => $h['bill_no'],
                'year' => $h['year'],
                'bill_type' => $h['bill_type'],
                'dat' => $h['date'],
                'total' => $h['total'],
                'details' => $h['details'],
                'entry_no' => $h['entry_no'],
                'cust_no' => $h['cust_no'],
                'cust_name' => $h['cust_name'],
                'dis_rate' => $h['dis_rate'],
                'discount' => $h['discount'],
                'net' => $h['net'],
                'cur_no' => $h['cur_no'],
                'cur_price' => $h['cur_price'],
                'store_no' => $h['store_no'],
                'acc_no' => $h['acc_no'],
                'acc_no1' => $h['acc_no1'],
                'manual_no' => $h['manual_no'],
                'kind' => SalesPolicy::CUTTING_OR_SALES,
                'branch_no' => $h['branch_no'],
                'created_by' => $h['created_by'],
                'created_by_name' => $h['created_by_name'],
                'the_time' => $h['the_time'],
                'payment_method' => $h['payment_method'],
                'tel_no1' => $h['tel_no1'],
                'address' => $h['address'],
                'sanf_nums' => $h['sanf_nums'],
                'amount_paid' => $h['amount_paid'],
            ])
        );
    }

    /**
     * إدراج سطور فاتورة مبيعات — `SALES_DETAILES.SEQ` عدّاد عام، والمفتاح (رقم، سنة، تسلسل)
     * Insert the invoice lines — SALES_DETAILES.SEQ is a global counter keyed with (no, year, seq).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function insertInvoiceLines(int $billNo, int $year, array $lines): void
    {
        $seq = SalesPolicy::nextDetailSeq();
        $order = 1;

        foreach ($lines as $line) {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.SALES_DETAILES
                     (BILL_NO, YEAR, SANF_NO, SANF_PRICE, QTY, CAP, PCS, TOTAL, SEQ, DISCOUNT_RATE,
                      DISCOUNT1, REMARKS, SIZE_FRM, SIZE_TO, COLOR_NO, CATEGORY, CAT_NO, STYLE_NO,
                      STK_COST, SANF_COST, SANF_DNO, SEQ_NO)
                 VALUES
                     (:bill_no, :year, :sanf_no, :price, :qty, :cap, :pcs, :total, :seq, :rate,
                      :discount, :remarks, :size_frm, :size_to, :color_no, :category, :cat_no,
                      :style_no, :cost, :cost2, :sanf_dno, :order_no)',
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
                    'remarks' => $line['remarks'],
                    'size_frm' => $line['size_frm'],
                    'size_to' => $line['size_to'],
                    'color_no' => $line['color_no'],
                    'category' => $line['category'],
                    'cat_no' => $line['cat_no'],
                    'style_no' => $line['style_no'],
                    'cost' => $line['cost'],
                    'cost2' => $line['cost'],
                    'sanf_dno' => $line['sanf_dno'],
                    'order_no' => $order,
                ])
            );

            $seq++;
            $order++;
        }
    }

    /**
     * إدراج رأس مردود مبيعات
     * Insert the sales return header.
     *
     * @param  array<string, mixed>  $h
     */
    public static function insertReturn(array $h): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.RETURN_SALES
                 (RETURN_NO, YEAR, RET_DATE, RETURN_TOTAL, BILL_NO, DETAILES, STORE_NO, CUST_NO,
                  BILL_TYPE, BILL_YEAR, ENTRY_NO, DIS_RATE, DISCOUNT, NET, CUST_NAME, ACC_NO,
                  CUR_NO, CUR_PRICE, MANUAL_RETURN_NO, BRANCH_NO, SELECT1, CREATED_ON, CREATED_BY,
                  CREATED_BY_NAME)
             VALUES
                 (:return_no, :year, TO_DATE(:ret_date, \'YYYY-MM-DD\'), :return_total, :bill_no, :details,
                  :store_no, :cust_no, :bill_type, :bill_year, :entry_no, :dis_rate, :discount, :net,
                  :cust_name, :acc_no, :cur_no, :cur_price, :manual_no, :branch_no, 2, SYSDATE, :created_by,
                  :created_by_name)',
            self::bindings([
                'return_no' => $h['return_no'],
                'year' => $h['year'],
                'ret_date' => $h['date'],
                'return_total' => $h['total'],
                'bill_no' => $h['bill_no'],
                'details' => $h['details'],
                'store_no' => $h['store_no'],
                'cust_no' => $h['cust_no'],
                'bill_type' => $h['bill_type'],
                'bill_year' => $h['bill_year'],
                'entry_no' => $h['entry_no'],
                'dis_rate' => $h['dis_rate'],
                'discount' => $h['discount'],
                'net' => $h['net'],
                'cust_name' => $h['cust_name'],
                'acc_no' => $h['acc_no'],
                'cur_no' => $h['cur_no'],
                'cur_price' => $h['cur_price'],
                'manual_no' => $h['manual_no'],
                'branch_no' => $h['branch_no'],
                'created_by' => $h['created_by'],
                'created_by_name' => $h['created_by_name'],
            ])
        );
    }

    /**
     * إدراج سطور مردود المبيعات
     * Insert the sales return lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function insertReturnLines(int $returnNo, int $year, array $lines): void
    {
        $seq = SalesPolicy::nextReturnDetailSeq();

        foreach ($lines as $line) {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.RETURN_SALES_DETAILES
                     (RETURN_NO, YEAR, SANF_NO, RET_QTY, CAP, PCS, SANF_PRICE, TOTAL, SEQ,
                      DISCOUNT_RATE, DISCOUNT1, SIZE_FRM, SIZE_TO, COLOR_NO, CATEGORY, SANF_DNO,
                      CAT_NO, STYLE_NO, STK_COST, SANF_COST, SEQ_SALES)
                 VALUES
                     (:return_no, :year, :sanf_no, :qty, :cap, :pcs, :price, :total, :seq,
                      :rate, :discount, :size_frm, :size_to, :color_no, :category, :sanf_dno,
                      :cat_no, :style_no, :cost, :cost2, :seq_sales)',
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
                    'size_frm' => $line['size_frm'],
                    'size_to' => $line['size_to'],
                    'color_no' => $line['color_no'],
                    'category' => $line['category'],
                    'sanf_dno' => $line['sanf_dno'],
                    'cat_no' => $line['cat_no'],
                    'style_no' => $line['style_no'],
                    'cost' => $line['cost'],
                    'cost2' => $line['cost'],
                    'seq_sales' => $line['seq_sales'],
                ])
            );

            $seq++;
        }
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
     * تحميل بطاقات الأصناف مرة واحدة لكل حزمة مفاتيح
     * Load the item cards once per chunk of keys.
     *
     * @param  array<int, int>  $sanfNos
     * @return array<int, array<string, mixed>>
     */
    private static function loadItems(array $sanfNos): array
    {
        $result = [];
        $unique = array_values(array_unique(array_map(static fn (int $n): int => (int) $n, $sanfNos)));

        foreach (array_chunk($unique, self::CHUNK) as $chunk) {
            $binds = [];
            $placeholders = [];

            foreach ($chunk as $i => $sanfNo) {
                $binds['s'.$i] = $sanfNo;
                $placeholders[] = ':s'.$i;
            }

            $sql = 'SELECT SANF_NO, SANF_NAME, CAP, CAT_NO, STYL_NO, BUY_PRICE, SANF_PRICE, CATEGORY
                      FROM ACCOUNTS.SANFS
                     WHERE SANF_NO IN ('.implode(',', $placeholders).')';

            foreach (DB::connection('oracle')->select($sql, $binds) as $row) {
                $row = array_change_key_case((array) $row, CASE_UPPER);
                $result[(int) $row['SANF_NO']] = $row;
            }
        }

        return $result;
    }
}
