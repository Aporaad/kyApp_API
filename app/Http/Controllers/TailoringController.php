<?php

namespace App\Http\Controllers;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\PermissionResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم قسم التفصيل — قراءة فقط من واجهة ACCOUNTS.CUTTING_SALES_BILL_VIEW
 * Tailoring controller — read-only over ACCOUNTS.CUTTING_SALES_BILL_VIEW.
 *
 * مصدر البيانات المتفق عليه هو الواجهة حصراً للقائمة والموديلات والمقاسات والأقمشة والحالة الحالية.
 * The agreed data source is the view alone for the list, models, measurements, fabrics and the
 * current stage.
 *
 * ثلاثة استثناءات موثّقة، وكلها قراءة لجداول لا تحملها الواجهة:
 * Three documented exceptions, all reads of tables the view does not carry:
 *  1. SALES_BILL — صف واحد بهوية الفاتورة لخانات الدفع والطلب والتسليم.
 *     One SALES_BILL row by the bill's identity, for payment/order/delivery fields.
 *  2. CUTING_TRANSD + ORDSTATE — سجل الحركة، لأن الواجهة تحمل الحالة الحالية فقط.
 *     The movement log, because the view only carries the current stage.
 *  3. SALES_DETAILES_SHAPES + STYLE — نصوص المواصفات واسم الشكل، وهي أعمدة غير موجودة في الواجهة
 *     إطلاقاً (الواجهة تمرّر المقاسات L1..L12 ونوع الموديل فقط). بلا هذه القراءة يسقط مطلب
 *     «المواصفات الخاصة بالموديل» من الشاشة.
 *     Spec texts and the shape name, columns the view does not expose at all (it passes the
 *     L1..L12 measurements and the type name only). Without this read the "model
 *     specifications" requirement cannot be shown.
 *
 * قاعدة التقييد: مفتاح الفاتورة مركّب (BILL_NO, YEAR). كل مسار هنا يحمل الرقمين معاً، لأن
 * BILL_NO وحده غير فريد (87,345 صفاً مقابل 28,975 رقماً مميزاً) و`InvoiceController::show()`
 * يعيد أول صف مطابق — وهذا خطأ لا يجوز تكراره.
 * Key rule: the bill identity is the composite (BILL_NO, YEAR). Every route carries both numbers,
 * because BILL_NO alone is not unique and taking the first match would return another year's bill.
 */
class TailoringController extends Controller
{
    private const VIEW_CUTTING_BILL = 'ACCOUNTS.CUTTING_SALES_BILL_VIEW';

    private const TABLE_SALES_BILL = 'ACCOUNTS.SALES_BILL';

    private const TABLE_SHAPES = 'ACCOUNTS.SALES_DETAILES_SHAPES';

    private const TABLE_STYLE = 'ACCOUNTS.STYLE';

    private const TABLE_TYPES = 'ACCOUNTS.TYPES';

    private const TABLE_ORDSTATE = 'ACCOUNTS.ORDSTATE';

    private const TABLE_TRANSD = 'ACCOUNTS.CUTING_TRANSD';

    private const TABLE_DEPTS = 'ACCOUNTS.DEPTS';

    private const TABLE_LABELS = 'ACCOUNTS.GENERAL_LABELS_CODING';

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    /** أقصى عدد قطع يُقبل في طلب سجل الحركة — سقف صريح كي لا تُبنى قائمة ربط ضخمة
     *  Upper bound on pieces per movement request, so no huge bind list is ever built. */
    private const MAX_MOVEMENT_SEQ_NOS = 200;

    /** القيمة الحرفية التي يكتبها النظام القديم بدل ترك الخانة فارغة
     *  The literal the legacy system writes instead of leaving the field empty. */
    private const NO_TAILOR_SENTINEL = 'لا يوجد';

