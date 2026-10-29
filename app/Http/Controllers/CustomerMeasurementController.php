<?php

namespace App\Http\Controllers;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\CuttingViewReader;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم «مقاسات العملاء» — قراءة فقط من ACCOUNTS.CUTTING_SALES_BILL_VIEW
 * Customer-measurements controller — read-only over ACCOUNTS.CUTTING_SALES_BILL_VIEW.
 *
 * مسار من ثلاث خطوات يطابق الطلب: قائمة العملاء (مع بحث بالاسم/الهاتف/المستفيد) ← موديلات
 * العميل واحدة ← تفصيل موديل واحد (مقاسات + مواصفات + أقمشة). كل خطوة تُقرأ من الواجهة،
 * والاستثناءان الوحيدان جدولان لا تحملهما الواجهة: SALES_BILL (عنوان العميل وبيانات الطلب)
 * و SALES_DETAILES_SHAPES (نصوص المواصفات، وهي أعمدة غير موجودة في الواجهة إطلاقاً).
 * A three-step path matching the request: the customer list (search by name, phone or
 * beneficiary) → the customer's models → one model in full (measurements, specs, fabrics).
 * Everything comes from the view; the two documented exceptions are tables the view does not
 * carry: SALES_BILL (address and order data) and SALES_DETAILES_SHAPES (spec texts, columns the
 * view never exposes).
 *
 * لا يقرأ هذا المتحكم أي خانة مالية إطلاقاً: القسم مقاسات ومواصفات وأقمشة، فلا مبالغ تصل
 * إلى الواجهة حتى مع منح أوسع.
 * This controller reads no money column at all: the feature is measurements, specs and fabrics,
 * so no amount ever reaches the client even with broader grants.
 *
 * قاعدة المفتاح: الموديل يُعرَّف بثلاثية (BILL_NO, YEAR, SEQ_NO)، والرقم وحده غير فريد لا
 * للفاتورة ولا للموديل.
 * Key rule: a model is the triple (BILL_NO, YEAR, SEQ_NO); neither number is unique alone.
 */
class CustomerMeasurementController extends Controller
{
    private const DEFAULT_LIMIT = 30;

    private const MAX_LIMIT = 100;

    /**
     * معامل تبديل المفاتيح المركّبة — أرقام الموديل تصل إلى 85,120 في القاعدة الحيّة، فأي
     * معامل أصغر من 100,000 يجعل موديلين مختلفين يتطابقان في `COUNT(DISTINCT …)`.
     * The composite-key multiplier. Live model numbers reach 85,120, so any factor below
     * 100,000 would make two different models collide inside `COUNT(DISTINCT …)`.
     */
    private const SEQ_FACTOR = 1000000;

    /** أقصى عدد مفاتيح في صفحة واحدة — سقف صريح كي لا تُبنى قائمة ربط ضخمة
     *  Upper bound on keys per page, so no huge bind list is ever built. */
    private const MAX_PAGE_KEYS = 100;

    /** أوضاع ترتيب قائمة العملاء — قيم حرفية مسموحة فقط، لا نص المستخدم
     *  Allowed customer-list sort orders: whitelisted literals, never user text. */
    private const CUSTOMER_SORTS = [
        'date_desc' => 'D DESC, v.CUST_NO DESC',
        'date_asc' => 'D ASC, v.CUST_NO ASC',
        'name_asc' => 'N ASC, v.CUST_NO ASC',
        'name_desc' => 'N DESC, v.CUST_NO DESC',
    ];

    /**
     * أوضاع ترتيب قائمة الموديلات — تُرتَّب داخل الاستعلام الأصلي بأسماء الأعمدة/المُسمّيات
     * الخاصة به، فالمرجع `q` لا يوجد إلا في الغلاف الخارجي.
     * Model-list sort orders: they are applied inside the inner query, where `q` does not exist —
     * that alias belongs to the outer wrapper only.
     */
    private const MODEL_SORTS = [
        'date_desc' => 'D DESC, BILL_NO DESC, SEQ_NO DESC',
        'date_asc' => 'D ASC, BILL_NO ASC, SEQ_NO ASC',
        'beneficiary_asc' => 'B ASC, BILL_NO DESC, SEQ_NO DESC',
    ];

