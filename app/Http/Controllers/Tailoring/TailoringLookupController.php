<?php

namespace App\Http\Controllers\Tailoring;

use App\Http\Controllers\Controller;
use App\Support\ArabicEncoding;
use App\Support\CuttingViewReader;
use App\Support\Tailoring\TailoringCustomerResolver;
use App\Support\Tailoring\TailoringPolicy;
use App\Support\Tailoring\TailoringReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم مرجعيات إنشاء فاتورة التفصيل — قراءة فقط، كلها بصلاحية tailoring.create
 * The tailoring-creation lookup controller — read-only, all under the tailoring.create grant.
 *
 * ── لماذا مسارات create/* منفصلة عن العملاء/المخازن؟ كي لا يتطلب إنشاء الفاتورة منح
 *    customers.view أو stores.* — صلاحية tailoring.create وحدها تكفي والخادم يقرر (قرار الخطة).
 * ── Why separate create/* routes instead of the customers/stores lookups: creating a bill
 *    must not require customers.view or stores.* grants — tailoring.create alone suffices and
 *    the server decides (the plan's decision).
 *
 * ── «المقاسات السابقة» تُقرأ من الجداول الأساسية لا من الواجهة (قرار D2): استعلام مباشر
 *    بالعميل لا يعتمد على اكتمال الواجهة، ويجلب التاريخ والمستفيد كاملين.
 * ── Previous measurements read from the base tables, not the view (decision D2): a direct
 *    per-customer query independent of the view's completeness, carrying full dates.
 */
class TailoringLookupController extends Controller
{
    private const DEFAULT_LIMIT = 30;

    private const MAX_LIMIT = 100;

    /** سقف قائمة المقاسات السابقة — أحدث 100 موديل كافيان للاختيار */
    /** The previous-models ceiling — the newest 100 models are plenty for picking. */
    private const MAX_PREVIOUS_MODELS = 100;

    // «كل المقاسات» تمتد لكل عملاء ومستفيدي النظام — سقف أوسع من قائمة العميل
    // "All measurements" spans every customer and beneficiary in the system — a wider
    // cap than the per-customer list.
    private const MAX_ALL_PREVIOUS_MODELS = 300;

    /** سقف قائمة المستفيدين / the beneficiaries ceiling */
    private const MAX_BENEFICIARIES = 100;

    /** سقف قائمة «كل المستفيدين» على مستوى النظام / the system-wide beneficiaries ceiling */
    private const MAX_ALL_BENEFICIARIES = 200;

    /** سقف قائمة العاملين لكل نوع / the per-type workers ceiling */
    private const MAX_WORKERS = 200;

    /** سقف قائمة الهواتف التاريخية / the historical-phones ceiling */
    private const MAX_PHONES = 20;

