<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Purchases\Concerns\WritesPurchaseInvoices;
use App\Support\ArabicEncoding;
use App\Support\ChartAccountNumbering;
use App\Support\InventoryLineGuard;
use App\Support\PurchasePolicy;
use App\Support\PurchasePosting;
use App\Support\StorePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم فواتير المشتريات — القراءة والمراجع والسطور القابلة للرد
 * Purchase invoices controller — reads, references and the returnable lines.
 *
 * ── الهوية مركّبة (BILL_NO, YEAR) لأن الرقم يتكرر كل سنة. ولا زناد على الجدول: الترقيم `MAX+1`
 *    داخل المعاملة، والترحيل صريح لأن `ENTRY_NO` مفتاح أجنبي غير فارغ. الكتابة في
 *    `Concerns\WritesPurchaseInvoices` والقراءة هنا.
 * ── The identity is composite (bill_no, year); nothing is trigger-numbered. Writes live in the
 *    trait, reads here.
 *
 * ── حقيقتان مقروءتان من البيانات تفرضان هذا الملف:
 *    1. `SANF_NUMS` إلزامي وقيمته 1 في كل الصفوف القديمة ⇒ تُكتب 1، لا عدد السطور.
 *    2. 596 فاتورة قديمة على مخازن 11/12 المستثناة من أرصدة المخزون ⇒ الإنشاء على مخزن مخزوني
 *       فقط، والتعديل يُبقي المخزن القديم ولا ينقل إليه.
 * ── Two measured facts drive this file: SANF_NUMS is literally 1 everywhere, and 596 legacy bills
 *    sit on stores 11/12 excluded from stock — so creation needs a stock store while an update
 *    keeps the old store and never moves into an excluded one.
 */
class PurchaseInvoiceController extends PurchaseBaseController
{
    use WritesPurchaseInvoices;

    private const TABLE = 'ACCOUNTS.BUY_BILL';

    private const TABLE_DETAILS = 'ACCOUNTS.BUY_DETAILES';

    private const TABLE_SANFS = 'ACCOUNTS.SANFS';

