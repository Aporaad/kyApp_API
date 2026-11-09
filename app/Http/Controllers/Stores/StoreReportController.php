<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\StorePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تقارير المخازن — المراجع والأرصدة وحركة الأصناف وخلاصتها
 * Store reports — reference data, balances, item movement and its summary.
 *
 * ── مصدر واحد للحركة: `ITEM_MOVEMENT_D`، وهي الـ VIEW التي تبني عليها `AVVG` نفسها؛
 *    فيستحيل أن يخالف التقريرُ الرصيدَ المعروض في شاشة المخزون.
 * ── One movement source: ITEM_MOVEMENT_D, the very view AVVG is built on, so a report can
 *    never disagree with the balance shown on the stock screen.
 *
 * ── مدى زمني إلزامي (سننان كحد أقصى): التقارير بلا مدى تمسح الـ VIEW كاملة، والـ VIEW
 *    UNION ALL على سبعة جداول مستندات.
 * ── A bounded date range is mandatory (two years max): an unbounded report scans the whole
 *    view, which is a UNION ALL over seven document tables.
 */
class StoreReportController extends Controller
{
    private const MAX_LIMIT = 5000;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * المراجع التي تحتاجها نوافذ التصفية: مخازن · مجموعات · أنواع · فئات · وحدات · أنواع حركة
     * Every reference the filter sheet needs, in one round-trip.
     */
    public function reference(Request $request): JsonResponse
    {
        try {
            $stores = DB::connection('oracle')->select(
                'SELECT s.STORE_NO, s.STORE_NAME,
                        (SELECT COUNT(*) FROM ACCOUNTS.STORE_EMP e WHERE e.STORE_NO = s.STORE_NO) AS KEEPERS
                   FROM ACCOUNTS.STORES s ORDER BY s.STORE_NO'
            );

            $groups = DB::connection('oracle')->select(
                'SELECT GROUP_NO, GROUP_NAME FROM ACCOUNTS.GROUP1 ORDER BY GROUP_NO'
            );

            $types = DB::connection('oracle')->select(
                'SELECT TYPE_NO, TYPE_NAME, GROUP_NO FROM ACCOUNTS.TYPES ORDER BY GROUP_NO, TYPE_NO'
            );

            $categories = DB::connection('oracle')->select(
                'SELECT SEQ, CAT_NO, CATEGORY FROM ACCOUNTS.CATEGORY ORDER BY CAT_NO'
            );

            $units = DB::connection('oracle')->select(
                'SELECT UNIT_NO, UNIT_NAME FROM ACCOUNTS.UNITS ORDER BY UNIT_NO'
            );

            $movementTypes = DB::connection('oracle')->select(
                'SELECT TYPE_NO, TYPE_NAME, INOUT FROM ACCOUNTS.TRANS_TYPE ORDER BY TYPE_NO'
            );

            // الأنماط (`STYLE`) تُحزَّم هنا لأن نموذج الصنف يحتاجها: الـ VIEW تربط
            // `sanfs.styl_no = style.shape_style_no`، فصنف بلا نمط لا يظهر في أي حركة.
            // Styles are bundled here because the item form needs them: the movement view
            // joins on sanfs.styl_no, so an item without a style never appears in movement.
            $styles = DB::connection('oracle')->select(
                'SELECT SHAPE_STYLE_NO AS ID, STYLE_NAME AS NAME, STYL_CODE AS CODE,
                        GROUP_NO, TYPE_NO
                   FROM ACCOUNTS.STYLE
                  WHERE NVL(ACTIVE, 1) = 1
                  ORDER BY GROUP_NO, TYPE_NO, SHAPE_STYLE_NO'
            );

            // العملات وألوان الأصناف: نموذج المستند المكتبي يحتاجهما — صفّا عملة (الأساسية
            // والثانية) وخانة لون — فمرجع بلا عملات يعني نموذجاً يعرض قائمتين فارغتين.
            // Currencies and item colours: the desktop document form needs both — two currency
            // rows and a colour field. A reference without them means a form with empty dropdowns.
            $currencies = DB::connection('oracle')->select(
                'SELECT CUR_NO, CUR_NAME FROM ACCOUNTS.CURRENCY ORDER BY CUR_NO'
            );

            $colors = DB::connection('oracle')->select(
                'SELECT COLOR_NO, COLOR_NAME, COLOR_TYPE FROM ACCOUNTS.SANFS_COLOR ORDER BY COLOR_NO'
            );

            $excluded = StorePolicy::excludedStores();

            return response()->json([
                'success' => true,
                'data' => [
                    'stores' => $this->clean($stores, $excluded),
                    'groups' => $this->clean($groups),
                    'types' => $this->clean($types),
                    'categories' => $this->clean($categories),
                    'units' => $this->clean($units),
                    'movement_types' => $this->clean($movementTypes),
                    'styles' => $this->clean($styles),
                    'currencies' => $this->clean($currencies),
                    'colors' => $this->clean($colors),
                    // أنواع الفاتورة كما في النظام المكتبي: 1 نقد · 2 آجل — مُعرَّفة هنا لا
                    // مكتوبة في الواجهة، حتى يبقى المصدر واحداً لو ظهرت أنواع أخرى.
                    // Bill types exactly as the desktop uses them: 1 cash · 2 credit.
                    'bill_types' => [
                        ['no' => 1, 'name' => 'نقد'],
                        ['no' => 2, 'name' => 'آجل'],
                    ],
                    // وصف الأنواع الثلاثة: حقول كل نوع كما يعلنها الخادم، فيبني العميل
                    // شاشته من إعلان واحد لا من شروط مكررة في كل شاشة.
                    // The three kinds' profiles: each kind declares its own fields, so the client
                    // builds its screen from one declaration instead of duplicate conditions.
                    'documents' => StorePolicy::documentProfiles(),
                    'excluded_stores' => $excluded,
                    'max_range_days' => StorePolicy::MAX_REPORT_DAYS,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب المراجع / Failed to retrieve reference data.', $e);
        }
    }

    /**
     * بحث الحسابات في دليل الحسابات — لمُنتقي «حساب المخزن / حساب دائن» في نموذج المستند
     * Chart-of-accounts search — for the document form's store/counter account pickers.
     *
     * ── لماذا مسار مستقل؟ لأن النموذج يحتاج بحثاً بالاسم والرقم في CHART_ACC، وقراءة دليل
     *    الحسابات الكاملة ليست صلاحية كل من يملك إنشاء أمر توريد. هنا تُرجع 30 صفاً فقط،
     *    بالاسم العربي المُصلَّح، بلا أي رصيد أو أثر مالي.
     * ── Why a separate route: the form needs a name/number search over CHART_ACC, while reading
     *    the whole chart of accounts is not a grant every receive-order creator holds. This
     *    returns at most 30 rows with the repaired Arabic name and no balances at all.
     */
    public function accounts(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = max(1, min((int) ($request->input('limit') ?? 30), 100));

        $where = ['1 = 1'];
        // العلامة تُربط بمفتاحين مختلفين: بعض منتقي OCI8 لا يتعامل مع إعادة استخدام اسم واحد
        // The placeholder is bound under two keys: OCI8 does not reliably reuse one named bind.
        $binds = ['limit1' => $limit, 'limit2' => $limit];

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);
            $where[] = '(a.ACC_NAME LIKE :name_ar OR a.ACC_NAME LIKE :name_utf OR a.ACC_NO = :acc_no)';
            $binds['name_ar'] = '%'.$oracle.'%';
            $binds['name_utf'] = '%'.$search.'%';
            $binds['acc_no'] = is_numeric($search) ? (int) $search : -1;
        }

        $sql = 'SELECT * FROM (
                    SELECT q.*, ROWNUM AS RN FROM (
                        SELECT a.ACC_NO, a.ACC_NAME, a.GROUP_ID
                          FROM ACCOUNTS.CHART_ACC a
                         WHERE '.implode(' AND ', $where).'
                         ORDER BY a.ACC_NO
                    ) q WHERE ROWNUM <= :limit1
                ) WHERE RN <= :limit2';

        try {
            $rows = DB::connection('oracle')->select($sql, $binds);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الحسابات / Accounts retrieved.',
                'data' => $this->clean($rows),
                'meta' => ['total' => count($rows), 'limit' => $limit],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب الحسابات / Failed to retrieve accounts.', $e);
        }
    }

