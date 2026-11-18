<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * كاتب القيود المحاسبية القديمة — رأس `ENTRY_MAIN` وسطور `ENTRY_SUB`
 * Legacy journal writer — the ENTRY_MAIN header and its ENTRY_SUB lines.
 *
 * ── لماذا وحدة مستقلة عن `SalesPosting`؟ لأن مستندات المشتريات تحتاج `SEQ_PRICE` في السطر
 *    (ربط السعر بسجل `CUR_PRICE` عند العملة الأجنبية)، وهو ما لا يكتبه ترحيل المبيعات، ولكي
 *    يشترك الموردون والفواتير والمردودات في إدراج وحذف واحد.
 * ── Why separate from SalesPosting: purchase lines need SEQ_PRICE (the CUR_PRICE row of a foreign
 *    currency), which sales posting never writes, and so every purchase document shares one
 *    insert/delete path.
 *
 * ── `BALANCE_S` لقطة رصيد جارية لا يقرأها هذا التطبيق (الرصيد يُشتق من ENTRY_MNT)، لكنها
 *    إلزامية في المخطط وتُحدَّث بمنطق النظام القديم كي لا يرى المحاسب لقطة مبتورة.
 * ── BALANCE_S is a running snapshot this API never reads, but it is NOT NULL in the schema and
 *    is maintained so the legacy screens keep a coherent snapshot.
 */
final class JournalWriter
{
    /** سعة `ENTRY_MAIN.DETAILS` و`ENTRY_SUB.SUB_DETAILS` / the two text capacities */
    private const DETAILS_LEN = 250;

    /**
     * إدراج رأس قيد وإرجاع رقمه
     * Insert the entry header and return its number.
     */
    public static function insertEntry(int $typeNo, string $details, string $date, int $prefix, int $year): int
    {
        $entryNo = SalesPolicy::nextEntryNo($prefix, $year);

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_MAIN (ENTRY_NO, DETAILS, ENTRY_DATE, ENTRY_TYPE_NO)
             VALUES (:entry_no, :details, TO_DATE(:entry_date, \'YYYY-MM-DD\'), :type_no)',
            [
                'entry_no' => $entryNo,
                'details' => self::arabic(self::clip($details, self::DETAILS_LEN)),
                'entry_date' => $date,
                'type_no' => $typeNo,
            ]
        );

        return $entryNo;
    }

    /**
     * إدراج سطر قيد واحد (موجب = مدين، سالب = دائن)
     * Insert one journal line (positive = debit, negative = credit).
     */
    public static function insertLine(
        int $entryNo,
        int $accNo,
        float $amount,
        ?int $seqPrice = null,
        ?string $details = null,
        ?int $giving = null,
        ?int $invoiceNo = null
    ): void {
        $seq = SalesPolicy::nextEntrySeq();
        $text = $details === null ? null : self::arabic(self::clip($details, self::DETAILS_LEN));

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_SUB
                 (SEQ, ENTRY_NO, ACC_NO, ENTRY_MNT, BALANCE_S, SEQ_PRICE, SUB_DETAILS, INVOICE_NO, GIVING)
             VALUES (:seq, :entry_no, :acc_no, :amount, :balance, :seq_price, :details, :invoice_no, :giving)',
            [
                'seq' => $seq,
                'entry_no' => $entryNo,
                'acc_no' => $accNo,
                'amount' => round($amount, 2),
                'balance' => round(self::snapshot($accNo) + $amount, 2),
                'seq_price' => $seqPrice,
                'details' => $text,
                'invoice_no' => $invoiceNo,
                'giving' => $giving,
            ]
        );
    }

    /**
     * حذف قيد بالكامل — السطور ثم الرأس (لا حذف متتالٍ في المخطط)
     * Delete a whole entry — lines then header (no cascade exists in the schema).
     */
    public static function deleteEntry(?int $entryNo): void
    {
        if ($entryNo === null || $entryNo <= 0) {
            return;
        }

        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :e', ['e' => $entryNo]);
        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :e', ['e' => $entryNo]);
    }

    /**
     * هل القيد موجود فعلاً؟ (6 فواتير قديمة بلا قيد، فلا يُحذف رقم غير موجود)
     * Does the entry actually exist? (six legacy invoices carry no entry at all.)
     */
    public static function entryExists(?int $entryNo): bool
    {
        if ($entryNo === null || $entryNo <= 0) {
            return false;
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :e',
            ['e' => $entryNo]
        );

        return ((int) (reset($row) ?: 0)) > 0;
    }

    /**
     * آخر لقطة رصيد للحساب قبل هذا السطر
     * The account's latest BALANCE_S snapshot before this line.
     */
    private static function snapshot(int $accNo): float
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(BALANCE_S) KEEP (DENSE_RANK LAST ORDER BY SEQ), 0) AS B
               FROM ACCOUNTS.ENTRY_SUB
              WHERE ACC_NO = :a',
            ['a' => $accNo]
        );

        return (float) (reset($row) ?: 0);
    }

    /** تحويل النص العربي إلى صيغة الكتابة الصحيحة في أوراكل (CP1256 عبر Latin-1) */
    private static function arabic(string $text): string
    {
        return (string) ArabicEncoding::toOracleSearchChars($text);
    }

    /** قصّ النص لسعة العمود بلا كسر محرف / clip without splitting a character */
    private static function clip(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }
}
