<?php

namespace App\Http\Controllers\Sales;

use App\Support\ArabicEncoding;
use App\Support\SalesDocumentWriter;
use App\Support\SalesPolicy;
use App\Support\SalesPosting;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم فواتير المبيعات — قراءة فقط من ACCOUNTS.SALES_BILL + تفاصيلها SALES_DETAILES
 * Sales invoices controller — read-only over ACCOUNTS.SALES_BILL and its SALES_DETAILES lines.
 *
 * تميّز المبيعات عن التفصيل في الجدول المشترك هو `CUTTING_OR_SLAES = 2`؛ فالتفصيل يقرأ من
 * واجهة مستقلة، بينما المبيعات صفوف مباشرة هنا. الهوية مركّبة (BILL_NO, YEAR) لأن الرقم وحده غير
 * فريد عبر السنوات.
 * Sales and cutting share SALES_BILL; sales rows carry `CUTTING_OR_SLAES = 2`. The identity is the
 * composite (BILL_NO, YEAR) because the number alone repeats across years.
 */
class SalesInvoiceController extends SalesBaseController
{
    private const TABLE = 'ACCOUNTS.SALES_BILL';

    private const TABLE_DETAILS = 'ACCOUNTS.SALES_DETAILES';

    private const TABLE_SANFS = 'ACCOUNTS.SANFS';

    private const TABLE_CURRENCY = 'ACCOUNTS.CURRENCY';

