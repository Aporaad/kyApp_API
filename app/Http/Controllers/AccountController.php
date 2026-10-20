<?php

namespace App\Http\Controllers;

use App\Support\ArabicEncoding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AccountController
 * إدارة الحسابات المحاسبية - يتصل مباشرة بقاعدة بيانات Oracle 11g
 *
 * جداول قاعدة البيانات:
 * - ACCOUNT        : الأقسام الرئيسية  (ACC_NO, ACC_NAME, BALANCE, CUR_NO)
 * - ACC_MAIN       : الحسابات الفرعية  (ACC_NO→ACCOUNT, ACC_SUB PK, SUB_NAME)
 * - ACC_SUB        : الحسابات الجزئية  (ACC_SUB→ACC_MAIN, ACC_PART PK, PART_NAME)
 * - ACC_SUB_GROUP  : مجموعات الجزئي   (ACC_PART→ACC_SUB, GROUP_ID PK, ACC_GROUP_NAME)
 * - CHART_ACC      : الحسابات المالية  (ACC_NO PK, ACC_NAME, BALANCE, GROUP_ID, SEQ)
 */
class AccountController extends Controller
{
    // =========================================================
    // ===           جلب هيكل الشجرة المحاسبية             ===
    // =========================================================

    /**
     * GET /accounts/account
     * جلب جميع الأقسام الرئيسية (ACCOUNT)
     */
    public function getAccounts()
    {
        try {
            $rows = DB::connection('oracle')->select('SELECT ACC_NO, ACC_NAME, BALANCE, CUR_NO FROM ACCOUNT ORDER BY ACC_NO');
            $data = array_map(function ($row) {
                $r = (array) $row;

                return [
                    'acc_no' => (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0),
                    'acc_name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    'cur_no' => (int) ($r['CUR_NO'] ?? $r['cur_no'] ?? 0),
                ];
            }, $rows);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/main?acc_no=1
     * جلب الحسابات الفرعية (ACC_MAIN) مع فلتر اختياري بالقسم الرئيسي
     */
    public function getAccMain(Request $request)
    {
        try {
            $accNo = $request->query('acc_no');
            $sql = 'SELECT ACC_NO, ACC_SUB, SUB_NAME, BALANCE, CUR_NO FROM ACC_MAIN';
            $params = [];
            if ($accNo) {
                $sql .= ' WHERE ACC_NO = :acc_no';
                $params['acc_no'] = $accNo;
            }
            $sql .= ' ORDER BY ACC_NO, ACC_SUB';
            $rows = DB::connection('oracle')->select($sql, $params);
            $data = array_map(function ($row) {
                $r = (array) $row;

                return [
                    'acc_no' => (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0),
                    'acc_sub' => (int) ($r['ACC_SUB'] ?? $r['acc_sub'] ?? 0),
                    'sub_name' => ArabicEncoding::fixMojibake($r['SUB_NAME'] ?? $r['sub_name'] ?? ''),
                    'balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    'cur_no' => (int) ($r['CUR_NO'] ?? $r['cur_no'] ?? 0),
                ];
            }, $rows);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/sub?acc_sub=12
     * جلب الحسابات الجزئية (ACC_SUB) مع فلتر اختياري بالحساب الفرعي
     */
    public function getAccSub(Request $request)
    {
        try {
            $accSub = $request->query('acc_sub');
            $sql = 'SELECT ACC_SUB, ACC_PART, PART_NAME, BALANCE, CUR_NO FROM ACC_SUB';
            $params = [];
            if ($accSub) {
                $sql .= ' WHERE ACC_SUB = :acc_sub';
                $params['acc_sub'] = $accSub;
            }
            $sql .= ' ORDER BY ACC_SUB, ACC_PART';
            $rows = DB::connection('oracle')->select($sql, $params);
            $data = array_map(function ($row) {
                $r = (array) $row;

                return [
                    'acc_sub' => (int) ($r['ACC_SUB'] ?? $r['acc_sub'] ?? 0),
                    'acc_part' => (int) ($r['ACC_PART'] ?? $r['acc_part'] ?? 0),
                    'part_name' => ArabicEncoding::fixMojibake($r['PART_NAME'] ?? $r['part_name'] ?? ''),
                    'balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    'cur_no' => (int) ($r['CUR_NO'] ?? $r['cur_no'] ?? 0),
                ];
            }, $rows);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/sub-group?acc_part=123
     * جلب مجموعات الحسابات الجزئي (ACC_SUB_GROUP)
     */
    public function getAccSubGroup(Request $request)
    {
        try {
            $accPart = $request->query('acc_part');
            $sql = 'SELECT GROUP_ID, ACC_PART, ACC_GROUP_NAME FROM ACC_SUB_GROUP';
            $params = [];
            if ($accPart) {
                $sql .= ' WHERE ACC_PART = :acc_part';
                $params['acc_part'] = $accPart;
            }
            $sql .= ' ORDER BY ACC_PART, GROUP_ID';
            $rows = DB::connection('oracle')->select($sql, $params);
            $data = array_map(function ($row) {
                $r = (array) $row;

                return [
                    'group_id' => (int) ($r['GROUP_ID'] ?? $r['group_id'] ?? 0),
                    'acc_part' => (int) ($r['ACC_PART'] ?? $r['acc_part'] ?? 0),
                    'acc_group_name' => ArabicEncoding::fixMojibake($r['ACC_GROUP_NAME'] ?? $r['acc_group_name'] ?? ''),
                ];
            }, $rows);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================
    // ===          الحسابات المالية  (CHART_ACC)           ===
    // =========================================================

    /**
     * GET /accounts/chart
     * جلب الحسابات المالية مع بحث + ترقيم صفحات (متوافق مع Oracle 11g)
     * Query params: search, page, limit, group_id
     */
    public function getChartAccounts(Request $request)
    {
        try {
            $search = trim((string) $request->query('search', ''));
            $page = max(1, (int) $request->query('page', 1));
            $limit = min(100, max(10, (int) $request->query('limit', 50)));
            $groupId = $request->query('group_id');
            $offset = ($page - 1) * $limit;
            $maxRow = $offset + $limit;

            $where = [];
            $params = [];

            if (! empty($search)) {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $where[] = '(UPPER(ACC_NAME) LIKE UPPER(:search) OR ACC_NAME LIKE :search_enc OR TO_CHAR(ACC_NO) LIKE :search_no)';
                $params['search'] = '%'.$search.'%';
                $params['search_enc'] = '%'.$oracleSearch.'%';
                $params['search_no'] = '%'.$search.'%';
            }
            if ($groupId !== null && $groupId !== '') {
                $where[] = 'GROUP_ID = :group_id';
                $params['group_id'] = $groupId;
            }

            $whereClause = count($where) ? 'WHERE '.implode(' AND ', $where) : '';

            // Count total
            $countSql = "SELECT COUNT(*) as TOTAL FROM CHART_ACC $whereClause";
            $countRows = DB::connection('oracle')->select($countSql, $params);
            $countArr = (array) ($countRows[0] ?? []);
            $total = (int) ($countArr['TOTAL'] ?? $countArr['total'] ?? 0);

            // Fetch page with Oracle 11g compatible pagination
            $sql = "
                SELECT * FROM (
                    SELECT a.*, ROWNUM rnum FROM (
                        SELECT ACC_NO, ACC_NAME, BALANCE, TO_CHAR(ACC_DATE,'YYYY-MM-DD') as ACC_DATE,
                               SEQ, GROUP_ID, LIMITED_BALANCE, STOPPING_ACC
                        FROM CHART_ACC $whereClause
                        ORDER BY ACC_NO
                    ) a
                    WHERE ROWNUM <= :max_row
                )
                WHERE rnum > :offset
            ";

            $fetchParams = $params;
            $fetchParams['max_row'] = $maxRow;
            $fetchParams['offset'] = $offset;

            $rows = DB::connection('oracle')->select($sql, $fetchParams);

            $data = array_map(function ($row) {
                $r = (array) $row;

                return [
                    'acc_no' => (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0),
                    'acc_name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    'acc_date' => $r['ACC_DATE'] ?? $r['acc_date'] ?? null,
                    'seq' => (int) ($r['SEQ'] ?? $r['seq'] ?? 0),
                    'group_id' => isset($r['GROUP_ID']) || isset($r['group_id']) ? (int) ($r['GROUP_ID'] ?? $r['group_id']) : null,
                    'limited_balance' => isset($r['LIMITED_BALANCE']) || isset($r['limited_balance']) ? (float) ($r['LIMITED_BALANCE'] ?? $r['limited_balance']) : null,
                    'stopping_acc' => isset($r['STOPPING_ACC']) || isset($r['stopping_acc']) ? (int) ($r['STOPPING_ACC'] ?? $r['stopping_acc']) : null,
                ];
            }, $rows);

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
                    'has_more' => ($page * $limit) < $total,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /accounts/chart
     * إنشاء حساب مالي جديد في CHART_ACC
     */
    public function createChartAccount(Request $request)
    {
        try {
            $request->validate([
                'acc_name' => 'required|string|max:150',
                'group_id' => 'required|integer',
            ]);

            $groupId = $request->input('group_id');

            // جلب ACC_PART للمجموعة
            $groupRow = DB::connection('oracle')->selectOne(
                'SELECT ACC_PART FROM ACC_SUB_GROUP WHERE GROUP_ID = :gid',
                ['gid' => $groupId]
            );
            if (! $groupRow) {
                return response()->json(['success' => false, 'message' => 'المجموعة غير موجودة'], 404);
            }
            $groupArr = (array) $groupRow;
            $accPart = $groupArr['ACC_PART'] ?? $groupArr['acc_part'];

            // أعلى رقم حساب موجود في نفس ACC_PART (أول 5 أرقام = ACC_PART * 10000)
            $maxRow = DB::connection('oracle')->selectOne(
                'SELECT MAX(ACC_NO) as MAX_NO FROM CHART_ACC WHERE TRUNC(ACC_NO / 10000) = :part',
                ['part' => $accPart]
            );
            $maxArr = (array) $maxRow;
            $maxNo = $maxArr['MAX_NO'] ?? $maxArr['max_no'] ?? null;
            $basePrefix = $accPart * 10000;
            $nextNo = $maxNo ? ((int) $maxNo + 1) : ($basePrefix + 1);

            $accName = ArabicEncoding::toOracleSearchChars($request->input('acc_name'));

            DB::connection('oracle')->insert(
                'INSERT INTO CHART_ACC (ACC_NO, ACC_NAME, BALANCE, ACC_DATE, GROUP_ID, LIMITED_BALANCE)
                 VALUES (:acc_no, :acc_name, :balance, SYSDATE, :group_id, :limited)',
                [
                    'acc_no' => $nextNo,
                    'acc_name' => $accName,
                    'balance' => $request->input('balance', 0),
                    'group_id' => $groupId,
                    'limited' => $request->input('limited_balance', 0),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء الحساب بنجاح',
                'data' => ['acc_no' => $nextNo],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /accounts/chart/{accNo}
     * تعديل حساب مالي موجود
     */
    public function updateChartAccount(Request $request, $accNo)
    {
        try {
            $existing = DB::connection('oracle')->selectOne('SELECT ACC_NO FROM CHART_ACC WHERE ACC_NO = :no', ['no' => $accNo]);
            if (! $existing) {
                return response()->json(['success' => false, 'message' => 'الحساب غير موجود'], 404);
            }

            $fields = [];
            $params = ['acc_no' => $accNo];

            if ($request->has('acc_name')) {
                $fields[] = 'ACC_NAME = :acc_name';
                $params['acc_name'] = ArabicEncoding::toOracleSearchChars($request->input('acc_name'));
            }
            if ($request->has('balance')) {
                $fields[] = 'BALANCE = :balance';
                $params['balance'] = $request->input('balance');
            }
            if ($request->has('group_id')) {
                $fields[] = 'GROUP_ID = :group_id';
                $params['group_id'] = $request->input('group_id');
            }
            if ($request->has('limited_balance')) {
                $fields[] = 'LIMITED_BALANCE = :limited';
                $params['limited'] = $request->input('limited_balance');
            }
            if ($request->has('stopping_acc')) {
                $fields[] = 'STOPPING_ACC = :stopping';
                $params['stopping'] = $request->input('stopping_acc');
            }

            if (empty($fields)) {
                return response()->json(['success' => false, 'message' => 'لا توجد بيانات للتعديل'], 400);
            }

            DB::connection('oracle')->update('UPDATE CHART_ACC SET '.implode(', ', $fields).' WHERE ACC_NO = :acc_no', $params);

            return response()->json(['success' => true, 'message' => 'تم تعديل الحساب بنجاح']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /accounts/sub-group
     * إنشاء مجموعة حسابات جزئي جديدة
     */
    public function createSubGroup(Request $request)
    {
        try {
            $request->validate([
                'acc_group_name' => 'required|string|max:100',
                'acc_part' => 'required|integer',
            ]);

            $maxRow = DB::connection('oracle')->selectOne('SELECT MAX(GROUP_ID) as MAX_ID FROM ACC_SUB_GROUP');
            $maxArr = (array) $maxRow;
            $maxId = $maxArr['MAX_ID'] ?? $maxArr['max_id'] ?? null;
            $nextId = $maxId ? ((int) $maxId + 1) : 1;

            $groupName = ArabicEncoding::toOracleSearchChars($request->input('acc_group_name'));

            DB::connection('oracle')->insert(
                'INSERT INTO ACC_SUB_GROUP (GROUP_ID, ACC_PART, ACC_GROUP_NAME) VALUES (:gid, :part, :name)',
                [
                    'gid' => $nextId,
                    'part' => $request->input('acc_part'),
                    'name' => $groupName,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء المجموعة بنجاح',
                'data' => ['group_id' => $nextId],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /accounts/sub-group/{groupId}
     * تعديل مجموعة حسابات جزئي
     */
    public function updateSubGroup(Request $request, $groupId)
    {
        try {
            $existing = DB::connection('oracle')->selectOne('SELECT GROUP_ID FROM ACC_SUB_GROUP WHERE GROUP_ID = :gid', ['gid' => $groupId]);
            if (! $existing) {
                return response()->json(['success' => false, 'message' => 'المجموعة غير موجودة'], 404);
            }

            $fields = [];
            $params = ['group_id' => $groupId];

            if ($request->has('acc_group_name')) {
                $fields[] = 'ACC_GROUP_NAME = :name';
                $params['name'] = ArabicEncoding::toOracleSearchChars($request->input('acc_group_name'));
            }
            if ($request->has('acc_part')) {
                $fields[] = 'ACC_PART = :part';
                $params['part'] = $request->input('acc_part');
            }

            if (empty($fields)) {
                return response()->json(['success' => false, 'message' => 'لا توجد بيانات للتعديل'], 400);
            }

            DB::connection('oracle')->update('UPDATE ACC_SUB_GROUP SET '.implode(', ', $fields).' WHERE GROUP_ID = :group_id', $params);

            return response()->json(['success' => true, 'message' => 'تم تعديل المجموعة بنجاح']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================
    // ===              كشف الحساب                          ===
    // =========================================================

    /**
     * GET /accounts/chart/{accNo}/statement
     * كشف حساب مالي واحد
     * Query params: from_date (DD/MM/YYYY), to_date, page, limit
     */
    public function getAccountStatement(Request $request, $accNo)
    {
        try {
            $accRow = DB::connection('oracle')->selectOne(
                'SELECT ACC_NO, ACC_NAME, BALANCE, GROUP_ID FROM CHART_ACC WHERE ACC_NO = :no',
                ['no' => $accNo]
            );
            if (! $accRow) {
                return response()->json(['success' => false, 'message' => 'الحساب غير موجود'], 404);
            }
            $accArr = (array) $accRow;

            $fromDate = $request->query('from_date');
            $toDate = $request->query('to_date');
            $page = max(1, (int) $request->query('page', 1));
            $limit = min(200, max(10, (int) $request->query('limit', 50)));
            $offset = ($page - 1) * $limit;
            $maxRow = $offset + $limit;

            $dateWhere = '';
            $params = ['acc_no' => $accNo];

            if ($fromDate) {
                $dateWhere .= " AND TRXN_DATE >= TO_DATE(:from_date, 'DD/MM/YYYY')";
                $params['from_date'] = $fromDate;
            }
            if ($toDate) {
                $dateWhere .= " AND TRXN_DATE <= TO_DATE(:to_date, 'DD/MM/YYYY')";
                $params['to_date'] = $toDate;
            }

            $transactions = [];
            $total = 0;

            try {
                $countSql = "SELECT COUNT(*) as TOTAL FROM ACC_TRXN WHERE ACC_NO = :acc_no $dateWhere";
                $countRow = DB::connection('oracle')->selectOne($countSql, $params);
                $cArr = (array) $countRow;
                $total = (int) ($cArr['TOTAL'] ?? $cArr['total'] ?? 0);

                $trxnSql = "
                    SELECT * FROM (
                        SELECT a.*, ROWNUM rnum FROM (
                            SELECT TRXN_DATE, DESCRIPTION, DEBIT, CREDIT, BALANCE
                            FROM ACC_TRXN
                            WHERE ACC_NO = :acc_no $dateWhere
                            ORDER BY TRXN_DATE, SEQ
                        ) a
                        WHERE ROWNUM <= :max_row
                    )
                    WHERE rnum > :offset
                ";
                $tParams = $params;
                $tParams['max_row'] = $maxRow;
                $tParams['offset'] = $offset;

                $trxnRows = DB::connection('oracle')->select($trxnSql, $tParams);
                $transactions = array_map(function ($row) {
                    $r = (array) $row;

                    return [
                        'date' => $r['TRXN_DATE'] ?? $r['trxn_date'] ?? null,
                        'description' => ArabicEncoding::fixMojibake($r['DESCRIPTION'] ?? $r['description'] ?? ''),
                        'debit' => (float) ($r['DEBIT'] ?? $r['debit'] ?? 0),
                        'credit' => (float) ($r['CREDIT'] ?? $r['credit'] ?? 0),
                        'balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    ];
                }, $trxnRows);
            } catch (\Exception) {
                // جدول الحركات غير موجود
            }

            $accName = ArabicEncoding::fixMojibake($accArr['ACC_NAME'] ?? $accArr['acc_name'] ?? '');
            $balance = (float) ($accArr['BALANCE'] ?? $accArr['balance'] ?? 0);

            return response()->json([
                'success' => true,
                'data' => [
                    'account' => [
                        'acc_no' => (int) ($accArr['ACC_NO'] ?? $accArr['acc_no'] ?? $accNo),
                        'acc_name' => $accName,
                        'balance' => $balance,
                        'group_id' => isset($accArr['GROUP_ID']) || isset($accArr['group_id']) ? (int) ($accArr['GROUP_ID'] ?? $accArr['group_id']) : null,
                    ],
                    'transactions' => $transactions,
                    'summary' => [
                        'current_balance' => $balance,
                        'total_records' => (int) $total,
                    ],
                ],
                'meta' => [
                    'total' => (int) $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
                    'has_more' => ($page * $limit) < $total,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/statement-detail
     * كشف حساب مفصل بخيارات متقدمة
     * Query params: type (account|main|sub|group), id, from_date, to_date, cur_no, page, limit
     */
    public function getDetailedStatement(Request $request)
    {
        try {
            $type = $request->query('type', 'account');
            $id = $request->query('id');
            $fromDate = $request->query('from_date');
            $toDate = $request->query('to_date');
            $curNo = $request->query('cur_no');
            $page = max(1, (int) $request->query('page', 1));
            $limit = min(200, max(10, (int) $request->query('limit', 50)));
            $offset = ($page - 1) * $limit;
            $maxRow = $offset + $limit;

            $accounts = [];
            $totalDebit = 0;
            $totalCredit = 0;
            $balance = 0;

            switch ($type) {
                case 'account':
                    $rows = $id
                        ? DB::connection('oracle')->select('SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC c
                                     INNER JOIN ACC_SUB s ON TRUNC(c.ACC_NO/10000) = s.ACC_PART
                                     INNER JOIN ACC_MAIN m ON s.ACC_SUB = m.ACC_SUB
                                     WHERE m.ACC_NO = :id ORDER BY c.ACC_NO', ['id' => $id])
                        : DB::connection('oracle')->select('
                            SELECT * FROM (
                                SELECT a.*, ROWNUM rnum FROM (
                                    SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC ORDER BY ACC_NO
                                ) a
                                WHERE ROWNUM <= :max_row
                            )
                            WHERE rnum > :offset', ['max_row' => $maxRow, 'offset' => $offset]);
                    break;

                case 'main':
                    $rows = DB::connection('oracle')->select(
                        'SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC c
                         INNER JOIN ACC_SUB s ON TRUNC(c.ACC_NO/10000) = s.ACC_PART
                         WHERE s.ACC_SUB = :id ORDER BY c.ACC_NO',
                        ['id' => $id]
                    );
                    break;

                case 'sub':
                    $rows = DB::connection('oracle')->select(
                        'SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC c
                         WHERE TRUNC(c.ACC_NO/10000) = :id ORDER BY c.ACC_NO',
                        ['id' => $id]
                    );
                    break;

                case 'group':
                    $rows = DB::connection('oracle')->select(
                        'SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC WHERE GROUP_ID = :id ORDER BY ACC_NO',
                        ['id' => $id]
                    );
                    break;

                default:
                    $rows = $id
                        ? DB::connection('oracle')->select('SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC WHERE ACC_NO = :id', ['id' => $id])
                        : [];
            }

            foreach ($rows as $row) {
                $r = (array) $row;
                $rowBal = (float) ($r['BALANCE'] ?? $r['balance'] ?? 0);
                $balance += $rowBal;
                $accounts[] = [
                    'acc_no' => (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0),
                    'acc_name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'balance' => $rowBal,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $accounts,
                    'total_count' => count($accounts),
                    'summary' => [
                        'total_balance' => $balance,
                        'total_debit' => $totalDebit,
                        'total_credit' => $totalCredit,
                        'from_date' => $fromDate,
                        'to_date' => $toDate,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================
    // ===              الشجرة المحاسبية                    ===
    // =========================================================

    /**
     * GET /accounts/tree
     * الشجرة المحاسبية الكاملة
     */
    public function getAccountTree()
    {
        try {
            $accounts = DB::connection('oracle')->select('SELECT ACC_NO, ACC_NAME FROM ACCOUNT ORDER BY ACC_NO');

            $tree = [];

            foreach ($accounts as $accRow) {
                $acc = (array) $accRow;
                $accNo = (int) ($acc['ACC_NO'] ?? $acc['acc_no']);
                $accNode = [
                    'id' => 'acc_'.$accNo,
                    'acc_no' => $accNo,
                    'name' => ArabicEncoding::fixMojibake($acc['ACC_NAME'] ?? $acc['acc_name'] ?? ''),
                    'level' => 'account',
                    'children' => [],
                ];

                $mains = DB::connection('oracle')->select(
                    'SELECT ACC_NO, ACC_SUB, SUB_NAME, BALANCE FROM ACC_MAIN WHERE ACC_NO = :no ORDER BY ACC_SUB',
                    ['no' => $accNo]
                );

                foreach ($mains as $mainRow) {
                    $main = (array) $mainRow;
                    $accSub = (int) ($main['ACC_SUB'] ?? $main['acc_sub']);
                    $mainNode = [
                        'id' => 'main_'.$accSub,
                        'acc_sub' => $accSub,
                        'name' => ArabicEncoding::fixMojibake($main['SUB_NAME'] ?? $main['sub_name'] ?? ''),
                        'level' => 'main',
                        'balance' => (float) ($main['BALANCE'] ?? $main['balance'] ?? 0),
                        'children' => [],
                    ];

                    $subs = DB::connection('oracle')->select(
                        'SELECT ACC_SUB, ACC_PART, PART_NAME, BALANCE FROM ACC_SUB WHERE ACC_SUB = :sub ORDER BY ACC_PART',
                        ['sub' => $accSub]
                    );

                    foreach ($subs as $subRow) {
                        $sub = (array) $subRow;
                        $accPart = (int) ($sub['ACC_PART'] ?? $sub['acc_part']);
                        $subNode = [
                            'id' => 'sub_'.$accPart,
                            'acc_part' => $accPart,
                            'name' => ArabicEncoding::fixMojibake($sub['PART_NAME'] ?? $sub['part_name'] ?? ''),
                            'level' => 'sub',
                            'balance' => (float) ($sub['BALANCE'] ?? $sub['balance'] ?? 0),
                            'children' => [],
                        ];

                        $groups = DB::connection('oracle')->select(
                            'SELECT GROUP_ID, ACC_PART, ACC_GROUP_NAME FROM ACC_SUB_GROUP WHERE ACC_PART = :part ORDER BY GROUP_ID',
                            ['part' => $accPart]
                        );

                        foreach ($groups as $groupRow) {
                            $group = (array) $groupRow;
                            $groupId = (int) ($group['GROUP_ID'] ?? $group['group_id']);

                            // استثناء مجموعة العملاء (GROUP_ID=5, ACC_PART=123)
                            if ($accPart == 123 && $groupId == 5) {
                                $koboudiAcc = DB::connection('oracle')->selectOne(
                                    'SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC WHERE ACC_NO = 12310000'
                                );
                                if ($koboudiAcc) {
                                    $kArr = (array) $koboudiAcc;
                                    $kNo = (int) ($kArr['ACC_NO'] ?? $kArr['acc_no']);
                                    $subNode['children'][] = [
                                        'id' => 'chart_'.$kNo,
                                        'acc_no' => $kNo,
                                        'name' => ArabicEncoding::fixMojibake($kArr['ACC_NAME'] ?? $kArr['acc_name'] ?? ''),
                                        'level' => 'chart',
                                        'balance' => (float) ($kArr['BALANCE'] ?? $kArr['balance'] ?? 0),
                                        'children' => [],
                                    ];
                                }

                                continue;
                            }

                            $groupNode = [
                                'id' => 'group_'.$groupId,
                                'group_id' => $groupId,
                                'name' => ArabicEncoding::fixMojibake($group['ACC_GROUP_NAME'] ?? $group['acc_group_name'] ?? ''),
                                'level' => 'group',
                                'children' => [],
                            ];

                            $charts = DB::connection('oracle')->select(
                                'SELECT ACC_NO, ACC_NAME, BALANCE, STOPPING_ACC
                                 FROM CHART_ACC WHERE GROUP_ID = :gid ORDER BY ACC_NO',
                                ['gid' => $groupId]
                            );

                            foreach ($charts as $chartRow) {
                                $chart = (array) $chartRow;
                                $chartNo = (int) ($chart['ACC_NO'] ?? $chart['acc_no']);
                                $groupNode['children'][] = [
                                    'id' => 'chart_'.$chartNo,
                                    'acc_no' => $chartNo,
                                    'name' => ArabicEncoding::fixMojibake($chart['ACC_NAME'] ?? $chart['acc_name'] ?? ''),
                                    'level' => 'chart',
                                    'balance' => (float) ($chart['BALANCE'] ?? $chart['balance'] ?? 0),
                                    'stopping_acc' => isset($chart['STOPPING_ACC']) || isset($chart['stopping_acc']) ? (int) ($chart['STOPPING_ACC'] ?? $chart['stopping_acc']) : null,
                                    'children' => [],
                                ];
                            }

                            if (! empty($groupNode['children'])) {
                                $subNode['children'][] = $groupNode;
                            }
                        }

                        if ($accPart != 123) {
                            $noGroupCharts = DB::connection('oracle')->select(
                                'SELECT ACC_NO, ACC_NAME, BALANCE, STOPPING_ACC
                                 FROM CHART_ACC
                                 WHERE GROUP_ID IS NULL AND TRUNC(ACC_NO/10000) = :part
                                 ORDER BY ACC_NO',
                                ['part' => $accPart]
                            );

                            foreach ($noGroupCharts as $chartRow) {
                                $chart = (array) $chartRow;
                                $chartNo = (int) ($chart['ACC_NO'] ?? $chart['acc_no']);
                                $subNode['children'][] = [
                                    'id' => 'chart_ng_'.$chartNo,
                                    'acc_no' => $chartNo,
                                    'name' => ArabicEncoding::fixMojibake($chart['ACC_NAME'] ?? $chart['acc_name'] ?? ''),
                                    'level' => 'chart',
                                    'balance' => (float) ($chart['BALANCE'] ?? $chart['balance'] ?? 0),
                                    'stopping_acc' => isset($chart['STOPPING_ACC']) || isset($chart['stopping_acc']) ? (int) ($chart['STOPPING_ACC'] ?? $chart['stopping_acc']) : null,
                                    'children' => [],
                                ];
                            }
                        }

                        if (! empty($subNode['children'])) {
                            $mainNode['children'][] = $subNode;
                        }
                    }

                    if (! empty($mainNode['children'])) {
                        $accNode['children'][] = $mainNode;
                    }
                }

                if (! empty($accNode['children'])) {
                    $tree[] = $accNode;
                }
            }

            return response()->json(['success' => true, 'data' => $tree]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
