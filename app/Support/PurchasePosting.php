<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * ترحيل مستندات المشتريات إلى القيود المحاسبية القديمة
 * Posting purchase documents into the legacy journal.
 *
 * ── آلية الترحيل كما أثبتتها القيود الحية (لا زناد على الجداول المالية):
 *    الفاتورة (ENTRY_TYPE 22 · بادئة 4) من سطرين:
 *      مدين حساب المخزون بمقدار `NET × CUR_PRICE1` بالريال،
 *      دائن حساب المورد/الصندوق بمقدار `-NET` بعملته مع `SEQ_PRICE`.
 *    والمردود (ENTRY_TYPE 13 · بادئة 5) معكوس تماماً:
 *      مدين حساب المورد/الصندوق بـ `+NET` بعملته مع `SEQ_PRICE`،
 *      دائن حساب المخزون بـ `-NET × CUR_PRICE` بالريال.
 * ── The posting mechanics as proved by the live entries: the invoice debits stock with the
 *    riyal equivalent and credits the party in its own currency; the return reverses both.
 *
 * ── الخصم لا يُرحَّل في سطر مستقل: الطرفان كلاهما بالصافي، مطابقةً للنظام القديم.
 * ── The discount never posts as its own line: both sides carry the net, exactly like the legacy.
 */
final class PurchasePosting
{
    /**
     * ترحيل فاتورة مشتريات — يُرجع رقم القيد
     * Post a purchase invoice — returns the created entry number.
     *
     * @param  array{bill_type: int, date: string, manual_no: int, net: float, net_base: float,
     *               stock_account: int, party_account: int, seq_price: ?int, note: string}  $doc
     */
    public static function postInvoice(array $doc): int
    {
        $year = (int) substr((string) $doc['date'], 0, 4);

        $entryNo = JournalWriter::insertEntry(
            PurchasePolicy::ENTRY_TYPE_INVOICE,
            (string) $doc['note'],
            (string) $doc['date'],
            PurchasePolicy::ENTRY_PREFIX_INVOICE,
            $year
        );

        // 1) المخزون مدين بمعادل الريال — بلا SEQ_PRICE لأن الحساب ريالي
        //    Stock is debited with the riyal equivalent; no SEQ_PRICE on a riyal account
        JournalWriter::insertLine($entryNo, (int) $doc['stock_account'], round((float) $doc['net_base'], 2));

        // 2) المورد/الصندوق دائن بعملته، و SEQ_PRICE يربط السعر بسجل CUR_PRICE
        //    The party is credited in its own currency, SEQ_PRICE pointing at the CUR_PRICE row
        JournalWriter::insertLine(
            $entryNo,
            (int) $doc['party_account'],
            -round((float) $doc['net'], 2),
            $doc['seq_price'] ?? null
        );

        return $entryNo;
    }

    /**
     * ترحيل مردود مشتريات — عكس الفاتورة
     * Post a purchase return — the exact reverse of the invoice.
     *
     * @param  array{bill_type: int, date: string, manual_no: int, net: float, net_base: float,
     *               stock_account: int, party_account: int, seq_price: ?int, note: string}  $doc
     */
    public static function postReturn(array $doc): int
    {
        $year = (int) substr((string) $doc['date'], 0, 4);

        $entryNo = JournalWriter::insertEntry(
            PurchasePolicy::ENTRY_TYPE_RETURN,
            (string) $doc['note'],
            (string) $doc['date'],
            PurchasePolicy::ENTRY_PREFIX_RETURN,
            $year
        );

        // 1) المورد/الصندوق مدين بالصافي بعملته / the party is debited with the net
        JournalWriter::insertLine(
            $entryNo,
            (int) $doc['party_account'],
            round((float) $doc['net'], 2),
            $doc['seq_price'] ?? null
        );

        // 2) المخزون دائن بمعادل الريال (البضاعة عادت للمورد) / stock is credited with the base
        JournalWriter::insertLine($entryNo, (int) $doc['stock_account'], -round((float) $doc['net_base'], 2));

        return $entryNo;
    }

    /**
     * صف `CUR_PRICE` المطابق للعملة والسعر والتاريخ، وإن غاب يُدرج صفاً جديداً
     * The CUR_PRICE row matching currency + price + date, inserting one when absent.
     *
     * ── لماذا البحث عن مطابق؟ لأن النظام القديم لا يخترع صف سعر لكل فاتورة: 3,142 صفاً فقط
     *    لعملة واحدة على مدى سنوات، وفواتير كثيرة تشير إلى الصف نفسه (`SEQ_PRICE1`).
     * ── Why reuse: the legacy never invents a price row per invoice — thousands of invoices share
     *    one daily row through SEQ_PRICE1.
     *
     * @return int|null رقم الصف، أو null للعملة المحلية (لا سعر صرف لها)
     */
    public static function resolveRateSeq(int $curNo, float $price, string $date): ?int
    {
        // العملة المحلية سعرها 1 ولا صف لها في CUR_PRICE أصلاً (لا صفوف للعملة 1)
        // The local currency is implicitly 1 and has no CUR_PRICE rows at all
        if ($curNo <= 1) {
            return null;
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT SEQ FROM (
                 SELECT SEQ
                   FROM ACCOUNTS.CUR_PRICE
                  WHERE CUR_NO = :cur_no
                    AND ABS(PRICE - :price) < 0.005
                    AND DAY_DATE <= TO_DATE(:day_date, \'YYYY-MM-DD\')
                  ORDER BY DAY_DATE DESC, SEQ DESC
             ) WHERE ROWNUM = 1',
            ['cur_no' => $curNo, 'price' => round($price, 4), 'day_date' => $date]
        );

        $seq = $row['SEQ'] ?? $row['seq'] ?? null;

        if ($seq !== null) {
            return (int) $seq;
        }

        $next = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.CUR_PRICE'
        );
        $newSeq = (int) (reset($next) ?: 0) + 1;

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.CUR_PRICE (SEQ, CUR_NO, PRICE, DAY_DATE)
             VALUES (:seq, :cur_no, :price, TO_DATE(:day_date, \'YYYY-MM-DD\'))',
            [
                'seq' => $newSeq,
                'cur_no' => $curNo,
                'price' => round($price, 4),
                'day_date' => $date,
            ]
        );

        return $newSeq;
    }

    /**
     * سعر الصرف المعتمد لمستند: أحدث سعر لا يتجاوز تاريخه، وإلا 1 للعملة المحلية
     * The document's FX rate: the latest row not after its date, or 1 for the local currency.
     */
    public static function suggestRate(int $curNo, ?string $date = null): float
    {
        if ($curNo <= 1) {
            return 1.0;
        }

        $day = $date ?? PurchasePolicy::dbToday();

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT PRICE FROM (
                 SELECT PRICE
                   FROM ACCOUNTS.CUR_PRICE
                  WHERE CUR_NO = :cur_no
                    AND DAY_DATE <= TO_DATE(:day_date, \'YYYY-MM-DD\')
                  ORDER BY DAY_DATE DESC, SEQ DESC
             ) WHERE ROWNUM = 1',
            ['cur_no' => $curNo, 'day_date' => $day]
        );

        $price = $row['PRICE'] ?? $row['price'] ?? null;

        return $price === null ? 1.0 : (float) $price;
    }
}