    /**
     * قائمة الفواتير: بحث (رقم/اسم عميل) وفلاتر (مدة/نوع/حالة سداد/نوع سداد/مبلغ/فرع/سنة)
     * وفرز بقائمة بيضاء وتصفح. المجاميع تُقرأ كما هي، والمتبقي يُحسب (NET - AMOUNT_PAID).
     * The invoice list: search by number or customer, plus filters (period, type, payment status,
     * payment method, amount, branch, year), a whitelisted sort and paging. The remaining balance
     * is computed (NET - AMOUNT_PAID); NET is never shown as a balance.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $query = DB::connection('oracle')->table(self::TABLE)
                ->where('CUTTING_OR_SLAES', self::CUTTING_OR_SALES);

            $this->applyListFilters($query, $request);

            $total = (clone $query)->count();

            $sort = $this->resolveSort($request);

            $rows = $query
                ->select([
                    'BILL_NO', 'YEAR', 'BILL_TYPE', 'CUST_NO', 'CUST_NAME',
                    'MANUAL_BILL_NO', 'TOTAL', 'DISCOUNT', 'NET', 'AMOUNT_PAID',
                    'STORE_NO', 'BRANCH_NO', 'CUR_NO', 'SANF_NUMS', 'PAYMENT_METHOD',
                    'HANDING_DONE', 'TEL_NO1', 'BILLS_KINDS',
                    DB::raw("TO_CHAR(DAT,'YYYY-MM-DD') AS DAT_ISO"),
                    DB::raw('(NVL(NET,0) - NVL(AMOUNT_PAID,0)) AS REMAINING'),
                ])
                ->orderBy($sort['column'], $sort['dir'])
                ->orderByDesc('BILL_NO')
                ->offset($offset)
                ->limit($limit)
                ->get();

            // عدد الأصناف الحقيقي (SANF_NUMS يحمل 1 في الصفوف القديمة) يُقرأ للصفحة وحدها:
            // استعلام تجميعي واحد على مفاتيح الصفحة بدل subquery مترابطة تُقيَّم لكل صف من
            // الجدول قبل الفرز والقصّ — قيست 94 ثانية وبعد التغيير 0.1 ثانية.
            // The real line count (SANF_NUMS is literally 1 in legacy rows) is read for the page
            // alone: one grouped query over the page's keys instead of a correlated sub-query
            // evaluated for every row of the table before the sort and the row cap — 94s → 0.1s.
            $lineCounts = $this->lineCounts($rows->all());

            $items = array_map(fn ($row): array => $this->toSummary((array) $row, $lineCounts), $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب فواتير المبيعات بنجاح / Sales invoices retrieved',
                'data' => ArabicEncoding::cleanData($items),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب فواتير المبيعات / Failed to retrieve sales invoices', $e);
        }
    }

    /**
     * خيارات الفلترة (facets): السنوات والفروع وأنواع الفواتير ووسائل السداد المميزة.
     * Filter facets: distinct years, branches, bill types and payment methods. Kinds stay codes
     * because the database holds no name dictionary for BILL_TYPE.
     */
    public function filterOptions(): JsonResponse
    {
        try {
            $base = fn () => DB::connection('oracle')->table(self::TABLE)
                ->where('CUTTING_OR_SLAES', self::CUTTING_OR_SALES);

            $years = array_values(array_filter(array_map(
                static fn ($r): ?int => isset($r->YEAR) ? (int) $r->YEAR : null,
                $base()->distinct()->select('YEAR')->orderByDesc('YEAR')->get()->all()
            )));

            $branches = array_values(array_filter(array_map(
                static fn ($r): ?int => isset($r->BRANCH_NO) ? (int) $r->BRANCH_NO : null,
                $base()->whereNotNull('BRANCH_NO')->distinct()->select('BRANCH_NO')->orderBy('BRANCH_NO')->get()->all()
            )));

            $billTypes = array_values(array_filter(array_map(
                static fn ($r): ?int => isset($r->BILL_TYPE) ? (int) $r->BILL_TYPE : null,
                $base()->whereNotNull('BILL_TYPE')->distinct()->select('BILL_TYPE')->orderBy('BILL_TYPE')->get()->all()
            )));

            $methods = array_values(array_filter(array_map(
                static fn ($r): ?string => ($v = $r->PAYMENT_METHOD ?? null) ? trim((string) $v) : null,
                $base()->whereNotNull('PAYMENT_METHOD')->distinct()->select('PAYMENT_METHOD')->orderBy('PAYMENT_METHOD')->get()->all()
            )));

            return response()->json([
                'success' => true,
                'message' => 'تم جلب خيارات الفلترة بنجاح / Filter options retrieved',
                'data' => ArabicEncoding::cleanData([
                    'years' => $years,
                    'branches' => $branches,
                    'bill_types' => $billTypes,
                    'payment_methods' => $methods,
                ]),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب خيارات الفلترة / Failed to retrieve filter options', $e);
        }
    }

    /**
     * فاتورة واحدة: الرأس + سطور التفاصيل (بأسماء الأصناف) + المجاميع.
     * One invoice: header + detail lines (with item names) + totals.
     */
    public function show(Request $request, int $billNo, int $year): JsonResponse
    {
        try {
            $header = DB::connection('oracle')->table(self::TABLE)
                ->select([
                    'BILL_NO', 'YEAR', 'BILL_TYPE', 'CUST_NO', 'CUST_NAME', 'MANUAL_BILL_NO',
                    'TOTAL', 'DISCOUNT', 'DIS_RATE', 'NET', 'AMOUNT_PAID', 'STORE_NO', 'BRANCH_NO',
                    'CUR_NO', 'SANF_NUMS', 'PAYMENT_METHOD', 'HANDING_DONE', 'TEL_NO1', 'TEL_NO2',
                    'ADDRESS', 'BILLS_KINDS', 'DETAILES', 'BENEFICIARY_NAME', 'VOUCHER_NO',
                    'TRANSFER_TO_ACC_NAME', 'CREATED_BY_NAME',
                    DB::raw("TO_CHAR(DAT,'YYYY-MM-DD') AS DAT_ISO"),
                    DB::raw("TO_CHAR(HANDING_DATE,'YYYY-MM-DD') AS HANDING_DATE_ISO"),
                    DB::raw('(NVL(NET,0) - NVL(AMOUNT_PAID,0)) AS REMAINING'),
                ])
                ->where('BILL_NO', $billNo)
                ->where('YEAR', $year)
                ->where('CUTTING_OR_SLAES', self::CUTTING_OR_SALES)
                ->first();

            if (! $header) {
                return response()->json([
                    'success' => false,
                    'message' => 'فاتورة المبيعات غير موجودة / Sales invoice not found',
                ], 404);
            }

            $header = (array) $header;

            $currency = $this->str($header, 'CUR_NO') !== null
                ? DB::connection('oracle')->table(self::TABLE_CURRENCY)
                    ->select(['CUR_NAME'])->where('CUR_NO', (int) $this->num($header, 'CUR_NO'))->first()
                : null;

            $detailRows = DB::connection('oracle')->table(self::TABLE_DETAILS.' d')
                ->select([
                    'd.SEQ', 'd.SANF_NO', 'd.QTY', 'd.CAP', 'd.PCS', 'd.SANF_PRICE',
                    'd.DISCOUNT_RATE', 'd.DISCOUNT1', 'd.BOUNS', 'd.TOTAL', 'd.REMARKS',
                    'd.CATEGORY', 'd.COLOR_NO',
                    DB::raw('(SELECT MAX(s.SANF_NAME) FROM '.self::TABLE_SANFS.' s WHERE s.SANF_NO = d.SANF_NO) AS SANF_NAME'),
                ])
                ->where('d.BILL_NO', $billNo)
                ->where('d.YEAR', $year)
                ->orderBy('d.SEQ')
                ->get();

            $details = array_map(fn ($row): array => $this->toDetail((array) $row), $detailRows->all());

            $net = $this->num($header, 'NET');
            $paid = $this->num($header, 'AMOUNT_PAID');

            $payload = [
                'bill_no' => $this->int($header, 'BILL_NO'),
                'year' => $this->int($header, 'YEAR'),
                'manual_bill_no' => $this->int($header, 'MANUAL_BILL_NO'),
                'bill_type' => $this->int($header, 'BILL_TYPE'),
                'bills_kinds' => $this->int($header, 'BILLS_KINDS'),
                'date' => $this->str($header, 'DAT_ISO'),
                'handing_date' => $this->str($header, 'HANDING_DATE_ISO'),
                'cust_no' => $this->int($header, 'CUST_NO'),
                'cust_name' => $this->str($header, 'CUST_NAME'),
                'tel_no1' => $this->str($header, 'TEL_NO1'),
                'tel_no2' => $this->str($header, 'TEL_NO2'),
                'address' => $this->str($header, 'ADDRESS'),
                'beneficiary_name' => $this->str($header, 'BENEFICIARY_NAME'),
                'voucher_no' => $this->str($header, 'VOUCHER_NO'),
                'transfer_to_acc_name' => $this->str($header, 'TRANSFER_TO_ACC_NAME'),
                'created_by_name' => $this->str($header, 'CREATED_BY_NAME'),
                'payment_method' => $this->str($header, 'PAYMENT_METHOD'),
                'note' => $this->str($header, 'DETAILES'),
                'store_no' => $this->int($header, 'STORE_NO'),
                'branch_no' => $this->int($header, 'BRANCH_NO'),
                'cur_no' => $this->int($header, 'CUR_NO'),
                'cur_name' => $currency ? ($currency->CUR_NAME ?? $currency->cur_name ?? null) : null,
                'items_count' => count($detailRows),
                'total' => $net === null ? null : $this->num($header, 'TOTAL'),
                'discount' => $this->num($header, 'DISCOUNT'),
                'dis_rate' => $this->num($header, 'DIS_RATE'),
                'net' => $net,
                'amount_paid' => $paid,
                'remaining' => $this->num($header, 'REMAINING'),
                'payment_status' => $this->paymentStatus($net, $paid),
                'handing_done' => $this->int($header, 'HANDING_DONE'),
                'details' => $details,
            ];

            $this->logActivity($request, 'sales.invoice_viewed', self::ENTITY_INVOICE, "$billNo/$year", 'عرض فاتورة مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم جلب فاتورة المبيعات بنجاح / Sales invoice retrieved',
                'data' => ArabicEncoding::cleanData($payload),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب فاتورة المبيعات / Failed to retrieve sales invoice', $e);
        }
    }

    /**
     * تسجيل حدث طباعة أو تصدير — لا يمسّ أي جدول إرث.
     * Record a print or export event — touches no legacy table.
     */
    public function activity(Request $request, int $billNo, int $year): JsonResponse
    {
        $action = (string) ($request->input('action') ?? '');

        $map = [
            'print' => ['sales.invoice_printed', 'طباعة فاتورة مبيعات'],
            'export' => ['sales.invoices_exported', 'تصدير فاتورة مبيعات'],
        ];

        if (! isset($map[$action])) {
            return response()->json([
                'success' => false,
                'message' => 'قيمة action غير مدعومة / Unsupported action value',
            ], 422);
        }

        $this->logActivity($request, $map[$action][0], self::ENTITY_INVOICE, "$billNo/$year", $map[$action][1]);

        return response()->json(['success' => true, 'message' => 'تم التسجيل / Logged'], 200);
    }

    // ── الكتابة: إنشاء/تعديل/حذف (المرحلة ب) ──────────────────────────────────
    // ── Writes: create / update / delete (phase B) ────────────────────────────

    /**
     * مراجع نموذج الفاتورة: الأنواع والحسابات الافتراضية والمخازن والعملات والفروع
     * The invoice form's reference payload: bill types, default accounts, stores, currencies.
     */
    public function reference(): JsonResponse
    {
        try {
            $stores = DB::connection('oracle')->select(
                'SELECT STORE_NO, STORE_NAME, INV_ACC_NO FROM ACCOUNTS.STORES ORDER BY STORE_NO'
            );

            // CURRENCY يحمل الاسمان فقط؛ سعر الصرف يُدخل يدوياً في الفاتورة (CUR_PRICE في الرأس).
            // CURRENCY carries names only; the exchange rate is entered on the invoice itself.
            $currencies = DB::connection('oracle')->select(
                'SELECT CUR_NO, CUR_NAME FROM ACCOUNTS.CURRENCY ORDER BY CUR_NO'
            );

            $branches = DB::connection('oracle')->select(
                'SELECT BRANCH_NO, BRANCH_NAME_AR, BRANCH_NAME_EN FROM ACCOUNTS.BRANCHES ORDER BY BRANCH_NO'
            );

            // مفاتيح مثبتة بأحرف صغيرة كي لا يتغير عقد النموذج مع اختلاف حالة أعمدة OCI8.
            // Pinned lowercase keys so the form contract does not shift with OCI8 column casing.
            return response()->json([
                'success' => true,
                'message' => 'تم جلب مراجع المبيعات بنجاح / Sales reference retrieved',
                'data' => ArabicEncoding::cleanData(array_merge(SalesPolicy::reference(), [
                    'stores' => array_map(fn ($r): array => [
                        'store_no' => $this->int((array) $r, 'STORE_NO'),
                        'store_name' => $this->str((array) $r, 'STORE_NAME'),
                        'inv_acc_no' => $this->int((array) $r, 'INV_ACC_NO'),
                    ], $stores),
                    'currencies' => array_map(fn ($r): array => [
                        'cur_no' => $this->int((array) $r, 'CUR_NO'),
                        'cur_name' => $this->str((array) $r, 'CUR_NAME'),
                    ], $currencies),
                    'branches' => array_map(fn ($r): array => [
                        'branch_no' => $this->int((array) $r, 'BRANCH_NO'),
                        'branch_name_ar' => $this->str((array) $r, 'BRANCH_NAME_AR'),
                        'branch_name_en' => $this->str((array) $r, 'BRANCH_NAME_EN'),
                    ], $branches),
                ])),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مراجع المبيعات / Failed to retrieve the sales reference', $e);
        }
    }

    /**
     * بحث أصناف لنموذج الفاتورة — اسم أو رقم، بحد أعلى للمعروض
     * Item lookup for the invoice form — by name or number, with a hard page cap.
     *
     * ── لماذا مسار خاص بالمبيعات؟ لأن نموذج البيع يحتاج الأصناف حتى لو لم يملك المستخدم
     *    صلاحية `stores.items_view`؛ الصلاحية هنا `sales.*`.
     * ── Why a sales-local route: the sales form needs items even without stores.items_view.
     */
    public function items(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = max(1, min((int) ($request->input('limit') ?? 30), 100));

        try {
            $query = DB::connection('oracle')->table(self::TABLE_SANFS);

            if ($search !== '') {
                if (is_numeric($search)) {
                    $query->where('SANF_NO', (int) $search);
                } else {
                    $oracle = ArabicEncoding::toOracleSearchChars($search);
                    $query->where(function ($q) use ($oracle, $search): void {
                        $q->where('SANF_NAME', 'LIKE', '%'.$oracle.'%')
                            ->orWhere('SANF_NAME', 'LIKE', '%'.$search.'%');
                    });
                }
            }

            $rows = $query
                ->select(['SANF_NO', 'SANF_NAME', 'CAP', 'BUY_PRICE', 'SANF_PRICE', 'CAT_NO', 'STYL_NO', 'CATEGORY'])
                ->orderBy('SANF_NO')
                ->limit($limit)
                ->get();

            $items = array_map(fn ($r): array => [
                'sanf_no' => $this->int((array) $r, 'SANF_NO'),
                'sanf_name' => $this->str((array) $r, 'SANF_NAME'),
                'cap' => $this->num((array) $r, 'CAP'),
                'cost' => $this->num((array) $r, 'BUY_PRICE'),
                'price' => $this->num((array) $r, 'SANF_PRICE'),
                'cat_no' => $this->int((array) $r, 'CAT_NO'),
                'style_no' => $this->int((array) $r, 'STYL_NO'),
                'category' => $this->str((array) $r, 'CATEGORY'),
            ], $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الأصناف بنجاح / Items retrieved',
                'data' => ArabicEncoding::cleanData($items),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب الأصناف / Failed to retrieve items', $e);
        }
    }

    /**
     * بحث عملاء لنموذج الفاتورة — الاسم أو الرقم، مع رقم الحساب اللازم للآجل
     * Customer lookup for the invoice form — by name or number, with the ledger account
     * needed on credit bills.
     */
    public function customers(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = max(1, min((int) ($request->input('limit') ?? 30), 100));

        try {
            $query = DB::connection('oracle')->table('ACCOUNTS.CUST');

            if ($search !== '') {
                if (is_numeric($search)) {
                    $query->where('CUST_NO', (int) $search);
                } else {
                    $oracle = ArabicEncoding::toOracleSearchChars($search);
                    $query->where(function ($q) use ($oracle, $search): void {
                        $q->where('CUST_NAME', 'LIKE', '%'.$oracle.'%')
                            ->orWhere('CUST_NAME', 'LIKE', '%'.$search.'%');
                    });
                }
            }

            $rows = $query
                ->select(['CUST_NO', 'CUST_NAME', 'ACC_NO', 'TEL1', 'ADDR'])
                ->orderBy('CUST_NO')
                ->limit($limit)
                ->get();

            $customers = array_map(fn ($r): array => [
                'cust_no' => $this->int((array) $r, 'CUST_NO'),
                'cust_name' => $this->str((array) $r, 'CUST_NAME'),
                'acc_no' => $this->int((array) $r, 'ACC_NO'),
                'tel_no1' => $this->str((array) $r, 'TEL1'),
                'address' => $this->str((array) $r, 'ADDR'),
            ], $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب العملاء بنجاح / Customers retrieved',
                'data' => ArabicEncoding::cleanData($customers),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب العملاء / Failed to retrieve customers', $e);
        }
    }

    /**
     * إنشاء فاتورة مبيعات: رقم من العدّاد، قيد محاسبي، ثم الرأس والسطور — كلها في معاملة واحدة
     * Create a sales invoice: number from the counter, journal entry, then header and lines — all
     * inside one transaction so a failure never leaves a half document or an orphan entry.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->writePayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(
                fn (): array => $this->createInvoice($payload, $request)
            ));

            $this->logActivity($request, 'sales.invoice_created', self::ENTITY_INVOICE, $result['bill_no'].'/'.$result['year'], 'إنشاء فاتورة مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء فاتورة المبيعات بنجاح / Sales invoice created',
                'data' => $result,
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء فاتورة المبيعات / Failed to create the sales invoice', $e);
        }
    }

    /**
     * تعديل فاتورة: يُعاد الترحيل (قيد جديد) ثم تُستبدل السطور، ويُحذف القيد القديم في النهاية
     * Update an invoice: re-post a new entry, replace the lines, then drop the old entry.
     *
     * ── لماذا قيد جديد؟ لأن `ENTRY_NO` مفتاح أجنبي غير فارغ في الرأس، فحذف القيد القديم أولاً
     *    مستحيل؛ الترتيب الصحيح: قيد جديد ⇒ تحديث الرأس ⇒ حذف القديم ⇒ استبدال السطور.
     * ── Why a new entry: ENTRY_NO is a NOT NULL foreign key on the header, so the old entry can
     *    only be removed after the header points at the new one.
     */
    public function update(Request $request, int $billNo, int $year): JsonResponse
    {
        $payload = $this->writePayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $header = $this->invoiceHeader($billNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'فاتورة المبيعات غير موجودة / Sales invoice not found',
            ], 404);
        }

        $oldEntryNo = (int) ($this->int($header, 'ENTRY_NO') ?? 0);
        $payload['manual_no'] = (int) ($this->int($header, 'MANUAL_BILL_NO') ?? 0);

        try {
            $result = DB::connection('oracle')->transaction(function () use ($payload, $request, $billNo, $year, $oldEntryNo): array {
                $h = $payload['header'];
                $note = $this->documentNote($payload, (int) $payload['manual_no']);

                $entryNo = SalesPosting::postInvoice([
                    'bill_type' => $h['bill_type'],
                    'date' => $h['date'],
                    'manual_no' => $payload['manual_no'],
                    'cust_no' => $h['cust_no'],
                    'cust_name' => $h['cust_name'],
                    'net' => $payload['totals']['net'],
                    'debit_account' => $h['acc_no'],
                    'credit_account' => $h['acc_no1'],
                    'note' => $note,
                ]);

                DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.SALES_BILL
                        SET BILL_TYPE = :bill_type, DAT = TO_DATE(:dat, \'YYYY-MM-DD\'), TOTAL = :total,
                            DETAILES = :details, ENTRY_NO = :entry_no, CUST_NO = :cust_no,
                            CUST_NAME = :cust_name, DIS_RATE = :dis_rate, DISCOUNT = :discount,
                            NET = :net, CUR_NO = :cur_no, CUR_PRICE = :cur_price, STORE_NO = :store_no,
                            ACC_NO = :acc_no, ACC_NO1 = :acc_no1, BRANCH_NO = :branch_no,
                            PAYMENT_METHOD = :payment_method, TEL_NO1 = :tel_no1, ADDRESS = :address,
                            SANF_NUMS = :sanf_nums, AMOUNT_PAID = :amount_paid,
                            LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :updated_by,
                            LAST_UPDATED_BY_NAME = :updated_by_name
                      WHERE BILL_NO = :bill_no AND YEAR = :year AND CUTTING_OR_SLAES = :kind',
                    SalesDocumentWriter::bindings([
                        'bill_type' => $h['bill_type'],
                        'dat' => $h['date'],
                        'total' => $payload['totals']['total'],
                        'details' => $note,
                        'entry_no' => $entryNo,
                        'cust_no' => $h['cust_no'],
                        'cust_name' => $h['cust_name'],
                        'dis_rate' => $payload['totals']['rate'],
                        'discount' => $payload['totals']['discount'],
                        'net' => $payload['totals']['net'],
                        'cur_no' => $h['cur_no'],
                        'cur_price' => $h['cur_price'],
                        'store_no' => $h['store_no'],
                        'acc_no' => $h['acc_no'],
                        'acc_no1' => $h['acc_no1'],
                        'branch_no' => $h['branch_no'],
                        'payment_method' => $h['payment_method'],
                        'tel_no1' => $h['tel_no1'],
                        'address' => $h['address'],
                        'sanf_nums' => $payload['totals']['count'],
                        'amount_paid' => $payload['totals']['paid'],
                        'updated_by' => $request->user()?->getAuthIdentifier(),
                        'updated_by_name' => SalesDocumentWriter::clip($request->user()?->USER_NAME_AR ?? null, 200),
                        'bill_no' => $billNo,
                        'year' => $year,
                        'kind' => self::CUTTING_OR_SALES,
                    ])
                );

                if ($oldEntryNo > 0 && $oldEntryNo !== $entryNo) {
                    SalesPosting::deleteEntry($oldEntryNo);
                }

                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.SALES_DETAILES WHERE BILL_NO = :bill_no AND YEAR = :year',
                    ['bill_no' => $billNo, 'year' => $year]
                );

                SalesDocumentWriter::insertInvoiceLines($billNo, $year, $payload['lines']);

                return [
                    'bill_no' => $billNo,
                    'year' => $year,
                    'manual_bill_no' => $payload['manual_no'],
                    'entry_no' => $entryNo,
                ];
            });

            $this->logActivity($request, 'sales.invoice_updated', self::ENTITY_INVOICE, "$billNo/$year", 'تعديل فاتورة مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل فاتورة المبيعات بنجاح / Sales invoice updated',
                'data' => $result,
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل فاتورة المبيعات / Failed to update the sales invoice', $e);
        }
    }

    /**
     * حذف فاتورة: حذف **فيزيائي** للسطور ثم الرأس ثم القيد — لأن VIEW الحركة يتجاهل FLAG_CANCEL
     * Delete an invoice: physics only — lines, then header, then entry — because the movement view
     * ignores FLAG_CANCEL, so a cancelled row would still move stock and balances.
     *
     * ── يُرفض الحذف إذا وُجد مردود مرتبط بالفاتورة، وإلا بقي المردود يشير إلى رقم غير موجود.
     * ── Refused when a return references the invoice, otherwise the return would point at a
     *    number that no longer exists.
     */
    public function destroy(Request $request, int $billNo, int $year): JsonResponse
    {
        $header = $this->invoiceHeader($billNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'فاتورة المبيعات غير موجودة / Sales invoice not found',
            ], 404);
        }

        $linked = DB::connection('oracle')->select(
            'SELECT RETURN_NO FROM ACCOUNTS.RETURN_SALES WHERE BILL_NO = :bill_no AND BILL_YEAR = :year',
            ['bill_no' => $billNo, 'year' => $year]
        );

        if ($linked !== []) {
            $numbers = implode(', ', array_map(static fn ($r): string => (string) ($r->RETURN_NO ?? $r->return_no ?? ''), $linked));

            return response()->json([
                'success' => false,
                'message' => "لا يمكن حذف الفاتورة لوجود مردودات مرتبطة بها: {$numbers} / The invoice has linked returns.",
                'errors' => ['returns' => $numbers],
            ], 422);
        }

        $entryNo = (int) ($this->int($header, 'ENTRY_NO') ?? 0);

        try {
            DB::connection('oracle')->transaction(function () use ($billNo, $year, $entryNo): void {
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.SALES_DETAILES WHERE BILL_NO = :bill_no AND YEAR = :year',
                    ['bill_no' => $billNo, 'year' => $year]
                );

                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.SALES_BILL WHERE BILL_NO = :bill_no AND YEAR = :year AND CUTTING_OR_SLAES = :kind',
                    ['bill_no' => $billNo, 'year' => $year, 'kind' => self::CUTTING_OR_SALES]
                );

                SalesPosting::deleteEntry($entryNo);
            });

            $this->logActivity($request, 'sales.invoice_deleted', self::ENTITY_INVOICE, "$billNo/$year", 'حذف فاتورة مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم حذف فاتورة المبيعات بنجاح / Sales invoice deleted',
                'data' => ['bill_no' => $billNo, 'year' => $year],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف فاتورة المبيعات / Failed to delete the sales invoice', $e);
        }
    }

    /**
     * رأس الفاتورة الخام (بما فيه ENTRY_NO و MANUAL_BILL_NO) أو null
     * The raw invoice header (with ENTRY_NO and MANUAL_BILL_NO) or null.
     *
     * @return array<string, mixed>|null
     */
    private function invoiceHeader(int $billNo, int $year): ?array
    {
        $row = DB::connection('oracle')->table(self::TABLE)
            ->where('BILL_NO', $billNo)
            ->where('YEAR', $year)
            ->where('CUTTING_OR_SLAES', self::CUTTING_OR_SALES)
            ->first();

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    // ── helpers ──────────────────────────────────────────────

    /**
     * إنشاء الفاتورة داخل المعاملة — الترقيم ثم القيد ثم الرأس ثم السطور
     * Create the invoice inside the transaction — number, entry, header, then lines.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createInvoice(array $payload, Request $request): array
    {
        $h = $payload['header'];
        $year = (int) $h['year'];
        $billNo = SalesPolicy::nextBillNo($year);
        $manualNo = SalesPolicy::nextManualBillNo($year, (int) $h['bill_type']);
        $note = $this->documentNote($payload, $manualNo);

        // القيد أولاً: `ENTRY_NO` مفتاح أجنبي في الرأس، فلا يمكن إدراج الرأس قبله.
        // Entry first: ENTRY_NO is a foreign key on the header, so the header cannot precede it.
        $entryNo = SalesPosting::postInvoice([
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'manual_no' => $manualNo,
            'cust_no' => $h['cust_no'],
            'cust_name' => $h['cust_name'],
            'net' => (float) $payload['totals']['net'],
            'debit_account' => (int) $h['acc_no'],
            'credit_account' => (int) $h['acc_no1'],
            'note' => $note,
        ]);

        SalesDocumentWriter::insertInvoice([
            'bill_no' => $billNo,
            'year' => $year,
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'total' => (float) $payload['totals']['total'],
            'details' => $note,
            'entry_no' => $entryNo,
            'cust_no' => $h['cust_no'],
            'cust_name' => $h['cust_name'],
            'dis_rate' => (float) $payload['totals']['rate'],
            'discount' => (float) $payload['totals']['discount'],
            'net' => (float) $payload['totals']['net'],
            'cur_no' => (int) $h['cur_no'],
            'cur_price' => (float) $h['cur_price'],
            'store_no' => (int) $h['store_no'],
            'acc_no' => (int) $h['acc_no'],
            'acc_no1' => (int) $h['acc_no1'],
            'manual_no' => $manualNo,
            'branch_no' => (int) $h['branch_no'],
            'created_by' => $request->user()?->getAuthIdentifier(),
            'created_by_name' => SalesDocumentWriter::clip($request->user()?->USER_NAME_AR ?? null, 200),
            'the_time' => gmdate('h:i:s A'),
            'payment_method' => $h['payment_method'],
            'tel_no1' => $h['tel_no1'],
            'address' => $h['address'],
            'sanf_nums' => (int) $payload['totals']['count'],
            'amount_paid' => (float) $payload['totals']['paid'],
        ]);

        SalesDocumentWriter::insertInvoiceLines($billNo, $year, $payload['lines']);

        return [
            'bill_no' => $billNo,
            'year' => $year,
            'manual_bill_no' => $manualNo,
            'entry_no' => $entryNo,
            'net' => (float) $payload['totals']['net'],
        ];
    }

    /**
     * نص البيان المحاسبي: بيان المستخدم أو صياغة النظام القديم
     * The journal narration: the user's text, or the legacy wording.
     *
     * @param  array<string, mixed>  $payload
     */
    private function documentNote(array $payload, int $manualNo): string
    {
        $h = $payload['header'];

        if (! empty($h['note'])) {
            return SalesDocumentWriter::clip((string) $h['note'], 180) ?? '';
        }

        $label = SalesPolicy::BILL_TYPE_LABELS[(int) $h['bill_type']] ?? '';

        return SalesDocumentWriter::clip("فاتورة مبيعات {$label} رقم {$manualNo}", 180) ?? '';
    }

    /**
     * التحقق من حمولة الكتابة وتجهيزها (رأس + سطور + مجاميع) أو رد 422
     * Validate and prepare the write payload (header + lines + totals) or a 422 response.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function writePayload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bill_type' => 'required|integer|in:1,2',
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'store_no' => 'required|integer|exists:oracle.STORES,STORE_NO',
            'branch_no' => 'nullable|integer',
            'cust_no' => 'nullable|integer',
            'cust_name' => 'nullable|string|max:150',
            'tel_no1' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:200',
            'acc_no' => 'nullable|integer',
            'acc_no1' => 'nullable|integer',
            'cur_no' => 'nullable|integer',
            'cur_price' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:180',
            'lines' => 'required|array|min:1|max:'.SalesPolicy::MAX_LINES,
            'lines.*.sanf_no' => 'required|integer|min:1',
            'lines.*.qty' => 'required|numeric|gt:0',
            'lines.*.price' => 'required|numeric|min:0',
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الفاتورة غير صالحة / The invoice payload is invalid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $billType = (int) $data['bill_type'];
        $custNo = isset($data['cust_no']) ? (int) $data['cust_no'] : null;
        $customer = $this->customer($custNo);

        if ($custNo !== null && $customer === null) {
            return response()->json([
                'success' => false,
                'message' => "العميل [{$custNo}] غير موجود / Customer [{$custNo}] not found",
                'errors' => ['cust_no' => 'unknown customer'],
            ], 422);
        }

        if ($billType === SalesPolicy::BILL_CREDIT && $customer === null) {
            return response()->json([
                'success' => false,
                'message' => 'الفاتورة الآجلة تحتاج عميلاً / A credit invoice requires a customer',
                'errors' => ['cust_no' => 'required for credit invoices'],
            ], 422);
        }

        // الحساب المدين: ما يختاره المستخدم، وإلا حساب العميل في الآجل وصندوق افتراضي في النقدي.
        // The debit account: the user's choice, else the customer ledger (credit) or a default cash box.
        $accNo = (int) ($data['acc_no'] ?? 0);

        if ($accNo <= 0) {
            $accNo = $billType === SalesPolicy::BILL_CREDIT
                ? (int) ($customer['ACC_NO'] ?? 0)
                : SalesPolicy::DEFAULT_CASH_ACCOUNT;
        }

        if ($accNo <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'حساب الطرف (مدين) مطلوب / A debit account is required',
                'errors' => ['acc_no' => 'required'],
            ], 422);
        }

        $accNo1 = (int) ($data['acc_no1'] ?? 0) ?: SalesPolicy::revenueAccount($billType);
        $guard = SalesDocumentWriter::normalizeLines((array) $data['lines']);

        if (! $guard['ok']) {
            return response()->json([
                'success' => false,
                'message' => $guard['message'],
                'errors' => $guard['errors'],
            ], 422);
        }

        $totals = SalesDocumentWriter::totals($guard['lines'], (float) ($data['discount'] ?? 0));

        if ($totals['net'] <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'صافي الفاتورة يجب أن يكون أكبر من صفر / The invoice net must be greater than zero',
                'errors' => ['net' => 'must be > 0'],
            ], 422);
        }

        $custName = $data['cust_name'] ?? $customer['CUST_NAME'] ?? ($billType === SalesPolicy::BILL_CASH ? 'مبيعات نقدية' : null);

        // النقدي مدفوع عند الشباك؛ والآجل يبدأ بلا سداد ويُسجَّل لاحقاً بسند قبض.
        // Cash is settled at the counter; credit starts unpaid and is settled later by a receipt.
        $paid = $billType === SalesPolicy::BILL_CASH
            ? $totals['net']
            : min((float) ($data['amount_paid'] ?? 0), $totals['net']);

        $linesTotal = array_sum(array_map(static fn (array $l): float => (float) $l['total'], $guard['lines']));

        return [
            'header' => [
                'bill_type' => $billType,
                'date' => (string) $data['date'],
                'year' => (int) substr((string) $data['date'], 0, 4),
                'store_no' => (int) $data['store_no'],
                'branch_no' => (int) ($data['branch_no'] ?? 1),
                'cust_no' => $custNo,
                'cust_name' => SalesDocumentWriter::clip((string) $custName, 150),
                'tel_no1' => SalesDocumentWriter::clip($data['tel_no1'] ?? ($customer['TEL1'] ?? null), 50),
                'address' => SalesDocumentWriter::clip($data['address'] ?? ($customer['ADDR'] ?? null), 200),
                'acc_no' => $accNo,
                'acc_no1' => $accNo1,
                'cur_no' => (int) ($data['cur_no'] ?? 1),
                'cur_price' => (float) ($data['cur_price'] ?? 1),
                'payment_method' => SalesDocumentWriter::clip($data['payment_method'] ?? null, 50),
                'note' => SalesDocumentWriter::clip($data['note'] ?? null, 180),
            ],
            'lines' => $guard['lines'],
            'totals' => [
                'total' => $totals['total'],
                'discount' => $totals['discount'],
                'rate' => $linesTotal > 0 ? round($totals['discount'] / $linesTotal * 100, 2) : 0.0,
                'net' => $totals['net'],
                'count' => $totals['count'],
                'paid' => round($paid, 2),
            ],
        ];
    }

    /**
     * بطاقة العميل أو null
     * The customer card or null.
     *
     * @return array<string, mixed>|null
     */
    private function customer(?int $custNo): ?array
    {
        if ($custNo === null || $custNo <= 0) {
            return null;
        }

        $row = DB::connection('oracle')->selectOne(
            'SELECT CUST_NO, CUST_NAME, ACC_NO, TEL1, TEL2, ADDR FROM ACCOUNTS.CUST WHERE CUST_NO = :c',
            ['c' => $custNo]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * تنفيذ عملية الترقيم مع إعادة المحاولة عند تصادم القيد الفريد (ORA-00001)
     * Run a numbering-sensitive write, retrying when the unique key collides (ORA-00001).
     *
     * ── لماذا إعادة المحاولة؟ عدّاد `MAX+1` يتصادم تحت التزامن مع النظام المكتبي، والقيد
     *    الفريد هو ما يحمي البيانات: تُرفض المعاملة كاملة وتُعاد بأرقام جديدة.
     * ── Why retry: a MAX+1 counter collides under concurrent desktop use; the unique key protects
     *    the data — the whole transaction is rolled back and retried with fresh numbers.
     *
     * @param  callable(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function withNumberRetry(callable $operation): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (QueryException $e) {
                $unique = (int) ($e->errorInfo[1] ?? 0) === 1 || str_contains($e->getMessage(), 'ORA-00001');

                if (! $unique || $attempt >= SalesPolicy::NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    private function applyListFilters($query, Request $request): void
    {
        $search = trim((string) ($request->input('search') ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $n = (int) $search;
                    $q->where('BILL_NO', $n)->orWhere('MANUAL_BILL_NO', $n);
                } else {
                    $oracle = ArabicEncoding::toOracleSearchChars($search);
                    $q->where('CUST_NAME', 'LIKE', '%'.$oracle.'%')
                        ->orWhere('CUST_NAME', 'LIKE', '%'.$search.'%');
                }
            });
        }

        if (($from = $this->dateInput($request, 'from_date')) !== null) {
            $query->whereRaw("DAT >= TO_DATE(?, 'YYYY-MM-DD')", [$from]);
        }
        if (($to = $this->dateInput($request, 'to_date')) !== null) {
            $query->whereRaw("DAT < TO_DATE(?, 'YYYY-MM-DD') + 1", [$to]);
        }

        if (($billType = $request->input('bill_type')) !== null && $billType !== '') {
            $query->where('BILL_TYPE', (int) $billType);
        }
        if (($year = $request->input('year')) !== null && $year !== '') {
            $query->where('YEAR', (int) $year);
        }
        if (($branch = $request->input('branch')) !== null && $branch !== '') {
            $query->where('BRANCH_NO', (int) $branch);
        }
        if (($method = trim((string) ($request->input('payment_method') ?? ''))) !== '') {
            $oracle = ArabicEncoding::toOracleSearchChars($method);
            $query->where('PAYMENT_METHOD', 'LIKE', '%'.$oracle.'%');
        }
        if (($min = $request->input('min_amount')) !== null && $min !== '' && is_numeric($min)) {
            $query->where('NET', '>=', (float) $min);
        }
        if (($max = $request->input('max_amount')) !== null && $max !== '' && is_numeric($max)) {
            $query->where('NET', '<=', (float) $max);
        }

        match ((string) ($request->input('payment_status') ?? '')) {
            'paid' => $query->whereRaw('NVL(AMOUNT_PAID,0) >= NVL(NET,0)')->where('NET', '>', 0),
            'partial' => $query->where('AMOUNT_PAID', '>', 0)->whereRaw('AMOUNT_PAID < NVL(NET,0)'),
            'unpaid' => $query->whereRaw('NVL(AMOUNT_PAID,0) <= 0')->where('NET', '>', 0),
            default => null,
        };
    }

    /**
     * قائمة بيضاء للفرز: مفتاح الطلب ⇒ عمود حقيقي. المفتاح الغريب يعود للتاريخ.
     * A closed sort whitelist: request key ⇒ real column. Unknown keys fall back to date.
     *
     * @return array{column: string, dir: string}
     */
    private function resolveSort(Request $request): array
    {
        $allowed = [
            'date' => 'DAT',
            'bill_no' => 'BILL_NO',
            'manual_no' => 'MANUAL_BILL_NO',
            'customer' => 'CUST_NAME',
            'total' => 'TOTAL',
            'net' => 'NET',
        ];
        $key = (string) ($request->input('sort') ?? 'date');
        $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return ['column' => $allowed[$key] ?? 'DAT', 'dir' => $dir];
    }

    private function dateInput(Request $request, string $key): ?string
    {
        $value = trim((string) ($request->input($key) ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * عدد سطور كل فاتورة في الصفحة الحالية — استعلام واحد على مفاتيح الصفحة
     * Line counts for the current page's invoices — one query over the page keys.
     *
     * @param  array<int, mixed>  $rows
     * @return array<string, int>
     */
    private function lineCounts(array $rows): array
    {
        $billNos = [];

        foreach ($rows as $row) {
            $billNo = $this->int((array) $row, 'BILL_NO');

            if ($billNo !== null) {
                $billNos[$billNo] = true;
            }
        }

        if ($billNos === []) {
            return [];
        }

        $counts = DB::connection('oracle')->table(self::TABLE_DETAILS)
            ->select(['BILL_NO', 'YEAR', DB::raw('COUNT(*) AS CNT')])
            ->whereIn('BILL_NO', array_keys($billNos))
            ->groupBy(['BILL_NO', 'YEAR'])
            ->get();

        $map = [];

        foreach ($counts as $count) {
            $row = array_change_key_case((array) $count, CASE_UPPER);
            $map[$this->int($row, 'BILL_NO').'|'.$this->int($row, 'YEAR')] = $this->int($row, 'CNT');
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $lineCounts
     * @return array<string, mixed>
     */
    private function toSummary(array $row, array $lineCounts = []): array
    {
        $net = $this->num($row, 'NET');
        $paid = $this->num($row, 'AMOUNT_PAID');

        return [
            'bill_no' => $this->int($row, 'BILL_NO'),
            'year' => $this->int($row, 'YEAR'),
            'manual_bill_no' => $this->int($row, 'MANUAL_BILL_NO'),
            'bill_type' => $this->int($row, 'BILL_TYPE'),
            'bills_kinds' => $this->int($row, 'BILLS_KINDS'),
            'date' => $this->str($row, 'DAT_ISO'),
            'cust_no' => $this->int($row, 'CUST_NO'),
            'cust_name' => $this->str($row, 'CUST_NAME'),
            'total' => $this->num($row, 'TOTAL'),
            'discount' => $this->num($row, 'DISCOUNT'),
            'net' => $net,
            'amount_paid' => $paid,
            'remaining' => $this->num($row, 'REMAINING'),
            'payment_status' => $this->paymentStatus($net, $paid),
            'payment_method' => $this->str($row, 'PAYMENT_METHOD'),
            'items_count' => $lineCounts[$this->int($row, 'BILL_NO').'|'.$this->int($row, 'YEAR')]
                ?? $this->int($row, 'ITEMS_COUNT')
                ?? $this->int($row, 'SANF_NUMS'),
            'branch_no' => $this->int($row, 'BRANCH_NO'),
            'store_no' => $this->int($row, 'STORE_NO'),
            'handing_done' => $this->int($row, 'HANDING_DONE'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toDetail(array $row): array
    {
        return [
            'seq' => $this->int($row, 'SEQ'),
            'sanf_no' => $this->int($row, 'SANF_NO'),
            'sanf_name' => $this->str($row, 'SANF_NAME'),
            'qty' => $this->num($row, 'QTY'),
            'cap' => $this->num($row, 'CAP'),
            'pcs' => $this->num($row, 'PCS'),
            'price' => $this->num($row, 'SANF_PRICE'),
            'discount_rate' => $this->num($row, 'DISCOUNT_RATE'),
            'discount' => $this->num($row, 'DISCOUNT1'),
            'bouns' => $this->num($row, 'BOUNS'),
            'total' => $this->num($row, 'TOTAL'),
            'category' => $this->str($row, 'CATEGORY'),
            'remarks' => $this->str($row, 'REMARKS'),
        ];
    }
}
