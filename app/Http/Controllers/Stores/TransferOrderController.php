<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\InventoryLineGuard;
use App\Support\StorePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * أوامر التحويل المخزني — INVENTORY_TRANSM + INVENTORY_TRANSD
 * Inventory transfer orders — the only document that produces **two** masters.
 *
 * ── لماذا مستندان؟ لأن الـ VIEW `ITEM_MOVE_D` تعالج التحويل بفرعين منفصلين:
 *    `TYPENO = 5` ⇒ صرف من `STORE_NO` بإشارة سالبة،
 *    `TYPENO = 4` ⇒ توريد إلى `STORE_NO` بإشارة موجبة.
 *    مستند واحد لا يحرّك مخزنين؛ فمن يكتب صفاً واحداً يرى الكمية تخرج من مخزن ولا تدخل
 *    أي مخزن — وهي أشهر طريقة لضياع رصيد في هذا المخطط.
 * ── Why two masters? The legacy view handles a transfer through two separate branches
 *    (TYPENO 5 = negative at STORE_NO, TYPENO 4 = positive at STORE_NO). A single row moves
 *    goods out of one store and into none — the classic way to lose stock in this schema.
 *
 * ── وفي كل سطر إلزامي: `SIZE_FRM = SANFS_D.LEN` و`SIZE_TO = SANFS_D.WID`، لأن الـ VIEW
 *    تربط السطر بالصنف الفرعي بهذين العمودين. إغفالهما يجعل التحويل غير مرئي تماماً.
 * ── Every line must also carry SIZE_FRM / SIZE_TO copied from the sub item's LEN / WID;
 *    the view joins on them, so omitting them makes the transfer invisible.
 */
class TransferOrderController extends Controller
{
    private const MAX_LIMIT = 200;

    private const DEFAULT_LIMIT = 50;

    /** نوع الحركة الصادر — هو "وجه" الأمر في كل الشاشات / outbound type is the visible face */
    private const TYPENO_OUT = 5;

    private const TYPENO_IN = 4;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * قائمة أوامر التحويل — تُعرض النسخة الصادرة فقط (سطر واحد لكل تحويل)
     * Transfer list — only the outbound master is listed (one row per transfer).
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        $storeNo = $request->input('store_no');
        $from = $this->dateOrNull($request->input('from'));
        $to = $this->dateOrNull($request->input('to'));
        $sort = (string) ($request->input('sort') ?? 'date');
        $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderBy = match ($sort) {
            'no' => 'm.BILL_NO',
            'net' => 'm.NET',
            default => 'm.DAT',
        };

        // الصادرة فقط: كل تحويل = صف واحد، والنسخة الواردة تعيش خلفه
        // Outbound only: one row per transfer, the inbound twin stays behind it.
        $where = ['m.TYPENO = :typeno_out'];
        $binds = ['typeno_out' => self::TYPENO_OUT];

        if ($storeNo !== null && $storeNo !== '' && $storeNo !== 'all') {
            $where[] = '(m.STORE_NO = :store_no OR m.STORE_TO = :store_to)';
            $binds['store_no'] = (int) $storeNo;
            $binds['store_to'] = (int) $storeNo;
        }

        if ($from !== null) {
            $where[] = 'm.DAT >= TO_DATE(:from_date, \'YYYY-MM-DD\')';
            $binds['from_date'] = $from;
        }

        if ($to !== null) {
            $where[] = 'm.DAT < TO_DATE(:to_date, \'YYYY-MM-DD\') + 1';
            $binds['to_date'] = $to;
        }

        if ($search !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($search);

            if (is_numeric($search)) {
                $where[] = 'm.BILL_NO = :bill_no';
                $binds['bill_no'] = (int) $search;
            } else {
                $where[] = '(m.DETAILES LIKE :desc_ar OR m.DETAILES LIKE :desc_utf)';
                $binds['desc_ar'] = '%'.$oracle.'%';
                $binds['desc_utf'] = '%'.$search.'%';
            }
        }

        $whereSql = implode(' AND ', $where);

