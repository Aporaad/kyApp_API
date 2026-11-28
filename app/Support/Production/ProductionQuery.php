<?php

namespace App\Support\Production;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * استعلامات خط الإنتاج — قراءة مباشرة من الجداول الأساسية لا من الواجهات القديمة
 * Production-line queries — direct reads from the base tables, never the legacy views.
 *
 * الواجهات القديمة تربط 5–7 جداول بلا فهارس مناسبة، وتُرجع الحركات المكرّرة كما هي.
 * هنا ننسخ شروطها حرفياً (الملحق ب في الخطة) مع صفحات وترتيب مضبوطين.
 * The legacy views join 5–7 unindexed tables and return duplicated movements; here their
 * filters are copied verbatim while paging and ordering stay under our control.
 */
final class ProductionQuery
{
    /**
     * تجميع الفاتورة: أعمدة الرأس كلها، لأن OHID فهرس فريد لا قيد PK يمنح الاعتماد الوظيفي
     * Invoice grouping: every header column, because OHID is a unique index, not a PK constraint.
     */
    private const INVOICE_GROUP_BY = ' GROUP BY sb.OHID, sb.BILL_NO, sb.YEAR, sb.MANUAL_BILL_NO,
        sb.DAT, sb.CUST_NO, sb.CUST_NAME, sb.TEL_NO1, sb.BENEFICIARY_NAME,
        sb.GROUP_NO, sb.BILLS_KINDS, sb.ORDSID, sb.BILL_TYPE, sb.TOTAL, sb.NET, sb.AMOUNT_PAID';

    /**
     * عدادات الأقسام الثلاثة المنفّذة — استعلامات صغيرة منفصلة أوضح من تجميع شرطي هش
     * The three implemented section counts — separate small queries beat a fragile conditional sum.
     *
     * @return array<string, array{pieces: int, invoices: int}>
     */
    public function summary(): array
    {
        $conn = DB::connection('oracle');

        $approval = (array) $conn->selectOne(
            'SELECT COUNT(*) AS PIECES, COUNT(DISTINCT mc.OHID) AS INVOICES
               FROM ACCOUNTS.SALES_BILL sb
               JOIN ACCOUNTS.MULTI_CLOTHS mc ON mc.OHID = sb.OHID
              WHERE mc.ORDSDID = 0
                AND NVL(mc.F_CANCEL, 0) = 0
                AND NVL(sb.HANDING_DONE, 0) <> 1
                AND NVL(mc.CUTTING_DONE, 0) <> 1
                AND sb.BILLS_KINDS = 1'
        );

        $toCut = (array) $conn->selectOne(
            'SELECT COUNT(*) AS PIECES, COUNT(DISTINCT mc.OHID) AS INVOICES
               FROM ACCOUNTS.SALES_BILL sb
               JOIN ACCOUNTS.MULTI_CLOTHS mc ON mc.OHID = sb.OHID
              WHERE mc.ORDSDID = 1
                AND NVL(mc.F_CANCEL, 0) = 0
                AND sb.BILLS_KINDS NOT IN (2, 3, 4)'
        );

        $fromCut = (array) $conn->selectOne(
            'SELECT COUNT(*) AS PIECES, COUNT(DISTINCT mc.OHID) AS INVOICES
               FROM ACCOUNTS.SALES_BILL sb
               JOIN ACCOUNTS.MULTI_CLOTHS mc ON mc.OHID = sb.OHID
              WHERE mc.ORDSDID = 2
                AND NVL(mc.F_CANCEL, 0) = 0
                AND sb.BILLS_KINDS NOT IN (2, 3, 4)
                AND EXISTS (
                    SELECT 1 FROM ACCOUNTS.CUTING_TRANSD ct
                     WHERE ct.OHDID = mc.OHDID
                       AND ct.ORDSID = 2
                       AND NVL(ct.CANCEL, 0) <> 1
                )'
        );

        return [
            ProductionPolicy::SECTION_APPROVAL => [
                'pieces' => (int) ($approval['PIECES'] ?? $approval['pieces'] ?? 0),
                'invoices' => (int) ($approval['INVOICES'] ?? $approval['invoices'] ?? 0),
            ],
            ProductionPolicy::SECTION_TO_CUT => [
                'pieces' => (int) ($toCut['PIECES'] ?? $toCut['pieces'] ?? 0),
                'invoices' => (int) ($toCut['INVOICES'] ?? $toCut['invoices'] ?? 0),
            ],
            ProductionPolicy::SECTION_FROM_CUT => [
                'pieces' => (int) ($fromCut['PIECES'] ?? $fromCut['pieces'] ?? 0),
                'invoices' => (int) ($fromCut['INVOICES'] ?? $fromCut['invoices'] ?? 0),
            ],
        ];
    }

