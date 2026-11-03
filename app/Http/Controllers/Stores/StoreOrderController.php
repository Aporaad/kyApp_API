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
                                   '.$this->referenceSelect().'
                                   m.ENTRY_NO, m.SANF_NUMS,
                                   m.TOTAL, m.DIS_RATE, m.DISCOUNT, m.NET, m.DETAILES,
                                   m.CUR_NO, cu.CUR_NAME, m.CUR_PRICE, m.SELECT1,
                                   m.CREATED_ON, m.CREATED_BY_NAME, m.THE_TIME,
                                   m.LAST_UPDATED_ON, m.LAST_UPDATED_BY_NAME, m.LAST_UPDATE_TIME,
                                   m.FLAG_POST,
                                   (SELECT COUNT(*) FROM '.$this->details().' d
                                     WHERE d.BILL_NO = m.BILL_NO AND d.YEAR = m.YEAR) AS LINES,
                                   (SELECT SUM(d.QTY) FROM '.$this->details().' d
                                     WHERE d.BILL_NO = m.BILL_NO AND d.YEAR = m.YEAR) AS QTY
                              FROM '.$this->master().' m
                              LEFT JOIN ACCOUNTS.STORES s ON s.STORE_NO = m.STORE_NO
                              LEFT JOIN ACCOUNTS.CURRENCY cu ON cu.CUR_NO = m.CUR_NO
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
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cur_no' => ['nullable', 'integer'],
            'cur_price' => ['nullable', 'numeric', 'min:0'],
            'allow_negative' => ['nullable', 'boolean'],
            // ── حقول المستند المكتبي: نوع الفاتورة (1 نقد · 2 آجل)، رقم المرجع، الحسابات،
            //    العملة الثانية، واختيار «العملة المحلية / عملة الحساب المدين». تُقبل وتُخزَّن
            //    لأن النموذج يعرضها؛ نموذج يعرض حقلاً ثم يرميه عند الحفظ هو نموذج كاذب.
            // ── Desktop-document fields: bill type, reference no, the accounts, the second
            //    currency, and the local/ledger-currency choice. They are accepted and stored
            //    because the form shows them — a form that displays a field then drops it lies.
            'bill_type' => ['nullable', 'integer', 'in:1,2'],
            'select_one' => ['nullable', 'integer', 'in:1,2'],
            'party_bill' => ['nullable', 'string', 'max:15'],
            'acc_no' => ['nullable', 'integer'],
            'acc_no1' => ['nullable', 'integer'],
            'cur_no1' => ['nullable', 'integer'],
            'cur_price1' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sanf_no' => ['required', 'integer', 'min:1'],
            'lines.*.sanf_dno' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
            // أعمدة السطر التي يطبعها المستند المكتبي وتحتاج أن تُحفظ إن عدّلها المستخدم
            // Line columns the desktop document prints, so edits to them must persist.
            'lines.*.pcs' => ['nullable', 'numeric', 'min:0'],
            'lines.*.cap' => ['nullable', 'numeric', 'min:0'],
            'lines.*.shelf_no' => ['nullable', 'integer', 'min:0'],
            'lines.*.color_no' => ['nullable', 'integer', 'min:0'],
            'lines.*.category' => ['nullable', 'string', 'max:30'],
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

        // حساب المخزن الافتراضي من المخزن نفسه — كما يفعل النظام المكتبي تماماً: المستخدم لا
        // يختار رقم حساب، بل المخزن يجرّ حسابه، ثم يعدّله إن أراد.
        // The store's own inventory account is the default, exactly as the desktop does it: the
        // user picks a store, not an account number, and may still override it.
        $accNo = (int) ($payload['acc_no'] ?? 0) > 0
            ? (int) $payload['acc_no']
            : (int) $this->storeInventoryAccount($storeNo);

        $binds = [
            'bill_no' => $billNo,
            'year' => $year,
            'bill_type' => (int) ($payload['bill_type'] ?? $this->document()['bill_type']),
            'dat' => $payload['dat'],
            'party_no' => $party['no'],
            'party_name' => $party['name'],
            'total' => $totals['total'],
            'dis_rate' => (float) ($payload['discount_rate'] ?? 0),
            'discount' => $totals['discount'],
            'net' => $totals['net'],
            'cur_no' => (int) ($payload['cur_no'] ?? 1),
            'cur_price' => (float) ($payload['cur_price'] ?? 1),
            'cur_no1' => (int) ($payload['cur_no1'] ?? ($payload['cur_no'] ?? 1)),
            'cur_price1' => (float) ($payload['cur_price1'] ?? ($payload['cur_price'] ?? 1)),
            'select_one' => (int) ($payload['select_one'] ?? 1),
            'acc_no' => $accNo > 0 ? $accNo : null,
            'acc_no1' => (int) ($payload['acc_no1'] ?? 0) > 0 ? (int) $payload['acc_no1'] : null,
            'party_bill' => $this->clip(trim((string) ($payload['party_bill'] ?? '')), 15) ?: null,
            'sanf_nums' => count($lines),
            'detailes' => $this->clip(ArabicEncoding::toOracleSearchChars($detailes) ?? $detailes, 100),
            'store_no' => $storeNo,
            'tr_type_id' => (int) $this->document()['tr_type_id'],
        ];

        if ($update) {
            DB::connection('oracle')->update(
                $updateSql = 'UPDATE '.$this->master().'
                    SET DAT = TO_DATE(:dat, \'YYYY-MM-DD\'),
                        BILL_TYPE = :bill_type,
                        '.$this->partyNoColumn().' = :party_no,
                        '.$this->partyNameColumn().' = :party_name,
                        TOTAL = :total,
                        DIS_RATE = :dis_rate,
                        DISCOUNT = :discount,
                        NET = :net,
                        CUR_NO = :cur_no,
                        CUR_PRICE = :cur_price,
                        CUR_NO1 = :cur_no1,
                        CUR_PRICE1 = :cur_price1,
                        SELECT1 = :select_one,
                        ACC_NO = :acc_no,
                        ACC_NO1 = :acc_no1,
                        '.$this->referenceSetClause().'
                        SANF_NUMS = :sanf_nums,
                        DETAILES = :detailes,
                        STORE_NO = :store_no,
                        LAST_UPDATED_BY = :user_id,
                        LAST_UPDATED_ON = SYSDATE,
                        LAST_UPDATED_BY_NAME = :user_name,
                        LAST_UPDATE_TIME = TO_CHAR(SYSDATE, \'HH24:MI:SS\')
                  WHERE BILL_NO = :bill_no AND YEAR = :year',
                $this->bindOnlyUsed(
                    $updateSql,
                    [
                        ...$binds,
                        'user_id' => $request->user()?->user_id,
                        'user_name' => $this->clip($this->userName($request), 100),
                    ]
                )
            );

            return;
        }

        // قائمة الأعمدة تُبنى ديناميكياً: رقم المرجع يُكتب حيث يوجد **ويقبل الكتابة** (التوريد).
        // الصرف يعرضه للقراءة فقط لأن عموده SEQBILL رقم داخلي لا نص مرجعي.
        // The column list is dynamic: the reference is written where it exists **and is
        // writable** (receive). Issue shows it read-only: its SEQBILL is an internal number.
        $refColumn = $this->referenceWritable() ? $this->referenceColumn() : null;

        $columns = [
            'BILL_NO', 'YEAR', 'BILL_TYPE', 'DAT',
            $this->partyNoColumn(), $this->partyNameColumn(),
            'TOTAL', 'CUR_NO', 'CUR_PRICE', 'SANF_NUMS', 'DETAILES', 'STORE_NO',
            'DIS_RATE', 'DISCOUNT', 'NET', 'CUR_NO1', 'CUR_PRICE1', 'SELECT1',
            'ACC_NO', 'ACC_NO1',
            ...($refColumn !== null ? [$refColumn] : []),
            'CREATED_ON', 'CREATED_BY', 'CREATED_BY_NAME', 'THE_TIME',
            'BRANCH_NO', 'TR_TYPE_ID', 'FLAG_POST', 'FLAG_CANCEL',
        ];

        $values = [
            ':bill_no', ':year', ':bill_type', "TO_DATE(:dat, 'YYYY-MM-DD')",
            ':party_no', ':party_name',
            ':total', ':cur_no', ':cur_price', ':sanf_nums', ':detailes', ':store_no',
            ':dis_rate', ':discount', ':net', ':cur_no1', ':cur_price1', ':select_one',
            ':acc_no', ':acc_no1',
            ...($refColumn !== null ? [':party_bill'] : []),
            'SYSDATE', ':user_id', ':user_name',
            "TO_CHAR(SYSDATE, 'HH24:MI:SS')", ':branch_no', ':tr_type_id', '0', '0',
        ];

        DB::connection('oracle')->insert(
            $insertSql = 'INSERT INTO '.$this->master().'
                ('.implode(', ', $columns).')
             VALUES
                ('.implode(', ', $values).')',
            $this->bindOnlyUsed(
                $insertSql,
                [
                    ...$binds,
                    'user_id' => $request->user()?->user_id,
                    'user_name' => $this->clip($this->userName($request), 100),
                    'branch_no' => $this->branchNo($request),
                ]
            )
        );
    }

    /**
     * عمود رقم المرجع في الجدول الرئيسي — PROV_BILL للتوريد، وnull حيث لا يوجد
     * The reference-number column: PROV_BILL on receive, null where the table has none.
     */
    protected function referenceColumn(): ?string
    {
        // المصدر الوحيد للحقيقة: سياسة النوع، لا شرط في المتحكم
        // Single source of truth: the kind's policy, not a condition in the controller.
        $column = StorePolicy::fieldsOf($this->kind())['reference_column'] ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * هل يُكتب رقم المرجع من التطبيق؟ التوريد فقط (الصرف يخزّنه رقماً داخلياً للقراءة)
     * Is the reference number writable from the app? Receive only; issue stores it as an
     * internal numeric serial and shows it read-only.
     */
    protected function referenceWritable(): bool
    {
        return (bool) (StorePolicy::fieldsOf($this->kind())['reference_writable'] ?? false);
    }

    /**
     * قطعة الاختيار لرقم المرجع — فارغة حيث لا يوجد العمود
     * The SELECT fragment for the reference number — empty where the column does not exist.
     *
     * ── لماذا لا تُكتب الخانة ثابتة في SELECT؟ لأن جدول الصرف لا يملك رقم مرجع أصلاً،
     *    فكتابتها ثابتة ترمي ORA-00904 وتُسقِط قائمة أوامر الصرف كاملة: شاشة فراغ لا خانة ناقصة.
     *    يُسمّى PARTY_BILL في الحالتين حتى لا يختلف عقد العميل بين التوريد والصرف.
     * ── Why not hardcode it: the issue master has no reference column, so naming it
     *    unconditionally throws ORA-00904 and empties the whole issue list. It is aliased
     *    PARTY_BILL either way so the client contract never differs per kind.
     */
    protected function referenceSelect(): string
    {
        $column = $this->referenceColumn();

        return $column === null ? '' : "m.{$column} AS PARTY_BILL,";
    }

    /**
     * أعمدة عملة السطر — تُشتق من علم `line_currency` في سياسة النوع
     * Per-line currency columns — driven by the kind's `line_currency` flag.
     *
     * ── نفس فخ رقم المرجع: جدول سطور الصرف لا يملك هذين العمودين، وكتابتهما ثابتة
     *    تُسقط تفصيل الصرف وإنشاءه معاً بـORA-00904.
     * ── The same trap as the reference number: the issue details table has neither column,
     *    and naming them unconditionally breaks both the issue detail and the issue create.
     *
     * @return array<int, string>
     */
    protected function lineCurrencyColumns(): array
    {
        $enabled = (bool) (StorePolicy::fieldsOf($this->kind())['line_currency'] ?? false);

        return $enabled ? ['CURNO', 'CURPRICE'] : [];
    }

    /**
     * قطعة اختيار عملات السطر، فارغة حيث لا توجد
     * The SELECT fragment for the line currency columns, empty where they do not exist.
     */
    protected function lineCurrencySelect(): string
    {
        $columns = $this->lineCurrencyColumns();

        return $columns === [] ? '' : implode(', ', array_map(
            static fn (string $column): string => 'd.'.$column,
            $columns
        )).',';
    }

    /**
     * قطعة الاختيار لرقم المرجع — فارغة حيث لا يوجد العمود
     * The reference SET fragment for UPDATE — empty where the column is absent.
     */
    protected function referenceSetClause(): string
    {
        $column = $this->referenceWritable() ? $this->referenceColumn() : null;

        return $column === null ? '' : "{$column} = :party_bill,";
    }

    /**
     * إسقاط كل رابط غير موجود في النص
     * Drop every bind whose placeholder is not in the statement.
     *
     * ── لماذا؟ لأن الأعمدة الاختيارية (رقم المرجع وعملتا السطر) توجد في التوريد لا في الصرف،
     *    فبقيت أسماؤها في مصفوفة الربط بلا مقابل في النص، ومنتقي OCI8 لا يتجاهل الزائد بل يرمي
     *    ORA-01036: illegal variable name/number — أي أن حفظ أمر صرف كان مستحيلاً.
     * ── Why: the optional columns (reference number, line currencies) exist on receive but not on
     *    issue, so their names stayed in the bind array with no placeholder in the text. OCI8 does
     *    not ignore extras — it throws ORA-01036, which made saving any issue order impossible.
     *
     * @param  array<string, mixed>  $binds
     * @return array<string, mixed>
     */
    protected function bindOnlyUsed(string $sql, array $binds): array
    {
        $found = [];

        preg_match_all('/:([a-z0-9_]+)/i', $sql, $found);

        return array_intersect_key($binds, array_flip($found[1] ?? []));
    }

    /**
     * حساب مخزون المخزن من STORES.INV_ACC_NO
     * The store's inventory account, from STORES.INV_ACC_NO.
     */
    private function storeInventoryAccount(int $storeNo): int
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT INV_ACC_NO FROM ACCOUNTS.STORES WHERE STORE_NO = :store_no',
            ['store_no' => $storeNo]
        );

        if ($row === null) {
            return 0;
        }

        $row = array_change_key_case((array) $row, CASE_UPPER);

        return (int) ($row['INV_ACC_NO'] ?? 0);
    }

    /**
     * إدراج سطور المستند
     * Insert the document lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function insertLines(array $lines, int $billNo, int $year, int $curNo, float $curPrice): void
    {
        // أعمدة عملة السطر تُضاف حيث توجد فقط (التوريد) — انظر lineCurrencyColumns()
        // The currency columns are added only where they exist (receive). See lineCurrencyColumns().
        $currency = $this->lineCurrencyColumns();

        $columns = [
            'BILL_NO', 'YEAR', 'SANF_NO', 'SANF_DNO', 'SANF_PRICE', 'QTY', 'CAP', 'PCS',
            'TOTAL', 'SEQ', 'SHELF_NO', 'SIZE_FRM', 'SIZE_TO', 'COLOR_NO', 'CATEGORY',
            'BOUNS', 'STK_COST', 'SANF_COST',
            ...$currency,
        ];

        $values = [
            ':bill_no', ':year', ':sanf_no', ':sanf_dno', ':price', ':qty', ':cap', ':pcs',
            ':total', ':seq', ':shelf_no', ':size_frm', ':size_to', ':color_no', ':category',
            '0', ':stk_cost', ':sanf_cost',
            ...array_map(
                static fn (string $column): string => (string) (
                    // خريطة اسم العمود إلى رابطته: لا يُترك عمود بلا علامة مطابقة
                    // Column to placeholder map: no column is ever left without its bind.
                    ['CURNO' => ':cur_no', 'CURPRICE' => ':cur_price'][$column] ?? 'NULL'
                ),
                $currency
            ),
        ];

        foreach ($lines as $index => $line) {
            DB::connection('oracle')->insert(
                $lineSql = 'INSERT INTO '.$this->details().'
                    ('.implode(', ', $columns).')
                 VALUES
                    ('.implode(', ', $values).')',
                $this->bindOnlyUsed(
                    $lineSql,
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
                        'pcs' => $line['pcs'] ?? $line['qty'],
                        'total' => $line['total'],
                        'shelf_no' => (int) ($line['shelf_no'] ?? 0),
                        // المقاسان يُخزَّنان في السطر نفسه: في التحويل هما شرط ظهور الحركة في الرصيد،
                        // وفي التوريد والصرف هما ما يطبعه المستند حتى لو تغيّر الصنف الفرعي لاحقاً.
                        // Sizes are stored on the line: for a transfer they are what makes the
                        // movement appear in stock at all; elsewhere they are what the document prints.
                        'size_frm' => (float) ($line['len'] ?? 0),
                        'size_to' => (float) ($line['wid'] ?? 0),
                        'color_no' => (int) ($line['color_no'] ?? 0),
                        'category' => $this->clip(
                            ArabicEncoding::toOracleSearchChars((string) ($line['category'] ?? ''))
                                ?? ($line['category'] ?? ''),
                            30
                        ),
                        'stk_cost' => $line['price'],
                        'sanf_cost' => $line['price'],
                        'cur_no' => $curNo,
                        'cur_price' => $curPrice,
                        'seq' => $index + 1,
                    ]
                )
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
     * ── لماذا كل هذه الأعمدة؟ لأن شاشة الاستعراض والنموذج يجب أن يطابقا مستند النظام المكتبي
     *    حرفياً: نوع الفاتورة (نقد/آجل)، رقم المرجع، رقم القيد، الحسابات بأسمائها لا بأرقامها،
     *    Currency pair rows, and the full entry/edit audit strip. النسخ السابقة كانت تعرض
     *    نصف المستند فيصير المستند «ملخّصاً» لا «نفس المستند».
     * ── Why every one of these columns: the view screen and the form must match the desktop
     *    document exactly — bill type, reference no, entry no, accounts by NAME not number,
     *    both currency rows, and the whole created/updated audit strip.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchHeader(int $billNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT m.BILL_NO, m.YEAR, m.DAT, m.BILL_TYPE, m.STORE_NO, s.STORE_NAME,
                    '.$this->partySelect().',
                    '.$this->referenceSelect().'
                    m.ENTRY_NO, m.SANF_NUMS, m.TOTAL, m.DIS_RATE, m.DISCOUNT, m.NET,
                    m.DETAILES, m.SELECT1, m.TR_TYPE_ID, m.BRANCH_NO, m.FLAG_POST, m.FLAG_CANCEL,
                    m.CUR_NO, c.CUR_NAME, m.CUR_PRICE, m.CUR_NO1, c1.CUR_NAME AS CUR_NAME1, m.CUR_PRICE1,
                    m.SEQ_PRICE, m.SEQ_PRICE1,
                    m.ACC_NO, a.ACC_NAME AS ACC_NAME, m.ACC_NO1, a1.ACC_NAME AS ACC_NAME1,
                    m.ACC_NO2, a2.ACC_NAME AS ACC_NAME2,
                    m.CREATED_ON, m.CREATED_BY, m.CREATED_BY_NAME, m.THE_TIME,
                    m.LAST_UPDATED_ON, m.LAST_UPDATED_BY, m.LAST_UPDATED_BY_NAME, m.LAST_UPDATE_TIME
               FROM '.$this->master().' m
               LEFT JOIN ACCOUNTS.STORES s ON s.STORE_NO = m.STORE_NO
               LEFT JOIN ACCOUNTS.CURRENCY c ON c.CUR_NO = m.CUR_NO
               LEFT JOIN ACCOUNTS.CURRENCY c1 ON c1.CUR_NO = m.CUR_NO1
               LEFT JOIN ACCOUNTS.CHART_ACC a ON a.ACC_NO = m.ACC_NO
               LEFT JOIN ACCOUNTS.CHART_ACC a1 ON a1.ACC_NO = m.ACC_NO1
               LEFT JOIN ACCOUNTS.CHART_ACC a2 ON a2.ACC_NO = m.ACC_NO2
              WHERE m.BILL_NO = :bill_no',
            ['bill_no' => $billNo]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * سطور المستند بكل أعمدة القالب: الفرعي والوحدة والمقاسات والتصنيف واللون والرف والعبوة
     * Every template column per line: sub item, unit, sizes, classification, colour, shelf, pack.
     *
     * ── المقاسات تُقرأ من SIZE_FRM/SIZE_TO **المخزّنة في السطر** لا من SANFS_D مباشرة: السطر
     *    يحفظ المقاس الذي حُرّر وقت الحركة، ولو غيّر النظام الصنف الفرعي لاحقاً بقي المستند
     *    التاريخي كما طُبع. (وللتحويل هذان العمودان شرط ظهور الحركة في الرصيد أصلاً.)
     * ── Sizes come from the line's own SIZE_FRM/SIZE_TO, not SANFS_D: a document keeps the
     *    dimensions it was moved at even if the sub item is edited later. For transfers these
     *    two columns are also what makes the movement visible in stock at all.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchLines(int $billNo, int $year): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT d.SANF_NO, d.SANF_DNO, d.QTY, d.SANF_PRICE, d.CAP, d.PCS, d.TOTAL, d.SEQ,
                    d.BOUNS, d.EXP_DATE, d.SHELF_NO, d.SIZE_FRM, d.SIZE_TO, d.COLOR_NO,
                    d.CATEGORY, d.COLOR_NO2, d.STK_COST, d.SANF_COST, d.STYLE_NO, d.CAT_NO,
                    '.$this->lineCurrencySelect().'
                    s.SANF_NAME, s.PART_NO, s.UNIT_NO, s.TYPE_NO, s.GROUPNO,
                    sd.PART_NO AS SUB_PART_NO, sd.SANF_DNAME AS SUB_NAME, sd.SANF_COCE AS SUB_CODE,
                    sd.BARACOCE AS SUB_BARCODE, sd.WID AS SUB_WID, sd.LEN AS SUB_LEN,
                    sd.DCOLOR AS SUB_DCOLOR, sd.MCOLOR AS SUB_MCOLOR, sd.CATEGORY AS SUB_CATEGORY,
                    u.UNIT_NAME, ty.TYPE_NAME AS TYPE_NAME, gy.GROUP_NAME AS GROUP_NAME,
                    c1.COLOR_NAME AS COLOR_NAME
               FROM '.$this->details().' d
               LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
               LEFT JOIN ACCOUNTS.SANFS_D sd ON sd.SANF_DNO = d.SANF_DNO
               LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = s.UNIT_NO
               LEFT JOIN ACCOUNTS.TYPES ty ON ty.TYPE_NO = s.TYPE_NO
               LEFT JOIN ACCOUNTS.GROUP1 gy ON gy.GROUP_NO = s.GROUPNO
               LEFT JOIN ACCOUNTS.SANFS_COLOR c1 ON c1.COLOR_NO = d.COLOR_NO
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
