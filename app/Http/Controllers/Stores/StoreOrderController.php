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
 * الأساس المشترك لأوامر التوريد والصرف — مستندان بنفس البنية (رقم + سنة)
 * Shared base for receive and issue orders — both documents are keyed by (BILL_NO, YEAR).
 *
 * ── قرارات مثبتة في هذا الملف:
 *   D1: كل عملية (إنشاء/تعديل/حذف) داخل معاملة أوراكل واحدة.
 *   D3: الحذف **فيزيائي** لأن `ITEM_MOVE_D` لا يقرأ `FLAG_CANCEL` — صفٌّ مُلغى يظل يحرّك
 *       الرصيد. الحذف الفيزيائي هو الطريقة الوحيدة لجعل الرقم يعود لحالته الصحيحة.
 *   D4: المجاميع تُحسب من السطور على الخادم؛ لا يُقبل أي مبلغ من العميل.
 *
 * ── Pinned decisions: every operation runs inside one Oracle transaction; deletion is
 *    physical because the legacy view ignores FLAG_CANCEL; totals are recomputed server-side.
 */
abstract class StoreOrderController extends Controller
{
    private const MAX_LIMIT = 200;

    private const DEFAULT_LIMIT = 50;

    public function __construct(private readonly ActivityLogger $activity) {}

    /** نوع المستند كما في StorePolicy / the document kind understood by StorePolicy */
    abstract protected function kind(): string;

    /** بادئة أكواد الصلاحيات: receiving · issuing / permission prefix */
    abstract protected function permissionBase(): string;

    /** عمود الطرف في القائمة / the party select fragment */
    abstract protected function partySelect(): string;

    /**
     * أعمدة الطرف في الإدراج (مورد للتوريد، عميل للصرف)
     * The party columns of the insert statement.
     *
     * @param  array<string, mixed>  $payload
     * @return array{no: mixed, name: mixed}
     */
    abstract protected function partyBindings(array $payload): array;

    /** اسم الجدول الرئيسي / master table name (schema-qualified) */
    protected function master(): string
    {
        return (string) $this->document()['master'];
    }

    /** اسم جدول السطور / details table name (schema-qualified) */
    protected function details(): string
    {
        return (string) $this->document()['details'];
    }

    /**
     * تعريف المستند الحالي من StorePolicy
     * The current document definition from StorePolicy.
     *
     * @return array<string, mixed>
     */
    protected function document(): array
    {
        return (array) StorePolicy::document($this->kind());
    }

