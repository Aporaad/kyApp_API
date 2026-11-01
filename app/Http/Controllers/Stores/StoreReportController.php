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
                    'excluded_stores' => $excluded,
                    'max_range_days' => StorePolicy::MAX_REPORT_DAYS,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب المراجع / Failed to retrieve reference data.', $e);
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

        $storeNos = $this->storeList($request);

        if ($storeNos !== []) {
            $placeholders = [];

            foreach ($storeNos as $i => $storeNo) {
                $binds['st'.$i] = $storeNo;
                $placeholders[] = ':st'.$i;
            }

            $where[] = 'INV_ID IN ('.implode(',', $placeholders).')';
        }

        $search = trim((string) ($request->input('search') ?? ''));

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);
            $where[] = '(ITEM_NAME LIKE :name_ar OR ITEM_NAME LIKE :name_utf OR PART_NO LIKE :part)';
            $binds['name_ar'] = '%'.$oracle.'%';
            $binds['name_utf'] = '%'.$search.'%';
            $binds['part'] = '%'.$search.'%';
        }

        if (($groupNo = $this->intOrNull($request->input('group_no'))) !== null) {
            $where[] = 'GROUPNO = :group_no';
            $binds['group_no'] = $groupNo;
        }

        if (($typeNo = $this->intOrNull($request->input('type_no'))) !== null) {
            $where[] = 'TYPE_NO = :type_no';
            $binds['type_no'] = $typeNo;
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

        $limit = max(1, min((int) ($request->input('limit') ?? 2000), self::MAX_LIMIT));
        $binds['limit'] = $limit;
        $whereSql = implode(' AND ', $where);

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT m.I_DATE, m.INVOIC_ID AS DOC_NO, m.DOC_NO AS MANUAL_BILL_NO, m.DOC_TYPE,
                           m.BILL_DOC_TYPE AS TYPE_NO, m.TR_INV_TYPE_NAME, m.INV_ID, m.INV_NAME,
                           m.I_CODE, m.P_CODE, m.IT_NAME, m.PART_NO, m.UOM_CODE,
                           m.GROUPNO, m.GROUP_NAME, m.TYPE_NO AS ITEM_TYPE_NO, m.TYPE_NAME, m.CAT_NAME,
                           CASE WHEN m.P_QTY > 0 THEN m.P_QTY END AS QTY_IN,
                           CASE WHEN m.P_QTY < 0 THEN -m.P_QTY END AS QTY_OUT,
                           m.P_QTY, m.STK_COST, ROUND(m.P_QTY * m.STK_COST, 2) AS LINE_VALUE
                      FROM ACCOUNTS.ITEM_MOVEMENT_D m
                     WHERE '.$whereSql.'
                     ORDER BY m.I_DATE, m.INVOIC_ID, m.I_CODE
                 ) WHERE ROWNUM <= :limit',
                $binds
            );

            $clean = array_map(
                static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                $rows
            );

            return response()->json([
                'success' => true,
                'data' => $clean,
                'meta' => [
                    'total' => count($clean),
                    'truncated' => count($clean) >= $limit,
                    'from' => $from,
                    'to' => $to,
                    'kind' => 'movement',
                    'qty_in' => array_sum(array_map(static fn (array $r): float => (float) ($r['QTY_IN'] ?? 0), $clean)),
                    'qty_out' => array_sum(array_map(static fn (array $r): float => (float) ($r['QTY_OUT'] ?? 0), $clean)),
                    'value' => array_sum(array_map(static fn (array $r): float => (float) ($r['LINE_VALUE'] ?? 0), $clean)),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب حركة المخزون / Failed to retrieve stock movement.', $e);
        }
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

        // التجميع بالمخزن والصنف ليبقى مطابقاً لمعنى الرصيد المعروض
        // Grouping by store and item keeps the meaning identical to the displayed balance.
        try {
            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT m.I_CODE, MAX(m.PART_NO) AS PART_NO, MAX(m.IT_NAME) AS ITEM_NAME,
                           MAX(m.UOM_CODE) AS UOM_CODE, m.INV_ID, MAX(m.INV_NAME) AS INV_NAME,
                           MAX(m.GROUPNO) AS GROUPNO, MAX(m.GROUP_NAME) AS GROUP_NAME,
                           MAX(m.TYPE_NAME) AS TYPE_NAME,
                           SUM(CASE WHEN m.P_QTY > 0 THEN m.P_QTY ELSE 0 END) AS QTY_IN,
                           SUM(CASE WHEN m.P_QTY < 0 THEN -m.P_QTY ELSE 0 END) AS QTY_OUT,
                           SUM(m.P_QTY) AS NET_QTY,
                           MAX(m.STK_COST) AS COST,
                           ROUND(SUM(m.P_QTY * m.STK_COST), 2) AS NET_VALUE,
                           COUNT(*) AS ROWS_COUNT
                      FROM ACCOUNTS.ITEM_MOVEMENT_D m
                     WHERE '.$whereSql.'
                     GROUP BY m.I_CODE, m.INV_ID
                     ORDER BY MAX(m.IT_NAME), m.INV_ID
                 ) WHERE ROWNUM <= :limit',
                $binds
            );

            $clean = array_map(
                static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                $rows
            );

            return response()->json([
                'success' => true,
                'data' => $clean,
                'meta' => [
                    'total' => count($clean),
                    'truncated' => count($clean) >= $limit,
                    'from' => $from,
                    'to' => $to,
                    'kind' => 'summary',
                    'qty_in' => array_sum(array_map(static fn (array $r): float => (float) ($r['QTY_IN'] ?? 0), $clean)),
                    'qty_out' => array_sum(array_map(static fn (array $r): float => (float) ($r['QTY_OUT'] ?? 0), $clean)),
                    'value' => array_sum(array_map(static fn (array $r): float => (float) ($r['NET_VALUE'] ?? 0), $clean)),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب خلاصة الحركة / Failed to retrieve the movement summary.', $e);
        }
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
        $storeNos = $this->storeList($request);

        if ($storeNos !== []) {
            $placeholders = [];

            foreach ($storeNos as $i => $storeNo) {
                $binds['st'.$i] = $storeNo;
                $placeholders[] = ':st'.$i;
            }

            $where[] = 'm.INV_ID IN ('.implode(',', $placeholders).')';
        }

        if (($groupNo = $this->intOrNull($request->input('group_no'))) !== null) {
            $where[] = 'm.GROUPNO = :group_no';
            $binds['group_no'] = $groupNo;
        }

        if (($typeNo = $this->intOrNull($request->input('type_no'))) !== null) {
            $where[] = 'm.TYPE_NO = :type_no';
            $binds['type_no'] = $typeNo;
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
            $placeholders = [];

            foreach ($typeNos as $i => $typeno) {
                $binds['tn'.$i] = $typeno;
                $placeholders[] = ':tn'.$i;
            }

            // نوع الحركة هو `BILL_DOC_TYPE` (typeno للـ TRANS_TYPE) وليس `TYPE_NO` الذي يحمل
            // نوع الصنف الفرعي؛ خلطهما كان يجعل منتَقي «نوع الحركة» يرشّح المجموعة الفرعية،
            // فيعود تقرير فارغ أو ناقص بلا سبب ظاهر للمستخدم.
            // The movement type is BILL_DOC_TYPE (the TRANS_TYPE typeno), not TYPE_NO which
            // carries the item's sub group. Mixing them made the movement-type picker filter
            // the sub group, returning an empty or partial report for no visible reason.
            $where[] = 'm.BILL_DOC_TYPE IN ('.implode(', ', $placeholders).')';
        }
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
     * قائمة المخازن المطلوبة: الكل (فارغة) أو أرقام مفصولة بفواصل
     * The requested stores: empty means all, otherwise comma-separated numbers.
     *
     * @return array<int, int>
     */
    private function storeList(Request $request): array
    {
        $raw = $request->input('store_no');

        if ($raw === null || $raw === '' || $raw === 'all') {
            return [];
        }

        return $this->intList($raw);
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
            $item = ArabicEncoding::cleanData((array) $row);

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
