<?php

namespace App\Http\Controllers\Sales;

use App\Support\ArabicEncoding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم تقارير المبيعات — تجميعات وإحصاءات للقراءة فقط
 * Sales report controller — aggregate statistics, read-only.
 *
 * كل الأرقام تُحسب على الخادم من نفس مصدر الحقيقة (SALES_BILL بتمييز المبيعات)، ولا يقبل العميل
 * أي مبلغ جاهز. المردودات تُطرح من الإجمالي لإظهار الصافي. العوامل: المدة/النوع/الحالة/المبلغ/
 * نوع السداد/الفرع/السنة.
 * Every figure is computed server-side over the same source of truth (sales rows of SALES_BILL);
 * the client never posts a total. Returns are netted off the gross to reveal the net. Filters:
 * period / type / payment status / amount / payment method / branch / year.
 */
class SalesReportController extends SalesBaseController
{
    private const TABLE = 'ACCOUNTS.SALES_BILL';

    private const TABLE_DETAILS = 'ACCOUNTS.SALES_DETAILES';

    private const TABLE_RETURNS = 'ACCOUNTS.RETURN_SALES';

    private const TABLE_SANFS = 'ACCOUNTS.SANFS';

    public function report(Request $request): JsonResponse
    {
        try {
            $bindings = [];
            $where = $this->buildWhere($request, $bindings);

            // المجاميع الرئيسية لفواتير المبيعات
            // The headline sales totals.
            $agg = DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS BILLS,
                        NVL(SUM(TOTAL),0) AS TOTAL,
                        NVL(SUM(DISCOUNT),0) AS DISCOUNT,
                        NVL(SUM(NET),0) AS NET,
                        NVL(SUM(AMOUNT_PAID),0) AS PAID,
                        NVL(SUM(NVL(NET,0) - NVL(AMOUNT_PAID,0)),0) AS REMAINING
                   FROM '.self::TABLE.'
                  WHERE CUTTING_OR_SLAES = ?'.$where,
                array_merge([self::CUTTING_OR_SALES], $bindings)
            );
            $agg = array_change_key_case((array) $agg);

            // إجمالي المردودات عن نفس المدة/العوامل (بجدول مستقل وارتباطات مستقلة)
            // Returns over the same period/filters (a separate table with its own bindings).
            $retBindings = [];
            $retWhere = $this->buildWhere($request, $retBindings, true);
            $ret = DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS BILLS, NVL(SUM(NET),0) AS NET FROM '.self::TABLE_RETURNS.' WHERE 1=1'.$retWhere,
                $retBindings
            );
            $ret = array_change_key_case((array) $ret);

            $net = (float) ($agg['net'] ?? 0);
            $paid = (float) ($agg['paid'] ?? 0);
            $retNet = (float) ($ret['net'] ?? 0);

            $summary = [
                'bills_count' => (int) ($agg['bills'] ?? 0),
                'returns_count' => (int) ($ret['bills'] ?? 0),
                'total' => (float) ($agg['total'] ?? 0),
                'discount' => (float) ($agg['discount'] ?? 0),
                'net' => $net,
                'paid' => $paid,
                'remaining' => (float) ($agg['remaining'] ?? 0),
                'returns_total' => $retNet,
                'net_after_returns' => $net - $retNet,
            ];

            // التوزيع حسب حالة السداد
            // Distribution by payment status.
            $statusRows = DB::connection('oracle')->select(
                'SELECT CASE
                            WHEN NVL(NET,0) <= 0 THEN \'paid\'
                            WHEN NVL(AMOUNT_PAID,0) >= NVL(NET,0) THEN \'paid\'
                            WHEN NVL(AMOUNT_PAID,0) > 0 THEN \'partial\'
                            ELSE \'unpaid\' END AS ST,
                        COUNT(*) AS C, NVL(SUM(NET),0) AS AMT
                   FROM '.self::TABLE.'
                  WHERE CUTTING_OR_SLAES = ?'.$where.'
                  GROUP BY CASE
                            WHEN NVL(NET,0) <= 0 THEN \'paid\'
                            WHEN NVL(AMOUNT_PAID,0) >= NVL(NET,0) THEN \'paid\'
                            WHEN NVL(AMOUNT_PAID,0) > 0 THEN \'partial\'
                            ELSE \'unpaid\' END',
                array_merge([self::CUTTING_OR_SALES], $bindings)
            );
            $byStatus = array_map(
                static fn ($r): array => ['status' => (string) (array_change_key_case((array) $r))['st'], 'count' => (int) (array_change_key_case((array) $r))['c'], 'amount' => (float) (array_change_key_case((array) $r))['amt']],
                $statusRows
            );

