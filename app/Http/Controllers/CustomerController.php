<?php

namespace App\Http\Controllers;

use App\Support\ArabicEncoding;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم إدارة بيانات العملاء - يتصل بجدول ACCOUNTS.CUST في أوراكل
 * Customer management controller - connected to Oracle ACCOUNTS.CUST table
 */
class CustomerController extends Controller
{
    private const TABLE_CUST = 'ACCOUNTS.CUST';

    private const MAX_LIMIT = 500;

    private const DEFAULT_LIMIT = 100;

    /**
     * جلب قائمة جميع العملاء مع دعم البحث والتصفية والتصفح (يدعم البحث بالاسم ورقم العميل ورقم الهاتف والحساب)
     * Fetch all customers with search (name, cust_no, phone, acc_no), filter, and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $query = DB::connection('oracle')
                ->table(self::TABLE_CUST)
                ->select([
                    'CUST_NO', 'CUST_NAME', 'ADDR', 'TEL1', 'TEL2',
                    'POSTBOX', 'EMAIL', 'FAX', 'ACC_NO', 'ACCNO',
                    'LIMITED_BALANCE', 'PRE_CUST_NAME', 'STOPPING_CUST',
                ]);

            // دعم البحث الشامل (بالرقم، بالاسم، برقم الهاتف 1 و 2، برقم الحساب)
            if (! empty($search)) {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $query->where(function ($q) use ($search, $oracleSearch) {
                    if (is_numeric($search)) {
                        $q->where('CUST_NO', (int) $search)
                            ->orWhere('TEL1', 'LIKE', '%'.$search.'%')
                            ->orWhere('TEL2', 'LIKE', '%'.$search.'%')
                            ->orWhere('ACC_NO', (int) $search);
                    } else {
                        $q->where('CUST_NAME', 'LIKE', '%'.$oracleSearch.'%')
                            ->orWhere('CUST_NAME', 'LIKE', '%'.$search.'%')
                            ->orWhere('TEL1', 'LIKE', '%'.$search.'%')
                            ->orWhere('TEL2', 'LIKE', '%'.$search.'%')
                            ->orWhere('ADDR', 'LIKE', '%'.$oracleSearch.'%')
                            ->orWhere('ADDR', 'LIKE', '%'.$search.'%')
                            ->orWhere('PRE_CUST_NAME', 'LIKE', '%'.$oracleSearch.'%');
                    }
                });
            }

            $total = $query->count();
            $data = $query->orderBy('CUST_NO')->offset($offset)->limit($limit)->get();

            // إصلاح وترجمة محارف النصوص العربية
            $cleanedData = ArabicEncoding::cleanData($data->toArray());

            return response()->json([
                'success' => true,
                'data' => $cleanedData,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة العملاء', $e);
        }
    }

    /**
     * جلب بيانات عميل واحد بواسطة رقمه
     * Get single customer by CUST_NO
     */
    public function show(int $custNo): JsonResponse
    {
        try {
            $customer = DB::connection('oracle')
                ->table(self::TABLE_CUST)
                ->where('CUST_NO', $custNo)
                ->first();

            if (! $customer) {
                return response()->json([
                    'success' => false,
                    'message' => "العميل رقم [{$custNo}] غير موجود / Customer [{$custNo}] not found.",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ArabicEncoding::cleanData((array) $customer),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب بيانات العميل', $e);
        }
    }

    /**
     * إضافة عميل جديد إلى جدول CUST مع حفظ الترميز الصحيح
     * Create a new customer in CUST table with proper Oracle encoding
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cust_name' => ['required', 'string', 'max:150'],
            'addr' => ['nullable', 'string', 'max:200'],
            'tel1' => ['nullable', 'string', 'max:20'],
            'tel2' => ['nullable', 'string', 'max:20'],
            'postbox' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'fax' => ['nullable', 'string', 'max:20'],
            'acc_no' => ['nullable', 'integer'],
            'limited_balance' => ['nullable', 'numeric'],
            'pre_cust_name' => ['nullable', 'string', 'max:150'],
            'stopping_cust' => ['nullable', 'integer', 'in:0,1'],
        ], [
            'cust_name.required' => 'اسم العميل مطلوب / Customer name is required.',
            'cust_name.max' => 'اسم العميل لا يتجاوز 150 حرفاً.',
            'email.email' => 'تنسيق البريد الإلكتروني غير صحيح.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات العميل غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // الحصول على أعلى رقم عميل وزيادته
            $maxCustNo = DB::connection('oracle')
                ->table(self::TABLE_CUST)
                ->max('CUST_NO');
            $newCustNo = (int) ($maxCustNo ?? 0) + 1;

            $custName = trim((string) $request->input('cust_name'));
            $addr = $request->input('addr') ? trim((string) $request->input('addr')) : null;
            $preCustName = $request->input('pre_cust_name') ? trim((string) $request->input('pre_cust_name')) : null;

            $data = [
                'CUST_NO' => $newCustNo,
                'CUST_NAME' => ArabicEncoding::toOracleSearchChars($custName) ?? $custName,
                'ADDR' => $addr ? (ArabicEncoding::toOracleSearchChars($addr) ?? $addr) : null,
                'TEL1' => $request->input('tel1') ? trim((string) $request->input('tel1')) : null,
                'TEL2' => $request->input('tel2') ? trim((string) $request->input('tel2')) : null,
                'POSTBOX' => $request->input('postbox') ? trim((string) $request->input('postbox')) : null,
                'EMAIL' => $request->input('email') ? trim((string) $request->input('email')) : null,
                'FAX' => $request->input('fax') ? trim((string) $request->input('fax')) : null,
                'ACC_NO' => $request->input('acc_no') ? (int) $request->input('acc_no') : null,
                'LIMITED_BALANCE' => $request->input('limited_balance') ? (float) $request->input('limited_balance') : null,
                'PRE_CUST_NAME' => $preCustName ? (ArabicEncoding::toOracleSearchChars($preCustName) ?? $preCustName) : null,
                'STOPPING_CUST' => (int) ($request->input('stopping_cust') ?? 0),
            ];

            DB::connection('oracle')->table(self::TABLE_CUST)->insert($data);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة العميل بنجاح / Customer created successfully.',
                'cust_no' => $newCustNo,
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إضافة العميل', $e);
        }
    }

    /**
     * تعديل بيانات عميل موجود
     * Update existing customer data
     */
    public function update(Request $request, int $custNo): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cust_name' => ['required', 'string', 'max:150'],
            'addr' => ['nullable', 'string', 'max:200'],
            'tel1' => ['nullable', 'string', 'max:20'],
            'tel2' => ['nullable', 'string', 'max:20'],
            'postbox' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'fax' => ['nullable', 'string', 'max:20'],
            'acc_no' => ['nullable', 'integer'],
            'limited_balance' => ['nullable', 'numeric'],
            'pre_cust_name' => ['nullable', 'string', 'max:150'],
            'stopping_cust' => ['nullable', 'integer', 'in:0,1'],
        ], [
            'cust_name.required' => 'اسم العميل مطلوب / Customer name is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات العميل غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $exists = DB::connection('oracle')
                ->table(self::TABLE_CUST)
                ->where('CUST_NO', $custNo)
                ->exists();

            if (! $exists) {
                return response()->json([
                    'success' => false,
                    'message' => "العميل رقم [{$custNo}] غير موجود / Customer [{$custNo}] not found.",
                ], 404);
            }

            $custName = trim((string) $request->input('cust_name'));
            $addr = $request->input('addr') ? trim((string) $request->input('addr')) : null;
            $preCustName = $request->input('pre_cust_name') ? trim((string) $request->input('pre_cust_name')) : null;

            $data = [
                'CUST_NAME' => ArabicEncoding::toOracleSearchChars($custName) ?? $custName,
                'ADDR' => $addr ? (ArabicEncoding::toOracleSearchChars($addr) ?? $addr) : null,
                'TEL1' => $request->input('tel1') ? trim((string) $request->input('tel1')) : null,
                'TEL2' => $request->input('tel2') ? trim((string) $request->input('tel2')) : null,
                'POSTBOX' => $request->input('postbox') ? trim((string) $request->input('postbox')) : null,
                'EMAIL' => $request->input('email') ? trim((string) $request->input('email')) : null,
                'FAX' => $request->input('fax') ? trim((string) $request->input('fax')) : null,
                'ACC_NO' => $request->input('acc_no') ? (int) $request->input('acc_no') : null,
                'LIMITED_BALANCE' => $request->input('limited_balance') ? (float) $request->input('limited_balance') : null,
                'PRE_CUST_NAME' => $preCustName ? (ArabicEncoding::toOracleSearchChars($preCustName) ?? $preCustName) : null,
                'STOPPING_CUST' => (int) ($request->input('stopping_cust') ?? 0),
            ];

            DB::connection('oracle')->table(self::TABLE_CUST)
                ->where('CUST_NO', $custNo)
                ->update($data);

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل بيانات العميل بنجاح / Customer updated successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل بيانات العميل', $e);
        }
    }

    /**
     * حذف عميل بواسطة رقمه
     * Delete customer by CUST_NO
     */
    public function destroy(int $custNo): JsonResponse
    {
        try {
            $exists = DB::connection('oracle')
                ->table(self::TABLE_CUST)
                ->where('CUST_NO', $custNo)
                ->exists();

            if (! $exists) {
                return response()->json([
                    'success' => false,
                    'message' => "العميل رقم [{$custNo}] غير موجود / Customer [{$custNo}] not found.",
                ], 404);
            }

            DB::connection('oracle')->table(self::TABLE_CUST)
                ->where('CUST_NO', $custNo)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'تم حذف العميل بنجاح / Customer deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف العميل', $e);
        }
    }

