<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use App\Support\CuttingViewReader;
use Illuminate\Support\Facades\DB;

/**
 * قارئ لقطة تعديل فاتورة التفصيل من الجداول الأساسية، لا من عرض القص.
 * The tailoring edit snapshot reader, based on base tables rather than the cutting view.
 *
 * ── القراءة المباشرة ضرورية لأن `CUTTING_SALES_BILL_VIEW` تستخدم روابط داخلية
 *    فتسقط الفواتير غير المسندة والموديلات بلا قطع.
 * ── Direct reads are required because the cutting view inner-joins unassigned invoices away.
 */
final class TailoringEditSnapshot
{
    /**
     * لقطة فاتورة واحدة بهويتها المركبة.
     * One invoice snapshot by its composite identity.
     *
     * @return array<string, mixed>|null
     */
    public static function read(int $billNo, int $year): ?array
    {
        $headerRow = DB::connection('oracle')->selectOne(
            "SELECT BILL_NO, YEAR, MANUAL_BILL_NO, OHID, ENTRY_NO, BILL_TYPE, BILLS_KINDS,
                    TO_CHAR(DAT, 'YYYY-MM-DD') AS DAT_ISO,
                    CUST_NO, CUST_NAME, TEL_NO1, TEL_NO2, ADDRESS,
                    REF_NO, DETAILES, TOTAL, NET, AMOUNT_PAID, DIS_RATE, DISCOUNT,
                    CUR_NO, CUR_PRICE, CUR_NO1, CUR_PRICE1, SELECT1,
                    ACC_NO, ACC_NO1, ACC_NO2, FIRST_TRANSFER, TRANSFER_THROUGH,
                    PERMANENT_CUST, URGENT, GIVING_IN, VOUCHER_NO, CUST_KNOWN,
                    BRANCH_NO, ORDSID, IS_GIVING, HANDING_DONE,
                    TO_CHAR(CREATED_ON, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_ON_TXT,
                    TO_CHAR(LAST_UPDATED_ON, 'YYYY-MM-DD HH24:MI:SS') AS LAST_UPDATED_ON_TXT,
                    CREATED_BY, CREATED_BY_NAME, LAST_UPDATED_BY, LAST_UPDATED_BY_NAME
               FROM ACCOUNTS.SALES_BILL
              WHERE BILL_NO = :bill_no AND YEAR = :year AND CUTTING_OR_SLAES = 1",
            ['bill_no' => $billNo, 'year' => $year]
        );

        if ($headerRow === null || $headerRow === []) {
            return null;
        }

        $header = (array) $headerRow;
        $shapes = array_map(
            static fn ($row): array => (array) $row,
            DB::connection('oracle')->select(
                'SELECT BILL_NO, YEAR, SEQ_NO, OHID, OHDID, SHAPE_STYLE_NO, STYL_NO,
                        STYLE_NAME, BENEFICIARYNAME, NOTES2, QTY, RQUEST_NO,
                        BUTTON_TYPE, BUTTON_NO, BUTTONS_NUM, COM_CABAK_TYPE, NECK_TYPE,
                        POCKET_OR_WITHOUT, GABZOR_TYPE, MOBILE_POCKET, PEN_POCKET,
                        HEGAZI_TYPE, TIPPER_TYPE, SHAPE_GABROZ_NO,
                        NECK_SHAPE_NO, SHAPE_COM_CABAK_NO, SHAPE_POCKET_UP_NO, SHAPE_POCKET_DOWN_NO,
                        L1, L2, L3, L4, L5, L6, L7, L8, L9, L10, L11, L12,
                        SANF_NO, SANF_DNO, PROD_NO, CUTTER_NO, TAILOR_NO,
                        CUTTER_PCS_PRICE, TAILOR_PCS_PRICE, HANDING_DONE
                   FROM ACCOUNTS.SALES_DETAILES_SHAPES
                  WHERE BILL_NO = :bill_no AND YEAR = :year
                  ORDER BY SEQ_NO',
                ['bill_no' => $billNo, 'year' => $year]
            )
        );
        $pieces = array_map(
            static fn ($row): array => (array) $row,
            DB::connection('oracle')->select(
                'SELECT SEQ_NO1, SEQ_NO, BILL_NO, YEAR, OHID, OHDID,
                        SANF_NO, SANF_DNO, PROD_NO, CLOTH_NAME, CLOTH_TYPE, CLOTHS_COLOR, CLOTHS_COLOR2,
                        QTY, CONSUMED_CLOTH_QTY_HINSH, CONSUMED_CLOTH_QTY_YARDS,
                        CONSUMED_CLOTH_QTY_HINSH1, SHARKH, GABZOR_HAND, TATRYZ_IN, TATRYZ_NO,
                        HABNA_DUABL, BUTTONNO, SANFPRICE, SANF_COST, STK_COST, SHAPE_STYLE_NO, STYLE_NAME, FIXED_FLEXED,
                        CLOTH_FRM_LOCAL_STORE, SHELF_NO, CLOTHS_OUR_SELECTION,
                        CUTTER_NO1, TAILOR_NO1, CUTTER_PCS_PRICE1, TAILOR_PCS_PRICE1,
                        CUTTING_DONE, TAILOR_DONE, HANDING_DONE1, ORDSDID,
                        TO_CHAR(CUTTING_DATE, \'YYYY-MM-DD\') AS CUTTING_DATE_TXT,
                        TO_CHAR(TAILOR_DATE, \'YYYY-MM-DD\') AS TAILOR_DATE_TXT,
                        TO_CHAR(HANDING_DATE1, \'YYYY-MM-DD\') AS HANDING_DATE_TXT,
                        CUT_TRANSFER_NO, TAILOR_TRANSFER_NO, CALC_CUTTER, CALC_TAILOR,
                        CUT_QTY, CQTY, F_CANCEL, RTN_UPDATE, UPDATE_DONE, UPDATED_BY_NAME
                   FROM ACCOUNTS.MULTI_CLOTHS
                  WHERE BILL_NO = :bill_no AND YEAR = :year
                  ORDER BY SEQ_NO, SEQ_NO1',
                ['bill_no' => $billNo, 'year' => $year]
            )
        );
        $details = array_map(
            static fn ($row): array => (array) $row,
            DB::connection('oracle')->select(
                'SELECT BILL_NO, YEAR, SANF_NO, SANF_PRICE, QTY, CAP, PCS, TOTAL, SEQ,
                        DISCOUNT_RATE, DISCOUNT1, IS_LOCAL, STK_COST, OHID
                   FROM ACCOUNTS.SALES_DETAILES
                  WHERE BILL_NO = :bill_no AND YEAR = :year
                  ORDER BY SEQ',
                ['bill_no' => $billNo, 'year' => $year]
            )
        );
        $privilege = (array) DB::connection('oracle')->selectOne(
            'SELECT SEQ, CREATED_ON_HAND_S, CREATED_BY_HAND_S, LAST_UPDATED_ON_HAND_S,
                    LAST_UPDATED_BY_HAND_S, CREATED_BY_NAME_HAND_S, LAST_UPDATED_BY_NAME_HAND_S
               FROM ACCOUNTS.SALES_BILL_PRIVILAGE
              WHERE BILL_NO = :bill_no AND YEAR = :year',
            ['bill_no' => $billNo, 'year' => $year]
        );
        $entryNo = (int) CuttingViewReader::num($header, 'ENTRY_NO');
        $entry = $entryNo > 0
            ? (array) DB::connection('oracle')->selectOne(
                "SELECT ENTRY_NO, DETAILS, TO_CHAR(ENTRY_DATE, 'YYYY-MM-DD') AS ENTRY_DATE_ISO, ENTRY_TYPE_NO
                   FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO = :entry_no",
                ['entry_no' => $entryNo]
            )
            : [];
        $entryLines = $entryNo > 0
            ? array_map(
                static fn ($row): array => (array) $row,
                DB::connection('oracle')->select(
                    'SELECT SEQ, ENTRY_NO, ACC_NO, ENTRY_MNT, BALANCE_S, SEQ_PRICE,
                            SUB_DETAILS, INVOICE_NO, GIVING
                       FROM ACCOUNTS.ENTRY_SUB
                      WHERE ENTRY_NO = :entry_no
                      ORDER BY SEQ',
                    ['entry_no' => $entryNo]
                )
            )
            : [];
        $movement = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(ORDSID), 0) AS MAX_ORDSID, COUNT(*) AS N
               FROM ACCOUNTS.CUTING_TRANSD
              WHERE BILL_NO = :bill_no AND YEAR = :year AND NVL(CANCEL, 0) <> 1',
            ['bill_no' => $billNo, 'year' => $year]
        );

        $modelsBySeq = [];
        foreach ($shapes as $shape) {
            $seqNo = (int) CuttingViewReader::num($shape, 'SEQ_NO');
            $modelsBySeq[$seqNo] = [
                'seq_no' => $seqNo,
                'ohid' => CuttingViewReader::num($shape, 'OHID'),
                'ohdid' => CuttingViewReader::num($shape, 'OHDID'),
                'family' => CuttingViewReader::num($shape, 'SHAPE_STYLE_NO'),
                'styl_no' => CuttingViewReader::num($shape, 'STYL_NO'),
                'style_name' => CuttingViewReader::txt($shape, 'STYLE_NAME'),
                'beneficiary' => CuttingViewReader::txt($shape, 'BENEFICIARYNAME'),
                'notes' => CuttingViewReader::txt($shape, 'NOTES2'),
                'gabzor_hand' => (int) CuttingViewReader::num($shape, 'SHAPE_GABROZ_NO'),
                'qty' => CuttingViewReader::num($shape, 'QTY'),
                'request_no' => CuttingViewReader::num($shape, 'RQUEST_NO'),
                'measurements' => self::measurements($shape),
                'specs' => self::specs($shape),
                'item' => [
                    'sanf_no' => CuttingViewReader::num($shape, 'SANF_NO'),
                    'sanf_dno' => CuttingViewReader::num($shape, 'SANF_DNO'),
                    'prod_no' => CuttingViewReader::num($shape, 'PROD_NO'),
                ],
                'cutter_no' => CuttingViewReader::num($shape, 'CUTTER_NO'),
                'tailor_no' => CuttingViewReader::num($shape, 'TAILOR_NO'),
                'cutting_price' => CuttingViewReader::num($shape, 'CUTTER_PCS_PRICE'),
                'tailor_price' => CuttingViewReader::num($shape, 'TAILOR_PCS_PRICE'),
                'pieces' => [],
            ];
        }

        $maxPieceStage = 0;
        $cuttingEvidence = false;
        $gross = 0.0;
        foreach ($pieces as $piece) {
            $seqNo = (int) CuttingViewReader::num($piece, 'SEQ_NO');
            $stage = (int) CuttingViewReader::num($piece, 'ORDSDID');
            $maxPieceStage = max($maxPieceStage, $stage);
            $cuttingEvidence = $cuttingEvidence || self::hasCuttingEvidence($piece);
            $gross += (float) CuttingViewReader::num($piece, 'SANFPRICE');
            $modelsBySeq[$seqNo]['pieces'][] = self::piece($piece);
        }

        $customerNo = CuttingViewReader::num($header, 'CUST_NO');
        $customer = $customerNo === null ? null : TailoringCustomerResolver::find((int) $customerNo);
        $worker = self::workerForAccount(CuttingViewReader::num($header, 'ACC_NO'));
        $lineDiscount = 0.0;
        foreach ($details as $detail) {
            $lineDiscount += (float) CuttingViewReader::num($detail, 'DISCOUNT1');
        }
        $total = (float) CuttingViewReader::num($header, 'TOTAL');
        $paid = (float) CuttingViewReader::num($header, 'AMOUNT_PAID');
        $stage = max(
            (int) CuttingViewReader::num($header, 'ORDSID'),
            $maxPieceStage,
            (int) CuttingViewReader::num($movement, 'MAX_ORDSID')
        );

        $snapshot = [
            'identity' => [
                'bill_no' => $billNo,
                'year' => $year,
                'manual_bill_no' => CuttingViewReader::num($header, 'MANUAL_BILL_NO'),
                'ohid' => CuttingViewReader::num($header, 'OHID'),
                'entry_no' => $entryNo,
                'date' => CuttingViewReader::txt($header, 'DAT_ISO'),
                'bill_type' => CuttingViewReader::num($header, 'BILL_TYPE'),
                'bill_kind' => CuttingViewReader::num($header, 'BILLS_KINDS'),
                'branch_no' => CuttingViewReader::num($header, 'BRANCH_NO'),
            ],
            'customer' => $customer,
            'worker' => $worker,
            'header' => [
                'cust_no' => $customerNo,
                'cust_name' => CuttingViewReader::txt($header, 'CUST_NAME'),
                'tel_no1' => CuttingViewReader::txt($header, 'TEL_NO1'),
                'tel_no2' => CuttingViewReader::txt($header, 'TEL_NO2'),
                'address' => CuttingViewReader::txt($header, 'ADDRESS'),
                'ref_no' => CuttingViewReader::txt($header, 'REF_NO'),
                'details' => CuttingViewReader::txt($header, 'DETAILES'),
                'permanent_cust' => (int) CuttingViewReader::num($header, 'PERMANENT_CUST'),
                'urgent' => (int) CuttingViewReader::num($header, 'URGENT'),
                'giving_in' => CuttingViewReader::txt($header, 'GIVING_IN'),
                'measurements_by' => CuttingViewReader::txt($header, 'VOUCHER_NO'),
                'cust_known' => (int) CuttingViewReader::num($header, 'CUST_KNOWN'),
                'created_on' => CuttingViewReader::txt($header, 'CREATED_ON_TXT'),
                'last_updated_on' => CuttingViewReader::txt($header, 'LAST_UPDATED_ON_TXT'),
                'created_by' => CuttingViewReader::num($header, 'CREATED_BY'),
                'created_by_name' => CuttingViewReader::txt($header, 'CREATED_BY_NAME'),
                'last_updated_by' => CuttingViewReader::num($header, 'LAST_UPDATED_BY'),
                'last_updated_by_name' => CuttingViewReader::txt($header, 'LAST_UPDATED_BY_NAME'),
            ],
            'finance' => [
                'gross' => round($gross, 2),
                'line_discount' => round($lineDiscount, 2),
                'discount' => (float) CuttingViewReader::num($header, 'DISCOUNT'),
                'discount_rate' => (float) CuttingViewReader::num($header, 'DIS_RATE'),
                'total' => $total,
                'net' => (float) CuttingViewReader::num($header, 'NET'),
                'paid' => $paid,
                'remaining' => round($total - $paid, 2),
                'acc_no' => CuttingViewReader::num($header, 'ACC_NO'),
                'acc_no1' => CuttingViewReader::num($header, 'ACC_NO1'),
                'acc_no2' => CuttingViewReader::num($header, 'ACC_NO2'),
                'cur_no' => CuttingViewReader::num($header, 'CUR_NO'),
                'cur_price' => CuttingViewReader::num($header, 'CUR_PRICE'),
                'cur_no1' => CuttingViewReader::num($header, 'CUR_NO1'),
                'cur_price1' => CuttingViewReader::num($header, 'CUR_PRICE1'),
                'select1' => CuttingViewReader::num($header, 'SELECT1'),
                'transfer_no' => CuttingViewReader::txt($header, 'FIRST_TRANSFER'),
                'transfer_through' => CuttingViewReader::txt($header, 'TRANSFER_THROUGH'),
            ],
            'models' => array_values($modelsBySeq),
            'sales_details' => $details,
            'privilege' => $privilege,
            'journal' => [
                'entry' => $entry,
                'lines' => $entryLines,
            ],
            'stage' => [
                'max_ordsid' => $stage,
                'header_ordsid' => CuttingViewReader::num($header, 'ORDSID'),
                'max_piece_ordsdid' => $maxPieceStage,
                'movement_count' => (int) CuttingViewReader::num($movement, 'N'),
                'cutting_evidence' => $cuttingEvidence,
            ],
            'external_links' => self::externalLinks($billNo, $year, $entryNo),
            'legacy' => [
                'preserved_fields' => [
                    'shapes' => array_map(static fn ($shape): array => [
                        'seq_no' => CuttingViewReader::num($shape, 'SEQ_NO'),
                        'l11' => CuttingViewReader::num($shape, 'L11'),
                        'l12' => CuttingViewReader::num($shape, 'L12'),
                        'hegazi_type' => CuttingViewReader::txt($shape, 'HEGAZI_TYPE'),
                        'tipper_type' => CuttingViewReader::txt($shape, 'TIPPER_TYPE'),
                        'neck_shape_no' => CuttingViewReader::num($shape, 'NECK_SHAPE_NO'),
                        'shape_com_cabak_no' => CuttingViewReader::num($shape, 'SHAPE_COM_CABAK_NO'),
                        'shape_pocket_up_no' => CuttingViewReader::num($shape, 'SHAPE_POCKET_UP_NO'),
                        'shape_pocket_down_no' => CuttingViewReader::num($shape, 'SHAPE_POCKET_DOWN_NO'),
                    ], $shapes),
                    'pieces' => array_map(static fn ($piece): array => [
                        'seq_no1' => CuttingViewReader::num($piece, 'SEQ_NO1'),
                        'color2' => CuttingViewReader::txt($piece, 'CLOTHS_COLOR2'),
                        'shelf_no' => CuttingViewReader::txt($piece, 'SHELF_NO'),
                        'our_selection' => CuttingViewReader::num($piece, 'CLOTHS_OUR_SELECTION'),
                        'fixed_flexed' => CuttingViewReader::txt($piece, 'FIXED_FLEXED'),
                    ], $pieces),
                ],
            ],
        ];

        $snapshot['capability'] = TailoringEditPolicy::evaluate($snapshot);
        $snapshot['revision'] = self::revision($snapshot);

        return ArabicEncoding::cleanData($snapshot);
    }

    /**
     * قائمة فواتير التعديل من الجدول الأساسي مع بحث وترقيم Oracle 11g.
     * Edit invoice list from the base table with Oracle 11g paging.
     */
    public static function list(array $filters): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 20), 200));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $where = ['b.CUTTING_OR_SLAES = 1'];
        $bindings = [];

        if (! empty($filters['from_date'])) {
            $where[] = "b.DAT >= TO_DATE(:from_date, 'YYYY-MM-DD')";
            $bindings['from_date'] = (string) $filters['from_date'];
        }
        if (! empty($filters['to_date'])) {
            $where[] = "TRUNC(b.DAT) <= TO_DATE(:to_date, 'YYYY-MM-DD')";
            $bindings['to_date'] = (string) $filters['to_date'];
        }
        if (! empty($filters['year'])) {
            $where[] = 'b.YEAR = :year';
            $bindings['year'] = (int) $filters['year'];
        }
        if (! empty($filters['bill_kind'])) {
            $where[] = 'b.BILLS_KINDS = :bill_kind';
            $bindings['bill_kind'] = (int) $filters['bill_kind'];
        }
        if (! empty($filters['branch'])) {
            $where[] = 'b.BRANCH_NO = :branch';
            $bindings['branch'] = (int) $filters['branch'];
        }
        if (isset($filters['urgent']) && $filters['urgent'] !== '') {
            $where[] = 'b.URGENT = :urgent';
            $bindings['urgent'] = (int) $filters['urgent'];
        }
        if (($filters['delivery'] ?? '') === 'delivered') {
            $where[] = 'EXISTS (SELECT 1 FROM ACCOUNTS.MULTI_CLOTHS d'
                .' WHERE d.BILL_NO = b.BILL_NO AND d.YEAR = b.YEAR AND d.HANDING_DONE1 = 1)';
        } elseif (($filters['delivery'] ?? '') === 'pending') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM ACCOUNTS.MULTI_CLOTHS d'
                .' WHERE d.BILL_NO = b.BILL_NO AND d.YEAR = b.YEAR AND d.HANDING_DONE1 = 1)';
        }
        if (! empty($filters['stage'])) {
            $where[] = '(b.ORDSID = :stage'
                .' OR EXISTS (SELECT 1 FROM ACCOUNTS.MULTI_CLOTHS p WHERE p.BILL_NO = b.BILL_NO AND p.YEAR = b.YEAR AND p.ORDSDID = :stage)'
                .' OR EXISTS (SELECT 1 FROM ACCOUNTS.CUTING_TRANSD m WHERE m.BILL_NO = b.BILL_NO AND m.YEAR = b.YEAR AND m.ORDSID = :stage AND NVL(m.CANCEL, 0) <> 1))';
            $bindings['stage'] = (int) $filters['stage'];
        }
        $payment = (string) ($filters['payment'] ?? '');
        if ($payment === 'paid') {
            $where[] = 'NVL(b.AMOUNT_PAID, 0) >= NVL(b.TOTAL, 0)';
        } elseif ($payment === 'partial') {
            $where[] = 'NVL(b.AMOUNT_PAID, 0) > 0 AND NVL(b.AMOUNT_PAID, 0) < NVL(b.TOTAL, 0)';
        } elseif ($payment === 'unpaid') {
            $where[] = 'NVL(b.AMOUNT_PAID, 0) <= 0';
        }
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $oracle = ArabicEncoding::toOracleSearchChars($search);
            $where[] = '(b.BILL_NO = :search_bill_no OR b.MANUAL_BILL_NO = :search_manual_no
                OR b.CUST_NAME LIKE :search_customer_oracle OR b.CUST_NAME LIKE :search_customer_plain
                OR b.TEL_NO1 LIKE :search_phone_plain
                OR EXISTS (SELECT 1 FROM ACCOUNTS.SALES_DETAILES_SHAPES sh
                            WHERE sh.BILL_NO = b.BILL_NO AND sh.YEAR = b.YEAR
                              AND (sh.BENEFICIARYNAME LIKE :search_beneficiary_oracle OR sh.BENEFICIARYNAME LIKE :search_beneficiary_plain)))';
            $bindings['search_bill_no'] = is_numeric($search) ? (int) $search : -1;
            $bindings['search_manual_no'] = is_numeric($search) ? (int) $search : -1;
            $bindings['search_customer_oracle'] = '%'.($oracle ?? $search).'%';
            $bindings['search_customer_plain'] = '%'.$search.'%';
            $bindings['search_phone_plain'] = '%'.$search.'%';
            $bindings['search_beneficiary_oracle'] = '%'.($oracle ?? $search).'%';
            $bindings['search_beneficiary_plain'] = '%'.$search.'%';
        }

        $sort = in_array($filters['sort'] ?? '', ['bill_no', 'manual_no', 'date', 'customer', 'total'], true)
            ? (string) $filters['sort']
            : 'date';
        $expr = match ($sort) {
            'bill_no' => 'b.BILL_NO',
            'manual_no' => 'b.MANUAL_BILL_NO',
            'customer' => 'b.CUST_NAME',
            'total' => 'b.TOTAL',
            default => 'b.DAT',
        };
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $whereSql = implode(' AND ', $where);
        $total = (int) self::first(DB::connection('oracle')->selectOne(
            "SELECT COUNT(*) AS N FROM ACCOUNTS.SALES_BILL b WHERE {$whereSql}",
            $bindings
        ));
        $keyRows = DB::connection('oracle')->select(
            "SELECT BILL_NO, YEAR FROM (
                SELECT BILL_NO, YEAR, ROWNUM AS RN FROM (
                    SELECT b.BILL_NO, b.YEAR, {$expr} AS SRT
                      FROM ACCOUNTS.SALES_BILL b
                     WHERE {$whereSql}
                     ORDER BY SRT {$dir}, b.BILL_NO DESC, b.YEAR DESC
                ) WHERE ROWNUM <= :row_max
             ) WHERE RN > :row_min",
            array_merge($bindings, ['row_max' => $offset + $limit, 'row_min' => $offset])
        );
        $pairs = [];
        $pageBindings = [];
        foreach ($keyRows as $i => $keyRow) {
            $key = (array) $keyRow;
            $pairs[] = '(:b'.$i.', :y'.$i.')';
            $pageBindings['b'.$i] = (int) CuttingViewReader::num($key, 'BILL_NO');
            $pageBindings['y'.$i] = (int) CuttingViewReader::num($key, 'YEAR');
        }
        if ($pairs === []) {
            return ['items' => [], 'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int) ceil($total / $limit)]];
        }
        $pairSql = implode(', ', $pairs);
        $headers = [];
        foreach (DB::connection('oracle')->select(
            "SELECT BILL_NO, YEAR, MANUAL_BILL_NO, CUST_NO, CUST_NAME, TEL_NO1, URGENT,
                    TO_CHAR(DAT, 'YYYY-MM-DD') AS DAT_ISO, TOTAL, AMOUNT_PAID, BILLS_KINDS, BRANCH_NO, ORDSID
               FROM ACCOUNTS.SALES_BILL WHERE (BILL_NO, YEAR) IN ({$pairSql})",
            $pageBindings
        ) as $row) {
            $record = (array) $row;
            $headers[((int) CuttingViewReader::num($record, 'BILL_NO')).'/'.((int) CuttingViewReader::num($record, 'YEAR'))] = $record;
        }
        $modelCounts = [];
        foreach (DB::connection('oracle')->select(
            "SELECT BILL_NO, YEAR, COUNT(*) AS N FROM ACCOUNTS.SALES_DETAILES_SHAPES
              WHERE (BILL_NO, YEAR) IN ({$pairSql}) GROUP BY BILL_NO, YEAR",
            $pageBindings
        ) as $row) {
            $record = (array) $row;
            $modelCounts[((int) CuttingViewReader::num($record, 'BILL_NO')).'/'.((int) CuttingViewReader::num($record, 'YEAR'))] = (int) CuttingViewReader::num($record, 'N');
        }
        $pieceStats = [];
        foreach (DB::connection('oracle')->select(
            "SELECT BILL_NO, YEAR, COUNT(*) AS N, NVL(MAX(ORDSDID), 0) AS STAGE,
                    MAX(NVL(CUTTING_DONE, 0)) AS CUT_DONE,
                    MAX(NVL(TAILOR_DONE, 0)) AS TAILOR_DONE,
                    MAX(NVL(HANDING_DONE1, 0)) AS HAND_DONE,
                    MAX(NVL(CUT_TRANSFER_NO, 0)) AS CUT_TRANSFER,
                    MAX(NVL(TAILOR_TRANSFER_NO, 0)) AS TAILOR_TRANSFER,
                    MAX(NVL(CUT_QTY, 0)) AS CUT_QTY, MAX(NVL(CQTY, 0)) AS CQTY,
                    MAX(NVL(CALC_CUTTER, 0)) AS CALC_CUTTER, MAX(NVL(CALC_TAILOR, 0)) AS CALC_TAILOR,
                    MAX(CASE WHEN CUTTING_DATE IS NOT NULL OR TAILOR_DATE IS NOT NULL OR HANDING_DATE1 IS NOT NULL THEN 1 ELSE 0 END) AS HAS_DATE
               FROM ACCOUNTS.MULTI_CLOTHS WHERE (BILL_NO, YEAR) IN ({$pairSql})
              GROUP BY BILL_NO, YEAR",
            $pageBindings
        ) as $row) {
            $record = (array) $row;
            $pieceStats[((int) CuttingViewReader::num($record, 'BILL_NO')).'/'.((int) CuttingViewReader::num($record, 'YEAR'))] = $record;
        }
        $movementStages = [];
        foreach (DB::connection('oracle')->select(
            "SELECT BILL_NO, YEAR, NVL(MAX(ORDSID), 0) AS STAGE FROM ACCOUNTS.CUTING_TRANSD
              WHERE (BILL_NO, YEAR) IN ({$pairSql}) AND NVL(CANCEL, 0) <> 1
              GROUP BY BILL_NO, YEAR",
            $pageBindings
        ) as $row) {
            $record = (array) $row;
            $movementStages[((int) CuttingViewReader::num($record, 'BILL_NO')).'/'.((int) CuttingViewReader::num($record, 'YEAR'))] = (int) CuttingViewReader::num($record, 'STAGE');
        }
        $items = [];
        foreach ($keyRows as $keyRow) {
            $key = (array) $keyRow;
            $lookup = ((int) CuttingViewReader::num($key, 'BILL_NO')).'/'.((int) CuttingViewReader::num($key, 'YEAR'));
            $record = $headers[$lookup] ?? null;
            if ($record === null) {
                continue;
            }
            $stats = $pieceStats[$lookup] ?? ['N' => 0, 'STAGE' => 0];
            $stage = max((int) CuttingViewReader::num($record, 'ORDSID'), (int) CuttingViewReader::num($stats, 'STAGE'), $movementStages[$lookup] ?? 0);
            $evidence = false;
            foreach (['CUT_DONE', 'TAILOR_DONE', 'HAND_DONE', 'CUT_TRANSFER', 'TAILOR_TRANSFER', 'CUT_QTY', 'CQTY', 'CALC_CUTTER', 'CALC_TAILOR', 'HAS_DATE'] as $flag) {
                $evidence = $evidence || (float) CuttingViewReader::num($stats, $flag) != 0.0;
            }
            $capability = TailoringEditPolicy::evaluate([
                'stage' => ['max_ordsid' => $stage, 'cutting_evidence' => $evidence],
            ]);
            $items[] = ArabicEncoding::cleanData([
                'bill_no' => (int) CuttingViewReader::num($record, 'BILL_NO'),
                'year' => (int) CuttingViewReader::num($record, 'YEAR'),
                'manual_bill_no' => (int) CuttingViewReader::num($record, 'MANUAL_BILL_NO'),
                'date' => CuttingViewReader::txt($record, 'DAT_ISO'),
                'cust_no' => (int) CuttingViewReader::num($record, 'CUST_NO'),
                'cust_name' => CuttingViewReader::txt($record, 'CUST_NAME'),
                'tel_no1' => CuttingViewReader::txt($record, 'TEL_NO1'),
                'urgent' => (int) CuttingViewReader::num($record, 'URGENT'),
                'total' => (float) CuttingViewReader::num($record, 'TOTAL'),
                'paid' => (float) CuttingViewReader::num($record, 'AMOUNT_PAID'),
                'remaining' => round((float) CuttingViewReader::num($record, 'TOTAL') - (float) CuttingViewReader::num($record, 'AMOUNT_PAID'), 2),
                'bill_kind' => (int) CuttingViewReader::num($record, 'BILLS_KINDS'),
                'branch_no' => (int) CuttingViewReader::num($record, 'BRANCH_NO'),
                'models_count' => $modelCounts[$lookup] ?? 0,
                'pieces_count' => (int) CuttingViewReader::num($stats, 'N'),
                'stage' => $stage,
                'capability' => $capability,
            ]);
        }

        return [
            'items' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * خيارات فلترة قائمة التعديل.
     * Edit-list filter options.
     */
    public static function filters(): array
    {
        $years = DB::connection('oracle')->select('SELECT DISTINCT YEAR FROM ACCOUNTS.SALES_BILL WHERE CUTTING_OR_SLAES = 1 ORDER BY YEAR DESC');
        $kinds = DB::connection('oracle')->select('SELECT DISTINCT BILLS_KINDS FROM ACCOUNTS.SALES_BILL WHERE CUTTING_OR_SLAES = 1 AND BILLS_KINDS IS NOT NULL ORDER BY BILLS_KINDS');
        $branches = DB::connection('oracle')->select('SELECT DISTINCT BRANCH_NO FROM ACCOUNTS.SALES_BILL WHERE CUTTING_OR_SLAES = 1 AND BRANCH_NO IS NOT NULL ORDER BY BRANCH_NO');

        return [
            'years' => array_map(static fn ($row): int => (int) CuttingViewReader::num((array) $row, 'YEAR'), $years),
            'bill_kinds' => array_map(static fn ($row): int => (int) CuttingViewReader::num((array) $row, 'BILLS_KINDS'), $kinds),
            'branches' => array_map(static fn ($row): int => (int) CuttingViewReader::num((array) $row, 'BRANCH_NO'), $branches),
        ];
    }

    private static function piece(array $piece): array
    {
        return [
            'seq_no1' => CuttingViewReader::num($piece, 'SEQ_NO1'),
            'seq_no' => CuttingViewReader::num($piece, 'SEQ_NO'),
            'ohdid' => CuttingViewReader::num($piece, 'OHDID'),
            'sanf_no' => CuttingViewReader::num($piece, 'SANF_NO'),
            'sanf_dno' => CuttingViewReader::num($piece, 'SANF_DNO'),
            'prod_no' => CuttingViewReader::num($piece, 'PROD_NO'),
            'cloth_name' => CuttingViewReader::txt($piece, 'CLOTH_NAME'),
            'cloth_type' => CuttingViewReader::txt($piece, 'CLOTH_TYPE'),
            'color_no' => CuttingViewReader::num($piece, 'CLOTHS_COLOR'),
            'color_text' => CuttingViewReader::txt($piece, 'CLOTHS_COLOR2'),
            'shape_style_no' => CuttingViewReader::num($piece, 'SHAPE_STYLE_NO'),
            'style_name' => CuttingViewReader::txt($piece, 'STYLE_NAME'),
            'qty' => CuttingViewReader::num($piece, 'QTY'),
            'hinsh' => CuttingViewReader::num($piece, 'CONSUMED_CLOTH_QTY_HINSH'),
            'yards' => CuttingViewReader::num($piece, 'CONSUMED_CLOTH_QTY_YARDS'),
            'hinsh1' => CuttingViewReader::num($piece, 'CONSUMED_CLOTH_QTY_HINSH1'),
            'sharkh' => CuttingViewReader::txt($piece, 'SHARKH'),
            'price' => CuttingViewReader::num($piece, 'SANFPRICE'),
            'sanf_cost' => CuttingViewReader::num($piece, 'SANF_COST'),
            'stk_cost' => CuttingViewReader::num($piece, 'STK_COST'),
            'from_store' => CuttingViewReader::num($piece, 'CLOTH_FRM_LOCAL_STORE'),
            'shelf_no' => CuttingViewReader::txt($piece, 'SHELF_NO'),
            'gabzor_hand' => (int) CuttingViewReader::num($piece, 'GABZOR_HAND'),
            'tatryz_in' => (int) CuttingViewReader::num($piece, 'TATRYZ_IN'),
            'tatryz_no' => CuttingViewReader::num($piece, 'TATRYZ_NO'),
            'habna_duabl' => (int) CuttingViewReader::num($piece, 'HABNA_DUABL'),
            'button_no' => CuttingViewReader::txt($piece, 'BUTTONNO'),
            'cutter_no' => CuttingViewReader::num($piece, 'CUTTER_NO1'),
            'tailor_no' => CuttingViewReader::num($piece, 'TAILOR_NO1'),
            'cutting_price' => CuttingViewReader::num($piece, 'CUTTER_PCS_PRICE1'),
            'tailor_price' => CuttingViewReader::num($piece, 'TAILOR_PCS_PRICE1'),
            'cutting_done' => (int) CuttingViewReader::num($piece, 'CUTTING_DONE'),
            'tailor_done' => (int) CuttingViewReader::num($piece, 'TAILOR_DONE'),
            'handing_done' => (int) CuttingViewReader::num($piece, 'HANDING_DONE1'),
            'ordsdid' => (int) CuttingViewReader::num($piece, 'ORDSDID'),
            'cutting_date' => CuttingViewReader::txt($piece, 'CUTTING_DATE_TXT'),
            'tailor_date' => CuttingViewReader::txt($piece, 'TAILOR_DATE_TXT'),
            'handing_date' => CuttingViewReader::txt($piece, 'HANDING_DATE_TXT'),
            'cut_transfer_no' => CuttingViewReader::num($piece, 'CUT_TRANSFER_NO'),
            'tailor_transfer_no' => CuttingViewReader::num($piece, 'TAILOR_TRANSFER_NO'),
            'f_cancel' => (int) CuttingViewReader::num($piece, 'F_CANCEL'),
        ];
    }

    private static function measurements(array $shape): array
    {
        $out = [];
        foreach (range(1, 12) as $i) {
            $out['l'.$i] = CuttingViewReader::num($shape, 'L'.$i);
        }

        return $out;
    }

    private static function specs(array $shape): array
    {
        return [
            'button_type' => CuttingViewReader::txt($shape, 'BUTTON_TYPE'),
            'button_no' => CuttingViewReader::txt($shape, 'BUTTON_NO'),
            'buttons_num' => CuttingViewReader::num($shape, 'BUTTONS_NUM'),
            'com_cabak_type' => CuttingViewReader::txt($shape, 'COM_CABAK_TYPE'),
            'neck_type' => CuttingViewReader::txt($shape, 'NECK_TYPE'),
            'pocket_or_without' => CuttingViewReader::txt($shape, 'POCKET_OR_WITHOUT'),
            'gabzor_type' => CuttingViewReader::txt($shape, 'GABZOR_TYPE'),
            'mobile_pocket' => CuttingViewReader::txt($shape, 'MOBILE_POCKET'),
            'pen_pocket' => CuttingViewReader::txt($shape, 'PEN_POCKET'),
            'hegazi_type' => CuttingViewReader::txt($shape, 'HEGAZI_TYPE'),
            'tipper_type' => CuttingViewReader::txt($shape, 'TIPPER_TYPE'),
        ];
    }

    private static function hasCuttingEvidence(array $piece): bool
    {
        foreach (['CUTTING_DONE', 'TAILOR_DONE', 'HANDING_DONE1', 'CUT_TRANSFER_NO', 'TAILOR_TRANSFER_NO', 'CUT_QTY', 'CQTY', 'CALC_CUTTER', 'CALC_TAILOR'] as $field) {
            if ((float) CuttingViewReader::num($piece, $field) != 0.0) {
                return true;
            }
        }

        return CuttingViewReader::txt($piece, 'CUTTING_DATE_TXT') !== null
            || CuttingViewReader::txt($piece, 'TAILOR_DATE_TXT') !== null
            || CuttingViewReader::txt($piece, 'HANDING_DATE_TXT') !== null;
    }

    private static function workerForAccount(int|float|null $accNo): ?array
    {
        if ($accNo === null || (int) $accNo <= 0) {
            return null;
        }
        $rows = DB::connection('oracle')->select(
            "SELECT 'employee' AS WORKER_TYPE, EMP_NO AS WORKER_NO, EMP_NAME AS WORKER_NAME, ACC_NO FROM ACCOUNTS.TAILOR_EMPLOYEES WHERE ACC_NO = :acc_employee UNION ALL SELECT 'cutter', CUTTER_NO, CUTTER_NAME, ACC_NO FROM ACCOUNTS.CUTTER WHERE ACC_NO = :acc_cutter UNION ALL SELECT 'tailor', TAILOR_NO, TAILOR_NAME, ACC_NO FROM ACCOUNTS.TAILOR WHERE ACC_NO = :acc_tailor",
            ['acc_employee' => (int) $accNo, 'acc_cutter' => (int) $accNo, 'acc_tailor' => (int) $accNo]
        );

        return $rows === [] ? null : ArabicEncoding::cleanData((array) $rows[0]);
    }

    private static function externalLinks(int $billNo, int $year, int $entryNo): array
    {
        $tables = [
            ['ACCOUNTS.CASH_RECEIVE', 'BILL_NO', 'BILL_YEAR'],
            ['ACCOUNTS.BANK_RECEIVE', 'BILL_NO', 'BILL_YEAR'],
            ['ACCOUNTS.RETURN_SALES', 'BILL_NO', 'BILL_YEAR'],
            ['ACCOUNTS.DEBITS_ACC_BILLS', 'BILL_NO', 'YEAR'],
            ['ACCOUNTS.DISCOUNTS_BILLS', 'BILL_NO', 'YEAR'],
        ];
        $counts = [];
        foreach ($tables as [$table, $billColumn, $yearColumn]) {
            $counts[$table] = (int) self::first(DB::connection('oracle')->selectOne(
                "SELECT COUNT(*) AS N FROM {$table} WHERE {$billColumn} = :bill_no AND {$yearColumn} = :year",
                ['bill_no' => $billNo, 'year' => $year]
            ));
        }
        $entryLinks = 0;
        if ($entryNo > 0) {
            $entryLinks = (int) self::first(DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS N FROM ACCOUNTS.ENTRY_MAIN_PRIVILAGE WHERE ENTRY_NO = :entry_no',
                ['entry_no' => $entryNo]
            ));
        }
        $counts['entry_audit'] = $entryLinks;

        return $counts;
    }

    private static function first(mixed $row): mixed
    {
        return $row === null ? null : array_values((array) $row)[0] ?? null;
    }

    private static function revision(array $snapshot): string
    {
        $canonical = [
            'identity' => $snapshot['identity'],
            'header' => $snapshot['header'],
            'finance' => $snapshot['finance'],
            'models' => $snapshot['models'],
            'journal' => $snapshot['journal'],
            'stage' => $snapshot['stage'],
            'external_links' => $snapshot['external_links'],
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
