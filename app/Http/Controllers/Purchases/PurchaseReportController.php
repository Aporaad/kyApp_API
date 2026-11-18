<?php

namespace App\Http\Controllers\Purchases;

use App\Support\ArabicEncoding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تقارير المشتريات — ملخص وتوزيعات وجدول مستندات، كله بمعادل الريال
 * Purchases reports — summary, distributions and a document table, all in riyal equivalents.
 *
 * ── لماذا معادل الريال؟ لأن مبالغ الفاتورة بعملة الحساب الدائن: `NET` بالدولار أو السعودي
 *    لا يُجمع مع `NET` بالريال. المعادل = `NET × CUR_PRICE1` للفاتورة و`NET × CUR_PRICE` للمردود.
 * ── Why riyal equivalents: a bill's NET is in its party account's currency, so dollars and riyals
 *    cannot be summed. The equivalent is NET × CUR_PRICE1 for invoices, NET × CUR_PRICE for returns.
 *
 * ── المردود يُطرح من الفاتورة في «الصافي» ولا يُخلط معها في «الإجمالي»، لأن جمع الاثنين بلا
 *    إشارة يعطي رقماً لا معنى له محاسبياً.
 * ── Returns are subtracted from invoices in the net, never mixed into the gross, because adding
 *    both without a sign produces an accounting number that means nothing.
 */
class PurchaseReportController extends PurchaseBaseController
{
    /** سقف جدول المستندات في تقرير واحد / the document table cap in one report */
    private const MAX_DOCUMENTS = 200;

    public function report(Request $request): JsonResponse
    {
        try {
            $filters = $this->filters($request);
            $invoiceWhere = $this->invoiceWhere($filters);
            $returnWhere = $this->returnWhere($filters);

            $invoiceSummary = $this->summaryRow('ACCOUNTS.BUY_BILL b', 'NVL(b.CUR_PRICE1, 1)', 'TOTAL', $invoiceWhere);
            $returnSummary = $this->summaryRow('ACCOUNTS.RETURN_BUY r', 'NVL(r.CUR_PRICE, 1)', 'RETURN_TOTAL', $returnWhere);

            $monthly = $this->monthlySeries($invoiceWhere, $returnWhere);
            $suppliers = $this->bySupplier($invoiceWhere, $returnWhere);
            $types = $this->byType($invoiceWhere, $returnWhere);
            $currencies = $this->byCurrency($invoiceWhere, $returnWhere);
            $items = $this->topItems($filters);
            $documents = $this->documents($filters);

            $net = round(($invoiceSummary['net'] ?? 0) - ($returnSummary['net'] ?? 0), 2);
            $netBase = round(($invoiceSummary['net_base'] ?? 0) - ($returnSummary['net_base'] ?? 0), 2);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب تقرير المشتريات بنجاح / Purchases report retrieved',
                'data' => ArabicEncoding::cleanData([
                    'filters' => $filters,
                    'summary' => [
                        'invoice_count' => $invoiceSummary['count'] ?? 0,
                        'invoice_total' => $invoiceSummary['total'] ?? 0,
                        'invoice_discount' => $invoiceSummary['discount'] ?? 0,
                        'invoice_net' => $invoiceSummary['net'] ?? 0,
                        'invoice_net_base' => $invoiceSummary['net_base'] ?? 0,
                        'return_count' => $returnSummary['count'] ?? 0,
                        'return_total' => $returnSummary['total'] ?? 0,
                        'return_net' => $returnSummary['net'] ?? 0,
                        'return_net_base' => $returnSummary['net_base'] ?? 0,
                        'net' => $net,
                        'net_base' => $netBase,
                        'average_invoice' => ($invoiceSummary['count'] ?? 0) > 0
                            ? round(($invoiceSummary['net_base'] ?? 0) / $invoiceSummary['count'], 2)
                            : 0,
                    ],
                    'monthly' => $monthly,
                    'suppliers' => $suppliers,
                    'types' => $types,
                    'currencies' => $currencies,
                    'top_items' => $items,
                    'documents' => $documents,
                ]),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب تقرير المشتريات / Failed to retrieve the purchases report', $e);
        }
    }