    /**
     * قائمة المستندات مع البحث والتصفية والترقيم
     * List documents with search, filters and pagination.
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

        $where = ['1 = 1'];
        $binds = [];

        if ($storeNo !== null && $storeNo !== '' && $storeNo !== 'all') {
            $where[] = 'm.STORE_NO = :store_no';
            $binds['store_no'] = (int) $storeNo;
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
            // العربية مخزّنة بايتات CP1256: نبحث بالصيغتين معاً
            // Arabic is stored as CP1256 bytes: search in both byte forms together.
            $oracle = ArabicEncoding::toOracleSearchChars($search);

            if (is_numeric($search)) {
                $where[] = '(m.BILL_NO = :bill_no OR '.$this->partyColumn().' LIKE :party_like_num)';
                $binds['bill_no'] = (int) $search;
                $binds['party_like_num'] = '%'.$search.'%';
            } else {
                $where[] = '('.$this->partyColumn().' LIKE :party_ar OR '.$this->partyColumn().' LIKE :party_utf
                            OR m.DETAILES LIKE :desc_ar OR m.DETAILES LIKE :desc_utf)';
                $binds['party_ar'] = '%'.$oracle.'%';
                $binds['party_utf'] = '%'.$search.'%';
                $binds['desc_ar'] = '%'.$oracle.'%';
                $binds['desc_utf'] = '%'.$search.'%';
            }
        }

        $whereSql = implode(' AND ', $where);

        try {
            $countRow = (array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS TOTAL FROM '.$this->master().' m WHERE '.$whereSql,
                $binds
            );
            $total = (int) (reset($countRow) ?: 0);

            $pagedBinds = $binds;
            $pagedBinds['offset'] = $offset;
            $pagedBinds['limit'] = $limit;

            // الترقيم اليدوي عبر ROWNUM لأن Oracle 11g لا يدعم OFFSET/FETCH.
            // الإزاحة تُربط مرتين بمفتاحين مختلفين: بعض منتقي OCI8 لا يتعامل مع العلامة
            // المتكررة في موضعين.
            // Manual ROWNUM pagination: Oracle 11g has no OFFSET/FETCH. The offset is bound
            // twice under two names because OCI8 does not reliably reuse one named placeholder.
            $pagedBinds['offset2'] = $offset;

            $sql = 'SELECT * FROM (
                        SELECT q.*, ROWNUM AS RN FROM (
                            SELECT m.BILL_NO, m.YEAR, m.DAT, m.BILL_TYPE, m.STORE_NO,
                                   s.STORE_NAME, '.$this->partySelect().',
                                   m.SANF_NUMS, m.TOTAL, m.DISCOUNT, m.NET, m.DETAILES,
                                   m.CREATED_ON, m.CREATED_BY_NAME, m.FLAG_POST,
                                   (SELECT COUNT(*) FROM '.$this->details().' d
                                     WHERE d.BILL_NO = m.BILL_NO AND d.YEAR = m.YEAR) AS LINES
                              FROM '.$this->master().' m
                              LEFT JOIN ACCOUNTS.STORES s ON s.STORE_NO = m.STORE_NO
                             WHERE '.$whereSql.'
                             ORDER BY '.$orderBy.' '.strtoupper($dir).', m.BILL_NO '.strtoupper($dir).'
                        ) q WHERE ROWNUM <= :offset2 + :limit
                    ) WHERE RN > :offset';

            $rows = DB::connection('oracle')->select($sql, $pagedBinds);

            $rows = array_map(
                static fn ($row): array => ArabicEncoding::cleanData((array) $row),
                $rows
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب المستندات بنجاح / Documents retrieved.',
                'data' => $rows,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب المستندات / Failed to retrieve documents.', $e);
        }
    }

    /**
     * مستند واحد برأسه وسطوره
     * A single document with its header and lines.
     */
    public function show(Request $request, int $billNo): JsonResponse
    {
        try {
            $header = $this->fetchHeader($billNo);

            if ($header === null) {
                return response()->json([
                    'success' => false,
                    'message' => "المستند رقم [{$billNo}] غير موجود / Document [{$billNo}] not found.",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'header' => ArabicEncoding::cleanData($header),
                    'lines' => $this->fetchLines($billNo, (int) $header['YEAR']),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر جلب المستند / Failed to retrieve the document.', $e);
        }
    }

    /**
     * إنشاء أمر جديد
     * Create a new order.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $storeNo = (int) $payload['store_no'];

        if (! StorePolicy::isStockStore($storeNo)) {
            return $this->archivedStoreResponse($storeNo);
        }

        $guard = InventoryLineGuard::normalize(
            (array) $payload['lines'],
            $this->kind(),
            $storeNo,
            (bool) ($payload['allow_negative'] ?? false) && $this->canOverrideStock($request)
        );

        if (! $guard['ok']) {
            return response()->json([
                'success' => false,
                'message' => $guard['message'],
                'errors' => $guard['errors'],
            ], 422);
        }

        $lines = $guard['lines'];
        $totals = $this->totalsOf($lines, (float) ($payload['discount'] ?? 0));

        try {
            $billNo = DB::connection('oracle')->transaction(function () use ($request, $payload, $lines, $totals, $storeNo): int {
                $year = StorePolicy::dbYear();
                $number = StorePolicy::nextNumber((string) $this->document()['sequence']);

                $this->insertHeader($request, $payload, $lines, $totals, $storeNo, $number, $year);
                $this->insertLines($lines, $number, $year, (int) ($payload['cur_no'] ?? 1), (float) ($payload['cur_price'] ?? 1));

                return $number;
            });

            $this->activity->success('stores.'.$this->permissionBase().'_created', [
                'module_code' => 'stores',
                'entity_type' => $this->kind(),
                'entity_id' => (string) $billNo,
                'description_ar' => 'إنشاء مستند '.$this->documentLabel().' رقم '.$billNo
                    .' — المخزن '.$storeNo.' — الصافي '.number_format($totals['net'], 2),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ المستند بنجاح / The document was saved.',
                'data' => ['bill_no' => $billNo],
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حفظ المستند / Failed to save the document.', $e);
        }
    }

    /**
     * تعديل مستند قائم — استبدال ذري للسطور وإعادة حساب المجاميع
     * Update an existing document — atomic line replacement with recomputed totals.
     */
    public function update(Request $request, int $billNo): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $storeNo = (int) $payload['store_no'];

        if (! StorePolicy::isStockStore($storeNo)) {
            return $this->archivedStoreResponse($storeNo);
        }

        $header = $this->fetchHeader($billNo);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => "المستند رقم [{$billNo}] غير موجود / Document [{$billNo}] not found.",
            ], 404);
        }

