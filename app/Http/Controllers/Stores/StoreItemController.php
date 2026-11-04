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
 * الأصناف — الرئيسي (SANFS) والفرعي (SANFS_D) ووحداته وأسعاره (PARTIAL_SANFS)
 * Items — the main item, its sub items, and its units/prices.
 *
 * ── لماذا ثلاثتها في متحكم واحد؟ لأن الصنف لا يعمل في المخازن بلا صنف فرعي: الـ VIEW
 *    `ITEM_MOVE_D` تربط سطور المستندات بـ `SANFS_D.SANF_DNO`، فصنف بلا فرعي لا يمكن
 *    توريده ولا صرفه. لذلك الإنشاء يكتب الثلاثة في معاملة واحدة.
 * ── Why one controller for all three? An item is unusable in this schema without a sub
 *    item: the movement view joins document lines on SANFS_D.SANF_DNO. Creation therefore
 *    writes all three inside a single transaction.
 *
 * ── قيود تفرّد حقيقية في المخطط: SANF_NAME و PART_NO فريدان على مستوى الجدول كله،
 *    وانتهاكهما يعطي ORA-00001 غير مفهوم للمستخدم — لذا يُفحصان قبل الإدراج برسالة عربية.
 * ── Real unique constraints: SANF_NAME and PART_NO are unique across the whole table.
 *    Violating them yields an opaque ORA-00001, so both are pre-checked with Arabic messages.
 */
class StoreItemController extends Controller
{
    private const MAX_LIMIT = 200;