    /**
     * مرجع الإنشاء الكامل: القواميس والتسميات والأرقام التالية والحسابات
     * The full creation reference: dictionaries, labels, next numbers and accounts.
     */
    public function reference(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'تم جلب مرجع الإنشاء بنجاح / Creation reference retrieved',
                'data' => TailoringReference::build(),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مرجع الإنشاء / Failed to retrieve the creation reference', $e);
        }
    }

    /**
     * الأرقام التالية وحدها — لإعادة التحيين بعد بقاء النموذج مفتوحاً
     * The next numbers alone — for a refresh after the form sat open.
     */
    public function nextNumbers(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'تم جلب الأرقام التالية / Next numbers retrieved',
                'data' => TailoringReference::nextNumbers(),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب الأرقام التالية / Failed to retrieve the next numbers', $e);
        }
    }

    /**
     * بحث العملاء لمنتقي الإضافة — بالاسم (الصيغتين) والهاتف والرقم الحرفي
     * Customer search for the creation picker — by name (both byte forms), phone, number.
     */
    public function customers(Request $request): JsonResponse
    {
        // الأرقام العربية المشرقية (٠-٩ / ۰-۹) تُطبَّع إلى لاتينية قبل أي فحص —
        // القاعدة تخزن الأرقام لاتينية والكيبورد العربي يكتبها مشرقية، وبلا تطبيع
        // يفشل البحث برقم الهاتف كلياً (المشكلة المرصودة).
        // Arabic-Indic digits (٠-٩ / ۰-۹) normalize to Latin before any check — the
        // database stores Latin digits while an Arabic keyboard types Eastern ones;
        // without this phone search never matches (observed failure).
        $search = strtr(trim((string) ($request->input('search') ?? '')), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $limit = $this->resolveLimit($request);

        try {
            $clauses = [];
            $bindings = [];

            if ($search !== '') {
                $oracle = ArabicEncoding::toOracleSearchChars($search);
                $bindings['search_oracle'] = '%'.($oracle ?? $search).'%';
                $bindings['search_name'] = '%'.$search.'%';
                $bindings['search_tel1'] = '%'.$search.'%';
                $bindings['search_tel2'] = '%'.$search.'%';

                // البحث بالاسم (الصيغتين) والهاتف على CUST وحده — الجدول صغير فالبحث
                // فوري. البحث في أرقام الفواتير يُنفَّذ منفصلاً أدناه وليس بـ OR + IN
                // في نفس الجملة: دمجهما يدمّر خطة المحرِّر ويعلق الاستعلام دقائق
                // (المشكلة المرصودة — كل طلبات النافذة كانت تتعطل خلفه).
                // Name and phone search runs on CUST alone — small table, instant. The
                // bill-phone lookup runs as a separate second phase, never as OR + IN in
                // one statement: combining them wrecks the plan and hangs the query for
                // minutes (the observed failure — every picker request stalled behind it).
                $clauses[] = '(CUST_NAME LIKE :search_oracle OR CUST_NAME LIKE :search_name
                               OR TEL1 LIKE :search_tel1 OR TEL2 LIKE :search_tel2)';

                if (is_numeric($search)) {
                    $clauses[] = 'CUST_NO = :search_no';
                    $bindings['search_no'] = (int) $search;
                }
            }

            $where = $clauses === [] ? '1 = 1' : implode(' AND ', $clauses);

            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT CUST_NO, CUST_NAME, TEL1, TEL2, ADDR, ACC_NO, STOPPING_CUST
                      FROM ACCOUNTS.CUST
                     WHERE '.$where.'
                     ORDER BY CUST_NO DESC
                 ) WHERE ROWNUM <= :row_max',
                array_merge($bindings, ['row_max' => $limit])
            );

            $items = [];

            // المرحلة الثانية — بحث الهاتف على أحدث فواتير التفصيل: للأرقام فقط
            // وعندما لم يجد CUST شيئاً. المسح محدود بأحدث 20000 فاتورة بفهرس
            // BILL_NO فهو فوري (~15ms، مقيس).
            // Phase two — the bill-phone search over the latest cutting bills: numbers
            // only and only when CUST found nothing. Capped at the latest 20000 bills
            // via the BILL_NO index — instant (~15ms, measured).
            if ($search !== '' && is_numeric($search) && $rows === []) {
                $billRows = DB::connection('oracle')->select(
                    'SELECT DISTINCT CUST_NO
                       FROM (SELECT CUST_NO, TEL_NO1
                               FROM ACCOUNTS.SALES_BILL
                              WHERE TEL_NO1 IS NOT NULL
                              ORDER BY BILL_NO DESC)
                      WHERE ROWNUM <= 20000
                        AND TEL_NO1 LIKE :search_bill_tel
                        AND ROWNUM <= :bill_max',
                    ['search_bill_tel' => '%'.$search.'%', 'bill_max' => $limit]
                );

                foreach ($billRows as $billRow) {
                    $custNo = (int) (CuttingViewReader::num((array) $billRow, 'CUST_NO') ?? 0);

                    if ($custNo > 0 && ($customer = TailoringCustomerResolver::find($custNo)) !== null) {
                        $items[] = $customer;
                    }
                }
            }

            foreach ($rows as $row) {
                $items[] = TailoringCustomerResolver::find(
                    (int) (CuttingViewReader::num((array) $row, 'CUST_NO') ?? 0)
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب العملاء بنجاح / Customers retrieved',
                'data' => $items,
                'meta' => ['limit' => $limit],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في بحث العملاء / Failed to search customers', $e);
        }
    }

    /**
     * أرقام العميل: TEL1/TEL2 + الأرقام التاريخية من فواتير التفصيل، بلا تكرار
     * The customer's numbers: TEL1/TEL2 plus the historical bill numbers, deduplicated.
     */
    public function phones(int $custNo): JsonResponse
    {
        if ($custNo <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'رقم العميل غير صالح / Invalid customer number',
            ], 422);
        }

        try {
            $phones = [];
            $seen = [];

            $customer = TailoringCustomerResolver::find($custNo);

            foreach ([$customer['tel1'] ?? null, $customer['tel2'] ?? null] as $phone) {
                if ($phone !== null && $phone !== '' && ! isset($seen[$phone])) {
                    $seen[$phone] = true;
                    $phones[] = $phone;
                }
            }

            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT TEL_NO1 AS PHONE
                      FROM ACCOUNTS.SALES_BILL
                     WHERE CUST_NO = :no
                       AND CUTTING_OR_SLAES = :k
                       AND TEL_NO1 IS NOT NULL
                     ORDER BY DAT DESC
                 ) WHERE ROWNUM <= :cap',
                ['no' => $custNo, 'k' => TailoringPolicy::CUTTING_OR_SLAES, 'cap' => self::MAX_PHONES]
            );

            foreach ($rows as $row) {
                $phone = trim((string) ($row->phone ?? array_values((array) $row)[0] ?? ''));

                if ($phone !== '' && ! isset($seen[$phone])) {
                    $seen[$phone] = true;
                    $phones[] = $phone;
                }
            }

            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT TEL_NO2 AS PHONE
                      FROM ACCOUNTS.SALES_BILL
                     WHERE CUST_NO = :no
                       AND CUTTING_OR_SLAES = :k
                       AND TEL_NO2 IS NOT NULL
                     ORDER BY DAT DESC
                 ) WHERE ROWNUM <= :cap',
                ['no' => $custNo, 'k' => TailoringPolicy::CUTTING_OR_SLAES, 'cap' => self::MAX_PHONES]
            );

            foreach ($rows as $row) {
                $phone = trim((string) ($row->phone ?? array_values((array) $row)[0] ?? ''));

                if ($phone !== '' && ! isset($seen[$phone])) {
                    $seen[$phone] = true;
                    $phones[] = $phone;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب أرقام العميل / Customer phones retrieved',
                'data' => [
                    'cust_no' => $custNo,
                    'phones' => array_slice($phones, 0, self::MAX_PHONES),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب أرقام العميل / Failed to retrieve customer phones', $e);
        }
    }

    /**
     * مقاسات العميل السابقة — أحدث الموديلات من الجداول الأساسية (قرار D2)،
     * و`scope=all` يجلب كل مقاسات النظام لكل العملاء، و`beneficiary` يفلتر باسم المستفيد.
     * The customer's previous models — newest first, from the base tables (decision D2);
     * `scope=all` loads every model in the system for all customers, and `beneficiary`
     * filters by the beneficiary's name.
     */
    public function previousModels(int $custNo, Request $request): JsonResponse
    {
        $scope = (string) $request->query('scope', '');
        $beneficiary = trim((string) $request->query('beneficiary', ''));
        $search = trim((string) $request->query('search', ''));

        if ($custNo <= 0 && $scope !== 'all' && $beneficiary === '' && $search === '') {
            return response()->json([
                'success' => false,
                'message' => 'رقم العميل غير صالح / Invalid customer number',
            ], 422);
        }

        try {
            $bindings = [
                'k' => TailoringPolicy::CUTTING_OR_SLAES,
                'cap' => self::MAX_PREVIOUS_MODELS,
            ];

            // فلتر العميل يسقط عند scope=all أو فلتر المستفيد الصريح أو البحث الحرفي
            // The customer filter drops for scope=all, an explicit beneficiary filter
            // or a free-text search.
            $custFilter = '';

            if ($scope !== 'all' && $beneficiary === '' && $search === '') {
                $custFilter = 'WHERE b.CUST_NO = :cust AND b.CUTTING_OR_SLAES = :k';
                $bindings['cust'] = $custNo;
            } else {
                $custFilter = 'WHERE b.CUTTING_OR_SLAES = :k';
                // البحث و«كل المقاسات» يمتدان لكل النظام — سقف أوسع
                // Search and "all measurements" span the whole system — wider cap.
                $bindings['cap'] = self::MAX_ALL_PREVIOUS_MODELS;
            }

            if ($beneficiary !== '') {
                // البحث بالصيغة المحوّلة كما يفرض عقد الترميز C1
                // Searching the converted form — the C1 encoding contract.
                $custFilter .= ' AND s.BENEFICIARYNAME = :ben';
                $bindings['ben'] = ArabicEncoding::toOracleSearchChars($beneficiary);
            }

            if ($search !== '') {
                // البحث الحرفي يمرّ على المستفيد واسم العميل ونوع الموديل بالصيغتين
                // — كي يجد أي اسم داخل النظام مهما كان العميل المحدد بالفاتورة
                // (طلب المستخدم الحرفي).
                // The free-text search sweeps beneficiary, customer name and model type
                // in both byte forms — so any name in the system is found regardless of
                // the bill's own customer (literal request).
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $custFilter .= ' AND (s.BENEFICIARYNAME LIKE :search_oracle
                                OR b.CUST_NAME LIKE :search_plain
                                OR s.STYLE_NAME LIKE :search_style)';
                $bindings['search_oracle'] = '%'.($oracleSearch ?? $search).'%';
                $bindings['search_plain'] = '%'.$search.'%';
                $bindings['search_style'] = '%'.$search.'%';
            }

            $rows = DB::connection('oracle')->select(
                "SELECT * FROM (
                    SELECT s.BILL_NO,
                           s.YEAR,
                           s.SEQ_NO,
                           MAX(s.SHAPE_STYLE_NO) AS SHAPE_STYLE_NO,
                           MAX(s.STYLE_NAME) AS STYLE_NAME,
                           MAX(s.BENEFICIARYNAME) AS BENEFICIARYNAME,
                           MAX(b.MANUAL_BILL_NO) AS MANUAL_BILL_NO,
                           MAX(b.CUST_NO) AS CUST_NO,
                           MAX(b.CUST_NAME) AS CUST_NAME,
                           MAX(b.TEL_NO1) AS BILL_TEL,
                           TO_CHAR(MAX(b.DAT), 'YYYY-MM-DD') AS DAT_ISO,
                           COUNT(m.SEQ_NO1) AS PIECES
                      FROM ACCOUNTS.SALES_DETAILES_SHAPES s
                      JOIN ACCOUNTS.SALES_BILL b
                        ON b.BILL_NO = s.BILL_NO AND b.YEAR = s.YEAR
                      LEFT JOIN ACCOUNTS.MULTI_CLOTHS m
                        ON m.BILL_NO = s.BILL_NO AND m.YEAR = s.YEAR AND m.SEQ_NO = s.SEQ_NO
                      {$custFilter}
                     GROUP BY s.BILL_NO, s.YEAR, s.SEQ_NO
                     ORDER BY MAX(b.DAT) DESC, s.SEQ_NO DESC
                 ) WHERE ROWNUM <= :cap",
                $bindings
            );

            $items = [];

            foreach ($rows as $row) {
                $data = (array) $row;

                $items[] = [
                    'bill_no' => CuttingViewReader::num($data, 'BILL_NO'),
                    'year' => CuttingViewReader::num($data, 'YEAR'),
                    'seq_no' => CuttingViewReader::num($data, 'SEQ_NO'),
                    'shape_style_no' => CuttingViewReader::num($data, 'SHAPE_STYLE_NO'),
                    'type_name' => CuttingViewReader::txt($data, 'STYLE_NAME'),
                    'beneficiary' => CuttingViewReader::txt($data, 'BENEFICIARYNAME'),
                    'cust_no' => CuttingViewReader::num($data, 'CUST_NO'),
                    'cust_name' => CuttingViewReader::txt($data, 'CUST_NAME'),
                    // هاتف العميل من الفاتورة — ليدعم البحث برقم الهاتف في النافذة
                    // The customer's phone from the bill — enables phone search in the
                    // window.
                    'phone' => CuttingViewReader::txt($data, 'BILL_TEL'),
                    'manual_bill_no' => CuttingViewReader::num($data, 'MANUAL_BILL_NO'),
                    'date' => CuttingViewReader::txt($data, 'DAT_ISO'),
                    'pieces_count' => (int) (CuttingViewReader::num($data, 'PIECES') ?? 0),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب المقاسات السابقة / Previous models retrieved',
                'data' => $items,
                'meta' => ['cust_no' => $custNo, 'scope' => $scope],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب المقاسات السابقة / Failed to retrieve previous models', $e);
        }
    }

    /**
     * تفصيل موديل سابق واحد — المقاسات والمواصفات بلا أقمشة (تُختار من جديد)
     * One previous model in full — measurements and specs, no fabrics (they are picked fresh).
     */
    public function previousModelDetail(int $custNo, int $billNo, int $year, int $seqNo): JsonResponse
    {
        try {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT s.SEQ_NO, s.SHAPE_STYLE_NO, s.STYLE_NAME, s.STYL_NO,
                        s.BENEFICIARYNAME, s.NOTES2,
                        s.BUTTON_TYPE, s.BUTTON_NO, s.BUTTONS_NUM,
                        s.COM_CABAK_TYPE, s.NECK_TYPE, s.POCKET_OR_WITHOUT,
                        s.GABZOR_TYPE, s.MOBILE_POCKET, s.PEN_POCKET, s.TIPPER_TYPE,
                        s.SHAPE_GABROZ_NO,
                        s.L1, s.L2, s.L3, s.L4, s.L5, s.L6, s.L7, s.L8, s.L9, s.L10, s.L11, s.L12,
                        b.CUST_NO
                   FROM ACCOUNTS.SALES_DETAILES_SHAPES s
                   JOIN ACCOUNTS.SALES_BILL b
                     ON b.BILL_NO = s.BILL_NO AND b.YEAR = s.YEAR
                  WHERE s.BILL_NO = :bill_no
                    AND s.YEAR = :year
                    AND s.SEQ_NO = :seq_no',
                ['bill_no' => $billNo, 'year' => $year, 'seq_no' => $seqNo]
            );

            if ($row === []) {
                return response()->json([
                    'success' => false,
                    'message' => "الموديل [{$seqNo}] من فاتورة [{$billNo}/{$year}] غير موجود"
                        .' / Previous model not found',
                ], 404);
            }

            // الموديل المطلوب يجب أن يعود للعميل المطلوب: مفتاح رباعي لا ثلاثي.
            // الصفر يعني «بلا عميل محدد» — موديل مختار من «كل المقاسات».
            // The requested model must belong to the requested customer: a four-part key.
            // Zero means "no specific customer" — a model picked from "all measurements".
            $ownerNo = (int) (CuttingViewReader::num($row, 'CUST_NO') ?? 0);

            if ($custNo > 0 && $ownerNo !== $custNo) {
                return response()->json([
                    'success' => false,
                    'message' => 'هذا الموديل لا يعود لهذا العميل / This model does not belong to this customer',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب تفصيل الموديل السابق / Previous model retrieved',
                'data' => [
                    'bill_no' => $billNo,
                    'year' => $year,
                    'seq_no' => $seqNo,
                    'shape_style_no' => CuttingViewReader::num($row, 'SHAPE_STYLE_NO'),
                    'type_name' => CuttingViewReader::txt($row, 'STYLE_NAME'),
                    'styl_no' => CuttingViewReader::num($row, 'STYL_NO'),
                    'beneficiary' => CuttingViewReader::txt($row, 'BENEFICIARYNAME'),
                    'notes' => CuttingViewReader::txt($row, 'NOTES2'),
                    'specs' => [
                        'button_type' => CuttingViewReader::txt($row, 'BUTTON_TYPE'),
                        'button_no' => CuttingViewReader::txt($row, 'BUTTON_NO'),
                        'buttons_num' => CuttingViewReader::num($row, 'BUTTONS_NUM'),
                        'com_cabak_type' => CuttingViewReader::txt($row, 'COM_CABAK_TYPE'),
                        'neck_type' => CuttingViewReader::txt($row, 'NECK_TYPE'),
                        'pocket_or_without' => CuttingViewReader::txt($row, 'POCKET_OR_WITHOUT'),
                        'gabzor_type' => CuttingViewReader::txt($row, 'GABZOR_TYPE'),
                        'mobile_pocket' => CuttingViewReader::txt($row, 'MOBILE_POCKET'),
                        'pen_pocket' => CuttingViewReader::txt($row, 'PEN_POCKET'),
                        'tipper_type' => CuttingViewReader::txt($row, 'TIPPER_TYPE'),
                    ],
                    'measurements' => CuttingViewReader::measurements($row),
                    'shape_gabroz_no' => CuttingViewReader::num($row, 'SHAPE_GABROZ_NO'),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب تفصيل الموديل / Failed to retrieve the previous model', $e);
        }
    }

    /**
     * مستفيدو العميل السابقون — أو كل مستفيدي النظام بـ `scope=all`
     * The customer's previous beneficiaries — or every beneficiary in the system with
     * `scope=all`.
     *
     * ── «كل المستفيدين» يخدم الحالة التي يفصّل صاحبها لغير عملائه المسجلين: القائمة تُقرأ
     *    من كل فواتير التفصيل بلا فلترة عميل، ويظل الاسم نصاً حراً قابلاً للكتابة.
     * ── "All beneficiaries" serves the case of tailoring for someone not a registered
     *    customer: the list reads every cutting bill with no customer filter, and the name
     *    stays free text.
     */
    public function beneficiaries(Request $request, int $custNo): JsonResponse
    {
        $scope = (string) $request->query('scope', 'customer');

        if ($custNo <= 0 && $scope !== 'all') {
            return response()->json([
                'success' => false,
                'message' => 'رقم العميل غير صالح / Invalid customer number',
            ], 422);
        }

        try {
            if ($scope === 'all') {
                $rows = DB::connection('oracle')->select(
                    'SELECT * FROM (
                        SELECT DISTINCT s.BENEFICIARYNAME AS BENEFICIARY
                          FROM ACCOUNTS.SALES_DETAILES_SHAPES s
                          JOIN ACCOUNTS.SALES_BILL b
                            ON b.BILL_NO = s.BILL_NO AND b.YEAR = s.YEAR
                         WHERE b.CUTTING_OR_SLAES = :k
                           AND s.BENEFICIARYNAME IS NOT NULL
                         ORDER BY BENEFICIARY
                     ) WHERE ROWNUM <= :cap',
                    [
                        'k' => TailoringPolicy::CUTTING_OR_SLAES,
                        'cap' => self::MAX_ALL_BENEFICIARIES,
                    ]
                );
            } else {
                $rows = DB::connection('oracle')->select(
                    'SELECT * FROM (
                        SELECT DISTINCT s.BENEFICIARYNAME AS BENEFICIARY
                          FROM ACCOUNTS.SALES_DETAILES_SHAPES s
                          JOIN ACCOUNTS.SALES_BILL b
                            ON b.BILL_NO = s.BILL_NO AND b.YEAR = s.YEAR
                         WHERE b.CUST_NO = :cust
                           AND b.CUTTING_OR_SLAES = :k
                           AND s.BENEFICIARYNAME IS NOT NULL
                         ORDER BY BENEFICIARY
                     ) WHERE ROWNUM <= :cap',
                    [
                        'cust' => $custNo,
                        'k' => TailoringPolicy::CUTTING_OR_SLAES,
                        'cap' => self::MAX_BENEFICIARIES,
                    ]
                );
            }

            $items = [];

            foreach ($rows as $row) {
                $data = (array) $row;
                $name = CuttingViewReader::txt($data, 'BENEFICIARY');

                if ($name !== null && $name !== '') {
                    $items[] = $name;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب المستفيدين / Beneficiaries retrieved',
                'data' => $items,
                'meta' => ['cust_no' => $custNo, 'scope' => $scope],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب المستفيدين / Failed to retrieve beneficiaries', $e);
        }
    }

    /**
     * العاملون الثلاثة بحساباتهم المالية — لربط الفاتورة بجاري العاملين
     * The three worker kinds with their ledgers — for the worker-ledger link.
     */
    public function workers(): JsonResponse
    {
        try {
            $defs = [
                'employees' => ['table' => 'ACCOUNTS.TAILOR_EMPLOYEES', 'pk' => 'EMP_NO', 'name' => 'EMP_NAME'],
                'cutters' => ['table' => 'ACCOUNTS.CUTTER', 'pk' => 'CUTTER_NO', 'name' => 'CUTTER_NAME'],
                'tailors' => ['table' => 'ACCOUNTS.TAILOR', 'pk' => 'TAILOR_NO', 'name' => 'TAILOR_NAME'],
            ];

            $out = [];

            foreach ($defs as $key => $def) {
                $rows = DB::connection('oracle')->select(
                    'SELECT * FROM (
                        SELECT '.$def['pk'].' AS WORKER_NO, '.$def['name'].' AS WORKER_NAME, ACC_NO
                          FROM '.$def['table'].'
                         ORDER BY '.$def['name'].'
                     ) WHERE ROWNUM <= :cap',
                    ['cap' => self::MAX_WORKERS]
                );

                $list = [];

                foreach ($rows as $row) {
                    $data = (array) $row;
                    $name = CuttingViewReader::txt($data, 'WORKER_NAME');

                    $list[] = [
                        'no' => CuttingViewReader::num($data, 'WORKER_NO'),
                        'name' => $name,
                        'acc_no' => CuttingViewReader::num($data, 'ACC_NO'),
                    ];
                }

                $out[$key] = $list;
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب العاملين / Workers retrieved',
                'data' => $out,
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب العاملين / Failed to retrieve workers', $e);
        }
    }

    /**
     * الأقمشة الرئيسية المتوفرة — التي مجموع PAL لها أكبر من صفر خارج المخازن المستثناة
     * The available main fabrics — those whose PAL sum is positive outside excluded stores.
     */
    public function fabrics(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = $this->resolveLimit($request);

        try {
            $clauses = ['s.TYPE_NO = 101'];
            $bindings = [];

            if ($search !== '') {
                $oracle = ArabicEncoding::toOracleSearchChars($search);
                $bindings['search_oracle'] = '%'.($oracle ?? $search).'%';
                $bindings['search_plain'] = '%'.$search.'%';

                $clauses[] = '(s.SANF_NAME LIKE :search_oracle OR s.SANF_NAME LIKE :search_plain)';
            }

            $excluded = implode(',', TailoringPolicy::EXCLUDED_STORES);
            $where = implode(' AND ', $clauses);

            $rows = DB::connection('oracle')->select(
                'SELECT * FROM (
                    SELECT s.SANF_NO, s.SANF_NAME, s.PART_NO, s.CATEGORY,
                           SUM(p.PAL) AS AVAILABLE,
                           ROW_NUMBER() OVER (ORDER BY s.SANF_NAME) AS RN
                      FROM ACCOUNTS.SANFS s
                      JOIN ACCOUNTS.SANFS_D d ON d.SANF_NO = s.SANF_NO
                      JOIN ACCOUNTS.SANFS_D_PAL p
                        ON p.SANF_DNO = d.SANF_DNO AND p.STORE_NO NOT IN ('.$excluded.')
                     WHERE '.$where.'
                     GROUP BY s.SANF_NO, s.SANF_NAME, s.PART_NO, s.CATEGORY
                    HAVING SUM(p.PAL) > 0
                 ) WHERE RN <= :cap',
                array_merge($bindings, ['cap' => $limit])
            );

            $items = [];

            foreach ($rows as $row) {
                $data = (array) $row;

                $items[] = [
                    'sanf_no' => CuttingViewReader::num($data, 'SANF_NO'),
                    'sanf_name' => CuttingViewReader::txt($data, 'SANF_NAME'),
                    'part_no' => CuttingViewReader::txt($data, 'PART_NO'),
                    'category' => CuttingViewReader::txt($data, 'CATEGORY'),
                    'available' => CuttingViewReader::num($data, 'AVAILABLE'),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الأقمشة / Fabrics retrieved',
                'data' => $items,
                'meta' => ['limit' => $limit],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب الأقمشة / Failed to retrieve fabrics', $e);
        }
    }

    /**
     * الأقمشة الفرعية المتوفرة لقماش رئيسي — PAL > 0 فقط مع اللون وآخر عرض مستخدم
     * The available variants of one main fabric — PAL > 0 only, with color and the width
     * last used for that variant.
     *
     * ── عرض القماش يُجلب تلقائياً من تاريخ القطع (آخر CLOTH_TYPE مستخدم لهذا SANF_DNO)
     *    ليعرض للقراءة فقط في النموذج — المستخدم لا يختاره (طلب المستخدم 2026-11-21).
     * ── The width comes from the variant's own cutting history (the last CLOTH_TYPE used)
     *    and is displayed read-only in the form — the user never picks it (user request).
     */
    public function fabricVariants(int $sanfNo): JsonResponse
    {
        if ($sanfNo <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'رقم القماش غير صالح / Invalid fabric number',
            ], 422);
        }

        try {
            $excluded = implode(',', TailoringPolicy::EXCLUDED_STORES);

            $rows = DB::connection('oracle')->select(
                'SELECT d.SANF_DNO, d.SANF_DNAME, d.PART_NO, d.COLOR_NO, d.DCOLOR,
                        c.COLOR_NAME AS COLOR_NAME,
                        SUM(p.PAL) AS AVAILABLE
                   FROM ACCOUNTS.SANFS_D d
                   JOIN ACCOUNTS.SANFS_D_PAL p
                     ON p.SANF_DNO = d.SANF_DNO AND p.STORE_NO NOT IN ('.$excluded.')
                   LEFT JOIN ACCOUNTS.SANFS_COLOR c
                     ON c.COLOR_NO = d.COLOR_NO
                  WHERE d.SANF_NO = :sanf
                  GROUP BY d.SANF_DNO, d.SANF_DNAME, d.PART_NO, d.COLOR_NO, d.DCOLOR, c.COLOR_NAME
                 HAVING SUM(p.PAL) > 0
                  ORDER BY d.SANF_DNO',
                ['sanf' => $sanfNo]
            );

            // آخر عرض مستخدم لكل قماش فرعي من تاريخ القطع / the last width per variant
            // from cutting history.
            $widths = $this->lastWidths(array_map(
                static fn ($row): int => (int) (CuttingViewReader::num((array) $row, 'SANF_DNO') ?? 0),
                $rows
            ));

            $items = [];

            foreach ($rows as $row) {
                $data = (array) $row;
                $dno = (int) (CuttingViewReader::num($data, 'SANF_DNO') ?? 0);

                $items[] = [
                    'sanf_dno' => $dno,
                    'sanf_dname' => CuttingViewReader::txt($data, 'SANF_DNAME'),
                    'part_no' => CuttingViewReader::txt($data, 'PART_NO'),
                    'color_no' => CuttingViewReader::num($data, 'COLOR_NO'),
                    'color_text' => CuttingViewReader::txt($data, 'DCOLOR'),
                    'color_name' => CuttingViewReader::txt($data, 'COLOR_NAME'),
                    'available' => CuttingViewReader::num($data, 'AVAILABLE'),
                    'cloth_type' => $widths[$dno] ?? null,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الأقمشة الفرعية / Fabric variants retrieved',
                'data' => $items,
                'meta' => ['sanf_no' => $sanfNo],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب الأقمشة الفرعية / Failed to retrieve fabric variants', $e);
        }
    }

    /**
     * آخر عرض قماش مستخدم لكل SANF_DNO من تاريخ القطع — استعلام دفعة واحدة
     * The last used CLOTH_TYPE per SANF_DNO from cutting history — one batched query.
     *
     * @param  array<int, int>  $sanfDnos
     * @return array<int, string>
     */
    private function lastWidths(array $sanfDnos): array
    {
        $sanfDnos = array_values(array_unique(array_filter($sanfDnos, fn ($d): bool => $d > 0)));

        if ($sanfDnos === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach (array_slice($sanfDnos, 0, 100) as $index => $dno) {
            $placeholders[] = ':d'.$index;
            $bindings['d'.$index] = $dno;
        }

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT SANF_DNO, CLOTH_TYPE
                   FROM (
                        SELECT m.SANF_DNO, m.CLOTH_TYPE,
                               ROW_NUMBER() OVER (
                                   PARTITION BY m.SANF_DNO
                                   ORDER BY m.BILL_NO DESC, m.SEQ_NO1 DESC
                               ) AS RN
                          FROM ACCOUNTS.MULTI_CLOTHS m
                         WHERE m.SANF_DNO IN ('.implode(', ', $placeholders).')
                           AND m.CLOTH_TYPE IS NOT NULL
                   ) WHERE RN = 1',
                $bindings
            );
        } catch (\Throwable $e) {
            // العرض تزييني: فشله لا يكسر القائمة / the width is decorative on read.
            report($e);

            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $dno = (int) (CuttingViewReader::num($data, 'SANF_DNO') ?? 0);
            $width = CuttingViewReader::txt($data, 'CLOTH_TYPE');

            if ($dno > 0 && $width !== null) {
                $out[$dno] = $width;
            }
        }

        return $out;
    }

    /**
     * حجم الصفحة بعد القصّ على السقف / The page size, clamped to the ceiling.
     */
    private function resolveLimit(Request $request): int
    {
        $limit = (int) ($request->input('limit') ?? self::DEFAULT_LIMIT);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    /**
     * معالج الأخطاء المركزي — نفس مغلّف بقية المتحكمات
     * Central error handler — the same envelope as the other controllers.
     */
    private function errorResponse(string $msg, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $msg.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
