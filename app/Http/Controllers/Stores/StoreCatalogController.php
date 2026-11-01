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
 * المرجعيات: المجموعات (رئيسية/فرعية) والفئات والوحدات
 * Reference data: main/sub groups, categories and units.
 *
 * ── أربعة أنواع في متحكم واحد لأنها كلها «قائمة اسم + عدد أصناف»، وتفريقها إلى أربعة
 *    متحكمات كان سيضاعف نفس التحقق والحماية أربع مرات.
 * ── Four kinds in one controller: they are all "a named list with an item count", and
 *    splitting them would duplicate the same validation and guards four times.
 *
 * ── حماية الحذف: لا يُحذف عنصر يُشير إليه صنف أو نوع، لأن المستندات القديمة تحمل
 *    `TYPE_NO`/`CAT_NO` خاماً؛ حذف المرجع يجعل الأصناف القديمة بلا تصنيف في التقارير.
 * ── Delete guards: an entry referenced by an item or a sub type is never deleted, because
 *    legacy documents carry the raw TYPE_NO / CAT_NO — removing the reference orphans it.
 */
class StoreCatalogController extends Controller
{
    /** الأنواع المدعومة / supported kinds */
    private const KINDS = ['groups', 'types', 'categories', 'units'];

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * قائمة مرجعية واحدة
     * One reference list.
     */
    public function index(Request $request, string $kind): JsonResponse
    {
        if (! in_array($kind, self::KINDS, true)) {
            return $this->unknownKind($kind);
        }

        try {
            return response()->json([
                'success' => true,
                'data' => array_map(
                    static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                    DB::connection('oracle')->select($this->listSql($kind))
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب القائمة المرجعية / Failed to retrieve the catalog.', $e);
        }
    }

    /**
     * إضافة عنصر مرجعي
     * Create a reference entry.
     */
    public function store(Request $request, string $kind): JsonResponse
    {
        if (! in_array($kind, self::KINDS, true)) {
            return $this->unknownKind($kind);
        }

        $name = trim((string) ($request->input('name') ?? ''));

        if ($name === '') {
            return response()->json([
                'success' => false,
                'message' => 'الاسم مطلوب / The name is required.',
                'errors' => ['name' => ['الاسم مطلوب / The name is required.']],
            ], 422);
        }

        $oracleName = $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 100);

        try {
            $id = match ($kind) {
                'groups' => $this->createGroup($oracleName),
                'types' => $this->createType($request, $oracleName),
                'categories' => $this->createCategory($oracleName),
                'units' => $this->createUnit($oracleName),
            };

            if ($id instanceof JsonResponse) {
                return $id;
            }

            $this->activity->success('stores.catalogs_created', [
                'module_code' => 'stores',
                'entity_type' => 'catalog_'.$kind,
                'entity_id' => (string) $id,
                'description_ar' => 'إضافة عنصر في '.$this->label($kind).' باسم '.$name,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة العنصر / The entry was created.',
                'data' => ['id' => $id],
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر إضافة العنصر / Failed to create the entry.', $e);
        }
    }

    /**
     * تعديل عنصر مرجعي
     * Update a reference entry.
     */
    public function update(Request $request, string $kind, int $id): JsonResponse
    {
        if (! in_array($kind, self::KINDS, true)) {
            return $this->unknownKind($kind);
        }

        $name = trim((string) ($request->input('name') ?? ''));

        if ($name === '') {
            return response()->json([
                'success' => false,
                'message' => 'الاسم مطلوب / The name is required.',
                'errors' => ['name' => ['الاسم مطلوب / The name is required.']],
            ], 422);
        }

        $oracleName = $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 100);

        try {
            $affected = match ($kind) {
                'groups' => DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.GROUP1 SET GROUP_NAME = :name WHERE GROUP_NO = :id',
                    ['name' => $oracleName, 'id' => $id]
                ),
                'types' => DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.TYPES SET TYPE_NAME = :name WHERE TYPE_NO = :id',
                    ['name' => $oracleName, 'id' => $id]
                ),
                'categories' => DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.CATEGORY SET CATEGORY = :name WHERE SEQ = :id',
                    ['name' => $oracleName, 'id' => $id]
                ),
                'units' => DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.UNITS SET UNIT_NAME = :name WHERE UNIT_NO = :id',
                    ['name' => $oracleName, 'id' => $id]
                ),
            };

            if ($affected === 0) {
                return $this->notFound($kind, $id);
            }

            $this->activity->success('stores.catalogs_updated', [
                'module_code' => 'stores',
                'entity_type' => 'catalog_'.$kind,
                'entity_id' => (string) $id,
                'description_ar' => 'تعديل عنصر في '.$this->label($kind).' باسم '.$name,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل العنصر / The entry was updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر تعديل العنصر / Failed to update the entry.', $e);
        }
    }

    /**
     * حذف عنصر مرجعي — يُرفض إن كان مشغولاً
     * Delete a reference entry — refused when still referenced.
     */
    public function destroy(Request $request, string $kind, int $id): JsonResponse
    {
        if (! in_array($kind, self::KINDS, true)) {
            return $this->unknownKind($kind);
        }

        $usage = $this->usageOf($kind, $id);

        if ($usage > 0) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن الحذف: العنصر مستخدم في '.$usage.' سجل '
                    .'/ Cannot delete: the entry is referenced by '.$usage.' record(s).',
                'errors' => ['usage' => $usage],
            ], 422);
        }

        try {
            $affected = match ($kind) {
                'groups' => DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.GROUP1 WHERE GROUP_NO = :id',
                    ['id' => $id]
                ),
                'types' => DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.TYPES WHERE TYPE_NO = :id',
                    ['id' => $id]
                ),
                'categories' => DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.CATEGORY WHERE SEQ = :id',
                    ['id' => $id]
                ),
                'units' => DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.UNITS WHERE UNIT_NO = :id',
                    ['id' => $id]
                ),
            };

            if ($affected === 0) {
                return $this->notFound($kind, $id);
            }

            $this->activity->success('stores.catalogs_deleted', [
                'module_code' => 'stores',
                'entity_type' => 'catalog_'.$kind,
                'entity_id' => (string) $id,
                'description_ar' => 'حذف عنصر من '.$this->label($kind).' رقم '.$id,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف العنصر / The entry was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف العنصر / Failed to delete the entry.', $e);
        }
    }

    /**
     * استعلام القائمة بحسب النوع — كل قائمة تحمل عدد الأصناف المرتبطة
     * The list query per kind, each carrying its linked item count.
     */
    private function listSql(string $kind): string
    {
        return match ($kind) {
            'groups' => 'SELECT g.GROUP_NO AS ID, g.GROUP_NAME AS NAME, g.ACTIVE,
                                (SELECT COUNT(*) FROM ACCOUNTS.TYPES t WHERE t.GROUP_NO = g.GROUP_NO) AS TYPES,
                                (SELECT COUNT(*) FROM ACCOUNTS.SANFS s WHERE s.GROUPNO = g.GROUP_NO) AS ITEMS
                           FROM ACCOUNTS.GROUP1 g ORDER BY g.GROUP_NO',
            'types' => 'SELECT t.TYPE_NO AS ID, t.TYPE_NAME AS NAME, t.GROUP_NO,
                               g.GROUP_NAME, t.TYPE_CODE, t.ACTIVE,
                               (SELECT COUNT(*) FROM ACCOUNTS.SANFS s WHERE s.TYPE_NO = t.TYPE_NO) AS ITEMS
                          FROM ACCOUNTS.TYPES t
                          LEFT JOIN ACCOUNTS.GROUP1 g ON g.GROUP_NO = t.GROUP_NO
                         ORDER BY t.GROUP_NO, t.TYPE_NO',
            'categories' => 'SELECT c.SEQ AS ID, c.CATEGORY AS NAME, c.CAT_NO, c.CAT_NAME, c.ACTIVE,
                                    (SELECT COUNT(*) FROM ACCOUNTS.SANFS s WHERE s.CAT_NO = c.CAT_NO) AS ITEMS
                               FROM ACCOUNTS.CATEGORY c ORDER BY c.CAT_NO',
            default => 'SELECT u.UNIT_NO AS ID, u.UNIT_NAME AS NAME,
                               (SELECT COUNT(*) FROM ACCOUNTS.SANFS s WHERE s.UNIT_NO = u.UNIT_NO) AS ITEMS
                          FROM ACCOUNTS.UNITS u ORDER BY u.UNIT_NO',
        };
    }

    /** إنشاء مجموعة رئيسية / create a main group */
    private function createGroup(string $name): int|JsonResponse
    {
        if ($this->existsBy('SELECT COUNT(*) AS C FROM ACCOUNTS.GROUP1 WHERE GROUP_NAME = :name', $name)) {
            return $this->duplicate($name);
        }

        $id = StorePolicy::nextNumber('SEQ_STORES_GROUP');

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.GROUP1 (GROUP_NO, GROUP_NAME, ACTIVE, TYPE_LENGTH)
             VALUES (:id, :name, 1, 3)',
            ['id' => $id, 'name' => $name]
        );

        return $id;
    }

    /** إنشاء مجموعة فرعية (نوع) / create a sub group (type) */
    private function createType(Request $request, string $name): int|JsonResponse
    {
        $groupNo = (int) ($request->input('group_no') ?? 0);

        if ($groupNo <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'المجموعة الرئيسية مطلوبة / The main group is required.',
                'errors' => ['group_no' => ['المجموعة الرئيسية مطلوبة / The main group is required.']],
            ], 422);
        }

        if ($this->existsBy('SELECT COUNT(*) AS C FROM ACCOUNTS.TYPES WHERE TYPE_NAME = :name', $name)) {
            return $this->duplicate($name);
        }

        $id = StorePolicy::nextNumber('SEQ_STORES_TYPE');

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.TYPES (TYPE_NO, TYPE_NAME, GROUP_NO, TYPE_CODE, ACTIVE)
             VALUES (:id, :name, :group_no, :code, 1)',
            [
                'id' => $id,
                'name' => $name,
                'group_no' => $groupNo,
                // TYPE_CODE وصفي فقط: لا تعتمد عليه أي JOIN في الـ VIEWs (الربط بـ TYPE_NO)،
                // فلا حاجة لاختلاق تسلسل داخلي للمجموعة.
                // TYPE_CODE is descriptive only: no legacy view joins on it (they use TYPE_NO),
                // so no invented per-group serial is needed.
                'code' => (string) $id,
            ]
        );

        return $id;
    }

    /** إنشاء فئة / create a category */
    private function createCategory(string $name): int|JsonResponse
    {
        if ($this->existsBy('SELECT COUNT(*) AS C FROM ACCOUNTS.CATEGORY WHERE CATEGORY = :name', $name)) {
            return $this->duplicate($name);
        }

        $id = StorePolicy::nextNumber('SEQ_STORES_CAT');

        // CAT_NO هو الرقم الذي تحمله الأصناف، وSEQ هو المفتاح الرئيسي
        // CAT_NO is the number items carry, SEQ is the primary key.
        $maxRow = (array) DB::connection('oracle')
            ->selectOne('SELECT NVL(MAX(CAT_NO), 0) AS M FROM ACCOUNTS.CATEGORY');
        $catNo = (int) (reset($maxRow) ?: 0) + 1;

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.CATEGORY (SEQ, CATEGORY, CAT_NO, CAT_NAME, ACTIVE, CAT_TYPE)
             VALUES (:seq, :name, :cat_no, :name, 1, 1)',
            ['seq' => $id, 'name' => $name, 'cat_no' => $catNo]
        );

        return $id;
    }

    /** إنشاء وحدة / create a unit */
    private function createUnit(string $name): int|JsonResponse
    {
        if ($this->existsBy('SELECT COUNT(*) AS C FROM ACCOUNTS.UNITS WHERE UNIT_NAME = :name', $name)) {
            return $this->duplicate($name);
        }

        $id = StorePolicy::nextNumber('SEQ_STORES_UNIT');

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.UNITS (UNIT_NO, UNIT_NAME) VALUES (:id, :name)',
            ['id' => $id, 'name' => $name]
        );

        return $id;
    }

    /**
     * عدد السجلات التي تشير إلى العنصر
     * How many records reference the entry.
     */
    private function usageOf(string $kind, int $id): int
    {
        [$sql, $binds] = match ($kind) {
            'groups' => [
                'SELECT (SELECT COUNT(*) FROM ACCOUNTS.TYPES WHERE GROUP_NO = :id)
                      + (SELECT COUNT(*) FROM ACCOUNTS.SANFS WHERE GROUPNO = :id2) AS C FROM DUAL',
                ['id' => $id, 'id2' => $id],
            ],
            'types' => ['SELECT COUNT(*) AS C FROM ACCOUNTS.SANFS WHERE TYPE_NO = :id', ['id' => $id]],
            'categories' => ['SELECT COUNT(*) AS C FROM ACCOUNTS.SANFS WHERE CAT_NO = :id', ['id' => $id]],
            default => ['SELECT COUNT(*) AS C FROM ACCOUNTS.SANFS WHERE UNIT_NO = :id', ['id' => $id]],
        };

        $row = (array) DB::connection('oracle')->selectOne($sql, $binds);

        return (int) (reset($row) ?: 0);
    }

    /** هل يوجد صف بهذا الاسم؟ / does a row carry this name? */
    private function existsBy(string $sql, string $name): bool
    {
        $row = (array) DB::connection('oracle')->selectOne($sql, ['name' => $name]);

        return (int) (reset($row) ?: 0) > 0;
    }

    private function label(string $kind): string
    {
        return match ($kind) {
            'groups' => 'المجموعات الرئيسية',
            'types' => 'المجموعات الفرعية',
            'categories' => 'الفئات',
            default => 'الوحدات',
        };
    }

    private function unknownKind(string $kind): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "نوع المرجعية [{$kind}] غير معروف / Unknown catalog kind [{$kind}].",
        ], 404);
    }

    private function duplicate(string $name): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'الاسم مستخدم مسبقاً — الأسماء فريدة في مخطط النظام القديم '
                .'/ The name is already used; names are unique in the legacy schema.',
            'errors' => ['name' => [$name]],
        ], 422);
    }

    private function notFound(string $kind, int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "العنصر رقم [{$id}] غير موجود في ".$this->label($kind).' / Entry ['.$id.'] not found.',
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

    private function clip(?string $value, int $bytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= $bytes ? $value : substr($value, 0, $bytes);
    }
}