    /**
     * قائمة الفواتير: بحث وفلاتر وفرز بقائمة بيضاء وتصفح
     * The invoice list: search, filters, a whitelisted sort and paging.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $query = $this->baseListQuery();
            $this->applyListFilters($query, $request);

            $total = (clone $query)->count();

            $rows = $query
                ->orderByRaw($this->resolveSort($request))
                ->orderByDesc('b.BILL_NO')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $items = array_map(fn ($row): array => $this->toSummary($this->upperRow($row) ?? []), $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب فواتير المشتريات بنجاح / Purchase invoices retrieved',
                'data' => ArabicEncoding::cleanData($items),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب فواتير المشتريات / Failed to retrieve purchase invoices', $e);
        }
    }

    /** خيارات الفلترة: السنوات والفروع والأنواع والموردون والمخازن والعملات */
    /** Filter facets: years, branches, types, suppliers, stores and currencies. */
    public function filterOptions(): JsonResponse
    {
        try {
            $base = fn () => DB::connection('oracle')->table(self::TABLE.' b');

            $suppliers = DB::connection('oracle')->select(
                'SELECT DISTINCT p.PROV_NO, p.PROV_NAME
                   FROM ACCOUNTS.PROV p
                  WHERE EXISTS (SELECT 1 FROM ACCOUNTS.BUY_BILL b WHERE b.ACC_NO1 = p.ACC_NO)
                  ORDER BY p.PROV_NAME'
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب خيارات الفلترة بنجاح / Filter options retrieved',
                'data' => ArabicEncoding::cleanData([
                    'years' => $this->distinctInts($base(), 'b.YEAR'),
                    'branches' => $this->distinctInts($base(), 'b.BRANCH_NO'),
                    'bill_types' => $this->distinctInts($base(), 'b.BILL_TYPE'),
                    'stores' => $this->distinctInts($base(), 'b.STORE_NO'),
                    'currencies' => $this->distinctInts($base(), 'b.CUR_NO1'),
                    'suppliers' => array_map(fn ($r): array => [
                        'prov_no' => $this->int((array) $r, 'PROV_NO'),
                        'name' => $this->str((array) $r, 'PROV_NAME'),
                    ], $suppliers),
                ]),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب خيارات الفلترة / Failed to retrieve filter options', $e);
        }
    }

    /**
     * فاتورة واحدة: الرأس والسطور والمجاميع والمعادل والمردودات المرتبطة
     * One invoice: header, lines, totals, the riyal equivalent and its linked returns.
     */
    public function show(int $billNo, int $year): JsonResponse
    {
        try {
            $header = $this->invoiceHeader($billNo, $year);

            if ($header === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'فاتورة المشتريات غير موجودة / Purchase invoice not found',
                ], 404);
            }

            $lines = DB::connection('oracle')->select(
                'SELECT d.SEQ, d.SANF_NO, d.SANF_DNO, d.SANF_PRICE, d.QTY, d.CAP, d.PCS, d.TOTAL,
                        d.DISCOUNT_RATE, d.DISCOUNT1, d.CATEGORY, d.CAT_NO, d.STYLE_NO,
                        d.COLOR_NO, d.STK_COST, d.STK_TOT, d.SEQ_NO,
                        s.SANF_NAME, sd.SANF_DNAME, sd.PART_NO AS SUB_PART_NO, u.UNIT_NAME
                   FROM ACCOUNTS.BUY_DETAILES d
                   LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
                   LEFT JOIN ACCOUNTS.SANFS_D sd ON sd.SANF_DNO = d.SANF_DNO
                   LEFT JOIN ACCOUNTS.UNITS u ON u.UNIT_NO = s.UNIT_NO
                  WHERE d.BILL_NO = :bill_no AND d.YEAR = :year
                  ORDER BY d.SEQ_NO, d.SEQ',
                ['bill_no' => $billNo, 'year' => $year]
            );

            $returns = DB::connection('oracle')->select(
                'SELECT RETURN_NO, YEAR, TO_CHAR(RET_DATE, \'YYYY-MM-DD\') AS RET_DATE_ISO, NET, ENTRY_NO
                   FROM ACCOUNTS.RETURN_BUY
                  WHERE BILL_NO = :bill_no AND BILL_YEAR = :year
                  ORDER BY RETURN_NO',
                ['bill_no' => $billNo, 'year' => $year]
            );

            $party = $this->accountInfo($this->int($header, 'ACC_NO1'));
            $stock = $this->accountInfo($this->int($header, 'ACC_NO'));
            $store = $this->storeInfo($this->int($header, 'STORE_NO'));

            $net = (float) ($this->num($header, 'NET') ?? 0);
            $rate = (float) ($this->num($header, 'CUR_PRICE1') ?? 1);

            $payload = [
                'bill_no' => $this->int($header, 'BILL_NO'),
                'year' => $this->int($header, 'YEAR'),
                'bill_type' => $this->int($header, 'BILL_TYPE'),
                'date' => $this->str($header, 'DAT_ISO'),
                'manual_bill_no' => $this->int($header, 'MANUAL_BILL_NO'),
                'prov_bill' => $this->str($header, 'PROV_BILL'),
                'prov_no' => $this->int($header, 'PROV_NO'),
                'prov_name' => $this->str($header, 'PROV_NAME'),
                'store_no' => $this->int($header, 'STORE_NO'),
                'store_name' => $store['store_name'],
                'store_is_stock' => $store['is_stock'],
                'acc_no' => $this->int($header, 'ACC_NO'),
                'acc_name' => $stock['acc_name'],
                'acc_no1' => $this->int($header, 'ACC_NO1'),
                'acc_name1' => $party['acc_name'],
                'cur_no' => $this->int($header, 'CUR_NO'),
                'cur_price' => (float) ($this->num($header, 'CUR_PRICE') ?? 1),
                'cur_no1' => $this->int($header, 'CUR_NO1'),
                'cur_price1' => $rate,
                'seq_price1' => $this->int($header, 'SEQ_PRICE1'),
                'total' => (float) ($this->num($header, 'TOTAL') ?? 0),
                'discount' => (float) ($this->num($header, 'DISCOUNT') ?? 0),
                'dis_rate' => (float) ($this->num($header, 'DIS_RATE') ?? 0),
                'net' => $net,
                'net_base' => round($net * $rate, 2),
                'entry_no' => $this->int($header, 'ENTRY_NO'),
                'posted' => $this->int($header, 'ENTRY_NO') !== null,
                'branch_no' => $this->int($header, 'BRANCH_NO'),
                'details' => $this->str($header, 'DETAILES'),
                'sanf_nums' => $this->int($header, 'SANF_NUMS'),
                'items_count' => count($lines),
                'created_by_name' => $this->str($header, 'CREATED_BY_NAME'),
                'created_on' => $this->str($header, 'CREATED_ON_ISO'),
                'updated_by_name' => $this->str($header, 'LAST_UPDATED_BY_NAME'),
                'updated_on' => $this->str($header, 'LAST_UPDATED_ON_ISO'),
                'the_time' => $this->str($header, 'THE_TIME'),
                'lines' => array_map(fn ($r): array => $this->toLine($this->upperRow($r) ?? []), $lines),
                'returns' => array_map(fn ($r): array => [
                    'return_no' => $this->int((array) $r, 'RETURN_NO'),
                    'year' => $this->int((array) $r, 'YEAR'),
                    'date' => $this->str((array) $r, 'RET_DATE_ISO'),
                    'net' => round((float) ($this->num((array) $r, 'NET') ?? 0), 2),
                    'entry_no' => $this->int((array) $r, 'ENTRY_NO'),
                ], $returns),
            ];

            $payload['totals'] = $this->lineTotals($payload['lines'], $payload['discount']);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب فاتورة المشتريات بنجاح / Purchase invoice retrieved',
                'data' => ArabicEncoding::cleanData($payload),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب فاتورة المشتريات / Failed to retrieve the purchase invoice', $e);
        }
    }

    /**
     * السطور القابلة للرد لفاتورة — مجمَّعة بالمفتاح الخماسي لسطور المردود
     * The returnable lines of an invoice — aggregated by the return line's five-column key.
     *
     * ── لماذا التجميع؟ لأن `RETURN_BUY_DETAILES` مفتاحه الأساسي
     *    (RETURN_NO, YEAR, SANF_NO, CAP, SANF_DNO): سطر واحد لكل صنف فرعي في المردود. و48 مجموعة
     *    سطور فواتير في هذه القاعدة تتكرر بنفس المفتاح، فلا يمكن ردّها سطراً بسطر.
     * ── Why aggregate: the return table's primary key is (return_no, year, sanf_no, cap, sanf_dno),
     *    so a return holds one line per sub item — while 48 invoice line-groups repeat that key.
     */
    public function returnable(Request $request, int $billNo, int $year): JsonResponse
    {
        $header = $this->invoiceHeader($billNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'فاتورة المشتريات غير موجودة / Purchase invoice not found',
            ], 404);
        }

        $excludeReturn = (int) ($request->input('exclude_return_no') ?? 0);

        try {
            $purchased = DB::connection('oracle')->select(
                'SELECT d.SANF_NO, d.SANF_DNO, d.CAP, SUM(d.QTY) AS QTY, MIN(d.SEQ) AS FIRST_SEQ,
                        MAX(d.SANF_PRICE) AS PRICE, MAX(d.CATEGORY) AS CATEGORY,
                        MAX(d.CAT_NO) AS CAT_NO, MAX(d.STYLE_NO) AS STYLE_NO, MAX(d.COLOR_NO) AS COLOR_NO,
                        MAX(s.SANF_NAME) AS SANF_NAME, MAX(sd.SANF_DNAME) AS SUB_NAME
                   FROM ACCOUNTS.BUY_DETAILES d
                   LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = d.SANF_NO
                   LEFT JOIN ACCOUNTS.SANFS_D sd ON sd.SANF_DNO = d.SANF_DNO
                  WHERE d.BILL_NO = :bill_no AND d.YEAR = :year
                  GROUP BY d.SANF_NO, d.SANF_DNO, d.CAP
                  ORDER BY MIN(d.SEQ_NO)',
                ['bill_no' => $billNo, 'year' => $year]
            );

            $returnedSql = 'SELECT r.SANF_NO, r.SANF_DNO, r.CAP, SUM(r.RET_QTY) AS QTY
                              FROM ACCOUNTS.RETURN_BUY_DETAILES r
                              JOIN ACCOUNTS.RETURN_BUY h ON h.RETURN_NO = r.RETURN_NO AND h.YEAR = r.YEAR
                             WHERE h.BILL_NO = :bill_no AND h.BILL_YEAR = :year';

            $binds = ['bill_no' => $billNo, 'year' => $year];

            if ($excludeReturn > 0) {
                $returnedSql .= ' AND h.RETURN_NO <> :exclude AND h.YEAR <> :exclude_year';
                $binds['exclude'] = $excludeReturn;
                $binds['exclude_year'] = (int) ($request->input('exclude_return_year') ?? $year);
            }

            $returnedSql .= ' GROUP BY r.SANF_NO, r.SANF_DNO, r.CAP';

            $returned = [];

            foreach (DB::connection('oracle')->select($returnedSql, $binds) as $row) {
                $r = array_change_key_case((array) $row, CASE_UPPER);
                $returned[$r['SANF_NO'].'|'.$r['SANF_DNO'].'|'.$r['CAP']] = (float) $r['QTY'];
            }

            $storeNo = (int) ($this->int($header, 'STORE_NO') ?? 0);
            $stockStore = StorePolicy::isStockStore($storeNo);

            $sanfNos = array_values(array_filter(array_map(
                static fn ($r): int => (int) ((array_change_key_case((array) $r, CASE_UPPER))['SANF_NO'] ?? 0),
                $purchased
            )));

            $available = $stockStore ? InventoryLineGuard::availableQuantities($storeNo, $sanfNos) : [];

            $lines = [];

            foreach ($purchased as $row) {
                $r = array_change_key_case((array) $row, CASE_UPPER);
                $key = $r['SANF_NO'].'|'.$r['SANF_DNO'].'|'.$r['CAP'];
                $qty = (float) ($r['QTY'] ?? 0);
                $already = $returned[$key] ?? 0.0;

                $lines[] = [
                    'sanf_no' => (int) $r['SANF_NO'],
                    'sanf_dno' => (int) $r['SANF_DNO'],
                    'cap' => (float) $r['CAP'],
                    'sanf_name' => $r['SANF_NAME'] ?? null,
                    'sub_name' => $r['SUB_NAME'] ?? null,
                    'seq_sales' => (int) ($r['FIRST_SEQ'] ?? 0),
                    'price' => (float) ($r['PRICE'] ?? 0),
                    'category' => $r['CATEGORY'] ?? null,
                    'cat_no' => (int) ($r['CAT_NO'] ?? 0),
                    'style_no' => (int) ($r['STYLE_NO'] ?? 0),
                    'color_no' => (int) ($r['COLOR_NO'] ?? 0),
                    'purchased' => round($qty, 3),
                    'returned' => round($already, 3),
                    'remaining' => round(max(0.0, $qty - $already), 3),
                    'available' => $stockStore ? round((float) ($available[(int) $r['SANF_NO']] ?? 0), 3) : null,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب السطور القابلة للرد / Returnable lines retrieved',
                'data' => ArabicEncoding::cleanData([
                    'bill_no' => $billNo,
                    'year' => $year,
                    'bill_type' => $this->int($header, 'BILL_TYPE'),
                    'store_no' => $storeNo,
                    'store_is_stock' => $stockStore,
                    'prov_no' => $this->int($header, 'PROV_NO'),
                    'prov_name' => $this->str($header, 'PROV_NAME'),
                    'acc_no' => $this->int($header, 'ACC_NO'),
                    'acc_no1' => $this->int($header, 'ACC_NO1'),
                    'cur_no' => $this->int($header, 'CUR_NO1'),
                    'cur_price' => (float) ($this->num($header, 'CUR_PRICE1') ?? 1),
                    'manual_bill_no' => $this->int($header, 'MANUAL_BILL_NO'),
                    'lines' => $lines,
                ]),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب السطور القابلة للرد / Failed to retrieve returnable lines', $e);
        }
    }

    /**
     * مراجع النموذج — الأنواع والحدود والمخازن والعملات وحسابات الدفع
     * The form references — types, limits, stores, currencies and cash accounts.
     */
    public function reference(): JsonResponse
    {
        try {
            $stores = DB::connection('oracle')->select(
                'SELECT STORE_NO, STORE_NAME, INV_ACC_NO FROM ACCOUNTS.STORES ORDER BY STORE_NO'
            );

            $currencies = DB::connection('oracle')->select(
                'SELECT CUR_NO, CUR_NAME FROM ACCOUNTS.CURRENCY ORDER BY CUR_NO'
            );

            $branches = DB::connection('oracle')->select(
                'SELECT BRANCH_NO, BRANCH_NAME_AR, BRANCH_NAME_EN FROM ACCOUNTS.BRANCHES ORDER BY BRANCH_NO'
            );

            $rates = [];

            foreach ($currencies as $row) {
                $curNo = $this->int((array) $row, 'CUR_NO') ?? 1;
                $rates[] = [
                    'cur_no' => $curNo,
                    'cur_name' => $this->str((array) $row, 'CUR_NAME'),
                    'price' => PurchasePosting::suggestRate($curNo),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مراجع المشتريات بنجاح / Purchases reference retrieved',
                'data' => ArabicEncoding::cleanData(array_merge(PurchasePolicy::reference(), [
                    'stores' => array_map(fn ($r): array => [
                        'store_no' => $this->int((array) $r, 'STORE_NO'),
                        'store_name' => $this->str((array) $r, 'STORE_NAME'),
                        'inv_acc_no' => $this->int((array) $r, 'INV_ACC_NO'),
                        'is_stock' => StorePolicy::isStockStore($this->int((array) $r, 'STORE_NO')),
                    ], $stores),
                    'currencies' => $rates,
                    'branches' => array_map(fn ($r): array => [
                        'branch_no' => $this->int((array) $r, 'BRANCH_NO'),
                        'branch_name_ar' => $this->str((array) $r, 'BRANCH_NAME_AR'),
                        'branch_name_en' => $this->str((array) $r, 'BRANCH_NAME_EN'),
                    ], $branches),
                    'cash_accounts' => $this->cashAccountRows(),
                    'default_branch' => PurchasePolicy::DEFAULT_BRANCH,
                    'excluded_stores' => StorePolicy::excludedStores(),
                ])),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مراجع المشتريات / Failed to retrieve the purchases reference', $e);
        }
    }

    /**
     * حسابات الدفع النقدي (الصناديق والبنوك) — الجزآن 121 و122
     * Cash and bank accounts — parts 121 and 122.
     */
    public function cashAccounts(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'تم جلب حسابات الدفع / Cash accounts retrieved',
                'data' => ArabicEncoding::cleanData($this->cashAccountRows()),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب حسابات الدفع / Failed to retrieve cash accounts', $e);
        }
    }

    /**
     * بحث الأصناف وأصنافها الفرعية — نمطان: قائمة أصناف، أو أصناف فرعية لصنف واحد
     * Item and sub-item lookup — two modes: an item list, or one item's sub items.
     */
    public function items(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $sanfNo = (int) ($request->input('sanf_no') ?? 0);
        $limit = max(1, min((int) ($request->input('limit') ?? 30), 200));

        try {
            if ($sanfNo > 0) {
                $rows = DB::connection('oracle')->select(
                    'SELECT SANF_DNO, SANF_DNAME, LEN, WID, COLOR_NO, BARACOCE, PART_NO, CATEGORY
                       FROM ACCOUNTS.SANFS_D
                      WHERE SANF_NO = :sanf
                      ORDER BY LEN, WID, COLOR_NO',
                    ['sanf' => $sanfNo]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'تم جلب الأصناف الفرعية / Sub items retrieved',
                    'data' => ArabicEncoding::cleanData(array_map(fn ($r): array => [
                        'sanf_dno' => $this->int((array) $r, 'SANF_DNO'),
                        'name' => $this->str((array) $r, 'SANF_DNAME'),
                        'len' => (float) ($this->num((array) $r, 'LEN') ?? 0),
                        'wid' => (float) ($this->num((array) $r, 'WID') ?? 0),
                        'color_no' => $this->int((array) $r, 'COLOR_NO'),
                        'barcode' => $this->str((array) $r, 'BARACOCE'),
                        'part_no' => $this->str((array) $r, 'PART_NO'),
                        'category' => $this->str((array) $r, 'CATEGORY'),
                    ], $rows)),
                ], 200);
            }

            $query = DB::connection('oracle')->table(self::TABLE_SANFS.' s');

            if ($search !== '') {
                if (is_numeric($search)) {
                    $query->where('s.SANF_NO', (int) $search);
                } else {
                    $needle = $this->arabicNeedle($search);
                    $query->where(function ($q) use ($needle, $search): void {
                        $q->where('s.SANF_NAME', 'LIKE', '%'.$needle.'%')
                            ->orWhere('s.SANF_NAME', 'LIKE', '%'.$search.'%');
                    });
                }
            }

            $rows = $query
                ->select([
                    's.SANF_NO', 's.SANF_NAME', 's.CAP', 's.BUY_PRICE', 's.SANF_PRICE',
                    's.CAT_NO', 's.STYL_NO', 's.CATEGORY',
                    DB::raw('(SELECT COUNT(*) FROM ACCOUNTS.SANFS_D d WHERE d.SANF_NO = s.SANF_NO) AS SUB_COUNT'),
                ])
                ->orderBy('s.SANF_NO')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الأصناف بنجاح / Items retrieved',
                'data' => ArabicEncoding::cleanData(array_map(fn ($r): array => [
                    'sanf_no' => $this->int((array) $r, 'SANF_NO'),
                    'sanf_name' => $this->str((array) $r, 'SANF_NAME'),
                    'cap' => (float) ($this->num((array) $r, 'CAP') ?? 1),
                    'cost' => (float) ($this->num((array) $r, 'BUY_PRICE') ?? 0),
                    'price' => (float) ($this->num((array) $r, 'SANF_PRICE') ?? 0),
                    'cat_no' => $this->int((array) $r, 'CAT_NO'),
                    'style_no' => $this->int((array) $r, 'STYL_NO'),
                    'category' => $this->str((array) $r, 'CATEGORY'),
                    'sub_count' => $this->int((array) $r, 'SUB_COUNT') ?? 0,
                ], $rows->all())),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب الأصناف / Failed to retrieve items', $e);
        }
    }

    // ── helpers ──────────────────────────────────────────────

    /**
     * استعلام القائمة الأساسي — الربط مع المخزن والمورد وحساب الطرف
     * The base list query — joins the store, the supplier and the party account.
     */
    private function baseListQuery()
    {
        return DB::connection('oracle')->table(self::TABLE.' b')
            ->leftJoin('ACCOUNTS.STORES s', 's.STORE_NO', '=', 'b.STORE_NO')
            ->leftJoin('ACCOUNTS.PROV p', 'p.PROV_NO', '=', 'b.PROV_NO')
            ->leftJoin('ACCOUNTS.CHART_ACC c', 'c.ACC_NO', '=', 'b.ACC_NO1')
            ->leftJoin('ACCOUNTS.CURRENCY cu', 'cu.CUR_NO', '=', 'b.CUR_NO1')
            ->select([
                'b.BILL_NO', 'b.YEAR', 'b.BILL_TYPE', 'b.PROV_NO', 'b.PROV_NAME', 'b.MANUAL_BILL_NO',
                'b.PROV_BILL', 'b.TOTAL', 'b.DISCOUNT', 'b.NET', 'b.CUR_NO', 'b.CUR_PRICE',
                'b.CUR_NO1', 'b.CUR_PRICE1', 'b.STORE_NO', 'b.ENTRY_NO', 'b.ACC_NO', 'b.ACC_NO1',
                'b.SANF_NUMS', 'b.BRANCH_NO', 'b.SEQ_PRICE1',
                's.STORE_NAME', 'c.ACC_NAME AS PARTY_NAME', 'cu.CUR_NAME AS CUR_NAME',
                DB::raw('TO_CHAR(b.DAT, \'YYYY-MM-DD\') AS DAT_ISO'),
                DB::raw('ROUND(NVL(b.NET, 0) * NVL(b.CUR_PRICE1, 1), 2) AS NET_BASE'),
                DB::raw('(SELECT COUNT(*) FROM ACCOUNTS.RETURN_BUY r WHERE r.BILL_NO = b.BILL_NO AND r.BILL_YEAR = b.YEAR) AS RETURN_COUNT'),
                // عدد الأصناف الحقيقي من سطور الفاتورة (SANF_NUMS = 1 تاريخياً).
                // The real line count from the lines table (SANF_NUMS is literally 1).
                DB::raw('(SELECT COUNT(*) FROM ACCOUNTS.BUY_DETAILES d WHERE d.BILL_NO = b.BILL_NO AND d.YEAR = b.YEAR) AS ITEMS_COUNT'),
            ]);
    }

    /**
     * فلاتر القائمة على استعلام واحد
     * The list filters applied to one query.
     */
    private function applyListFilters($query, Request $request): void
    {
        $search = trim((string) ($request->input('search') ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                if (is_numeric($search)) {
                    $n = (int) $search;
                    $q->where('b.BILL_NO', $n)
                        ->orWhere('b.MANUAL_BILL_NO', $n)
                        ->orWhere('b.PROV_BILL', 'LIKE', '%'.$search.'%')
                        ->orWhere('b.PROV_NO', $n);
                }

                $needle = $this->arabicNeedle($search);
                $q->orWhere('b.PROV_NAME', 'LIKE', '%'.$needle.'%')
                    ->orWhere('b.PROV_NAME', 'LIKE', '%'.$search.'%');
            });
        }

        if (($from = $this->dateInput($request, 'from_date')) !== null) {
            $query->whereRaw('b.DAT >= TO_DATE(?, \'YYYY-MM-DD\')', [$from]);
        }

        if (($to = $this->dateInput($request, 'to_date')) !== null) {
            $query->whereRaw('b.DAT < TO_DATE(?, \'YYYY-MM-DD\') + 1', [$to]);
        }

        foreach (['bill_type' => 'b.BILL_TYPE', 'year' => 'b.YEAR', 'branch' => 'b.BRANCH_NO', 'store_no' => 'b.STORE_NO'] as $input => $column) {
            $value = $request->input($input);

            if ($value !== null && $value !== '') {
                $query->where($column, (int) $value);
            }
        }

        if (($provNo = $request->input('prov_no')) !== null && $provNo !== '') {
            $accNo = (int) ($request->input('acc_no') ?? 0);

            $query->where(function ($q) use ($provNo, $accNo): void {
                $q->where('b.PROV_NO', (int) $provNo);

                if ($accNo > 0) {
                    $q->orWhere('b.ACC_NO1', $accNo);
                }
            });
        }

        if (($curNo = $request->input('cur_no')) !== null && $curNo !== '') {
            $query->where('b.CUR_NO1', (int) $curNo);
        }

        if (($min = $request->input('min_amount')) !== null && $min !== '') {
            $query->whereRaw('NVL(b.NET, 0) >= ?', [(float) $min]);
        }

        if (($max = $request->input('max_amount')) !== null && $max !== '') {
            $query->whereRaw('NVL(b.NET, 0) <= ?', [(float) $max]);
        }

        $status = (string) ($request->input('status') ?? '');

        if ($status === 'posted') {
            $query->whereNotNull('b.ENTRY_NO');
        } elseif ($status === 'unposted') {
            $query->whereNull('b.ENTRY_NO');
        }

        $hasReturns = (string) ($request->input('has_returns') ?? '');

        if ($hasReturns === 'yes') {
            $query->whereRaw('EXISTS (SELECT 1 FROM ACCOUNTS.RETURN_BUY r WHERE r.BILL_NO = b.BILL_NO AND r.BILL_YEAR = b.YEAR)');
        } elseif ($hasReturns === 'no') {
            $query->whereRaw('NOT EXISTS (SELECT 1 FROM ACCOUNTS.RETURN_BUY r WHERE r.BILL_NO = b.BILL_NO AND r.BILL_YEAR = b.YEAR)');
        }
    }

    /**
     * الفرز بقائمة بيضاء — المفتاح الغريب يعود للتاريخ
     * A closed sort whitelist; unknown keys fall back to the date.
     */
    private function resolveSort(Request $request): string
    {
        $allowed = [
            'date' => 'b.DAT',
            'bill_no' => 'b.BILL_NO',
            'manual_no' => 'b.MANUAL_BILL_NO',
            'supplier' => 'b.PROV_NAME',
            'net' => 'b.NET',
            'net_base' => 'ROUND(NVL(b.NET, 0) * NVL(b.CUR_PRICE1, 1), 2)',
        ];

        $key = (string) ($request->input('sort') ?? 'date');
        $column = $allowed[$key] ?? $allowed['date'];
        $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        return $column.' '.$dir;
    }

    /**
     * صف الفاتورة في القائمة
     * An invoice row in the list.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toSummary(array $row): array
    {
        $net = (float) ($this->num($row, 'NET') ?? 0);
        $rate = (float) ($this->num($row, 'CUR_PRICE1') ?? 1);

        return [
            'bill_no' => $this->int($row, 'BILL_NO'),
            'year' => $this->int($row, 'YEAR'),
            'bill_type' => $this->int($row, 'BILL_TYPE'),
            'date' => $this->str($row, 'DAT_ISO'),
            'manual_bill_no' => $this->int($row, 'MANUAL_BILL_NO'),
            'prov_bill' => $this->str($row, 'PROV_BILL'),
            'prov_no' => $this->int($row, 'PROV_NO'),
            'prov_name' => $this->str($row, 'PROV_NAME') ?? $this->str($row, 'PARTY_NAME'),
            'store_no' => $this->int($row, 'STORE_NO'),
            'store_name' => $this->str($row, 'STORE_NAME'),
            'acc_no1' => $this->int($row, 'ACC_NO1'),
            'total' => round((float) ($this->num($row, 'TOTAL') ?? 0), 2),
            'discount' => round((float) ($this->num($row, 'DISCOUNT') ?? 0), 2),
            'net' => round($net, 2),
            'cur_no1' => $this->int($row, 'CUR_NO1'),
            'cur_price1' => $rate,
            'net_base' => round($net * $rate, 2),
            'entry_no' => $this->int($row, 'ENTRY_NO'),
            'posted' => $this->int($row, 'ENTRY_NO') !== null,
            'return_count' => $this->int($row, 'RETURN_COUNT') ?? 0,
            'branch_no' => $this->int($row, 'BRANCH_NO'),
            'items_count' => $this->int($row, 'ITEMS_COUNT') ?? 0,
            'cur_name' => $this->str($row, 'CUR_NAME'),
        ];
    }

    /**
     * سطر فاتورة في الاستعراض
     * An invoice line in the detail view.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toLine(array $row): array
    {
        return [
            'seq' => $this->int($row, 'SEQ'),
            'sanf_no' => $this->int($row, 'SANF_NO'),
            'sanf_name' => $this->str($row, 'SANF_NAME'),
            'sanf_dno' => $this->int($row, 'SANF_DNO'),
            'sub_name' => $this->str($row, 'SANF_DNAME'),
            'barcode' => $this->str($row, 'SUB_PART_NO'),
            'unit_name' => $this->str($row, 'UNIT_NAME'),
            'qty' => (float) ($this->num($row, 'QTY') ?? 0),
            'cap' => (float) ($this->num($row, 'CAP') ?? 1),
            'pcs' => (float) ($this->num($row, 'PCS') ?? 0),
            'price' => (float) ($this->num($row, 'SANF_PRICE') ?? 0),
            'total' => round((float) ($this->num($row, 'TOTAL') ?? 0), 2),
            'discount_rate' => (float) ($this->num($row, 'DISCOUNT_RATE') ?? 0),
            'discount' => round((float) ($this->num($row, 'DISCOUNT1') ?? 0), 2),
            'stk_cost' => (float) ($this->num($row, 'STK_COST') ?? 0),
            'stk_tot' => round((float) ($this->num($row, 'STK_TOT') ?? 0), 2),
            'category' => $this->str($row, 'CATEGORY'),
            'cat_no' => $this->int($row, 'CAT_NO'),
            'style_no' => $this->int($row, 'STYLE_NO'),
            'color_no' => $this->int($row, 'COLOR_NO'),
        ];
    }

    /**
     * مجاميع مشتقة من السطور — لتقاطع حساب الشاشة مع الخادم
     * Totals derived from the lines, so the screen's arithmetic can be cross-checked.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, float|int>
     */
    private function lineTotals(array $lines, float $headerDiscount): array
    {
        $total = 0.0;
        $discount = 0.0;
        $base = 0.0;

        foreach ($lines as $line) {
            $total += (float) $line['total'];
            $discount += (float) $line['discount'];
            $base += (float) $line['stk_tot'];
        }

        return [
            'lines_total' => round($total, 2),
            'lines_discount' => round($discount, 2),
            'header_discount' => round($headerDiscount, 2),
            'net' => round($total - max($discount, 0) - max($headerDiscount, 0), 2),
            'net_base' => round($base, 2),
            'count' => count($lines),
        ];
    }

    /**
     * رأس الفاتورة بمفاتيح كبيرة مع تواريخ ISO
     * The invoice header with uppercase keys and ISO dates.
     *
     * @return array<string, mixed>|null
     */
    private function invoiceHeader(int $billNo, int $year): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT b.*,
                    TO_CHAR(b.DAT, \'YYYY-MM-DD\') AS DAT_ISO,
                    TO_CHAR(b.CREATED_ON, \'YYYY-MM-DD\') AS CREATED_ON_ISO,
                    TO_CHAR(b.LAST_UPDATED_ON, \'YYYY-MM-DD\') AS LAST_UPDATED_ON_ISO
               FROM ACCOUNTS.BUY_BILL b
              WHERE b.BILL_NO = :bill_no AND b.YEAR = :year',
            ['bill_no' => $billNo, 'year' => $year]
        );

        return $this->upperRow($row);
    }

    /** عدد المردودات المرتبطة بفاتورة / how many returns reference the invoice */
    private function returnsCount(int $billNo, int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.RETURN_BUY WHERE BILL_NO = :bill_no AND BILL_YEAR = :year',
            ['bill_no' => $billNo, 'year' => $year]
        );

        return (int) (reset($row) ?: 0);
    }

    /**
     * حسابات الدفع النقدي مع عملاتها
     * Cash accounts with their currencies.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cashAccountRows(): array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT ACC_NO, ACC_NAME FROM ACCOUNTS.CHART_ACC
              WHERE ACC_NO >= 12100000 AND ACC_NO < 12300000
              ORDER BY ACC_NO'
        );

        return array_map(fn ($r): array => [
            'acc_no' => $this->int((array) $r, 'ACC_NO'),
            'acc_name' => $this->str((array) $r, 'ACC_NAME'),
            'cur_no' => ChartAccountNumbering::currencyOf($this->int((array) $r, 'ACC_NO') ?? 0) ?? 1,
        ], $rows);
    }

    /**
     * اسم وعملة حساب مالي
     * A chart account's name and currency.
     *
     * @return array{acc_name: ?string, cur_no: ?int}
     */
    private function accountInfo(?int $accNo): array
    {
        if ($accNo === null || $accNo <= 0) {
            return ['acc_name' => null, 'cur_no' => null];
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT ACC_NAME FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :acc',
            ['acc' => $accNo]
        );

        return [
            'acc_name' => trim((string) (reset($row) ?: '')) ?: null,
            'cur_no' => ChartAccountNumbering::currencyOf($accNo),
        ];
    }

    /**
     * اسم المخزن وهل هو مخزوني
     * A store's name and whether it carries stock.
     *
     * @return array{store_name: ?string, is_stock: bool}
     */
    private function storeInfo(?int $storeNo): array
    {
        if ($storeNo === null || $storeNo <= 0) {
            return ['store_name' => null, 'is_stock' => false];
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT STORE_NAME FROM ACCOUNTS.STORES WHERE STORE_NO = :store',
            ['store' => $storeNo]
        );

        return [
            'store_name' => trim((string) (reset($row) ?: '')) ?: null,
            'is_stock' => StorePolicy::isStockStore($storeNo),
        ];
    }

    /**
     * قيم مميزة لعمود رقمي
     * Distinct values of a numeric column.
     *
     * @return array<int, int>
     */
    private function distinctInts($query, string $column): array
    {
        $values = [];
        $alias = strtoupper(substr($column, strpos($column, '.') + 1));

        foreach ($query->whereNotNull($column)->distinct()->select($column)->orderBy($column)->get() as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $value = $r[$alias] ?? reset($r);

            if ($value !== null) {
                $values[] = (int) $value;
            }
        }

        return array_values(array_unique($values));
    }
}
