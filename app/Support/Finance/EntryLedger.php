<?php

namespace App\Support\Finance;

use App\Support\ArabicEncoding;
use App\Support\SalesPolicy;
use Illuminate\Support\Facades\DB;

/**
 * دفتر القيود — الكتابة والقراءة الموحّدة على ENTRY_MAIN/ENTRY_SUB والمؤقت ENTRY_MAIN2/ENTRY_MAIN3
 * The journal ledger — unified writes and reads over ENTRY_MAIN/ENTRY_SUB and the temporary
 * ENTRY_MAIN2/ENTRY_MAIN3 pair.
 *
 * ── قواعد مثبتة من البيانات الحية:
 *    • ENTRY_MNT موجب = مدين وسالب = دائن، وبعملة الحساب نفسه (الخانة الرابعة من رقمه).
 *    • سطر الحساب الأجنبي يحمل SEQ_PRICE (سعر الصرف في CUR_PRICE)، وسطر المحلي بلا سعر،
 *      فالتوازن يُقاس بالمعادل المحلي: Σ(ENTRY_MNT × السعر) = 0.
 *    • GIVING = رقم العميل على سطر حسابه، و BALANCE_S لقطة رصيد جارية تُحدَّث كالنظام القديم.
 *    • المؤقت: ENTRY_MNT1 مدين و ENTRY_MNT2 دائن بالمعادل المحلي، و FORGN_MNT المبلغ الأجنبي.
 * ── Rules proved on live rows: positive ENTRY_MNT is a debit, negative a credit, always in the
 *    account's own currency; a foreign-currency line carries SEQ_PRICE while a local line has
 *    none, so balance is measured on local equivalents; GIVING holds the customer number; the
 *    temporary pair stores local debit/credit plus the foreign amount in FORGN_MNT.
 */
final class EntryLedger
{
    /** @var array<int, string>|null */
    private static ?array $currencyNames = null;

    // ── التحقق / validation ─────────────────────────────────────────────

    /**
     * تجهيز سطور القيد والتحقق منها: الحساب موجود وغير موقوف، مدين أو دائن لا الاثنان،
     * السعر للحساب الأجنبي، ثم التوازن بالمعادل المحلي.
     * Prepare and validate entry lines: known, non-stopped account; debit xor credit; a rate for
     * foreign accounts; then the local-equivalent balance.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array{ok: bool, message: string, errors: array<string, string>, lines: array<int, array<string, mixed>>}
     */
    public static function normalizeLines(array $input, int $minLines = 2): array
    {
        $fail = static fn (string $msg, array $errors = []): array => [
            'ok' => false, 'message' => $msg, 'errors' => $errors, 'lines' => [],
        ];

        if (count($input) < $minLines) {
            return $fail("القيد يحتاج {$minLines} سطرين على الأقل / An entry needs at least {$minLines} lines", ['lines' => 'min']);
        }

        if (count($input) > FinancePolicy::MAX_LINES) {
            return $fail('عدد السطور يتجاوز الحد المسموح / Too many lines', ['lines' => 'max']);
        }

        $accNos = array_values(array_unique(array_map(static fn ($l): int => (int) ($l['acc_no'] ?? 0), $input)));
        $accounts = self::accounts($accNos);
        $lines = [];
        $priceCache = [];

        foreach (array_values($input) as $i => $line) {
            $row = $i + 1;
            $accNo = (int) ($line['acc_no'] ?? 0);
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($accNo <= 0 || ! isset($accounts[$accNo])) {
                return $fail("الحساب في السطر {$row} غير موجود / Unknown account on line {$row}", ["lines.{$i}.acc_no" => 'unknown']);
            }

            if ($accounts[$accNo]['stopped']) {
                return $fail("الحساب في السطر {$row} موقوف / The account on line {$row} is stopped", ["lines.{$i}.acc_no" => 'stopped']);
            }

            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                return $fail("السطر {$row}: أدخل مديناً أو دائناً فقط / Line {$row}: enter either a debit or a credit", ["lines.{$i}" => 'debit_xor_credit']);
            }

            $curNo = FinancePolicy::currencyOf($accNo);
            $seqPrice = null;
            $price = 1.0;

            if ($curNo !== FinancePolicy::LOCAL_CURRENCY) {
                $requested = isset($line['seq_price']) && $line['seq_price'] !== '' ? (int) $line['seq_price'] : null;
                $key = $curNo.':'.($requested ?? 0);
                $priceCache[$key] ??= $requested !== null ? self::priceOf($requested) : self::latestPrice($curNo);
                $rate = $priceCache[$key];

                if ($rate === null || (int) $rate['cur_no'] !== $curNo || (float) $rate['price'] <= 0) {
                    return $fail("لا يوجد سعر صرف صالح لعملة السطر {$row} / No valid exchange rate for line {$row}", ["lines.{$i}.seq_price" => 'missing']);
                }

                $seqPrice = (int) $rate['seq'];
                $price = (float) $rate['price'];
            }

            $amount = $debit > 0 ? $debit : -$credit;
            $custNo = isset($line['cust_no']) && $line['cust_no'] !== '' && (int) $line['cust_no'] > 0 ? (int) $line['cust_no'] : null;

            $lines[] = [
                'acc_no' => $accNo,
                'acc_name' => $accounts[$accNo]['acc_name'],
                'cur_no' => $curNo,
                'amount' => $amount,
                'price' => $price,
                'local' => round($amount * $price, 2),
                'seq_price' => $seqPrice,
                'sub_details' => self::clip(trim((string) ($line['sub_details'] ?? '')), 200),
                'cust_no' => $custNo,
            ];
        }

