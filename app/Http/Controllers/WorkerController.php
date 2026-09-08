<?php

namespace App\Http\Controllers;

use App\Support\ArabicEncoding;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم إدارة العمال (الموظفين - القصاصين - الخياطين)
 * Worker management controller for TAILOR_EMPLOYEES, CUTTER, TAILOR tables
 */
class WorkerController extends Controller
{
    // ===================================================
    // إعدادات الجداول وأعمدتها
    // ===================================================

    private const TABLES = [
        'employees' => [
            'table'       => 'ACCOUNTS.TAILOR_EMPLOYEES',
            'pk'          => 'EMP_NO',
            'name_col'    => 'EMP_NAME',
            'phone1_col'  => 'EMP_PHONE1',
            'phone2_col'  => 'EMP_PHONE2',
            'email_col'   => 'EMP_EMAIL',
            'status_col'  => 'EMP_STATUS',
            'notes_col'   => 'NOTES1',
            'acc_col'     => 'ACC_NO',
            'extra_cols'  => ['JOP_TYPE', 'EMP_CLASS', 'WAGES_TYPE', 'SALARY', 'PIECE_PRICE', 'CUR_NO'],
        ],
        'cutters' => [
            'table'       => 'ACCOUNTS.CUTTER',
            'pk'          => 'CUTTER_NO',
            'name_col'    => 'CUTTER_NAME',
            'phone1_col'  => 'CUTTER_PHONE1',
            'phone2_col'  => 'CUTTER_PHONE2',
            'email_col'   => 'CUTTER_EMAIL',
            'status_col'  => 'CUTTER_STATUS',
            'notes_col'   => 'NOTES1',
            'acc_col'     => 'ACC_NO',
            'extra_cols'  => ['CUR_NO'],
        ],
        'tailors' => [
            'table'       => 'ACCOUNTS.TAILOR',
            'pk'          => 'TAILOR_NO',
            'name_col'    => 'TAILOR_NAME',
            'phone1_col'  => 'TAILOR_PHONE1',
            'phone2_col'  => 'TAILOR_PHONE2',
            'email_col'   => 'TAILOR_EMAIL',
            'status_col'  => 'TAILOR_STATUS',
            'notes_col'   => 'NOTES1',
            'acc_col'     => 'ACC_NO',
            'extra_cols'  => ['CUR_NO'],
        ],
    ];

    private const MAX_LIMIT     = 500;
    private const DEFAULT_LIMIT = 100;

    // ===================================================
    // دوال قراءة البيانات (Index)
    // ===================================================

    /** قائمة الموظفين */
    public function employees(Request $request): JsonResponse
    {
        return $this->listWorkers('employees', $request);
    }

    /** قائمة القصاصين */
    public function cutters(Request $request): JsonResponse
    {
        return $this->listWorkers('cutters', $request);
    }

    /** قائمة الخياطين */
    public function tailors(Request $request): JsonResponse
    {
        return $this->listWorkers('tailors', $request);
    }

    // ===================================================
    // دوال الإضافة (Store)
    // ===================================================

    public function storeEmployee(Request $request): JsonResponse
    {
        return $this->storeWorker('employees', $request);
    }

    public function storeCutter(Request $request): JsonResponse
    {
        return $this->storeWorker('cutters', $request);
    }

    public function storeTailor(Request $request): JsonResponse
    {
        return $this->storeWorker('tailors', $request);
    }

    // ===================================================
    // دوال التعديل (Update)
    // ===================================================

    public function updateEmployee(Request $request, $id): JsonResponse
    {
        return $this->updateWorker('employees', $request, $id);
    }

    public function updateCutter(Request $request, $id): JsonResponse
    {
        return $this->updateWorker('cutters', $request, $id);
    }

    public function updateTailor(Request $request, $id): JsonResponse
    {
        return $this->updateWorker('tailors', $request, $id);
    }

    // ===================================================
    // دوال الحذف (Destroy)
    // ===================================================

    public function destroyEmployee($id): JsonResponse
    {
        return $this->destroyWorker('employees', $id);
    }