    /**
     * الأرصدة الحالية من `AVVG` مع تصفية ومجاميع تذييل
     * Current balances from AVVG with filters and footer totals.
     */
    public function balances(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? 200), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        $where = ['1 = 1'];
        $binds = [];

        $storeNos = $this->scopeList($request, 'store_nos', 'store_no');

        if ($storeNos !== []) {
            $where[] = $this->inClause('INV_ID', $storeNos, $binds, 'st');
        }

        $search = trim((string) ($request->input('search') ?? ''));

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);
            $where[] = '(ITEM_NAME LIKE :name_ar OR ITEM_NAME LIKE :name_utf OR PART_NO LIKE :part)';
            $binds['name_ar'] = '%'.$oracle.'%';
            $binds['name_utf'] = '%'.$search.'%';
            $binds['part'] = '%'.$search.'%';
        }

        // الأرصدة تتبع نفس قوائم التصفية التي يستعملها التقرير، فلا يختلف «نفس الاختيار»
        // بين شاشتين
        // Balances accept the same multi-selection as the reports, so one choice cannot mean
        // two different things on two screens.
        $groupNos = $this->scopeList($request, 'group_nos', 'group_no');

        if ($groupNos !== []) {
            $where[] = $this->inClause('GROUPNO', $groupNos, $binds, 'gr');
        }

        $itemTypeNos = $this->scopeList($request, 'item_type_nos', 'type_no');

        if ($itemTypeNos !== []) {
            $where[] = $this->inClause('TYPE_NO', $itemTypeNos, $binds, 'it');
        }

        $only = (string) ($request->input('only') ?? 'all');

        if ($only === 'positive') {
            $where[] = 'SUM_TP > 0';
        } elseif ($only === 'negative') {
            $where[] = 'SUM_TP < 0';
        }

        $whereSql = implode(' AND ', $where);

        try {
            $aggregate = array_change_key_case((array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS TOTAL, NVL(SUM(QTY), 0) AS QTY, NVL(SUM(VALUE), 0) AS VALUE,
                        SUM(CASE WHEN QTY < 0 THEN 1 ELSE 0 END) AS NEGATIVE
                   FROM (
                        SELECT I_CODE, INV_ID, SUM(SUM_TP) AS QTY, SUM(SUM_PRICE) AS VALUE
                          FROM ACCOUNTS.AVVG WHERE '.$whereSql.'
                         GROUP BY I_CODE, INV_ID
                   )',
                $binds
            ), CASE_UPPER);

            $pagedBinds = $binds;
            $pagedBinds['offset'] = $offset;
            $pagedBinds['offset2'] = $offset;
            $pagedBinds['limit'] = $limit;

            $sql = 'SELECT * FROM (
                        SELECT q.*, ROWNUM AS RN FROM (
                            SELECT I_CODE, MAX(PART_NO) AS PART_NO, MAX(ITEM_NAME) AS ITEM_NAME,
                                   MAX(UOM_CODE) AS UOM_CODE, INV_ID, MAX(INVNAME) AS INVNAME,
                                   SUM(SUM_TP) AS QTY, MAX(STK_COST) AS COST,
                                   SUM(SUM_PRICE) AS VALUE, MAX(GROUPNO) AS GROUPNO,
                                   MAX(GROUP_NAME) AS GROUP_NAME, MAX(TYPE_NO) AS TYPE_NO,
                                   MAX(TYPE_NAME) AS TYPE_NAME, MAX(CAT) AS CAT
                              FROM ACCOUNTS.AVVG
                             WHERE '.$whereSql.'
                             GROUP BY I_CODE, INV_ID
                             -- الترتيب بالأسماء المستعارة لا بـ MAX(...) مكرراً: أوراكل تدمج الكتلة
                             -- المجمّعة داخل الغلاف فيتداخل التجميع (ORA-00935) حين يُكرَّر MAX
                             -- في ORDER BY.
                             -- Order by the aliases, never by a repeated MAX(...): Oracle merges the
                             -- grouped block into the wrapper and nests the aggregates (ORA-00935).
                             ORDER BY ITEM_NAME, INV_ID
                        ) q WHERE ROWNUM <= :offset2 + :limit
                    ) WHERE RN > :offset';

            $rows = DB::connection('oracle')->select($sql, $pagedBinds);

            return response()->json([
                'success' => true,
                'data' => array_map(
                    static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                    $rows
                ),
                'meta' => [
                    'total' => (int) ($aggregate['TOTAL'] ?? 0),
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil(((int) ($aggregate['TOTAL'] ?? 0)) / $limit) : 1,
                    'qty' => (float) ($aggregate['QTY'] ?? 0),
                    'value' => (float) ($aggregate['VALUE'] ?? 0),
                    'negative' => (int) ($aggregate['NEGATIVE'] ?? 0),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب الأرصدة / Failed to retrieve balances.', $e);
        }
    }

    /**
     * عرض حركة — سطر لكل حركة مخزنية في المدى المحدد
     * Movement view — one row per stock movement inside the range.
     *
     * ── التقرير الآن يُبنى على **نطاق** لا على مجرد فلاتر: حركة صنف فرعي جدول واحد،
     *    وحركة صنف رئيسي تُقسَّم لكل فرع، وحركة مجموعة فرعية لكل صنف رئيسي، وحركة
     *    مجموعة رئيسية لكل مجموعة فرعية — ولكل قسم إحصاؤه ثم إحصاء عام في النهاية.
     * ── A report now has a SCOPE, not just filters: a branch statement is one table, an item
     *    statement sections by branch, a sub-group statement by main item, a main-group one by
     *    sub group — each section with its own statistics and a grand total at the end.
     */
    public function movement(Request $request): JsonResponse
    {
        $range = $this->resolveRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;

        $where = ['m.I_DATE >= TO_DATE(:from_date, \'YYYY-MM-DD\')',
            'm.I_DATE < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1'];
        $binds = ['from_date' => $from, 'to_date' => $to];

        $this->applyScope($request, $where, $binds);

        // شروط النطاق بدون المدى الزمني: بها يُقاس الرصيد السابق على نفس الأقسام بالضبط
        // The scope predicates without the date window: the opening balance must be measured
        // over the very same sections or the two halves of the report would not add up.
        $outerWhere = [];
        $outerBinds = [];
        $this->applyScope($request, $outerWhere, $outerBinds);

        $scope = $this->movementScope($request);
        $sectionColumn = self::MOVEMENT_SECTIONS[$scope] ?? null;

        $limit = max(1, min((int) ($request->input('limit') ?? 2000), self::MAX_LIMIT));
        $binds['limit'] = $limit;
        $whereSql = implode(' AND ', $where);

        // الترتيب بالقسم ثم بالتاريخ: الرصيد الجاري والإحصاء يُحسبان بتراكم صفّي واحد
        // Order by section then date: the running balance and the section stats come out of a
        // single sequential pass, with no second query and no guesswork.
        $orderSql = $sectionColumn === null
            ? 'm.I_DATE, m.INVOIC_ID, m.I_CODE'
            : $sectionColumn.', m.I_DATE, m.INVOIC_ID, m.I_CODE';

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT m.I_DATE, m.INVOIC_ID AS DOC_NO, m.DOC_NO AS MANUAL_BILL_NO, m.DOC_TYPE,
                           m.BILL_DOC_TYPE AS TYPE_NO, m.TR_INV_TYPE_NAME, m.INV_ID, m.INV_NAME,
                           m.I_CODE, m.P_CODE, m.IT_NAME, m.PART_NO, m.UOM_CODE,
                           m.GROUPNO, m.GROUP_NAME, m.TYPE_NO AS ITEM_TYPE_NO, m.TYPE_NAME, m.CAT_NAME,
                           -- الفرع بلقطة فردية لا برابط: الرابط على جدول غير فريد يضاعف السطور
                           -- The branch by a scalar lookup, never a join: joining a non-unique
                           -- table would multiply the very rows this report counts.
                           (SELECT MAX(sd.SANF_DNAME) FROM ACCOUNTS.SANFS_D sd
                             WHERE sd.SANF_DNO = m.P_CODE) AS SUB_NAME,
                           (SELECT MAX(sc.COLOR_NAME) FROM ACCOUNTS.SANFS_COLOR sc
                             WHERE sc.COLOR_NO = m.COLOR_NO) AS COLOR_NAME,
                           CASE WHEN m.P_QTY > 0 THEN m.P_QTY END AS QTY_IN,
                           CASE WHEN m.P_QTY < 0 THEN -m.P_QTY END AS QTY_OUT,
                           m.P_QTY, m.STK_COST, ROUND(m.P_QTY * m.STK_COST, 2) AS LINE_VALUE
                      FROM ACCOUNTS.ITEM_MOVEMENT_D m
                     WHERE '.$whereSql.'
                     ORDER BY '.$orderSql.'
                 ) WHERE ROWNUM <= :limit',
                $binds
            );

            // تطبيع المفاتيح إلى الكبيرة قبل أي معالجة: منتقي OCI8 يعيدها صغيرة، وقراءة
            // P_QTY/P_CODE من صف كهذا تُرجع null، فيصير الرصيد الجاري صفراً دائماً ويبدو
            // عمود الرصيد فارغاً على الشاشة رغم أن الحركة موجودة فعلاً.
            // OCI8 hands back lowercase keys; reading P_QTY/P_CODE off such a row yields null,
            // so the running balance accumulates nothing and the balance column reads empty
            // while the movement itself is plainly there.
            $clean = array_map(
                static fn ($row): array => ArabicEncoding::cleanData(
                    array_change_key_case((array) $row, CASE_UPPER)
                ),
                $rows
            );

            // الرصيد السابق يُحسب في كل نطاق: حركة الصنف الفرعي تقرير واحد له رصيد سابق واحد،
            // وليس استثناءً بلا افتتاح. تركه فارغاً هو سبب بدء عمود الرصيد من الصفر.
            // The opening is computed in every scope: a branch statement is one report with one
            // opening balance, never an exception without one. Leaving it out is exactly why
            // the balance column used to start from zero.
            $openings = $this->openingBySection(
                '('.implode(') AND (', $outerWhere ?: ['1 = 1']).')',
                $outerBinds,
                $from,
                $sectionColumn
            );

            // الوسم يُحفظ في متغيّر قبل بناء الإحصاءات: مصفوفات PHP تُنسخ عند الكتابة، فتمرير
            // السطور الخام إلى `movementMeta` يجعلها لا ترى `SECTION_KEY` وتنهار كل الأقسام
            // إلى قسم واحد مفتاحه `__all__` بلا عنوان ولا رصيد سابق.
            // Stamping is kept in a variable before the meta is built: PHP arrays copy on
            // write, so handing the RAW rows to `movementMeta` meant it never saw `SECTION_KEY`
            // and every section collapsed into one `__all__` entry with no label and no opening.
            $stamped = $this->stampMovement($clean, $scope, $sectionColumn, $openings);

            return response()->json([
                'success' => true,
                'data' => $stamped,
                'meta' => $this->movementMeta($stamped, $scope, $sectionColumn, $openings, $from, $to, $limit),
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب حركة المخزون / Failed to retrieve stock movement.', $e);
        }
    }

    /**
     * عمود التقسيم لكل نطاق حركة — لا قسم البتة في حركة الصنف الفرعي
     * The section column per movement scope — a branch statement has no sections at all.
     */
    private const MOVEMENT_SECTIONS = [
        'branch' => null,
        'item' => 'm.P_CODE',
        'subgroup' => 'm.I_CODE',
        'group' => 'm.TYPE_NO',
    ];

    /**
     * نطاق تقرير الحركة: من المعطيات إن لم يُرسل صراحة
     * The movement report's scope, inferred from the parameters when not sent explicitly.
     */
    private function movementScope(Request $request): string
    {
        $scope = (string) ($request->input('scope') ?? '');

        if (array_key_exists($scope, self::MOVEMENT_SECTIONS)) {
            return $scope;
        }

        // التراتيب الطبيعي للسؤال: فرع ← صنف ← مجموعة فرعية ← مجموعة رئيسية
        // The natural order of the question: branch, item, sub group, main group.
        if ($this->intOrNull($request->input('sanf_dno')) !== null) {
            return 'branch';
        }

        if ($this->intOrNull($request->input('sanf_no')) !== null) {
            return 'item';
        }

        return $this->scopeList($request, 'item_type_nos', 'type_no') !== [] ? 'subgroup' : 'group';
    }

    /**
     * رصيد ما قبل الفترة لكل قسم — الكمية والقيمة معاً في استعلام واحد
     * The pre-period balance per section — quantity and value in a single query.
     *
     * @return array<string, array{qty: float, value: float}>
     */
    private function openingBySection(
        string $scopeSql,
        array $binds,
        string $from,
        ?string $sectionColumn
    ): array {
        // المفتاح يُختار مرة واحدة: بلا أقسام (حركة صنف فرعي) يصير `__all__` — وهو نفس المفتاح
        // الذي يوسم به `stampMovement` سطوره — بدل قسم مخترع لا يطابق أي رصيد فيبدأ العمود من الصفر.
        // The key is resolved once: with no sections (a branch statement) it becomes `__all__` —
        // the very key `stampMovement` stamps its rows with — instead of an invented section that
        // matches no balance and opens the running column on zero.
        $keySql = $sectionColumn === null ? "'__all__' AS SK" : $sectionColumn.' AS SK';
        $groupSql = $sectionColumn === null ? '' : ' GROUP BY '.$sectionColumn;

        // القيمة تُجمع من تكلفة كل حركة قبل الفترة: قسم قد لا يتحرك إطلاقاً داخل الفترة وله رصيد
        // سابق قائم، فتصفير قيمة الرصيد الحالي في هذه الحالة كذب على المستند.
        // The value sums each pre-period movement's own cost: a section may not move at all
        // inside the period yet still carry stock, and zeroing its closing value would be a lie.
        $rows = DB::connection('oracle')->select(
            'SELECT '.$keySql.',
                    NVL(SUM(m.P_QTY), 0) AS OPEN_QTY,
                    NVL(SUM(m.P_QTY * m.STK_COST), 0) AS OPEN_VALUE
               FROM ACCOUNTS.ITEM_MOVEMENT_D m
              WHERE '.$scopeSql.'
                AND m.I_DATE < TO_DATE(:open_from, \'YYYY-MM-DD\')'.$groupSql,
            $binds + ['open_from' => $from]
        );

        $openings = [];

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            $openings[(string) ($row['SK'] ?? '')] = [
                'qty' => (float) ($row['OPEN_QTY'] ?? 0),
                'value' => (float) ($row['OPEN_VALUE'] ?? 0),
            ];
        }

        return $openings;
    }

    /**
     * وسم السطور بالقسم والرصيد الجاري — تراكم واحد فوق السطور المرتبة أصلاً
     * Stamp the rows with their section and running balance — one accumulation over rows
     * that are already ordered by section and date.
     *
     * ── الرصيد الجاري يُحسب بالتراكم لا باستعلام لكل سطر: الفرق بين 2000 سطر و 2000
     *    رحلة قاعدة بيانات هو ما يجعل التقرير مستحيلاً لا بطيئاً فحسب.
     * ── The running balance accumulates instead of querying per row: the difference between
     *    2000 rows and 2000 database round-trips is the line between slow and impossible.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array{qty: float, value: float}>  $openings
     * @return array<int, array<string, mixed>>
     */
    private function stampMovement(
        array $rows,
        string $scope,
        ?string $sectionColumn,
        array $openings
    ): array {
        // عمود المفتاح في الصف نفسه: السطور لا تحمل أسماء أعمدة الـ SQL الخام
        // The key as the row carries it: rows never hold the raw SQL column names.
        $keyField = match ($scope) {
            'item' => 'P_CODE',
            'subgroup' => 'I_CODE',
            'group' => 'ITEM_TYPE_NO',
            default => null,
        };

        $labelField = match ($scope) {
            'item' => 'SUB_NAME',
            'subgroup' => 'IT_NAME',
            'group' => 'TYPE_NAME',
            default => null,
        };

        $current = null;
        $balanceQty = 0.0;
        $balanceValue = 0.0;

        foreach ($rows as $index => $row) {
            $key = $keyField === null
                ? '__all__'
                : (string) ($row[$keyField] ?? '');

            if ($key !== $current) {
                $current = $key;
                // الرصيد يبدأ من رصيد افتتاح قسمه كمّاً وقيمةً، لا من الصفر
                // A section starts at its own opening balance in both quantity and value.
                $opening = $openings[$key] ?? ['qty' => 0.0, 'value' => 0.0];
                $balanceQty = (float) ($opening['qty'] ?? 0);
                $balanceValue = (float) ($opening['value'] ?? 0);
            }

            $balanceQty += (float) ($row['P_QTY'] ?? 0);
            $balanceValue += (float) ($row['LINE_VALUE'] ?? 0);

            $rows[$index]['SECTION_KEY'] = $key;
            $rows[$index]['SECTION_LABEL'] = $labelField === null
                ? ''
                : trim((string) ($row[$labelField] ?? ''));
            $rows[$index]['BALANCE_QTY'] = round($balanceQty, 4);
            $rows[$index]['BALANCE_VALUE'] = round($balanceValue, 2);
        }

        return $rows;
    }

    /**
     * إحصاءات الأقسام والإحصاء العام — لكل قسم رصيد سابق ووارد ومنصرف ورصيد حالي
     * Per-section statistics plus the grand total: opening, inbound, outbound, closing.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array{qty: float, value: float}>  $openings
     * @return array<string, mixed>
     */
    private function movementMeta(
        array $rows,
        string $scope,
        ?string $sectionColumn,
        array $openings,
        string $from,
        string $to,
        int $limit
    ): array {
        $sections = [];

        foreach ($rows as $row) {
            $key = (string) ($row['SECTION_KEY'] ?? '__all__');
            $label = (string) ($row['SECTION_LABEL'] ?? '');

            if (! isset($sections[$key])) {
                $sections[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'opening_qty' => (float) ($openings[$key]['qty'] ?? 0),
                    'opening_value' => (float) ($openings[$key]['value'] ?? 0),
                    'rows' => 0,
                    'qty_in' => 0.0,
                    'qty_in_value' => 0.0,
                    'qty_out' => 0.0,
                    'qty_out_value' => 0.0,
                ];
            }

            $qtyIn = (float) ($row['QTY_IN'] ?? 0);
            $qtyOut = (float) ($row['QTY_OUT'] ?? 0);
            $value = (float) ($row['LINE_VALUE'] ?? 0);

            $sections[$key]['rows']++;
            $sections[$key]['qty_in'] += $qtyIn;
            $sections[$key]['qty_out'] += $qtyOut;

            // قيمة الوارد/المنصرف تُفصل لأن المستند قد يدخل بتكلفة ويخرج بأخرى
            // Inbound and outbound values stay separate: a document may cost in at one price
            // and leave at another.
            $sections[$key]['qty_in_value'] += $qtyIn > 0 ? $value : 0;
            $sections[$key]['qty_out_value'] += $qtyOut > 0 ? -$value : 0;

            // الوصف قد يأتي على أول سطر ثم يفرغ على بقية السطور: لا يُستبدل بوصف أعمى
            // A label can be blank on the first row and present later: never keep the blank.
            if ($sections[$key]['label'] === '' && $label !== '') {
                $sections[$key]['label'] = $label;
            }
        }

        foreach ($sections as $key => $section) {
            $net = (float) $section['qty_in'] - (float) $section['qty_out'];

            $sections[$key]['net_qty'] = round($net, 4);
            $sections[$key]['closing_qty'] = round((float) $section['opening_qty'] + $net, 4);
            // الرصيد الحالي قيمةً = افتتاح + وارد − منصرف، لا حركة الفترة وحدها
            // The closing value = opening + inbound − outbound, not the period's movement alone.
            $sections[$key]['closing_value'] = round(
                (float) $section['opening_value']
                    + (float) $section['qty_in_value']
                    - (float) $section['qty_out_value'],
                2
            );
            $sections[$key]['opening_value'] = round((float) $section['opening_value'], 2);
            $sections[$key]['qty_in'] = round((float) $section['qty_in'], 4);
            $sections[$key]['qty_out'] = round((float) $section['qty_out'], 4);
            $sections[$key]['qty_in_value'] = round((float) $section['qty_in_value'], 2);
            $sections[$key]['qty_out_value'] = round((float) $section['qty_out_value'], 2);
            $sections[$key]['opening_qty'] = round((float) $section['opening_qty'], 4);
        }

        $totals = [
            'sections' => count($sections),
            'rows' => count($rows),
            'opening_qty' => array_sum(array_column($sections, 'opening_qty')),
            'opening_value' => array_sum(array_column($sections, 'opening_value')),
            'qty_in' => array_sum(array_column($sections, 'qty_in')),
            'qty_in_value' => array_sum(array_column($sections, 'qty_in_value')),
            'qty_out' => array_sum(array_column($sections, 'qty_out')),
            'qty_out_value' => array_sum(array_column($sections, 'qty_out_value')),
            'net_qty' => array_sum(array_column($sections, 'net_qty')),
            'closing_qty' => array_sum(array_column($sections, 'closing_qty')),
            'closing_value' => array_sum(array_column($sections, 'closing_value')),
        ];

        return [
            'total' => count($rows),
            'truncated' => count($rows) >= $limit,
            'from' => $from,
            'to' => $to,
            'kind' => 'movement',
            'scope' => $scope,
            'sectioned' => $sectionColumn !== null,
            'sections' => array_values($sections),
            'totals' => $totals,
            // مفتاحان قديمان يُبقيانهما لأن شاشات الاختبارات والمستهلكين تقرأهما
            // Two legacy keys stay because existing tests and consumers read them.
            'qty_in' => $totals['qty_in'],
            'qty_out' => $totals['qty_out'],
            'value' => $totals['closing_value'],
        ];
    }

    /**
     * خلاصة — وارد/صادر/صافي لكل صنف خلال المدى
     * Summary — inbound/outbound/net per item across the range.
     */
    public function summary(Request $request): JsonResponse
    {
        $range = $this->resolveRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;

        $where = ['m.I_DATE >= TO_DATE(:from_date, \'YYYY-MM-DD\')',
            'm.I_DATE < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1'];
        $binds = ['from_date' => $from, 'to_date' => $to];

        $this->applyScope($request, $where, $binds);

        $limit = max(1, min((int) ($request->input('limit') ?? 2000), self::MAX_LIMIT));
        $binds['limit'] = $limit;
        $whereSql = implode(' AND ', $where);

        // ── التجميع مركّب لا مفرد: «صنف فرعي × مخزن» و«مجموعة فرعية × صنف رئيسي» سؤالان
        //    حقيقيان في المراجعة، وكان كل مستوى منها يستلزم دالة GROUP BY جديدة بيده.
        //    الرموز تُقرأ من قائمة بيضاء فقط، فلا يصل أي نص مستخدم إلى نص SQL.
        // ── Grouping is composite, not single: "branch × store" and "sub group × main item" are
        //    real review questions, and each level used to need its own hand-written GROUP BY.
        //    Tokens come from a whitelist only, so no user text ever reaches the SQL.
        $groupBy = (string) ($request->input('group_by') ?? 'item_store');

        // الصيغة القديمة «item» كانت تعني صنف × مخزن حرفياً، فتبقى معناها لا يتغير
        // The legacy "item" already meant item × store, so its meaning must not drift.
        if ($groupBy === 'item') {
            $groupBy = 'item_store';
        }

        $tokens = $this->groupTokens($groupBy);

        if ($tokens === []) {
            $tokens = ['item', 'store'];
            $groupBy = 'item_store';
        }

        $groupColumns = implode(', ', array_map(
            static fn (string $token): string => 'm.'.self::GROUP_COLUMNS[$token],
            $tokens
        ));

        $outerWhere = [];
        $outerBinds = [];
        $this->applyScope($request, $outerWhere, $outerBinds);

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT q.*, (SELECT MAX(sd.SANF_DNAME) FROM ACCOUNTS.SANFS_D sd
                                WHERE sd.SANF_DNO = q.P_CODE) AS SUB_NAME
                   FROM (
                    SELECT * FROM (
                    SELECT MAX(m.I_CODE) AS I_CODE, MAX(m.PART_NO) AS PART_NO, MAX(m.IT_NAME) AS ITEM_NAME,
                           MAX(m.UOM_CODE) AS UOM_CODE, MAX(m.INV_ID) AS INV_ID,
                           MAX(m.INV_NAME) AS INV_NAME,
                           MAX(m.GROUPNO) AS GROUPNO, MAX(m.GROUP_NAME) AS GROUP_NAME,
                           MAX(m.TYPE_NO) AS ITEM_TYPE_NO, MAX(m.TYPE_NAME) AS TYPE_NAME,
                           MAX(m.CAT_NAME) AS CAT_NAME,
                           -- اسم الفرع في طبقة خارجية لا هنا: تداخل تجميعة داخل استعلام فرعي
                           -- يرفضه أوراكل، وهو ما كان يضاعف السطور حين جُرّب رابطًا
                           -- The branch name resolves in the outer layer: an aggregate nested in a
                           -- scalar subquery is rejected by Oracle, and a join there multiplied rows.
                           MAX(m.P_CODE) AS P_CODE,
                           SUM(CASE WHEN m.P_QTY > 0 THEN m.P_QTY ELSE 0 END) AS QTY_IN,
                           SUM(CASE WHEN m.P_QTY < 0 THEN -m.P_QTY ELSE 0 END) AS QTY_OUT,
                           SUM(m.P_QTY) AS NET_QTY,
                           ROUND(SUM(CASE WHEN m.P_QTY > 0 THEN m.P_QTY * m.STK_COST ELSE 0 END), 2) AS IN_VALUE,
                           ROUND(SUM(CASE WHEN m.P_QTY < 0 THEN -m.P_QTY * m.STK_COST ELSE 0 END), 2) AS OUT_VALUE,
                           MAX(m.STK_COST) AS COST,
                           ROUND(SUM(m.P_QTY * m.STK_COST), 2) AS NET_VALUE,
                           COUNT(*) AS ROWS_COUNT
                      FROM ACCOUNTS.ITEM_MOVEMENT_D m
                     WHERE '.$whereSql.'
                     GROUP BY '.$groupColumns.'
                     -- الترتيب بالأسماء المستعارة: MAX() مكرراً في ORDER BY داخل كتلة مجمّعة
                     -- ملفوفة بـROWNUM يرمي ORA-00935
                     -- Order by the aliases: repeating MAX(...) in the ORDER BY of a grouped
                     -- block wrapped by ROWNUM raises ORA-00935.
                     ORDER BY ITEM_NAME, INV_ID
                 ) WHERE ROWNUM <= :limit
                   ) q',
                $binds
            );

            // التطبيع نفسه: الرصيدان السابق والحالي يُقرآن NET_QTY ومفاتيح التجميع من الصف،
            // ومفاتيح صغيرة تعني null فيظهر العمودان صفراً بلا خطأ ظاهر.
            // The same normalisation: both balances read NET_QTY and the grouping keys off the
            // row, and lowercase keys would silently turn them into zeros.
            $clean = array_map(
                static fn ($row): array => ArabicEncoding::cleanData(
                    array_change_key_case((array) $row, CASE_UPPER)
                ),
                $rows
            );

            $openings = $this->openingByGroups(
                '('.implode(') AND (', $outerWhere ?: ['1 = 1']).')',
                $outerBinds,
                $from,
                $tokens
            );

            $clean = $this->stampSummary($clean, $tokens, $openings);

            return response()->json([
                'success' => true,
                'data' => $clean,
                'meta' => $this->summaryMeta($clean, $groupBy, $tokens, $from, $to, $limit),
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب خلاصة الحركة / Failed to retrieve the movement summary.', $e);
        }
    }

    /**
     * الأعمدة الممكنة للتجميع — قائمة بيضاء وحيدة تصل إلى SQL
     * The columns that may be grouped by — the only whitelist that reaches the SQL.
     */
    private const GROUP_COLUMNS = [
        'item' => 'I_CODE',
        'sub' => 'P_CODE',
        'store' => 'INV_ID',
        'subgroup' => 'TYPE_NO',
        'group' => 'GROUPNO',
        'category' => 'CAT_NAME',
    ];

    /**
     * الحقل الذي يحمل الصف قيمته لكل رمز تجميع
     * The field a row carries each token's value in.
     */
    private const GROUP_ROW_FIELDS = [
        'item' => 'I_CODE',
        'sub' => 'P_CODE',
        'store' => 'INV_ID',
        'subgroup' => 'ITEM_TYPE_NO',
        'group' => 'GROUPNO',
        'category' => 'CAT_NAME',
    ];

    /**
     * تفكيك رمز التجميع المركّب إلى قائمة بيضاء فقط
     * Split a composite grouping into whitelisted tokens only.
     *
     * @return array<int, string>
     */
    private function groupTokens(string $groupBy): array
    {
        $tokens = [];

        foreach (explode('*', $groupBy) as $raw) {
            $token = strtolower(trim($raw));

            // رمز مجهول يُهمل لا يُمرَّر: «مجموعة فرعية*نص عشوائي» لا يجوز أن يصبح عموداً
            // An unknown token is dropped, never passed through: a typo must not become a column.
            if (isset(self::GROUP_COLUMNS[$token])) {
                $tokens[$token] = $token;
            }
        }

        return array_values($tokens);
    }

    /**
     * رصيد ما قبل الفترة لكل مجموعة مفتاحية
     * The pre-period balance for each composite group key.
     *
     * @param  array<int, string>  $tokens
     * @return array<string, float>
     */
    private function openingByGroups(
        string $scopeSql,
        array $binds,
        string $from,
        array $tokens
    ): array {
        $columns = array_map(static fn (string $t): string => 'm.'.self::GROUP_COLUMNS[$t], $tokens);

        $select = [];

        foreach ($tokens as $i => $token) {
            $select[] = $columns[$i].' AS GK'.$i;
        }

        $rows = DB::connection('oracle')->select(
            'SELECT '.implode(', ', $select).', NVL(SUM(m.P_QTY), 0) AS OPEN_QTY
              FROM ACCOUNTS.ITEM_MOVEMENT_D m
             WHERE '.$scopeSql.'
               AND m.I_DATE < TO_DATE(:open_from, \'YYYY-MM-DD\')
             GROUP BY '.implode(', ', $columns),
            $binds + ['open_from' => $from]
        );

        $openings = [];

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            $parts = [];

            foreach (array_keys($tokens) as $i) {
                $parts[] = (string) ($row['GK'.$i] ?? '');
            }

            $openings[implode('|', $parts)] = (float) ($row['OPEN_QTY'] ?? 0);
        }

        return $openings;
    }

    /**
     * إضافة الرصيد السابق والحالي لكل سطر خلاصة
     * Attach the opening and closing balance to every summary row.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $tokens
     * @param  array<string, float>  $openings
     * @return array<int, array<string, mixed>>
     */
    private function stampSummary(array $rows, array $tokens, array $openings): array
    {
        foreach ($rows as $index => $row) {
            $parts = [];

            foreach ($tokens as $token) {
                $parts[] = (string) ($row[self::GROUP_ROW_FIELDS[$token]] ?? '');
            }

            $opening = (float) ($openings[implode('|', $parts)] ?? 0);
            $net = (float) ($row['NET_QTY'] ?? 0);

            $rows[$index]['OPENING_QTY'] = round($opening, 4);
            $rows[$index]['CLOSING_QTY'] = round($opening + $net, 4);
        }

        return $rows;
    }

    /**
     * إحصاءات الخلاصة — السابق والوارد والمنصرف والصافي والحالي كمية وقيمة
     * The summary statistics: opening, inbound, outbound, net and closing, quantity and value.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $tokens
     * @return array<string, mixed>
     */
    private function summaryMeta(
        array $rows,
        string $groupBy,
        array $tokens,
        string $from,
        string $to,
        int $limit
    ): array {
        $sum = static fn (string $key): float => array_sum(array_map(
            static fn (array $r): float => (float) ($r[$key] ?? 0),
            $rows
        ));

        $totals = [
            'rows' => count($rows),
            'opening_qty' => round($sum('OPENING_QTY'), 4),
            'qty_in' => round($sum('QTY_IN'), 4),
            'qty_in_value' => round($sum('IN_VALUE'), 2),
            'qty_out' => round($sum('QTY_OUT'), 4),
            'qty_out_value' => round($sum('OUT_VALUE'), 2),
            'net_qty' => round($sum('NET_QTY'), 4),
            'net_value' => round($sum('NET_VALUE'), 2),
            'closing_qty' => round($sum('CLOSING_QTY'), 4),
            'closing_value' => round($sum('NET_VALUE'), 2),
        ];

        return [
            'total' => count($rows),
            'truncated' => count($rows) >= $limit,
            'from' => $from,
            'to' => $to,
            'kind' => 'summary',
            'group_by' => $groupBy,
            'group_tokens' => $tokens,
            'totals' => $totals,
            'qty_in' => $totals['qty_in'],
            'qty_out' => $totals['qty_out'],
            'value' => $totals['net_value'],
        ];
    }

    /**
     * تسجيل حدث تصدير/طباعة — لا يمسّ أي جدول مخزني
     * Record an export/print event — touches no inventory table.
     */
    public function exportLog(Request $request): JsonResponse
    {
        $action = (string) ($request->input('action') ?? 'export');
        $code = $action === 'print' ? 'stores.reports_printed' : 'stores.reports_exported';

        $this->activity->success($code, [
            'module_code' => 'stores',
            'entity_type' => 'report',
            'entity_id' => (string) ($request->input('report') ?? 'stores'),
            'description_ar' => 'تصدير/طباعة تقرير مخازن: '
                .(string) ($request->input('report') ?? '')
                .' — من '.(string) ($request->input('from') ?? '-')
                .' إلى '.(string) ($request->input('to') ?? '-'),
        ], $request);

        return response()->json(['success' => true]);
    }

    /**
     * تطبيق نطاق التصفية المشترك على استعلام الحركة
     * Apply the shared report scope to a movement query.
     *
     * @param  array<int, string>  $where
     * @param  array<string, mixed>  $binds
     */
    private function applyScope(Request $request, array &$where, array &$binds): void
    {
        $storeNos = $this->scopeList($request, 'store_nos', 'store_no');

        if ($storeNos !== []) {
            $where[] = $this->inClause('m.INV_ID', $storeNos, $binds, 'st');
        }

        // ── المجموعات والفرعيات قوائم متعددة الآن: تقرير «كل الأقمشة بكل فروعها» أو
        //    «ثلاث مجموعات مرة واحدة» طلب عملي لا رفاهية، والمفردة القديمة تبقى مدعومة.
        // ── Groups and sub groups are multi-valued now: reporting on three groups at once is a
        //    working need, not a luxury. The legacy single value keeps working.
        $groupNos = $this->scopeList($request, 'group_nos', 'group_no');

        if ($groupNos !== []) {
            $where[] = $this->inClause('m.GROUPNO', $groupNos, $binds, 'gr');
        }

        $itemTypeNos = $this->scopeList($request, 'item_type_nos', 'type_no');

        if ($itemTypeNos !== []) {
            $where[] = $this->inClause('m.TYPE_NO', $itemTypeNos, $binds, 'it');
        }

        if (($sanfNo = $this->intOrNull($request->input('sanf_no'))) !== null) {
            $where[] = 'm.I_CODE = :sanf_no';
            $binds['sanf_no'] = $sanfNo;
        }

        if (($sanfDno = $this->intOrNull($request->input('sanf_dno'))) !== null) {
            $where[] = 'm.P_CODE = :sanf_dno';
            $binds['sanf_dno'] = $sanfDno;
        }

        if (($catNo = $this->intOrNull($request->input('cat_no'))) !== null) {
            // الفئة تُقرأ من جدول الأصناف لأن الـ VIEW تحمل اسم الفئة نصاً لا رقمها
            // The category is resolved through SANFS because the view carries its name only.
            $where[] = 'm.I_CODE IN (SELECT SANF_NO FROM ACCOUNTS.SANFS WHERE CAT_NO = :cat_no)';
            $binds['cat_no'] = $catNo;
        }

        $typeNos = $this->intList($request->input('type_nos'));

        if ($typeNos !== []) {
            // نوع الحركة هو `BILL_DOC_TYPE` (typeno للـ TRANS_TYPE) وليس `TYPE_NO` الذي يحمل
            // نوع الصنف الفرعي؛ خلطهما كان يجعل منتَقي «نوع الحركة» يرشّح المجموعة الفرعية،
            // فيعود تقرير فارغ أو ناقص بلا سبب ظاهر للمستخدم.
            // The movement type is BILL_DOC_TYPE (the TRANS_TYPE typeno), not TYPE_NO which
            // carries the item's sub group. Mixing them made the movement-type picker filter
            // the sub group, returning an empty or partial report for no visible reason.
            $where[] = $this->inClause('m.BILL_DOC_TYPE', $typeNos, $binds, 'tn');
        }
    }

    /**
     * قائمة المعرّفات من وسيط plural مع رجوع إلى المفردة القديمة
     * The id list from the plural parameter, falling back to the legacy singular one.
     *
     * ── «لاشي» قيمة صريحة في الواجهة، وتصل كفراغ أو كـ`all`: كلاهما يعني بلا قيد، فلا
     *    يُرسل رقم وهمي يُرشّح على لا شيء.
     * ── "None" is an explicit UI choice that arrives as empty or as `all`; both mean
     *    unconstrained, so no phantom id is ever sent to filter on nothing.
     *
     * @return array<int, int>
     */
    private function scopeList(Request $request, string $plural, string $singular): array
    {
        $values = $this->intList($request->input($plural));

        if ($values !== []) {
            return $values;
        }

        $single = $this->intOrNull($request->input($singular));

        return $single === null ? [] : [$single];
    }

    /**
     * بناء شرط IN بروابط مسمّاة فريدة — لا أرقام داخل النص أبداً
     * Build an IN clause with uniquely named binds — never numbers inside the text.
     *
     * @param  array<int, int>  $values
     */
    private function inClause(string $column, array $values, array &$binds, string $prefix): string
    {
        $placeholders = [];

        foreach (array_values($values) as $i => $value) {
            $binds[$prefix.$i] = $value;
            $placeholders[] = ':'.$prefix.$i;
        }

        return $column.' IN ('.implode(', ', $placeholders).')';
    }

    /**
     * المدى الزمني: من/إلى صالحان، وأقصاهما سنتان، والافتراضي آخر 30 يوماً
     * The date range: valid bounds, two years max, 30 days by default.
     *
     * @return array{0: string, 1: string}|JsonResponse
     */
    private function resolveRange(Request $request): array|JsonResponse
    {
        $from = $this->dateOrNull($request->input('from'));
        $to = $this->dateOrNull($request->input('to'));

        if ($from === null || $to === null) {
            $row = array_change_key_case((array) DB::connection('oracle')->selectOne(
                "SELECT TO_CHAR(SYSDATE - 30, 'YYYY-MM-DD') AS F, TO_CHAR(SYSDATE, 'YYYY-MM-DD') AS T FROM DUAL"
            ), CASE_UPPER);

            $from ??= (string) ($row['F'] ?? '');
            $to ??= (string) ($row['T'] ?? '');
        }

        if ($from > $to) {
            return response()->json([
                'success' => false,
                'message' => 'تاريخ البداية بعد تاريخ النهاية / The start date is after the end date.',
                'errors' => ['from' => ['تاريخ البداية بعد تاريخ النهاية / Start date is after the end date.']],
            ], 422);
        }

        $days = (int) round((strtotime($to) - strtotime($from)) / 86400);

        if ($days > StorePolicy::MAX_REPORT_DAYS) {
            return response()->json([
                'success' => false,
                'message' => 'المدى الزمني أكبر من الحد المسموح ('.StorePolicy::MAX_REPORT_DAYS.' يوماً) '
                    .'/ The range exceeds the allowed span of '.StorePolicy::MAX_REPORT_DAYS.' days.',
                'errors' => ['to' => ['المدى الزمني أكبر من الحد المسموح / The range is too wide.']],
            ], 422);
        }

        return [$from, $to];
    }

    /**
     * تحويل قائمة نصية أو مصفوفة إلى أرقام صحيحة فريدة
     * Turn a comma-separated string or an array into unique integers.
     *
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);
        $numbers = [];

        foreach ($items as $item) {
            if (is_numeric($item)) {
                $numbers[] = (int) $item;
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * تنظيف مجموعة صفوف مع وسم المخازن الأرشيفية
     * Clean a row set and flag archived stores.
     *
     * @param  array<int, mixed>  $rows
     * @param  array<int, int>  $excluded
     * @return array<int, array<string, mixed>>
     */
    private function clean(array $rows, array $excluded = []): array
    {
        return array_map(static function ($row) use ($excluded): array {
            // ── تطبيع أسماء الأعمدة إلى أحرف كبيرة قبل أي قراءة: منتقي OCI8 يعيدها صغيرة
            //    (`group_no`)، فكان العميل يقرأ `GROUP_NO` فلا يجدها فيرى كل مجموعة = 0، وكل
            //    مجموعة فرعية = 0، ثم يطلب أصناف المجموعة 0 فيعود فارغاً. وهو نفسه سبب عدم
            //    وسم المخازن الأرشيفية: `isset($item['STORE_NO'])` كان يفشل دائماً.
            // ── Normalise column names to upper case before any read: the OCI8 driver returns
            //    them lower (`group_no`), so the client looking for `GROUP_NO` saw every group as
            //    0 and asked for items of group 0 — an empty list. The same failure kept archived
            //    stores unmarked, because `isset($item['STORE_NO'])` never matched.
            $item = ArabicEncoding::cleanData(
                array_change_key_case((array) $row, CASE_UPPER)
            );

            if (isset($item['STORE_NO'])) {
                $item['ARCHIVED'] = in_array((int) $item['STORE_NO'], $excluded, true) ? 1 : 0;
            }

            return $item;
        }, $rows);
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' || $value === 'all' ? null : (int) $value;
    }

    private function failure(string $message, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