    /**
     * قائمة العملاء الذين لهم موديلات تفصيل — الأحدث نشاطاً أولاً، مع بحث وتصفح.
     * Customers that have tailoring models — most recent activity first, with search and paging.
     *
     * التجميع على `CUST_NO` آمن: تحقّق القاعدة الحيّة أظهر أن لكل رقم عميل اسماً واحداً
     * (0 عميلاً بأكثر من اسم)، فـ `MAX(CUST_NAME)` ليس تسكيناً عشوائياً بل الاسم الوحيد.
     * Grouping by CUST_NO is safe: the live database shows one name per customer number
     * (zero customers with two names), so MAX(CUST_NAME) is the only name, not a guess.
     *
     * ثلاثة استعلامات كما في قائمة فواتير التفصيل: عدّ المجموعات، مفاتيح الصفحة بـ ROWNUM،
     * ثم تجميع الصفحة فقط. التجميع الكامل لكل الواجهة على صفحة واحدة أبطأ بما يكفي ليرفضه
     * المستخدم على شبكة محلية.
     * Three queries like the cutting-bill list: count the groups, page keys via ROWNUM, then
     * aggregate only that page. Aggregating the whole view for one page is measurably slower.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $limit = $this->resolveLimit($request);
        $offset = ($page - 1) * $limit;

        try {
            $filters = $this->customerFilters($request, $search);
            $groupBy = ' GROUP BY v.CUST_NO';
            $from = ' FROM '.CuttingViewReader::VIEW_BILL.' v'
                .($filters['where'] === '' ? '' : ' WHERE '.$filters['where'])
                .$groupBy
                .($filters['having'] === '' ? '' : ' HAVING '.$filters['having']);

            $totalRow = DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS AGGREGATE FROM (SELECT v.CUST_NO'.$from.') t',
                $filters['bindings']
            );

            $total = (int) (CuttingViewReader::value((array) $totalRow, 'AGGREGATE') ?? 0);

            // الترتيب بعمود مُسمّى (D/N) لا بالتعبير: أوراكل 11b تحتاج الاسم المستعار لتقطع
            // الصفوف مبكراً (stopkey)، والترتيب بالتعبير يسقطها في مسح كامل.
            // Order by the alias (D/N), not the expression: Oracle needs the alias to cut rows
            // early (stopkey); ordering by the expression forces a full scan.
            $keyRows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT p.CUST_NO, ROWNUM AS RN FROM (
                        SELECT v.CUST_NO, MAX(v.DAT) AS D, MAX(v.CUST_NAME) AS N'.$from.'
                        ORDER BY '.$this->customerOrder($request)
                .'
                    ) p WHERE ROWNUM <= :row_max
                 ) WHERE RN > :row_min',
                array_merge($filters['bindings'], [
                    'row_max' => $offset + $limit,
                    'row_min' => $offset,
                ])
            );

            $items = $this->customerProfiles($keyRows);

            // التسجيل فقط لأول صفحة بحث — التصفح المتكرر لا يملأ السجل بأحداث متماثلة.
            // Logged only for the first page of a search: paging must not flood the trail.
            if ($page === 1 && $search !== '') {
                $this->logActivity($request, 'customers.measurements_listed', [
                    'entity_id' => 'search:'.$search,
                    'description_ar' => 'بحث في مقاسات العملاء: '.$search,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب قائمة عملاء المقاسات بنجاح / Measurement customers retrieved',
                'data' => $items,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة عملاء المقاسات / Failed to retrieve measurement customers', $e);
        }
    }

    /**
     * موديلات عميل واحد — الأحدث أولاً، مع بحث المستفيد/Nطاق الزمني والتصفح.
     * One customer's models — newest first, with beneficiary search, date range and paging.
     *
     * التجميع هنا على ثلاثية الموديل و`CUST_NO` في `WHERE` (عمود صف)، فلا مسح لكل الواجهة.
     * The grouping is over the model triple with CUST_NO in WHERE (a row column), so the whole
     * view is never aggregated.
     *
     * لا يُربط جدول الأشكال هنا: `COUNT(*)` يجب أن يبقى عدد القطع الحقيقي، وأي ربط زائد
     * يضاعفه. اسم الشكل الكامل يُقرأ في التفصيل فقط.
     * The shape dictionary is not joined here: COUNT(*) must stay the true piece count and an
     * extra join would multiply it. The full shape name is read in the detail only.
     */
    public function models(Request $request, int $custNo): JsonResponse
    {
        if ($custNo <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'رقم العميل غير صالح / Invalid customer number',
            ], 422);
        }

        $page = max((int) ($request->input('page') ?? 1), 1);
        $limit = $this->resolveLimit($request);
        $offset = ($page - 1) * $limit;

        try {
            $filters = $this->modelFilters($request, $custNo);
            $group = ' GROUP BY v.BILL_NO, v.YEAR, v.SEQ_NO'
                .($filters['having'] === '' ? '' : ' HAVING '.$filters['having']);

            $from = ' FROM '.CuttingViewReader::VIEW_BILL.' v WHERE '.$filters['where'].$group;

            $totalRow = DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS AGGREGATE FROM (SELECT v.BILL_NO, v.YEAR, v.SEQ_NO'.$from.') t',
                $filters['bindings']
            );

            $total = (int) (CuttingViewReader::value((array) $totalRow, 'AGGREGATE') ?? 0);

            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT q.*, ROWNUM AS RN FROM (
                        SELECT v.BILL_NO, v.YEAR, v.SEQ_NO,
                               MAX(v.BENEFICIARY_NAME) AS BENEFICIARY_NAME,
                               MAX(v.STYLE_NAME) AS TYPE_NAME,
                               MAX(v.SHAPE_STYLE_NO) AS SHAPE_STYLE_NO,
                               MAX(v.MANUAL_BILL_NO) AS MANUAL_BILL_NO,
                               MAX(v.QTY) AS MODEL_QTY,
                               MAX(v.HANDING_DONE1) AS HANDING_DONE,
                               MAX(v.CUTTING_DONE) AS CUTTING_DONE,
                               MAX(v.TAILOR_DONE) AS TAILOR_DONE,
                               COUNT(*) AS PIECES_COUNT,
                               TO_CHAR(MAX(v.DAT), \'YYYY-MM-DD\') AS D,
                               TO_CHAR(MAX(v.DAT), \'DD/MM/YYYY\') AS DAT_TXT,
                               MAX(v.BENEFICIARY_NAME) AS B
                          '.$from.'
                          ORDER BY '.$this->modelOrder($request).'
                    ) q WHERE ROWNUM <= :row_max
                ) WHERE RN > :row_min',
                array_merge($filters['bindings'], [
                    'row_max' => $offset + $limit,
                    'row_min' => $offset,
                ])
            );

            $items = array_map(
                static fn (array $row): array => self::mapModelSummary($row),
                array_map(static fn ($row): array => (array) $row, $rows)
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب موديلات العميل بنجاح / Customer models retrieved',
                'data' => $items,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                    'cust_no' => $custNo,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب موديلات العميل / Failed to retrieve customer models', $e);
        }
    }

    /**
     * تفصيل موديل واحد: العميل/المستفيد + المقاسات + المواصفات + الأقمشة.
     * One model in full: customer and beneficiary, measurements, specs and fabrics.
     *
     * شكل الاستجابة يطابق `GET /tailoring/bills/{billNo}/{year}` (header + models + measure_labels)
     * لأن الواجهة تعيد استخدام نماذج قسم التفصيل وشرائحه بدل نسخة موازية تتفكك لاحقاً.
     * The payload mirrors the tailoring bill detail (header + models + measure_labels) because the
     * client reuses that feature's models and widgets instead of a parallel copy that would drift.
     *
     * `models` يحمل الموديل المطلوب وحده، و`siblings` بقية موديلات نفس الفاتورة ليتمكن شريط
     * الفلترة من التنقل بينها بلا طلب إضافي.
     * `models` holds the requested model alone; `siblings` carries the rest of the same bill so the
     * filter bar can switch between them without another request.
     */
    public function show(Request $request, int $custNo, int $billNo, int $year, int $seqNo): JsonResponse
    {
        try {
            $rows = DB::connection('oracle')->select(
                $this->detailSql(),
                ['bill_no' => $billNo, 'year' => $year, 'seq_no' => $seqNo]
            );

            if ($rows === []) {
                return response()->json([
                    'success' => false,
                    'message' => "الموديل [{$seqNo}] من فاتورة [{$billNo}/{$year}] غير موجود في بيانات التفصيل "
                        .'/ Model not found in the tailoring view',
                    'not_found_in_view' => true,
                ], 404);
            }

            $records = array_map(static fn ($row): array => (array) $row, $rows);
            $headerRow = $records[0];

            // العميل المطلوب يجب أن يطابق عميل الفاتورة: مفتاح رباعي لا ثلاثي.
            // The requested customer must own the bill: a four-part key, not three.
            $ownerNo = CuttingViewReader::num($headerRow, 'CUST_NO');

            if ($ownerNo !== null && (int) $ownerNo !== $custNo) {
                return response()->json([
                    'success' => false,
                    'message' => 'هذا الموديل لا يعود لهذا العميل / This model does not belong to this customer',
                    'not_found_in_view' => true,
                ], 404);
            }

            // القراءة التكميلية: صف SALES_BILL الوحيد لما لا تحمله الواجهة.
            // Supplementary read: the single SALES_BILL row for what the view lacks.
            $bill = DB::connection('oracle')
                ->table(CuttingViewReader::TABLE_SALES_BILL)
                ->where('BILL_NO', $billNo)
                ->where('YEAR', $year)
                ->first();

            $billRow = $bill ? (array) $bill : [];

            $model = $this->buildModel($headerRow, $records);

            // كل فتح موديل حدث تدقيق: المقاسات بيانات حساسة تُعرف بها أجساد الناس.
            // Every model open is an audit event: measurements are personal data about bodies.
            $this->logActivity($request, 'customers.measurements_viewed', [
                'entity_id' => $custNo.'/'.$billNo.'/'.$year.'/'.$seqNo,
                'description_ar' => 'عرض مقاسات موديل '.$seqNo.' من فاتورة '.$billNo.'/'.$year,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مقاسات الموديل بنجاح / Model measurements retrieved',
                'data' => [
                    'header' => $this->buildHeader($billNo, $year, $headerRow, $billRow, $model),
                    'models' => [$model],
                    'measure_labels' => CuttingViewReader::measureLabels(),
                    'siblings' => $this->siblingModels($billNo, $year, $seqNo),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مقاسات الموديل / Failed to retrieve model measurements', $e);
        }
    }

    /**
     * تسجيل حدث طباعة أو تصدير في سجل نشاط المستخدم فقط.
     * Record a print or export event in the user activity trail only.
     *
     * لا يمسّ أي جدول إرث: الحدث صف واحد في APP_USER_ACTIVITY عبر ActivityLogger.
     * Nothing legacy is touched: one APP_USER_ACTIVITY row through ActivityLogger.
     */
    public function activity(Request $request, int $custNo, int $billNo, int $year, int $seqNo): JsonResponse
    {
        $action = (string) ($request->input('action') ?? '');

        $map = [
            'print' => ['customers.measurements_printed', 'طباعة مقاسات عميل'],
            'export' => ['customers.measurements_exported', 'تصدير مقاسات عميل'],
        ];

        if (! isset($map[$action])) {
            return response()->json([
                'success' => false,
                'message' => 'قيمة action غير مدعومة / Unsupported action value',
            ], 422);
        }

        $this->logActivity($request, $map[$action][0], [
            'entity_id' => $custNo.'/'.$billNo.'/'.$year.'/'.$seqNo,
            'description_ar' => $map[$action][1].' — موديل '.$seqNo.' من '.$billNo.'/'.$year.' لعميل '.$custNo,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الحدث / Event recorded',
        ], 200);
    }

    /**
     * تصفية قائمة العملاء وقيم الربط — كل قيمة مربوطة، والبحث بالتجميع الشرطي في HAVING.
     * Customer-list filters and binds — every value bound; the search is a conditional aggregate.
     *
     * البحث في `HAVING` لأن المستفيد يقيم في صف الموديل لا في هوية العميل: عميل يطابق لأنه
     * فصّل لـ «أحمد» يجب أن يظهر ولو كان اسمه هو «محمد».
     * The search lives in HAVING because the beneficiary sits on the model row: a customer matches
     * because he tailored for "Ahmed", even when his own name is "Mohammed".
     *
     * @return array{where: string, having: string, bindings: array<string, mixed>}
     */
    private function customerFilters(Request $request, string $search): array
    {
        $clauses = [];
        $conditions = [];
        $bindings = [];

        // نطاق زمني اختياري على تاريخ الفاتورة — يُقيّد الصفوف قبل التجميع فيقلّ العمل.
        // Optional invoice-date range: it narrows rows before grouping, so less work is done.
        $this->applyDateRange($request, $clauses, $bindings);

        if ($search !== '') {
            $oracleSearch = ArabicEncoding::toOracleSearchChars($search);

            // الصيغتان معاً لأن العربية مخزّنة بايتات CP1256 فالنص UTF-8 لا يطابقها.
            // Both forms: Arabic is stored as CP1256 bytes, so typed UTF-8 does not match it.
            $bindings['search_oracle'] = '%'.($oracleSearch ?? $search).'%';
            $bindings['search_plain'] = '%'.$search.'%';

            $conditions[] = 'MAX(CASE WHEN v.CUST_NAME LIKE :search_oracle
                                        OR v.CUST_NAME LIKE :search_plain
                                        OR v.TEL_NO1 LIKE :search_plain
                                        OR v.BENEFICIARY_NAME LIKE :search_oracle
                                        OR v.BENEFICIARY_NAME LIKE :search_plain
                                   THEN 1 ELSE 0 END) = 1';

            // رقم العميل حرفياً عند إدخال رقم — يطابق من يكتب رقم السجل لا اسمه.
            // The literal customer number when the input is numeric.
            if (is_numeric($search)) {
                $conditions[] = 'MAX(CASE WHEN v.CUST_NO = :search_cust_no
                                        OR v.MANUAL_BILL_NO = :search_manual_no
                                   THEN 1 ELSE 0 END) = 1';
                $bindings['search_cust_no'] = (int) $search;
                $bindings['search_manual_no'] = (int) $search;
            }
        }

        return [
            'where' => $clauses === [] ? '1 = 1' : implode(' AND ', $clauses),
            // بلا بحث يبقى فارغاً: `HAVING ()` ليست SQL صحيحة.
            // Blank without a search: `HAVING ()` is not valid SQL.
            'having' => $conditions === [] ? '' : '('.implode(' OR ', $conditions).')',
            'bindings' => $bindings,
        ];
    }

    /**
     * تصفية موديلات العميل: العميل في `WHERE` (عمود صف) والبحث في `HAVING`.
     * Model-list filters: the customer in WHERE (a row column), the search in HAVING.
     *
     * @return array{where: string, having: string, bindings: array<string, mixed>}
     */
    private function modelFilters(Request $request, int $custNo): array
    {
        $clauses = ['v.CUST_NO = :cust_no'];
        $conditions = [];
        $bindings = ['cust_no' => $custNo];

        $this->applyDateRange($request, $clauses, $bindings);

        $search = trim((string) ($request->input('search') ?? ''));

        if ($search !== '') {
            $oracleSearch = ArabicEncoding::toOracleSearchChars($search);

            $bindings['search_oracle'] = '%'.($oracleSearch ?? $search).'%';
            $bindings['search_plain'] = '%'.$search.'%';

            // المستفيد قد يكون فارغاً في 18,846 صفاً: NULL LIKE يعيد NULL فيُحسب بلا مشكلة هنا
            // لأن الشرط يجمع `MAX(CASE …)`.
            // The beneficiary is null on 18,846 rows; NULL LIKE yields NULL, which is harmless
            // inside the conditional aggregate.
            $conditions[] = 'MAX(CASE WHEN v.BENEFICIARY_NAME LIKE :search_oracle
                                        OR v.BENEFICIARY_NAME LIKE :search_plain
                                        OR v.STYLE_NAME LIKE :search_oracle
                                        OR v.STYLE_NAME LIKE :search_plain
                                   THEN 1 ELSE 0 END) = 1';

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
            'having' => $conditions === [] ? '' : '('.implode(' OR ', $conditions).')',
            'bindings' => $bindings,
        ];
    }

    /**
     * شروط النطاق الزمني على عمود DAT.
     * The date-range clauses for the DAT column.
     *
     * `< اليوم التالي` لا `<= اليوم`: العمود يحمل وقتاً، فالمقارنة بالتساوي تُسقط فواتير اليوم
     * الأخير — نفس ماقرّر في قائمة فواتير التفصيل.
     * `< the next day`, not `<= the day`: DAT carries a time, so equality would drop the last day.
     *
     * @param  array<int, string>  $clauses
     * @param  array<string, mixed>  $bindings
     */
    private function applyDateRange(Request $request, array &$clauses, array &$bindings): void
    {
        $from = $this->parseDateToIso((string) ($request->input('from_date') ?? ''));
        $to = $this->parseDateToIso((string) ($request->input('to_date') ?? ''));

        if ($from !== null) {
            $clauses[] = 'v.DAT >= TO_DATE(:from_date, \'YYYY-MM-DD\')';
            $bindings['from_date'] = $from;
        }

        if ($to !== null) {
            $clauses[] = 'v.DAT < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1';
            $bindings['to_date'] = $to;
        }
    }

    /**
     * ترتيب قائمة العملاء من قيمة مسموحة فقط.
     * The customer-list order, taken from an allowed value only.
     */
    private function customerOrder(Request $request): string
    {
        $sort = (string) ($request->input('sort') ?? 'date_desc');

        return self::CUSTOMER_SORTS[$sort] ?? self::CUSTOMER_SORTS['date_desc'];
    }

    /**
     * ترتيب قائمة الموديلات من قيمة مسموحة فقط.
     * The model-list order, taken from an allowed value only.
     */
    private function modelOrder(Request $request): string
    {
        $sort = (string) ($request->input('sort') ?? 'date_desc');

        return self::MODEL_SORTS[$sort] ?? self::MODEL_SORTS['date_desc'];
    }

    /**
     * حجم الصفحة بعد القصّ على السقف.
     * The page size, clamped to the ceiling.
     */
    private function resolveLimit(Request $request): int
    {
        $limit = (int) ($request->input('limit') ?? self::DEFAULT_LIMIT);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    /**
     * تجميع صفحة واحدة من العملاء، مع الحفاظ على ترتيب الصفحة.
     * Aggregate one page of customers, preserving the page order.
     *
     * @param  array<int, mixed>  $keyRows
     * @return array<int, array<string, mixed>>
     */
    private function customerProfiles(array $keyRows): array
    {
        $custNos = [];

        foreach ($keyRows as $row) {
            $custNo = CuttingViewReader::num((array) $row, 'CUST_NO');

            if ($custNo !== null) {
                $custNos[] = (int) $custNo;
            }
        }

        if ($custNos === []) {
            return [];
        }

        $custNos = array_slice(array_values(array_unique($custNos)), 0, self::MAX_PAGE_KEYS);

        $placeholders = [];
        $bindings = [];

        foreach ($custNos as $index => $custNo) {
            $placeholders[] = ':c'.$index;
            $bindings['c'.$index] = $custNo;
        }

        $rows = DB::connection('oracle')->select(
            'SELECT v.CUST_NO,
                    MAX(v.CUST_NAME) AS CUST_NAME,
                    MAX(v.TEL_NO1) AS TEL_NO1,
                    MAX(v.ACC_NO) AS ACC_NO,
                    MAX(v.GROUP_NO) AS GROUP_NO,
                    COUNT(DISTINCT v.BILL_NO * 10000 + v.YEAR) AS BILLS_COUNT,
                    COUNT(DISTINCT (v.BILL_NO * 10000 + v.YEAR) * '.self::SEQ_FACTOR.' + v.SEQ_NO) AS MODELS_COUNT,
                    TO_CHAR(MAX(v.DAT), \'YYYY-MM-DD\') AS LAST_DATE_ISO,
                    TO_CHAR(MAX(v.DAT), \'DD/MM/YYYY\') AS LAST_DATE_TXT
               FROM '.CuttingViewReader::VIEW_BILL.' v
              WHERE v.CUST_NO IN ('.implode(', ', $placeholders).')
              GROUP BY v.CUST_NO',
            $bindings
        );

        // الاستعلام لا يضمن الترتيب، فيُعاد من قائمة المفاتيح نفسها.
        // The batch query carries no order, so the page order is restored from the keys.
        $byCust = [];

        foreach ($rows as $row) {
            $profile = $this->customerProfile((array) $row);
            $byCust[$profile['cust_no']] = $profile;
        }

        $ordered = [];

        foreach ($custNos as $custNo) {
            if (isset($byCust[$custNo])) {
                $ordered[] = $byCust[$custNo];
            }
        }

        return $ordered;
    }

    /**
     * صف عميل واحد في القائمة.
     * One customer row of the list.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function customerProfile(array $row): array
    {
        return [
            'cust_no' => CuttingViewReader::num($row, 'CUST_NO'),
            'cust_name' => CuttingViewReader::txt($row, 'CUST_NAME'),
            'tel_no1' => CuttingViewReader::txt($row, 'TEL_NO1'),
            'acc_no' => CuttingViewReader::num($row, 'ACC_NO'),
            'group_no' => CuttingViewReader::num($row, 'GROUP_NO'),
            'bills_count' => (int) (CuttingViewReader::num($row, 'BILLS_COUNT') ?? 0),
            'models_count' => (int) (CuttingViewReader::num($row, 'MODELS_COUNT') ?? 0),
            'last_date' => CuttingViewReader::txt($row, 'LAST_DATE_TXT'),
            'raw_last_date' => CuttingViewReader::txt($row, 'LAST_DATE_ISO'),
        ];
    }

    /**
     * صف موديل واحد في قائمة العميل.
     * One model row of the customer's list.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function mapModelSummary(array $row): array
    {
        return [
            'bill_no' => CuttingViewReader::num($row, 'BILL_NO'),
            'year' => CuttingViewReader::num($row, 'YEAR'),
            'seq_no' => CuttingViewReader::num($row, 'SEQ_NO'),
            'beneficiary' => CuttingViewReader::txt($row, 'BENEFICIARY_NAME'),
            'type_name' => CuttingViewReader::txt($row, 'TYPE_NAME'),
            'shape_style_no' => CuttingViewReader::num($row, 'SHAPE_STYLE_NO'),
            'manual_bill_no' => CuttingViewReader::num($row, 'MANUAL_BILL_NO'),
            'qty' => (int) (CuttingViewReader::num($row, 'MODEL_QTY') ?? 0),
            'pieces_count' => (int) (CuttingViewReader::num($row, 'PIECES_COUNT') ?? 0),
            'cutting_done' => (int) (CuttingViewReader::num($row, 'CUTTING_DONE') ?? 0),
            'tailor_done' => (int) (CuttingViewReader::num($row, 'TAILOR_DONE') ?? 0),
            'handing_done' => (int) (CuttingViewReader::num($row, 'HANDING_DONE') ?? 0),
            'date' => CuttingViewReader::txt($row, 'DAT_TXT'),
            // `D` يحمل التاريخ بصيغة ISO كما رُتِّب به، فيُقرأ بلا تحويل إضافي.
            // D holds the ISO date the rows were ordered by, so it needs no extra parsing.
            'raw_date' => CuttingViewReader::txt($row, 'D'),
        ];
    }

    /**
     * استعلام تفصيل موديل واحد — صفوف قطعه مع مواصفاته من جدول الأشكال.
     * The detail query for one model — its piece rows plus the spec row from the shapes table.
     *
     * الأعمدة مطابقة لاستعلام فاتورة التفصيل لأن الواجهة تقرأ النموذج نفسه، مع `SEQ_NO` في
     * `WHERE` بدل الفاتورة كاملة.
     * The columns match the cutting-bill detail query because the client reads the same model,
     * with SEQ_NO in WHERE instead of the whole bill.
     */
    private function detailSql(): string
    {
        return 'SELECT v.SEQ_NO, v.SEQ_NO1, v.BENEFICIARY_NAME, v.QTY AS PIECE_QTY,
                       v.SHAPE_STYLE_NO, v.STYLE_NAME, st.STYLE_NAME AS SHAPE_NAME,
                       v.CLOTH_NAME, v.CLOTH_TYPE, v.CLOTHS_COLOR, v.CLOTHS_COLOR2,
                       v.CONSUMED_CLOTH_QTY_HINSH, v.CONSUMED_CLOTH_QTY_YARDS,
                       v.CLOTH_FRM_LOCAL_STORE, v.CLOTHS_OUR_SELECTION, v.SHELF_NO,
                       v.CUTTER_NAME, v.TAILOR_NAME1, v.CUTTER_NO1, v.TAILOR_NO1,
                       v.CUTTING_DONE, v.CUTTING_DATE, v.TAILOR_DONE, v.TAILOR_DATE,
                       v.HANDING_DONE1, v.HANDING_DATE1, v.SANF_NO, v.SANF_DNO,
                       v.MANUAL_BILL_NO, v.DAT, v.CUST_NO, v.CUST_NAME, v.TEL_NO1,
                       v.ACC_NO, v.GROUP_NO, v.HANDING_DONE, v.ORDSDID, v.BILLS_KINDS,
                       v.BATCH_NO_PRINT, v.FIXED_FLEXED,
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
                  FROM '.CuttingViewReader::VIEW_BILL.' v
                  LEFT JOIN '.CuttingViewReader::TABLE_SHAPES.' s
                         ON s.BILL_NO = v.BILL_NO
                        AND s.YEAR = v.YEAR
                        AND s.SEQ_NO = v.SEQ_NO
                  LEFT JOIN '.CuttingViewReader::TABLE_STYLE.' st
                         ON st.SHAPE_STYLE_NO = v.SHAPE_STYLE_NO
                 WHERE v.BILL_NO = :bill_no
                   AND v.YEAR = :year
                   AND v.SEQ_NO = :seq_no
                 ORDER BY v.SEQ_NO1';
    }

    /**
     * بناء الموديل من صفوف قطعه — المقاسات والمواصفات من صف الموديل، والقائمة من القطع.
     * Build the model from its piece rows: measurements and specs from the model row, the list
     * from the pieces.
     *
     * المقاسات تُقرأ من أول صف لأن الواجهة تخزّنها على صف الموديل نفسه فتراها متكررة على كل
     * قطعة؛ تكرارها لا يغيّر القيمة.
     * Measurements come from the first row: the view stores them on the model row and repeats them
     * on every piece, so repeating them changes nothing.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function buildModel(array $headerRow, array $records): array
    {
        $pieces = [];

        foreach ($records as $row) {
            $pieces[] = [
                'seq_no1' => CuttingViewReader::num($row, 'SEQ_NO1'),
                'sanf_no' => CuttingViewReader::num($row, 'SANF_NO'),
                'sanf_dno' => CuttingViewReader::num($row, 'SANF_DNO'),
                'cloth_name' => CuttingViewReader::txt($row, 'CLOTH_NAME'),
                'cloth_type' => CuttingViewReader::txt($row, 'CLOTH_TYPE'),
                'color_no' => CuttingViewReader::num($row, 'CLOTHS_COLOR'),
                'color_text' => CuttingViewReader::txt($row, 'CLOTHS_COLOR2'),
                'hinsh' => CuttingViewReader::num($row, 'CONSUMED_CLOTH_QTY_HINSH'),
                'yards' => CuttingViewReader::num($row, 'CONSUMED_CLOTH_QTY_YARDS'),
                'from_local_store' => CuttingViewReader::num($row, 'CLOTH_FRM_LOCAL_STORE'),
                'our_selection' => CuttingViewReader::num($row, 'CLOTHS_OUR_SELECTION'),
                'shelf_no' => CuttingViewReader::txt($row, 'SHELF_NO'),
                'cutter_name' => CuttingViewReader::txt($row, 'CUTTER_NAME'),
                'tailor_name' => CuttingViewReader::normalizeTailorName(
                    CuttingViewReader::txt($row, 'TAILOR_NAME1')
                ),
                'cutting_done' => (int) (CuttingViewReader::num($row, 'CUTTING_DONE') ?? 0),
                'cutting_date' => CuttingViewReader::dateTxt($row, 'CUTTING_DATE'),
                'tailor_done' => (int) (CuttingViewReader::num($row, 'TAILOR_DONE') ?? 0),
                'tailor_date' => CuttingViewReader::dateTxt($row, 'TAILOR_DATE'),
                'handing_done' => (int) (CuttingViewReader::num($row, 'HANDING_DONE1') ?? 0),
                'handing_date' => CuttingViewReader::dateTxt($row, 'HANDING_DATE1'),
                'fixed_flexed' => CuttingViewReader::txt($row, 'FIXED_FLEXED'),
                'batch_no_print' => CuttingViewReader::txt($row, 'BATCH_NO_PRINT'),
            ];
        }

        $qty = (int) (CuttingViewReader::num($headerRow, 'MODEL_QTY') ?? 0);

        return [
            'seq_no' => (int) (CuttingViewReader::num($headerRow, 'SEQ_NO') ?? 0),
            'beneficiary' => CuttingViewReader::txt($headerRow, 'BENEFICIARY_NAME'),
            'type_name' => CuttingViewReader::txt($headerRow, 'STYLE_NAME'),
            'shape_name' => CuttingViewReader::txt($headerRow, 'SHAPE_NAME'),
            'shape_style_no' => CuttingViewReader::num($headerRow, 'SHAPE_STYLE_NO'),
            // بلا عدد مخزّن على صف الموديل يبقى عدد القطع هو الصورة الصادقة لا صفر مضلّل.
            // With no stored model quantity the piece count is honest where a zero would lie.
            'qty' => $qty > 0 ? $qty : count($pieces),
            'measurements' => CuttingViewReader::measurements($headerRow),
            'specs' => CuttingViewReader::specs($headerRow),
            'pieces' => $pieces,
        ];
    }

    /**
     * ترويسة العرض: بيانات العميل والطلب — بلا أي خانة مالية.
     * The view header: customer and order data — with no money column at all.
     *
     * @param  array<string, mixed>  $headerRow
     * @param  array<string, mixed>  $billRow
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    private function buildHeader(int $billNo, int $year, array $headerRow, array $billRow, array $model): array
    {
        $ordsdid = (int) (CuttingViewReader::num($headerRow, 'ORDSDID') ?? 0);

        return [
            'bill_no' => $billNo,
            'year' => $year,
            'manual_bill_no' => CuttingViewReader::num($headerRow, 'MANUAL_BILL_NO'),
            'date' => CuttingViewReader::txt($headerRow, 'DAT_TXT'),
            'raw_date' => CuttingViewReader::txt($headerRow, 'DAT_ISO'),
            'cust_no' => CuttingViewReader::num($headerRow, 'CUST_NO'),
            'cust_name' => CuttingViewReader::txt($headerRow, 'CUST_NAME'),
            'tel_no1' => CuttingViewReader::txt($headerRow, 'TEL_NO1'),
            'tel_no2' => CuttingViewReader::lower($billRow, 'TEL_NO2'),
            'address' => CuttingViewReader::lower($billRow, 'ADDRESS'),
            'ref_no' => CuttingViewReader::lower($billRow, 'REF_NO'),
            'details' => CuttingViewReader::lower($billRow, 'DETAILES'),
            'acc_no' => CuttingViewReader::num($headerRow, 'ACC_NO'),
            'group_no' => CuttingViewReader::num($headerRow, 'GROUP_NO'),
            'branch_no' => CuttingViewReader::lower($billRow, 'BRANCH_NO'),
            'giving_date' => CuttingViewReader::txt($headerRow, 'GIVING_DATE_TXT'),
            'handing_done' => CuttingViewReader::num($headerRow, 'HANDING_DONE'),
            'handing_date' => CuttingViewReader::txt($headerRow, 'HANDING_DATE_TXT'),
            'ordsdid' => $ordsdid,
            'ords_name' => CuttingViewReader::orderStateName($ordsdid),
            'beneficiary' => $model['beneficiary'],
            'type_name' => $model['type_name'],
            'seq_no' => $model['seq_no'],
            'models_count' => 1,
            'pieces_count' => count($model['pieces']),
        ];
    }

    /**
     * بقية موديلات نفس الفاتورة — ليتنقّل شريط الفلترة بينها بلا طلب إضافي.
     * The other models of the same bill — so the filter bar can move without another request.
     *
     * @return array<int, array<string, mixed>>
     */
    private function siblingModels(int $billNo, int $year, int $seqNo): array
    {
        try {
            $rows = DB::connection('oracle')->select(
                'SELECT v.SEQ_NO,
                        MAX(v.BENEFICIARY_NAME) AS BENEFICIARY_NAME,
                        MAX(v.STYLE_NAME) AS TYPE_NAME,
                        COUNT(*) AS PIECES_COUNT,
                        TO_CHAR(MAX(v.DAT), \'DD/MM/YYYY\') AS DAT_TXT
                   FROM '.CuttingViewReader::VIEW_BILL.' v
                  WHERE v.BILL_NO = :bill_no
                    AND v.YEAR = :year
                  GROUP BY v.SEQ_NO
                  ORDER BY v.SEQ_NO',
                ['bill_no' => $billNo, 'year' => $year]
            );
        } catch (\Throwable $e) {
            // الإخوة تزيينيون: فشلهم لا يُسقط المقاسات المطلوبة.
            // Siblings are decoration: their failure must not drop the requested measurements.
            report($e);

            return [];
        }

        $siblings = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $sibSeq = (int) (CuttingViewReader::num($row, 'SEQ_NO') ?? 0);

            $siblings[] = [
                'seq_no' => $sibSeq,
                'beneficiary' => CuttingViewReader::txt($row, 'BENEFICIARY_NAME'),
                'type_name' => CuttingViewReader::txt($row, 'TYPE_NAME'),
                'pieces_count' => (int) (CuttingViewReader::num($row, 'PIECES_COUNT') ?? 0),
                'date' => CuttingViewReader::txt($row, 'DAT_TXT'),
                // العلامة تُحسب في PHP لا في SQL لأن الصفوف هنا قليلة (موديلات فاتورة واحدة).
                // Flagged in PHP, not SQL: this set is small (one bill's models).
                'selected' => $sibSeq === $seqNo,
            ];
        }

        return $siblings;
    }

    /**
     * تسجيل حدث في سجل النشاط — لا يُفشل الطلب عند فشله.
     * Write an activity event — never breaks the request.
     *
     * @param  array<string, mixed>  $context
     */
    private function logActivity(Request $request, string $actionCode, array $context): void
    {
        try {
            app(ActivityLogger::class)->success($actionCode, array_merge([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'module_code' => 'customers',
                'entity_type' => 'customer_measurement',
                'platform' => 'mobile',
            ], $context), $request);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * تحويل التواريخ بأشكالها المختلفة إلى YYYY-MM-DD — مطابق لسائر المتحكمات.
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
     * معالج الأخطاء المركزي — نفس مغلّف بقية المتحكمات.
     * Central error handler — the same envelope as the other controllers.
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
