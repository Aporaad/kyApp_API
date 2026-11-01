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
 * إدارة المخازن وأمنائها — STORES + STORE_EMP
 * Store registry and its keepers — STORES + STORE_EMP.
 *
 * ── المخزن «الأرشيفي» (2 · 11 · 12) لا يُحذف ولا يُخفى، بل يُعلَّم: المستخدم يجب أن يعرف
 *    أن هذه المخازن مستثناة من الأرصدة، وإلا ظنّ أن بضاعتها ضاعت.
 * ── Archived stores (2 · 11 · 12) are neither deleted nor hidden, they are flagged: the
 *    user must know these are excluded from balances rather than believe stock vanished.
 */
class StoreController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * قائمة المخازن مع قيمة المخزون وعدد الأصناف لكل مخزن
     * Store list with stock value and item count per store.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));

        $sql = 'SELECT s.STORE_NO, s.STORE_NAME, s.LOC, s.NOTE,
                       s.INV_ACC_NO, s.COST_ACC_NO, s.DISCOUNT_ACC, s.DIFF_COST_ACC,
                       NVL(st.ITEMS, 0) AS ITEMS, NVL(st.QTY, 0) AS QTY, NVL(st.VALUE, 0) AS VALUE,
                       NVL(st.NEGATIVE, 0) AS NEGATIVE,
                       (SELECT COUNT(*) FROM ACCOUNTS.STORE_EMP e WHERE e.STORE_NO = s.STORE_NO) AS KEEPERS
                  FROM ACCOUNTS.STORES s
                  LEFT JOIN (
                        -- NEGATIVE = عدد صفوف الصنف×مخزن ذات رصيد سالب في هذا المخزن، لأن 41 رصيداً
                        -- سالباً موجودة فعلاً ويجب أن تظهر كمؤشر لا أن تُخفى.
                        -- NEGATIVE counts the item × store rows below zero in this store: 41 such
                        -- balances already exist and must surface as a KPI, not be hidden.
                        SELECT INV_ID, COUNT(DISTINCT I_CODE) AS ITEMS,
                               SUM(SUM_TP) AS QTY, SUM(SUM_PRICE) AS VALUE,
                               SUM(CASE WHEN SUM_TP < 0 THEN 1 ELSE 0 END) AS NEGATIVE
                          FROM ACCOUNTS.AVVG
                         GROUP BY INV_ID
                  ) st ON st.INV_ID = s.STORE_NO';

        $binds = [];

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);
            $sql .= ' WHERE (s.STORE_NAME LIKE :name_ar OR s.STORE_NAME LIKE :name_utf)';
            $binds['name_ar'] = '%'.$oracle.'%';
            $binds['name_utf'] = '%'.$search.'%';
        }

        $sql .= ' ORDER BY s.STORE_NO';

        try {
            $rows = DB::connection('oracle')->select($sql, $binds);
            $excluded = StorePolicy::excludedStores();

            $data = array_map(static function ($row) use ($excluded): array {
                // منتقي OCI8 قد يعيد المفاتيح صغيرة الحروف، فنُطبّعها قبل قراءة STORE_NO؛
                // الاعتماد على الحالة الأصلية كان يرمي «Undefined array key» عند أول مخزن.
                // OCI8 may return lowercase keys, so normalise before reading STORE_NO; relying
                // on the driver's casing threw "Undefined array key" on the first store.
                $item = ArabicEncoding::cleanData(array_change_key_case((array) $row, CASE_UPPER));
                $item['ARCHIVED'] = in_array((int) $item['STORE_NO'], $excluded, true) ? 1 : 0;

                return $item;
            }, $rows);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب المخازن / Stores retrieved.',
                'data' => $data,
                'meta' => [
                    'total' => count($data),
                    'excluded' => $excluded,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب المخازن / Failed to retrieve stores.', $e);
        }
    }

    /**
     * إضافة مخزن جديد
     * Create a new store.
     *
     * ── المخزن الجديد يعمل مباشرة في الأرصدة لأن `ITEM_MOVEMENT_D` تستثني أرقاماً محددة
     *    فقط (2 · 11 · 12) لا «ما كان موجوداً عند إنشاء العرض».
     * ── A new store starts carrying balances immediately: the view excludes three fixed
     *    numbers, not the stores that existed when it was written.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'store_name' => ['required', 'string', 'max:40'],
            'loc' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:40'],
            'inv_acc_no' => ['nullable', 'integer'],
            'cost_acc_no' => ['nullable', 'integer'],
            'discount_acc_no' => ['nullable', 'integer'],
            'diff_cost_acc' => ['nullable', 'integer'],
        ], [
            'store_name.required' => 'اسم المخزن مطلوب / The store name is required.',
            'store_name.max' => 'اسم المخزن لا يتجاوز 40 حرفاً / The store name is limited to 40 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المخزن غير صالحة / The store payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = trim((string) $request->input('store_name'));

        if ($this->nameExists($name)) {
            return $this->duplicateName($name);
        }

        try {
            $storeNo = StorePolicy::nextNumber('SEQ_STORES_STORE');

            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.STORES
                    (STORE_NO, STORE_NAME, LOC, NOTE, INV_ACC_NO, COST_ACC_NO, DISCOUNT_ACC, DIFF_COST_ACC)
                 VALUES
                    (:store_no, :name, :loc, :note, :inv_acc, :cost_acc, :discount_acc, :diff_acc)',
                [
                    'store_no' => $storeNo,
                    'name' => $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 40),
                    'loc' => $this->clip($this->arabicOrNull($request->input('loc')), 40),
                    'note' => $this->clip($this->arabicOrNull($request->input('note')), 40),
                    'inv_acc' => $this->intOrNull($request->input('inv_acc_no')),
                    'cost_acc' => $this->intOrNull($request->input('cost_acc_no')),
                    'discount_acc' => $this->intOrNull($request->input('discount_acc_no')),
                    'diff_acc' => $this->intOrNull($request->input('diff_cost_acc')),
                ]
            );

            $this->activity->success('stores.created', [
                'module_code' => 'stores',
                'entity_type' => 'store',
                'entity_id' => (string) $storeNo,
                'description_ar' => 'إضافة مخزن رقم '.$storeNo.' باسم '.$name,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة المخزن بنجاح / The store was created.',
                'data' => ['store_no' => $storeNo],
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر إضافة المخزن / Failed to create the store.', $e);
        }
    }

    /**
     * تعديل بيانات مخزن
     * Update a store.
     */
    public function update(Request $request, int $storeNo): JsonResponse
    {
        $validator = validator($request->all(), [
            'store_name' => ['required', 'string', 'max:40'],
            'loc' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:40'],
            'inv_acc_no' => ['nullable', 'integer'],
            'cost_acc_no' => ['nullable', 'integer'],
            'discount_acc_no' => ['nullable', 'integer'],
            'diff_cost_acc' => ['nullable', 'integer'],
        ], [
            'store_name.required' => 'اسم المخزن مطلوب / The store name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المخزن غير صالحة / The store payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! $this->storeExists($storeNo)) {
            return $this->notFound($storeNo);
        }

        $name = trim((string) $request->input('store_name'));

        if ($this->nameExists($name, $storeNo)) {
            return $this->duplicateName($name);
        }

        try {
            DB::connection('oracle')->update(
                'UPDATE ACCOUNTS.STORES
                    SET STORE_NAME = :name, LOC = :loc, NOTE = :note,
                        INV_ACC_NO = :inv_acc, COST_ACC_NO = :cost_acc,
                        DISCOUNT_ACC = :discount_acc, DIFF_COST_ACC = :diff_acc
                  WHERE STORE_NO = :store_no',
                [
                    'name' => $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 40),
                    'loc' => $this->clip($this->arabicOrNull($request->input('loc')), 40),
                    'note' => $this->clip($this->arabicOrNull($request->input('note')), 40),
                    'inv_acc' => $this->intOrNull($request->input('inv_acc_no')),
                    'cost_acc' => $this->intOrNull($request->input('cost_acc_no')),
                    'discount_acc' => $this->intOrNull($request->input('discount_acc_no')),
                    'diff_acc' => $this->intOrNull($request->input('diff_cost_acc')),
                    'store_no' => $storeNo,
                ]
            );

            $this->activity->success('stores.updated', [
                'module_code' => 'stores',
                'entity_type' => 'store',
                'entity_id' => (string) $storeNo,
                'description_ar' => 'تعديل المخزن رقم '.$storeNo.' باسم '.$name,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل المخزن بنجاح / The store was updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر تعديل المخزن / Failed to update the store.', $e);
        }
    }

    /**
     * حذف مخزن — ممنوع إن كان له مستندات أو رصيد
     * Delete a store — refused when it owns documents or stock.
     *
     * ── الحذف هنا **مقصود** لا «ناعم»: STORES لا تحمل علم إلغاء، وترك مخزن محذوف يعني
     *    مستندات معلّقة تشير إلى مخزن غير موجود (وتكسر FK عند الإدراج التالي).
     * ── Deletion is intentional (not soft): STORES has no cancel flag, and a missing store
     *    would leave orphan documents that break the foreign key on the next insert.
     */
    public function destroy(Request $request, int $storeNo): JsonResponse
    {
        if (! $this->storeExists($storeNo)) {
            return $this->notFound($storeNo);
        }

        $usage = $this->usageOf($storeNo);

        if ($usage['documents'] > 0 || abs($usage['qty']) > 0.0001) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف المخزن لوجود مستندات أو رصيد مرتبط به '
                    .'/ The store cannot be deleted: it owns documents or stock.',
                'errors' => ['usage' => $usage],
            ], 422);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($storeNo): void {
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.STORE_EMP WHERE STORE_NO = :store_no',
                    ['store_no' => $storeNo]
                );
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.STORES WHERE STORE_NO = :store_no',
                    ['store_no' => $storeNo]
                );
            });

            $this->activity->success('stores.deleted', [
                'module_code' => 'stores',
                'entity_type' => 'store',
                'entity_id' => (string) $storeNo,
                'description_ar' => 'حذف المخزن رقم '.$storeNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف المخزن بنجاح / The store was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف المخزن / Failed to delete the store.', $e);
        }
    }

    /**
     * قائمة أمناء المخازن
     * The store keepers list.
     */
    public function keepers(Request $request): JsonResponse
    {
        try {
            $rows = DB::connection('oracle')->select(
                'SELECT e.EMP_NO, e.EMP_NAME, e.STORE_NO, s.STORE_NAME
                   FROM ACCOUNTS.STORE_EMP e
                   LEFT JOIN ACCOUNTS.STORES s ON s.STORE_NO = e.STORE_NO
                  ORDER BY s.STORE_NO, e.EMP_NAME'
            );

            return response()->json([
                'success' => true,
                'data' => array_map(
                    static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                    $rows
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب أمناء المخازن / Failed to retrieve keepers.', $e);
        }
    }

    /**
     * إضافة أمين مخزن — EMP_NO مفتاح رئيسي فلا يتكرر
     * Add a keeper. EMP_NO is the primary key, so it must be unique.
     */
    public function storeKeeper(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'emp_name' => ['required', 'string', 'max:40'],
            'store_no' => ['required', 'integer', 'min:1'],
        ], [
            'emp_name.required' => 'اسم أمين المخزن مطلوب / The keeper name is required.',
            'store_no.required' => 'المخزن مطلوب / A store is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات أمين المخزن غير صالحة / The keeper payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $storeNo = (int) $request->input('store_no');

        if (! $this->storeExists($storeNo)) {
            return $this->notFound($storeNo);
        }

        $name = trim((string) $request->input('emp_name'));

        try {
            // EMP_NO ليس له تسلسل في المخطط القديم؛ نولّده من SEQ_STORES_KEEPER ونبدؤه من
            // أكبر رقم قائم + 1 عند أول استخدام.
            $empNo = StorePolicy::nextNumber('SEQ_STORES_KEEPER');
            $maxRow = (array) DB::connection('oracle')
                ->selectOne('SELECT MAX(EMP_NO) AS M FROM ACCOUNTS.STORE_EMP');
            $maxEmp = (int) (reset($maxRow) ?: 0);

            if ($empNo <= $maxEmp) {
                $empNo = $maxEmp + 1;
            }

            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.STORE_EMP (EMP_NO, EMP_NAME, STORE_NO)
                 VALUES (:emp_no, :name, :store_no)',
                [
                    'emp_no' => $empNo,
                    'name' => $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 40),
                    'store_no' => $storeNo,
                ]
            );

            $this->activity->success('stores.keepers_created', [
                'module_code' => 'stores',
                'entity_type' => 'store_keeper',
                'entity_id' => (string) $empNo,
                'description_ar' => 'إضافة أمين مخزن '.$name.' على المخزن '.$storeNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة أمين المخزن / The keeper was created.',
                'data' => ['emp_no' => $empNo],
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر إضافة أمين المخزن / Failed to create the keeper.', $e);
        }
    }

    /**
     * تعديل أمين مخزن
     * Update a keeper.
     */
    public function updateKeeper(Request $request, int $empNo): JsonResponse
    {
        $validator = validator($request->all(), [
            'emp_name' => ['required', 'string', 'max:40'],
            'store_no' => ['required', 'integer', 'min:1'],
        ], [
            'emp_name.required' => 'اسم أمين المخزن مطلوب / The keeper name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات أمين المخزن غير صالحة / The keeper payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $storeNo = (int) $request->input('store_no');

        if (! $this->storeExists($storeNo)) {
            return $this->notFound($storeNo);
        }

        $name = trim((string) $request->input('emp_name'));

        try {
            $affected = DB::connection('oracle')->update(
                'UPDATE ACCOUNTS.STORE_EMP
                    SET EMP_NAME = :name, STORE_NO = :store_no
                  WHERE EMP_NO = :emp_no',
                [
                    'name' => $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 40),
                    'store_no' => $storeNo,
                    'emp_no' => $empNo,
                ]
            );

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "أمين المخزن رقم [{$empNo}] غير موجود / Keeper [{$empNo}] not found.",
                ], 404);
            }

            $this->activity->success('stores.keepers_updated', [
                'module_code' => 'stores',
                'entity_type' => 'store_keeper',
                'entity_id' => (string) $empNo,
                'description_ar' => 'تعديل أمين المخزن '.$name.' على المخزن '.$storeNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل أمين المخزن / The keeper was updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر تعديل أمين المخزن / Failed to update the keeper.', $e);
        }
    }

    /**
     * حذف أمين مخزن
     * Delete a keeper.
     */
    public function destroyKeeper(Request $request, int $empNo): JsonResponse
    {
        try {
            $affected = DB::connection('oracle')->delete(
                'DELETE FROM ACCOUNTS.STORE_EMP WHERE EMP_NO = :emp_no',
                ['emp_no' => $empNo]
            );

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "أمين المخزن رقم [{$empNo}] غير موجود / Keeper [{$empNo}] not found.",
                ], 404);
            }

            $this->activity->success('stores.keepers_deleted', [
                'module_code' => 'stores',
                'entity_type' => 'store_keeper',
                'entity_id' => (string) $empNo,
                'description_ar' => 'حذف أمين المخزن رقم '.$empNo,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف أمين المخزن / The keeper was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف أمين المخزن / Failed to delete the keeper.', $e);
        }
    }

    /** هل يوجد مخزن بهذا الاسم؟ / does a store carry this name? */
    private function nameExists(string $name, ?int $exceptStoreNo = null): bool
    {
        $binds = ['name' => ArabicEncoding::toOracleSearchChars($name) ?? $name];
        $sql = 'SELECT COUNT(*) AS C FROM ACCOUNTS.STORES WHERE STORE_NAME = :name';

        if ($exceptStoreNo !== null) {
            $sql .= ' AND STORE_NO <> :store_no';
            $binds['store_no'] = $exceptStoreNo;
        }

        $row = (array) DB::connection('oracle')->selectOne($sql, $binds);

        return (int) (reset($row) ?: 0) > 0;
    }

    private function storeExists(int $storeNo): bool
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.STORES WHERE STORE_NO = :store_no',
            ['store_no' => $storeNo]
        );

        return (int) (reset($row) ?: 0) > 0;
    }

    /**
     * استخدام المخزن: عدد المستندات والكمية والقيمة
     * Store usage: document count, quantity and value.
     *
     * @return array{documents: int, qty: float, value: float}
     */
    private function usageOf(int $storeNo): array
    {
        $documents = (array) DB::connection('oracle')->selectOne(
            'SELECT (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_RECEIVE WHERE STORE_NO = :s1)
                  + (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_SARF WHERE STORE_NO = :s2)
                  + (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_TRANSM WHERE STORE_NO = :s3 OR STORE_TO = :s4)
                    AS C FROM DUAL',
            ['s1' => $storeNo, 's2' => $storeNo, 's3' => $storeNo, 's4' => $storeNo]
        );

        $stock = array_change_key_case((array) DB::connection('oracle')->selectOne(
            'SELECT NVL(SUM(SUM_TP), 0) AS QTY, NVL(SUM(SUM_PRICE), 0) AS VALUE
               FROM ACCOUNTS.AVVG WHERE INV_ID = :store_no',
            ['store_no' => $storeNo]
        ), CASE_UPPER);

        return [
            'documents' => (int) (reset($documents) ?: 0),
            'qty' => (float) ($stock['QTY'] ?? 0),
            'value' => (float) ($stock['VALUE'] ?? 0),
        ];
    }

    private function duplicateName(string $name): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'يوجد مخزن بنفس الاسم — الأسماء فريدة في مخطط النظام القديم '
                .'/ A store with this name already exists; names are unique in the legacy schema.',
            'errors' => ['store_name' => [$name]],
        ], 422);
    }

    private function notFound(int $storeNo): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "المخزن رقم [{$storeNo}] غير موجود / Store [{$storeNo}] not found.",
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

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function clip(?string $value, int $bytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= $bytes ? $value : substr($value, 0, $bytes);
    }
}
