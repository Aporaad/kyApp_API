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
            'qty' => 'NVL(av.QTY, 0)',
            'value' => 'NVL(av.VALUE, 0)',
            default => 's.SANF_NAME',
        };

        $whereSql = implode(' AND ', $where);

        // استعلام الأرصدة المجمّعة لكل صنف على حدة
        // Aggregated per-item stock sub-query.
        $stockJoin = 'LEFT JOIN (
                          SELECT I_CODE, SUM(SUM_TP) AS QTY, SUM(SUM_PRICE) AS VALUE,
                                 MAX(STK_COST) AS COST
                            FROM ACCOUNTS.AVVG GROUP BY I_CODE
                      ) av ON av.I_CODE = s.SANF_NO';

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
                                   s.CAT_NO, (SELECT MAX(c.CATEGORY) FROM ACCOUNTS.CATEGORY c
                                               WHERE c.CAT_NO = s.CAT_NO) AS CATEGORY_NAME,
                                   s.UNIT_NO, u.UNIT_NAME, s.SANF_PRICE, s.BUY_PRICE,
                                   s.ACTIVE, s.STYL_NO, st.STYLE_NAME,
                                   (SELECT COUNT(*) FROM ACCOUNTS.SANFS_D d
                                     WHERE d.SANF_NO = s.SANF_NO) AS SUBS,
                                   NVL(av.QTY, 0) AS QTY, NVL(av.VALUE, 0) AS VALUE,
                                   NVL(av.COST, NVL(s.SANF_PRICE, 0)) AS COST
                              FROM ACCOUNTS.SANFS s
                              LEFT JOIN ACCOUNTS.GROUP1 g ON g.GROUP_NO = s.GROUPNO
                              LEFT JOIN ACCOUNTS.TYPES t ON t.TYPE_NO = s.TYPE_NO
                              LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = s.UNIT_NO
                              LEFT JOIN ACCOUNTS.STYLE st ON st.SHAPE_STYLE_NO = s.STYL_NO
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
                    static fn ($row): array => ArabicEncoding::cleanData((array) $row),
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
     * تفصيل صنف: بياناته + أصنافه الفرعية + وحداته وأسعاره + أرصدته
     * Item detail: header data, sub items, units/prices and per-store balances.
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
                    'balances' => $this->fetchBalances($sanfNo),
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
                         STYL_NO, SANF_CODE, MAIN_COLOR, CAP, BUY_PRICE, CAT_NO, SANF_TYPE,
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
                        SANF_CODE = :sanf_code, MAIN_COLOR = :mcolor, CAP = :cap,
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
        $limit = max(1, min((int) ($request->input('limit') ?? 200), 1000));
        $storeNo = $request->input('store_no');
        $from = $request->input('from');
        $to = $request->input('to');

        $where = ['m.I_CODE = :sanf_no'];
        $binds = ['sanf_no' => $sanfNo, 'limit' => $limit];

        if ($storeNo !== null && $storeNo !== '' && $storeNo !== 'all') {
            $where[] = 'm.INV_ID = :store_no';
            $binds['store_no'] = (int) $storeNo;
        }

        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'm.I_DATE >= TO_DATE(:from_date, \'YYYY-MM-DD\')';
            $binds['from_date'] = $from;
        }

        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'm.I_DATE < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1';
            $binds['to_date'] = $to;
        }

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT m.I_DATE, m.INVOIC_ID AS DOC_NO, m.BILL_DOC_TYPE AS TYPE_NO, m.TR_INV_TYPE_NAME,
                           m.INV_ID, m.INV_NAME, m.UOM_CODE, m.P_QTY,
                           m.STK_COST, m.P_QTY * m.STK_COST AS LINE_VALUE, m.IT_NAME
                      FROM ACCOUNTS.ITEM_MOVEMENT_D m
                     WHERE '.implode(' AND ', $where).'
                     ORDER BY m.I_DATE DESC, m.INVOIC_ID DESC
                 ) WHERE ROWNUM <= :limit',
                $binds
            );

            return response()->json([
                'success' => true,
                'data' => array_map(
                    static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                    $rows
                ),
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
                    s.UNIT_NO, u.UNIT_NAME, s.SANF_PRICE, s.BUY_PRICE, s.CAP, s.MAIN_COLOR,
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
     * الأصناف الفرعية
     * The sub items.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchSubs(int $sanfNo): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT d.SANF_DNO, d.PART_NO, d.SANF_NAME, d.SANF_DNAME, d.SANF_COCE, d.BARACOCE,
                    d.LEN, d.WID, d.COLOR_NO, d.SANF_PRICE, d.UNIT_NO, d.ACTIVE
               FROM ACCOUNTS.SANFS_D d
              WHERE d.SANF_NO = :sanf_no
              ORDER BY d.LEN, d.WID, d.COLOR_NO',
            ['sanf_no' => $sanfNo]
        );

        return array_map(static fn ($row): array => ArabicEncoding::cleanData((array) $row), $rows);
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

        return array_map(static fn ($row): array => ArabicEncoding::cleanData((array) $row), $rows);
    }

    /**
     * الأرصدة لكل مخزن
     * Balances per store.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchBalances(int $sanfNo): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT INV_ID, MAX(INVNAME) AS INVNAME, SUM(SUM_TP) AS QTY,
                    MAX(STK_COST) AS COST, SUM(SUM_PRICE) AS VALUE
               FROM ACCOUNTS.AVVG
              WHERE I_CODE = :sanf_no
              GROUP BY INV_ID
              ORDER BY INV_ID',
            ['sanf_no' => $sanfNo]
        );

        return array_map(static fn ($row): array => ArabicEncoding::cleanData((array) $row), $rows);
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