        // الرصيد المتاح يجب أن يُقاس بعد استبعاد كميات هذا المستند نفسه، وإلّا رفض
        // التعديلُ الذي لا يغيّر شيئاً. نضيف كمياته إلى المتاح قبل الفحص.
        // The available stock must exclude this document's own lines, otherwise a no-op edit
        // would be refused. Its quantities are added back before the check.
        $guard = InventoryLineGuard::normalize(
            (array) $payload['lines'],
            $this->kind(),
            $storeNo,
            (bool) ($payload['allow_negative'] ?? false) && $this->canOverrideStock($request)
        );

        if (! $guard['ok'] && isset($guard['errors']['stock'])) {
            $guard = $this->recheckWithOwnQuantities($guard, $billNo, (int) $header['YEAR'], $storeNo, $payload);
        }

        if (! $guard['ok']) {
            return response()->json([
                'success' => false,
                'message' => $guard['message'],
                'errors' => $guard['errors'],
            ], 422);
        }

        $lines = $guard['lines'];
        $totals = $this->totalsOf($lines, (float) ($payload['discount'] ?? 0));
        $year = (int) $header['YEAR'];

        try {
            DB::connection('oracle')->transaction(function () use ($request, $payload, $lines, $totals, $storeNo, $billNo, $year): void {
                $this->insertHeader($request, $payload, $lines, $totals, $storeNo, $billNo, $year, update: true);
                $this->deleteLines($billNo, $year);
                $this->insertLines($lines, $billNo, $year, (int) ($payload['cur_no'] ?? 1), (float) ($payload['cur_price'] ?? 1));
            });

            $this->activity->success('stores.'.$this->permissionBase().'_updated', [
                'module_code' => 'stores',
                'entity_type' => $this->kind(),
                'entity_id' => (string) $billNo,
                'description_ar' => 'تعديل مستند '.$this->documentLabel().' رقم '.$billNo
                    .' — المخزن '.$storeNo.' — الصافي '.number_format($totals['net'], 2),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل المستند بنجاح / The document was updated.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر تعديل المستند / Failed to update the document.', $e);
        }
    }

    /**
     * حذف مستند — حذف فيزيائي مع تسجيل تدقيق كامل قبله
     * Delete a document — physical delete preceded by a full audit record.
     */
    public function destroy(Request $request, int $billNo): JsonResponse
    {
        $header = $this->fetchHeader($billNo);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => "المستند رقم [{$billNo}] غير موجود / Document [{$billNo}] not found.",
            ], 404);
        }

        $year = (int) $header['YEAR'];

        try {
            DB::connection('oracle')->transaction(function () use ($billNo, $year): void {
                $this->deleteLines($billNo, $year);
                DB::connection('oracle')->delete(
                    'DELETE FROM '.$this->master().' WHERE BILL_NO = :bill_no AND YEAR = :year',
                    ['bill_no' => $billNo, 'year' => $year]
                );
            });

            // السجل بعد نجاح المعاملة لا قبلها: لا نريد تدقيق حذفٍ لم يحدث
            // Logged after the transaction succeeds, never before: no audit of a delete
            // that did not happen.
            $this->activity->success('stores.'.$this->permissionBase().'_deleted', [
                'module_code' => 'stores',
                'entity_type' => $this->kind(),
                'entity_id' => (string) $billNo,
                'description_ar' => 'حذف مستند '.$this->documentLabel().' رقم '.$billNo
                    .' — المخزن '.(int) $header['STORE_NO']
                    .' — الصافي '.number_format((float) $header['NET'], 2)
                    .' — السطور '.count($this->fetchLines($billNo, $year)),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف المستند بنجاح / The document was deleted.',
            ]);
        } catch (\Throwable $e) {
            return $this->failure('تعذر حذف المستند / Failed to delete the document.', $e);
        }
    }

    /**
     * فحص وتطبيع حمولة الطلب المشتركة
     * Validate and normalize the shared request payload.
     *
     * @return array<string, mixed>|JsonResponse
     */
    protected function validatedPayload(Request $request): array|JsonResponse
    {
        $rules = [
            'store_no' => ['required', 'integer', 'min:1'],
            'dat' => ['nullable', 'date_format:Y-m-d'],
            'party_name' => ['required', 'string', 'max:80'],
            'party_no' => ['nullable', 'integer'],
            'detailes' => ['nullable', 'string', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'cur_no' => ['nullable', 'integer'],
            'cur_price' => ['nullable', 'numeric', 'min:0'],
            'allow_negative' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sanf_no' => ['required', 'integer', 'min:1'],
            'lines.*.sanf_dno' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
        ];

        $validator = validator($request->all(), $rules, [
            'store_no.required' => 'المخزن مطلوب / A store is required.',
            'party_name.required' => 'اسم الطرف مطلوب / The party name is required.',
            'lines.required' => 'يجب إضافة سطر واحد على الأقل / At least one line is required.',
            'lines.*.sanf_dno.required' => 'الصنف الفرعي مطلوب / The sub item is required.',
            'lines.*.qty.gt' => 'الكمية يجب أن تكون أكبر من صفر / Quantity must be greater than zero.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المستند غير صالحة / The document payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $payload['dat'] = $this->dateOrNull($payload['dat'] ?? null) ?? StorePolicy::dbToday();

        return $payload;
    }

    /**
     * إدراج أو تعديل رأس المستند
     * Insert or update the document header.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, float>  $totals
     */
    protected function insertHeader(
        Request $request,
        array $payload,
        array $lines,
        array $totals,
        int $storeNo,
        int $billNo,
        int $year,
        bool $update = false
    ): void {
        $party = $this->partyBindings($payload);
        $detailes = trim((string) ($payload['detailes'] ?? ''));
        $detailes = $detailes !== '' ? $detailes : $this->documentLabel();

        $binds = [
            'bill_no' => $billNo,
            'year' => $year,
            'bill_type' => (int) $this->document()['bill_type'],
            'dat' => $payload['dat'],
            'party_no' => $party['no'],
            'party_name' => $party['name'],
            'total' => $totals['total'],
            'discount' => $totals['discount'],
            'net' => $totals['net'],
            'cur_no' => (int) ($payload['cur_no'] ?? 1),
            'cur_price' => (float) ($payload['cur_price'] ?? 1),
            'sanf_nums' => count($lines),
            'detailes' => $this->clip(ArabicEncoding::toOracleSearchChars($detailes) ?? $detailes, 100),
            'store_no' => $storeNo,
            'tr_type_id' => (int) $this->document()['tr_type_id'],
        ];

        if ($update) {
            DB::connection('oracle')->update(
                'UPDATE '.$this->master().'
                    SET DAT = TO_DATE(:dat, \'YYYY-MM-DD\'),
                        '.$this->partyNoColumn().' = :party_no,
                        '.$this->partyNameColumn().' = :party_name,
                        TOTAL = :total,
                        DISCOUNT = :discount,
                        NET = :net,
                        CUR_NO = :cur_no,
                        CUR_PRICE = :cur_price,
                        SANF_NUMS = :sanf_nums,
                        DETAILES = :detailes,
                        STORE_NO = :store_no,
                        LAST_UPDATED_BY = :user_id,
                        LAST_UPDATED_ON = SYSDATE,
                        LAST_UPDATED_BY_NAME = :user_name,
                        LAST_UPDATE_TIME = TO_CHAR(SYSDATE, \'HH24:MI:SS\')
                  WHERE BILL_NO = :bill_no AND YEAR = :year',
                [
                    ...$binds,
                    'user_id' => $request->user()?->user_id,
                    'user_name' => $this->clip($this->userName($request), 100),
                ]
            );

            return;
        }

        DB::connection('oracle')->insert(
            'INSERT INTO '.$this->master().'
                (BILL_NO, YEAR, BILL_TYPE, DAT, '.$this->partyNoColumn().', '.$this->partyNameColumn().',
                 TOTAL, CUR_NO, CUR_PRICE, SANF_NUMS, DETAILES, STORE_NO, DIS_RATE, DISCOUNT, NET,
                 CUR_NO1, CUR_PRICE1, SELECT1, CREATED_ON, CREATED_BY, CREATED_BY_NAME, THE_TIME,
                 BRANCH_NO, TR_TYPE_ID, FLAG_POST, FLAG_CANCEL)
             VALUES
                (:bill_no, :year, :bill_type, TO_DATE(:dat, \'YYYY-MM-DD\'), :party_no, :party_name,
                 :total, :cur_no, :cur_price, :sanf_nums, :detailes, :store_no, 0, :discount, :net,
                 :cur_no, :cur_price, 0, SYSDATE, :user_id, :user_name,
                 TO_CHAR(SYSDATE, \'HH24:MI:SS\'), :branch_no, :tr_type_id, 0, 0)',
            [
                ...$binds,
                'user_id' => $request->user()?->user_id,
                'user_name' => $this->clip($this->userName($request), 100),
                'branch_no' => $this->branchNo($request),
            ]
        );
    }

    /**
     * إدراج سطور المستند
     * Insert the document lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function insertLines(array $lines, int $billNo, int $year, int $curNo, float $curPrice): void
    {
        foreach ($lines as $index => $line) {
            DB::connection('oracle')->insert(
                'INSERT INTO '.$this->details().'
                    (BILL_NO, YEAR, SANF_NO, SANF_DNO, SANF_PRICE, QTY, CAP, TOTAL, PCS,
                     COLOR_NO, STK_COST, SANF_COST, CURNO, CURPRICE, SEQ)
                 VALUES
                    (:bill_no, :year, :sanf_no, :sanf_dno, :price, :qty, :cap, :total, 0,
                     :color_no, :stk_cost, :sanf_cost, :cur_no, :cur_price, :seq)',
                [
                    'bill_no' => $billNo,
                    'year' => $year,
                    'sanf_no' => $line['sanf_no'],
                    'sanf_dno' => $line['sanf_dno'],
                    // SANF_PRICE وTOTAL وCAP أعمدة إلزامية في المخطط القديم
                    // SANF_PRICE, TOTAL and CAP are NOT NULL in the legacy schema.
                    'price' => $line['price'],
                    'qty' => $line['qty'],
                    'cap' => $line['cap'],
                    'total' => $line['total'],
                    'color_no' => $line['color_no'],
                    'stk_cost' => $line['price'],
                    'sanf_cost' => $line['price'],
                    'cur_no' => $curNo,
                    'cur_price' => $curPrice,
                    'seq' => $index + 1,
                ]
            );
        }
    }

    /**
     * حذف سطور مستند
     * Delete a document's lines.
     */
    protected function deleteLines(int $billNo, int $year): void
    {
        DB::connection('oracle')->delete(
            'DELETE FROM '.$this->details().' WHERE BILL_NO = :bill_no AND YEAR = :year',
            ['bill_no' => $billNo, 'year' => $year]
        );
    }

    /**
     * رأس المستند من قاعدة البيانات
     * The document header from the database.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchHeader(int $billNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT m.BILL_NO, m.YEAR, m.DAT, m.BILL_TYPE, m.STORE_NO, s.STORE_NAME,
                    '.$this->partySelect().', m.SANF_NUMS, m.TOTAL, m.DISCOUNT, m.NET,
                    m.DETAILES, m.CUR_NO, m.CUR_PRICE, m.CREATED_ON, m.CREATED_BY_NAME
               FROM '.$this->master().' m
               LEFT JOIN ACCOUNTS.STORES s ON s.STORE_NO = m.STORE_NO
              WHERE m.BILL_NO = :bill_no',
            ['bill_no' => $billNo]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * سطور المستند مع اسم الصنف ووحدته
     * The document lines with item name and unit.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchLines(int $billNo, int $year): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT d.SANF_NO, d.SANF_DNO, d.QTY, d.SANF_PRICE, d.CAP, d.TOTAL, d.SEQ,
                    s.SANF_NAME, s.PART_NO, u.UNIT_NAME
               FROM '.$this->details().' d
               LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
               LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = s.UNIT_NO
              WHERE d.BILL_NO = :bill_no AND d.YEAR = :year
              ORDER BY d.SEQ NULLS LAST, d.SANF_NO',
            ['bill_no' => $billNo, 'year' => $year]
        );

        return array_map(
            static fn ($row): array => ArabicEncoding::cleanData((array) $row),
            $rows
        );
    }

    /**
     * المجاميع من السطور — لا يُقبل مبلغ من العميل
     * Totals derived from the lines — never taken from the client.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{total: float, discount: float, net: float}
     */
    protected function totalsOf(array $lines, float $discount): array
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $total += (float) $line['total'];
        }

        $total = round($total, 2);
        $discount = max(0.0, min(round($discount, 2), $total));

        return ['total' => $total, 'discount' => $discount, 'net' => round($total - $discount, 2)];
    }

    /**
     * إعادة فحص الرصيد بإضافة كميات المستند نفسه (للتعديل)
     * Re-run the stock check adding this document's own quantities back (for updates).
     *
     * @param  array<string, mixed>  $guard
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recheckWithOwnQuantities(array $guard, int $billNo, int $year, int $storeNo, array $payload): array
    {
        $own = [];

        foreach ($this->fetchLines($billNo, $year) as $line) {
            $sanfNo = (int) $line['SANF_NO'];
            $own[$sanfNo] = ($own[$sanfNo] ?? 0) + (float) $line['QTY'];
        }

        $needed = [];

        foreach ((array) $payload['lines'] as $line) {
            $sanfNo = (int) $line['sanf_no'];
            $needed[$sanfNo] = ($needed[$sanfNo] ?? 0) + (float) $line['qty'];
        }

        $available = InventoryLineGuard::availableQuantities($storeNo, array_keys($needed));
        $shortages = [];

        foreach ($needed as $sanfNo => $qty) {
            $onHand = ($available[$sanfNo] ?? 0.0) + ($own[$sanfNo] ?? 0.0);

            if (round($onHand - $qty, 3) < 0) {
                $shortages[] = "الصنف [{$sanfNo}]: المتاح {$onHand} والمطلوب {$qty}";
            }
        }

        if ($shortages !== []) {
            return [
                'ok' => false,
                'message' => 'الكمية المطلوبة تتجاوز الرصيد المتاح / The requested quantity exceeds the available stock.',
                'errors' => ['stock' => $shortages],
                'lines' => [],
            ];
        }

        return InventoryLineGuard::normalize(
            (array) $payload['lines'],
            $this->kind(),
            $storeNo,
            true
        );
    }

    /**
     * هل يملك المستخدم منحاً شاملاً يتيح تجاوز الرصيد؟
     * Does the user hold the wildcard that allows overriding the stock check?
     */
    private function canOverrideStock(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && (bool) ($user->is_admin ?? false);
    }

    /**
     * رد المخزن الأرشيفي — سبب الرفض مكتوب للمستخدم لا مجرد رقم خطأ
     * Archived-store refusal with a reason, not a bare error code.
     */
    protected function archivedStoreResponse(int $storeNo): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "المخزن [{$storeNo}] أرشيفي ولا يدخل في حساب الأرصدة، فلا يمكن إنشاء مستند عليه "
                .'/ Store ['.$storeNo.'] is archived and excluded from balances, so no document can be created on it.',
        ], 422);
    }

    /**
     * رد خطأ موحّد
     * Uniform error response.
     */
    protected function failure(string $message, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }

    /** تاريخ صالح أو null / a valid date or null */
    protected function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }

    /** رقم الفرع من جلسة المستخدم / the branch number from the user session */
    protected function branchNo(Request $request): int
    {
        $branch = $request->user()?->branch_no;

        return is_numeric($branch) ? (int) $branch : 1;
    }

    /** اسم المستخدم للتدقيق / the acting user's name */
    protected function userName(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $name = $user->user_name_ar ?: $user->user_name_en ?: $user->user_name;

        return $name !== null ? (string) $name : null;
    }

    /** قصّ نص بحد البايتات / byte-safe clip */
    protected function clip(?string $value, int $bytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= $bytes ? $value : substr($value, 0, $bytes);
    }

    /** عمود رقم الطرف في الجدول / the party number column */
    abstract protected function partyNoColumn(): string;

    /** عمود اسم الطرف في الجدول / the party name column */
    abstract protected function partyNameColumn(): string;

    /** عمود اسم الطرف في القائمة / the party name placeholder column */
    protected function partyColumn(): string
    {
        return 'm.'.$this->partyNameColumn();
    }

    /** الاسم العربي لنوع المستند في سجل التدقيق / Arabic document label for the audit log */
    protected function documentLabel(): string
    {
        return $this->kind() === StorePolicy::KIND_RECEIVE ? 'توريد مخزني' : 'صرف مخزني';
    }
}