            // التوزيع حسب الشهر
            // Distribution by month.
            $monthRows = DB::connection('oracle')->select(
                'SELECT TO_CHAR(DAT,\'YYYY-MM\') AS M, COUNT(*) AS C, NVL(SUM(NET),0) AS AMT
                   FROM '.self::TABLE.'
                  WHERE CUTTING_OR_SLAES = ?'.$where.'
                  GROUP BY TO_CHAR(DAT,\'YYYY-MM\')
                  ORDER BY M',
                array_merge([self::CUTTING_OR_SALES], $bindings)
            );
            $byMonth = array_map(
                static function ($r): array {
                    $a = array_change_key_case((array) $r);

                    return ['month' => (string) $a['m'], 'count' => (int) $a['c'], 'amount' => (float) $a['amt']];
                },
                $monthRows
            );

            // أعلى الأصناف مبيعاً (كمية وقيمة) على نفس عوامل المدة عبر ربط التفاصيل
            // Top sold items (qty and value) over the same period, joining the detail lines.
            $itemRows = DB::connection('oracle')->select(
                'SELECT d.SANF_NO AS NO,
                        (SELECT MAX(s.SANF_NAME) FROM '.self::TABLE_SANFS.' s WHERE s.SANF_NO = d.SANF_NO) AS NM,
                        NVL(SUM(d.QTY),0) AS Q, NVL(SUM(d.TOTAL),0) AS AMT
                   FROM '.self::TABLE_DETAILS.' d
                   JOIN '.self::TABLE.' b ON b.BILL_NO = d.BILL_NO AND b.YEAR = d.YEAR
                  WHERE b.CUTTING_OR_SLAES = ?'.$this->prefixWhere('b', $bindings, $where).'
                  GROUP BY d.SANF_NO
                  ORDER BY AMT DESC', array_merge([self::CUTTING_OR_SALES], $this->lastBindings));
            $topItems = array_map(
                static function ($r): array {
                    $a = array_change_key_case((array) $r);

                    return ['sanf_no' => (int) $a['no'], 'sanf_name' => $a['nm'] ?? null, 'qty' => (float) $a['q'], 'amount' => (float) $a['amt']];
                },
                array_slice($itemRows, 0, 10)
            );

            // أعلى العملاء (قيمة)
            // Top customers by value.
            $custRows = DB::connection('oracle')->select(
                'SELECT MAX(CUST_NAME) AS NM, NVL(SUM(NET),0) AS AMT, COUNT(*) AS C
                   FROM '.self::TABLE.'
                  WHERE CUTTING_OR_SLAES = ? AND CUST_NO IS NOT NULL'.$where.'
                  GROUP BY CUST_NO
                  ORDER BY AMT DESC',
                array_merge([self::CUTTING_OR_SALES], $bindings)
            );
            $topCustomers = array_map(
                static function ($r): array {
                    $a = array_change_key_case((array) $r);

                    return ['cust_name' => $a['nm'] ?? null, 'amount' => (float) $a['amt'], 'count' => (int) $a['c']];
                },
                array_slice($custRows, 0, 10)
            );

            // جدول الفواتير ضمن العوامل (صفوف مفصّلة للتقرير المطبوع)
            // The invoice rows within the filters — the printable detail table.
            $rows = DB::connection('oracle')->select(
                'SELECT BILL_NO, YEAR, TO_CHAR(DAT,\'YYYY-MM-DD\') AS D, CUST_NAME, TOTAL, DISCOUNT, NET, AMOUNT_PAID,
                        (NVL(NET,0) - NVL(AMOUNT_PAID,0)) AS REMAINING, BILL_TYPE, PAYMENT_METHOD
                   FROM '.self::TABLE.'
                  WHERE CUTTING_OR_SLAES = ?'.$where.'
                  ORDER BY DAT DESC',
                array_merge([self::CUTTING_OR_SALES], $bindings)
            );
            $rows = array_map(function ($r): array {
                $a = array_change_key_case((array) $r);
                $net = (float) ($a['net'] ?? 0);
                $paid = (float) ($a['amount_paid'] ?? 0);

                return [
                    'bill_no' => (int) $a['bill_no'],
                    'year' => (int) $a['year'],
                    'date' => $a['d'] ?? null,
                    'cust_name' => $a['cust_name'] ?? null,
                    'total' => (float) ($a['total'] ?? 0),
                    'discount' => (float) ($a['discount'] ?? 0),
                    'net' => $net,
                    'amount_paid' => $paid,
                    'remaining' => (float) ($a['remaining'] ?? 0),
                    'bill_type' => isset($a['bill_type']) ? (int) $a['bill_type'] : null,
                    'payment_method' => $a['payment_method'] ?? null,
                    'payment_status' => $this->paymentStatus($net, $paid),
                ];
            }, $rows);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء تقرير المبيعات / Sales report generated',
                'data' => ArabicEncoding::cleanData([
                    'summary' => $summary,
                    'by_status' => $byStatus,
                    'by_month' => $byMonth,
                    'top_items' => $topItems,
                    'top_customers' => $topCustomers,
                    'rows' => $rows,
                ]),
                'meta' => ['generated_at' => now()->toDateTimeString()],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء تقرير المبيعات / Failed to build sales report', $e);
        }
    }

