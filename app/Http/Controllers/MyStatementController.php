<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ArabicEncoding;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم الكشوفات الشخصية — مالي وعمل — خاص بالموظف الحالي فقط
 * Personal statements controller: financial and work statements scoped to the authenticated user.
 *
 * لا يقبل أي بارامتر معرّف للحساب — يقرأ ACC_NO من صف المستخدم المصادق عليه حصراً،
 * مما يمنع أي مستخدم من عرض كشف حساب موظف آخر.
 * It accepts NO account identifier — it reads ACC_NO from the authenticated user's row
 * exclusively, preventing any user from viewing another employee's statement.
 */
class MyStatementController extends Controller
{
    // ===================================================
    // كشف الحساب المالي الشخصي
    // Personal financial statement
    // ===================================================

    /**
     * GET /my-statement/financial?from_date=&to_date=&page=&limit=
     *
     * يعيد كشف حركات الحساب المالي المرتبط بالمستخدم الحالي.
     * Returns ledger transactions for the authenticated user's linked financial account.
     *
     * `limit` غير المُمرَّر = كل حركات الفترة. الافتراضي القديم (50) كان يقتطع الكشف.
     * Omitted `limit` returns every movement of the period; the old 50-row default
     * truncated the statement.
     */
    public function financialStatement(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accNo = $user->acc_no ?? $user->ACC_NO;

        // التحقق من وجود حساب مالي مرتبط
        if (! $accNo) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد حساب مالي مرتبط بحسابك — تواصل مع الإدارة لربطه',
            ], 422);
        }

        try {
            // جلب بيانات الحساب من CHART_ACC (بلا عمود BALANCE المخزّن — رصيد الحساب
            // يُحسب من الحركات أدناه)
            $accRow = DB::connection('oracle')->selectOne(
                'SELECT ACC_NO, ACC_NAME, GROUP_ID FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :no',
                ['no' => $accNo]
            );
            if (! $accRow) {
                return response()->json([
                    'success' => false,
                    'message' => 'الحساب المالي المرتبط بك غير موجود في الدليل',
                ], 422);
            }
            $accArr = (array) $accRow;

            // ── الترقيم: غياب `limit` (أو limit=0) يعني «كل حركات الفترة» ──
            // الافتراضي القديم 50 سطراً كان يقتطع الكشف فيبدو أن الفترة مُتجاهَلة.
            // Absent `limit` (or limit=0) returns every movement of the period; the old
            // 50-row default truncated the statement and looked like a broken date filter.
            $page = max(1, (int) $request->query('page', 1));
            $limitRaw = $request->query('limit');
            $limitValue = ($limitRaw === null || $limitRaw === '') ? 0 : (int) $limitRaw;
            $limit = $limitValue > 0 ? min(2000, $limitValue) : 0;
            $offset = $limit > 0 ? ($page - 1) * $limit : 0;

            // ── جلب كامل دفتر حركات الحساب مرتباً زمنياً ──
            // Fetch full ledger ordered chronologically (filtered in PHP for opening balance).
            //
            // عمود البيان: تفصيل السطر الفرعي أولاً وإن كان فارغاً بيان القيد الرئيسي.
            // ولا يُقرأ ENTRY_SUB.BALANCE_S إطلاقاً — الرصيد يُشتق من ENTRY_MNT.
            // Description: sub-entry text first, main entry text as fallback; the stored
            // BALANCE_S snapshot is never read — the balance comes from ENTRY_MNT.
            $allSql = '
                SELECT es.ENTRY_NO,
                       SUBSTR(TO_CHAR(es.ENTRY_NO), 1, 1) || SUBSTR(TO_CHAR(es.ENTRY_NO), 7, 4) AS SUB_ENTRY_NO,
                       TO_CHAR(em.ENTRY_DATE, \'DD/MM/YYYY\') AS ENTRY_DATE,
                       TO_CHAR(em.ENTRY_DATE, \'YYYY-MM-DD\') AS RAW_DATE,
                       et.ENTRY_TYPE_NAME,
                       NVL(TRIM(es.SUB_DETAILS), em.DETAILS) AS DETAILS,
                       es.ENTRY_MNT,
                       es.SEQ_PRICE
                FROM ACCOUNTS.ENTRY_MAIN em, ACCOUNTS.ENTRY_SUB es, ACCOUNTS.ENTRY_TYPE et
                WHERE es.ACC_NO = :acc_no
                  AND es.ENTRY_NO = em.ENTRY_NO
                  AND em.ENTRY_TYPE_NO = et.ENTRY_TYPE_NO
                ORDER BY em.ENTRY_DATE, es.SEQ
            ';
            $rows = DB::connection('oracle')->select($allSql, ['acc_no' => $accNo]);

            // ── الفترة الافتراضية: آخر 30 يوم قبل تاريخ آخر حركة ──
            $defaulted = false;
            $fromDateParam = $request->query('from_date');
            $toDateParam = $request->query('to_date');

            if (! $fromDateParam && ! $toDateParam && ! empty($rows)) {
                $lastRawDate = null;
                foreach ($rows as $lastRow) {
                    $lr = (array) $lastRow;
                    $lastRawDate = $lr['RAW_DATE'] ?? $lr['raw_date'] ?? $lastRawDate;
                }
                if ($lastRawDate) {
                    try {
                        $lastDate = Carbon::parse($lastRawDate);
                        $fromDateParam = $lastDate->copy()->subDays(30)->format('Y-m-d');
                        $toDateParam = $lastDate->format('Y-m-d');
                        $defaulted = true;
                    } catch (\Throwable) {
                        $fromDateParam = null;
                        $toDateParam = null;
                    }
                }
            }

            $fromDateFilter = $fromDateParam ? $this->parseDateToIso($fromDateParam) : null;
            $toDateFilter = $toDateParam ? $this->parseDateToIso($toDateParam) : null;

            $openingBalance = 0.0;
            $runningBalance = 0.0;
            $periodMovement = 0.0;
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $firstDate = null;
            $lastDate = null;
            // رصيد الحساب النهائي: صافي كل حركاته بلا فلتر تاريخ (يُعرض بجانب الحساب)
            // Final account balance: net of every movement, date filters ignored.
            $ledgerBalance = 0.0;
            $allEntries = [];

            foreach ($rows as $row) {
                $r = (array) $row;
                $rawDate = $r['RAW_DATE'] ?? $r['raw_date'] ?? null;
                $entryDate = $r['ENTRY_DATE'] ?? $r['entry_date'] ?? null;
                $mnt = (float) ($r['ENTRY_MNT'] ?? $r['entry_mnt'] ?? 0);

                $ledgerBalance += $mnt;

                // الحركات الأقدم من بداية الفترة تُحسب ضمن الرصيد الافتتاحي فقط
                if ($fromDateFilter && $rawDate && $rawDate < $fromDateFilter) {
                    $openingBalance += $mnt;
                    $runningBalance = $openingBalance;

                    continue;
                }
                if ($toDateFilter && $rawDate && $rawDate > $toDateFilter) {
                    continue;
                }

                if ($firstDate === null) {
                    $firstDate = $entryDate;
                }
                $lastDate = $entryDate;

                $debit = $mnt > 0 ? $mnt : 0.0;
                $credit = $mnt < 0 ? abs($mnt) : 0.0;

                $totalDebit += $debit;
                $totalCredit += $credit;

                // الرصيد الجاري = الافتتاحي + صافي حركة الفترة التراكمية
                // Running balance = opening balance + cumulative period movement.
                $periodMovement += $mnt;
                $runningBalance = $openingBalance + $periodMovement;

                $allEntries[] = [
                    'entry_no' => (int) ($r['ENTRY_NO'] ?? $r['entry_no'] ?? 0),
                    'sub_entry_no' => (string) ($r['SUB_ENTRY_NO'] ?? $r['sub_entry_no'] ?? ''),
                    'date' => $entryDate,
                    'entry_type_name' => ArabicEncoding::fixMojibake($r['ENTRY_TYPE_NAME'] ?? $r['entry_type_name'] ?? ''),
                    'description' => ArabicEncoding::normalizeStatementText($r['DETAILS'] ?? $r['details'] ?? ''),
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => $runningBalance,
                ];
            }

            // ── الترقيم اليدوي بعد الانتهاء من حساب الرصيد الافتتاحي ──
            // بلا `limit` تُعاد كل حركات الفترة؛ لا اقتطاع إطلاقاً.
            // Without a `limit` every movement of the period is returned — no truncation.
            $total = count($allEntries);
            $transactions = $limit > 0 ? array_slice($allEntries, $offset, $limit) : $allEntries;

            // الرصيد النهائي = الافتتاحي + صافي حركة الفترة (بلا أي عمود مخزَّن)
            // Final balance = opening balance + net period movement, never a stored column.
            $finalBalance = $openingBalance + $periodMovement;

            // ── العملة من آخر حركة ──
            $currency = 'يمني YR';
            try {
                $lastPriceSeq = null;
                foreach ($rows as $row) {
                    $r = (array) $row;
                    $sp = $r['SEQ_PRICE'] ?? $r['seq_price'] ?? null;
                    if ($sp) {
                        $lastPriceSeq = $sp;
                    }
                }
                if ($lastPriceSeq) {
                    $curRow = DB::connection('oracle')->selectOne(
                        'SELECT cc.CODE, c.CUR_NAME FROM ACCOUNTS.CUR_PRICE cp
                         JOIN ACCOUNTS.CURRENCY c ON c.CUR_NO = cp.CUR_NO
                         JOIN ACCOUNTS.CUR_CODE cc ON cc.CUR_NO = cp.CUR_NO
                         WHERE cp.SEQ = :seq',
                        ['seq' => $lastPriceSeq]
                    );
                    if ($curRow) {
                        $cr = (array) $curRow;
                        $curName = ArabicEncoding::fixMojibake($cr['CUR_NAME'] ?? $cr['cur_name'] ?? '');
                        $curCode = $cr['CODE'] ?? $cr['code'] ?? '';
                        if ($curName !== '' || $curCode !== '') {
                            $currency = trim($curName.' '.$curCode);
                        }
                    }
                }
            } catch (\Exception) {
                // fallback to default
            }

            $accName = ArabicEncoding::fixMojibake($accArr['ACC_NAME'] ?? $accArr['acc_name'] ?? '');

            return response()->json([
                'success' => true,
                'data' => [
                    'account' => [
                        'acc_no' => (int) ($accArr['ACC_NO'] ?? $accArr['acc_no'] ?? $accNo),
                        'acc_name' => $accName,
                        // الرصيد النهائي المحسوب من الحركات (لا CHART_ACC.BALANCE)
                        // Computed final balance, never the stored column.
                        'balance' => $ledgerBalance,
                        'group_id' => isset($accArr['GROUP_ID']) || isset($accArr['group_id'])
                            ? (int) ($accArr['GROUP_ID'] ?? $accArr['group_id'])
                            : null,
                    ],
                    'transactions' => $transactions,
                    'summary' => [
                        'opening_balance' => $openingBalance,
                        'final_balance' => $finalBalance,
                        'total_debit' => $totalDebit,
                        'total_credit' => $totalCredit,
                        'current_balance' => $finalBalance,
                        'total_records' => $total,
                        'currency' => $currency,
                        'from_date' => $fromDateParam ?: ($firstDate ?? ''),
                        'to_date' => $toDateParam ?: ($lastDate ?? ''),
                        'defaulted' => $defaulted,
                    ],
                ],
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    // 0 = بلا ترقيم (كل صفوف الفترة) / 0 means "every row, not paginated".
                    'limit' => $limit,
                    'returned' => count($transactions),
                    'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                    'has_more' => $limit > 0 && ($page * $limit) < $total,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطأ في جلب كشف الحساب المالي',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ===================================================
    // كشف العمل الشخصي (خياط / قصاص)
    // Personal work report (tailor / cutter)
    // ===================================================

    /**
     * GET /my-statement/work?from_date=&to_date=&report_type=calc|uncalc
     *
     * يحدد نوع العامل (خياط أو قصاص) من جداول CALC عبر ACC_NO ويعيد كشف العمل.
     * Auto-detects the worker type (tailor or cutter) via ACC_NO in the CALC tables
     * and returns the work report.
     *
     * نوع التقرير (`report_type`):
     * - `calc`   (الافتراضي): الكشف المرحل من `*_FROM_CALC` — الوضع الحالي كما هو.
     * - `uncalc` : الكشف الغير مرحل من `*_FROM_UNCALC` — سطور العمل قبل ترحيلها للحساب.
     *   والفلترة على NVL(تاريخ العمل, DDATE) لأن غير المرحل كثيراً ما يأتي بلا تاريخ عمل.
     *   Report type: posted from `*_FROM_CALC` (default) or unposted from
     *   `*_FROM_UNCALC`; date filtering uses NVL(work date, DDATE) because unposted
     *   rows often lack the work date while the document date is always set.
     */
    public function workStatement(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accNo = $user->acc_no ?? $user->ACC_NO;

        if (! $accNo) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد حساب مالي مرتبط بحسابك — تواصل مع الإدارة لربطه',
            ], 422);
        }

        try {
            // ── نوع التقرير: المرحل (calc) أو الغير مرحل (uncalc) ──
            // القيمة الحالية والافتراضية هي calc (المرحل فقط كما كان سابقاً)؛ أي قيمة
            // غير معروفة تعود إلى calc (قائمة بيضاء قبل وصولها للاستعلام).
            // Report type: posted (calc) or unposted (uncalc). Default stays calc —
            // exactly the previous behaviour; unknown values fall back to calc
            // (whitelisted before the query is built).
            $reportType = strtolower(trim((string) ($request->input('report_type') ?? 'calc')));
            if (! in_array($reportType, ['calc', 'uncalc'], true)) {
                $reportType = 'calc';
            }
            $reportTypeLabel = $reportType === 'uncalc' ? 'الغير مرحل' : 'المرحل';

            // ── تحديد نوع العامل: خياط أم قصاص ──
            // العد يشمل المرحل (CALC) وغير المرحل (UNCALC) معاً — قد تكون كل سطور
            // العامل غير مرحلة بعد؛ الخياط له أولوية إن وُجد في كليهما (نفس القاعدة السابقة).
            // Detect worker type from the work tables. The count spans CALC (posted) and
            // UNCALC (unposted) together — a worker may have every row still unposted;
            // tailor keeps its priority when present in both.
            $tailorCount = $this->countWorkRows('ACCOUNTS.TAILOR_FROM_CALC', 'TAILOR_ACC', $accNo)
                + $this->countWorkRows('ACCOUNTS.TAILOR_FROM_UNCALC', 'TAILOR_ACC', $accNo);

            $cutterCount = $this->countWorkRows('ACCOUNTS.CUTTER_FROM_CALC', 'CUTTER_ACC', $accNo)
                + $this->countWorkRows('ACCOUNTS.CUTTER_FROM_UNCALC', 'CUTTER_ACC', $accNo);

            // إذا لم يوجد أي سجل عمل → خطأ
            if ($tailorCount === 0 && $cutterCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'لم يتم العثور على سجل عمل مرتبط بحسابك',
                ], 422);
            }

            // تحديد الإعدادات حسب النوع (الخياط له أولوية إن وُجد في كلاهما)
            $isTailor = $tailorCount > 0;
            // جدول الكشف المطلوب: CALC للمرحل (الافتراضي) و UNCALC لغير المرحل — نفس الأعمدة.
            // Requested source: CALC for posted (default), UNCALC for unposted — identical columns.
            $calcTable = $isTailor
                ? ($reportType === 'uncalc' ? 'ACCOUNTS.TAILOR_FROM_UNCALC' : 'ACCOUNTS.TAILOR_FROM_CALC')
                : ($reportType === 'uncalc' ? 'ACCOUNTS.CUTTER_FROM_UNCALC' : 'ACCOUNTS.CUTTER_FROM_CALC');
            $accCol = $isTailor ? 'TAILOR_ACC' : 'CUTTER_ACC';
            $nameCol = $isTailor ? 'TAILOR_NAME' : 'CUTTER_NAME';
            $dateCol = $isTailor ? 'TAILOR_DATE' : 'CUTTING_DATE';
            $styleCol = 'STYLE_NAME';
            $qtyCol = 'POST';
            $totalCol = $isTailor ? 'AMT_ALL' : 'AMT';
            $workerType = $isTailor ? 'tailors' : 'cutters';
            $workerTypeLabel = $isTailor ? 'الخياط' : 'القصاص';

            // ── نطاق التواريخ ──
            $fromDateParam = trim((string) ($request->input('from_date') ?? ''));
            $toDateParam = trim((string) ($request->input('to_date') ?? ''));

            // تعبير التاريخ: غير المرحل كثيراً ما يحمل تاريخ عمل فارغاً بينما DDATE (تاريخ
            // المستند) موجود دائماً ويساوي تاريخ العمل عندما يوجد — فلترة/ترتيب على NVL
            // حتى لا تختفي سطور غير المرحلة من أي نطاق زمني.
            // Date expression: unposted rows often lack the work date while DDATE is
            // always set (and equals it when present) — filter/order on the NVL form.
            $dateSql = $reportType === 'uncalc' ? "NVL(w.$dateCol, w.DDATE)" : "w.$dateCol";

            $fromIso = $fromDateParam !== '' ? $this->parseDateToIso($fromDateParam) : null;
            $toIso = $toDateParam !== '' ? $this->parseDateToIso($toDateParam) : null;

            // الافتراضي: تاريخ آخر سجل عمل (عبر نفس تعبير التاريخ — NVL في حالة غير المرحل)
            if ($fromIso === null && $toIso === null) {
                $lastRows = DB::connection('oracle')->select(
                    "SELECT TO_CHAR(MAX($dateSql), 'YYYY-MM-DD') AS LAST_DATE
                       FROM $calcTable w WHERE w.$accCol = :acc_no",
                    ['acc_no' => $accNo]
                );
                $lastDateStr = '';
                if ($lastRows) {
                    $lr = (array) $lastRows[0];
                    $lastDateStr = trim((string) ($lr['LAST_DATE'] ?? $lr['last_date'] ?? ''));
                }
                $defaultIso = $lastDateStr !== '' ? $lastDateStr : Carbon::today()->format('Y-m-d');
                $fromIso = $defaultIso;
                $toIso = $defaultIso;
            } else {
                $fromIso ??= $toIso;
                $toIso ??= $fromIso;
            }

            if ($fromIso && $toIso && $fromIso > $toIso) {
                $fromIso = $toIso;
            }

            // ── استعلام كشف العمل ──
            $sql = "
                SELECT TO_CHAR($dateSql, 'DD/MM/YYYY') AS WORK_DATE,
                       w.$nameCol AS WORKER_NAME,
                       w.$styleCol AS STYLE_NAME,
                       NVL(w.$qtyCol, 0) AS QTY,
                       NVL(w.$totalCol, 0) AS TOTAL
                FROM $calcTable w
                WHERE w.$accCol = :acc_no
                  AND $dateSql BETWEEN TO_DATE(:from_date,'YYYY-MM-DD') AND TO_DATE(:to_date,'YYYY-MM-DD') + 1 - 1/86400
                ORDER BY $dateSql, w.$styleCol
            ";

            $raw = DB::connection('oracle')->select($sql, [
                'acc_no' => $accNo,
                'from_date' => $fromIso,
                'to_date' => $toIso,
            ]);

            // ── بناء الأسطر والإجماليات ──
            $rows = [];
            $totalCount = 0;
            $totalAmount = 0.0;
            $workerName = '';

            foreach ($raw as $r) {
                $r = (array) $r;
                $qty = (float) ($r['QTY'] ?? $r['qty'] ?? 0);
                $total = (float) ($r['TOTAL'] ?? $r['total'] ?? 0);
                $unitPrice = $qty > 0 ? $total / $qty : 0.0;

                $totalCount += $qty;
                $totalAmount += $total;

                if ($workerName === '') {
                    $workerName = ArabicEncoding::fixMojibake(trim((string) ($r['WORKER_NAME'] ?? $r['worker_name'] ?? '')));
                }

                $rows[] = [
                    'date' => $r['WORK_DATE'] ?? $r['work_date'] ?? '',
                    'style_name' => ArabicEncoding::fixMojibake(trim((string) ($r['STYLE_NAME'] ?? $r['style_name'] ?? ''))),
                    'count' => $qty,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ];
            }

            // ── العملة ──
            $currency = 'يمني YR';

            return response()->json([
                'success' => true,
                'worker' => [
                    'name' => $workerName,
                    'acc_no' => (string) $accNo,
                    'type' => $workerType,
                    'type_label' => $workerTypeLabel,
                ],
                'data' => $rows,
                'summary' => [
                    'total_rows' => count($rows),
                    'total_count' => $totalCount,
                    'total_amount' => $totalAmount,
                    'from_date' => $fromIso ? Carbon::parse($fromIso)->format('d/m/Y') : '',
                    'to_date' => $toIso ? Carbon::parse($toIso)->format('d/m/Y') : '',
                    'currency' => $currency,
                    'account_title' => "$accNo - $workerName",
                    // نوع الكشف المُستخدم: calc = المرحل (الافتراضي) / uncalc = الغير مرحل
                    // Which source answered: calc = posted (default) / uncalc = unposted.
                    'report_type' => $reportType,
                    'report_type_label' => $reportTypeLabel,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطأ في جلب كشف العمل',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ===================================================
    // مساعدات داخلية
    // ===================================================

    /**
     * عدد سطور عمل عامل في جدول كشف عمل معيّن حسب عمود حسابه
     * Counts a worker's rows in one of the *_FROM_* work tables by its ACC column.
     */
    private function countWorkRows(string $table, string $accCol, $accNo): int
    {
        $rows = DB::connection('oracle')->select(
            "SELECT COUNT(*) AS CNT FROM $table WHERE $accCol = :acc_no",
            ['acc_no' => $accNo]
        );
        if (! $rows) {
            return 0;
        }
        $r = (array) $rows[0];

        return (int) ($r['CNT'] ?? $r['cnt'] ?? 0);
    }

    /**
     * تحويل نص تاريخ (DD/MM/YYYY أو YYYY-MM-DD) إلى YYYY-MM-DD
     * Date normalizer — accepts multiple formats and returns ISO date string.
     */
    private function parseDateToIso(string $dateStr): ?string
    {
        $d = trim($dateStr);
        if (empty($d)) {
            return null;
        }

        try {
            if (str_contains($d, '/')) {
                $parts = explode('/', $d);
                if (count($parts) === 3) {
                    if (strlen($parts[0]) === 4) {
                        return sprintf('%04d-%02d-%02d', (int) $parts[0], (int) $parts[1], (int) $parts[2]);
                    }

                    return sprintf('%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0]);
                }
            }

            return Carbon::parse($d)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