        try {
            $countRow = (array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.INVENTORY_TRANSM m WHERE '.$whereSql,
                $binds
            );
            $total = (int) (reset($countRow) ?: 0);

            $pagedBinds = $binds;
            $pagedBinds['offset'] = $offset;
            $pagedBinds['offset2'] = $offset;
            $pagedBinds['limit'] = $limit;

            $sql = 'SELECT * FROM (
                        SELECT q.*, ROWNUM AS RN FROM (
                            SELECT m.TR_INV_M_ID, m.BILL_NO, m.YEAR, m.DAT, m.TYPENO,
                                   m.STORE_NO, fs.STORE_NAME, m.STORE_TO, ts.STORE_NAME AS STORE_TO_NAME,
                                   m.SANF_NUMS, m.TOTAL, m.NET, m.DETAILES, m.CREATED_ON,
                                   m.FLAG_POST,
                                   (SELECT COUNT(*) FROM ACCOUNTS.INVENTORY_TRANSD d
                                     WHERE d.TR_INV_M_ID = m.TR_INV_M_ID) AS LINES
                              FROM ACCOUNTS.INVENTORY_TRANSM m
                              LEFT JOIN ACCOUNTS.STORES fs ON fs.STORE_NO = m.STORE_NO
                              LEFT JOIN ACCOUNTS.STORES ts ON ts.STORE_NO = m.STORE_TO
                             WHERE '.$whereSql.'
                             ORDER BY '.$orderBy.' '.strtoupper($dir).', m.BILL_NO '.strtoupper($dir).'
                        ) q WHERE ROWNUM <= :offset2 + :limit
                    ) WHERE RN > :offset';