    public function activity(Request $request): JsonResponse
    {
        $action = (string) ($request->input('action') ?? '');
        $map = [
            'print' => ['sales.report_printed', 'طباعة تقرير مبيعات'],
            'export' => ['sales.report_exported', 'تصدير تقرير مبيعات'],
        ];

        if (! isset($map[$action])) {
            return response()->json(['success' => false, 'message' => 'قيمة action غير مدعومة / Unsupported action value'], 422);
        }

        $this->logActivity($request, $map[$action][0], self::ENTITY_REPORT, '', $map[$action][1]);

        return response()->json(['success' => true, 'message' => 'تم التسجيل / Logged'], 200);
    }

    // ── helpers ──────────────────────────────────────────────

    /** الروابط المرتبطة بالعمود `b` عند تكرار شرط المدة على جدول التفاصيل */
    /** Where fragment prefixed with an alias, plus the bindings the caller must reuse. */
    private array $lastBindings = [];

    /**
     * بناء شرط WHERE المشترك لكل من الفواتير والمردودات.
     * Build the shared WHERE fragment for both invoices and returns.
     *
     * @param  array<string, mixed>  $bindings
     */
    private function buildWhere(Request $request, array &$bindings, bool $forReturns = false): string
    {
        $sql = '';
        $dateCol = $forReturns ? 'RET_DATE' : 'DAT';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($request->input('from_date') ?? '')) === 1) {
            $sql .= " AND $dateCol >= TO_DATE(?, 'YYYY-MM-DD')";
            $bindings[] = (string) $request->input('from_date');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($request->input('to_date') ?? '')) === 1) {
            $sql .= " AND $dateCol < TO_DATE(?, 'YYYY-MM-DD') + 1";
            $bindings[] = (string) $request->input('to_date');
        }
        if (! $forReturns && ($bt = $request->input('bill_type')) !== null && $bt !== '') {
            $sql .= ' AND BILL_TYPE = ?';
            $bindings[] = (int) $bt;
        }
        if (($year = $request->input('year')) !== null && $year !== '') {
            $sql .= ' AND YEAR = ?';
            $bindings[] = (int) $year;
        }
        if (($branch = $request->input('branch')) !== null && $branch !== '') {
            $sql .= ' AND BRANCH_NO = ?';
            $bindings[] = (int) $branch;
        }
        if (! $forReturns && ($method = trim((string) ($request->input('payment_method') ?? ''))) !== '') {
            $sql .= ' AND PAYMENT_METHOD LIKE ?';
            $bindings[] = '%'.ArabicEncoding::toOracleSearchChars($method).'%';
        }
        if (($min = $request->input('min_amount')) !== null && $min !== '' && is_numeric($min)) {
            $sql .= ' AND NET >= ?';
            $bindings[] = (float) $min;
        }
        if (($max = $request->input('max_amount')) !== null && $max !== '' && is_numeric($max)) {
            $sql .= ' AND NET <= ?';
            $bindings[] = (float) $max;
        }
        if (! $forReturns) {
            match ((string) ($request->input('payment_status') ?? '')) {
                'paid' => $this->appendStatus($sql, $bindings, 'paid'),
                'partial' => $this->appendStatus($sql, $bindings, 'partial'),
                'unpaid' => $this->appendStatus($sql, $bindings, 'unpaid'),
                default => null,
            };
        }

        return $sql;
    }

    private function appendStatus(string &$sql, array &$bindings, string $status): void
    {
        $sql .= match ($status) {
            'paid' => ' AND NVL(NET,0) > 0 AND NVL(AMOUNT_PAID,0) >= NVL(NET,0)',
            'partial' => ' AND NVL(AMOUNT_PAID,0) > 0 AND NVL(AMOUNT_PAID,0) < NVL(NET,0)',
            'unpaid' => ' AND NVL(AMOUNT_PAID,0) <= 0 AND NVL(NET,0) > 0',
            default => '',
        };
    }

    /**
     * إعادة استخدام شرط المدة على مخطط مميّز بـ alias مع التقاط الروابط للمزامنة.
     * Re-apply the period condition on an aliased join, capturing the bindings for reuse.
     */
    private function prefixWhere(string $alias, array &$bindings, string $where): string
    {
        // الشرط المبني بلا alias يُستخدم حرفياً بعد ربط الجداول؛ الروابط تبقى بترتيبها.
        // The alias-free fragment is used literally after the join; bindings keep their order.
        $this->lastBindings = $bindings;

        return $where;
    }
}