    public function destroyCutter($id): JsonResponse
    {
        return $this->destroyWorker('cutters', $id);
    }

    public function destroyTailor($id): JsonResponse
    {
        return $this->destroyWorker('tailors', $id);
    }

    // ===================================================
    // دوال التعطيل/التفعيل (Toggle Status)
    // ===================================================

    public function toggleEmployee($id): JsonResponse
    {
        return $this->toggleWorker('employees', $id);
    }

    public function toggleCutter($id): JsonResponse
    {
        return $this->toggleWorker('cutters', $id);
    }

    public function toggleTailor($id): JsonResponse
    {
        return $this->toggleWorker('tailors', $id);
    }

    // ===================================================
    // كشف الحساب (Statement) - مشترك للجميع
    // ===================================================

    public function statementEmployee(Request $request, $id): JsonResponse
    {
        return $this->fetchStatement('employees', $request, $id);
    }

    public function statementCutter(Request $request, $id): JsonResponse
    {
        return $this->fetchStatement('cutters', $request, $id);
    }

    public function statementTailor(Request $request, $id): JsonResponse
    {
        return $this->fetchStatement('tailors', $request, $id);
    }

    // ===================================================
    // المنطق الداخلي المشترك
    // ===================================================

    /**
     * جلب قائمة العمال مع البحث والتصفح
     */
    private function listWorkers(string $type, Request $request): JsonResponse
    {
        $cfg    = self::TABLES[$type];
        $search = trim((string) ($request->input('search') ?? ''));
        $limit  = min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $page   = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $allCols = array_merge(
                [$cfg['pk'], $cfg['name_col'], $cfg['phone1_col'], $cfg['phone2_col'],
                 $cfg['email_col'], $cfg['status_col'], $cfg['notes_col'], $cfg['acc_col']],
                $cfg['extra_cols']
            );

            $query = DB::connection('oracle')
                ->table($cfg['table'])
                ->select($allCols);

            if (!empty($search)) {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $query->where(function ($q) use ($cfg, $search, $oracleSearch) {
                    if (is_numeric($search)) {
                        $q->where($cfg['pk'], (int) $search);
                    } else {
                        $q->where($cfg['name_col'], 'LIKE', '%' . $oracleSearch . '%')
                          ->orWhere($cfg['name_col'], 'LIKE', '%' . $search . '%')
                          ->orWhere($cfg['phone1_col'], 'LIKE', '%' . $search . '%');
                    }
                });
            }

            $total = $query->count();
            $data  = $query->orderBy($cfg['pk'])->offset($offset)->limit($limit)->get();

            $cleanedData = ArabicEncoding::cleanData($data->toArray());

            return response()->json([
                'success' => true,
                'type'    => $type,
                'data'    => $cleanedData,
                'meta'    => [
                    'total' => $total,
                    'page'  => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / max($limit, 1)),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في جلب قائمة $type", $e);
        }
    }

    /**
     * إضافة عامل جديد
     */
    private function storeWorker(string $type, Request $request): JsonResponse
    {
        $cfg = self::TABLES[$type];

        try {
            $data = $request->only(array_merge(
                [$cfg['name_col'], $cfg['phone1_col'], $cfg['phone2_col'],
                 $cfg['email_col'], $cfg['status_col'], $cfg['notes_col'], $cfg['acc_col']],
                $cfg['extra_cols']
            ));

            // تحويل النصوص العربية إلى ترميز أوراكل
            foreach ([$cfg['name_col'], $cfg['notes_col']] as $col) {
                if (!empty($data[$col])) {
                    $data[$col] = ArabicEncoding::toOracleSearchChars($data[$col]);
                }
            }

            // الحصول على الرقم التالي
            $maxId = DB::connection('oracle')
                ->table($cfg['table'])
                ->max($cfg['pk']);
            $newId = ((int) $maxId) + 1;
            $data[$cfg['pk']] = $newId;

            // تعيين الحالة الافتراضية
            if (empty($data[$cfg['status_col']])) {
                $data[$cfg['status_col']] = 'فعال';
            }

            DB::connection('oracle')->table($cfg['table'])->insert($data);

            return response()->json([
                'success' => true,
                'message' => 'تم الإضافة بنجاح',
                'id'      => $newId,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في إضافة $type", $e);
        }
    }

    /**
     * تعديل بيانات عامل
     */
    private function updateWorker(string $type, Request $request, $id): JsonResponse
    {
        $cfg = self::TABLES[$type];

        try {
            $data = $request->only(array_merge(
                [$cfg['name_col'], $cfg['phone1_col'], $cfg['phone2_col'],
                 $cfg['email_col'], $cfg['status_col'], $cfg['notes_col'], $cfg['acc_col']],
                $cfg['extra_cols']
            ));

            // تحويل النصوص العربية
            foreach ([$cfg['name_col'], $cfg['notes_col']] as $col) {
                if (isset($data[$col]) && !empty($data[$col])) {
                    $data[$col] = ArabicEncoding::toOracleSearchChars($data[$col]);
                }
            }

            $affected = DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->update($data);

            if ($affected === 0) {
                return response()->json(['success' => false, 'message' => 'لم يتم العثور على السجل'], 404);
            }

            return response()->json(['success' => true, 'message' => 'تم التعديل بنجاح']);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في تعديل $type", $e);
        }
    }

    /**
     * حذف عامل
     */
    private function destroyWorker(string $type, $id): JsonResponse
    {
        $cfg = self::TABLES[$type];

        try {
            $affected = DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->delete();

            if ($affected === 0) {
                return response()->json(['success' => false, 'message' => 'لم يتم العثور على السجل'], 404);
            }

            return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في حذف $type", $e);
        }
    }

    /**
     * تعطيل / تفعيل حالة عامل
     */
    private function toggleWorker(string $type, $id): JsonResponse
    {
        $cfg = self::TABLES[$type];

        try {
            $worker = DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->first();

            if (!$worker) {
                return response()->json(['success' => false, 'message' => 'لم يتم العثور على السجل'], 404);
            }

            $wArr = (array) $worker;
            $currentStatus = ArabicEncoding::fixMojibake((string) ($wArr[strtolower($cfg['status_col'])] ?? $wArr[$cfg['status_col']] ?? $worker->{$cfg['status_col']} ?? 'فعال'));
            $newStatus     = (str_contains($currentStatus, 'معطل') || str_contains($currentStatus, 'موقوف'))
                ? 'فعال'
                : 'معطل';

            DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->update([$cfg['status_col'] => ArabicEncoding::toOracleSearchChars($newStatus)]);

            return response()->json([
                'success'    => true,
                'message'    => "تم تغيير الحالة إلى: $newStatus",
                'new_status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في تغيير حالة $type", $e);
        }
    }

    /**
     * كشف الحساب لأي عامل بناءً على ACC_NO المخزن في السجل
     *
     * الاستعلام المستخدم:
     * SELECT ACCOUNTS.ENTRY_SUB.ENTRY_NO,
     *        SUBSTR(ACCOUNTS.ENTRY_SUB.ENTRY_NO,1,1)||SUBSTR(ACCOUNTS.ENTRY_SUB.ENTRY_NO,7,4) SUB_ENTRY_NO,
     *        ENTRY_MAIN.ENTRY_DATE, ENTRY_TYPE.ENTRY_TYPE_NAME, ENTRY_MAIN.DETAILS,
     *        ENTRY_SUB.BALANCE_S, ENTRY_SUB.ENTRY_MNT
     * FROM ACCOUNTS.ENTRY_MAIN, ACCOUNTS.ENTRY_SUB, ACCOUNTS.ENTRY_TYPE
     * WHERE ENTRY_SUB.ACC_NO = :acc_no
     *   AND ENTRY_MAIN.ENTRY_DATE BETWEEN :from_date AND :to_date
     *   AND ENTRY_MAIN.entry_type_no = ENTRY_TYPE.ENTRY_TYPE_no
     *   AND ACCOUNTS.ENTRY_SUB.ENTRY_NO = ACCOUNTS.ENTRY_MAIN.ENTRY_NO
     * ORDER BY entry_date
     */
    private function fetchStatement(string $type, Request $request, $id): JsonResponse
    {
        $cfg = self::TABLES[$type];

        $fromDateParam = trim((string) ($request->input('from_date') ?? ''));
        $toDateParam   = trim((string) ($request->input('to_date') ?? ''));

        try {
            // جلب بيانات العامل
            $worker = DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->first();

            if (!$worker) {
                return response()->json(['success' => false, 'message' => 'لم يتم العثور على العامل'], 404);
            }

            $wArr = (array) $worker;
            $accNo = $wArr[strtolower($cfg['acc_col'])] ?? $wArr[$cfg['acc_col']] ?? $worker->{$cfg['acc_col']} ?? null;

            if (!$accNo) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد رقم حساب مرتبط بهذا العامل',
                ], 422);
            }

            // تحديد نطاق التواريخ
            $fromDateFilter = null;
            $toDateFilter   = null;

            if (!empty($fromDateParam)) {
                $parsed = $this->parseDateToIso($fromDateParam);
                if ($parsed) {
                    $fromDateFilter = $parsed;
                }
            }
            if (!empty($toDateParam)) {
                $parsed = $this->parseDateToIso($toDateParam);
                if ($parsed) {
                    $toDateFilter = $parsed;
                }
            }

            // استعلام جميع الحركات لحساب العامل
            $sql = "
                SELECT ACCOUNTS.ENTRY_SUB.ENTRY_NO,
                       SUBSTR(TO_CHAR(ACCOUNTS.ENTRY_SUB.ENTRY_NO), 1, 1) || SUBSTR(TO_CHAR(ACCOUNTS.ENTRY_SUB.ENTRY_NO), 7, 4) AS SUB_ENTRY_NO,
                       TO_CHAR(ACCOUNTS.ENTRY_MAIN.ENTRY_DATE, 'DD/MM/YYYY') AS ENTRY_DATE,
                       TO_CHAR(ACCOUNTS.ENTRY_MAIN.ENTRY_DATE, 'YYYY-MM-DD') AS RAW_DATE,
                       ACCOUNTS.ENTRY_TYPE.ENTRY_TYPE_NAME,
                       ACCOUNTS.ENTRY_MAIN.DETAILS,
                       ACCOUNTS.ENTRY_SUB.BALANCE_S,
                       ACCOUNTS.ENTRY_SUB.ENTRY_MNT
                FROM ACCOUNTS.ENTRY_MAIN,
                     ACCOUNTS.ENTRY_SUB,
                     ACCOUNTS.ENTRY_TYPE
                WHERE ACCOUNTS.ENTRY_SUB.ACC_NO = :acc_no
                  AND ACCOUNTS.ENTRY_MAIN.entry_type_no = ACCOUNTS.ENTRY_TYPE.ENTRY_TYPE_no
                  AND ACCOUNTS.ENTRY_SUB.ENTRY_NO = ACCOUNTS.ENTRY_MAIN.ENTRY_NO
                ORDER BY ACCOUNTS.ENTRY_MAIN.ENTRY_DATE, ACCOUNTS.ENTRY_SUB.ENTRY_NO
            ";

            $rows = DB::connection('oracle')->select($sql, ['acc_no' => $accNo]);

            // إذا لم يتم تمرير تواريخ، نحدد آخر 30 يوماً من تاريخ أحدث حركة
            if (empty($fromDateParam) && empty($toDateParam) && count($rows) > 0) {
                $lastRow = end($rows);
                $lastDateStr = $lastRow->raw_date ?? $lastRow->RAW_DATE ?? null;
                if ($lastDateStr) {
                    $latestDate = Carbon::parse($lastDateStr);
                    $fromDateFilter = $latestDate->copy()->subDays(30)->format('Y-m-d');
                    $toDateFilter   = $latestDate->format('Y-m-d');
                    $fromDateParam  = $latestDate->copy()->subDays(30)->format('d/m/Y');
                    $toDateParam    = $latestDate->format('d/m/Y');
                }
            }

            // معالجة النتائج وحساب الرصيد الافتتاحي
            $openingBalance = 0.0;
            $runningBalance = 0.0;
            $totalDebit     = 0.0;
            $totalCredit    = 0.0;
            $filteredRows   = [];
            $firstDate      = null;
            $lastDate       = null;

            foreach ($rows as $r) {
                $r = (array) $r;
                $rawDate = $r['RAW_DATE'] ?? $r['raw_date'] ?? null;
                $mnt = (float) ($r['ENTRY_MNT'] ?? $r['entry_mnt'] ?? 0);
                $bal = (float) ($r['BALANCE_S'] ?? $r['balance_s'] ?? 0);
                $entryDate = $r['ENTRY_DATE'] ?? $r['entry_date'] ?? null;

                // إذا كانت الحركة قبل تاريخ البداية المطلوب، تُحسب ضمن الرصيد الافتتاحي
                if ($fromDateFilter && $rawDate && $rawDate < $fromDateFilter) {
                    $openingBalance = $bal;
                    $runningBalance = $bal;
                    continue;
                }

                // إذا كانت بعد تاريخ النهاية المطلوب، نتوقف
                if ($toDateFilter && $rawDate && $rawDate > $toDateFilter) {
                    continue;
                }

                $debit  = $mnt > 0 ? $mnt : 0.0;
                $credit = $mnt < 0 ? abs($mnt) : 0.0;

                $totalDebit    += $debit;
                $totalCredit   += $credit;
                $runningBalance = $bal;

                if ($firstDate === null) {
                    $firstDate = $entryDate;
                }
                $lastDate = $entryDate;

                $filteredRows[] = [
                    'entry_no'        => $r['ENTRY_NO'] ?? $r['entry_no'] ?? null,
                    'sub_entry_no'    => $r['SUB_ENTRY_NO'] ?? $r['sub_entry_no'] ?? null,
                    'entry_date'      => $entryDate,
                    'raw_date'        => $rawDate,
                    'entry_type_name' => ArabicEncoding::fixMojibake($r['ENTRY_TYPE_NAME'] ?? $r['entry_type_name'] ?? ''),
                    'details'         => ArabicEncoding::fixMojibake(trim((string) ($r['DETAILS'] ?? $r['details'] ?? ''))),
                    'balance_s'       => $bal,
                    'entry_mnt'       => $mnt,
                    'debit'           => $debit,
                    'credit'          => $credit,
                    'acc_no'          => $accNo,
                ];
            }

            $workerName = ArabicEncoding::fixMojibake((string) ($wArr[strtolower($cfg['name_col'])] ?? $wArr[$cfg['name_col']] ?? $worker->{$cfg['name_col']} ?? ''));

            return response()->json([
                'success' => true,
                'worker'  => [
                    'id'     => $id,
                    'name'   => $workerName,
                    'acc_no' => $accNo,
                    'type'   => $type,
                ],
                'data'    => $filteredRows,
                'summary' => [
                    'total_rows'      => count($filteredRows),
                    'total_debit'     => $totalDebit,
                    'total_credit'    => $totalCredit,
                    'opening_balance' => $openingBalance,
                    'final_balance'   => count($filteredRows) > 0 ? $runningBalance : $openingBalance,
                    'from_date'       => $fromDateParam ?: ($firstDate ?: date('d/m/Y')),
                    'to_date'         => $toDateParam ?: ($lastDate ?: date('d/m/Y')),
                    'currency'        => 'يمني YR',
                    'account_title'   => "$accNo - $workerName",
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في جلب كشف حساب $type", $e);
        }
    }

    /**
     * تحويل صيغ التواريخ المختلفة إلى YYYY-MM-DD
     */
    private function parseDateToIso(string $dateStr): ?string
    {
        $d = trim($dateStr);
        if (empty($d)) {
            return null;
        }

        try {
            if (str_contains($d, '/')) {
                $parts = explode('/', $d);
                if (count($parts) === 3) {
                    if (strlen($parts[0]) === 4) {
                        return sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
                    }
                    return sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                }
            }
            return Carbon::parse($d)->format('Y-m-d');
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
