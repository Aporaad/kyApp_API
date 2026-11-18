<?php

namespace App\Http\Controllers\Purchases;

use App\Support\ArabicEncoding;
use App\Support\ChartAccountNumbering;
use App\Support\PurchasePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم الموردين — سجل `PROV` وحساب كل مورد في `CHART_ACC`
 * Suppliers controller — the PROV registry and each supplier's CHART_ACC account.
 *
 * ── حقيقة مثبتة: كل مورد له حساب في الجزء 251 (الدائنون)، واسم الحساب يطابق اسم المورد في
 *    47 من 48 صفاً. النظام القديم ينشئ حساباً مالياً لكل مورد، فالإنشاء هنا يولّد الحسابين معاً.
 *    2 من 48 مورداً يتشاركان حساباً واحداً، فيُعلَم العميل بذلك بدل إخفائه.
 * ── A proven fact: every supplier owns a part-251 account and 47 of 48 names match. The legacy
 *    creates a ledger per supplier, so creation here writes both rows. Two suppliers share one
 *    account, so the payload flags it instead of hiding it.
 */
class SupplierController extends PurchaseBaseController
{
    private const TABLE_PROV = 'ACCOUNTS.PROV';

    private const TABLE_CHART = 'ACCOUNTS.CHART_ACC';

    /**
     * رصيد الحساب من دفتر الأستاذ — الرصيد السالب يعني دائناً للمورد
     * The account balance from the ledger — a negative value means the supplier is owed.
     */
    private const BALANCE_SQL = 'NVL((SELECT SUM(es.ENTRY_MNT) FROM ACCOUNTS.ENTRY_SUB es WHERE es.ACC_NO = p.ACC_NO), 0)';