    /**
     * قائمة الفواتير: الأحدث أولاً مع البحث والفلترة الزمنية والتصفح
     * Invoice list: newest first, with search, date range and pagination.
     *
     * كل التجميع يقع على الواجهة نفسها، فلا يُقرأ جدول آخر في هذا المسار.
     * All aggregation happens on the view itself, so no other table is read on this route.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = (int) ($request->input('limit') ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $filters = $this->listFilters($request, $search);
            $where = $filters['where'];
            $having = $filters['having'];
            $bindings = $filters['bindings'];

            // ثلاثة استعلامات بدل استعلام واحد، لأن كل واحد منها قيس على القاعدة الحيّة:
            //  · العدّ على مفتاحين فقط (0.8s) بدل تجميع 14 عموداً لكل الفواتير (4.8s).
            //  · مفاتيح الصفحة وترتيبها (1s) — الترتيب بالاسم المستعار D لا بالتعبير، وإلا فشل
            //    قطع الصفوف (stopkey) وسقط الأداء إلى 6s.
            //  · تجميع صفحة واحدة فقط بقائمة زوج (رقم، سنة) مربوطة (1s) بدل مسح كامل (4.9s).
            // Three queries rather than one, each measured on the live database: the count over
            // the key only, the page keys ordered by the alias (stopkey needs the alias), and the
            // aggregates for one page of composite keys.
            $from = ' FROM '.self::VIEW_CUTTING_BILL.' v'
                .($where === '' ? '' : ' WHERE '.$where)
                .' GROUP BY v.BILL_NO, v.YEAR'
                .($having === '' ? '' : ' HAVING '.$having);

            $totalRow = DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS AGGREGATE FROM (SELECT v.BILL_NO, v.YEAR'.$from.') t',
                $bindings
            );

            $total = (int) ($this->value((array) $totalRow, 'AGGREGATE') ?? 0);

            // Oracle 11g بلا OFFSET/FETCH: التصفح بـ ROWNUM داخل غلافين.
            // Oracle 11g has no OFFSET/FETCH: paging is done with ROWNUM in two wrappers.
            $keyRows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT p.BILL_NO, p.YEAR, ROWNUM AS RN FROM (
                        SELECT v.BILL_NO, v.YEAR, MAX(v.DAT) AS D'.$from.'
                        ORDER BY D DESC, v.BILL_NO DESC
                    ) p WHERE ROWNUM <= :row_max
                 ) WHERE RN > :row_min',
                array_merge($bindings, [
                    'row_max' => $offset + $limit,
                    'row_min' => $offset,
                ])
            );

            $items = $this->pageSummaries($keyRows);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب فواتير التفصيل بنجاح / Cutting bills retrieved',
                'data' => $items,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب فواتير التفصيل / Failed to retrieve cutting bills', $e);
        }
    }

    /**
     * تجميع صفحة واحدة من المفاتيح المرتّبة، مع الحفاظ على ترتيب الصفحة
     * Aggregate one page of ordered keys, preserving the page order.
     *
     * @param  array<int, mixed>  $keyRows
     * @return array<int, array<string, mixed>>
     */
    private function pageSummaries(array $keyRows): array
    {
        $keys = [];

        foreach ($keyRows as $row) {
            $keys[] = [
                'bill_no' => $this->num((array) $row, 'BILL_NO'),
                'year' => $this->num((array) $row, 'YEAR'),
            ];
        }

        if ($keys === []) {
            return [];
        }

        $pairs = [];
        $bindings = [];

        foreach ($keys as $index => $key) {
            $pairs[] = '(:b'.$index.', :y'.$index.')';
            $bindings['b'.$index] = (int) $key['bill_no'];
            $bindings['y'.$index] = (int) $key['year'];
        }

        $rows = DB::connection('oracle')->select(
            'SELECT v.BILL_NO,
                    v.YEAR,
                    TO_CHAR(MAX(v.DAT), \'YYYY-MM-DD\') AS DAT_ISO,
                    TO_CHAR(MAX(v.DAT), \'DD/MM/YYYY\') AS DAT_TXT,
                    MAX(v.MANUAL_BILL_NO) AS MANUAL_BILL_NO,
                    MAX(v.CUST_NO) AS CUST_NO,
                    MAX(v.CUST_NAME) AS CUST_NAME,
                    MAX(v.TEL_NO1) AS TEL_NO1,
                    COUNT(DISTINCT v.SEQ_NO) AS MODELS_COUNT,
                    COUNT(*) AS PIECES_COUNT,
                    MAX(v.TOTAL) AS TOTAL,
                    MAX(v.DISCOUNT) AS DISCOUNT,
                    MAX(v.NET) AS NET,
                    MAX(v.HANDING_DONE) AS HANDING_DONE,
                    MAX(v.ORDSDID) AS ORDSDID
               FROM '.self::VIEW_CUTTING_BILL.' v
              WHERE (v.BILL_NO, v.YEAR) IN ('.implode(', ', $pairs).')
              GROUP BY v.BILL_NO, v.YEAR',
            $bindings
        );

        // الاستعلام لا يضمن ترتيب الصفوف، فالترتيب يُعاد من قائمة المفاتيح نفسها.
        // The batch query carries no order, so the page order is restored from the key list.
        $byKey = [];

        foreach ($rows as $row) {
            $summary = $this->billSummary((array) $row);
            $byKey[$summary['bill_no'].'/'.$summary['year']] = $summary;
        }

        $ordered = [];

        foreach ($keys as $key) {
            $lookup = $key['bill_no'].'/'.$key['year'];

            if (isset($byKey[$lookup])) {
                $ordered[] = $byKey[$lookup];
            }
        }

        return $ordered;
    }

    /**
     * تفصيل فاتورة واحدة: الترويسة + الموديلات بقطعها + المقاسات + المواصفات + الدفع
     * One bill in full: header, models with their pieces, measurements, specs and payment.
     */
    public function show(Request $request, int $billNo, int $year): JsonResponse
    {
        try {
            $rows = DB::connection('oracle')->select(
                $this->detailSql(),
                ['bill_no' => $billNo, 'year' => $year]
            );

            if ($rows === []) {
                return response()->json([
                    'success' => false,
                    'message' => "فاتورة التفصيل رقم [{$billNo}] لسنة [{$year}] غير موجودة في قائمة الفواتير المُسندة "
                        .'/ Cutting bill not found among the assigned bills',
                    'not_found_in_view' => true,
                ], 404);
            }

            $records = array_map(static fn ($row): array => (array) $row, $rows);
            $models = $this->groupModels($records);
            $headerRow = $records[0];

            $orderState = $this->orderStateOf(
                $headerRow['ORDSDID'] ?? $headerRow['ordsdid'] ?? null
            );

            $header = [
                'bill_no' => $billNo,
                'year' => $year,
                'manual_bill_no' => $this->num($headerRow, 'MANUAL_BILL_NO'),
                'date' => $this->txt($headerRow, 'DAT_TXT'),
                'raw_date' => $this->txt($headerRow, 'DAT_ISO'),
                'cust_no' => $this->num($headerRow, 'CUST_NO'),
                'cust_name' => $this->txt($headerRow, 'CUST_NAME'),
                'tel_no1' => $this->txt($headerRow, 'TEL_NO1'),
                'acc_no' => $this->num($headerRow, 'ACC_NO'),
                'group_no' => $this->num($headerRow, 'GROUP_NO'),
                'giving_date' => $this->txt($headerRow, 'GIVING_DATE_TXT'),
                'handing_done' => $this->num($headerRow, 'HANDING_DONE'),
                'handing_date' => $this->txt($headerRow, 'HANDING_DATE_TXT'),
                'ordsdid' => (int) ($headerRow['ORDSDID'] ?? $headerRow['ordsdid'] ?? 0),
                'ords_name' => $orderState['name'],
                'models_count' => count($models),
                'pieces_count' => count($records),
            ];

            // قراءة تكميلية (1): صف SALES_BILL الوحيد بهوية الفاتورة لما لا تحمله الواجهة.
            // Supplementary read (1): the single SALES_BILL row for what the view lacks.
            $bill = DB::connection('oracle')
                ->table(self::TABLE_SALES_BILL)
                ->where('BILL_NO', $billNo)
                ->where('YEAR', $year)
                ->first();

            $billRow = $bill ? (array) $bill : [];

            // بيانات الطلب من صف الفاتورة — تُدمج في الترويسة بدل حقل منفصل في الواجهة.
            // Order data from the bill row, merged into the header rather than a separate box.
            $header = array_merge($header, [
                'tel_no2' => $this->lower($billRow, 'TEL_NO2'),
                'address' => $this->lower($billRow, 'ADDRESS'),
                'ref_no' => $this->lower($billRow, 'REF_NO'),
                'details' => $this->lower($billRow, 'DETAILES'),
                'urgent' => (int) ($this->lower($billRow, 'URGENT') ?? 0),
                'cur_no' => $this->lower($billRow, 'CUR_NO'),
                'cur_price' => $this->lower($billRow, 'CUR_PRICE'),
                'branch_no' => $this->lower($billRow, 'BRANCH_NO'),
                'created_by' => $this->lower($billRow, 'CREATED_BY_NAME'),
                'last_updated_by' => $this->lower($billRow, 'LAST_UPDATED_BY_NAME'),
            ]);

            // الحجب على الخادم لا في الواجهة: بلا `tailoring.payments_view` لا تصل المبالغ أصلاً.
            // The block is server-side: without `tailoring.payments_view` no amount ever arrives.
            $payment = $this->canSeePayments($request) ? $this->paymentPayload($billRow, $headerRow) : null;

            $this->logActivity($request, 'tailoring.bill_viewed', $billNo, $year, 'عرض فاتورة تفصيل');

            return response()->json([
                'success' => true,
                'message' => 'تم جلب فاتورة التفصيل بنجاح / Cutting bill retrieved',
                'data' => [
                    'header' => $header,
                    'payment' => $payment,
                    'models' => $models,
                    'measure_labels' => $this->measureLabels(),
                    'pieces_count_by_model' => array_sum(array_map(
                        static fn (array $m): int => count($m['pieces']),
                        $models
                    )),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب فاتورة التفصيل / Failed to retrieve cutting bill', $e);
        }
    }

    /**
     * سجل حركة القطع المختارة فقط — القراءة التكميلية (2)
     * Movement log for the selected pieces only — supplementary read (2).
     *
     * `seq_nos` هي أرقام القطع (SEQ_NO1) التي يحملها العميل فعلاً من صفوف الواجهة، فلا حاجة
     * لقراءة أخرى لمعرفة قطع الفاتورة.
     * `seq_nos` are the piece numbers (SEQ_NO1) the client already holds from the view rows.
     */
    public function movements(Request $request, int $billNo, int $year): JsonResponse
    {
        $raw = trim((string) ($request->input('seq_nos') ?? ''));

        // الأرقام فقط: أي محرف آخر يُسقَط قبل بناء قائمة الربط.
        // Digits only: anything else is dropped before the bind list is built.
        $seqNos = array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[,\s]+/', $raw) ?: []),
            static fn (int $n): bool => $n > 0
        )));

        if ($seqNos === []) {
            return response()->json([
                'success' => false,
                'message' => 'معامل seq_nos مطلوب ويحمل أرقام القطع / seq_nos is required and must hold piece numbers',
            ], 422);
        }

        $seqNos = array_slice($seqNos, 0, self::MAX_MOVEMENT_SEQ_NOS);

        try {
            $placeholders = [];
            $bindings = ['bill_no' => $billNo, 'year' => $year];

            foreach ($seqNos as $index => $seqNo) {
                $key = 'seq'.$index;
                $placeholders[] = ':'.$key;
                $bindings[$key] = $seqNo;
            }

            $sql = 'SELECT ct.TR_INV_D_ID,
                           TO_CHAR(ct.DDATE, \'DD/MM/YYYY\') AS DDATE_TXT,
                           TO_CHAR(ct.DDATE, \'YYYY-MM-DD\') AS DDATE_ISO,
                           os.ORDSNAME,
                           ct.ORDSID,
                           dp.DEPTNAME,
                           ct.DETAILE,
                           ct.QTY,
                           ct.PQTY,
                           ct.SQTY,
                           ct.SEQ_NO,
                           ct.POST,
                           ct.CREATED_BY_NAME,
                           ct.UPDATED_BY_NAME
                      FROM '.self::TABLE_TRANSD.' ct
                      JOIN '.self::TABLE_ORDSTATE.' os ON os.ORDSID = ct.ORDSID
                      LEFT JOIN '.self::TABLE_DEPTS.' dp ON dp.DEPTID = ct.DEPTID
                     WHERE ct.BILL_NO = :bill_no
                       AND ct.YEAR = :year
                       AND NVL(ct.CANCEL, 0) <> 1
                       AND ct.SEQ_NO IN ('.implode(', ', $placeholders).')
                     ORDER BY ct.DDATE, ct.TR_INV_D_ID';

            $rows = DB::connection('oracle')->select($sql, $bindings);

            $movements = array_map(fn ($row): array => $this->movementRow((array) $row), $rows);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب سجل الحركة بنجاح / Movements retrieved',
                'data' => $movements,
                'meta' => [
                    'total' => count($movements),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب سجل حركة الفاتورة / Failed to retrieve movements', $e);
        }
    }

    /**
     * المرجعيات المشتركة بين شاشة الاستعراض ونموذج الإضافة: تسميات المقاسات وأنواع الموديلات
     * وأشكالها وحالات السير.
     * Reference data shared by the view screen and the add form: measurement labels, model types,
     * shapes and workflow stages.
     */
    public function reference(): JsonResponse
    {
        try {
            $types = DB::connection('oracle')
                ->table(self::TABLE_TYPES)
                ->select(['TYPE_NO', 'TYPE_NAME', 'GROUP_NO', 'ACTIVE'])
                ->orderBy('TYPE_NO')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

            $styles = DB::connection('oracle')
                ->table(self::TABLE_STYLE)
                ->select(['SHAPE_STYLE_NO', 'STYLE_NAME', 'TYPE_NO'])
                ->orderBy('SHAPE_STYLE_NO')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

            $stages = DB::connection('oracle')
                ->table(self::TABLE_ORDSTATE)
                ->select(['ORDSID', 'ORDSNAME', 'ORDS_NEXT', 'ACTIVE'])
                ->orderBy('ORDSID')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب المرجعيات بنجاح / Reference data retrieved',
                'data' => [
                    'measure_labels' => $this->measureLabels(),
                    'types' => ArabicEncoding::cleanData(array_map(
                        fn (array $row): array => [
                            'type_no' => $this->num($row, 'TYPE_NO'),
                            'type_name' => $this->txt($row, 'TYPE_NAME'),
                            'group_no' => $this->num($row, 'GROUP_NO'),
                            'active' => (int) ($this->num($row, 'ACTIVE') ?? 0),
                        ],
                        $types
                    )),
                    'styles' => ArabicEncoding::cleanData(array_map(
                        fn (array $row): array => [
                            'shape_style_no' => $this->num($row, 'SHAPE_STYLE_NO'),
                            'style_name' => $this->txt($row, 'STYLE_NAME'),
                            'type_no' => $this->num($row, 'TYPE_NO'),
                        ],
                        $styles
                    )),
                    'stages' => ArabicEncoding::cleanData(array_map(
                        fn (array $row): array => [
                            'ordsid' => (int) ($this->num($row, 'ORDSID') ?? 0),
                            'ordsname' => $this->txt($row, 'ORDSNAME'),
                            'next_ordsid' => $this->num($row, 'ORDS_NEXT'),
                            'active' => (int) ($this->num($row, 'ACTIVE') ?? 0),
                        ],
                        $stages
                    )),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مرجعيات التفصيل / Failed to retrieve reference data', $e);
        }
    }

    /**
     * تسجيل حدث طباعة أو تصدير في سجل نشاط المستخدم فقط
     * Record a print or export event in the user activity trail only.
     *
     * لا يمسّ أي جدول إرث: الحدث صف واحد في APP_USER_ACTIVITY عبر ActivityLogger.
     * Nothing in the legacy tables is touched: one APP_USER_ACTIVITY row through ActivityLogger.
     */
    public function activity(Request $request, int $billNo, int $year): JsonResponse
    {
        $action = (string) ($request->input('action') ?? '');

        $map = [
            'print' => ['tailoring.bill_printed', 'طباعة فاتورة تفصيل'],
            'export' => ['tailoring.bills_exported', 'تصدير فاتورة تفصيل'],
        ];

        if (! isset($map[$action])) {
            return response()->json([
                'success' => false,
                'message' => 'قيمة action غير مدعومة / Unsupported action value',
            ], 422);
        }

        $this->logActivity($request, $map[$action][0], $billNo, $year, $map[$action][1]);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الحدث / Event recorded',
        ], 200);
    }

    /**
     * شروط القائمة وقيم الربط — تُبنى شرطياً فتبقى كل القيم مربوطة
     * List filters and bind values — built conditionally so every value stays bound.
     *
     * التصفية الزمنية في `WHERE` لأنها على عمود الصف، والبحث في `HAVING` لأن المستفيد يقيم على
     * صف الموديل داخل نفس الفاتورة. البحث بالتجميع الشرطي (`MAX(CASE …)`) قيس على القاعدة
     * الحيّة: 0.8 ثانية، مقابل 300 ثانية للاستعلام المترابط المتكرر على الواجهة.
     * The date filter lives in `WHERE` (a row column); the search lives in `HAVING` because the
     * beneficiary sits on the model row of the same bill. The conditional-aggregate search was
     * measured live at 0.8s against 300s for the correlated EXISTS form.
     *
     * @return array{where: string, having: string, bindings: array<string, mixed>}
     */
    private function listFilters(Request $request, string $search): array
    {
        $clauses = [];
        $conditions = [];
        $bindings = [];

        $from = $this->parseDateToIso((string) ($request->input('from_date') ?? ''));
        $to = $this->parseDateToIso((string) ($request->input('to_date') ?? ''));

        if ($from !== null) {
            $clauses[] = 'v.DAT >= TO_DATE(:from_date, \'YYYY-MM-DD\')';
            $bindings['from_date'] = $from;
        }

        // `< اليوم التالي` بدل `<= اليوم`: عمود DAT يحمل وقتاً، فالمقارنة بالتساوي تُسقط فواتير اليوم.
        // `< the next day` instead of `<= the day`: DAT carries a time, so equality drops today.
        if ($to !== null) {
            $clauses[] = 'v.DAT < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1';
            $bindings['to_date'] = $to;
        }

        if ($search !== '') {
            $oracleSearch = ArabicEncoding::toOracleSearchChars($search);

            // الصيغتان معاً لأن العربية مخزّنة بايتات CP1256 فالنص المكتوب UTF-8 لا يطابقها.
            // Both forms: Arabic is stored as CP1256 bytes, so typed UTF-8 does not match it.
            $bindings['search_oracle'] = '%'.($oracleSearch ?? $search).'%';
            $bindings['search_plain'] = '%'.$search.'%';

            $conditions[] = 'MAX(CASE WHEN v.CUST_NAME LIKE :search_oracle
                                        OR v.CUST_NAME LIKE :search_plain
                                        OR v.TEL_NO1 LIKE :search_plain
                                   THEN 1 ELSE 0 END) = 1';

            // المستفيد يقيم في صف الموديل لا في الترويسة، فالشرط يُقيَّم على كل قطع الفاتورة.
            // The beneficiary lives on the model row, so the test covers every piece of the bill.
            $conditions[] = 'MAX(CASE WHEN v.BENEFICIARY_NAME LIKE :search_beneficiary
                                        OR v.BENEFICIARY_NAME LIKE :search_plain
                                   THEN 1 ELSE 0 END) = 1';

            $bindings['search_beneficiary'] = '%'.($oracleSearch ?? $search).'%';

            // أرقام الفواتير: الرقم الداخلي والرقم اليدوي المطبوع على الكرت.
            // Bill numbers: the internal number and the manual number printed on the card.
            if (is_numeric($search)) {
                $conditions[] = 'MAX(CASE WHEN v.BILL_NO = :search_bill_no
                                            OR v.MANUAL_BILL_NO = :search_manual_no
                                       THEN 1 ELSE 0 END) = 1';
                $bindings['search_bill_no'] = (int) $search;
                $bindings['search_manual_no'] = (int) $search;
            }
        }

        return [
            'where' => implode(' AND ', $clauses),
            // بلا بحث تبقى القائمة فارغة (`''`) فلا يُضاف `HAVING` أصلاً — `()` ليست SQL صحيحة.
            // With no search the clause stays `''` so no HAVING is emitted — `()` is not valid SQL.
            'having' => $conditions === [] ? '' : '('.implode(' OR ', $conditions).')',
            'bindings' => $bindings,
        ];
    }

    /**
     * استعلام التفصيل الكامل لفاتورة واحدة بهويتها المركّبة
     * The full detail query for one bill by its composite identity.
     */
    private function detailSql(): string
    {
        return 'SELECT v.SEQ_NO, v.SEQ_NO1, v.BENEFICIARY_NAME, v.QTY AS PIECE_QTY,
                       v.SHAPE_STYLE_NO, v.STYLE_NAME, st.STYLE_NAME AS SHAPE_NAME,
                       v.CLOTH_NAME, v.CLOTH_TYPE, v.CLOTHS_COLOR, v.CLOTHS_COLOR2,
                       v.CONSUMED_CLOTH_QTY_HINSH, v.CONSUMED_CLOTH_QTY_YARDS,
                       v.CLOTH_FRM_LOCAL_STORE, v.CLOTHS_OUR_SELECTION, v.SHELF_NO,
                       v.CUTTER_NAME, v.TAILOR_NAME1, v.CUTTER_NO1, v.TAILOR_NO1,
                       v.CUTTER_PCS_PRICE1, v.TAILOR_PCS_PRICE1,
                       v.CUTTING_DONE, v.CUTTING_DATE, v.TAILOR_DONE, v.TAILOR_DATE,
                       v.HANDING_DONE1, v.HANDING_DATE1, v.TIME1,
                       v.SANF_PRICE, v.SANF_NO, v.SANF_DNO,
                       v.MANUAL_BILL_NO, v.DAT, v.CUST_NO, v.CUST_NAME, v.TEL_NO1,
                       v.ACC_NO, v.GROUP_NO, v.TOTAL, v.DISCOUNT, v.NET,
                       v.HANDING_DONE, v.ORDSDID, v.BILLS_KINDS, v.BATCH_NO_PRINT,
                       TO_CHAR(v.DAT, \'YYYY-MM-DD\') AS DAT_ISO,
                       TO_CHAR(v.DAT, \'DD/MM/YYYY\') AS DAT_TXT,
                       TO_CHAR(v.GIVING_DATE, \'DD/MM/YYYY\') AS GIVING_DATE_TXT,
                       TO_CHAR(v.HANDING_DATE, \'DD/MM/YYYY\') AS HANDING_DATE_TXT,
                       v.L1, v.L2, v.L3, v.L4, v.L5, v.L6,
                       v.L7, v.L8, v.L9, v.L10, v.L11, v.L12,
                       s.QTY AS MODEL_QTY,
                       s.NECK_TYPE, s.TIPPER_TYPE, s.COM_CABAK_TYPE, s.POCKET_OR_WITHOUT,
                       s.GABZOR_TYPE, s.BUTTON_TYPE, s.BUTTON_NO, s.MOBILE_POCKET,
                       s.PEN_POCKET, s.HEGAZI_TYPE, s.NOTES2,
                       s.NECK_SHAPE_NO, s.SHAPE_GABROZ_NO, s.SHAPE_COM_CABAK_NO,
                       s.SHAPE_POCKET_UP_NO, s.SHAPE_POCKET_DOWN_NO
                  FROM '.self::VIEW_CUTTING_BILL.' v
                  LEFT JOIN '.self::TABLE_SHAPES.' s
                         ON s.BILL_NO = v.BILL_NO
                        AND s.YEAR = v.YEAR
                        AND s.SEQ_NO = v.SEQ_NO
                  LEFT JOIN '.self::TABLE_STYLE.' st
                         ON st.SHAPE_STYLE_NO = v.SHAPE_STYLE_NO
                 WHERE v.BILL_NO = :bill_no
                   AND v.YEAR = :year
                 ORDER BY v.SEQ_NO, v.SEQ_NO1';
    }

    /**
     * تجميع صفوف القطع إلى موديلات، وكل موديل يحمل قطعه
     * Fold piece rows into models, each model carrying its pieces.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function groupModels(array $records): array
    {
        $models = [];

        foreach ($records as $row) {
            $seqNo = (int) ($row['SEQ_NO'] ?? $row['seq_no'] ?? 0);

            if (! isset($models[$seqNo])) {
                $models[$seqNo] = [
                    'seq_no' => $seqNo,
                    'beneficiary' => $this->txt($row, 'BENEFICIARY_NAME'),
                    'type_name' => $this->txt($row, 'STYLE_NAME'),
                    'shape_name' => $this->txt($row, 'SHAPE_NAME'),
                    'shape_style_no' => $this->num($row, 'SHAPE_STYLE_NO'),
                    // عدد الموديل من صف الموديل؛ وإن غاب فعدد قطعه هو الصورة الصادقة.
                    // The model quantity from the model row; when absent the piece count is honest.
                    'qty' => (int) ($this->num($row, 'MODEL_QTY') ?? 0),
                    'measurements' => $this->measurements($row),
                    'specs' => $this->specs($row),
                    'pieces' => [],
                ];
            }

            $models[$seqNo]['pieces'][] = [
                'seq_no1' => $this->num($row, 'SEQ_NO1'),
                'sanf_no' => $this->num($row, 'SANF_NO'),
                'sanf_dno' => $this->num($row, 'SANF_DNO'),
                'cloth_name' => $this->txt($row, 'CLOTH_NAME'),
                'cloth_type' => $this->txt($row, 'CLOTH_TYPE'),
                'color_no' => $this->num($row, 'CLOTHS_COLOR'),
                'color_text' => $this->txt($row, 'CLOTHS_COLOR2'),
                'hinsh' => $this->num($row, 'CONSUMED_CLOTH_QTY_HINSH'),
                'yards' => $this->num($row, 'CONSUMED_CLOTH_QTY_YARDS'),
                'from_local_store' => $this->num($row, 'CLOTH_FRM_LOCAL_STORE'),
                'our_selection' => $this->num($row, 'CLOTHS_OUR_SELECTION'),
                'shelf_no' => $this->txt($row, 'SHELF_NO'),
                'cutter_name' => $this->txt($row, 'CUTTER_NAME'),
                'cutter_no' => $this->num($row, 'CUTTER_NO1'),
                'tailor_name' => $this->normalizeTailorName($this->txt($row, 'TAILOR_NAME1')),
                'tailor_no' => $this->num($row, 'TAILOR_NO1'),
                'cutting_price' => $this->num($row, 'CUTTER_PCS_PRICE1'),
                'tailor_price' => $this->num($row, 'TAILOR_PCS_PRICE1'),
                'cutting_done' => (int) ($this->num($row, 'CUTTING_DONE') ?? 0),
                'cutting_date' => $this->dateTxt($row, 'CUTTING_DATE'),
                'tailor_done' => (int) ($this->num($row, 'TAILOR_DONE') ?? 0),
                'tailor_date' => $this->dateTxt($row, 'TAILOR_DATE'),
                'handing_done' => (int) ($this->num($row, 'HANDING_DONE1') ?? 0),
                'handing_date' => $this->dateTxt($row, 'HANDING_DATE1'),
                'handing_time' => $this->txt($row, 'TIME1'),
                'price' => $this->num($row, 'SANF_PRICE'),
            ];
        }

        // الموديل الذي لا يحمل عدداً من صف الموديل يُعرض بعدد قطعه بدل صفر مضلّل.
        // A model with no stored quantity shows its piece count instead of a misleading zero.
        foreach ($models as $seqNo => $model) {
            if ($model['qty'] <= 0) {
                $models[$seqNo]['qty'] = count($model['pieces']);
            }
        }

        return array_values($models);
    }

    /**
     * المقاسات L1..L12 كما هي — التسمية تأتي من GENERAL_LABELS_CODING لا من الشاشة
     * Measurements L1..L12 as stored; their labels come from GENERAL_LABELS_CODING.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, float|null>
     */
    private function measurements(array $row): array
    {
        $measures = [];

        for ($i = 1; $i <= 12; $i++) {
            $measures['L'.$i] = $this->num($row, 'L'.$i);
        }

        return $measures;
    }

    /**
     * المواصفات النصية والأكواد الرقمية كما هي بلا ترجمة مخترعة
     * Spec texts and numeric codes exactly as stored, with no invented translation.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function specs(array $row): array
    {
        return [
            'neck_type' => $this->txt($row, 'NECK_TYPE'),
            'tipper_type' => $this->txt($row, 'TIPPER_TYPE'),
            'com_cabak_type' => $this->txt($row, 'COM_CABAK_TYPE'),
            'pocket_or_without' => $this->txt($row, 'POCKET_OR_WITHOUT'),
            'gabzor_type' => $this->txt($row, 'GABZOR_TYPE'),
            'button_type' => $this->txt($row, 'BUTTON_TYPE'),
            'button_no' => $this->txt($row, 'BUTTON_NO'),
            'mobile_pocket' => $this->txt($row, 'MOBILE_POCKET'),
            'pen_pocket' => $this->txt($row, 'PEN_POCKET'),
            'hegazi_type' => $this->txt($row, 'HEGAZI_TYPE'),
            'notes' => $this->txt($row, 'NOTES2'),
            'neck_shape_no' => $this->num($row, 'NECK_SHAPE_NO'),
            'shape_gabzor_no' => $this->num($row, 'SHAPE_GABROZ_NO'),
            'shape_com_cabak_no' => $this->num($row, 'SHAPE_COM_CABAK_NO'),
            'shape_pocket_up_no' => $this->num($row, 'SHAPE_POCKET_UP_NO'),
            'shape_pocket_down_no' => $this->num($row, 'SHAPE_POCKET_DOWN_NO'),
        ];
    }

    /**
     * كتلة الدفع: تُبنى من صف SALES_BILL وتُترك `null` حين لا يملك المستخدم الصلاحية
     * The payment block, built from the SALES_BILL row and null when the grant is missing.
     *
     * @param  array<string, mixed>  $billRow
     * @param  array<string, mixed>  $headerRow
     * @return array<string, mixed>
     */
    private function paymentPayload(array $billRow, array $headerRow): array
    {
        $total = $this->lower($billRow, 'TOTAL') ?? $this->num($headerRow, 'TOTAL');
        $discount = $this->lower($billRow, 'DISCOUNT') ?? $this->num($headerRow, 'DISCOUNT');
        $net = $this->lower($billRow, 'NET') ?? $this->num($headerRow, 'NET');
        $paid = $this->lower($billRow, 'AMOUNT_PAID');
        $others = $this->lower($billRow, 'OTHERS_PAYMENTS');

        $totalValue = (float) ($total ?? 0);
        $discountValue = (float) ($discount ?? 0);
        $netValue = (float) ($net ?? 0);
        $paidValue = (float) ($paid ?? 0) + (float) ($others ?? 0);

        return [
            'total' => $total !== null ? $totalValue : null,
            'discount' => $discount !== null ? $discountValue : null,
            'net' => $net !== null ? $netValue : null,
            'paid' => $paid !== null ? (float) $paid : null,
            'others_payments' => $others !== null ? (float) $others : null,
            // الفرق قد يكون سالباً (دفعة مقدمة) — الإشارة تُترك للعميل ليسمّيها «رصيد مقدم».
            // The difference can be negative (an advance); the sign is left to the client.
            'remaining' => $net !== null ? $netValue - $paidValue : null,
            'is_giving' => $this->lower($billRow, 'IS_GIVING'),
            'giving_date' => $this->txt($headerRow, 'GIVING_DATE_TXT'),
            'payment_method' => $this->lower($billRow, 'PAYMENT_METHOD'),
            'voucher_no' => $this->lower($billRow, 'VOUCHER_NO'),
            'currency_no' => $this->lower($billRow, 'CUR_NO'),
            'currency_price' => $this->lower($billRow, 'CUR_PRICE'),
        ];
    }

    /**
     * صف حركة واحدة بالشكل الذي تقرأه الشاشة
     * One movement row in the shape the screen reads.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function movementRow(array $row): array
    {
        return [
            'tr_inv_d_id' => $this->num($row, 'TR_INV_D_ID'),
            'date' => $this->txt($row, 'DDATE_TXT'),
            'raw_date' => $this->txt($row, 'DDATE_ISO'),
            'ordsid' => (int) ($this->num($row, 'ORDSID') ?? 0),
            'ords_name' => $this->txt($row, 'ORDSNAME'),
            'dept_name' => $this->txt($row, 'DEPTNAME'),
            'details' => $this->txt($row, 'DETAILE'),
            'qty' => $this->num($row, 'QTY'),
            'posted_qty' => $this->num($row, 'PQTY'),
            'signed_qty' => $this->num($row, 'SQTY'),
            'seq_no' => $this->num($row, 'SEQ_NO'),
            'posted' => (int) ($this->num($row, 'POST') ?? 0),
            'created_by' => $this->txt($row, 'CREATED_BY_NAME'),
            'updated_by' => $this->txt($row, 'UPDATED_BY_NAME'),
        ];
    }

    /**
     * صف قائمة الفاتورة
     * One row of the invoice list.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function billSummary(array $row): array
    {
        return [
            'bill_no' => $this->num($row, 'BILL_NO'),
            'year' => $this->num($row, 'YEAR'),
            'manual_bill_no' => $this->num($row, 'MANUAL_BILL_NO'),
            'date' => $this->txt($row, 'DAT_TXT'),
            'raw_date' => $this->txt($row, 'DAT_ISO'),
            'cust_no' => $this->num($row, 'CUST_NO'),
            'cust_name' => $this->txt($row, 'CUST_NAME'),
            'tel_no1' => $this->txt($row, 'TEL_NO1'),
            'models_count' => (int) ($this->num($row, 'MODELS_COUNT') ?? 0),
            'pieces_count' => (int) ($this->num($row, 'PIECES_COUNT') ?? 0),
            'total' => $this->num($row, 'TOTAL'),
            'discount' => $this->num($row, 'DISCOUNT'),
            'net' => $this->num($row, 'NET'),
            'handing_done' => $this->num($row, 'HANDING_DONE'),
            'ordsdid' => (int) ($this->num($row, 'ORDSDID') ?? 0),
        ];
    }

    /**
     * اسم الحالة الحالية من قاموس ORDSTATE
     * The current stage name from the ORDSTATE dictionary.
     *
     * @return array{name: string|null}
     */
    private function orderStateOf(mixed $ordsdid): array
    {
        if ($ordsdid === null || $ordsdid === '') {
            return ['name' => null];
        }

        try {
            $row = DB::connection('oracle')
                ->table(self::TABLE_ORDSTATE)
                ->where('ORDSID', (int) $ordsdid)
                ->first();

            if (! $row) {
                return ['name' => null];
            }

            return ['name' => ArabicEncoding::fixMojibake((string) ((array) $row)['ORDSNAME'])];
        } catch (\Throwable $e) {
            // اسم الحالة تزييني: فشله لا يُفشل الفاتورة
            // The stage name is decoration: its failure must not fail the bill
            report($e);

            return ['name' => null];
        }
    }

    /**
     * تسميات المقاسات الحقيقية من GENERAL_LABELS_CODING (L1..L12)
     * The real measurement labels from GENERAL_LABELS_CODING (L1..L12).
     *
     * @return array<string, string|null>
     */
    private function measureLabels(): array
    {
        $labels = [];

        for ($i = 1; $i <= 12; $i++) {
            $labels['L'.$i] = null;
        }

        try {
            $row = DB::connection('oracle')->table(self::TABLE_LABELS)->first();

            if ($row) {
                foreach ((array) $row as $key => $value) {
                    $upper = strtoupper((string) $key);
                    if (preg_match('/^L([0-9]{1,2})$/', $upper) === 1) {
                        $labels[$upper] = ArabicEncoding::normalizeStatementText($value);
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $labels;
    }

    /**
     * هل يملك المستخدم صلاحية رؤية خانات المال؟
     * Does the user hold the payment-details grant?
     */
    private function canSeePayments(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return app(PermissionResolver::class)->can($user, 'tailoring.payments_view');
    }

    /**
     * تسجيل حدث في سجل النشاط — لا يُفشل الطلب عند فشله
     * Write an activity event — never breaks the request.
     */
    private function logActivity(Request $request, string $actionCode, int $billNo, int $year, string $description): void
    {
        try {
            app(ActivityLogger::class)->success($actionCode, [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'module_code' => 'tailoring',
                'entity_type' => 'cutting_bill',
                'entity_id' => $billNo.'/'.$year,
                'description_ar' => $description.' '.$billNo.'/'.$year,
                'platform' => 'mobile',
            ], $request);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * «لا يوجد» قيمة حرفية يكتبها النظام القديم حين لا يُسند خيّاط — تُعرض «غير مسند»
     * "لا يوجد" is a literal the legacy system writes when no tailor is assigned.
     */
    private function normalizeTailorName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        if ($trimmed === '' || $trimmed === self::NO_TAILOR_SENTINEL) {
            return null;
        }

        return $trimmed;
    }

    /**
     * قراءة حقل يُحتمل وروده بالحالتين (ORACLE يعيد المفاتيح صغيرة أحياناً)
     * Read a field that may arrive in either case (Oracle sometimes returns lowercase keys).
     *
     * @param  array<string, mixed>  $row
     */
    private function value(array $row, string $key): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $lower = strtolower($key);

        return $row[$lower] ?? null;
    }

    /**
     * حقل نصي مُصلَّح الترميز
     * A text field with its encoding repaired.
     *
     * @param  array<string, mixed>  $row
     */
    private function txt(array $row, string $key): ?string
    {
        $value = $this->value($row, $key);

        if ($value === null || $value === '') {
            return null;
        }

        $fixed = ArabicEncoding::normalizeStatementText($value);

        return $fixed === '' ? null : $fixed;
    }

    /**
     * حقل رقمي — أرقام أوراكل قد تصل نصوصاً
     * A numeric field — Oracle numbers may arrive as strings.
     *
     * @param  array<string, mixed>  $row
     */
    private function num(array $row, string $key): float|int|null
    {
        $value = $this->value($row, $key);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float ? (int) $float : $float;
    }

    /**
     * قيمة صف SALES_BILL بحروف صغيرة مُصلَّحة
     * A SALES_BILL value with its text repaired.
     *
     * @param  array<string, mixed>  $row
     */
    private function lower(array $row, string $key): string|int|float|null
    {
        $value = $this->value($row, $key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            $float = (float) $value;

            return floor($float) === $float ? (int) $float : $float;
        }

        return ArabicEncoding::normalizeStatementText($value);
    }

    /**
     * تاريخ للعرض (يوم/شهر/سنة) من عمود DATE أو من صف يحمل الحقل مسبقاً
     * A display date (DD/MM/YYYY) from a DATE column or a pre-formatted field.
     *
     * @param  array<string, mixed>  $row
     */
    private function dateTxt(array $row, string $key): ?string
    {
        $raw = $this->value($row, $key);

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $date = $raw instanceof \DateTimeInterface
                ? Carbon::instance($raw)
                : Carbon::parse((string) $raw);

            return $date->year < 1900 ? null : $date->format('d/m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * تحويل التواريخ بأشكالها المختلفة إلى YYYY-MM-DD — مطابق لسائر المتحكمات
     * Normalise the accepted date shapes to YYYY-MM-DD, as the other controllers do.
     */
    private function parseDateToIso(string $dateStr): ?string
    {
        $d = trim($dateStr);

        if ($d === '') {
            return null;
        }

        try {
            if (str_contains($d, '/')) {
                $parts = explode('/', $d);

                if (count($parts) === 3) {
                    $year = strlen($parts[0]) === 4 ? (int) $parts[0] : (int) $parts[2];
                    $month = (int) $parts[1];
                    $day = strlen($parts[0]) === 4 ? (int) $parts[2] : (int) $parts[0];

                    if ($year < 1900 || $year > 2100 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
                        return null;
                    }

                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }

            // 'Y-m-d' لا 'YYYY-MM-DD': الحروف المكرّرة في PHP تُنتج سلسلة مشوهة.
            // 'Y-m-d' rather than 'YYYY-MM-DD': repeated letters produce garbage.
            $parsed = Carbon::parse($d);

            if ($parsed->year < 1900 || $parsed->year > 2100) {
                return null;
            }

            return $parsed->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * معالج الأخطاء المركزي
     * Central error response.
     */
    private function errorResponse(string $msg, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $msg.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