    /** تسجيل طباعة/تصدير التقرير / log a report print or export */
    public function activity(Request $request): JsonResponse
    {
        $this->logActivity($request, 'purchases.report_exported', self::ENTITY_REPORT, 'purchases', 'طباعة/تصدير تقرير مشتريات');

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل النشاط / Activity logged',
        ], 200);
    }

    // ── الفلاتر ──────────────────────────────────────────────

    /**
     * قراءة الفلاتر وتطبيعها — كلها اختيارية، والمدى الافتراضي سنة كاملة
     * Read and normalise the filters — all optional; the default span is one year.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $from = $this->dateInput($request, 'from_date');
        $to = $this->dateInput($request, 'to_date');

        if ($from === null && $to === null) {
            $today = (array) DB::connection('oracle')->selectOne(
                "SELECT TO_CHAR(SYSDATE, 'YYYY-MM-DD') AS D, TO_CHAR(ADD_MONTHS(SYSDATE, -12), 'YYYY-MM-DD') AS F FROM DUAL"
            );
            $to = (string) ($today['D'] ?? $today['d'] ?? '');
            $from = (string) ($today['F'] ?? $today['f'] ?? '');
        }

        return [
            'from_date' => $from,
            'to_date' => $to,
            'type' => in_array((string) $request->input('type'), ['invoice', 'return', 'all'], true)
                ? (string) $request->input('type')
                : 'all',
            'bill_type' => $request->filled('bill_type') ? (int) $request->input('bill_type') : null,
            'store_no' => $request->filled('store_no') ? (int) $request->input('store_no') : null,
            'cur_no' => $request->filled('cur_no') ? (int) $request->input('cur_no') : null,
            'acc_no' => $request->filled('acc_no') ? (int) $request->input('acc_no') : null,
            'status' => in_array((string) $request->input('status'), ['posted', 'unposted'], true)
                ? (string) $request->input('status')
                : null,
            'min_amount' => $request->filled('min_amount') ? (float) $request->input('min_amount') : null,
            'max_amount' => $request->filled('max_amount') ? (float) $request->input('max_amount') : null,
        ];
    }

    /**
     * شرط WHERE للفواتير مع روابطه
     * The invoice WHERE clause with its bindings.
     *
     * @param  array<string, mixed>  $f
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function invoiceWhere(array $f): array
    {
        $clauses = ['1 = 1'];
        $binds = [];

        if (($f['from_date'] ?? null) !== null) {
            $clauses[] = 'b.DAT >= TO_DATE(:from_date, \'YYYY-MM-DD\')';
            $binds['from_date'] = $f['from_date'];
        }

        if (($f['to_date'] ?? null) !== null) {
            $clauses[] = 'b.DAT < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1';
            $binds['to_date'] = $f['to_date'];
        }

        if ($f['bill_type'] !== null) {
            $clauses[] = 'b.BILL_TYPE = :bill_type';
            $binds['bill_type'] = $f['bill_type'];
        }

        if ($f['store_no'] !== null) {
            $clauses[] = 'b.STORE_NO = :store_no';
            $binds['store_no'] = $f['store_no'];
        }

        if ($f['cur_no'] !== null) {
            $clauses[] = 'b.CUR_NO1 = :cur_no';
            $binds['cur_no'] = $f['cur_no'];
        }

        if ($f['acc_no'] !== null) {
            $clauses[] = 'b.ACC_NO1 = :acc_no';
            $binds['acc_no'] = $f['acc_no'];
        }

        if ($f['status'] === 'posted') {
            $clauses[] = 'b.ENTRY_NO IS NOT NULL';
        } elseif ($f['status'] === 'unposted') {
            $clauses[] = 'b.ENTRY_NO IS NULL';
        }

        if ($f['min_amount'] !== null) {
            $clauses[] = 'NVL(b.NET, 0) >= :min_amount';
            $binds['min_amount'] = $f['min_amount'];
        }

        if ($f['max_amount'] !== null) {
            $clauses[] = 'NVL(b.NET, 0) <= :max_amount';
            $binds['max_amount'] = $f['max_amount'];
        }

        return [implode(' AND ', $clauses), $binds];
    }

    /**
     * شرط WHERE للمردودات مع روابطه — بأسماء مستقلة عن روابط الفواتير
     * The return WHERE clause with its bindings — names kept apart from the invoice binds.
     *
     * @param  array<string, mixed>  $f
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function returnWhere(array $f): array
    {
        $clauses = ['1 = 1'];
        $binds = [];

        if (($f['from_date'] ?? null) !== null) {
            $clauses[] = 'r.RET_DATE >= TO_DATE(:r_from_date, \'YYYY-MM-DD\')';
            $binds['r_from_date'] = $f['from_date'];
        }

        if (($f['to_date'] ?? null) !== null) {
            $clauses[] = 'r.RET_DATE < TO_DATE(:r_to_date, \'YYYY-MM-DD\') + 1';
            $binds['r_to_date'] = $f['to_date'];
        }

        if ($f['bill_type'] !== null) {
            $clauses[] = 'r.BILL_TYPE = :r_bill_type';
            $binds['r_bill_type'] = $f['bill_type'];
        }

        if ($f['store_no'] !== null) {
            $clauses[] = 'r.STORE_NO = :r_store_no';
            $binds['r_store_no'] = $f['store_no'];
        }

        if ($f['cur_no'] !== null) {
            $clauses[] = 'r.CUR_NO = :r_cur_no';
            $binds['r_cur_no'] = $f['cur_no'];
        }

        if ($f['acc_no'] !== null) {
            $clauses[] = 'r.ACC_NO = :r_acc_no';
            $binds['r_acc_no'] = $f['acc_no'];
        }

        if ($f['status'] === 'posted') {
            $clauses[] = 'r.ENTRY_NO IS NOT NULL';
        } elseif ($f['status'] === 'unposted') {
            $clauses[] = 'r.ENTRY_NO IS NULL';
        }

        if ($f['min_amount'] !== null) {
            $clauses[] = 'NVL(r.NET, 0) >= :r_min_amount';
            $binds['r_min_amount'] = $f['min_amount'];
        }

        if ($f['max_amount'] !== null) {
            $clauses[] = 'NVL(r.NET, 0) <= :r_max_amount';
            $binds['r_max_amount'] = $f['max_amount'];
        }

        return [implode(' AND ', $clauses), $binds];
    }

    // ── التجميعات ────────────────────────────────────────────

    /**
     * صف ملخص واحد (عدّاد ومجاميع ومعادل)
     * One summary row (count, totals and the riyal equivalent).
     *
     * @param  array{0: string, 1: array<string, mixed>}  $where
     * @return array<string, float|int>
     */
    private function summaryRow(string $table, string $rateExpression, string $totalColumn, array $where): array
    {
        [$clause, $binds] = $where;

        $row = (array) DB::connection('oracle')->selectOne(
            "SELECT COUNT(*) AS CNT,
                    NVL(SUM(NET), 0) AS NET_SUM,
                    NVL(SUM({$totalColumn}), 0) AS TOTAL_SUM,
                    NVL(SUM(DISCOUNT), 0) AS DISC_SUM,
                    NVL(SUM(NVL(NET, 0) * {$rateExpression}), 0) AS NET_BASE
               FROM {$table}
              WHERE {$clause}",
            $binds
        );

        return [
            'count' => (int) ($row['CNT'] ?? $row['cnt'] ?? 0),
            'net' => round((float) ($row['NET_SUM'] ?? $row['net_sum'] ?? 0), 2),
            'total' => round((float) ($row['TOTAL_SUM'] ?? $row['total_sum'] ?? 0), 2),
            'discount' => round((float) ($row['DISC_SUM'] ?? $row['disc_sum'] ?? 0), 2),
            'net_base' => round((float) ($row['NET_BASE'] ?? $row['net_base'] ?? 0), 2),
        ];
    }

    /**
     * السلسلة الشهرية — تُدمج فيها الفواتير (موجبة) والمردودات (سالبة)
     * The monthly series — invoices positive, returns negative.
     *
     * @param  array{0: string, 1: array<string, mixed>}  $invoiceWhere
     * @param  array{0: string, 1: array<string, mixed>}  $returnWhere
     * @return array<int, array<string, mixed>>
     */
    private function monthlySeries(array $invoiceWhere, array $returnWhere): array
    {
        $series = [];

        [$clause, $binds] = $invoiceWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT TO_CHAR(b.DAT, 'YYYY-MM') AS MONTH,
                    COUNT(*) AS CNT,
                    NVL(SUM(NVL(b.NET, 0) * NVL(b.CUR_PRICE1, 1)), 0) AS BASE
               FROM ACCOUNTS.BUY_BILL b
              WHERE {$clause}
              GROUP BY TO_CHAR(b.DAT, 'YYYY-MM')
              ORDER BY 1",
            $binds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $month = (string) $r['MONTH'];
            $series[$month] = [
                'month' => $month,
                'invoice_count' => (int) $r['CNT'],
                'invoice_base' => round((float) $r['BASE'], 2),
                'return_count' => 0,
                'return_base' => 0.0,
                'net_base' => round((float) $r['BASE'], 2),
            ];
        }

        [$rClause, $rBinds] = $returnWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT TO_CHAR(r.RET_DATE, 'YYYY-MM') AS MONTH,
                    COUNT(*) AS CNT,
                    NVL(SUM(NVL(r.NET, 0) * NVL(r.CUR_PRICE, 1)), 0) AS BASE
               FROM ACCOUNTS.RETURN_BUY r
              WHERE {$rClause}
              GROUP BY TO_CHAR(r.RET_DATE, 'YYYY-MM')
              ORDER BY 1",
            $rBinds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $month = (string) $r['MONTH'];
            $series[$month] ??= [
                'month' => $month,
                'invoice_count' => 0,
                'invoice_base' => 0.0,
                'return_count' => 0,
                'return_base' => 0.0,
                'net_base' => 0.0,
            ];
            $series[$month]['return_count'] = (int) $r['CNT'];
            $series[$month]['return_base'] = round((float) $r['BASE'], 2);
            $series[$month]['net_base'] = round($series[$month]['invoice_base'] - $series[$month]['return_base'], 2);
        }

        ksort($series);

        return array_values($series);
    }

    /**
     * التوزيع حسب المورد — بالمعادل وعدد المستندات
     * The per-supplier distribution — equivalent value and document counts.
     *
     * @param  array{0: string, 1: array<string, mixed>}  $invoiceWhere
     * @param  array{0: string, 1: array<string, mixed>}  $returnWhere
     * @return array<int, array<string, mixed>>
     */
    private function bySupplier(array $invoiceWhere, array $returnWhere): array
    {
        $rows = [];

        [$clause, $binds] = $invoiceWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT b.ACC_NO1 AS ACC_NO, MAX(b.PROV_NAME) AS NAME,
                    COUNT(*) AS CNT,
                    NVL(SUM(NVL(b.NET, 0) * NVL(b.CUR_PRICE1, 1)), 0) AS BASE
               FROM ACCOUNTS.BUY_BILL b
              WHERE {$clause}
              GROUP BY b.ACC_NO1",
            $binds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $acc = (string) ($r['ACC_NO'] ?? '0');
            $rows[$acc] = [
                'acc_no' => (int) $acc,
                'name' => $r['NAME'] ?? null,
                'invoice_count' => (int) $r['CNT'],
                'invoice_base' => round((float) $r['BASE'], 2),
                'return_count' => 0,
                'return_base' => 0.0,
                'net_base' => round((float) $r['BASE'], 2),
            ];
        }

        [$rClause, $rBinds] = $returnWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT r.ACC_NO AS ACC_NO, MAX(r.PROV_NAME) AS NAME,
                    COUNT(*) AS CNT,
                    NVL(SUM(NVL(r.NET, 0) * NVL(r.CUR_PRICE, 1)), 0) AS BASE
               FROM ACCOUNTS.RETURN_BUY r
              WHERE {$rClause}
              GROUP BY r.ACC_NO",
            $rBinds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $acc = (string) ($r['ACC_NO'] ?? '0');
            $rows[$acc] ??= [
                'acc_no' => (int) $acc,
                'name' => $r['NAME'] ?? null,
                'invoice_count' => 0,
                'invoice_base' => 0.0,
                'return_count' => 0,
                'return_base' => 0.0,
                'net_base' => 0.0,
            ];
            $rows[$acc]['return_count'] = (int) $r['CNT'];
            $rows[$acc]['return_base'] = round((float) $r['BASE'], 2);
            $rows[$acc]['net_base'] = round($rows[$acc]['invoice_base'] - $rows[$acc]['return_base'], 2);

            if (($rows[$acc]['name'] ?? null) === null) {
                $rows[$acc]['name'] = $r['NAME'] ?? null;
            }
        }

        $list = array_values($rows);
        usort($list, static fn (array $a, array $b): int => $b['net_base'] <=> $a['net_base']);

        return array_slice($list, 0, 50);
    }

    /**
     * التوزيع حسب نوع السداد (نقدي/آجل)
     * The distribution by payment type (cash/credit).
     *
     * @param  array{0: string, 1: array<string, mixed>}  $invoiceWhere
     * @param  array{0: string, 1: array<string, mixed>}  $returnWhere
     * @return array<int, array<string, mixed>>
     */
    private function byType(array $invoiceWhere, array $returnWhere): array
    {
        $rows = [];

        [$clause, $binds] = $invoiceWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT b.BILL_TYPE AS T, COUNT(*) AS CNT,
                    NVL(SUM(NVL(b.NET, 0) * NVL(b.CUR_PRICE1, 1)), 0) AS BASE
               FROM ACCOUNTS.BUY_BILL b
              WHERE {$clause}
              GROUP BY b.BILL_TYPE",
            $binds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $t = (int) $r['T'];
            $rows[$t] = ['bill_type' => $t, 'invoice_count' => (int) $r['CNT'], 'invoice_base' => round((float) $r['BASE'], 2), 'return_count' => 0, 'return_base' => 0.0];
        }

        [$rClause, $rBinds] = $returnWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT r.BILL_TYPE AS T, COUNT(*) AS CNT,
                    NVL(SUM(NVL(r.NET, 0) * NVL(r.CUR_PRICE, 1)), 0) AS BASE
               FROM ACCOUNTS.RETURN_BUY r
              WHERE {$rClause}
              GROUP BY r.BILL_TYPE",
            $rBinds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $t = (int) $r['T'];
            $rows[$t] ??= ['bill_type' => $t, 'invoice_count' => 0, 'invoice_base' => 0.0, 'return_count' => 0, 'return_base' => 0.0];
            $rows[$t]['return_count'] = (int) $r['CNT'];
            $rows[$t]['return_base'] = round((float) $r['BASE'], 2);
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * التوزيع حسب العملة الأصلية — يُظهر أن الأرقام قبل المعادل ليست قابلة للجمع
     * The distribution by original currency — showing why the raw amounts cannot be summed.
     *
     * @param  array{0: string, 1: array<string, mixed>}  $invoiceWhere
     * @param  array{0: string, 1: array<string, mixed>}  $returnWhere
     * @return array<int, array<string, mixed>>
     */
    private function byCurrency(array $invoiceWhere, array $returnWhere): array
    {
        $rows = [];

        [$clause, $binds] = $invoiceWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT b.CUR_NO1 AS CUR_NO, COUNT(*) AS CNT,
                    NVL(SUM(NVL(b.NET, 0)), 0) AS AMOUNT,
                    NVL(SUM(NVL(b.NET, 0) * NVL(b.CUR_PRICE1, 1)), 0) AS BASE
               FROM ACCOUNTS.BUY_BILL b
              WHERE {$clause}
              GROUP BY b.CUR_NO1",
            $binds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $cur = (string) ($r['CUR_NO'] ?? '0');
            $rows[$cur] = [
                'cur_no' => (int) $cur,
                'invoice_count' => (int) $r['CNT'],
                'invoice_amount' => round((float) $r['AMOUNT'], 2),
                'invoice_base' => round((float) $r['BASE'], 2),
                'return_count' => 0,
                'return_amount' => 0.0,
                'return_base' => 0.0,
            ];
        }

        [$rClause, $rBinds] = $returnWhere;

        foreach (DB::connection('oracle')->select(
            "SELECT r.CUR_NO AS CUR_NO, COUNT(*) AS CNT,
                    NVL(SUM(NVL(r.NET, 0)), 0) AS AMOUNT,
                    NVL(SUM(NVL(r.NET, 0) * NVL(r.CUR_PRICE, 1)), 0) AS BASE
               FROM ACCOUNTS.RETURN_BUY r
              WHERE {$rClause}
              GROUP BY r.CUR_NO",
            $rBinds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $cur = (string) ($r['CUR_NO'] ?? '0');
            $rows[$cur] ??= [
                'cur_no' => (int) $cur,
                'invoice_count' => 0,
                'invoice_amount' => 0.0,
                'invoice_base' => 0.0,
                'return_count' => 0,
                'return_amount' => 0.0,
                'return_base' => 0.0,
            ];
            $rows[$cur]['return_count'] = (int) $r['CNT'];
            $rows[$cur]['return_amount'] = round((float) $r['AMOUNT'], 2);
            $rows[$cur]['return_base'] = round((float) $r['BASE'], 2);
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * أعلى الأصناف حركةً — صافي الكمية والقيمة بالريال
     * The top items by movement — net quantity and riyal value.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, array<string, mixed>>
     */
    private function topItems(array $f): array
    {
        [$clause, $binds] = $this->invoiceWhere($f);
        $purchased = [];

        foreach (DB::connection('oracle')->select(
            "SELECT d.SANF_NO, MAX(s.SANF_NAME) AS NAME,
                    NVL(SUM(d.QTY), 0) AS QTY,
                    NVL(SUM(NVL(d.TOTAL, 0) * NVL(b.CUR_PRICE1, 1)), 0) AS BASE
               FROM ACCOUNTS.BUY_DETAILES d
               JOIN ACCOUNTS.BUY_BILL b ON b.BILL_NO = d.BILL_NO AND b.YEAR = d.YEAR
               LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
              WHERE {$clause}
              GROUP BY d.SANF_NO",
            $binds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $purchased[(int) $r['SANF_NO']] = [
                'sanf_no' => (int) $r['SANF_NO'],
                'name' => $r['NAME'] ?? null,
                'purchased_qty' => round((float) $r['QTY'], 3),
                'purchased_base' => round((float) $r['BASE'], 2),
                'returned_qty' => 0.0,
                'returned_base' => 0.0,
            ];
        }

        [$rClause, $rBinds] = $this->returnWhere($f);

        foreach (DB::connection('oracle')->select(
            "SELECT d.SANF_NO, MAX(s.SANF_NAME) AS NAME,
                    NVL(SUM(d.RET_QTY), 0) AS QTY,
                    NVL(SUM(NVL(d.TOTAL, 0) * NVL(r.CUR_PRICE, 1)), 0) AS BASE
               FROM ACCOUNTS.RETURN_BUY_DETAILES d
               JOIN ACCOUNTS.RETURN_BUY r ON r.RETURN_NO = d.RETURN_NO AND r.YEAR = d.YEAR
               LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
              WHERE {$rClause}
              GROUP BY d.SANF_NO",
            $rBinds
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $sanfNo = (int) $r['SANF_NO'];
            $purchased[$sanfNo] ??= [
                'sanf_no' => $sanfNo,
                'name' => $r['NAME'] ?? null,
                'purchased_qty' => 0.0,
                'purchased_base' => 0.0,
            ];
            $purchased[$sanfNo]['returned_qty'] = round((float) $r['QTY'], 3);
            $purchased[$sanfNo]['returned_base'] = round((float) $r['BASE'], 2);
        }

        $list = [];

        foreach ($purchased as $item) {
            $item['net_qty'] = round(($item['purchased_qty'] ?? 0) - ($item['returned_qty'] ?? 0), 3);
            $item['net_base'] = round(($item['purchased_base'] ?? 0) - ($item['returned_base'] ?? 0), 2);
            $list[] = $item;
        }

        usort($list, static fn (array $a, array $b): int => $b['net_base'] <=> $a['net_base']);

        return array_slice($list, 0, 25);
    }

    /**
     * جدول المستندات — فواتير ومردودات في قائمة واحدة موحّدة الشكل
     * The document table — invoices and returns in one uniform list.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, array<string, mixed>>
     */
    private function documents(array $f): array
    {
        $documents = [];

        if (($f['type'] ?? 'all') !== 'return') {
            [$clause, $binds] = $this->invoiceWhere($f);

            foreach (DB::connection('oracle')->select(
                "SELECT * FROM (
                     SELECT 'invoice' AS KIND, b.BILL_NO AS DOC_NO, b.YEAR AS DOC_YEAR,
                            b.BILL_TYPE, TO_CHAR(b.DAT, 'YYYY-MM-DD') AS DOC_DATE,
                            b.PROV_NAME, b.STORE_NO, b.CUR_NO1 AS CUR_NO, b.CUR_PRICE1 AS CUR_PRICE,
                            b.TOTAL, b.DISCOUNT, b.NET, b.ENTRY_NO, b.MANUAL_BILL_NO,
                            b.BILL_NO AS LINK_NO, b.YEAR AS LINK_YEAR, b.DETAILES
                       FROM ACCOUNTS.BUY_BILL b
                      WHERE {$clause}
                      ORDER BY b.DAT DESC, b.BILL_NO DESC
                 ) WHERE ROWNUM <= ".self::MAX_DOCUMENTS,
                $binds
            ) as $row) {
                $documents[] = $this->documentRow((array) $row);
            }
        }

        if (($f['type'] ?? 'all') !== 'invoice') {
            [$clause, $binds] = $this->returnWhere($f);

            foreach (DB::connection('oracle')->select(
                "SELECT * FROM (
                     SELECT 'return' AS KIND, r.RETURN_NO AS DOC_NO, r.YEAR AS DOC_YEAR,
                            r.BILL_TYPE, TO_CHAR(r.RET_DATE, 'YYYY-MM-DD') AS DOC_DATE,
                            r.PROV_NAME, r.STORE_NO, r.CUR_NO AS CUR_NO, r.CUR_PRICE AS CUR_PRICE,
                            r.RETURN_TOTAL AS TOTAL, r.DISCOUNT, r.NET, r.ENTRY_NO,
                            r.MANUAL_RETURN_NO AS MANUAL_BILL_NO,
                            r.BILL_NO AS LINK_NO, r.BILL_YEAR AS LINK_YEAR, r.DETAILES
                       FROM ACCOUNTS.RETURN_BUY r
                      WHERE {$clause}
                      ORDER BY r.RET_DATE DESC, r.RETURN_NO DESC
                 ) WHERE ROWNUM <= ".self::MAX_DOCUMENTS,
                $binds
            ) as $row) {
                $documents[] = $this->documentRow((array) $row);
            }
        }

        usort($documents, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return array_slice($documents, 0, self::MAX_DOCUMENTS);
    }

    /**
     * صف مستند موحّد بين الفاتورة والمردود
     * A document row unified across invoices and returns.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function documentRow(array $row): array
    {
        $r = array_change_key_case($row, CASE_UPPER);
        $net = (float) ($r['NET'] ?? 0);
        $rate = (float) ($r['CUR_PRICE'] ?? 1);

        return [
            'kind' => $r['KIND'] ?? null,
            'doc_no' => (int) ($r['DOC_NO'] ?? 0),
            'doc_year' => (int) ($r['DOC_YEAR'] ?? 0),
            'bill_type' => (int) ($r['BILL_TYPE'] ?? 0),
            'date' => $r['DOC_DATE'] ?? null,
            'prov_name' => $r['PROV_NAME'] ?? null,
            'store_no' => (int) ($r['STORE_NO'] ?? 0),
            'cur_no' => (int) ($r['CUR_NO'] ?? 0),
            'cur_price' => $rate,
            'total' => round((float) ($r['TOTAL'] ?? 0), 2),
            'discount' => round((float) ($r['DISCOUNT'] ?? 0), 2),
            'net' => round($net, 2),
            'net_base' => round($net * $rate, 2),
            'entry_no' => isset($r['ENTRY_NO']) ? (int) $r['ENTRY_NO'] : null,
            'manual_no' => isset($r['MANUAL_BILL_NO']) ? (int) $r['MANUAL_BILL_NO'] : null,
            'link_no' => isset($r['LINK_NO']) ? (int) $r['LINK_NO'] : null,
            'link_year' => isset($r['LINK_YEAR']) ? (int) $r['LINK_YEAR'] : null,
            'details' => $r['DETAILES'] ?? null,
        ];
    }
}
