<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * تحديث الجانب المالي لفاتورة التفصيل في جميع المراحل.
 * The tailoring invoice financial updater, available in every workflow stage.
 *
 * ── قبل «تسليم للقص» يمكن إعادة بناء أطراف الفاتورة القياسية؛ بعدها أو عند
 *    وجود ارتباط خارجي تُحفظ السطور التاريخية وتُضاف أسطر تعديل بالفارق.
 * ── Before cutting handover, standard invoice lines may be rebuilt. Afterwards,
 *    or with external links, history is preserved and delta lines are appended.
 */
final class TailoringFinancialUpdate
{
    /**
     * تطبيق التعديل المالي على القيد الحالي.
     * Apply a financial change to the existing journal entry.
     */
    public static function apply(
        array $oldSnapshot,
        array $newFinance,
        string $details,
        ?string $date,
        int $manualNo,
        int $custNo,
        string $custName,
        bool $preserveHistory
    ): void {
        $entryNo = (int) $oldSnapshot['identity']['entry_no'];
        if ($entryNo <= 0) {
            throw new \RuntimeException('لا يوجد قيد مرتبط بفاتورة التفصيل / No journal entry is linked to the cutting bill');
        }
        $lockedEntry = (array) DB::connection('oracle')->selectOne(
            'SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :entry_no FOR UPDATE',
            ['entry_no' => $entryNo]
        );
        if ($lockedEntry === []) {
            throw new \RuntimeException('القيد المرتبط غير موجود / The linked journal entry does not exist');
        }

        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.ENTRY_MAIN
                SET DETAILS = :details,
                    ENTRY_DATE = TO_DATE(:entry_date, \'YYYY-MM-DD\')
              WHERE ENTRY_NO = :entry_no',
            [
                'details' => ArabicEncoding::toOracleSearchChars(mb_substr($details, 0, 250)),
                'entry_date' => $date ?? (string) $oldSnapshot['identity']['date'],
                'entry_no' => $entryNo,
            ]
        );

        $oldFinance = $oldSnapshot['finance'];
        $oldManual = (int) $oldSnapshot['identity']['manual_bill_no'];
        $oldCustNo = (int) $oldSnapshot['header']['cust_no'];
        $oldCustName = (string) $oldSnapshot['header']['cust_name'];
        $newFinance = array_merge($oldFinance, $newFinance);
        $affectedAccounts = [];

        if ($preserveHistory) {
            $oldActual = self::ownedLines($oldSnapshot, $oldManual, $oldCustNo);
            if ($oldManual !== $manualNo || $oldCustNo !== $custNo) {
                DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.ENTRY_SUB SET INVOICE_NO = :manual, GIVING = :cust
                      WHERE ENTRY_NO = :entry_no AND (INVOICE_NO = :old_manual OR GIVING = :old_cust)',
                    [
                        'manual' => $manualNo, 'cust' => $custNo, 'entry_no' => $entryNo,
                        'old_manual' => $oldManual, 'old_cust' => $oldCustNo,
                    ]
                );
            }
            $actualByAccount = [];
            foreach ($oldActual as $line) {
                $account = (int) CuttingViewReader::num($line, 'ACC_NO');
                $actualByAccount[$account] ??= ['amount' => 0.0, 'seq_price' => null];
                $actualByAccount[$account]['amount'] += (float) CuttingViewReader::num($line, 'ENTRY_MNT');
                $actualByAccount[$account]['seq_price'] = CuttingViewReader::num($line, 'SEQ_PRICE')
                    ?? $actualByAccount[$account]['seq_price'];
            }
            $newByAccount = [];
            foreach (self::targetLines($newFinance, $manualNo, $custNo, $custName) as $line) {
                $account = (int) $line['acc_no'];
                $newByAccount[$account] ??= ['amount' => 0.0, 'seq_price' => null, 'details' => $line['details']];
                $newByAccount[$account]['amount'] += (float) $line['amount'];
                $newByAccount[$account]['seq_price'] = $line['seq_price'];
                $newByAccount[$account]['details'] = $line['details'];
            }
            foreach (array_unique(array_merge(array_keys($actualByAccount), array_keys($newByAccount))) as $account) {
                $oldAmount = (float) ($actualByAccount[$account]['amount'] ?? 0);
                $newAmount = (float) ($newByAccount[$account]['amount'] ?? 0);
                $delta = round($newAmount - $oldAmount, 2);
                if (abs($delta) < 0.01) {
                    continue;
                }
                self::appendLine(
                    $entryNo,
                    $account,
                    $delta,
                    $newByAccount[$account]['seq_price'] ?? $actualByAccount[$account]['seq_price'] ?? null,
                    $manualNo,
                    $custNo,
                    $newByAccount[$account]['details'] ?? 'تعديل فاتورة تفصيل'
                );
                $affectedAccounts[$account] = true;
            }
        } else {
            foreach (self::ownedLines($oldSnapshot, $oldManual, $oldCustNo) as $line) {
                $affectedAccounts[(int) CuttingViewReader::num($line, 'ACC_NO')] = true;
            }
            DB::connection('oracle')->delete(
                'DELETE FROM ACCOUNTS.ENTRY_SUB
                  WHERE ENTRY_NO = :entry_no
                    AND (INVOICE_NO = :invoice_no OR GIVING = :cust_no)',
                ['entry_no' => $entryNo, 'invoice_no' => $oldManual, 'cust_no' => $oldCustNo]
            );
            foreach (self::targetLines($newFinance, $manualNo, $custNo, $custName) as $line) {
                self::appendLine($entryNo, $line['acc_no'], $line['amount'], $line['seq_price'], $manualNo, $custNo, $line['details']);
                $affectedAccounts[(int) $line['acc_no']] = true;
            }
        }

