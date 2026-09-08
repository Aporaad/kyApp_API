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
            if (!empty($search)) {
                $searchEncoded = ArabicEncoding::encodeForOracle($search);
                $query->where(function ($q) use ($search, $searchEncoded) {
                    if (is_numeric($search)) {
                        $q->where('CUST_NO', (int)$search)
                          ->orWhere('TEL1', 'LIKE', '%' . $search . '%')
                          ->orWhere('TEL2', 'LIKE', '%' . $search . '%')
                          ->orWhere('ACC_NO', (int)$search);
                    } else {
                        $q->whereRaw("UPPER(CUST_NAME) LIKE UPPER(?)", ['%' . $search . '%'])
                          ->orWhereRaw("UPPER(CUST_NAME) LIKE UPPER(?)", ['%' . $searchEncoded . '%'])
                          ->orWhere('TEL1', 'LIKE', '%' . $search . '%')
                          ->orWhere('TEL2', 'LIKE', '%' . $search . '%')
                          ->orWhereRaw("UPPER(ADDR) LIKE UPPER(?)", ['%' . $search . '%']);
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

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => "العميل رقم [{$custNo}] غير موجود / Customer [{$custNo}] not found.",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => ArabicEncoding::cleanData((array)$customer),
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
            'addr'      => ['nullable', 'string', 'max:200'],
            'tel1'      => ['nullable', 'string', 'max:20'],
            'tel2'      => ['nullable', 'string', 'max:20'],
            'postbox'   => ['nullable', 'string', 'max:20'],
            'email'     => ['nullable', 'email', 'max:100'],
            'fax'       => ['nullable', 'string', 'max:20'],
            'acc_no'    => ['nullable', 'integer'],
            'limited_balance' => ['nullable', 'numeric'],
            'pre_cust_name'   => ['nullable', 'string', 'max:150'],
            'stopping_cust'   => ['nullable', 'integer', 'in:0,1'],
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
            $newCustNo = (int)($maxCustNo ?? 0) + 1;

            $custName = trim((string)$request->input('cust_name'));
            $addr = $request->input('addr') ? trim((string)$request->input('addr')) : null;
            $preCustName = $request->input('pre_cust_name') ? trim((string)$request->input('pre_cust_name')) : null;

            $data = [
                'CUST_NO'         => $newCustNo,
                'CUST_NAME'       => ArabicEncoding::encodeForOracle($custName) ?? $custName,
                'ADDR'            => $addr ? (ArabicEncoding::encodeForOracle($addr) ?? $addr) : null,
                'TEL1'            => $request->input('tel1') ? trim((string)$request->input('tel1')) : null,
                'TEL2'            => $request->input('tel2') ? trim((string)$request->input('tel2')) : null,
                'POSTBOX'         => $request->input('postbox') ? trim((string)$request->input('postbox')) : null,
                'EMAIL'           => $request->input('email') ? trim((string)$request->input('email')) : null,
                'FAX'             => $request->input('fax') ? trim((string)$request->input('fax')) : null,
                'ACC_NO'          => $request->input('acc_no') ? (int)$request->input('acc_no') : null,
                'LIMITED_BALANCE' => $request->input('limited_balance') ? (float)$request->input('limited_balance') : null,
                'PRE_CUST_NAME'   => $preCustName ? (ArabicEncoding::encodeForOracle($preCustName) ?? $preCustName) : null,
                'STOPPING_CUST'   => (int)($request->input('stopping_cust') ?? 0),
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
            'addr'      => ['nullable', 'string', 'max:200'],
            'tel1'      => ['nullable', 'string', 'max:20'],
            'tel2'      => ['nullable', 'string', 'max:20'],
            'postbox'   => ['nullable', 'string', 'max:20'],
            'email'     => ['nullable', 'email', 'max:100'],
            'fax'       => ['nullable', 'string', 'max:20'],
            'acc_no'    => ['nullable', 'integer'],
            'limited_balance' => ['nullable', 'numeric'],
            'pre_cust_name'   => ['nullable', 'string', 'max:150'],
            'stopping_cust'   => ['nullable', 'integer', 'in:0,1'],
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

            if (!$exists) {
                return response()->json([
                    'success' => false,
                    'message' => "العميل رقم [{$custNo}] غير موجود / Customer [{$custNo}] not found.",
                ], 404);
            }

            $custName = trim((string)$request->input('cust_name'));
            $addr = $request->input('addr') ? trim((string)$request->input('addr')) : null;
            $preCustName = $request->input('pre_cust_name') ? trim((string)$request->input('pre_cust_name')) : null;

            $data = [
                'CUST_NAME'       => ArabicEncoding::encodeForOracle($custName) ?? $custName,
                'ADDR'            => $addr ? (ArabicEncoding::encodeForOracle($addr) ?? $addr) : null,
                'TEL1'            => $request->input('tel1') ? trim((string)$request->input('tel1')) : null,
                'TEL2'            => $request->input('tel2') ? trim((string)$request->input('tel2')) : null,
                'POSTBOX'         => $request->input('postbox') ? trim((string)$request->input('postbox')) : null,
                'EMAIL'           => $request->input('email') ? trim((string)$request->input('email')) : null,
                'FAX'             => $request->input('fax') ? trim((string)$request->input('fax')) : null,
                'ACC_NO'          => $request->input('acc_no') ? (int)$request->input('acc_no') : null,
                'LIMITED_BALANCE' => $request->input('limited_balance') ? (float)$request->input('limited_balance') : null,
                'PRE_CUST_NAME'   => $preCustName ? (ArabicEncoding::encodeForOracle($preCustName) ?? $preCustName) : null,
                'STOPPING_CUST'   => (int)($request->input('stopping_cust') ?? 0),
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

            if (!$exists) {
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

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => "العميل رقم [{$custNo}] غير موجود / Customer [{$custNo}] not found.",
                ], 404);
            }

            // استعلام كشف الحساب لجميع الحركات المرتبطة بحساب العميل
            $sql = "SELECT ACCOUNTS.ENTRY_SUB.ENTRY_NO,
                        SUBSTR(TO_CHAR(ACCOUNTS.ENTRY_SUB.ENTRY_NO), 1, 1)||SUBSTR(TO_CHAR(ACCOUNTS.ENTRY_SUB.ENTRY_NO), 7, 4) AS SUB_ENTRY_NO,
                        TO_CHAR(ACCOUNTS.ENTRY_MAIN.ENTRY_DATE, 'DD/MM/YYYY') AS ENTRY_DATE,
                        TO_CHAR(ACCOUNTS.ENTRY_MAIN.ENTRY_DATE, 'YYYY-MM-DD') AS RAW_DATE,
                        ACCOUNTS.ENTRY_TYPE.ENTRY_TYPE_NAME,
                        ACCOUNTS.ENTRY_MAIN.DETAILS,
                        ACCOUNTS.ENTRY_SUB.BALANCE_S,
                        ACCOUNTS.ENTRY_SUB.ENTRY_MNT,
                        ACCOUNTS.CUST.CUST_NAME,
                        ACCOUNTS.CUST.CUST_NO,
                        ACCOUNTS.CUST.ACC_NO,
                        ACCOUNTS.ENTRY_SUB.ACC_NO AS ENTRY_SUB_ACC_NO
                    FROM ACCOUNTS.ENTRY_MAIN, ACCOUNTS.ENTRY_SUB, ACCOUNTS.ENTRY_TYPE, ACCOUNTS.CUST
                    WHERE ACCOUNTS.ENTRY_SUB.ACC_NO = ACCOUNTS.CUST.ACC_NO
                        AND ACCOUNTS.ENTRY_SUB.GIVING = ACCOUNTS.CUST.CUST_NO
                        AND ACCOUNTS.ENTRY_SUB.ENTRY_NO = ACCOUNTS.ENTRY_MAIN.ENTRY_NO
                        AND ACCOUNTS.ENTRY_MAIN.ENTRY_TYPE_NO = ACCOUNTS.ENTRY_TYPE.ENTRY_TYPE_NO
                        AND ACCOUNTS.CUST.CUST_NO = :custNo
                    ORDER BY ACCOUNTS.ENTRY_MAIN.ENTRY_DATE, ACCOUNTS.ENTRY_SUB.ENTRY_NO";

            $rows = DB::connection('oracle')->select($sql, ['custNo' => $custNo]);

            // تحويل بارامترات التاريخ للمقارنة
            $fromDateFilter = $fromDateParam ? $this->parseDateToIso($fromDateParam) : null;
            $toDateFilter = $toDateParam ? $this->parseDateToIso($toDateParam) : null;

            // حساب الرصيد الافتتاحي والمدين والدائن للحركات المفلترة
            $openingBalance = 0.0;
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $runningBalance = 0.0;
            $firstDate = null;
            $lastDate = null;

            $filteredRows = [];

            foreach ($rows as $row) {
                $r = (array) $row;
                $rawDate = $r['RAW_DATE'] ?? $r['raw_date'] ?? null;
                $entryDate = $r['ENTRY_DATE'] ?? $r['entry_date'] ?? null;
                $mnt = (float) ($r['ENTRY_MNT'] ?? $r['entry_mnt'] ?? 0);
                $bal = (float) ($r['BALANCE_S'] ?? $r['balance_s'] ?? 0);

                // إذا كان قبل تاريخ البداية المطلوب، يُحسب ضمن الرصيد الافتتاحي
                if ($fromDateFilter && $rawDate && $rawDate < $fromDateFilter) {
                    $openingBalance = $bal;
                    $runningBalance = $bal;
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
                $runningBalance = $bal;

                $filteredRows[] = [
                    'entry_no'        => $r['ENTRY_NO'] ?? $r['entry_no'] ?? null,
                    'sub_entry_no'    => $r['SUB_ENTRY_NO'] ?? $r['sub_entry_no'] ?? null,
                    'entry_date'      => $entryDate,
                    'entry_type_name' => ArabicEncoding::fixMojibake($r['ENTRY_TYPE_NAME'] ?? $r['entry_type_name'] ?? ''),
                    'details'         => ArabicEncoding::fixMojibake(trim((string)($r['DETAILS'] ?? $r['details'] ?? ''))),
                    'balance_s'       => $bal,
                    'entry_mnt'       => $mnt,
                    'debit'           => $debit,
                    'credit'          => $credit,
                    'cust_name'       => ArabicEncoding::fixMojibake($r['CUST_NAME'] ?? $r['cust_name'] ?? ''),
                    'cust_no'         => $r['CUST_NO'] ?? $r['cust_no'] ?? null,
                    'acc_no'          => $r['ACC_NO'] ?? $r['acc_no'] ?? null,
                ];
            }

            $finalBalance = count($filteredRows) > 0 ? $runningBalance : $openingBalance;

            $cleanedCustomer = ArabicEncoding::cleanData((array)$customer);

            return response()->json([
                'success'  => true,
                'customer' => $cleanedCustomer,
                'data'     => $filteredRows,
                'summary'  => [
                    'total_rows'      => count($filteredRows),
                    'total_debit'     => $totalDebit,
                    'total_credit'    => $totalCredit,
                    'opening_balance' => $openingBalance,
                    'final_balance'   => $finalBalance,
                    'from_date'       => $fromDateParam ?: ($firstDate ?: date('d/m/Y')),
                    'to_date'         => $toDateParam ?: ($lastDate ?: date('d/m/Y')),
                    'currency'        => 'يمني YR',
                    'account_title'   => ($cleanedCustomer['ACC_NO'] ?? $cleanedCustomer['acc_no'] ?? '12310000') . ' عملاء الكبودي للخياطة',
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
        if (empty($d)) return null;

        try {
            if (strpos($d, '/') !== false) {
                // DD/MM/YYYY
                $parts = explode('/', $d);
                if (count($parts) === 3) {
                    if (strlen($parts[0]) === 4) {
                        return sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
                    }
                    return sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                }
            }
            return Carbon::parse($d)->format('YYYY-MM-DD');
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
            'message' => $msg . ' / Database error.',
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