    /**
     * قائمة الموردين: بحث بالاسم أو الرقم أو الهاتف، وفلاتر العملة والرصيد والنشاط، وفرز وتصفح
     * The supplier list: search by name/number/phone, currency/balance/activity filters, sort, pages.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $query = DB::connection('oracle')->table(self::TABLE_PROV.' p')
                ->select([
                    'p.PROV_NO', 'p.PROV_NAME', 'p.ADDR', 'p.TEL1', 'p.TEL2', 'p.POSTBOX',
                    'p.EMAIL', 'p.FAX', 'p.ACC_NO',
                    DB::raw('SUBSTR(TO_CHAR(p.ACC_NO), 4, 1) AS CUR_NO'),
                    DB::raw('(SELECT COUNT(*) FROM ACCOUNTS.PROV q WHERE q.ACC_NO = p.ACC_NO) AS SHARED_COUNT'),
                    DB::raw(self::BALANCE_SQL.' AS BALANCE'),
                    DB::raw('(SELECT COUNT(*) FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = p.ACC_NO) AS BILL_COUNT'),
                    DB::raw('(SELECT NVL(SUM(b.NET), 0) FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = p.ACC_NO) AS BILL_NET'),
                    DB::raw('(SELECT COUNT(*) FROM ACCOUNTS.RETURN_BUY r WHERE r.ACC_NO = p.ACC_NO) AS RETURN_COUNT'),
                    DB::raw('(SELECT TO_CHAR(MAX(b.DAT), \'YYYY-MM-DD\') FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = p.ACC_NO) AS LAST_BUY'),
                ]);

            $this->applySupplierFilters($query, $request);

            $total = (clone $query)->count();

            $sort = $this->resolveSort($request);

            $rows = $query->orderByRaw($sort)
                ->offset($offset)
                ->limit($limit)
                ->get();

            $items = array_map(fn ($row): array => $this->toSupplier((array) $row), $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الموردين بنجاح / Suppliers retrieved',
                'data' => ArabicEncoding::cleanData($items),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب الموردين / Failed to retrieve suppliers', $e);
        }
    }

    /**
     * قائمة مختصرة لمنتقيات النماذج — بلا تصفح ولا عدّادات
     * A compact list for form pickers — no paging, no counters.
     */
    public function options(Request $request): JsonResponse
    {
        try {
            $query = DB::connection('oracle')->table(self::TABLE_PROV.' p')
                ->select([
                    'p.PROV_NO', 'p.PROV_NAME', 'p.ACC_NO',
                    DB::raw('SUBSTR(TO_CHAR(p.ACC_NO), 4, 1) AS CUR_NO'),
                    DB::raw(self::BALANCE_SQL.' AS BALANCE'),
                ]);

            $search = trim((string) ($request->input('search') ?? ''));

            if ($search !== '') {
                $query->where(function ($q) use ($search): void {
                    if (is_numeric($search)) {
                        $q->where('p.PROV_NO', (int) $search)
                            ->orWhere('p.ACC_NO', (int) $search);
                    }

                    $needle = $this->arabicNeedle($search);
                    $q->orWhere('p.PROV_NAME', 'LIKE', '%'.$needle.'%')
                        ->orWhere('p.PROV_NAME', 'LIKE', '%'.$search.'%');
                });
            }

            $rows = $query->orderBy('p.PROV_NAME')->limit(500)->get();

            $items = array_map(static function ($row): array {
                $r = array_change_key_case((array) $row, CASE_UPPER);

                return [
                    'prov_no' => isset($r['PROV_NO']) ? (int) $r['PROV_NO'] : null,
                    'name' => $r['PROV_NAME'] ?? null,
                    'acc_no' => isset($r['ACC_NO']) ? (int) $r['ACC_NO'] : null,
                    'cur_no' => isset($r['CUR_NO']) ? (int) $r['CUR_NO'] : null,
                    'balance' => isset($r['BALANCE']) ? (float) $r['BALANCE'] : 0.0,
                ];
            }, $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب قائمة الموردين / Supplier options retrieved',
                'data' => ArabicEncoding::cleanData($items),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة الموردين / Failed to retrieve supplier options', $e);
        }
    }

    /** خيارات الفلترة: العملات والحالة الرصيدية / filter facets: currencies and balance states */
    public function filterOptions(): JsonResponse
    {
        try {
            $currencies = DB::connection('oracle')->select(
                'SELECT DISTINCT SUBSTR(TO_CHAR(ACC_NO), 4, 1) AS CUR_NO
                   FROM ACCOUNTS.PROV
                  WHERE ACC_NO IS NOT NULL
                  ORDER BY 1'
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب خيارات الفلترة / Filter options retrieved',
                'data' => ArabicEncoding::cleanData([
                    'currencies' => array_values(array_filter(array_map(
                        static function ($r): ?int {
                            $row = array_change_key_case((array) $r, CASE_UPPER);
                            $cur = (int) ($row['CUR_NO'] ?? 0);

                            return $cur > 0 ? $cur : null;
                        },
                        $currencies
                    ))),
                    'balance_states' => ['debit', 'credit', 'zero'],
                    'suppliers' => $this->supplierFacets(),
                ]),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب خيارات الفلترة / Failed to retrieve filter options', $e);
        }
    }

    /** مورد واحد مع إحصاءاته / one supplier with its counters */
    public function show(int $provNo): JsonResponse
    {
        try {
            $row = $this->supplierRow($provNo);

            if ($row === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'المورد غير موجود / Supplier not found',
                ], 404);
            }

            $stats = (array) DB::connection('oracle')->selectOne(
                'SELECT
                    (SELECT COUNT(*) FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = :acc) AS BILL_COUNT,
                    (SELECT NVL(SUM(b.NET), 0) FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = :acc2) AS BILL_NET,
                    (SELECT NVL(SUM(b.NET * NVL(b.CUR_PRICE1, 1)), 0) FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = :acc3) AS BILL_NET_BASE,
                    (SELECT COUNT(*) FROM ACCOUNTS.RETURN_BUY r WHERE r.ACC_NO = :acc4) AS RETURN_COUNT,
                    (SELECT NVL(SUM(r.NET), 0) FROM ACCOUNTS.RETURN_BUY r WHERE r.ACC_NO = :acc5) AS RETURN_NET,
                    (SELECT NVL(SUM(es.ENTRY_MNT), 0) FROM ACCOUNTS.ENTRY_SUB es WHERE es.ACC_NO = :acc6) AS BALANCE,
                    (SELECT TO_CHAR(MAX(b.DAT), \'YYYY-MM-DD\') FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = :acc7) AS LAST_BUY,
                    (SELECT COUNT(*) FROM ACCOUNTS.PROV q WHERE q.ACC_NO = :acc8) AS SHARED_COUNT
                 FROM DUAL',
                [
                    'acc' => (int) ($row['ACC_NO'] ?? 0),
                    'acc2' => (int) ($row['ACC_NO'] ?? 0),
                    'acc3' => (int) ($row['ACC_NO'] ?? 0),
                    'acc4' => (int) ($row['ACC_NO'] ?? 0),
                    'acc5' => (int) ($row['ACC_NO'] ?? 0),
                    'acc6' => (int) ($row['ACC_NO'] ?? 0),
                    'acc7' => (int) ($row['ACC_NO'] ?? 0),
                    'acc8' => (int) ($row['ACC_NO'] ?? 0),
                ]
            );

            $recent = DB::connection('oracle')->select(
                'SELECT * FROM (
                     SELECT b.BILL_NO, b.YEAR, b.BILL_TYPE, TO_CHAR(b.DAT, \'YYYY-MM-DD\') AS DAT_ISO,
                            b.NET, b.CUR_NO1, b.CUR_PRICE1, b.DETAILES
                       FROM ACCOUNTS.BUY_BILL b
                      WHERE b.ACC_NO1 = :acc
                      ORDER BY b.DAT DESC, b.BILL_NO DESC
                 ) WHERE ROWNUM <= 10',
                ['acc' => (int) ($row['ACC_NO'] ?? 0)]
            );

            $supplier = $this->toSupplier($row);
            $supplier['stats'] = [
                'bill_count' => (int) ($stats['BILL_COUNT'] ?? $stats['bill_count'] ?? 0),
                'bill_net' => (float) ($stats['BILL_NET'] ?? $stats['bill_net'] ?? 0),
                'bill_net_base' => (float) ($stats['BILL_NET_BASE'] ?? $stats['bill_net_base'] ?? 0),
                'return_count' => (int) ($stats['RETURN_COUNT'] ?? $stats['return_count'] ?? 0),
                'return_net' => (float) ($stats['RETURN_NET'] ?? $stats['return_net'] ?? 0),
                'balance' => (float) ($stats['BALANCE'] ?? $stats['balance'] ?? 0),
                'last_buy' => $stats['LAST_BUY'] ?? $stats['last_buy'] ?? null,
                'shared_count' => (int) ($stats['SHARED_COUNT'] ?? $stats['shared_count'] ?? 1),
            ];
            $supplier['recent_documents'] = array_map(fn ($r): array => $this->recentDocument((array) $r), $recent);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب المورد بنجاح / Supplier retrieved',
                'data' => ArabicEncoding::cleanData($supplier),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب المورد / Failed to retrieve the supplier', $e);
        }
    }

    /**
     * الرقم التالي للمورد والحساب المقترح للعملة المختارة
     * The next supplier number and the projected account for the chosen currency.
     */
    public function nextNumber(Request $request): JsonResponse
    {
        $curNo = max(1, min((int) ($request->input('cur_no') ?? 1), 9));

        try {
            $acc = ChartAccountNumbering::next(PurchasePolicy::SUPPLIER_ACC_PART, $curNo);

            return response()->json([
                'success' => true,
                'message' => 'تم توليد الرقم التالي / Next number generated',
                'data' => [
                    'prov_no' => PurchasePolicy::nextProvNo(),
                    'acc_no' => $acc['number'],
                    'cur_no' => $curNo,
                    'error' => $acc['error'],
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في توليد الرقم التالي / Failed to generate the next number', $e);
        }
    }

    /**
     * إنشاء مورد: صف `PROV` + حساب مالي في الجزء 251 بالعملة المختارة
     * Create a supplier: a PROV row plus a part-251 chart account in the chosen currency.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'cur_no' => 'required|integer|min:1|max:9',
            'address' => 'nullable|string|max:200',
            'phone1' => 'nullable|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'postbox' => 'nullable|string|max:20',
            'email' => 'nullable|string|max:100',
            'fax' => 'nullable|string|max:20',
            'acc_no' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->invalid('بيانات المورد غير صالحة / The supplier data is invalid.', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $name = trim((string) $data['name']);

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(
                fn (): array => $this->createSupplier($data, $name, $request)
            ));

            $this->logActivity($request, 'purchases.supplier_created', self::ENTITY_SUPPLIER, (string) $result['prov_no'], 'إنشاء مورد');

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء المورد بنجاح / Supplier created',
                'data' => $result,
            ], 201);
        } catch (\RuntimeException $e) {
            // الرفض العارض من داخل المعاملة (اسم مكرر أو حساب غير صالح) ردُّ تحقق لا خطأ خادم
            // A business refusal raised inside the transaction is a 422, not a server error.
            return $this->invalid($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء المورد / Failed to create the supplier', $e);
        }
    }

    /**
     * تعديل بيانات المورد — الاسم يحدّث اسم حسابه المالي إن كان مطابقاً للاسم القديم
     * Update a supplier — the name also updates its ledger name when it matched the old one.
     *
     * ── الحساب والعملة لا يتغيّران بعد الإنشاء: العملة مشفّرة في رقم الحساب، وتغييرها يعني
     *    نقل كل أرصدة المورد إلى حساب آخر — عملية لا يقوم بها هذا التطبيق.
     * ── The account and currency never change after creation: the currency is encoded in the
     *    account number, and moving it would relocate every balance.
     */
    public function update(Request $request, int $provNo): JsonResponse
    {
        $row = $this->supplierRow($provNo);

        if ($row === null) {
            return response()->json([
                'success' => false,
                'message' => 'المورد غير موجود / Supplier not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'address' => 'nullable|string|max:200',
            'phone1' => 'nullable|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'postbox' => 'nullable|string|max:20',
            'email' => 'nullable|string|max:100',
            'fax' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->invalid('بيانات المورد غير صالحة / The supplier data is invalid.', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $name = trim((string) $data['name']);
        $oldName = (string) ($row['PROV_NAME'] ?? '');
        $accNo = (int) ($row['ACC_NO'] ?? 0);

        $duplicate = DB::connection('oracle')->selectOne(
            'SELECT PROV_NO FROM ACCOUNTS.PROV WHERE PROV_NAME = :name AND PROV_NO <> :prov',
            ['name' => $this->arabicNeedle($name), 'prov' => $provNo]
        );

        if ($duplicate !== null) {
            return $this->invalid('يوجد مورد آخر بالاسم نفسه / Another supplier already uses this name.');
        }

        try {
            DB::connection('oracle')->transaction(function () use ($provNo, $accNo, $name, $oldName, $data): void {
                DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.PROV
                        SET PROV_NAME = :name, ADDR = :addr, TEL1 = :tel1, TEL2 = :tel2,
                            POSTBOX = :postbox, EMAIL = :email, FAX = :fax
                      WHERE PROV_NO = :prov',
                    [
                        'name' => $this->arabicNeedle($name),
                        'addr' => $this->clipOrNull($data['address'] ?? null, 200),
                        'tel1' => $this->clipOrNull($data['phone1'] ?? null, 20),
                        'tel2' => $this->clipOrNull($data['phone2'] ?? null, 20),
                        'postbox' => $this->clipOrNull($data['postbox'] ?? null, 20),
                        'email' => $this->clipOrNull($data['email'] ?? null, 100),
                        'fax' => $this->clipOrNull($data['fax'] ?? null, 20),
                        'prov' => $provNo,
                    ]
                );

                // اسم الحساب يتبع اسم المورد ما دام مطابقاً للاسم القديم — لا نكتب فوق اسم
                // اختاره المحاسب يدوياً.
                // The ledger name follows the supplier only while it still matches the old name;
                // a name the accountant edited by hand is never overwritten.
                if ($accNo > 0) {
                    DB::connection('oracle')->update(
                        'UPDATE ACCOUNTS.CHART_ACC SET ACC_NAME = :name WHERE ACC_NO = :acc AND ACC_NAME = :old',
                        [
                            'name' => $this->arabicNeedle($name),
                            'acc' => $accNo,
                            'old' => $this->arabicNeedle($oldName),
                        ]
                    );
                }
            });

            $this->logActivity($request, 'purchases.supplier_updated', self::ENTITY_SUPPLIER, (string) $provNo, 'تعديل مورد');

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل المورد بنجاح / Supplier updated',
                'data' => ['prov_no' => $provNo, 'name' => $name],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل المورد / Failed to update the supplier', $e);
        }
    }

    /**
     * حذف مورد بلا أي حركة — ويُحذف حسابه فقط إن كان خاصاً به
     * Delete a supplier with no movement at all — its account goes only when it is its own.
     */
    public function destroy(Request $request, int $provNo): JsonResponse
    {
        $row = $this->supplierRow($provNo);

        if ($row === null) {
            return response()->json([
                'success' => false,
                'message' => 'المورد غير موجود / Supplier not found',
            ], 404);
        }

        $accNo = (int) ($row['ACC_NO'] ?? 0);

        $counts = (array) DB::connection('oracle')->selectOne(
            'SELECT
                (SELECT COUNT(*) FROM ACCOUNTS.BUY_BILL b WHERE b.PROV_NO = :prov1 OR b.ACC_NO1 = :acc1) AS BILLS,
                (SELECT COUNT(*) FROM ACCOUNTS.RETURN_BUY r WHERE r.PROV_NO = :prov2 OR r.ACC_NO = :acc2) AS RETURNS,
                (SELECT COUNT(*) FROM ACCOUNTS.ENTRY_SUB es WHERE es.ACC_NO = :acc3) AS MOVES,
                (SELECT COUNT(*) FROM ACCOUNTS.PROV q WHERE q.ACC_NO = :acc4) AS SHARED
             FROM DUAL',
            [
                'prov1' => $provNo, 'acc1' => $accNo,
                'prov2' => $provNo, 'acc2' => $accNo,
                'acc3' => $accNo,
                'acc4' => $accNo,
            ]
        );

        $bills = (int) ($counts['BILLS'] ?? $counts['bills'] ?? 0);
        $returns = (int) ($counts['RETURNS'] ?? $counts['returns'] ?? 0);
        $moves = (int) ($counts['MOVES'] ?? $counts['moves'] ?? 0);
        $shared = (int) ($counts['SHARED'] ?? $counts['shared'] ?? 1);

        if ($bills > 0 || $returns > 0 || $moves > 0) {
            return $this->invalid(
                'لا يمكن حذف مورد له فواتير أو مردودات أو حركات على حسابه / The supplier has documents or ledger movements.',
                ['bills' => $bills, 'returns' => $returns, 'movements' => $moves]
            );
        }

        try {
            DB::connection('oracle')->transaction(function () use ($provNo, $accNo, $shared): void {
                DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.PROV WHERE PROV_NO = :prov', ['prov' => $provNo]);

                // الحساب المشترك يبقى: مورد آخر ما زال يستخدمه
                // A shared account stays: another supplier still points at it.
                if ($accNo > 0 && $shared <= 1) {
                    DB::connection('oracle')->delete('DELETE FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :acc', ['acc' => $accNo]);
                }
            });

            $this->logActivity($request, 'purchases.supplier_deleted', self::ENTITY_SUPPLIER, (string) $provNo, 'حذف مورد');

            return response()->json([
                'success' => true,
                'message' => 'تم حذف المورد / Supplier deleted',
                'data' => ['prov_no' => $provNo],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف المورد / Failed to delete the supplier', $e);
        }
    }

    /**
     * كشف حساب المورد — الحركات من دفتر الأستاذ بعملة الحساب
     * The supplier statement — ledger movements in the account's currency.
     *
     * ── لا يوجد عمود «جهة» في ENTRY_SUB للموردين كما في العملاء (GIVING)، فالربط بالحساب
     *    وحده. وحين يتشارك موردان حساباً واحداً تظهر حركاتهما معاً، ويُعلَم العميل بذلك.
     * ── Unlike customers, suppliers have no party column in ENTRY_SUB (no GIVING), so the link is
     *    the account alone. When two suppliers share an account their movements appear together,
     *    and the payload says so.
     */
    public function statement(Request $request, int $provNo): JsonResponse
    {
        $row = $this->supplierRow($provNo);

        if ($row === null) {
            return response()->json([
                'success' => false,
                'message' => 'المورد غير موجود / Supplier not found',
            ], 404);
        }

        $accNo = (int) ($row['ACC_NO'] ?? 0);

        if ($accNo <= 0) {
            return $this->invalid('المورد بلا حساب مالي / The supplier has no ledger account.');
        }

        $from = $this->dateInput($request, 'from_date');
        $to = $this->dateInput($request, 'to_date');

        try {
            $sql = 'SELECT es.SEQ, es.ENTRY_NO, TO_CHAR(em.ENTRY_DATE, \'YYYY-MM-DD\') AS RAW_DATE,
                           TO_CHAR(em.ENTRY_DATE, \'DD/MM/YYYY\') AS ENTRY_DATE,
                           et.ENTRY_TYPE_NAME,
                           NVL(TRIM(es.SUB_DETAILS), em.DETAILS) AS DETAILS,
                           es.ENTRY_MNT
                      FROM ACCOUNTS.ENTRY_SUB es
                      JOIN ACCOUNTS.ENTRY_MAIN em ON em.ENTRY_NO = es.ENTRY_NO
                      LEFT JOIN ACCOUNTS.ENTRY_TYPE et ON et.ENTRY_TYPE_NO = em.ENTRY_TYPE_NO
                     WHERE es.ACC_NO = :acc
                     ORDER BY em.ENTRY_DATE, es.SEQ';

            $rows = DB::connection('oracle')->select($sql, ['acc' => $accNo]);

            $opening = 0.0;
            $debit = 0.0;
            $credit = 0.0;
            $running = 0.0;
            $items = [];

            foreach ($rows as $raw) {
                $r = array_change_key_case((array) $raw, CASE_UPPER);
                $rawDate = (string) ($r['RAW_DATE'] ?? '');
                $amount = (float) ($r['ENTRY_MNT'] ?? 0);

                if ($from !== null && $rawDate < $from) {
                    $opening += $amount;

                    continue;
                }

                if ($to !== null && $rawDate > $to) {
                    continue;
                }

                if ($items === []) {
                    $running = $opening;
                }

                $running += $amount;
                $debit += $amount > 0 ? $amount : 0.0;
                $credit += $amount < 0 ? abs($amount) : 0.0;

                $items[] = [
                    'seq' => isset($r['SEQ']) ? (int) $r['SEQ'] : null,
                    'entry_no' => isset($r['ENTRY_NO']) ? (int) $r['ENTRY_NO'] : null,
                    'voucher' => isset($r['ENTRY_NO']) ? (string) $r['ENTRY_NO'] : null,
                    'date' => $r['ENTRY_DATE'] ?? null,
                    'type_name' => $r['ENTRY_TYPE_NAME'] ?? null,
                    'details' => $r['DETAILS'] ?? null,
                    'debit' => $amount > 0 ? round($amount, 2) : 0.0,
                    'credit' => $amount < 0 ? round(abs($amount), 2) : 0.0,
                    'balance' => round($running, 2),
                ];
            }

            $supplier = $this->toSupplier($row);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب كشف حساب المورد / Supplier statement retrieved',
                'data' => ArabicEncoding::cleanData([
                    'supplier' => $supplier,
                    'acc_no' => $accNo,
                    'cur_no' => $supplier['cur_no'],
                    'shared_account' => ($supplier['shared_count'] ?? 1) > 1,
                    'from_date' => $from,
                    'to_date' => $to,
                    'opening_balance' => round($opening, 2),
                    'total_debit' => round($debit, 2),
                    'total_credit' => round($credit, 2),
                    'closing_balance' => round($opening + $debit - $credit, 2),
                    'rows' => $items,
                ]),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب كشف حساب المورد / Failed to retrieve the supplier statement', $e);
        }
    }

    /** تسجيل طباعة/تصدير على المورد / log a print or export action on the supplier */
    public function activity(Request $request, int $provNo): JsonResponse
    {
        $this->logActivity($request, 'purchases.supplier_printed', self::ENTITY_SUPPLIER, (string) $provNo, 'طباعة/تصدير مورد');

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل النشاط / Activity logged',
        ], 200);
    }

    // ── helpers ──────────────────────────────────────────────

    /**
     * صف المورد بمفاتيح كبيرة
     * The supplier row with uppercase keys.
     *
     * @return array<string, mixed>|null
     */
    private function supplierRow(int $provNo): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT p.PROV_NO, p.PROV_NAME, p.ADDR, p.TEL1, p.TEL2, p.POSTBOX, p.EMAIL, p.FAX, p.ACC_NO,
                    SUBSTR(TO_CHAR(p.ACC_NO), 4, 1) AS CUR_NO,
                    (SELECT COUNT(*) FROM ACCOUNTS.PROV q WHERE q.ACC_NO = p.ACC_NO) AS SHARED_COUNT
               FROM ACCOUNTS.PROV p
              WHERE p.PROV_NO = :prov',
            ['prov' => $provNo]
        );

        return $this->upperRow($row);
    }

    /**
     * إنشاء المورد وحسابه داخل معاملة واحدة
     * Create the supplier and its account inside one transaction.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createSupplier(array $data, string $name, Request $request): array
    {
        $duplicate = DB::connection('oracle')->selectOne(
            'SELECT PROV_NO FROM ACCOUNTS.PROV WHERE PROV_NAME = :name',
            ['name' => $this->arabicNeedle($name)]
        );

        if ($duplicate !== null) {
            throw new \RuntimeException('يوجد مورد بالاسم نفسه / A supplier with this name already exists.');
        }

        $curNo = (int) $data['cur_no'];
        $accNo = isset($data['acc_no']) && (int) $data['acc_no'] > 0 ? (int) $data['acc_no'] : null;

        if ($accNo !== null) {
            // ربط بحساب قائم: يجب أن يكون في الجزء 251 وغير مرتبط بمورد آخر
            // Linking an existing account: it must live in part 251 and be free of another supplier
            if (ChartAccountNumbering::currencyOf($accNo) === null
                || ! PurchasePolicy::isSupplierAccount($accNo)) {
                throw new \RuntimeException('الحساب المختار ليس حساب موردين (الجزء 251) / The chosen account is not a supplier account.');
            }

            $taken = DB::connection('oracle')->selectOne(
                'SELECT PROV_NO FROM ACCOUNTS.PROV WHERE ACC_NO = :acc',
                ['acc' => $accNo]
            );

            if ($taken !== null) {
                throw new \RuntimeException('الحساب مرتبط بمورد آخر / The account belongs to another supplier.');
            }

            $exists = DB::connection('oracle')->selectOne(
                'SELECT ACC_NAME FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :acc',
                ['acc' => $accNo]
            );

            if ($exists === null) {
                throw new \RuntimeException('الحساب المختار غير موجود في دليل الحسابات / The chosen account does not exist.');
            }
        } else {
            $generated = ChartAccountNumbering::next(PurchasePolicy::SUPPLIER_ACC_PART, $curNo);

            if ($generated['number'] === null) {
                throw new \RuntimeException((string) $generated['error']);
            }

            $accNo = (int) $generated['number'];

            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.CHART_ACC (ACC_NO, ACC_NAME, BALANCE, ACC_DATE, SEQ, GROUP_ID, LIMITED_BALANCE, STOPPING_ACC)
                 VALUES (:acc, :name, 0, SYSDATE, 0, NULL, NULL, NULL)',
                ['acc' => $accNo, 'name' => $this->arabicNeedle($name)]
            );
        }

        $provNo = PurchasePolicy::nextProvNo();

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.PROV (PROV_NO, PROV_NAME, ADDR, TEL1, TEL2, POSTBOX, EMAIL, FAX, ACC_NO)
             VALUES (:prov, :name, :addr, :tel1, :tel2, :postbox, :email, :fax, :acc)',
            [
                'prov' => $provNo,
                'name' => $this->arabicNeedle($name),
                'addr' => $this->clipOrNull($data['address'] ?? null, 200),
                'tel1' => $this->clipOrNull($data['phone1'] ?? null, 20),
                'tel2' => $this->clipOrNull($data['phone2'] ?? null, 20),
                'postbox' => $this->clipOrNull($data['postbox'] ?? null, 20),
                'email' => $this->clipOrNull($data['email'] ?? null, 100),
                'fax' => $this->clipOrNull($data['fax'] ?? null, 20),
                'acc' => $accNo,
            ]
        );

        $row = $this->supplierRow($provNo);

        return [
            'prov_no' => $provNo,
            'name' => $name,
            'acc_no' => $accNo,
            'cur_no' => $curNo,
            'supplier' => $row === null ? null : $this->toSupplier($row),
        ];
    }

    /**
     * تحويل صف المورد إلى حمولة العميل
     * Turn a supplier row into the client payload.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toSupplier(array $row): array
    {
        $balance = (float) ($this->num($row, 'BALANCE') ?? 0);

        return [
            'prov_no' => $this->int($row, 'PROV_NO'),
            'name' => $this->str($row, 'PROV_NAME'),
            'acc_no' => $this->int($row, 'ACC_NO'),
            'cur_no' => $this->int($row, 'CUR_NO'),
            'address' => $this->str($row, 'ADDR'),
            'phone1' => $this->str($row, 'TEL1'),
            'phone2' => $this->str($row, 'TEL2'),
            'postbox' => $this->str($row, 'POSTBOX'),
            'email' => $this->str($row, 'EMAIL'),
            'fax' => $this->str($row, 'FAX'),
            'balance' => round($balance, 2),
            'balance_type' => $balance > 0.004 ? 'debit' : ($balance < -0.004 ? 'credit' : 'zero'),
            'bill_count' => $this->int($row, 'BILL_COUNT') ?? 0,
            'bill_net' => round((float) ($this->num($row, 'BILL_NET') ?? 0), 2),
            'return_count' => $this->int($row, 'RETURN_COUNT') ?? 0,
            'last_buy' => $this->str($row, 'LAST_BUY'),
            'shared_count' => $this->int($row, 'SHARED_COUNT') ?? 1,
        ];
    }

    /**
     * صف مستند حديث في بطاقة المورد
     * A recent document row inside the supplier card.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function recentDocument(array $row): array
    {
        return [
            'bill_no' => $this->int($row, 'BILL_NO'),
            'year' => $this->int($row, 'YEAR'),
            'bill_type' => $this->int($row, 'BILL_TYPE'),
            'date' => $this->str($row, 'DAT_ISO'),
            'net' => round((float) ($this->num($row, 'NET') ?? 0), 2),
            'cur_no' => $this->int($row, 'CUR_NO1'),
            'cur_price' => (float) ($this->num($row, 'CUR_PRICE1') ?? 1),
            'details' => $this->str($row, 'DETAILES'),
        ];
    }

    /**
     * فلاتر القائمة — البحث والفلاتر على استعلام واحد
     * The list filters applied to one query.
     */
    private function applySupplierFilters($query, Request $request): void
    {
        $search = trim((string) ($request->input('search') ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                if (is_numeric($search)) {
                    $q->where('p.PROV_NO', (int) $search)
                        ->orWhere('p.ACC_NO', (int) $search);
                }

                $needle = $this->arabicNeedle($search);
                $q->orWhere('p.PROV_NAME', 'LIKE', '%'.$needle.'%')
                    ->orWhere('p.PROV_NAME', 'LIKE', '%'.$search.'%')
                    ->orWhere('p.TEL1', 'LIKE', '%'.$search.'%')
                    ->orWhere('p.TEL2', 'LIKE', '%'.$search.'%');
            });
        }

        if (($cur = $request->input('cur_no')) !== null && $cur !== '') {
            $query->whereRaw('SUBSTR(TO_CHAR(p.ACC_NO), 4, 1) = ?', [(int) $cur]);
        }

        $state = (string) ($request->input('balance_state') ?? '');

        if ($state === 'debit') {
            $query->whereRaw(self::BALANCE_SQL.' > 0.004');
        } elseif ($state === 'credit') {
            $query->whereRaw(self::BALANCE_SQL.' < -0.004');
        } elseif ($state === 'zero') {
            $query->whereRaw('ABS('.self::BALANCE_SQL.') <= 0.004');
        }

        if (($activeFrom = $this->dateInput($request, 'active_from')) !== null) {
            $query->whereRaw(
                '(SELECT MAX(b.DAT) FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = p.ACC_NO) >= TO_DATE(?, \'YYYY-MM-DD\')',
                [$activeFrom]
            );
        }
    }

    /**
     * الفرز بقائمة بيضاء — المفتاح الغريب يعود إلى الاسم
     * A closed sort whitelist; unknown keys fall back to the name.
     */
    private function resolveSort(Request $request): string
    {
        $allowed = [
            'name' => 'p.PROV_NAME',
            'prov_no' => 'p.PROV_NO',
            'balance' => self::BALANCE_SQL,
            'last_buy' => '(SELECT MAX(b.DAT) FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = p.ACC_NO)',
        ];

        $key = (string) ($request->input('sort') ?? 'name');
        $column = $allowed[$key] ?? $allowed['name'];
        $dir = strtolower((string) ($request->input('dir') ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        return $column.' '.$dir;
    }

    /**
     * قائمة الموردين المختصرة لفلتر التقارير
     * A compact supplier list for the reports filter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function supplierFacets(): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT PROV_NO, PROV_NAME FROM ACCOUNTS.PROV ORDER BY PROV_NAME'
        );

        return array_map(static function ($row): array {
            $r = array_change_key_case((array) $row, CASE_UPPER);

            return ['prov_no' => (int) $r['PROV_NO'], 'name' => $r['PROV_NAME'] ?? null];
        }, $rows);
    }

    /** قصّ نص أو null / clip text or null */
    private function clipOrNull(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }
}