        $balance = round(array_sum(array_column($lines, 'local')), 2);

        if (abs($balance) > FinancePolicy::BALANCE_TOLERANCE) {
            return $fail("القيد غير متوازن: الفرق {$balance} / The entry is not balanced (difference {$balance})", ['balance' => (string) $balance]);
        }

        return ['ok' => true, 'message' => '', 'errors' => [], 'lines' => $lines];
    }

    /**
     * بطاقات الحسابات المطلوبة مفهرسة برقمها
     * The requested accounts keyed by number.
     *
     * @param  array<int, int>  $accNos
     * @return array<int, array{acc_no: int, acc_name: string, stopped: bool}>
     */
    public static function accounts(array $accNos): array
    {
        $accNos = array_values(array_filter($accNos, static fn (int $n): bool => $n > 0));

        if ($accNos === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk($accNos, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::connection('oracle')->select(
                "SELECT ACC_NO, ACC_NAME, STOPPING_ACC FROM ACCOUNTS.CHART_ACC WHERE ACC_NO IN ({$marks})",
                $chunk
            );

            foreach ($rows as $row) {
                $r = array_change_key_case((array) $row, CASE_UPPER);
                $found[(int) $r['ACC_NO']] = [
                    'acc_no' => (int) $r['ACC_NO'],
                    'acc_name' => (string) ArabicEncoding::normalizeStatementText($r['ACC_NAME'] ?? ''),
                    'stopped' => (int) ($r['STOPPING_ACC'] ?? 0) === 1,
                ];
            }
        }

        return $found;
    }

    /** سعر صرف بتسلسله / an exchange rate by its sequence */
    public static function priceOf(int $seq): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT SEQ, CUR_NO, PRICE FROM ACCOUNTS.CUR_PRICE WHERE SEQ = :s',
            ['s' => $seq]
        );

        return $row === null ? null : self::priceRow((array) $row);
    }

    /** أحدث سعر صرف لعملة / the latest exchange rate of a currency */
    public static function latestPrice(int $curNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT * FROM (SELECT SEQ, CUR_NO, PRICE FROM ACCOUNTS.CUR_PRICE WHERE CUR_NO = :c ORDER BY DAY_DATE DESC, SEQ DESC) WHERE ROWNUM = 1',
            ['c' => $curNo]
        );

        return $row === null ? null : self::priceRow((array) $row);
    }

    /** أسماء العملات مفهرسة برقمها / currency names keyed by number */
    public static function currencyNames(): array
    {
        if (self::$currencyNames !== null) {
            return self::$currencyNames;
        }

        $names = [];

        foreach (DB::connection('oracle')->select('SELECT CUR_NO, CUR_NAME FROM ACCOUNTS.CURRENCY') as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $names[(int) $r['CUR_NO']] = (string) ArabicEncoding::normalizeStatementText($r['CUR_NAME'] ?? '');
        }

        return self::$currencyNames = $names;
    }

    // ── الكتابة على المرحل / posted writes ───────────────────────────────

    /** إدراج رأس قيد مرحل / insert a posted entry header */
    public static function insertHeader(int $entryNo, string $details, string $date, int $typeNo): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_MAIN (ENTRY_NO, DETAILS, ENTRY_DATE, ENTRY_TYPE_NO)
             VALUES (:entry_no, :details, TO_DATE(:entry_date, \'YYYY-MM-DD\'), :type_no)',
            [
                'entry_no' => $entryNo,
                'details' => self::arabic(self::clip($details, 250) ?: '-'),
                'entry_date' => $date,
                'type_no' => $typeNo,
            ]
        );
    }

    /** تحديث رأس قيد مرحل (البيان والتاريخ والنوع) / update a posted header */
    public static function updateHeader(int $entryNo, string $details, string $date, ?int $typeNo = null): void
    {
        $sql = 'UPDATE ACCOUNTS.ENTRY_MAIN SET DETAILS = :details, ENTRY_DATE = TO_DATE(:entry_date, \'YYYY-MM-DD\')';
        $bindings = [
            'details' => self::arabic(self::clip($details, 250) ?: '-'),
            'entry_date' => $date,
            'entry_no' => $entryNo,
        ];

        if ($typeNo !== null) {
            $sql .= ', ENTRY_TYPE_NO = :type_no';
            $bindings['type_no'] = $typeNo;
        }

        DB::connection('oracle')->update($sql.' WHERE ENTRY_NO = :entry_no', $bindings);
    }

    /**
     * إدراج سطور قيد مرحل — كل سطر بتسلسل عام جديد ولقطة رصيد جارية
     * Insert posted lines — each with a fresh global SEQ and a running balance snapshot.
     *
     * @param  array<int, array{acc_no: int, amount: float, seq_price: ?int, sub_details: ?string, cust_no: ?int}>  $lines
     */
    public static function insertLines(int $entryNo, array $lines, ?int $invoiceNo = null): void
    {
        foreach ($lines as $line) {
            $accNo = (int) $line['acc_no'];
            $amount = round((float) $line['amount'], 2);

            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.ENTRY_SUB
                     (SEQ, ENTRY_NO, ACC_NO, ENTRY_MNT, BALANCE_S, SEQ_PRICE, SUB_DETAILS, INVOICE_NO, GIVING)
                 VALUES (:seq, :entry_no, :acc_no, :amount, :balance, :seq_price, :details, :invoice_no, :giving)',
                [
                    'seq' => SalesPolicy::nextEntrySeq(),
                    'entry_no' => $entryNo,
                    'acc_no' => $accNo,
                    'amount' => $amount,
                    'balance' => round(self::snapshot($accNo) + $amount, 2),
                    'seq_price' => $line['seq_price'] ?? null,
                    'details' => self::arabic(self::clip((string) ($line['sub_details'] ?? ''), 250)),
                    'invoice_no' => $invoiceNo,
                    'giving' => $line['cust_no'] ?? null,
                ]
            );
        }
    }

    /** حذف سطور قيد مرحل / delete a posted entry's lines */
    public static function deleteLines(int $entryNo): void
    {
        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_SUB WHERE ENTRY_NO = :e', ['e' => $entryNo]);
    }

    /**
     * حذف قيد مرحل كاملاً: السطور، ثم صف التدقيق القديم (مفتاح أجنبي)، ثم الرأس
     * Delete a whole posted entry: lines, then the legacy audit row (a foreign key), then header.
     */
    public static function deleteEntry(int $entryNo): void
    {
        if ($entryNo <= 0) {
            return;
        }

        self::deleteLines($entryNo);
        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_MAIN_PRIVILAGE WHERE ENTRY_NO = :e', ['e' => $entryNo]);
        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :e', ['e' => $entryNo]);
    }

    // ── القراءة / reads ────────────────────────────────────────────────

    /** رأس قيد مرحل مع اسم نوعه ومصدره / a posted header with its type name and source */
    public static function header(int $entryNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            "SELECT m.ENTRY_NO, m.DETAILS, TO_CHAR(m.ENTRY_DATE,'YYYY-MM-DD') AS ENTRY_DATE_ISO,
                    m.ENTRY_TYPE_NO, t.ENTRY_TYPE_NAME, t.SOURCE_NAME
               FROM ACCOUNTS.ENTRY_MAIN m
               LEFT JOIN ACCOUNTS.ENTRY_TYPE t ON t.ENTRY_TYPE_NO = m.ENTRY_TYPE_NO
              WHERE m.ENTRY_NO = :e",
            ['e' => $entryNo]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * سطور قيد مرحل بأسماء الحسابات والعملاء والمعادل المحلي
     * A posted entry's lines with account/customer names and local equivalents.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function readLines(int $entryNo): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT s.SEQ, s.ACC_NO, s.ENTRY_MNT, s.SEQ_PRICE, s.SUB_DETAILS, s.INVOICE_NO, s.GIVING,
                    c.ACC_NAME, p.PRICE,
                    (SELECT MAX(u.CUST_NAME) FROM ACCOUNTS.CUST u WHERE u.CUST_NO = s.GIVING) AS CUST_NAME
               FROM ACCOUNTS.ENTRY_SUB s
               LEFT JOIN ACCOUNTS.CHART_ACC c ON c.ACC_NO = s.ACC_NO
               LEFT JOIN ACCOUNTS.CUR_PRICE p ON p.SEQ = s.SEQ_PRICE
              WHERE s.ENTRY_NO = :e
              ORDER BY s.SEQ',
            ['e' => $entryNo]
        );

        return array_map(static function ($row): array {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $accNo = (int) $r['ACC_NO'];
            $amount = (float) ($r['ENTRY_MNT'] ?? 0);
            $curNo = FinancePolicy::currencyOf($accNo);
            $price = $curNo !== FinancePolicy::LOCAL_CURRENCY && isset($r['PRICE']) && (float) $r['PRICE'] > 0
                ? (float) $r['PRICE'] : 1.0;

            return self::lineView([
                'seq' => (int) $r['SEQ'],
                'acc_no' => $accNo,
                'acc_name' => $r['ACC_NAME'] ?? null,
                'amount' => $amount,
                'price' => $price,
                'seq_price' => isset($r['SEQ_PRICE']) ? (int) $r['SEQ_PRICE'] : null,
                'sub_details' => $r['SUB_DETAILS'] ?? null,
                'cust_no' => isset($r['GIVING']) ? (int) $r['GIVING'] : null,
                'cust_name' => $r['CUST_NAME'] ?? null,
                'invoice_no' => isset($r['INVOICE_NO']) ? (int) $r['INVOICE_NO'] : null,
            ]);
        }, $rows);
    }

    /**
     * عرض سطر موحّد (مرحل أو مؤقت) — المبلغ بعملة الحساب ومعادله المحلي
     * A unified line view (posted or temporary) — the account-currency amount and its local twin.
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function lineView(array $l): array
    {
        $amount = round((float) $l['amount'], 2);
        $local = round($amount * (float) $l['price'], 2);
        $curNo = FinancePolicy::currencyOf((int) $l['acc_no']);
        $names = self::currencyNames();

        return [
            'seq' => $l['seq'] ?? null,
            'acc_no' => (int) $l['acc_no'],
            'acc_name' => ArabicEncoding::normalizeStatementText($l['acc_name'] ?? ''),
            'cur_no' => $curNo,
            'cur_name' => $names[$curNo] ?? null,
            'debit' => $amount > 0 ? $amount : 0.0,
            'credit' => $amount < 0 ? -$amount : 0.0,
            'price' => (float) $l['price'],
            'seq_price' => $l['seq_price'] ?? null,
            'local_debit' => $local > 0 ? $local : 0.0,
            'local_credit' => $local < 0 ? -$local : 0.0,
            'sub_details' => ArabicEncoding::normalizeStatementText($l['sub_details'] ?? ''),
            'cust_no' => $l['cust_no'] ?? null,
            'cust_name' => ArabicEncoding::normalizeStatementText($l['cust_name'] ?? ''),
            'invoice_no' => $l['invoice_no'] ?? null,
        ];
    }

    /**
     * مجاميع سطور العرض بالمعادل المحلي
     * Totals of the viewed lines in local equivalents.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{debit: float, credit: float, difference: float}
     */
    public static function totals(array $lines): array
    {
        $debit = round(array_sum(array_column($lines, 'local_debit')), 2);
        $credit = round(array_sum(array_column($lines, 'local_credit')), 2);

        return ['debit' => $debit, 'credit' => $credit, 'difference' => round($debit - $credit, 2)];
    }

    // ── المؤقت / temporary ─────────────────────────────────────────────

    /** هل القيد المؤقت موجود؟ / does the temporary entry exist? */
    public static function tempHeader(int $entryNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            "SELECT ENTRY_NO, RTRIM(DETAILS) AS DETAILS, TO_CHAR(ENTRY_DATE,'YYYY-MM-DD') AS ENTRY_DATE_ISO, PERIODIC_ENTRY
               FROM ACCOUNTS.ENTRY_MAIN2 WHERE ENTRY_NO = :e",
            ['e' => $entryNo]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * كتابة قيد مؤقت كاملاً (الرأس + السطور) — السطور مُطبَّعة مسبقاً بـ normalizeLines
     * Write a whole temporary entry (header + lines); lines come pre-normalized.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function insertTemp(int $entryNo, string $details, string $date, bool $periodic, array $lines): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.ENTRY_MAIN2 (ENTRY_NO, DETAILS, ENTRY_DATE, PERIODIC_ENTRY)
             VALUES (:entry_no, :details, TO_DATE(:entry_date, \'YYYY-MM-DD\'), :periodic)',
            [
                'entry_no' => $entryNo,
                'details' => self::arabic(self::clip($details, 200) ?: '-'),
                'entry_date' => $date,
                'periodic' => $periodic ? 1 : 0,
            ]
        );

        self::insertTempLines($entryNo, $lines);
    }

    /** تحديث رأس المؤقت / update the temporary header */
    public static function updateTempHeader(int $entryNo, string $details, string $date, bool $periodic): void
    {
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.ENTRY_MAIN2
                SET DETAILS = :details, ENTRY_DATE = TO_DATE(:entry_date, \'YYYY-MM-DD\'), PERIODIC_ENTRY = :periodic
              WHERE ENTRY_NO = :entry_no',
            [
                'details' => self::arabic(self::clip($details, 200) ?: '-'),
                'entry_date' => $date,
                'periodic' => $periodic ? 1 : 0,
                'entry_no' => $entryNo,
            ]
        );
    }

    /**
     * سطور المؤقت: المدين/الدائن بالمعادل المحلي و FORGN_MNT للمبلغ الأجنبي، وتسلسل 1..n
     * Temporary lines: local debit/credit, FORGN_MNT for the foreign amount, and SEQ 1..n.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function insertTempLines(int $entryNo, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $local = (float) $line['local'];
            $foreign = (int) $line['cur_no'] !== FinancePolicy::LOCAL_CURRENCY ? abs((float) $line['amount']) : null;

            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.ENTRY_MAIN3
                     (ENTRY_NO, ACC, ENTRY_MNT1, ENTRY_MNT2, ACC_NO, FORGN_MNT, SEQ_PRICE, SEQ, SUB_DETAILS, CUST_NO)
                 VALUES (:entry_no, :acc, :mnt1, :mnt2, :acc_no, :forgn, :seq_price, :seq, :details, :cust_no)',
                [
                    'entry_no' => $entryNo,
                    'acc' => self::arabic(self::clip((string) $line['acc_name'], 100) ?: (string) $line['acc_no']),
                    'mnt1' => $local > 0 ? round($local, 2) : 0,
                    'mnt2' => $local < 0 ? round(-$local, 2) : 0,
                    'acc_no' => (int) $line['acc_no'],
                    'forgn' => $foreign,
                    'seq_price' => $line['seq_price'] ?? null,
                    'seq' => $i + 1,
                    'details' => self::arabic(self::clip((string) ($line['sub_details'] ?? ''), 200)),
                    'cust_no' => $line['cust_no'] ?? null,
                ]
            );
        }
    }

    /** حذف سطور المؤقت / delete the temporary lines */
    public static function deleteTempLines(int $entryNo): void
    {
        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_MAIN3 WHERE ENTRY_NO = :e', ['e' => $entryNo]);
    }

    /** حذف قيد مؤقت كاملاً / delete a whole temporary entry */
    public static function deleteTemp(int $entryNo): void
    {
        self::deleteTempLines($entryNo);
        DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.ENTRY_MAIN2 WHERE ENTRY_NO = :e', ['e' => $entryNo]);
    }

    /**
     * سطور المؤقت بالشكل الموحّد — المبلغ بعملة الحساب (FORGN_MNT للأجنبي)
     * Temporary lines in the unified shape — the amount in the account currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function readTempLines(int $entryNo): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT t.SEQ, t.ACC_NO, t.ACC, t.ENTRY_MNT1, t.ENTRY_MNT2, t.FORGN_MNT, t.SEQ_PRICE,
                    RTRIM(t.SUB_DETAILS) AS SUB_DETAILS, t.CUST_NO, c.ACC_NAME, p.PRICE,
                    (SELECT MAX(u.CUST_NAME) FROM ACCOUNTS.CUST u WHERE u.CUST_NO = t.CUST_NO) AS CUST_NAME
               FROM ACCOUNTS.ENTRY_MAIN3 t
               LEFT JOIN ACCOUNTS.CHART_ACC c ON c.ACC_NO = t.ACC_NO
               LEFT JOIN ACCOUNTS.CUR_PRICE p ON p.SEQ = t.SEQ_PRICE
              WHERE t.ENTRY_NO = :e
              ORDER BY t.SEQ',
            ['e' => $entryNo]
        );

        return array_map(static function ($row): array {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $accNo = (int) $r['ACC_NO'];
            $local = (float) ($r['ENTRY_MNT1'] ?? 0) - (float) ($r['ENTRY_MNT2'] ?? 0);
            $foreign = FinancePolicy::currencyOf($accNo) !== FinancePolicy::LOCAL_CURRENCY && isset($r['FORGN_MNT']);
            $price = $foreign && isset($r['PRICE']) && (float) $r['PRICE'] > 0 ? (float) $r['PRICE'] : 1.0;
            $amount = $foreign ? ($local >= 0 ? 1 : -1) * abs((float) $r['FORGN_MNT']) : $local;

            return self::lineView([
                'seq' => (int) $r['SEQ'],
                'acc_no' => $accNo,
                'acc_name' => $r['ACC_NAME'] ?? $r['ACC'] ?? null,
                'amount' => $amount,
                'price' => $price,
                'seq_price' => isset($r['SEQ_PRICE']) ? (int) $r['SEQ_PRICE'] : null,
                'sub_details' => $r['SUB_DETAILS'] ?? null,
                'cust_no' => isset($r['CUST_NO']) ? (int) $r['CUST_NO'] : null,
                'cust_name' => $r['CUST_NAME'] ?? null,
                'invoice_no' => null,
            ]);
        }, $rows);
    }

    // ── مساعدات / helpers ──────────────────────────────────────────────

    /** العربية بصيغة الكتابة في أوراكل (CP1256 عبر Latin-1) / Arabic in the Oracle write form */
    public static function arabic(?string $text): ?string
    {
        return $text === null || $text === '' ? $text : ArabicEncoding::toOracleSearchChars($text);
    }

    /** قصّ نص لسعة العمود (CP1256 = بايت لكل محرف) / clip to the column capacity */
    public static function clip(?string $text, int $limit): string
    {
        $text = (string) $text;

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }

    /** آخر لقطة رصيد للحساب / the account's latest balance snapshot */
    private static function snapshot(int $accNo): float
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(BALANCE_S) KEEP (DENSE_RANK LAST ORDER BY SEQ), 0) AS B
               FROM ACCOUNTS.ENTRY_SUB WHERE ACC_NO = :a',
            ['a' => $accNo]
        );

        return (float) (reset($row) ?: 0);
    }

    /** @param  array<string, mixed>  $row */
    private static function priceRow(array $row): array
    {
        $r = array_change_key_case($row, CASE_UPPER);

        return ['seq' => (int) $r['SEQ'], 'cur_no' => (int) $r['CUR_NO'], 'price' => (float) $r['PRICE']];
    }
}