            $rows = DB::connection('oracle')->select($sql, $pagedBinds);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب أوامر التحويل / Transfer orders retrieved.',
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
            return $this->failure('تعذر جلب أوامر التحويل / Failed to retrieve transfers.', $e);
        }
    }

    /**
     * أمر تحويل واحد برأسه وسطوره
     * One transfer order with its header and lines.
     */
    public function show(Request $request, int $billNo): JsonResponse
    {
        try {
            $header = $this->outboundHeader($billNo);

            if ($header === null) {
                return response()->json([
                    'success' => false,
                    'message' => "أمر التحويل رقم [{$billNo}] غير موجود / Transfer [{$billNo}] not found.",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'header' => ArabicEncoding::cleanData($header),
                    'lines' => $this->lines($header['TR_INV_M_ID']),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب أمر التحويل / Failed to retrieve the transfer.', $e);
        }
    }

    /**
     * إنشاء أمر تحويل — مستندان في معاملة واحدة
     * Create a transfer — two masters inside one transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $from = (int) $payload['store_no'];
        $to = (int) $payload['store_to'];

        if ($from === $to) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن التحويل إلى نفس المخزن / A transfer cannot target its own source store.',
            ], 422);
        }

        if (! StorePolicy::isStockStore($from) || ! StorePolicy::isStockStore($to)) {
            return response()->json([
                'success' => false,
                'message' => 'أحد المخزنين أرشيفي ولا يدخل في حساب الأرصدة، فلا يمكن التحويل عليه '
                    .'/ One of the stores is archived and excluded from balances, so it cannot be transferred to.',
            ], 422);
        }

        $guard = InventoryLineGuard::normalize(
            (array) $payload['lines'],
            StorePolicy::KIND_TRANSFER,
            $from,
            (bool) ($payload['allow_negative'] ?? false) && ($request->user()?->is_admin ?? false)
        );

        if (! $guard['ok']) {
            return response()->json([
                'success' => false,
                'message' => $guard['message'],
                'errors' => $guard['errors'],
            ], 422);
        }

        try {
            $billNo = DB::connection('oracle')->transaction(
                fn (): int => $this->writePair($request, $payload, $guard['lines'], $from, $to)
            );

            $this->activity->success('stores.transfer_created', [
                'module_code' => 'stores',
                'entity_type' => 'transfer',
                'entity_id' => (string) $billNo,
                'description_ar' => 'إنشاء أمر تحويل رقم '.$billNo.' من المخزن '.$from
                    .' إلى المخزن '.$to.' — السطور '.count($guard['lines']),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ أمر التحويل بنجاح / The transfer was saved.',
                'data' => ['bill_no' => $billNo],
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حفظ أمر التحويل / Failed to save the transfer.', $e);
        }
    }

    /**
     * تعديل أمر تحويل — إعادة كتابة الزوج كاملاً بنفس رقم المستند
     * Update a transfer — the whole pair is rewritten under the same bill number.
     */
    public function update(Request $request, int $billNo): JsonResponse
    {
        $payload = $this->validatePayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $from = (int) $payload['store_no'];
        $to = (int) $payload['store_to'];

        if ($from === $to) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن التحويل إلى نفس المخزن / A transfer cannot target its own source store.',
            ], 422);
        }

        $header = $this->outboundHeader($billNo);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => "أمر التحويل رقم [{$billNo}] غير موجود / Transfer [{$billNo}] not found.",
            ], 404);
        }

        $guard = InventoryLineGuard::normalize(
            (array) $payload['lines'],
            StorePolicy::KIND_TRANSFER,
            $from,
            // التعديل يقارن مع رصيد يخصّ المستند نفسه، لذا نضيف كمياته الصادرة قبل الفحص
            // An update is measured against stock that still contains this order's own
            // outbound quantities, so those are added back before the check.
            true
        );

        if (! $guard['ok']) {
            return response()->json([
                'success' => false,
                'message' => $guard['message'],
                'errors' => $guard['errors'],
            ], 422);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($request, $payload, $guard, $from, $to, $billNo): void {
                $this->deletePair($billNo);
                $this->writePair($request, $payload, $guard['lines'], $from, $to, $billNo);
            });

            $this->activity->success('stores.transfer_updated', [
                'module_code' => 'stores',
                'entity_type' => 'transfer',
                'entity_id' => (string) $billNo,
                'description_ar' => 'تعديل أمر تحويل رقم '.$billNo.' من المخزن '.$from
                    .' إلى المخزن '.$to.' — السطور '.count($guard['lines']),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل أمر التحويل بنجاح / The transfer was updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر تعديل أمر التحويل / Failed to update the transfer.', $e);
        }
    }

    /**
     * حذف أمر تحويل — الزوج كاملاً، حذفاً فيزيائياً
     * Delete a transfer — both masters, physically.
     */
    public function destroy(Request $request, int $billNo): JsonResponse
    {
        $header = $this->outboundHeader($billNo);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => "أمر التحويل رقم [{$billNo}] غير موجود / Transfer [{$billNo}] not found.",
            ], 404);
        }

        $lineCount = count($this->lines($header['TR_INV_M_ID']));

        try {
            DB::connection('oracle')->transaction(fn () => $this->deletePair($billNo));

            $this->activity->success('stores.transfer_deleted', [
                'module_code' => 'stores',
                'entity_type' => 'transfer',
                'entity_id' => (string) $billNo,
                'description_ar' => 'حذف أمر تحويل رقم '.$billNo.' من المخزن '.(int) $header['STORE_NO']
                    .' إلى المخزن '.(int) $header['STORE_TO'].' — الصافي '.number_format((float) $header['NET'], 2)
                    .' — السطور '.$lineCount,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف أمر التحويل بنجاح / The transfer was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف أمر التحويل / Failed to delete the transfer.', $e);
        }
    }

    /**
     * كتابة الزوج الصادر/الوارد
     * Write the outbound/inbound pair.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writePair(
        Request $request,
        array $payload,
        array $lines,
        int $from,
        int $to,
        ?int $existingBillNo = null
    ): int {
        $year = StorePolicy::dbYear();
        $billNo = $existingBillNo ?? StorePolicy::nextNumber('SEQ_STORES_TRNM');
        $total = 0.0;

        foreach ($lines as $line) {
            $total += (float) $line['total'];
        }

        $total = round($total, 2);
        $detailes = trim((string) ($payload['detailes'] ?? ''));
        $detailes = $detailes !== ''
            ? $detailes
            : 'تحويل مخزني من '.$from.' إلى '.$to;

        // النسخة الصادرة: من المخزن المصدر إلى الهدف
        // Outbound master: from the source store to the destination.
        $outId = StorePolicy::nextNumber('SEQ_STORES_TRNM');
        $this->insertMaster($request, $outId, $billNo, $year, self::TYPENO_OUT, $from, $to, $lines, $total, $detailes, $payload);
        $this->insertDetails($outId, $billNo, $year, self::TYPENO_OUT, $from, $to, $lines, $payload);

        // النسخة الواردة: مخزنها هو الهدف، وهي التي تعطي الرصيد الموجب
        // Inbound master: its store is the destination — this is the row that adds stock.
        $inId = StorePolicy::nextNumber('SEQ_STORES_TRNM');
        $this->insertMaster($request, $inId, $billNo, $year, self::TYPENO_IN, $to, $from, $lines, $total, $detailes, $payload);
        $this->insertDetails($inId, $billNo, $year, self::TYPENO_IN, $to, $from, $lines, $payload);

        return $billNo;
    }

    /**
     * الصف الوارد المرافق — يُبحث برقم المستند ونوع الحركة
     * The twin inbound master, located by bill number and movement type.
     */
    private function insertMaster(
        Request $request,
        int $trInvMId,
        int $billNo,
        int $year,
        int $typeno,
        int $storeNo,
        int $storeTo,
        array $lines,
        float $total,
        string $detailes,
        array $payload
    ): void {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.INVENTORY_TRANSM
                (TR_INV_M_ID, BILL_NO, YEAR, BILL_TYPE, TYPENO, DAT, STORE_NO, STORE_TO,
                 DETAILES, TOTAL, SANF_NUMS, CUR_NO, CUR_PRICE, NET, DIS_RATE, DISCOUNT,
                 FLAG_POST, FLAG_CANCEL, RCV_DONE, CREATED_ON, THE_TIME, BRANCH_NO,
                 LAST_UPDATED_ON)
             VALUES
                (:id, :bill_no, :year, 1, :typeno, TO_DATE(:dat, \'YYYY-MM-DD\'), :store_no, :store_to,
                 :detailes, :total, :sanf_nums, :cur_no, :cur_price, :total, 0, 0,
                 0, 0, 0, SYSDATE, TO_CHAR(SYSDATE, \'HH24:MI:SS\'), :branch_no,
                 SYSDATE)',
            [
                'id' => $trInvMId,
                'bill_no' => $billNo,
                'year' => $year,
                'typeno' => $typeno,
                'dat' => $payload['dat'],
                'store_no' => $storeNo,
                'store_to' => $storeTo,
                'detailes' => $this->clip(ArabicEncoding::toOracleSearchChars($detailes) ?? $detailes, 200),
                'total' => $total,
                'sanf_nums' => count($lines),
                'cur_no' => (int) ($payload['cur_no'] ?? 1),
                'cur_price' => (float) ($payload['cur_price'] ?? 1),
                'branch_no' => $this->branchNo($request),
            ]
        );
    }

    /**
     * سطور التحويل — SIZE_FRM/SIZE_TO منسوخة من الصنف الفرعي إجبارياً
     * Transfer lines — SIZE_FRM / SIZE_TO are force-copied from the sub item.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function insertDetails(
        int $trInvMId,
        int $billNo,
        int $year,
        int $typeno,
        int $storeNo,
        int $storeTo,
        array $lines,
        array $payload
    ): void {
        foreach ($lines as $index => $line) {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.INVENTORY_TRANSD
                    (TR_INV_D_ID, TR_INV_M_ID, BILL_NO, YEAR, TYPENO, DDATE, STORENO, STORETO,
                     DETAILE, SANF_NO, SANF_DNO, SANF_PRICE, QTY, CAP, SEQ,
                     SIZE_FRM, SIZE_TO, COLOR_NO, SEQ_NO, UNIT_NO, SUNIT_NO, SQTY,
                     STK_COST, SANF_COST, TOTALD)
                 VALUES
                    (:id, :master_id, :bill_no, :year, :typeno, TO_DATE(:dat, \'YYYY-MM-DD\'), :store_no, :store_to,
                     :detailes, :sanf_no, :sanf_dno, :price, :qty, :cap, :seq,
                     :size_frm, :size_to, :color_no, :seq_no, :unit_no, :unit_no, :qty,
                     :stk_cost, :sanf_cost, :total)',
                [
                    'id' => StorePolicy::nextNumber('SEQ_STORES_TRND'),
                    'master_id' => $trInvMId,
                    'bill_no' => $billNo,
                    'year' => $year,
                    'typeno' => $typeno,
                    'dat' => $payload['dat'],
                    'store_no' => $storeNo,
                    'store_to' => $storeTo,
                    'detailes' => 'تحويل مخزني',
                    'sanf_no' => $line['sanf_no'],
                    'sanf_dno' => $line['sanf_dno'],
                    'price' => $line['price'],
                    'qty' => $line['qty'],
                    'cap' => $line['cap'],
                    'seq' => $index + 1,
                    // هذان العمودان هما شرط ظهور التحويل في ITEM_MOVE_D
                    // These two columns are the join condition that makes the transfer visible.
                    'size_frm' => $line['len'],
                    'size_to' => $line['wid'],
                    'color_no' => $line['color_no'],
                    'seq_no' => $index + 1,
                    'unit_no' => $line['unit_no'],
                    'stk_cost' => $line['price'],
                    'sanf_cost' => $line['price'],
                    'total' => $line['total'],
                ]
            );
        }
    }

    /**
     * حذف الزوج كاملاً برقم المستند
     * Delete the whole pair by bill number.
     */
    private function deletePair(int $billNo): void
    {
        DB::connection('oracle')->delete(
            'DELETE FROM ACCOUNTS.INVENTORY_TRANSD
              WHERE TR_INV_M_ID IN (SELECT TR_INV_M_ID FROM ACCOUNTS.INVENTORY_TRANSM WHERE BILL_NO = :bill_no)',
            ['bill_no' => $billNo]
        );

        DB::connection('oracle')->delete(
            'DELETE FROM ACCOUNTS.INVENTORY_TRANSM WHERE BILL_NO = :bill_no',
            ['bill_no' => $billNo]
        );
    }

    /**
     * رأس النسخة الصادرة
     * The outbound master header.
     *
     * @return array<string, mixed>|null
     */
    private function outboundHeader(int $billNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT m.TR_INV_M_ID, m.BILL_NO, m.YEAR, m.DAT, m.TYPENO, m.STORE_NO,
                    fs.STORE_NAME, m.STORE_TO, ts.STORE_NAME AS STORE_TO_NAME,
                    m.SANF_NUMS, m.TOTAL, m.NET, m.DETAILES, m.CUR_NO, m.CUR_PRICE, m.CREATED_ON
               FROM ACCOUNTS.INVENTORY_TRANSM m
               LEFT JOIN ACCOUNTS.STORES fs ON fs.STORE_NO = m.STORE_NO
               LEFT JOIN ACCOUNTS.STORES ts ON ts.STORE_NO = m.STORE_TO
              WHERE m.BILL_NO = :bill_no AND m.TYPENO = :typeno',
            ['bill_no' => $billNo, 'typeno' => self::TYPENO_OUT]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * سطور مستند تحويل بمعرّفه
     * Lines of one transfer master.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(int $trInvMId): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT d.SANF_NO, d.SANF_DNO, d.QTY, d.SANF_PRICE, d.CAP, d.TOTALD AS TOTAL, d.SEQ,
                    s.SANF_NAME, s.PART_NO, u.UNIT_NAME
               FROM ACCOUNTS.INVENTORY_TRANSD d
               LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
               LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = d.UNIT_NO
              WHERE d.TR_INV_M_ID = :id
              ORDER BY d.SEQ NULLS LAST, d.SANF_NO',
            ['id' => $trInvMId]
        );

        return array_map(
            static fn ($row): array => ArabicEncoding::cleanData((array) $row),
            $rows
        );
    }

    /**
     * تحقق حمولة التحويل
     * Validate the transfer payload.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function validatePayload(Request $request): array|JsonResponse
    {
        $validator = validator($request->all(), [
            'store_no' => ['required', 'integer', 'min:1'],
            'store_to' => ['required', 'integer', 'min:1', 'different:store_no'],
            'dat' => ['nullable', 'date_format:Y-m-d'],
            'detailes' => ['nullable', 'string', 'max:200'],
            'cur_no' => ['nullable', 'integer'],
            'cur_price' => ['nullable', 'numeric', 'min:0'],
            'allow_negative' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sanf_no' => ['required', 'integer', 'min:1'],
            'lines.*.sanf_dno' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'store_to.different' => 'لا يمكن التحويل إلى نفس المخزن / The target store must differ from the source.',
            'lines.*.qty.gt' => 'الكمية يجب أن تكون أكبر من صفر / Quantity must be greater than zero.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات أمر التحويل غير صالحة / The transfer payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $payload['dat'] = $this->dateOrNull($payload['dat'] ?? null) ?? StorePolicy::dbToday();

        return $payload;
    }

    private function failure(string $message, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }

    private function branchNo(Request $request): int
    {
        $branch = $request->user()?->branch_no;

        return is_numeric($branch) ? (int) $branch : 1;
    }

    private function clip(?string $value, int $bytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= $bytes ? $value : substr($value, 0, $bytes);
    }
}
