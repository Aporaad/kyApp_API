<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل فاتورة التفصيل إلى القيود المحاسبية القديمة
 * Posting a cutting bill into the legacy journal.
 *
 * ── القيد المقيس من آخر الفواتير الحيّة (22095/22096):
 *    عربون 0 ⇒ سطران: مدين حساب العميل بالكامل، دائن 41210001 بالكامل.
 *    عربون > 0 ⇒ أربعة أسطر: السطران أعلاه، ثم مدين الصندوق بالعربون ودائن العميل به.
 *    ENTRY_TYPE_NO = 6 (تفصيل)، بادئة ENTRY_NO = 5، INVOICE_NO = الرقم اليدوي،
 *    GIVING = CUST_NO للعميل العادي وNULL عند الربط بحساب عامل.
 * ── The measured entries: zero deposit ⇒ two lines (customer debit, 41210001 credit);
 *    a paid deposit adds the cash debit and the customer credit. Entry type 6, prefix 5.
 *
 * ── ENTRY_SUB.BALANCE_S لقطة رصيد جارية لا يقرأها هذا التطبيق لكنها تُحدَّث هنا كما في
 *    SalesPosting كي لا يرى المحاسب لقطة مبتورة.
 * ── BALANCE_S is a running snapshot this API never reads, maintained exactly as SalesPosting
 *    does so the accountant's desktop keeps a coherent snapshot.
 */
final class TailoringPosting
{
    /**
     * ترحيل فاتورة التفصيل — يُرجع رقم القيد المُنشأ
     * Post a cutting bill — returns the created entry number.
     *
     * @param  array{date: string, manual_no: int, cust_no: int|null, total: float,
     *               paid: float, party_account: int, credit_account: int, cash_account: int|null,
     *               note: string}  $doc
     */
    public static function postBill(array $doc): int
    {
        $year = (int) substr((string) $doc['date'], 0, 4);

        $entryNo = TailoringNumbering::nextEntryNo($year);

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_MAIN (ENTRY_NO, DETAILS, ENTRY_DATE, ENTRY_TYPE_NO)
             VALUES (:entry_no, :details, TO_DATE(:entry_date, \'YYYY-MM-DD\'), :type_no)',
            [
                'entry_no' => $entryNo,
                'details' => self::arabic(self::clip((string) $doc['note'], 250)),
                'entry_date' => (string) $doc['date'],
                'type_no' => TailoringPolicy::ENTRY_TYPE_TAILORING,
            ]
        );

        $total = round((float) $doc['total'], 2);
        $paid = round((float) $doc['paid'], 2);
        $giving = $doc['cust_no'];
        $invoiceNo = (int) $doc['manual_no'];

        // 1) حساب العميل/العامل مدين بالإجمالي / the party ledger is debited with the total
        self::insertLine($entryNo, (int) $doc['party_account'], $total, $giving, $invoiceNo, (string) $doc['note']);

        // 2) مبيعات آجلة دائن بالإجمالي / credit revenue with the total
        self::insertLine($entryNo, (int) $doc['credit_account'], -$total, null, $invoiceNo, (string) $doc['note']);

        // 3 و 4) العربون: الصندوق مدين والعميل دائن به / the deposit: cash debit, party credit
        if ($paid > 0 && $doc['cash_account'] !== null) {
            self::insertLine($entryNo, (int) $doc['cash_account'], $paid, null, $invoiceNo, (string) $doc['note']);
            self::insertLine($entryNo, (int) $doc['party_account'], -$paid, $giving, $invoiceNo, (string) $doc['note']);
        }

        return $entryNo;
    }

    /**
     * إدراج سطر قيد واحد (موجب = مدين، سالب = دائن) — بنفس منطق SalesPosting
     * Insert one journal line (positive = debit, negative = credit), as SalesPosting does.
     */
    private static function insertLine(int $entryNo, int $accNo, float $amount, ?int $giving, ?int $invoiceNo, string $details): void
    {
        $seq = TailoringNumbering::nextEntrySeq();
        $text = self::arabic(self::clip($details, 250));

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_SUB
                 (SEQ, ENTRY_NO, ACC_NO, ENTRY_MNT, BALANCE_S, SUB_DETAILS, INVOICE_NO, GIVING)
             VALUES (:seq, :entry_no, :acc_no, :amount, :balance, :details, :invoice_no, :giving)',
            [
                'seq' => $seq,
                'entry_no' => $entryNo,
                'acc_no' => $accNo,
                'amount' => round($amount, 2),
                'balance' => round(self::snapshot($accNo) + $amount, 2),
                'details' => $text,
                'invoice_no' => $invoiceNo,
                'giving' => $giving,
            ]
        );
    }

    /**
     * آخر لقطة رصيد للحساب قبل هذا السطر / the account's latest BALANCE_S snapshot.
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

    /**
     * تحويل النص العربي إلى صيغة الكتابة الصحيحة في أوراكل
     * Convert Arabic to the correct Oracle write form.
     */
    private static function arabic(string $text): string
    {
        return (string) ArabicEncoding::toOracleSearchChars($text);
    }

    /**
     * قصّ النص لسعة العمود بلا كسر محرف / clip text to the column capacity.
     */
    private static function clip(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }
}