        self::rebalance($entryNo, array_keys($affectedAccounts));
        self::balanceDifference($entryNo, $manualNo, $custNo, $custName, $affectedAccounts);
        self::rebalance($entryNo, array_keys($affectedAccounts));
        $sum = (float) self::first(DB::connection('oracle')->selectOne(
            'SELECT NVL(SUM(ENTRY_MNT), 0) AS N FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :entry_no',
            ['entry_no' => $entryNo]
        ));
        if (abs($sum) >= 0.01) {
            throw new \RuntimeException('القيد غير متوازن بعد التعديل / The journal is unbalanced after editing');
        }
    }

    /**
     * هل القيد مطابق تماماً لأطراف فاتورة قياسية ويمكن إعادة بناؤه بأمان؟
     * Does the journal exactly match a standard invoice and safely allow a rebuild?
     */
    public static function matchesInvoice(array $snapshot): bool
    {
        $entry = $snapshot['journal']['entry'] ?? [];
        $lines = $snapshot['journal']['lines'] ?? [];
        if ((int) CuttingViewReader::num($entry, 'ENTRY_TYPE_NO') !== TailoringPolicy::ENTRY_TYPE_TAILORING) {
            return false;
        }
        $finance = $snapshot['finance'];
        $manual = (int) $snapshot['identity']['manual_bill_no'];
        $custNo = (int) $snapshot['header']['cust_no'];
        $expected = self::targetLines(
            $finance,
            $manual,
            $custNo,
            (string) $snapshot['header']['cust_name']
        );
        if (count($lines) !== count($expected)) {
            return false;
        }
        $remaining = [];
        foreach ($lines as $line) {
            if (CuttingViewReader::num($line, 'SEQ_PRICE') !== null
                || (int) CuttingViewReader::num($line, 'INVOICE_NO') !== $manual
                || (int) CuttingViewReader::num($line, 'GIVING') !== $custNo) {
                return false;
            }
            $remaining[] = $line;
        }
        foreach ($expected as $line) {
            $matched = null;
            foreach ($remaining as $index => $actual) {
                if ((int) CuttingViewReader::num($actual, 'ACC_NO') === (int) $line['acc_no']
                    && abs((float) CuttingViewReader::num($actual, 'ENTRY_MNT') - (float) $line['amount']) < 0.01) {
                    $matched = $index;
                    break;
                }
            }
            if ($matched === null) {
                return false;
            }
            unset($remaining[$matched]);
        }

        return $remaining === [];
    }

    // / السطور المملوكة للفاتورة داخل القيد الحالي.
    // / Invoice-owned lines inside the current journal entry.
    private static function ownedLines(array $snapshot, int $manualNo, int $custNo): array
    {
        return array_values(array_filter(
            $snapshot['journal']['lines'] ?? [],
            static function (array $line) use ($manualNo, $custNo): bool {
                $invoice = CuttingViewReader::num($line, 'INVOICE_NO');
                $giving = CuttingViewReader::num($line, 'GIVING');
                if ($invoice === null && $giving === null) {
                    return true;
                }

                return ($invoice === null || (int) $invoice === $manualNo)
                    && ($giving === null || (int) $giving === $custNo);
            }
        ));
    }

    /**
     * أطراف الفاتورة المستهدفة كما يكتبها النظام القديم.
     * Target invoice journal lines, matching the legacy document shape.
     */
    private static function targetLines(array $finance, int $manualNo, int $custNo, string $custName): array
    {
        $total = round((float) $finance['total'], 2);
        $paid = round((float) $finance['paid'], 2);
        $texts = TailoringPosting::lineTexts($manualNo, $custName);
        $lines = [
            'party_sale' => [
                'acc_no' => (int) $finance['acc_no'],
                'amount' => self::accountAmount((int) $finance['acc_no'], $total, $finance, false),
                'seq_price' => self::sequencePrice((int) $finance['acc_no'], $finance),
                'details' => $texts['sale'],
            ],
            'credit_sale' => [
                'acc_no' => (int) $finance['acc_no1'],
                'amount' => -self::accountAmount((int) $finance['acc_no1'], $total, $finance, true),
                'seq_price' => self::sequencePrice((int) $finance['acc_no1'], $finance),
                'details' => $texts['sale'],
            ],
        ];
        if ($paid > 0) {
            $lines['cash_deposit'] = [
                'acc_no' => (int) $finance['acc_no2'],
                'amount' => self::accountAmount((int) $finance['acc_no2'], $paid, $finance, false),
                'seq_price' => self::sequencePrice((int) $finance['acc_no2'], $finance),
                'details' => $texts['cash'],
            ];
            $lines['party_deposit'] = [
                'acc_no' => (int) $finance['acc_no'],
                'amount' => -self::accountAmount((int) $finance['acc_no'], $paid, $finance, true),
                'seq_price' => self::sequencePrice((int) $finance['acc_no'], $finance),
                'details' => $texts['deposit'],
            ];
        }

        return $lines;
    }

    /**
     * تحويل المبلغ إلى عملة الحساب وفق SELECT1 وأسعار الصرف.
     * Convert an amount into the account currency using SELECT1 and FX rates.
     */
    private static function accountAmount(int $accNo, float $amount, array $finance, bool $creditSide): float
    {
        $currency = (int) substr((string) $accNo, 3, 1);
        $rate = (float) ($creditSide ? ($finance['cur_price1'] ?? 1) : ($finance['cur_price'] ?? 1));
        $rate = $rate > 0 ? $rate : 1.0;
        if ($currency === 1) {
            return round((int) ($finance['select1'] ?? 1) === 1 ? $amount : $amount * $rate, 2);
        }

        return round((int) ($finance['select1'] ?? 1) === 1 ? $amount / $rate : $amount, 2);
    }

    /**
     * مرجع سعر العملة: السعر الحالي للفاتورة أو آخر سطر مناسب.
     * FX sequence reference: current invoice rate or the latest matching line.
     */
    private static function sequencePrice(int $accNo, array $finance): ?int
    {
        $currency = (int) substr((string) $accNo, 3, 1);
        if ($currency === 1) {
            return null;
        }
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT SEQ FROM ACCOUNTS.CUR_PRICE
              WHERE CUR_NO = :cur_no
              ORDER BY DAY_DATE DESC, SEQ DESC',
            ['cur_no' => $currency]
        );

        return $row === [] ? null : (int) reset($row);
    }

    /**
     * إضافة سطر قيد مع لقطة الرصيد قبل الإدراج.
     * Append one journal line with the pre-insert balance snapshot.
     */
    private static function appendLine(
        int $entryNo,
        int $accNo,
        float $amount,
        ?int $seqPrice,
        int $manualNo,
        int $custNo,
        string $details
    ): void {
        $amount = round($amount, 2);
        if (abs($amount) < 0.01) {
            return;
        }
        $snapshot = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(BALANCE_S) KEEP (DENSE_RANK LAST ORDER BY SEQ), 0) AS B
               FROM ACCOUNTS.ENTRY_SUB WHERE ACC_NO = :acc_no',
            ['acc_no' => $accNo]
        );
        $balance = (float) self::first($snapshot);
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_SUB
                (SEQ, ENTRY_NO, ACC_NO, ENTRY_MNT, BALANCE_S, SEQ_PRICE, SUB_DETAILS, INVOICE_NO, GIVING)
             VALUES (:seq, :entry_no, :acc_no, :amount, :balance, :seq_price, :details, :invoice_no, :cust_no)',
            [
                'seq' => TailoringNumbering::nextEntrySeq(),
                'entry_no' => $entryNo,
                'acc_no' => $accNo,
                'amount' => $amount,
                'balance' => round($balance + $amount, 2),
                'seq_price' => $seqPrice,
                'details' => ArabicEncoding::toOracleSearchChars(mb_substr($details, 0, 250)),
                'invoice_no' => $manualNo,
                'cust_no' => $custNo,
            ]
        );
    }

    private static function first(mixed $row): mixed
    {
        return $row === null ? null : array_values((array) $row)[0] ?? null;
    }

    /**
     * إعادة حساب BALANCE_S لكل الحسابات المتأثرة كما في CHECK_BALANCE.
     * Recompute BALANCE_S for affected accounts, matching CHECK_BALANCE.
     */
    private static function rebalance(int $entryNo, array $accounts): void
    {
        $accounts = array_values(array_filter(array_map('intval', $accounts), static fn (int $n): bool => $n > 0));
        if ($accounts === []) {
            return;
        }
        $marks = [];
        $bindings = [];
        foreach ($accounts as $i => $account) {
            $marks[] = ':acc'.$i;
            $bindings['acc'.$i] = $account;
        }
        DB::connection('oracle')->statement(
            'MERGE INTO ACCOUNTS.ENTRY_SUB e
             USING (
                SELECT es.ROWID AS RID,
                       SUM(es.ENTRY_MNT) OVER (
                           PARTITION BY es.ACC_NO
                           ORDER BY em.ENTRY_DATE, es.SEQ
                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                       ) AS NEW_BALANCE
                  FROM ACCOUNTS.ENTRY_SUB es
                  JOIN ACCOUNTS.ENTRY_MAIN em ON em.ENTRY_NO = es.ENTRY_NO
                 WHERE es.ACC_NO IN ('.implode(', ', $marks).')
             ) s
                ON (e.ROWID = s.RID)
             WHEN MATCHED THEN UPDATE SET e.BALANCE_S = ROUND(s.NEW_BALANCE, 2)',
            $bindings
        );
    }

    /**
     * إضافة سطر فروقات عملة عند بقاء فرق غير صفري.
     * Append an exchange-difference line when a non-zero remainder remains.
     */
    private static function balanceDifference(int $entryNo, int $manualNo, int $custNo, string $custName, array &$affectedAccounts): void
    {
        $sum = (float) self::first(DB::connection('oracle')->selectOne(
            'SELECT NVL(SUM(ENTRY_MNT), 0) AS N FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :entry_no',
            ['entry_no' => $entryNo]
        ));
        if (abs($sum) < 0.01) {
            return;
        }
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT ACC_NO FROM ACCOUNTS.CASH_ACC WHERE TYPE_NO = 12 AND ROWNUM <= 1'
        );
        if ($row === []) {
            throw new \RuntimeException('حساب فروقات العملة غير مهيأ / The exchange-difference account is not configured');
        }
        $accNo = (int) self::first($row);
        self::appendLine($entryNo, $accNo, -$sum, null, $manualNo, $custNo, 'فروقات تعديل فاتورة تفصيل');
        $affectedAccounts[$accNo] = true;
    }
}
