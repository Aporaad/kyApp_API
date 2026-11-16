<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * ترحيل مستندات المبيعات إلى القيود المحاسبية القديمة
 * Posting sales documents into the legacy journal.
 *
 * ── آلية الترحيل كما أثبتتها المرحلة 0 (لا زناد على الجداول المالية):
 *    الفاتورة النقدية تنشئ قيداً من سطرين: مدين حساب الصندوق/المحفظة بالإجمالي الصافي،
 *    ودائن حساب الإيراد (41110001). والفاتورة الآجلة تمدّن حساب العميل مع `GIVING = CUST_NO`
 *    وتدائن 41210001. أما المردود فأربعة سطور: مدين حساب المردودات، مدين المخزون بالتكلفة،
 *    دائن حساب العميل/الصندوق، دائن تكلفة المبيعات — أي عكس الإيراد والتكلفة معاً.
 * ── The posting mechanics as proved by Phase 0 (no triggers on the financial tables): a cash
 *    invoice posts two lines (debit the cash/wallet account, credit revenue 41110001); a credit
 *    invoice debits the customer ledger with `GIVING = CUST_NO` and credits 41210001. A return
 *    posts four lines — debit returns, debit stock by cost, credit the customer/cash, credit cost
 *    of sales — reversing both revenue and cost.
 *
 * ── `ENTRY_SUB.BALANCE_S` لقطة رصيد جارية لا يقرأها هذا التطبيق (الرصيد يُشتق من ENTRY_MNT)،
 *    لكنها تُحدَّث هنا بنفس منطق النظام القديم كي لا يرى المحاسب لقطة مبتورة.
 * ── ENTRY_SUB.BALANCE_S is a running-balance snapshot this API never reads (balances derive
 *    from ENTRY_MNT), but it is maintained here so the legacy screens keep a coherent snapshot.
 */
final class SalesPosting
{
    /**
     * ترحيل فاتورة مبيعات — يُرجع رقم القيد المُنشأ
     * Post a sales invoice — returns the created entry number.
     *
     * @param  array{bill_type: int, date: string, manual_no: int, cust_no: ?int, cust_name: ?string,
     *               net: float, debit_account: int, credit_account: int, note: string}  $doc
     */
    public static function postInvoice(array $doc): int
    {
        $year = (int) substr((string) $doc['date'], 0, 4);
        $prefix = SalesPolicy::invoiceEntryPrefix((int) $doc['bill_type']);

        $entryNo = self::insertEntry(
            SalesPolicy::ENTRY_TYPE_INVOICE,
            (string) $doc['note'],
            (string) $doc['date'],
            $prefix,
            $year
        );

        // في الآجل: الحساب المدين هو حساب العميل، فيُثبت رقم العميل في GIVING (كما في القيود القديمة).
        // On credit: the debit account is the customer ledger, so the customer number goes to GIVING.
        $giving = (int) $doc['bill_type'] === SalesPolicy::BILL_CREDIT ? ($doc['cust_no'] ?? null) : null;

        self::insertLine($entryNo, (int) $doc['debit_account'], (float) $doc['net'], $giving, (int) $doc['manual_no'], (string) $doc['note']);
        self::insertLine($entryNo, (int) $doc['credit_account'], -(float) $doc['net'], null, (int) $doc['manual_no'], (string) $doc['note']);

        return $entryNo;
    }

    /**
     * ترحيل مردود مبيعات — عكس الإيراد والتكلفة
     * Post a sales return — reverses both revenue and cost.
     *
     * @param  array{bill_type: int, date: string, manual_no: int, cust_no: ?int,
     *               net: float, cost: float, returns_account: int, party_account: int,
     *               note: string}  $doc
     */
    public static function postReturn(array $doc): int
    {
        $year = (int) substr((string) $doc['date'], 0, 4);

        $entryNo = self::insertEntry(
            SalesPolicy::ENTRY_TYPE_RETURN,
            (string) $doc['note'],
            (string) $doc['date'],
            SalesPolicy::ENTRY_PREFIX_RETURN,
            $year
        );

        $net = (float) $doc['net'];
        $cost = round((float) $doc['cost'], 2);
        $giving = (int) $doc['bill_type'] === SalesPolicy::BILL_CREDIT ? ($doc['cust_no'] ?? null) : null;

        // 1) مردودات المبيعات مدينة بالصافي / returns are debited with the net
        self::insertLine($entryNo, (int) $doc['returns_account'], $net, null, (int) $doc['manual_no'], (string) $doc['note']);
        // 2) المخزون مدين بالتكلفة (البضاعة عادت للمخزن) / stock is debited with the returning cost
        if ($cost > 0) {
            self::insertLine($entryNo, SalesPolicy::STOCK_ACCOUNT, $cost, null, (int) $doc['manual_no'], (string) $doc['note']);
        }
        // 3) حساب العميل أو الصندوق دائن بالصافي / the customer/cash account is credited with the net
        self::insertLine($entryNo, (int) $doc['party_account'], -$net, $giving, null, (string) $doc['note']);
        // 4) تكلفة المبيعات دائنة بالتكلفة / cost of sales is credited
        if ($cost > 0) {
            self::insertLine($entryNo, SalesPolicy::COST_OF_SALES_ACCOUNT, -$cost, null, (int) $doc['manual_no'], (string) $doc['note']);
        }

        return $entryNo;
    }

    /**
     * حذف قيد بالكامل — السطور ثم الرأس (لا يوجد حذف متتالٍ في المخطط)
     * Delete a whole entry — lines then header (no cascade exists in the schema).
     */
    public static function deleteEntry(int $entryNo): void
    {
        if ($entryNo <= 0) {
            return;
        }

        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :e', ['e' => $entryNo]);
        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :e', ['e' => $entryNo]);
    }

    /**
     * هل القيد موجود فعلاً؟ (حماية من حذف رقم غير موجود في ENTRY_MAIN)
     * Does the entry actually exist? (guards against deleting a dangling number)
     */
    public static function entryExists(int $entryNo): bool
    {
        if ($entryNo <= 0) {
            return false;
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :e',
            ['e' => $entryNo]
        );

        return (int) (reset($row) ?: 0) > 0;
    }

    /**
     * إدراج رأس القيد وإرجاع رقمه
     * Insert the entry header and return its number.
     */
    private static function insertEntry(int $typeNo, string $details, string $date, int $prefix, int $year): int
    {
        $entryNo = SalesPolicy::nextEntryNo($prefix, $year);

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_MAIN (ENTRY_NO, DETAILS, ENTRY_DATE, ENTRY_TYPE_NO)
             VALUES (:entry_no, :details, TO_DATE(:entry_date, \'YYYY-MM-DD\'), :type_no)',
            [
                'entry_no' => $entryNo,
                'details' => self::arabic(self::clip($details, 250)),
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
    private static function insertLine(int $entryNo, int $accNo, float $amount, ?int $giving, ?int $invoiceNo, string $details): void
    {
        $seq = SalesPolicy::nextEntrySeq();
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
     * آخر لقطة رصيد للحساب (BALANCE_S) قبل هذا السطر
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

    /**
     * تحويل النص العربي إلى صيغة الكتابة الصحيحة في أوراكل (CP1256 عبر Latin-1)
     * Convert Arabic to the correct Oracle write form (CP1256 carried as Latin-1).
     */
    private static function arabic(string $text): string
    {
        return (string) ArabicEncoding::toOracleSearchChars($text);
    }

    /**
     * قصّ النص لسعة العمود بلا كسر محرف
     * Clip text to the column capacity without splitting a character.
     */
    private static function clip(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }
}