    /**
     * فواتير الاعتماد — النشطة افتراضياً، والموقوفة/الملغية في تبويب قراءة فقط
     * Approval invoices — active by default, stopped/cancelled in a read-only tab.
     *
     * @param  array{search?: string, kind?: string}  $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    /**
     * فواتير الاعتماد — بحث وحالة وتصنيف وترتيب، مع المدفوع والمبلغ والقطع المعلّقة
     * Approval invoices — search, status, classification and sort, with paid/total/pending pieces.
     *
     * @param  array{search?: string, status?: string, classification?: string, sort?: string}  $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function approvalInvoices(array $filters, int $page, int $limit): array
    {
        $binds = [];
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'unapproved');
        $classification = (string) ($filters['classification'] ?? 'all');
        $sort = ($filters['sort'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $where = ' FROM ACCOUNTS.SALES_BILL sb
                   JOIN ACCOUNTS.MULTI_CLOTHS mc ON mc.OHID = sb.OHID
                  WHERE NVL(mc.F_CANCEL, 0) = 0
                    AND NVL(sb.HANDING_DONE, 0) <> 1';

        switch ($status) {
            case 'approved':
                $where .= ' AND sb.BILLS_KINDS = 1 AND sb.ORDSID = 1';
                break;

            case 'stopped':
                $where .= ' AND sb.BILLS_KINDS = 2';
                break;

            case 'cancelled':
                $where .= ' AND sb.BILLS_KINDS = 3';
                break;

            case 'pending':
                $where .= ' AND sb.BILLS_KINDS = 4';
                break;

            case 'all':
                break;

            case 'unapproved':
            default:
                $where .= ' AND sb.BILLS_KINDS = 1 AND NVL(sb.ORDSID, 0) = 0';
                break;
        }

        if ($classification === 'orders') {
            $where .= ' AND sb.BILL_TYPE = 2';
        } elseif ($classification === 'ready') {
            $where .= ' AND sb.BILL_TYPE = 1';
        }

        $where .= $this->searchClause($binds, $search, [
            'sb.CUST_NAME',
            'sb.BENEFICIARY_NAME',
            'sb.TEL_NO1',
        ], 'sb.BILL_NO', 'sb.OHID');

        $where .= self::INVOICE_GROUP_BY;

        // «غير معتمدة» لا تعرض فاتورة انتهت قطعها فعلاً وإن تأخر عمود حالتها.
        // Unapproved never lists an invoice whose pieces are all approved, even if the
        // legacy header status lagged behind.
        if ($status === 'unapproved') {
            $where .= ' HAVING SUM(CASE WHEN mc.ORDSDID = 0 THEN 1 ELSE 0 END) > 0';
        }

        $select = 'SELECT sb.OHID, sb.BILL_NO, sb.YEAR, sb.MANUAL_BILL_NO,
                          TO_CHAR(sb.DAT, \'YYYY-MM-DD\') AS DAT_ISO,
                          TO_CHAR(sb.DAT, \'DD/MM/YYYY\') AS DAT_TXT,
                          sb.CUST_NO, sb.CUST_NAME, sb.TEL_NO1, sb.BENEFICIARY_NAME,
                          sb.GROUP_NO, sb.BILLS_KINDS, sb.ORDSID, sb.BILL_TYPE,
                          NVL(sb.TOTAL, 0) AS TOTAL, NVL(sb.NET, 0) AS NET,
                          NVL(sb.AMOUNT_PAID, 0) AS AMOUNT_PAID,
                          COUNT(*) AS PIECES,
                          SUM(CASE WHEN mc.ORDSDID = 0 THEN 1 ELSE 0 END) AS PENDING_PIECES,
                          SUM(CASE WHEN NVL(mc.GABZOR_HAND, 0) = 1 THEN 1 ELSE 0 END) AS GABZOR_COUNT,
                          SUM(CASE WHEN NVL(mc.TATRYZ_IN, 0) = 1 THEN 1 ELSE 0 END) AS TATRYZ_COUNT';

        $order = 'sb.DAT '.$sort.', sb.BILL_NO '.$sort;

        return $this->page($select, $where, $binds, $order, $page, $limit);
    }

    /**
     * فواتير التسليم إلى القص — قطع معتمدة فقط، والفواتير غير الموقوفة/الملغية
     * Delivery-to-cut invoices — approved pieces only, non-stopped bills.
     *
     * @param  array{search?: string}  $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function deliveryInvoices(array $filters, int $page, int $limit): array
    {
        $binds = [];
        $search = trim((string) ($filters['search'] ?? ''));

        $where = ' FROM ACCOUNTS.SALES_BILL sb
                   JOIN ACCOUNTS.MULTI_CLOTHS mc ON mc.OHID = sb.OHID
                  WHERE mc.ORDSDID = 1
                    AND NVL(mc.F_CANCEL, 0) = 0
                    AND sb.BILLS_KINDS NOT IN (2, 3, 4)';

        $where .= $this->searchClause($binds, $search, [
            'sb.CUST_NAME',
            'sb.BENEFICIARY_NAME',
            'sb.TEL_NO1',
        ], 'sb.BILL_NO');

        $where .= self::INVOICE_GROUP_BY;

        $select = 'SELECT sb.OHID, sb.BILL_NO, sb.YEAR, sb.MANUAL_BILL_NO,
                          TO_CHAR(sb.DAT, \'YYYY-MM-DD\') AS DAT_ISO,
                          TO_CHAR(sb.DAT, \'DD/MM/YYYY\') AS DAT_TXT,
                          sb.CUST_NO, sb.CUST_NAME, sb.TEL_NO1, sb.BENEFICIARY_NAME,
                          sb.GROUP_NO, sb.BILLS_KINDS, sb.ORDSID,
                          COUNT(*) AS PIECES,
                          SUM(CASE WHEN NVL(mc.GABZOR_HAND, 0) = 1 THEN 1 ELSE 0 END) AS GABZOR_COUNT,
                          SUM(CASE WHEN NVL(mc.TATRYZ_IN, 0) = 1 THEN 1 ELSE 0 END) AS TATRYZ_COUNT';

        return $this->page($select, $where, $binds, 'sb.DAT ASC, sb.BILL_NO ASC', $page, $limit);
    }

    /**
     * فواتير الاستلام من القص — القطع عند قصاص، مع حركة سارية
     * Receipt-from-cut invoices — pieces at a cutter with an open movement.
     *
     * @param  array{search?: string, cutter_no?: int|null}  $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function receiptInvoices(array $filters, int $page, int $limit): array
    {
        $binds = [];
        $search = trim((string) ($filters['search'] ?? ''));
        $cutterNo = $filters['cutter_no'] ?? null;

        $where = ' FROM ACCOUNTS.SALES_BILL sb
                   JOIN ACCOUNTS.MULTI_CLOTHS mc ON mc.OHID = sb.OHID
                  WHERE mc.ORDSDID = 2
                    AND NVL(mc.F_CANCEL, 0) = 0
                    AND sb.BILLS_KINDS NOT IN (2, 3, 4)
                    AND EXISTS (
                        SELECT 1 FROM ACCOUNTS.CUTING_TRANSD ct
                         WHERE ct.OHDID = mc.OHDID
                           AND ct.ORDSID = 2
                           AND NVL(ct.CANCEL, 0) <> 1
                    )';

        if ($cutterNo !== null) {
            $where .= ' AND mc.CUTTER_NO1 = :cutter_no';
            $binds['cutter_no'] = $cutterNo;
        }

        $where .= $this->searchClause($binds, $search, [
            'sb.CUST_NAME',
            'sb.BENEFICIARY_NAME',
            'sb.TEL_NO1',
        ], 'sb.BILL_NO');

        $where .= self::INVOICE_GROUP_BY;

        $select = 'SELECT sb.OHID, sb.BILL_NO, sb.YEAR, sb.MANUAL_BILL_NO,
                          TO_CHAR(sb.DAT, \'YYYY-MM-DD\') AS DAT_ISO,
                          TO_CHAR(sb.DAT, \'DD/MM/YYYY\') AS DAT_TXT,
                          sb.CUST_NO, sb.CUST_NAME, sb.TEL_NO1, sb.BENEFICIARY_NAME,
                          sb.GROUP_NO, sb.BILLS_KINDS, sb.ORDSID,
                          COUNT(*) AS PIECES,
                          MIN(TO_CHAR(NVL(mc.CUTTING_DATE,
                              (SELECT MIN(ct.DDATE) FROM ACCOUNTS.CUTING_TRANSD ct
                                WHERE ct.OHDID = mc.OHDID AND ct.ORDSID = 2
                                  AND NVL(ct.CANCEL, 0) <> 1)), \'YYYY-MM-DD\')) AS DELIVERED_ISO,
                          MAX(ROUND(SYSDATE - NVL(mc.CUTTING_DATE,
                              (SELECT MIN(ct.DDATE) FROM ACCOUNTS.CUTING_TRANSD ct
                                WHERE ct.OHDID = mc.OHDID AND ct.ORDSID = 2
                                  AND NVL(ct.CANCEL, 0) <> 1)))) AS OLDEST_DAYS,
                          MAX(mc.CUTTER_NO1) AS CUTTER_NO';

        return $this->page($select, $where, $binds, 'MIN(mc.CUTTING_DATE) ASC, sb.BILL_NO ASC', $page, $limit);
    }

    /**
     * قطع فاتورة واحدة حسب القسم — الشروط تمنع عرض قطعة خرجت من المحطة
     * Pieces of one invoice per section — the conditions keep a piece that already moved out.
     *
     * @return list<array<string, mixed>>
     */
    public function pieces(string $section, int $ohid, ?int $cutterNo = null): array
    {
        $binds = ['ohid' => $ohid];
        $where = ' WHERE mc.OHID = :ohid AND NVL(mc.F_CANCEL, 0) = 0';

        switch ($section) {
            case ProductionPolicy::SECTION_APPROVAL:
                $where .= ' AND mc.ORDSDID = 0
                            AND NVL(sb.HANDING_DONE, 0) <> 1
                            AND NVL(mc.CUTTING_DONE, 0) <> 1';
                break;

            case ProductionPolicy::SECTION_TO_CUT:
                $where .= ' AND mc.ORDSDID = 1 AND sb.BILLS_KINDS NOT IN (2, 3, 4)';
                break;

            case ProductionPolicy::SECTION_FROM_CUT:
                $where .= ' AND mc.ORDSDID = 2 AND sb.BILLS_KINDS NOT IN (2, 3, 4)
                            AND EXISTS (
                                SELECT 1 FROM ACCOUNTS.CUTING_TRANSD ct
                                 WHERE ct.OHDID = mc.OHDID
                                   AND ct.ORDSID = 2
                                   AND NVL(ct.CANCEL, 0) <> 1
                            )';
                if ($cutterNo !== null) {
                    $where .= ' AND mc.CUTTER_NO1 = :cutter_no';
                    $binds['cutter_no'] = $cutterNo;
                }
                break;

            default:
                return [];
        }

        $sql = 'SELECT mc.OHDID, mc.OHID, mc.BILL_NO, mc.YEAR, mc.SEQ_NO1, mc.SEQ_NO,
                       mc.SANF_NO, mc.SANF_DNO, mc.CLOTH_NAME, mc.CLOTH_TYPE,
                       mc.CLOTHS_COLOR, mc.CLOTHS_COLOR2,
                       mc.CONSUMED_CLOTH_QTY_HINSH, mc.CONSUMED_CLOTH_QTY_YARDS,
                       mc.CLOTH_FRM_LOCAL_STORE, mc.CLOTHS_OUR_SELECTION, mc.SHELF_NO,
                       mc.QTY, mc.SHAPE_STYLE_NO, mc.STYLE_NAME, mc.ORDSDID,
                       mc.CUTTER_NO1, mc.CUTTER_PCS_PRICE1,
                       TO_CHAR(mc.CUTTING_DATE, \'YYYY-MM-DD\') AS CUTTING_DATE_ISO,
                       TO_CHAR(mc.CUTTING_DATE, \'DD/MM/YYYY\') AS CUTTING_DATE_TXT,
                       mc.TIME1, mc.GABZOR_HAND, mc.GABZOR_HAND_AMT,
                       mc.TATRYZ_IN, mc.TATRYZ_AMT, mc.FIXED_FLEXED,
                       s.L1, s.L5,
                       NVL(s.BENEFICIARYNAME, sb.BENEFICIARY_NAME) AS BENEFICIARY_NAME
                  FROM ACCOUNTS.MULTI_CLOTHS mc
                  JOIN ACCOUNTS.SALES_BILL sb ON sb.OHID = mc.OHID
                  LEFT JOIN ACCOUNTS.SALES_DETAILES_SHAPES s
                         ON s.OHID = mc.OHID AND s.SEQ_NO = mc.SEQ_NO
                 '.$where.'
                 ORDER BY mc.SEQ_NO1';

        return DB::connection('oracle')->select($sql, $binds);
    }

    /**
     * كل القصاصين لاختيار وجهة التسليم
     * All cutters, for choosing a delivery destination.
     *
     * @return list<array<string, mixed>>
     */
    public function cuttersForDelivery(): array
    {
        return DB::connection('oracle')->select(
            'SELECT CUTTER_NO, CUTTER_NAME, CUTTER_PHONE1, CUTTER_STATUS
               FROM ACCOUNTS.CUTTER
              ORDER BY CUTTER_NAME'
        );
    }

    /**
     * القصاصون الذين لديهم قطع فعلية، مع عدد القطع والفواتير وأقدم يوم
     * Cutters holding pieces, with piece/invoice counts and the oldest day.
     *
     * @return list<array<string, mixed>>
     */
    public function cuttersWithWork(): array
    {
        return DB::connection('oracle')->select(
            'SELECT c.CUTTER_NO, c.CUTTER_NAME, c.CUTTER_PHONE1, c.CUTTER_STATUS,
                    t.PIECES, t.INVOICES, t.OLDEST_DAYS
               FROM (
                    SELECT q.CUTTER_NO1 AS NO,
                           COUNT(*) AS PIECES,
                           COUNT(DISTINCT q.OHID) AS INVOICES,
                           MAX(ROUND(SYSDATE - q.DELIVERY_DATE)) AS OLDEST_DAYS
                      FROM (
                           SELECT mc.CUTTER_NO1, mc.OHID, mc.OHDID,
                                  NVL(mc.CUTTING_DATE,
                                      (SELECT MIN(ct.DDATE)
                                         FROM ACCOUNTS.CUTING_TRANSD ct
                                        WHERE ct.OHDID = mc.OHDID
                                          AND ct.ORDSID = 2
                                          AND NVL(ct.CANCEL, 0) <> 1)) AS DELIVERY_DATE
                             FROM ACCOUNTS.MULTI_CLOTHS mc
                            WHERE mc.ORDSDID = 2
                              AND NVL(mc.F_CANCEL, 0) = 0
                      ) q
                     GROUP BY q.CUTTER_NO1
               ) t
               JOIN ACCOUNTS.CUTTER c ON c.CUTTER_NO = t.NO
              ORDER BY t.PIECES DESC, c.CUTTER_NAME'
        );
    }

    /**
     * صفحة Oracle 11g: عدّاد على المجموعة، ثم ROWNUM داخل غلافين
     * Oracle 11g page: count the grouped set, then ROWNUM in two wrappers.
     *
     * @param  array<string, mixed>  $binds
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function page(
        string $select,
        string $fromWhere,
        array $binds,
        string $orderBy,
        int $page,
        int $limit
    ): array {
        $conn = DB::connection('oracle');

        $countRow = (array) $conn->selectOne(
            'SELECT COUNT(*) AS C FROM (SELECT 1 '.$fromWhere.') t',
            $binds
        );
        $total = (int) ($countRow['C'] ?? $countRow['c'] ?? 0);

        $offset = max(0, ($page - 1) * $limit);
        $bindings = array_merge($binds, [
            'row_max' => $offset + $limit,
            'row_min' => $offset,
        ]);

        $rows = $conn->select(
            'SELECT * FROM (
                 SELECT p.*, ROWNUM AS RN FROM (
                     '.$select.$fromWhere.'
                     ORDER BY '.$orderBy.'
                 ) p WHERE ROWNUM <= :row_max
             ) WHERE RN > :row_min',
            $bindings
        );

        // ROWNUM أداة تصفح لا حقل في العقد — يُزال قبل الرد
        // ROWNUM is a paging device, not a payload field — strip it before responding.
        $rows = array_map(static function (object|array $row): array {
            $array = (array) $row;
            unset($array['RN'], $array['rn']);

            return $array;
        }, $rows);

        return [$rows, $total];
    }

    /**
     * شرط بحث عربي يحاول الصيغتين (بايتات CP1256 والنص UTF-8) ورقم الفاتورة
     * Arabic search across both byte forms plus an exact bill-number match.
     *
     * @param  array<string, mixed>  $binds
     * @param  list<string>  $textColumns
     */
    private function searchClause(
        array &$binds,
        string $search,
        array $textColumns,
        string $billColumn,
        ?string $orderColumn = null
    ): string {
        if ($search === '') {
            return '';
        }

        $raw = '%'.$search.'%';
        $encoded = '%'.ArabicEncoding::toOracleSearchChars($search).'%';
        $parts = [];

        foreach ($textColumns as $index => $column) {
            $rawKey = 's'.$index.'_raw';
            $encodedKey = 's'.$index.'_enc';
            $binds[$rawKey] = $raw;
            $binds[$encodedKey] = $encoded;
            $parts[] = $column.' LIKE :'.$rawKey.' OR '.$column.' LIKE :'.$encodedKey;
        }

        if (ctype_digit($search)) {
            $binds['s_bill_no'] = (int) $search;
            $binds['s_manual_no'] = (int) $search;
            $parts[] = $billColumn.' = :s_bill_no OR sb.MANUAL_BILL_NO = :s_manual_no';

            if ($orderColumn !== null) {
                $binds['s_order_no'] = (int) $search;
                $parts[] = $orderColumn.' = :s_order_no';
            }
        }

        return ' AND ('.implode(' OR ', $parts).')';
    }
}