    private const DEFAULT_LIMIT = 50;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * قائمة الأصناف الرئيسية مع بحث وفرز وتصفية وأرصدة مجمّعة
     * Main items with search, sorting, filters and aggregated stock.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        $where = ['1 = 1'];
        $binds = [];

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);

            if (is_numeric($search)) {
                $where[] = '(s.SANF_NO = :sanf_no OR s.PART_NO LIKE :part_like)';
                $binds['sanf_no'] = (int) $search;
                $binds['part_like'] = '%'.$search.'%';
            } else {
                $where[] = '(s.SANF_NAME LIKE :name_ar OR s.SANF_NAME LIKE :name_utf
                             OR s.PART_NO LIKE :part_like2 OR s.SANF_CODE LIKE :code_like)';
                $binds['name_ar'] = '%'.$oracle.'%';
                $binds['name_utf'] = '%'.$search.'%';
                $binds['part_like2'] = '%'.$search.'%';
                $binds['code_like'] = '%'.$search.'%';
            }
        }

        foreach ([
            'group_no' => 's.GROUPNO',
            'type_no' => 's.TYPE_NO',
            'cat_no' => 's.CAT_NO',
            'unit_no' => 's.UNIT_NO',
        ] as $input => $column) {
            $value = $request->input($input);

            if ($value !== null && $value !== '' && $value !== 'all') {
                $where[] = $column.' = :'.$input;
                $binds[$input] = (int) $value;
            }
        }

        $active = $request->input('active');

        if ($active !== null && $active !== '' && $active !== 'all') {
            $where[] = 'NVL(s.ACTIVE, 1) = :active';
            $binds['active'] = (int) $active;
        }

        $sort = (string) ($request->input('sort') ?? 'name');
        $dir = strtolower((string) ($request->input('dir') ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $orderBy = match ($sort) {
            'no' => 's.SANF_NO',
            'part' => 's.PART_NO',
            'qty' => 'NVL(mv.QTY, 0)',
            'value' => 'NVL(mv.VALUE, 0)',
            default => 's.SANF_NAME',
        };

        $whereSql = implode(' AND ', $where);

        // ── رصيد الصنف = مجموع أرصدة فروعه، كما حدّد المستخدم. المصدر `ITEM_MOVEMENT_D` مجمَّعاً
        //    لكل فرع (`P_CODE`) ثم ملفوفاً بمجموع الأصناف الفرعية لكل صنف أب.
        // ── لا `AVVG` هنا: مقاسٌ، تجميعها الكامل يكلّف 14,589 مللي مقابل 1,552 مللي لتجميع
        //    الحركة بالفرع — نفس الأرقام (كلاهما مشتق من الحركة) بسُبع الزمن.
        // ── An item's balance is the sum of its branches, exactly as specified. The source is
        //    ITEM_MOVEMENT_D aggregated per branch (P_CODE), then rolled up by parent item.
        //    Not AVVG: measured 14,589ms against 1,552ms for the branch aggregate — same numbers
        //    (both derive from movement) at a seventh of the time.
        // ── التجميع يبدأ من قائمة فروعه **المميزة** (`DISTINCT`) لا من الجدول مباشرة: لو تكرّر
        //    سطر الفرع في `SANFS_D` لتكرّر رصيده في المجموع وقرأ المستخدم رقماً مضاعفاً.
        //   DISTINCT يجعل الرصيد مناعةً لا اعتماداً على نظافة البيانات.
        //    The rollup starts from the item's *distinct* branches, not the table: a repeated
        //    SANFS_D row would otherwise double that branch's quantity and the reader would see
        //    an inflated balance. DISTINCT makes the figure immune rather than dependent on clean data.
        $stockJoin = 'LEFT JOIN (
                          SELECT dd.SANF_NO,
                                 SUM(b.QTY) AS QTY,
                                 SUM(b.VALUE) AS VALUE,
                                 MAX(b.COST) AS COST
                            FROM (SELECT DISTINCT SANF_NO, SANF_DNO FROM ACCOUNTS.SANFS_D) dd
                            JOIN (
                                  SELECT P_CODE,
                                         SUM(P_QTY) AS QTY,
                                         SUM(P_QTY * STK_COST) AS VALUE,
                                         MAX(STK_COST) AS COST
                                    FROM ACCOUNTS.ITEM_MOVEMENT_D
                                   GROUP BY P_CODE
                            ) b ON b.P_CODE = dd.SANF_DNO
                           GROUP BY dd.SANF_NO
                      ) mv ON mv.SANF_NO = s.SANF_NO';

        // ── عدد الأصناف الفرعية وتسمية الفئة كتجميعين مفردين لا كاستعلامين مترابطين لكل صف:
        //    النسخة المترابطة تُنفَّذ مرة لكل صف معروض (٥٠ مرة في الصفحة) وتلك كانت السبب في
        //    تجاوز مهلة العميل (١٥ ثانية) فتُفتح الشاشة فارغة. التجميع يمرّ على `SANFS_D` مرة.
        // ── The sub-item count and the category label are single grouped joins, not per-row
        //    correlated sub-queries: the correlated form ran once per visible row (50 times per
        //    page) and that is what exceeded the client's 15s timeout and left the screen empty.
        //    A grouped join walks SANFS_D exactly once.
        $subsJoin = 'LEFT JOIN (
                         SELECT SANF_NO, COUNT(DISTINCT SANF_DNO) AS SUBS
                           FROM ACCOUNTS.SANFS_D GROUP BY SANF_NO
                     ) sd ON sd.SANF_NO = s.SANF_NO';
        // تجميع الفئة يحفظ دلالة `MAX(CATEGORY)` الأصلية ويمنع تكرار الصف لو تكرر `CAT_NO`
        // Grouping keeps the original MAX(CATEGORY) semantics and cannot duplicate a row when
        // CAT_NO repeats.
        $categoryJoin = 'LEFT JOIN (
                             SELECT CAT_NO, MAX(CATEGORY) AS CATEGORY
                               FROM ACCOUNTS.CATEGORY GROUP BY CAT_NO
                         ) c ON c.CAT_NO = s.CAT_NO';

        try {
            $countRow = (array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.SANFS s WHERE '.$whereSql,
                $binds
            );
            $total = (int) (reset($countRow) ?: 0);

            $pagedBinds = $binds;
            $pagedBinds['offset'] = $offset;
            $pagedBinds['offset2'] = $offset;
            $pagedBinds['limit'] = $limit;

            $sql = 'SELECT * FROM (
                        SELECT q.*, ROWNUM AS RN FROM (
                            SELECT s.SANF_NO, s.PART_NO, s.SANF_NAME, s.SANF_CODE,
                                   s.GROUPNO, g.GROUP_NAME, s.TYPE_NO, t.TYPE_NAME,
                                   s.CAT_NO, c.CATEGORY AS CATEGORY_NAME,
                                   s.UNIT_NO, u.UNIT_NAME, s.SANF_PRICE, s.BUY_PRICE,
                                   s.ACTIVE, s.STYL_NO, st.STYLE_NAME,
                                   NVL(sd.SUBS, 0) AS SUBS,
                                   NVL(mv.QTY, 0) AS QTY,
                                   NVL(mv.VALUE, 0) AS VALUE,
                                   NVL(mv.COST, NVL(s.SANF_PRICE, 0)) AS COST
                              FROM ACCOUNTS.SANFS s
                              LEFT JOIN ACCOUNTS.GROUP1 g ON g.GROUP_NO = s.GROUPNO
                              LEFT JOIN ACCOUNTS.TYPES t ON t.TYPE_NO = s.TYPE_NO
                              LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = s.UNIT_NO
                              LEFT JOIN ACCOUNTS.STYLE st ON st.SHAPE_STYLE_NO = s.STYL_NO
                              '.$subsJoin.'
                              '.$categoryJoin.'
                              '.$stockJoin.'
                             WHERE '.$whereSql.'
                             ORDER BY '.$orderBy.' '.strtoupper($dir).', s.SANF_NO
                        ) q WHERE ROWNUM <= :offset2 + :limit
                    ) WHERE RN > :offset';

            $rows = DB::connection('oracle')->select($sql, $pagedBinds);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الأصناف / Items retrieved.',
                'data' => array_map(
                    static fn ($row): array => ArabicEncoding::cleanData(array_change_key_case((array) $row, CASE_UPPER)),
                    $rows
                ),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب الأصناف / Failed to retrieve items.', $e);
        }
    }

    /**
     * تفصيل صنف: بياناته + أصنافه الفرعية بكمياتها + وحداته وأسعاره
     * Item detail: header data, sub items with their quantities, and units/prices.
     *
     * ── لا `AVVG` هنا عمداً: استعلامها يكلّف ~١٣ ثانية مهما ضاق نطاقه (تُجمّع تاريخ الحركة
     *    كاملاً قبل الفلترة). وكمية الصنف الإجمالية تُشتق من كميات فروعه، وهي **نفس المصدر**
     *    الذي تُبنى منه `AVVG` نفسها (`ITEM_MOVEMENT_D`)، فلا فقد في الدقة وكسب زمني كامل.
     * ── No AVVG here on purpose: a query against it costs ~13s however narrow (it aggregates
     *    the entire movement history before filtering). The item's total quantity is derived
     *    from its branches — the *same* source AVVG is built from (ITEM_MOVEMENT_D) — so no
     *    accuracy is lost and all of the time is.
     */
    public function show(int $sanfNo): JsonResponse
    {
        try {
            $item = $this->fetchItem($sanfNo);

            if ($item === null) {
                return $this->notFound($sanfNo);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'item' => ArabicEncoding::cleanData($item),
                    'subs' => $this->fetchSubs($sanfNo),
                    'units' => $this->fetchUnits($sanfNo),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب الصنف / Failed to retrieve the item.', $e);
        }
    }

    /**
     * إنشاء صنف كامل: رئيسي + أول صنف فرعي + أول وحدة وسعر
     * Create a complete item: main + first sub item + first unit/price.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'part_no' => ['required', 'string', 'max:30'],
            'sanf_name' => ['required', 'string', 'max:100'],
            'sanf_price' => ['required', 'numeric', 'min:0'],
            'buy_price' => ['nullable', 'numeric', 'min:0'],
            'unit_no' => ['required', 'integer', 'min:1'],
            'group_no' => ['required', 'integer', 'min:1'],
            'type_no' => ['required', 'integer', 'min:1'],
            'cat_no' => ['nullable', 'integer'],
            'styl_no' => ['required', 'integer', 'min:1'],
            'sanf_code' => ['nullable', 'string', 'max:30'],
            'mcolor' => ['nullable', 'string', 'max:20'],
            'cap' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:30'],
            'len' => ['nullable', 'numeric'],
            'wid' => ['nullable', 'numeric'],
            'color_no' => ['nullable', 'integer'],
        ], [
            'part_no.required' => 'رقم الصنف (كوده) مطلوب / The item part number is required.',
            'sanf_name.required' => 'اسم الصنف مطلوب / The item name is required.',
            'unit_no.required' => 'الوحدة مطلوبة / A unit is required.',
            'group_no.required' => 'المجموعة الرئيسية مطلوبة / The main group is required.',
            'type_no.required' => 'المجموعة الفرعية مطلوبة / The sub group is required.',
            'styl_no.required' => 'النمط مطلوب — بدونه لا يظهر الصنف في حركات المخزن '
                .'/ The style is required: without it the item never appears in stock movement.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الصنف غير صالحة / The item payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = trim((string) $request->input('sanf_name'));
        $partNo = trim((string) $request->input('part_no'));

        $conflict = $this->nameOrPartConflict($name, $partNo);

        if ($conflict !== null) {
            return $conflict;
        }

        if (! $this->styleExists((int) $request->input('styl_no'))) {
            return response()->json([
                'success' => false,
                'message' => 'النمط المحدد غير موجود / The selected style does not exist.',
                'errors' => ['styl_no' => ['النمط المحدد غير موجود / The selected style does not exist.']],
            ], 422);
        }

        try {
            $sanfNo = DB::connection('oracle')->transaction(function () use ($request, $name, $partNo): int {
                $sanfNo = StorePolicy::nextNumber('SEQ_STORES_ITEM');
                $oracleName = $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 100);

                DB::connection('oracle')->insert(
                    'INSERT INTO ACCOUNTS.SANFS
                        (SANF_NO, TYPE_NO, PART_NO, SANF_NAME, SANF_PRICE, UNIT_NO, GROUPNO,
                         STYL_NO, SANF_CODE, MCOLOR, CAP, BUY_PRICE, CAT_NO, SANF_TYPE,
                         ACTIVE, CREATED_ON)
                     VALUES
                        (:sanf_no, :type_no, :part_no, :sanf_name, :sanf_price, :unit_no, :group_no,
                         :styl_no, :sanf_code, :mcolor, :cap, :buy_price, :cat_no, 1,
                         1, SYSDATE)',
                    [
                        'sanf_no' => $sanfNo,
                        'type_no' => (int) $request->input('type_no'),
                        'part_no' => $partNo,
                        'sanf_name' => $oracleName,
                        'sanf_price' => (float) $request->input('sanf_price'),
                        'unit_no' => (int) $request->input('unit_no'),
                        'group_no' => (int) $request->input('group_no'),
                        'styl_no' => (int) $request->input('styl_no'),
                        'sanf_code' => $this->clip((string) ($request->input('sanf_code') ?? ''), 30),
                        'mcolor' => $this->clip($this->arabicOrNull($request->input('mcolor')), 20),
                        // CAP في SANFS نصّي (VARCHAR2) خلافاً لعمود المستندات الرقمي
                        // CAP is text in SANFS while the document column is numeric.
                        'cap' => (string) (float) ($request->input('cap') ?? 1),
                        'buy_price' => (float) ($request->input('buy_price') ?? 0),
                        'cat_no' => $request->input('cat_no') !== null ? (int) $request->input('cat_no') : null,
                    ]
                );

                $this->insertSub($sanfNo, [
                    'len' => (float) ($request->input('len') ?? 0),
                    'wid' => (float) ($request->input('wid') ?? 0),
                    'color_no' => (int) ($request->input('color_no') ?? 0),
                    'barcode' => (string) ($request->input('barcode') ?? ''),
                    'price' => (float) $request->input('sanf_price'),
                ], [
                    'name' => $oracleName,
                    'part_no' => $partNo,
                    'type_no' => (int) $request->input('type_no'),
                    'styl_no' => (int) $request->input('styl_no'),
                    'unit_no' => (int) $request->input('unit_no'),
                ]);

                $this->insertUnit($sanfNo, (int) $request->input('unit_no'), 1.0, (float) $request->input('sanf_price'));

                return $sanfNo;
            });

            $this->activity->success('stores.items_created', [
                'module_code' => 'stores',
                'entity_type' => 'item',
                'entity_id' => (string) $sanfNo,
                'description_ar' => 'إضافة صنف رقم '.$sanfNo.' باسم '.$name.' — الكود '.$partNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة الصنف بنجاح / The item was created.',
                'data' => ['sanf_no' => $sanfNo],
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر إضافة الصنف / Failed to create the item.', $e);
        }
    }

    /**
     * تعديل بيانات الصنف الرئيسي (لا يمس السطور التاريخية)
     * Update the main item record (historical lines are untouched).
     */
    public function update(Request $request, int $sanfNo): JsonResponse
    {
        $validator = validator($request->all(), [
            'part_no' => ['required', 'string', 'max:30'],
            'sanf_name' => ['required', 'string', 'max:100'],
            'sanf_price' => ['required', 'numeric', 'min:0'],
            'buy_price' => ['nullable', 'numeric', 'min:0'],
            'unit_no' => ['required', 'integer', 'min:1'],
            'group_no' => ['required', 'integer', 'min:1'],
            'type_no' => ['required', 'integer', 'min:1'],
            'cat_no' => ['nullable', 'integer'],
            'styl_no' => ['required', 'integer', 'min:1'],
            'sanf_code' => ['nullable', 'string', 'max:30'],
            'mcolor' => ['nullable', 'string', 'max:20'],
            'cap' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'integer', 'in:0,1'],
        ], [
            'sanf_name.required' => 'اسم الصنف مطلوب / The item name is required.',
            'styl_no.required' => 'النمط مطلوب / The style is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الصنف غير صالحة / The item payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($this->fetchItem($sanfNo) === null) {
            return $this->notFound($sanfNo);
        }

        $name = trim((string) $request->input('sanf_name'));
        $partNo = trim((string) $request->input('part_no'));
        $conflict = $this->nameOrPartConflict($name, $partNo, $sanfNo);

        if ($conflict !== null) {
            return $conflict;
        }

        if (! $this->styleExists((int) $request->input('styl_no'))) {
            return response()->json([
                'success' => false,
                'message' => 'النمط المحدد غير موجود / The selected style does not exist.',
                'errors' => ['styl_no' => ['النمط المحدد غير موجود / The selected style does not exist.']],
            ], 422);
        }

        try {
            $oracleName = $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 100);

            DB::connection('oracle')->update(
                'UPDATE ACCOUNTS.SANFS
                    SET SANF_NAME = :sanf_name, PART_NO = :part_no, SANF_PRICE = :sanf_price,
                        BUY_PRICE = :buy_price, UNIT_NO = :unit_no, GROUPNO = :group_no,
                        TYPE_NO = :type_no, CAT_NO = :cat_no, STYL_NO = :styl_no,
                        SANF_CODE = :sanf_code, MCOLOR = :mcolor, CAP = :cap,
                        ACTIVE = :active, LAST_UPDATED_DATE = SYSDATE
                  WHERE SANF_NO = :sanf_no',
                [
                    'sanf_name' => $oracleName,
                    'part_no' => $partNo,
                    'sanf_price' => (float) $request->input('sanf_price'),
                    'buy_price' => (float) ($request->input('buy_price') ?? 0),
                    'unit_no' => (int) $request->input('unit_no'),
                    'group_no' => (int) $request->input('group_no'),
                    'type_no' => (int) $request->input('type_no'),
                    'cat_no' => $request->input('cat_no') !== null ? (int) $request->input('cat_no') : null,
                    'styl_no' => (int) $request->input('styl_no'),
                    'sanf_code' => $this->clip((string) ($request->input('sanf_code') ?? ''), 30),
                    'mcolor' => $this->clip($this->arabicOrNull($request->input('mcolor')), 20),
                    'cap' => (string) (float) ($request->input('cap') ?? 1),
                    'active' => (int) ($request->input('active') ?? 1),
                    'sanf_no' => $sanfNo,
                ]
            );

            $this->activity->success('stores.items_updated', [
                'module_code' => 'stores',
                'entity_type' => 'item',
                'entity_id' => (string) $sanfNo,
                'description_ar' => 'تعديل الصنف رقم '.$sanfNo.' باسم '.$name,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الصنف بنجاح / The item was updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر تعديل الصنف / Failed to update the item.', $e);
        }
    }

    /**
     * حذف صنف — ممنوع إن كان له أي حركة مخزنية
     * Delete an item — refused when it has any stock movement.
     *
     * ── القاعدة: الحركة هي التاريخ. صنف بلا حركة يمكن حذفه مع أصنافه الفرعية ووحداته
     *    (تُحذف أولاً لاحترام المفاتيح الأجنبية). صنف له حركة يُعطّل (`ACTIVE = 0`) بدلاً
     *    من حذفه، لأن حذفه يترك سطور مستندات معلّقة بلا صنف.
     * ── Rule: movement is history. An item with no movement can be deleted together with its
     *    sub items and units (children first, for the foreign keys). An item with movement is
     *    deactivated instead, because deleting it would orphan document lines.
     */
    public function destroy(Request $request, int $sanfNo): JsonResponse
    {
        $item = $this->fetchItem($sanfNo);

        if ($item === null) {
            return $this->notFound($sanfNo);
        }

        $movements = $this->movementCount($sanfNo);

        if ($movements > 0) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف الصنف لوجود '.$movements.' حركة مخزنية — عطّله بدلاً من حذفه '
                    .'/ The item cannot be deleted ('.$movements.' movements). Deactivate it instead.',
                'errors' => ['movements' => $movements],
            ], 422);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($sanfNo): void {
                // الأبناء أولاً: المفاتيح الأجنبية تمنع حذف الأب قبلهم
                // Children first: the foreign keys block deleting the parent beforehand.
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.PARTIAL_SANFS WHERE SANF_NO = :id',
                    ['id' => $sanfNo]
                );
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.SANFS_D WHERE SANF_NO = :id',
                    ['id' => $sanfNo]
                );
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.SANFS WHERE SANF_NO = :id',
                    ['id' => $sanfNo]
                );
            });

            $this->activity->success('stores.items_deleted', [
                'module_code' => 'stores',
                'entity_type' => 'item',
                'entity_id' => (string) $sanfNo,
                'description_ar' => 'حذف الصنف رقم '.$sanfNo.' — '.($item['SANF_NAME'] ?? ''),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الصنف بنجاح / The item was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف الصنف / Failed to delete the item.', $e);
        }
    }

    /**
     * إضافة صنف فرعي (باركود/لون/مقاس)
     * Add a sub item (barcode / colour / size).
     */
    public function storeSub(Request $request, int $sanfNo): JsonResponse
    {
        $item = $this->fetchItem($sanfNo);

        if ($item === null) {
            return $this->notFound($sanfNo);
        }

        $validator = validator($request->all(), [
            'len' => ['nullable', 'numeric'],
            'wid' => ['nullable', 'numeric'],
            'color_no' => ['nullable', 'integer'],
            'barcode' => ['nullable', 'string', 'max:30'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الصنف الفرعي غير صالحة / The sub item payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $sanfDno = DB::connection('oracle')->transaction(fn (): int => $this->insertSub($sanfNo, [
                'len' => (float) ($request->input('len') ?? 0),
                'wid' => (float) ($request->input('wid') ?? 0),
                'color_no' => (int) ($request->input('color_no') ?? 0),
                'barcode' => (string) ($request->input('barcode') ?? ''),
                'price' => (float) ($request->input('price') ?? $item['SANF_PRICE'] ?? 0),
            ], [
                'name' => (string) ($item['SANF_NAME'] ?? ''),
                'part_no' => (string) ($item['PART_NO'] ?? ''),
                'type_no' => (int) ($item['TYPE_NO'] ?? 0),
                'styl_no' => (int) ($item['STYL_NO'] ?? 0),
                'unit_no' => (int) ($item['UNIT_NO'] ?? 1),
            ]));

            $this->activity->success('stores.items_updated', [
                'module_code' => 'stores',
                'entity_type' => 'item_sub',
                'entity_id' => (string) $sanfDno,
                'description_ar' => 'إضافة صنف فرعي '.$sanfDno.' للصنف '.$sanfNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة الصنف الفرعي / The sub item was created.',
                'data' => ['sanf_dno' => $sanfDno],
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر إضافة الصنف الفرعي / Failed to create the sub item.', $e);
        }
    }

    /**
     * تعديل صنف فرعي
     * Update a sub item.
     */
    public function updateSub(Request $request, int $sanfNo, int $sanfDno): JsonResponse
    {
        $validator = validator($request->all(), [
            'len' => ['nullable', 'numeric'],
            'wid' => ['nullable', 'numeric'],
            'color_no' => ['nullable', 'integer'],
            'barcode' => ['nullable', 'string', 'max:30'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'integer', 'in:0,1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الصنف الفرعي غير صالحة / The sub item payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $affected = DB::connection('oracle')->update(
                'UPDATE ACCOUNTS.SANFS_D
                    SET LEN = :len, WID = :wid, COLOR_NO = :color_no, BARACOCE = :barcode,
                        SANF_PRICE = :price, ACTIVE = :active
                  WHERE SANF_DNO = :sanf_dno AND SANF_NO = :sanf_no',
                [
                    'len' => (float) ($request->input('len') ?? 0),
                    'wid' => (float) ($request->input('wid') ?? 0),
                    'color_no' => (int) ($request->input('color_no') ?? 0),
                    'barcode' => $this->clip((string) ($request->input('barcode') ?? ''), 30),
                    'price' => $request->input('price') !== null ? (float) $request->input('price') : null,
                    'active' => (int) ($request->input('active') ?? 1),
                    'sanf_dno' => $sanfDno,
                    'sanf_no' => $sanfNo,
                ]
            );

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "الصنف الفرعي [{$sanfDno}] غير موجود / Sub item [{$sanfDno}] not found.",
                ], 404);
            }

            $this->activity->success('stores.items_updated', [
                'module_code' => 'stores',
                'entity_type' => 'item_sub',
                'entity_id' => (string) $sanfDno,
                'description_ar' => 'تعديل الصنف الفرعي '.$sanfDno.' للصنف '.$sanfNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الصنف الفرعي / The sub item was updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر تعديل الصنف الفرعي / Failed to update the sub item.', $e);
        }
    }

    /**
     * حذف صنف فرعي — ممنوع إن كان قد ظهر في مستند
     * Delete a sub item — refused when it already appears in a document.
     */
    public function destroySub(Request $request, int $sanfNo, int $sanfDno): JsonResponse
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_RECEIVE_DETAILES WHERE SANF_DNO = :d1)
                  + (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_SARF_DETAILES WHERE SANF_DNO = :d2)
                  + (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_TRANSD WHERE SANF_DNO = :d3) AS C FROM DUAL',
            ['d1' => $sanfDno, 'd2' => $sanfDno, 'd3' => $sanfDno]
        );

        if ((int) (reset($row) ?: 0) > 0) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف الصنف الفرعي لأنه مستخدم في مستندات — عطّله بدلاً من حذفه '
                    .'/ The sub item is used by documents; deactivate it instead.',
            ], 422);
        }

        try {
            $affected = DB::connection('oracle')->delete(
                'DELETE FROM ACCOUNTS.SANFS_D WHERE SANF_DNO = :sanf_dno AND SANF_NO = :sanf_no',
                ['sanf_dno' => $sanfDno, 'sanf_no' => $sanfNo]
            );

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "الصنف الفرعي [{$sanfDno}] غير موجود / Sub item [{$sanfDno}] not found.",
                ], 404);
            }

            $this->activity->success('stores.items_updated', [
                'module_code' => 'stores',
                'entity_type' => 'item_sub',
                'entity_id' => (string) $sanfDno,
                'description_ar' => 'حذف الصنف الفرعي '.$sanfDno.' من الصنف '.$sanfNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الصنف الفرعي / The sub item was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف الصنف الفرعي / Failed to delete the sub item.', $e);
        }
    }

    /**
     * إضافة وحدة وسعر للصنف
     * Add a unit and price to the item.
     */
    public function storeUnit(Request $request, int $sanfNo): JsonResponse
    {
        if ($this->fetchItem($sanfNo) === null) {
            return $this->notFound($sanfNo);
        }

        $validator = validator($request->all(), [
            'unit_no' => ['required', 'integer', 'min:1'],
            'cap' => ['required', 'numeric', 'not_in:0'],
            'price' => ['required', 'numeric', 'min:0'],
        ], [
            'cap.required' => 'معامل التحويل (عدد الوحدات) مطلوب / The conversion factor is required.',
            'cap.not_in' => 'معامل التحويل لا يكون صفراً / The conversion factor cannot be zero.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الوحدة غير صالحة / The unit payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::connection('oracle')->transaction(fn () => $this->insertUnit(
                $sanfNo,
                (int) $request->input('unit_no'),
                (float) $request->input('cap'),
                (float) $request->input('price'),
                duplicateSafe: true
            ));

            $this->activity->success('stores.items_updated', [
                'module_code' => 'stores',
                'entity_type' => 'item_unit',
                'entity_id' => (string) $sanfNo,
                'description_ar' => 'إضافة وحدة للصنف '.$sanfNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة الوحدة / The unit was added.',
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر إضافة الوحدة / Failed to add the unit.', $e);
        }
    }

    /**
     * حذف وحدة وسعر — ممنوع إن كانت الوحدة الأساسية للصنف
     * Delete a unit/price — refused when it is the item's base unit.
     */
    public function destroyUnit(Request $request, int $sanfNo, int $cap): JsonResponse
    {
        $item = $this->fetchItem($sanfNo);

        if ($item === null) {
            return $this->notFound($sanfNo);
        }

        if ((float) ($item['CAP'] ?? 0) === (float) $cap) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف الوحدة الأساسية للصنف / The item base unit cannot be deleted.',
            ], 422);
        }

        try {
            DB::connection('oracle')->delete(
                'DELETE FROM ACCOUNTS.PARTIAL_SANFS WHERE SANF_NO = :sanf_no AND CAP = :cap',
                ['sanf_no' => $sanfNo, 'cap' => $cap]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الوحدة / The unit was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف الوحدة / Failed to delete the unit.', $e);
        }
    }

    /**
     * كارت الصنف — آخر حركاته من `ITEM_MOVEMENT_D`
     * The item card — its latest movements from ITEM_MOVEMENT_D.
     */
    public function movements(Request $request, int $sanfNo): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? 500), 2000));
        $storeNo = $request->input('store_no');
        $subDno = $request->input('sub_dno');
        $from = $request->input('from');
        $to = $request->input('to');

        $where = ['m.I_CODE = :sanf_no'];
        $binds = ['sanf_no' => $sanfNo, 'limit' => $limit];

        if ($storeNo !== null && $storeNo !== '' && $storeNo !== 'all') {
            $where[] = 'm.INV_ID = :store_no';
            $binds['store_no'] = (int) $storeNo;
        }

        // تصفية على فرع واحد: الأيقونة بجانب كل صنف فرعي تفتح كشفه هو
        // Scope to one branch: the icon beside a sub item opens its own statement.
        if ($subDno !== null && $subDno !== '' && $subDno !== 'all') {
            $where[] = 'm.P_CODE = :sub_dno';
            $binds['sub_dno'] = (int) $subDno;
        }

        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'm.I_DATE >= TO_DATE(:from_date, \'YYYY-MM-DD\')';
            $binds['from_date'] = $from;
        }

        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'm.I_DATE < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1';
            $binds['to_date'] = $to;
        }

        $whereSql = implode(' AND ', $where);

        try {
            // ── `P_CODE` هو `SANFS_D.SANF_DNO`: بلا إظهاره يصبح كشف حركة الصنف الرئيسي قائمة
            //    سطور لا يُعرف أي فرع حرّكها، والمستخدم طلب الكشف «بكامل فروعه».
            // ── P_CODE is SANFS_D.SANF_DNO. Without it the main item's statement is a list of
            //    lines with no branch identity, and the whole point is the item with all branches.
            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT m.I_DATE, m.INVOIC_ID AS DOC_NO, m.BILL_DOC_TYPE AS TYPE_NO,
                           m.TR_INV_TYPE_NAME, m.INV_ID, m.INV_NAME, m.UOM_CODE, m.P_QTY,
                           m.P_CODE AS RCRD_NO, m.COLOR_NO, m.WID, m.LEN,
                           (SELECT MAX(sd.SANF_DNAME) FROM ACCOUNTS.SANFS_D sd
                             WHERE sd.SANF_DNO = m.P_CODE) AS SUB_NAME,
                           (SELECT MAX(sd.PART_NO) FROM ACCOUNTS.SANFS_D sd
                             WHERE sd.SANF_DNO = m.P_CODE) AS SUB_PART_NO,
                           (SELECT MAX(sc.COLOR_NAME) FROM ACCOUNTS.SANFS_COLOR sc
                             WHERE sc.COLOR_NO = m.COLOR_NO) AS COLOR_NAME,
                           CASE WHEN m.P_QTY > 0 THEN m.P_QTY END AS QTY_IN,
                           CASE WHEN m.P_QTY < 0 THEN -m.P_QTY END AS QTY_OUT,
                           m.STK_COST, m.P_QTY * m.STK_COST AS LINE_VALUE, m.IT_NAME
                      FROM ACCOUNTS.ITEM_MOVEMENT_D m
                     WHERE '.$whereSql.'
                     ORDER BY m.I_DATE DESC, m.INVOIC_ID DESC
                 ) WHERE ROWNUM <= :limit',
                $binds
            );

            $clean = array_map(
                static fn ($row): array => ArabicEncoding::cleanData(array_change_key_case((array) $row, CASE_UPPER)),
                $rows
            );

            // المجاميع تُحسب على كل الصفوف لا على صفحة العرض، وإلا صار رصيد الكشف خاطئاً
            // Totals come from every row, not the visible page, or the statement balance lies.
            $totals = (array) DB::connection('oracle')->selectOne(
                'SELECT SUM(CASE WHEN m.P_QTY > 0 THEN m.P_QTY ELSE 0 END) AS QTY_IN,
                        SUM(CASE WHEN m.P_QTY < 0 THEN -m.P_QTY ELSE 0 END) AS QTY_OUT,
                        SUM(m.P_QTY) AS QTY_NET,
                        SUM(m.P_QTY * m.STK_COST) AS VALUE,
                        COUNT(*) AS LINES
                   FROM ACCOUNTS.ITEM_MOVEMENT_D m
                  WHERE '.$whereSql,
                array_diff_key($binds, ['limit' => true])
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب حركات الصنف / Item movements retrieved.',
                'data' => $clean,
                'meta' => [
                    'total' => count($clean),
                    'limit' => $limit,
                    'totals' => array_change_key_case($totals, CASE_UPPER),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب حركات الصنف / Failed to retrieve item movements.', $e);
        }
    }

    /**
     * رأس الصنف مع أسماء المرجعيات
     * The item header with its reference names.
     *
     * @return array<string, mixed>|null
     */
    private function fetchItem(int $sanfNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT s.SANF_NO, s.PART_NO, s.SANF_NAME, s.SANF_CODE, s.GROUPNO, g.GROUP_NAME,
                    s.TYPE_NO, t.TYPE_NAME, s.CAT_NO,
                    (SELECT MAX(c.CATEGORY) FROM ACCOUNTS.CATEGORY c WHERE c.CAT_NO = s.CAT_NO) AS CATEGORY_NAME,
                    s.UNIT_NO, u.UNIT_NAME, s.SANF_PRICE, s.BUY_PRICE, s.CAP, s.MCOLOR,
                    s.ACTIVE, s.STYL_NO, st.STYLE_NAME, s.CREATED_ON
               FROM ACCOUNTS.SANFS s
               LEFT JOIN ACCOUNTS.GROUP1 g ON g.GROUP_NO = s.GROUPNO
               LEFT JOIN ACCOUNTS.TYPES t ON t.TYPE_NO = s.TYPE_NO
               LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = s.UNIT_NO
               LEFT JOIN ACCOUNTS.STYLE st ON st.SHAPE_STYLE_NO = s.STYL_NO
              WHERE s.SANF_NO = :sanf_no',
            ['sanf_no' => $sanfNo]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * الأصناف الفرعية بأرصدتها
     * The sub items, each with its own balance.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchSubs(int $sanfNo): array
    {
        // ── رصيد الصنف الفرعي: `AVVG` مُجمَّعة على الصنف الرئيسي لا على الفرعي، فالمصدر الوحيد
        //    لكمية الفرع هو `ITEM_MOVEMENT_D` عبر `P_CODE` (= `SANFS_D.SANF_DNO`).
        // ── أسماء الألوان والوحدات **استعلامات فردية لا روابط**: القاعدة فيها 54 صفاً في
        //    `SANFS_COLOR` لـ 27 معرّف لون فقط (كل لون بصفين)، فأي `LEFT JOIN` عليهضاعف كل فرع
        //    مضاعفة ظاهرة — وهو بالضبط العطل الذي بلّغه المستخدم مراراً عن تكرار الأصناف الفرعية.
        // ── A sub item's balance: AVVG aggregates on the main item, never the sub, so the only
        //    source for a branch quantity is ITEM_MOVEMENT_D via P_CODE (= SANFS_D.SANF_DNO).
        //    Colour and unit names are scalar lookups, not joins: SANFS_COLOR holds 54 rows for
        //    27 colour ids (every colour twice), so any LEFT JOIN on it doubles every branch —
        //    exactly the duplication the user kept reporting.
        $rows = DB::connection('oracle')->select(
            'SELECT d.SANF_DNO, d.PART_NO, d.SANF_NAME, d.SANF_DNAME, d.SANF_COCE, d.BARACOCE,
                    d.LEN, d.WID, d.COLOR_NO, d.SANF_PRICE, d.UNIT_NO, d.CAP, d.ACTIVE,
                    d.CATEGORY, d.DCOLOR, d.MCOLOR,
                    (SELECT MAX(sc.COLOR_NAME) FROM ACCOUNTS.SANFS_COLOR sc
                      WHERE sc.COLOR_NO = d.COLOR_NO) AS COLOR_NAME,
                    (SELECT MAX(u.UNIT_NAME) FROM ACCOUNTS.UNITS u
                      WHERE u.UNIT_NO = d.UNIT_NO) AS UNIT_NAME,
                    s.SANF_NAME AS PARENT_NAME, s.PART_NO AS ITEM_PART_NO,
                    NVL(q.QTY, 0) AS QTY,
                    NVL(q.VALUE, 0) AS VALUE
               FROM ACCOUNTS.SANFS_D d
               LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
               LEFT JOIN (
                    -- رصيد الفرع من حركته هو، بغير تقييد بالصنف الأب: نفس المصدر يستعمل في
                    -- القائمة والتفصيل وكشف الحركة فتتطابق الأرقام في الثلاثة دائماً. القيد لم
                    -- يكن يوفّر زمناً أصلاً — العرض يجمع كله قبل الفلترة (مقاس: 1,552 مللي).
                    -- A branch balance is its own movement, unrestricted by parent: the same
                    -- source feeds the list, the detail and the statement, so the three always
                    -- agree. The predicate saved nothing anyway, because the view aggregates
                    -- everything before filtering (measured 1,552ms).
                    SELECT P_CODE, SUM(P_QTY) AS QTY, SUM(P_QTY * STK_COST) AS VALUE
                      FROM ACCOUNTS.ITEM_MOVEMENT_D
                     GROUP BY P_CODE
               ) q ON q.P_CODE = d.SANF_DNO
              WHERE d.SANF_NO = :sanf_no
              ORDER BY d.LEN, d.WID, d.COLOR_NO, d.SANF_DNO',
            ['sanf_no' => $sanfNo]
        );

        return array_map(static fn ($row): array => ArabicEncoding::cleanData(array_change_key_case((array) $row, CASE_UPPER)), $rows);
    }

    /**
     * تبويب الأصناف الفرعية — كل الفروع في النظام بصفحات، ورصيد كل فرع مباشرة
     * The sub-items tab — every branch in the system, paged, each with its own balance.
     *
     * ── رصيد الفرع يُحسب من حركته هو (`P_CODE`)، مقاس: 2,150 مللي للصفحة. وهو نفس الرقم الذي
     *    يُبنى منه رصيد الصنف الأب، فتبقى اللائحتان متطابقتين دائماً: مجموع الفروع = الرئيسي.
     * ── A branch balance is its own movement (P_CODE): measured 2,150ms per page. It is the same
     *    figure the parent item's balance is rolled up from, so the two lists always agree —
     *    branches sum to the main item, by construction.
     */
    public function subIndex(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        $where = ['1 = 1'];
        $binds = [];

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);

            if (is_numeric($search)) {
                $where[] = '(d.SANF_DNO = :sanf_dno OR d.SANF_NO = :sanf_no
                             OR d.PART_NO LIKE :part_like)';
                $binds['sanf_dno'] = (int) $search;
                $binds['sanf_no'] = (int) $search;
                $binds['part_like'] = '%'.$search.'%';
            } else {
                $where[] = '(d.SANF_DNAME LIKE :name_ar OR d.SANF_DNAME LIKE :name_utf
                             OR d.SANF_NAME LIKE :name_ar2 OR d.SANF_NAME LIKE :name_utf2
                             OR d.PART_NO LIKE :part_like2 OR d.SANF_COCE LIKE :code_like
                             OR s.SANF_NAME LIKE :parent_ar OR s.SANF_NAME LIKE :parent_utf)';
                $binds['name_ar'] = '%'.$oracle.'%';
                $binds['name_utf'] = '%'.$search.'%';
                $binds['name_ar2'] = '%'.$oracle.'%';
                $binds['name_utf2'] = '%'.$search.'%';
                $binds['part_like2'] = '%'.$search.'%';
                $binds['code_like'] = '%'.$search.'%';
                $binds['parent_ar'] = '%'.$oracle.'%';
                $binds['parent_utf'] = '%'.$search.'%';
            }
        }

        foreach (['group_no' => 's.GROUPNO', 'type_no' => 's.TYPE_NO'] as $input => $column) {
            $value = $request->input($input);

            if ($value !== null && $value !== '' && $value !== 'all') {
                $where[] = $column.' = :'.$input;
                $binds[$input] = (int) $value;
            }
        }

        $active = $request->input('active');

        if ($active !== null && $active !== '' && $active !== 'all') {
            $where[] = 'NVL(d.ACTIVE, 1) = :active';
            $binds['active'] = (int) $active;
        }

        $whereSql = implode(' AND ', $where);

        try {
            $countRow = (array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS TOTAL
                   FROM ACCOUNTS.SANFS_D d
                   JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
                  WHERE '.$whereSql,
                $binds
            );
            $total = (int) (reset($countRow) ?: 0);

            $pagedBinds = $binds;
            $pagedBinds['offset'] = $offset;
            $pagedBinds['offset2'] = $offset;
            $pagedBinds['limit'] = $limit;

            $sql = 'SELECT * FROM (
                        SELECT q.*, ROWNUM AS RN FROM (
                            -- ── التجميع بمعرّف الفرع وحده يجعل مضاعفة الصفف مستحيلة بنيوياً: أي
                            --    رابط يصادف أنه 1:N (لوحة ألوان مكررة، صنف أب مكرر) يُدمج في صف واحد.
                            --    وهذا يحمي من العطل الذي بلّغه المستخدم عن تكرار الصنف الفرعي مرتين.
                            -- ── Grouping by the branch id alone makes row doubling structurally
                            --    impossible: any join that happens to be 1:N (a repeated colour row, a
                            --    duplicated parent) collapses back into one row. This is the guard
                            --    against the reported "sub item shown twice".
                            SELECT d.SANF_DNO, d.SANF_NO,
                                   MAX(d.PART_NO) AS PART_NO, MAX(d.SANF_DNAME) AS SANF_DNAME,
                                   MAX(d.SANF_COCE) AS SANF_COCE, MAX(d.BARACOCE) AS BARACOCE,
                                   MAX(d.LEN) AS LEN, MAX(d.WID) AS WID,
                                   MAX(d.COLOR_NO) AS COLOR_NO, MAX(d.ACTIVE) AS ACTIVE,
                                   MAX(d.CATEGORY) AS CATEGORY, MAX(d.DCOLOR) AS DCOLOR,
                                   MAX(d.MCOLOR) AS MCOLOR,
                                   MAX(d.SANF_PRICE) AS SANF_PRICE, MAX(d.UNIT_NO) AS UNIT_NO,
                                   MAX(d.CAP) AS CAP, MAX(u.UNIT_NAME) AS UNIT_NAME,
                                   MAX(s.SANF_NAME) AS SANF_NAME,
                                   MAX(s.PART_NO) AS ITEM_PART_NO,
                                   MAX(s.GROUPNO) AS GROUPNO, MAX(g.GROUP_NAME) AS GROUP_NAME,
                                   MAX(s.TYPE_NO) AS TYPE_NO, MAX(t.TYPE_NAME) AS TYPE_NAME,
                                   MAX(sc.COLOR_NAME) AS COLOR_NAME,
                                   NVL(MAX(bt.QTY), 0) AS QTY,
                                   NVL(MAX(bt.VALUE), 0) AS VALUE
                              FROM ACCOUNTS.SANFS_D d
                              JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
                              LEFT JOIN ACCOUNTS.GROUP1 g ON g.GROUP_NO = s.GROUPNO
                              LEFT JOIN ACCOUNTS.TYPES t ON t.TYPE_NO = s.TYPE_NO
                              LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = d.UNIT_NO
                              LEFT JOIN ACCOUNTS.SANFS_COLOR sc ON sc.COLOR_NO = d.COLOR_NO
                              LEFT JOIN (
                                    SELECT P_CODE, SUM(P_QTY) AS QTY,
                                           SUM(P_QTY * STK_COST) AS VALUE
                                      FROM ACCOUNTS.ITEM_MOVEMENT_D
                                     GROUP BY P_CODE
                              ) bt ON bt.P_CODE = d.SANF_DNO
                             WHERE '.$whereSql.'
                             GROUP BY d.SANF_DNO, d.SANF_NO
                             -- الترتيب بالأسماء المستعارة لا بـMAX(): داخل كتلة مجمّعة ملفوفة
                             -- بـROWNUM يرمي ORDER BY MAX(...) الخطأ ORA-00935
                             -- Order by the aliases: ORDER BY MAX(...) inside a grouped block wrapped
                             -- by ROWNUM raises ORA-00935.
                             ORDER BY SANF_NAME, LEN, WID, SANF_DNO
                        ) q WHERE ROWNUM <= :offset2 + :limit
                    ) WHERE RN > :offset';

            $rows = DB::connection('oracle')->select($sql, $pagedBinds);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الأصناف الفرعية / Sub items retrieved.',
                'data' => array_map(
                    static fn ($row): array => ArabicEncoding::cleanData(array_change_key_case((array) $row, CASE_UPPER)),
                    $rows
                ),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب الأصناف الفرعية / Failed to retrieve sub items.', $e);
        }
    }

    /**
     * الوحدات والأسعار
     * Units and prices.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchUnits(int $sanfNo): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT p.SEQ_NO, p.SANF_NO, p.CAP, p.SANF_PRICE, p.UNIT_NO,
                    NVL(p.UNIT_NAME, u.UNIT_NAME) AS UNIT_NAME
               FROM ACCOUNTS.PARTIAL_SANFS p
               LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = p.UNIT_NO
              WHERE p.SANF_NO = :sanf_no
              ORDER BY p.CAP',
            ['sanf_no' => $sanfNo]
        );

        return array_map(static fn ($row): array => ArabicEncoding::cleanData(array_change_key_case((array) $row, CASE_UPPER)), $rows);
    }

    /**
     * عدد حركات الصنف — البوابة الوحيدة أمام الحذف
     * Movement count — the single gate in front of deletion.
     */
    private function movementCount(int $sanfNo): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.ITEM_MOVEMENT_D WHERE I_CODE = :sanf_no',
            ['sanf_no' => $sanfNo]
        );

        return (int) (reset($row) ?: 0);
    }

    /**
     * إدراج صنف فرعي
     * Insert a sub item.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $item
     */
    private function insertSub(int $sanfNo, array $values, array $item): int
    {
        $sanfDno = StorePolicy::nextNumber('SEQ_STORES_ITEM_D');
        $name = (string) ($item['name'] ?? '');
        $oracleName = $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 100);

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.SANFS_D
                (SANF_DNO, SANF_NO, TYPE_NO, PART_NO, SANF_COCE, BARACOCE, SANF_NAME,
                 SANF_DNAME, WID, LEN, COLOR_NO, STYL_NO, UNIT_NO, SANF_PRICE, CATEGORY,
                 SANF_TYPE, ACTIVE, STORNO)
             VALUES
                (:sanf_dno, :sanf_no, :type_no, :part_no, :code, :barcode, :name,
                 :dname, :wid, :len, :color_no, :styl_no, :unit_no, :price, NULL,
                 1, 1, 0)',
            [
                'sanf_dno' => $sanfDno,
                'sanf_no' => $sanfNo,
                'type_no' => (int) ($item['type_no'] ?? 0),
                'part_no' => (string) ($item['part_no'] ?? ''),
                // SANF_COCE إلزامي في المخطط: كود الصنف + رقمه الفرعي
                // SANF_COCE is mandatory: the part number plus a local sequence.
                'code' => $this->clip((string) ($item['part_no'] ?? '').'-'.$sanfDno, 30),
                'barcode' => $this->clip((string) ($values['barcode'] ?? ''), 30),
                'name' => $oracleName,
                'dname' => $oracleName,
                'wid' => $values['wid'],
                'len' => $values['len'],
                'color_no' => $values['color_no'],
                'styl_no' => (int) ($item['styl_no'] ?? 0),
                'unit_no' => (int) ($item['unit_no'] ?? 1),
                'price' => $values['price'],
            ]
        );

        return $sanfDno;
    }

    /**
     * إدراج وحدة وسعر
     * Insert a unit and its price.
     */
    private function insertUnit(int $sanfNo, int $unitNo, float $cap, float $price, bool $duplicateSafe = false): void
    {
        if ($duplicateSafe) {
            $exists = (array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS C FROM ACCOUNTS.PARTIAL_SANFS WHERE SANF_NO = :s AND CAP = :c',
                ['s' => $sanfNo, 'c' => $cap]
            );

            if ((int) (reset($exists) ?: 0) > 0) {
                return;
            }
        }

        $seqNo = StorePolicy::nextNumber('SEQ_STORES_PSANF');
        $maxRow = (array) DB::connection('oracle')
            ->selectOne('SELECT NVL(MAX(SEQ_NO), 0) AS M FROM ACCOUNTS.PARTIAL_SANFS');
        $maxSeq = (int) (reset($maxRow) ?: 0);

        // SEQ_NO فريد؛ التسلسل قد يتأخر عن القيم القائمة بعد استيراد قديم، فنأخذ الأكبر
        // SEQ_NO is unique: the sequence may lag behind legacy values, so take the greater.
        if ($seqNo <= $maxSeq) {
            $seqNo = $maxSeq + 1;
        }

        $unitRow = (array) DB::connection('oracle')->selectOne(
            'SELECT UNIT_NAME FROM ACCOUNTS.UNITS WHERE UNIT_NO = :u',
            ['u' => $unitNo]
        );
        $unitName = (string) (reset($unitRow) ?: '');

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.PARTIAL_SANFS
                (SEQ_NO, SANF_NO, CAP, SANF_PRICE, UNIT_NO, UNIT_NAME)
             VALUES (:seq_no, :sanf_no, :cap, :price, :unit_no, :unit_name)',
            [
                'seq_no' => $seqNo,
                'sanf_no' => $sanfNo,
                'cap' => $cap,
                'price' => $price,
                'unit_no' => $unitNo,
                'unit_name' => $this->clip($unitName !== '' ? $unitName : null, 30),
            ]
        );
    }

    /**
     * فحص تعارض الاسم أو رقم الصنف قبل الإدراج
     * Pre-check a name / part-number conflict before inserting.
     */
    private function nameOrPartConflict(string $name, string $partNo, ?int $exceptSanfNo = null): ?JsonResponse
    {
        $oracleName = ArabicEncoding::toOracleSearchChars($name) ?? $name;
        $binds = ['name' => $oracleName, 'part' => $partNo];
        $sql = 'SELECT SUM(CASE WHEN SANF_NAME = :name THEN 1 ELSE 0 END) AS NAME_HIT,
                       SUM(CASE WHEN PART_NO = :part THEN 1 ELSE 0 END) AS PART_HIT
                  FROM ACCOUNTS.SANFS';

        if ($exceptSanfNo !== null) {
            $sql .= ' WHERE SANF_NO <> :sanf_no';
            $binds['sanf_no'] = $exceptSanfNo;
        }

        $row = array_change_key_case((array) DB::connection('oracle')->selectOne($sql, $binds), CASE_UPPER);
        $errors = [];

        if ((int) ($row['NAME_HIT'] ?? 0) > 0) {
            $errors['sanf_name'] = ['يوجد صنف بنفس الاسم — الأسماء فريدة / An item with this name already exists.'];
        }

        if ((int) ($row['PART_HIT'] ?? 0) > 0) {
            $errors['part_no'] = ['يوجد صنف بنفس رقم الكود — الأكواد فريدة / An item with this part number already exists.'];
        }

        if ($errors === []) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'بيانات الصنف مكررة / The item duplicates an existing record.',
            'errors' => $errors,
        ], 422);
    }

    /** هل النمط موجود؟ (شرط ظهور الصنف في الـ VIEW) / does the style exist? */
    private function styleExists(int $stylNo): bool
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.STYLE WHERE SHAPE_STYLE_NO = :id',
            ['id' => $stylNo]
        );

        return (int) (reset($row) ?: 0) > 0;
    }

    private function notFound(int $sanfNo): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "الصنف رقم [{$sanfNo}] غير موجود / Item [{$sanfNo}] not found.",
        ], 404);
    }

    private function failure(string $message, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }

    private function arabicOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : (ArabicEncoding::toOracleSearchChars($text) ?? $text);
    }

    private function clip(?string $value, int $bytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= $bytes ? $value : substr($value, 0, $bytes);
    }
}
