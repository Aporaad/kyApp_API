<?php

namespace App\Http\Controllers;

use App\Support\ArabicEncoding;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'table' => 'ACCOUNTS.TAILOR_EMPLOYEES',
            'pk' => 'EMP_NO',
            'name_col' => 'EMP_NAME',
            'phone1_col' => 'EMP_PHONE1',
            'phone2_col' => 'EMP_PHONE2',
            'email_col' => 'EMP_EMAIL',
            'status_col' => 'EMP_STATUS',
            'notes_col' => 'NOTES1',
            'acc_col' => 'ACC_NO',
            'extra_cols' => ['JOP_TYPE', 'EMP_CLASS', 'WAGES_TYPE', 'SALARY', 'PIECE_PRICE', 'CUR_NO'],
        ],
        'cutters' => [
            'table' => 'ACCOUNTS.CUTTER',
            'pk' => 'CUTTER_NO',
            'name_col' => 'CUTTER_NAME',
            'phone1_col' => 'CUTTER_PHONE1',
            'phone2_col' => 'CUTTER_PHONE2',
            'email_col' => 'CUTTER_EMAIL',
            'status_col' => 'CUTTER_STATUS',
            'notes_col' => 'NOTES1',
            'acc_col' => 'ACC_NO',
            'extra_cols' => ['CUR_NO'],
            // إعدادات كشف العمل (نسخة من تقرير أوراكل FOR_CUTTER_TAILOR - جدول CUTTER_FROM_CALC فقط)
            // Work-report source config (mirror of Oracle FOR_CUTTER_TAILOR's work table only)
            'work_table' => 'ACCOUNTS.CUTTER_FROM_CALC',
            'work_join_col' => 'CUTTER_NO',
            'work_date_col' => 'CUTTING_DATE',
            'work_style_col' => 'STYLE_NAME',
            'work_qty_col' => 'POST',
            'work_total_col' => 'AMT',
        ],
        'tailors' => [
            'table' => 'ACCOUNTS.TAILOR',
            'pk' => 'TAILOR_NO',
            'name_col' => 'TAILOR_NAME',
            'phone1_col' => 'TAILOR_PHONE1',
            'phone2_col' => 'TAILOR_PHONE2',
            'email_col' => 'TAILOR_EMAIL',
            'status_col' => 'TAILOR_STATUS',
            'notes_col' => 'NOTES1',
            'acc_col' => 'ACC_NO',
            'extra_cols' => ['CUR_NO'],
            // إعدادات كشف العمل (نسخة من تقرير أوراكل FOR_TAILOR - جدول TAILOR_FROM_CALC فقط)
            // الإجمالي هنا AMT_ALL لأنه يشمل تكاليف إضافية (ADDCOST2/3) بخلاف AMT الأساسي
            'work_table' => 'ACCOUNTS.TAILOR_FROM_CALC',
            'work_join_col' => 'TAILOR_NO',
            'work_date_col' => 'TAILOR_DATE',
            'work_style_col' => 'STYLE_NAME',
            'work_qty_col' => 'POST',
            'work_total_col' => 'AMT_ALL',
        ],
    ];

    private const MAX_LIMIT = 500;

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
    // كشف العمل (Work Report) - للقصاصين والخياطين فقط
    // Work report - cutters & tailors only (mirrors Oracle FOR_CUTTER_TAILOR / FOR_TAILOR)
    // ===================================================

    /** كشف حساب عمل القصاص */
    public function workReportCutter(Request $request, $id): JsonResponse
    {
        return $this->fetchWorkReport('cutters', $request, $id);
    }

    /** كشف حساب عمل الخياط */
    public function workReportTailor(Request $request, $id): JsonResponse
    {
        return $this->fetchWorkReport('tailors', $request, $id);
    }

    // ===================================================
    // كشف عمل المجموعة (Group Work Report) - جميع القصاصين أو الخياطين
    // Group work report - all cutters or all tailors in a date range
    // Mirrors Oracle FOR_CUTTER_CALC: اسم | تاريخ | موديل | عدد | سعر | إجمالي
    // ===================================================

    /** كشف عمل مجموعة القصاصين */
    public function groupWorkReportCutter(Request $request): JsonResponse
    {
        return $this->fetchGroupWorkReport('cutters', $request);
    }

    /** كشف عمل مجموعة الخياطين */
    public function groupWorkReportTailor(Request $request): JsonResponse
    {
        return $this->fetchGroupWorkReport('tailors', $request);
    }

    // ===================================================
    // المنطق الداخلي المشترك
    // ===================================================

    /**
     * جلب قائمة العمال مع البحث والتصفح
     */
    private function listWorkers(string $type, Request $request): JsonResponse
    {
        $cfg = self::TABLES[$type];
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $page = max((int) ($request->input('page') ?? 1), 1);
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

            if (! empty($search)) {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $query->where(function ($q) use ($cfg, $search, $oracleSearch) {
                    if (is_numeric($search)) {
                        $q->where($cfg['pk'], (int) $search);
                    } else {
                        $q->where($cfg['name_col'], 'LIKE', '%'.$oracleSearch.'%')
                            ->orWhere($cfg['name_col'], 'LIKE', '%'.$search.'%')
                            ->orWhere($cfg['phone1_col'], 'LIKE', '%'.$search.'%');
                    }
                });
            }

            $total = $query->count();
            $data = $query->orderBy($cfg['pk'])->offset($offset)->limit($limit)->get();

            $cleanedData = ArabicEncoding::cleanData($data->toArray());

            return response()->json([
                'success' => true,
                'type' => $type,
                'data' => $cleanedData,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
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
                if (! empty($data[$col])) {
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
                'id' => $newId,
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
                if (isset($data[$col]) && ! empty($data[$col])) {
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

            if (! $worker) {
                return response()->json(['success' => false, 'message' => 'لم يتم العثور على السجل'], 404);
            }

            $wArr = (array) $worker;
            $currentStatus = ArabicEncoding::fixMojibake((string) ($wArr[strtolower($cfg['status_col'])] ?? $wArr[$cfg['status_col']] ?? $worker->{$cfg['status_col']} ?? 'فعال'));
            $newStatus = (str_contains($currentStatus, 'معطل') || str_contains($currentStatus, 'موقوف'))
                ? 'فعال'
                : 'معطل';

            DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->update([$cfg['status_col'] => ArabicEncoding::toOracleSearchChars($newStatus)]);

            return response()->json([
                'success' => true,
                'message' => "تم تغيير الحالة إلى: $newStatus",
                'new_status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في تغيير حالة $type", $e);
        }
    }

    /**
     * كشف الحساب لأي عامل بناءً على ACC_NO المخزن في السجل
     *
     * ملاحظتان جوهريتان (نفس قواعد كشوف العملاء والحسابات):
     * 1. البيان: `NVL(TRIM(ENTRY_SUB.SUB_DETAILS), ENTRY_MAIN.DETAILS)` — التفصيل
     *    الفرعي أولاً ثم بيان القيد الرئيسي عند فراغه.
     * 2. الرصيد: `ENTRY_SUB.BALANCE_S` لا يُقرأ إطلاقاً — لقطة مخزّنة لا تتبع
     *    الترتيب الزمني؛ الرصيد والرصيد النهائي محسوبان من `ENTRY_MNT`.
     *
     * الاستعلام المستخدم:
     * SELECT es.ENTRY_NO,
     *        SUBSTR(TO_CHAR(es.ENTRY_NO),1,1)||SUBSTR(TO_CHAR(es.ENTRY_NO),7,4) SUB_ENTRY_NO,
     *        em.ENTRY_DATE, et.ENTRY_TYPE_NAME,
     *        NVL(TRIM(es.SUB_DETAILS), em.DETAILS) DETAILS, es.ENTRY_MNT
     * FROM ACCOUNTS.ENTRY_SUB es
     * JOIN ACCOUNTS.ENTRY_MAIN em ON em.ENTRY_NO = es.ENTRY_NO
     * JOIN ACCOUNTS.ENTRY_TYPE et ON et.ENTRY_TYPE_NO = em.ENTRY_TYPE_NO
     * WHERE es.ACC_NO = :acc_no
     * ORDER BY em.ENTRY_DATE, es.ENTRY_NO, es.SEQ
     */
    private function fetchStatement(string $type, Request $request, $id): JsonResponse
    {
        $cfg = self::TABLES[$type];

        $fromDateParam = trim((string) ($request->input('from_date') ?? ''));
        $toDateParam = trim((string) ($request->input('to_date') ?? ''));

        try {
            // جلب بيانات العامل
            $worker = DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->first();

            if (! $worker) {
                return response()->json(['success' => false, 'message' => 'لم يتم العثور على العامل'], 404);
            }

            $wArr = (array) $worker;
            $accNo = $wArr[strtolower($cfg['acc_col'])] ?? $wArr[$cfg['acc_col']] ?? $worker->{$cfg['acc_col']} ?? null;

            if (! $accNo) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد رقم حساب مرتبط بهذا العامل',
                ], 422);
            }

            // تحديد نطاق التواريخ
            $fromDateFilter = null;
            $toDateFilter = null;

            if (! empty($fromDateParam)) {
                $parsed = $this->parseDateToIso($fromDateParam);
                if ($parsed) {
                    $fromDateFilter = $parsed;
                }
            }
            if (! empty($toDateParam)) {
                $parsed = $this->parseDateToIso($toDateParam);
                if ($parsed) {
                    $toDateFilter = $parsed;
                }
            }

            // استعلام جميع الحركات لحساب العامل — البيان من SUB_DETAILS أولاً ثم بيان
            // القيد الرئيسي، وبلا أي قراءة لـ BALANCE_S (انظر تعليق الدالة أعلاه).
            // All movements for the worker's account; description from the sub-entry
            // text first, main entry text as fallback; BALANCE_S is never selected.
            $sql = "
                SELECT es.ENTRY_NO,
                       SUBSTR(TO_CHAR(es.ENTRY_NO), 1, 1) || SUBSTR(TO_CHAR(es.ENTRY_NO), 7, 4) AS SUB_ENTRY_NO,
                       TO_CHAR(em.ENTRY_DATE, 'DD/MM/YYYY') AS ENTRY_DATE,
                       TO_CHAR(em.ENTRY_DATE, 'YYYY-MM-DD') AS RAW_DATE,
                       et.ENTRY_TYPE_NAME,
                       NVL(TRIM(es.SUB_DETAILS), em.DETAILS) AS DETAILS,
                       es.ENTRY_MNT
                FROM ACCOUNTS.ENTRY_SUB es
                JOIN ACCOUNTS.ENTRY_MAIN em ON em.ENTRY_NO = es.ENTRY_NO
                JOIN ACCOUNTS.ENTRY_TYPE et ON et.ENTRY_TYPE_NO = em.ENTRY_TYPE_NO
                WHERE es.ACC_NO = :acc_no
                ORDER BY em.ENTRY_DATE, es.ENTRY_NO, es.SEQ
            ";

            $rows = DB::connection('oracle')->select($sql, ['acc_no' => $accNo]);

            // إذا لم يتم تمرير تواريخ، نحدد آخر 30 يوماً من تاريخ أحدث حركة
            if (empty($fromDateParam) && empty($toDateParam) && count($rows) > 0) {
                $lastRow = end($rows);
                $lastDateStr = $lastRow->raw_date ?? $lastRow->RAW_DATE ?? null;
                if ($lastDateStr) {
                    $latestDate = Carbon::parse($lastDateStr);
                    $fromDateFilter = $latestDate->copy()->subDays(30)->format('Y-m-d');
                    $toDateFilter = $latestDate->format('Y-m-d');
                    $fromDateParam = $latestDate->copy()->subDays(30)->format('d/m/Y');
                    $toDateParam = $latestDate->format('d/m/Y');
                }
            }

            // معالجة النتائج وحساب الرصيد الافتتاحي والرصيد الجاري من ENTRY_MNT
            // Opening / running balance are derived from ENTRY_MNT, never from BALANCE_S.
            $openingBalance = 0.0;
            $runningBalance = 0.0;
            $periodMovement = 0.0;
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $filteredRows = [];
            $firstDate = null;
            $lastDate = null;

            foreach ($rows as $r) {
                $r = (array) $r;
                $rawDate = $r['RAW_DATE'] ?? $r['raw_date'] ?? null;
                $mnt = (float) ($r['ENTRY_MNT'] ?? $r['entry_mnt'] ?? 0);
                $entryDate = $r['ENTRY_DATE'] ?? $r['entry_date'] ?? null;

                // إذا كانت الحركة قبل تاريخ البداية المطلوب، تُحسب ضمن الرصيد الافتتاحي
                if ($fromDateFilter && $rawDate && $rawDate < $fromDateFilter) {
                    $openingBalance += $mnt;
                    $runningBalance = $openingBalance;

                    continue;
                }

                // إذا كانت بعد تاريخ النهاية المطلوب، نتوقف
                if ($toDateFilter && $rawDate && $rawDate > $toDateFilter) {
                    continue;
                }

                $debit = $mnt > 0 ? $mnt : 0.0;
                $credit = $mnt < 0 ? abs($mnt) : 0.0;

                $totalDebit += $debit;
                $totalCredit += $credit;

                // الرصيد الجاري = الافتتاحي + صافي حركة الفترة التراكمية
                // Running balance = opening balance + cumulative period movement.
                $periodMovement += $mnt;
                $runningBalance = $openingBalance + $periodMovement;

                if ($firstDate === null) {
                    $firstDate = $entryDate;
                }
                $lastDate = $entryDate;

                $filteredRows[] = [
                    'entry_no' => $r['ENTRY_NO'] ?? $r['entry_no'] ?? null,
                    'sub_entry_no' => $r['SUB_ENTRY_NO'] ?? $r['sub_entry_no'] ?? null,
                    'entry_date' => $entryDate,
                    'raw_date' => $rawDate,
                    'entry_type_name' => ArabicEncoding::fixMojibake($r['ENTRY_TYPE_NAME'] ?? $r['entry_type_name'] ?? ''),
                    'details' => ArabicEncoding::normalizeStatementText($r['DETAILS'] ?? $r['details'] ?? ''),
                    'balance' => $runningBalance,
                    'entry_mnt' => $mnt,
                    'debit' => $debit,
                    'credit' => $credit,
                    'acc_no' => $accNo,
                ];
            }

            $workerName = ArabicEncoding::fixMojibake((string) ($wArr[strtolower($cfg['name_col'])] ?? $wArr[$cfg['name_col']] ?? $worker->{$cfg['name_col']} ?? ''));

            return response()->json([
                'success' => true,
                'worker' => [
                    'id' => $id,
                    'name' => $workerName,
                    'acc_no' => $accNo,
                    'type' => $type,
                ],
                'data' => $filteredRows,
                'summary' => [
                    'total_rows' => count($filteredRows),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'opening_balance' => $openingBalance,
                    // الرصيد النهائي = الافتتاحي + صافي حركة الفترة
                    // Final balance = opening balance + net period movement.
                    'final_balance' => $openingBalance + $periodMovement,
                    'from_date' => $fromDateParam ?: ($firstDate ?: date('d/m/Y')),
                    'to_date' => $toDateParam ?: ($lastDate ?: date('d/m/Y')),
                    'currency' => 'يمني YR',
                    'account_title' => "$accNo - $workerName",
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في جلب كشف حساب $type", $e);
        }
    }

    /**
     * كشف العمل - يطابق جدول "كشف العمل" في تقرير أوراكل (التاريخ | الموديل | العدد | السعر | الإجمالي)
     * لا يمس جدول الكشف المالي (ENTRY_MAIN/ENTRY_SUB) إطلاقاً - فقط جدول CALC الخاص بالعامل.
     *
     * Work report - mirrors ONLY the "work" table of the legacy Oracle report
     * (date | style | count | unit price | total). It never touches the financial
     * statement tables; it reads the worker's *_FROM_CALC registry directly.
     */
    private function fetchWorkReport(string $type, Request $request, $id): JsonResponse
    {
        $cfg = self::TABLES[$type];

        // كشف العمل متاح فقط للسجلات التي لها جدول CALC معرّف (القصاصين والخياطين)
        // Work report exists only for registries that define a CALC table (cutters/tailors).
        if (empty($cfg['work_table'])) {
            return response()->json([
                'success' => false,
                'message' => 'كشف العمل غير متاح لهذا النوع',
            ], 422);
        }

        $fromDateParam = trim((string) ($request->input('from_date') ?? ''));
        $toDateParam = trim((string) ($request->input('to_date') ?? ''));

        try {
            // جلب صف العامل والتحقق من الحساب المرتبط
            $worker = DB::connection('oracle')
                ->table($cfg['table'])
                ->where($cfg['pk'], $id)
                ->first();

            if (! $worker) {
                return response()->json(['success' => false, 'message' => 'لم يتم العثور على العامل'], 404);
            }

            $wArr = (array) $worker;
            $accNo = $wArr[strtolower($cfg['acc_col'])] ?? $wArr[$cfg['acc_col']] ?? $worker->{$cfg['acc_col']} ?? null;
            $workerNo = $wArr[strtolower($cfg['pk'])] ?? $wArr[$cfg['pk']] ?? $id;

            if (! $accNo) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد رقم حساب مرتبط بهذا العامل',
                ], 422);
            }

            // أعمدة وجدول كشف العمل (تختلف بين القصاص والخياط)
            // Work-report columns/tables differ between cutters (AMT) and tailors (AMT_ALL).
            $dateCol = $cfg['work_date_col'];
            $totalCol = $cfg['work_total_col'];
            $styleCol = $cfg['work_style_col'];
            $qtyCol = $cfg['work_qty_col'];
            $calcTable = $cfg['work_table'];
            $regTable = $cfg['table'];
            $joinCol = $cfg['work_join_col'];

            // تحديد نطاق التواريخ:
            // - عند إرسال تاريخ(ين) صريح(ين) نستخدم المتاح منهما (الأحادي يُطابَق للطرفين).
            // - بلا أي تاريخ: الافتراضي = تاريخ آخر سجل عمل موجود لهذا القصاص/الخياط
            //   (من = إلى = ذلك اليوم)؛ وعند انعدام السجلات كلياً نعود لتاريخ اليوم كقيمة آمنة.
            $fromIso = $fromDateParam !== '' ? $this->parseDateToIso($fromDateParam) : null;
            $toIso = $toDateParam !== '' ? $this->parseDateToIso($toDateParam) : null;

            if ($fromIso === null && $toIso === null) {
                // جلب تاريخ آخر سجل عمل (MAX) للعامل عبر نفس الربط و ACC_NO المعتمدين في التقرير
                $lastRows = DB::connection('oracle')->select(
                    "SELECT TO_CHAR(MAX(w.$dateCol), 'YYYY-MM-DD') AS LAST_DATE
                       FROM $calcTable w, $regTable r
                      WHERE w.$joinCol = r.$joinCol
                        AND r.ACC_NO = :acc_no",
                    ['acc_no' => $accNo]
                );
                $lastDateStr = '';
                if ($lastRows) {
                    $lr = (array) $lastRows[0];
                    $lastDateStr = trim((string) ($lr['LAST_DATE'] ?? $lr['last_date'] ?? ''));
                }
                $defaultIso = $lastDateStr !== '' ? $lastDateStr : Carbon::today()->format('Y-m-d');
                $fromIso = $defaultIso;
                $toIso = $defaultIso;
            } else {
                // تاريخ واحد مرسَل: نجعل الطرف الآخر مطابقاً له حتى يكون النطاق صالحاً
                $fromIso ??= $toIso;
                $toIso ??= $fromIso;
            }

            // ضمان صحة المدى (إن كان من > إلى نجعلهما متطابقين مع قيمة واحدة)
            if ($fromIso && $toIso && $fromIso > $toIso) {
                $fromIso = $toIso;
            }

            $sql = "
                SELECT TO_CHAR(w.$dateCol, 'DD/MM/YYYY') AS WORK_DATE,
                       TO_CHAR(w.$dateCol, 'YYYY-MM-DD') AS RAW_DATE,
                       w.$styleCol AS STYLE_NAME,
                       NVL(w.$qtyCol, 0)   AS QTY,
                       NVL(w.$totalCol, 0) AS TOTAL
                FROM $calcTable w, $regTable r
                WHERE w.$joinCol = r.$joinCol
                  AND r.ACC_NO = :acc_no
                  AND w.$dateCol BETWEEN TO_DATE(:from_date,'YYYY-MM-DD') AND TO_DATE(:to_date,'YYYY-MM-DD') + 1 - 1/86400
                ORDER BY w.$dateCol, w.$styleCol
            ";

            $raw = DB::connection('oracle')->select($sql, [
                'acc_no' => $accNo,
                'from_date' => $fromIso,
                'to_date' => $toIso,
            ]);

            // بناء الأسطر + الإجماليات (العدد الإجمالي والمبلغ الإجمالي)
            $rows = [];
            $totalCount = 0;
            $totalAmount = 0.0;
            foreach ($raw as $r) {
                $r = (array) $r;
                $qty = (float) ($r['QTY'] ?? $r['qty'] ?? 0);
                $total = (float) ($r['TOTAL'] ?? $r['total'] ?? 0);
                // السعر = الإجمالي / العدد (يضمن العدد × السعر = الإجمالي دائماً، ويتعامل مع القسمة على صفر)
                $unitPrice = $qty > 0 ? $total / $qty : 0.0;

                $totalCount += $qty;
                $totalAmount += $total;

                $rows[] = [
                    'date' => $r['WORK_DATE'] ?? $r['work_date'] ?? '',
                    'style_name' => ArabicEncoding::fixMojibake(trim((string) ($r['STYLE_NAME'] ?? $r['style_name'] ?? ''))),
                    'count' => $qty,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ];
            }

            // currency label: join CUR_CODE via worker CUR_NO (mirrors Oracle "select CODE from CUR_CODE")
            $curNo = $wArr['cur_no'] ?? $wArr['CUR_NO'] ?? ($worker->CUR_NO ?? 1);
            $currency = 'يمني YR';
            if ($curNo) {
                $cur = DB::connection('oracle')
                    ->table('ACCOUNTS.CUR_CODE')
                    ->selectRaw('CODE, MAIN_CUR_NAME')
                    ->where('CUR_NO', $curNo)
                    ->first();
                if ($cur) {
                    $c = (array) $cur;
                    $code = ArabicEncoding::fixMojibake((string) ($c['CODE'] ?? $c['code'] ?? ''));
                    $name = ArabicEncoding::fixMojibake(trim((string) ($c['MAIN_CUR_NAME'] ?? $c['main_cur_name'] ?? '')));
                    if ($code !== '') {
                        $currency = ($name !== '' ? $name.' ' : '').$code;
                    }
                }
            }

            $workerName = ArabicEncoding::fixMojibake((string) ($wArr[strtolower($cfg['name_col'])] ?? $wArr[$cfg['name_col']] ?? $worker->{$cfg['name_col']} ?? ''));

            return response()->json([
                'success' => true,
                'worker' => [
                    'id' => $workerNo,
                    'name' => $workerName,
                    'acc_no' => $accNo,
                    'type' => $type,
                ],
                'data' => $rows,
                'summary' => [
                    'total_rows' => count($rows),
                    'total_count' => $totalCount,
                    'total_amount' => $totalAmount,
                    'from_date' => $fromIso ? Carbon::parse($fromIso)->format('d/m/Y') : '',
                    'to_date' => $toIso ? Carbon::parse($toIso)->format('d/m/Y') : '',
                    'currency' => $currency,
                    'account_title' => "$accNo - $workerName",
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في جلب كشف عمل $type", $e);
        }
    }

    /**
     * كشف عمل المجموعة — يجلب عمليات ALL العمال في مجموعة محددة بفترة زمنية
     * Group work report — fetches work rows for ALL workers of a group in a date range.
     * Mirrors Oracle FOR_CUTTER_CALC (multi-worker, includes worker name as first column).
     */
    private function fetchGroupWorkReport(string $type, Request $request): JsonResponse
    {
        $cfg = self::TABLES[$type];

        // كشف العمل متاح فقط للسجلات التي لها جدول CALC معرّف
        if (empty($cfg['work_table'])) {
            return response()->json([
                'success' => false,
                'message' => 'كشف العمل غير متاح لهذا النوع',
            ], 422);
        }

        // التواريخ: من الطلب أو الافتراضي (آخر 7 أيام)
        $fromDateParam = trim((string) ($request->input('from_date') ?? ''));
        $toDateParam = trim((string) ($request->input('to_date') ?? ''));

        $fromIso = $fromDateParam !== '' ? $this->parseDateToIso($fromDateParam) : null;
        $toIso = $toDateParam !== '' ? $this->parseDateToIso($toDateParam) : null;

        // الافتراضي: آخر 7 أيام من اليوم الحالي
        if ($fromIso === null && $toIso === null) {
            $toIso = Carbon::today()->format('Y-m-d');
            $fromIso = Carbon::today()->subDays(6)->format('Y-m-d');
        } else {
            $fromIso ??= $toIso;
            $toIso ??= $fromIso;
        }

        // ضمان صحة المدى
        if ($fromIso > $toIso) {
            $fromIso = $toIso;
        }

        // أعمدة وجدول كشف العمل
        $dateCol = $cfg['work_date_col'];
        $totalCol = $cfg['work_total_col'];
        $styleCol = $cfg['work_style_col'];
        $qtyCol = $cfg['work_qty_col'];
        $calcTable = $cfg['work_table'];
        $regTable = $cfg['table'];
        $joinCol = $cfg['work_join_col'];
        $nameCol = $cfg['name_col'];

        try {
            // التجميع: سطر واحد لكل (عامل + موديل) يجمع العدد والمبلغ عبر كل سجلات الفترة.
            // Aggregation: one row per (worker + style), summing count and amount across
            // every work record that falls inside the selected date range. التاريخ يُستعرض
            // كمدى زمني في ترويسة الكشف وليس كعمود لكل سطر.
            $sql = "
                SELECT r.{$nameCol} AS WORKER_NAME,
                       w.{$styleCol} AS STYLE_NAME,
                       SUM(NVL(w.{$qtyCol}, 0))   AS QTY,
                       SUM(NVL(w.{$totalCol}, 0)) AS TOTAL
                FROM {$calcTable} w, {$regTable} r
                WHERE w.{$joinCol} = r.{$joinCol}
                  AND w.{$dateCol} BETWEEN TO_DATE(:from_date,'YYYY-MM-DD') AND TO_DATE(:to_date,'YYYY-MM-DD') + 1 - 1/86400
                GROUP BY r.{$nameCol}, w.{$styleCol}
                ORDER BY r.{$nameCol}, w.{$styleCol}
            ";

            $raw = DB::connection('oracle')->select($sql, [
                'from_date' => $fromIso,
                'to_date' => $toIso,
            ]);

            // بناء الأسطر المجمّعة والإجماليات
            $rows = [];
            $totalCount = 0;
            $totalAmount = 0.0;
            $workerNames = [];

            foreach ($raw as $r) {
                $r = (array) $r;
                $qty = (float) ($r['QTY'] ?? $r['qty'] ?? 0);
                $total = (float) ($r['TOTAL'] ?? $r['total'] ?? 0);
                // سعر الوحدة للمجموعة = إجمالي المبلغ ÷ إجمالي العدد (آمن من القسمة على صفر)
                $unitPrice = $qty > 0 ? $total / $qty : 0.0;

                $totalCount += $qty;
                $totalAmount += $total;

                $workerName = ArabicEncoding::fixMojibake(trim((string) ($r['WORKER_NAME'] ?? $r['worker_name'] ?? '')));
                $workerNames[$workerName] = true;

                $rows[] = [
                    'worker_name' => $workerName,
                    'style_name' => ArabicEncoding::fixMojibake(trim((string) ($r['STYLE_NAME'] ?? $r['style_name'] ?? ''))),
                    'count' => $qty,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ];
            }

            return response()->json([
                'success' => true,
                'group' => $type,
                'data' => $rows,
                'summary' => [
                    'total_count' => $totalCount,
                    'total_amount' => $totalAmount,
                    'from_date' => Carbon::parse($fromIso)->format('d/m/Y'),
                    'to_date' => Carbon::parse($toIso)->format('d/m/Y'),
                    'worker_count' => count($workerNames),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse("خطأ في جلب كشف عمل مجموعة $type", $e);
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
            'message' => $msg.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
