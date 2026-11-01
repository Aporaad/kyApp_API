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
 * كشف الجرد — عدّ حيّ بلا حفظ وبلا أثر على الأرصدة
 * The stocktake sheet — a live count with no persistence and no effect on balances.
 *
 * ── قرار المستخدم (D9 + D13): لا جدول جديد ولا ترحيل.
 *    لهذا لا يوجد هنا أي INSERT/UPDATE/DELETE إطلاقاً: الشاشة تجلب رصيد النظام من `AVVG`،
 *    وتُدخل الكميات الفعلية في ذاكرة الواجهة، وتطبع/تصدّر النتيجة فقط.
 * ── User decision (D9 + D13): no new table and no posting. This controller therefore
 *    contains no write statement at all — it reads system quantities from AVVG, the client
 *    keeps counted quantities in memory, and the result is printed/exported only.
 *
 * ── لماذا لا نستخدم GARD؟ لأن `GARD_TYPE = 6` مُدرج فعلياً في `ITEM_MOVE_D` كحركة
 *    موجبة، فأي جرد يُكتب هناك يُضاف إلى الرصيد بدل أن يطابقه.
 * ── Why not GARD? GARD_TYPE = 6 is already folded into ITEM_MOVE_D as a positive movement,
 *    so writing a stocktake there adds to the balance instead of reconciling it.
 */
class StockSheetController extends Controller
{
    private const MAX_LIMIT = 5000;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * سطور كشف الجرد: صنف × مخزن مع رصيد النظام وتكلفته وقيمته
     * Stocktake lines: item × store with the system quantity, cost and value.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? 3000), self::MAX_LIMIT));

        $where = ['1 = 1'];
        $binds = ['limit' => $limit];

        $storeNos = $this->intList($request->input('store_no'));

        if ($storeNos !== []) {
            $placeholders = [];

            foreach ($storeNos as $i => $storeNo) {
                $binds['st'.$i] = $storeNo;
                $placeholders[] = ':st'.$i;
            }

            $where[] = 'INV_ID IN ('.implode(',', $placeholders).')';
        }

        if (($groupNo = $this->intOrNull($request->input('group_no'))) !== null) {
            $where[] = 'GROUPNO = :group_no';
            $binds['group_no'] = $groupNo;
        }

        $search = trim((string) ($request->input('search') ?? ''));

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);
            $where[] = '(ITEM_NAME LIKE :name_ar OR ITEM_NAME LIKE :name_utf OR PART_NO LIKE :part)';
            $binds['name_ar'] = '%'.$oracle.'%';
            $binds['name_utf'] = '%'.$search.'%';
            $binds['part'] = '%'.$search.'%';
        }

        $whereSql = implode(' AND ', $where);

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT I_CODE, MAX(PART_NO) AS PART_NO, MAX(ITEM_NAME) AS ITEM_NAME,
                           MAX(UOM_CODE) AS UOM_CODE, INV_ID, MAX(INVNAME) AS INVNAME,
                           SUM(SUM_TP) AS SYSTEM_QTY, MAX(STK_COST) AS COST,
                           SUM(SUM_PRICE) AS SYSTEM_VALUE,
                           MAX(GROUP_NAME) AS GROUP_NAME, MAX(TYPE_NAME) AS TYPE_NAME,
                           MAX(CAT) AS CAT
                      FROM ACCOUNTS.AVVG
                     WHERE '.$whereSql.'
                     GROUP BY I_CODE, INV_ID
                     -- الترتيب بالأسماء المستعارة لا بـ MAX(...): تكرار MAX داخل الغلاف
                     -- المحاط بـ ROWNUM يجعل أوراكل تُداخل التجميع فترمي ORA-00935.
                     -- Order by the aliases, never a repeated MAX(...): inside a ROWNUM wrapper
                     -- Oracle nests the aggregates and raises ORA-00935.
                     ORDER BY INVNAME, ITEM_NAME
                 ) WHERE ROWNUM <= :limit',
                $binds
            );

            $clean = array_map(
                static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                $rows
            );

            // أسماء المخازن المختارة لتُطبع في ترويسة الكشف
            // The selected store names, printed on the sheet header.
            $storeNames = $this->storeNames($storeNos);

            return response()->json([
                'success' => true,
                'message' => 'كشف جرد استرشادي — لا يُرحَّل ولا يغيّر الأرصدة / '
                    .'An advisory counting sheet: nothing is posted and no balance changes.',
                'data' => $clean,
                'meta' => [
                    'total' => count($clean),
                    'truncated' => count($clean) >= $limit,
                    'stores' => $storeNames,
                    'date' => $this->dateOrNull($request->input('date')) ?? StorePolicy::dbToday(),
                    'qty' => array_sum(array_map(static fn (array $r): float => (float) ($r['SYSTEM_QTY'] ?? 0), $clean)),
                    'value' => array_sum(array_map(static fn (array $r): float => (float) ($r['SYSTEM_VALUE'] ?? 0), $clean)),
                    'postable' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب كشف الجرد / Failed to build the stocktake sheet.', $e);
        }
    }

    /**
     * تسجيل حدث طباعة/تصدير الكشف — سجل نشاط فقط، بلا أي كتابة مخزنية
     * Record a print/export event — an activity row only, never an inventory write.
     */
    public function logAction(Request $request): JsonResponse
    {
        $action = (string) ($request->input('action') ?? 'print');
        $code = $action === 'export' ? 'stores.stocktake_exported' : 'stores.stocktake_printed';

        $this->activity->success($code, [
            'module_code' => 'stores',
            'entity_type' => 'stocktake_sheet',
            'entity_id' => (string) ($request->input('date') ?? ''),
            'description_ar' => 'طباعة/تصدير كشف جرد — المخازن: '
                .(string) ($request->input('stores') ?? 'الكل'),
        ], $request);

        return response()->json(['success' => true]);
    }

    /**
     * أسماء المخازن المختارة (أو كل المخازن عند عدم التحديد)
     * The selected store names, or every store when none were chosen.
     *
     * @param  array<int, int>  $storeNos
     * @return array<int, array<string, mixed>>
     */
    private function storeNames(array $storeNos): array
    {
        $binds = [];
        $sql = 'SELECT STORE_NO, STORE_NAME FROM ACCOUNTS.STORES';

        if ($storeNos !== []) {
            $placeholders = [];

            foreach ($storeNos as $i => $storeNo) {
                $binds['st'.$i] = $storeNo;
                $placeholders[] = ':st'.$i;
            }

            $sql .= ' WHERE STORE_NO IN ('.implode(',', $placeholders).')';
        }

        $sql .= ' ORDER BY STORE_NO';

        return array_map(
            static fn ($row): array => ArabicEncoding::cleanData((array) $row),
            DB::connection('oracle')->select($sql, $binds)
        );
    }

    /**
     * تحويل قائمة نصية إلى أرقام فريدة
     * Turn a comma-separated string into unique integers.
     *
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 'all') {
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

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' || $value === 'all' ? null : (int) $value;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
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