    /**
     * كشف حساب عميل - مع دعم تصفية التواريخ (from_date / to_date)
     * Customer account statement with optional date range filtering
     */
    public function statement(Request $request, int $custNo): JsonResponse
    {
        $fromDateParam = $request->input('from_date');
        $toDateParam = $request->input('to_date');

        try {
            // التحقق من وجود العميل أولاً
            $customer = DB::connection('oracle')
                ->table(self::TABLE_CUST)
                ->where('CUST_NO', $custNo)
                ->first();

            if (! $customer) {
                return response()->json([
                    'success' => false,
                    'message' => "العميل رقم [{$custNo}] غير موجود / Customer [{$custNo}] not found.",
                ], 404);
            }

            // استعلام كشف الحساب — مقيَّد دائماً بالحساب المالي ورقم العميل معاً.
            // سبب التقييد المزدوج: آلاف العملاء يشتركون في حساب المراقبة 12310000،
            // فالتقييد برقم الحساب وحده يعرض حركات كل العملاء وأرصدتهم.
            //
            // The statement is always scoped by account number AND customer number.
            // Thousands of customers share the control account 12310000, so scoping
            // by the account alone leaks every other customer's movements.
            //
            // عمود البيان: التفصيل الفرعي أولاً، وإن كان فارغاً (NULL أو مسافات فقط)
            // يُؤخذ بيان القيد الرئيسي.
            // Description: the sub-entry text first; when it is blank (NULL or spaces)
            // the main entry text is used instead.
            $sql = 'SELECT es.ENTRY_NO,
                        SUBSTR(TO_CHAR(es.ENTRY_NO), 1, 1) || SUBSTR(TO_CHAR(es.ENTRY_NO), 7, 4) AS SUB_ENTRY_NO,
                        TO_CHAR(em.ENTRY_DATE, \'DD/MM/YYYY\') AS ENTRY_DATE,
                        TO_CHAR(em.ENTRY_DATE, \'YYYY-MM-DD\') AS RAW_DATE,
                        et.ENTRY_TYPE_NAME,
                        NVL(TRIM(es.SUB_DETAILS), em.DETAILS) AS DETAILS,
                        es.ENTRY_MNT,
                        c.CUST_NAME,
                        c.CUST_NO,
                        c.ACC_NO,
                        es.ACC_NO AS ENTRY_SUB_ACC_NO
                    FROM ACCOUNTS.CUST c
                    JOIN ACCOUNTS.ENTRY_SUB es
                      ON es.ACC_NO = c.ACC_NO
                     AND es.GIVING = c.CUST_NO
                    JOIN ACCOUNTS.ENTRY_MAIN em
                      ON em.ENTRY_NO = es.ENTRY_NO
                    JOIN ACCOUNTS.ENTRY_TYPE et
                      ON et.ENTRY_TYPE_NO = em.ENTRY_TYPE_NO
                    WHERE c.CUST_NO = :custNo
                    ORDER BY em.ENTRY_DATE, es.ENTRY_NO, es.SEQ';

            $rows = DB::connection('oracle')->select($sql, ['custNo' => $custNo]);

            // تحويل بارامترات التاريخ للمقارنة
            $fromDateFilter = $fromDateParam ? $this->parseDateToIso($fromDateParam) : null;
            $toDateFilter = $toDateParam ? $this->parseDateToIso($toDateParam) : null;

            // حساب الرصيد الافتتاحي والمدين والدائن للحركات المفلترة
            // Opening balance, period debit/credit and the running balance.
            //
            // لا نعتمد على ENTRY_SUB.BALANCE_S إطلاقاً: فهو رصيد الحساب المالي كاملاً
            // (يشمل كل من يشترك فيه) وليس رصيد العميل، كما أنه لا يطابق الترتيب الزمني.
            // الرصيد هنا يُحسب تراكمياً من مبالغ الحركات ENTRY_MNT للعميل وحده.
            //
            // ENTRY_SUB.BALANCE_S is never used: it is the whole account's stored
            // balance (shared by every party on that account) and it does not follow
            // chronological order. The balance is derived from ENTRY_MNT instead.
            $openingBalance = 0.0;
            $periodMovement = 0.0;
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $firstDate = null;
            $lastDate = null;

            $filteredRows = [];

            foreach ($rows as $row) {
                $r = (array) $row;
                $rawDate = $r['RAW_DATE'] ?? $r['raw_date'] ?? null;
                $entryDate = $r['ENTRY_DATE'] ?? $r['entry_date'] ?? null;
                $mnt = (float) ($r['ENTRY_MNT'] ?? $r['entry_mnt'] ?? 0);

                // إذا كان قبل تاريخ البداية المطلوب، يُحسب ضمن الرصيد الافتتاحي
                if ($fromDateFilter && $rawDate && $rawDate < $fromDateFilter) {
                    $openingBalance += $mnt;

                    continue;
                }

                // إذا كان بعد تاريخ النهاية المطلوب، نتوقف
                if ($toDateFilter && $rawDate && $rawDate > $toDateFilter) {
                    continue;
                }

                if ($firstDate === null) {
                    $firstDate = $entryDate;
                }
                $lastDate = $entryDate;

                $debit = $mnt > 0 ? $mnt : 0.0;
                $credit = $mnt < 0 ? abs($mnt) : 0.0;

                $totalDebit += $debit;
                $totalCredit += $credit;
                $periodMovement += $mnt;

                // رصيد السطر = الافتتاحي + حركة الفترة التراكمية
                // Line balance = opening balance + cumulative period movement.
                $lineBalance = $openingBalance + $periodMovement;

                $filteredRows[] = [
                    'entry_no' => $r['ENTRY_NO'] ?? $r['entry_no'] ?? null,
                    'sub_entry_no' => $r['SUB_ENTRY_NO'] ?? $r['sub_entry_no'] ?? null,
                    'entry_date' => $entryDate,
                    'entry_type_name' => ArabicEncoding::fixMojibake($r['ENTRY_TYPE_NAME'] ?? $r['entry_type_name'] ?? ''),
                    'details' => ArabicEncoding::normalizeStatementText($r['DETAILS'] ?? $r['details'] ?? ''),
                    'balance' => $lineBalance,
                    'entry_mnt' => $mnt,
                    'debit' => $debit,
                    'credit' => $credit,
                    'cust_name' => ArabicEncoding::fixMojibake($r['CUST_NAME'] ?? $r['cust_name'] ?? ''),
                    'cust_no' => $r['CUST_NO'] ?? $r['cust_no'] ?? null,
                    'acc_no' => $r['ACC_NO'] ?? $r['acc_no'] ?? null,
                ];
            }

            // الرصيد النهائي = الافتتاحي + صافي حركة الفترة
            // Final balance = opening balance + net period movement.
            $finalBalance = $openingBalance + $periodMovement;

            $cleanedCustomer = ArabicEncoding::cleanData((array) $customer);

            return response()->json([
                'success' => true,
                'customer' => $cleanedCustomer,
                'data' => $filteredRows,
                'summary' => [
                    'total_rows' => count($filteredRows),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'opening_balance' => $openingBalance,
                    'final_balance' => $finalBalance,
                    // التواريخ تُعرض دائماً بصيغة يوم/شهر/سنة مهما كان شكل الطلب
                    // Dates always reach the client as DD/MM/YYYY, whatever the input format.
                    'from_date' => $fromDateFilter
                        ? Carbon::parse($fromDateFilter)->format('d/m/Y')
                        : ($firstDate ?: date('d/m/Y')),
                    'to_date' => $toDateFilter
                        ? Carbon::parse($toDateFilter)->format('d/m/Y')
                        : ($lastDate ?: date('d/m/Y')),
                    'currency' => 'يمني YR',
                    // عنوان الحساب = رقم الحساب المالي + اسم العميل (لا اسم حساب ثابت)
                    // Account title = the account number plus the customer's own name.
                    'account_title' => trim(
                        (string) ($cleanedCustomer['ACC_NO'] ?? $cleanedCustomer['acc_no'] ?? '').' - '.
                        ArabicEncoding::fixMojibake((string) ($cleanedCustomer['CUST_NAME'] ?? $cleanedCustomer['cust_name'] ?? ''))
                    ),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب كشف حساب العميل', $e);
        }
    }

    /**
     * تحويل التواريخ بأشكالها المختلفة إلى YYYY-MM-DD
     */
    private function parseDateToIso(string $dateStr): ?string
    {
        $d = trim($dateStr);
        if (empty($d)) {
            return null;
        }

        try {
            if (strpos($d, '/') !== false) {
                // DD/MM/YYYY
                $parts = explode('/', $d);
                if (count($parts) === 3) {
                    $year = strlen($parts[0]) === 4 ? (int) $parts[0] : (int) $parts[2];
                    $month = (int) $parts[1];
                    $day = strlen($parts[0]) === 4 ? (int) $parts[2] : (int) $parts[0];

                    // حماية من التواريخ المشوهة: نرفض أي سنة/شهر/يوم خارج النطاق المنطقي.
                    // Reject absurd dates so garbage never reaches the client UI.
                    if ($year < 1900 || $year > 2100 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
                        return null;
                    }

                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }

            // 'Y-m-d' وليس 'YYYY-MM-DD': في PHP كل Y تُكرّر السنة، وM اسم الشهر
            // وD اسم اليوم — فتنتج سلسلة مشوهة تكسر منتقي التاريخ على العميل.
            // Use 'Y-m-d': repeated format letters produce garbage output.
            $parsed = Carbon::parse($d);
            if ($parsed->year < 1900 || $parsed->year > 2100) {
                return null;
            }

            return $parsed->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * معالج الأخطاء المركزي
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
