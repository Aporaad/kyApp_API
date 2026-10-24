<?php

namespace App\Http\Controllers;

use App\Support\ArabicEncoding;
use Carbon\Carbon;
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
    /**
     * معرّف الحساب الجزئي للعملاء في القاعدة التراثية: كل حسابات العملاء تبدأ بـ 123
     * (أكثر من 10 آلاف حساب). تُستثنى من عرض الشجرة حصراً، وتبقى داخل التجميع.
     * The legacy customers part: hidden from the tree display only, never from roll-ups.
     */
    private const CUSTOMERS_ACC_PART = 123;

    /** حساب «عملاء الكبودي للخياطة» — المستثنى الوحيد المعروض تحت الجزء 123. */
    private const KOBOUDI_CUSTOMER_ACC_NO = 12310000;
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
     * عملة الحساب المالي مشفَّرة في رقمه لا في عمود: CHART_ACC لا يملك CUR_NO، والخانة
     * الرابعة من ACC_NO هي رقم العملة في CURRENCY —
     *   25120016 = الجزء 251  +  العملة 2 (دولار)  +  التسلسل 0016
     *   11010001 = الجزء 110  +  العملة 1 (ريال)   +  التسلسل 0001
     * والقراءة من اليسار لا من اليمين لأن أرقام العملاء تسعة خانات (123110000…) وتتبع
     * القاعدة نفسها. لا عمود يُضاف ولا جدول: الرقم هو مخزن العملة عند هذا النظام التراثي.
     *
     * The chart account's currency is carried by its NUMBER, not a column: the 4th digit
     * from the left is CURRENCY.CUR_NO. Read positionally so 8- and 9-digit numbers agree.
     */
    private function currencyOfChartNumber(int $accNo): ?int
    {
        $digits = (string) $accNo;
        if (strlen($digits) < 4) {
            return null; // رقم أقصر من أن يحمل خانة عملة — لا يُختلق له عملة
        }
        $curNo = (int) $digits[3];

        return $curNo > 0 ? $curNo : null;
    }

    /**
     * تالية رقم حساب داخل نطاق (جزء, عملة) واحد: المُنخفض = جزء×100000 + عملة×10000،
     * والسقف عشرة آلاف رقم (أربعة أرقام تسلسل). النطاق القديم كان الجزء كاملاً، فيأخذ
     * MAX أعلىِ عملة ترتيباً ثم يمنح رقمها لحساب طلبه المستخدم بعملة أخرى: الجزء 251
     * أقصى رقمه 25130011 (عملة 3) فوُلد 25130012 حساباً سعودياً لم يُطلب.
     *
     * Next ACC_NO inside a single (part, currency) band. The previous range spanned the
     * whole part, so MAX landed in whichever currency sorts highest and the new account
     * was silently numbered under a currency its user never chose.
     *
     * @return array{number: int|null, error: string|null}
     */
    private function nextChartAccountNumber(int $accPart, int $curNo): array
    {
        $lo = $accPart * 100000 + $curNo * 10000;
        $hi = $lo + 10000;

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT MAX(ACC_NO) AS MAX_NO FROM CHART_ACC WHERE ACC_NO >= :lo AND ACC_NO < :hi',
            ['lo' => $lo, 'hi' => $hi]
        );
        $max = $row['MAX_NO'] ?? $row['max_no'] ?? null;
        // أول رقم في النطاق = تسلسل 0001، مطابق لأول حساب في كل جزء قديم (11010001)
        $next = $max !== null ? (int) $max + 1 : $lo + 1;

        if ($next >= $hi) {
            return [
                'number' => null,
                'error' => 'نفدت أرقام العملة '.$curNo.' داخل الحساب الجزئي '.$accPart
                    .' (عشرة آلاف حساب لكل عملة) — لن يُمنح رقم يخرق قاعدته',
            ];
        }

        return ['number' => $next, 'error' => null];
    }

    /**
     * POST /accounts/chart
     * إنشاء حساب مالي جديد في CHART_ACC
     *
     * التولير الصحيح: رقم الحساب = بادئة الجزء (3 أرقام) + رقم العملة (خانة واحدة) +
     * التسلسل، فالجزء 110 بعملة ريال يعطي 11010001 وبعملة دولار يعطي 11020001.
     * الصيغة القديمة كانت TRUNC(ACC_NO/10000) التي تعطي 4 أرقام فلا تصل أبداً لأكبر
     * رقم داخل الجزء، وكانت العملة نفسها تُجهل فتقطف أعلى عملة ترتيباً.
     * Numbering rule: ACC_NO = accPart(3) + CUR_NO(1) + sequence. The old
     * TRUNC(ACC_NO/10000) filter produced a 4-digit prefix that never matched a
     * 3-digit part, so MAX() came back null and every insert collided with the first slot.
     *
     * الحقول المدعومة = أعمدة CHART_ACC الموجودة فقط (لا أعمدة جديدة في قاعدة الغير لنا):
     * العملة ليست عموداً هنا بل تُرمَّز داخل الرقم. Body: acc_name, cur_no, group_id |
     * acc_part, balance, limited_balance, stopping_acc, seq, acc_date.
     */
    public function createChartAccount(Request $request)
    {
        try {
            $data = $request->validate([
                'acc_name' => 'required|string|max:150',
                'cur_no' => 'required|integer|min:1|max:9',
                'group_id' => 'nullable|integer',
                'acc_part' => 'nullable|integer',
                'balance' => 'nullable|numeric',
                'limited_balance' => 'nullable|numeric',
                'stopping_acc' => 'nullable|integer|in:0,1',
                'seq' => 'nullable|integer|min:0',
                'acc_date' => 'nullable|string',
            ]);

            $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (int) $data['group_id'] : null;
            $accPart = null;

            if ($groupId !== null) {
                $groupRow = DB::connection('oracle')->selectOne(
                    'SELECT ACC_PART FROM ACC_SUB_GROUP WHERE GROUP_ID = :gid',
                    ['gid' => $groupId]
                );
                if (! $groupRow) {
                    return response()->json(['success' => false, 'message' => 'المجموعة غير موجودة'], 404);
                }
                $groupArr = (array) $groupRow;
                $accPart = (int) ($groupArr['ACC_PART'] ?? $groupArr['acc_part']);
            } else {
                if (empty($data['acc_part'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'يجب تحديد مجموعة الحساب (group_id) أو الحساب الجزئي (acc_part)',
                    ], 422);
                }
                $accPart = (int) $data['acc_part'];
                $partRow = DB::connection('oracle')->selectOne(
                    'SELECT ACC_PART FROM ACC_SUB WHERE ACC_PART = :p',
                    ['p' => $accPart]
                );
                if (! $partRow) {
                    return response()->json(['success' => false, 'message' => 'الحساب الجزئي غير موجود'], 404);
                }
                // المجموعة غير مُعرَّفة أو صفر → حساب مباشر تحت الجزئي بـ GROUP_ID NULL
                $groupId = null;
            }

            // العملة جزء من الرقم: يجب أن تكون خانة واحدة ومعروفة في CURRENCY، وإلا وُلد
            // حساب برقم لا يطابق أي عملة في الدليل.
            // The currency becomes a digit of the number, so it must be a real CURRENCY row.
            $curNo = (int) $data['cur_no'];
            $curRow = DB::connection('oracle')->selectOne(
                'SELECT CUR_NO FROM CURRENCY WHERE CUR_NO = :c',
                ['c' => $curNo]
            );
            if (! $curRow) {
                return response()->json([
                    'success' => false,
                    'message' => 'العملة '.$curNo.' غير معروفة في دليل العملات',
                ], 422);
            }

            // منع تكرار الاسم: CHART_ACC_BRW_U1 يفهرس ACC_NAME — الاستعلام أسبق من الخطأ
            // Duplicate names are indexed by CHART_ACC_BRW_U1, so check before inserting.
            $oracleName = ArabicEncoding::toOracleSearchChars($data['acc_name']);
            $dupe = DB::connection('oracle')->selectOne(
                'SELECT ACC_NO FROM CHART_ACC WHERE ACC_NAME = :name OR UPPER(ACC_NAME) = UPPER(:name2)',
                ['name' => $oracleName, 'name2' => $data['acc_name']]
            );
            if ($dupe) {
                $dupeArr = (array) $dupe;

                return response()->json([
                    'success' => false,
                    'message' => 'يوجد حساب بنفس الاسم: '.(int) ($dupeArr['ACC_NO'] ?? $dupeArr['acc_no'] ?? 0),
                ], 422);
            }

            // ترقيم داخل نطاق العملة المختارة وحدها (نفس القاعدة في المعاينة أدناه)
            // Numbering stays inside the chosen currency's band — same helper the preview uses.
            $band = $this->nextChartAccountNumber($accPart, $curNo);
            if ($band['number'] === null) {
                return response()->json(['success' => false, 'message' => $band['error']], 422);
            }
            $nextNo = $band['number'];

            $accDate = $this->normaliseOptionalDate($data['acc_date'] ?? null);
            $balance = (float) ($data['balance'] ?? 0);
            $limited = isset($data['limited_balance']) && $data['limited_balance'] !== '' ? (float) $data['limited_balance'] : null;
            $stopping = isset($data['stopping_acc']) && $data['stopping_acc'] !== '' ? (int) $data['stopping_acc'] : null;
            $seq = isset($data['seq']) && $data['seq'] !== '' ? (int) $data['seq'] : null;

            $sql = 'INSERT INTO CHART_ACC (ACC_NO, ACC_NAME, BALANCE, ACC_DATE, SEQ, GROUP_ID, LIMITED_BALANCE, STOPPING_ACC)
                 VALUES (:acc_no, :acc_name, :balance, '
                .($accDate !== null ? "TO_DATE(:acc_date, 'YYYY-MM-DD')" : 'SYSDATE')
                .', :seq, :group_id, :limited, :stopping)';

            $binds = [
                'acc_no' => $nextNo,
                'acc_name' => $oracleName,
                'balance' => $balance,
                'seq' => $seq,
                'group_id' => $groupId,
                'limited' => $limited,
                'stopping' => $stopping,
            ];
            // لا تُمرَّر واسطة غير موجودة في نص SQL وإلا رفضت Oracle الربط
            // Never bind a placeholder that is absent from the SQL text (ORA-01036).
            if ($accDate !== null) {
                $binds['acc_date'] = $accDate;
            }

            DB::connection('oracle')->insert($sql, $binds);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء الحساب بنجاح',
                'data' => [
                    'acc_no' => $nextNo,
                    'acc_name' => $data['acc_name'],
                    'balance' => $balance,
                    // العملة تُقرأ من الرقم نفسه — تُعاد في الرد ليعرضها النموذج فور الإنشاء
                    'cur_no' => $this->currencyOfChartNumber($nextNo),
                    'acc_date' => $accDate ?? date('Y-m-d'),
                    'seq' => $seq,
                    'group_id' => $groupId,
                    'acc_part' => $accPart,
                    'limited_balance' => $limited,
                    'stopping_acc' => $stopping,
                ],
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
                $oracleName = ArabicEncoding::toOracleSearchChars($request->input('acc_name'));
                // تكرار الاسم مرفوض ما دام حساباً آخر هو صاحب الاسم
                // Reject a name owned by a different account (CHART_ACC_BRW_U1).
                $dupe = DB::connection('oracle')->selectOne(
                    'SELECT ACC_NO FROM CHART_ACC WHERE ACC_NO <> :self AND (ACC_NAME = :name OR UPPER(ACC_NAME) = UPPER(:name2))',
                    ['self' => $accNo, 'name' => $oracleName, 'name2' => $request->input('acc_name')]
                );
                if ($dupe) {
                    $dupeArr = (array) $dupe;

                    return response()->json([
                        'success' => false,
                        'message' => 'يوجد حساب بنفس الاسم: '.(int) ($dupeArr['ACC_NO'] ?? $dupeArr['acc_no'] ?? 0),
                    ], 422);
                }
                $fields[] = 'ACC_NAME = :acc_name';
                $params['acc_name'] = $oracleName;
            }
            if ($request->has('balance')) {
                $fields[] = 'BALANCE = :balance';
                $params['balance'] = $request->input('balance');
            }
            if ($request->has('seq')) {
                $fields[] = 'SEQ = :seq';
                $params['seq'] = (int) $request->input('seq');
            }
            if ($request->has('acc_date') && $request->input('acc_date') !== null && $request->input('acc_date') !== '') {
                $accDate = $this->normaliseOptionalDate($request->input('acc_date'));
                if ($accDate !== null) {
                    $fields[] = "ACC_DATE = TO_DATE(:acc_date, 'YYYY-MM-DD')";
                    $params['acc_date'] = $accDate;
                }
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
     * كشف حساب مالي واحد (دفتر حركات حساب CHART_ACC مفرد)
     * مطابق لقالب تقرير أوراكل السابق acc_report.RDF/acc_report.pdf
     *
     * مصدر البيانات الحقيقي: ENTRY_MAIN + ENTRY_SUB + ENTRY_TYPE
     * ملاحظة: جدول ACC_TRXN غير موجود فعلياً في مخطط ACCOUNTS -
     * كان الاستخدام السابق له يُنتج كشف حساب فارغ بصمت دون خطأ.
     * This is the same proven data source used by CustomerController::statement,
     * joined directly on ENTRY_SUB.ACC_NO (a CHART_ACC's own ledger line needs no
     * GIVING/party join, unlike the customer case).
     *
     * Query params: from_date (DD/MM/YYYY or YYYY-MM-DD), to_date, page, limit
     * Default period when both dates are absent: the 30 days preceding the account's
     * last transaction date (confirmed requirement).
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

            $page = max(1, (int) $request->query('page', 1));
            $limit = min(200, max(10, (int) $request->query('limit', 50)));
            $offset = ($page - 1) * $limit;
            $maxRow = $offset + $limit;

            // ── جلب كامل دفتر حركات الحساب مرتباً زمنياً (يُصفّى بالفترة في PHP
            //    لتسهيل حساب الرصيد الافتتاحي، بنفس نمط CustomerController::statement) ──
            $allSql = '
                SELECT es.ENTRY_NO,
                       SUBSTR(TO_CHAR(es.ENTRY_NO), 1, 1) || SUBSTR(TO_CHAR(es.ENTRY_NO), 7, 4) AS SUB_ENTRY_NO,
                       TO_CHAR(em.ENTRY_DATE, \'DD/MM/YYYY\') AS ENTRY_DATE,
                       TO_CHAR(em.ENTRY_DATE, \'YYYY-MM-DD\') AS RAW_DATE,
                       et.ENTRY_TYPE_NAME,
                       NVL(es.SUB_DETAILS, em.DETAILS) AS DETAILS,
                       es.BALANCE_S,
                       es.ENTRY_MNT,
                       es.SEQ_PRICE
                FROM ENTRY_MAIN em, ENTRY_SUB es, ENTRY_TYPE et
                WHERE es.ACC_NO = :acc_no
                  AND es.ENTRY_NO = em.ENTRY_NO
                  AND em.ENTRY_TYPE_NO = et.ENTRY_TYPE_NO
                ORDER BY em.ENTRY_DATE, es.ENTRY_NO, es.SEQ
            ';
            $rows = DB::connection('oracle')->select($allSql, ['acc_no' => $accNo]);

            // ── الفترة الافتراضية: آخر 30 يوم قبل تاريخ آخر حركة ──
            $defaulted = false;
            $fromDateParam = $request->query('from_date');
            $toDateParam = $request->query('to_date');

            if (! $fromDateParam && ! $toDateParam && ! empty($rows)) {
                $lastRawDate = null;
                foreach ($rows as $lastRow) {
                    $lr = (array) $lastRow;
                    $lastRawDate = $lr['RAW_DATE'] ?? $lr['raw_date'] ?? $lastRawDate;
                }
                if ($lastRawDate) {
                    try {
                        $lastDate = Carbon::parse($lastRawDate);
                        // 'Y-m-d' وليس 'YYYY-MM-DD': في PHP كل Y تُكرّر السنة، وM اسم
                        // الشهر وD اسم اليوم — فتنتج سلسلة مشوهة تكسر منتقي التاريخ
                        // على العميل (سنة 93422). صيغة ISO الصحيحة هي Y-m-d فقط.
                        // Use 'Y-m-d', never 'YYYY-MM-DD': repeated format
                        // letters produce garbage strings that crash the client.
                        $fromDateParam = $lastDate->copy()->subDays(30)->format('Y-m-d');
                        $toDateParam = $lastDate->format('Y-m-d');
                        $defaulted = true;
                    } catch (\Throwable) {
                        $fromDateParam = null;
                        $toDateParam = null;
                    }
                }
            }

            $fromDateFilter = $fromDateParam ? $this->parseStatementDateToIso($fromDateParam) : null;
            $toDateFilter = $toDateParam ? $this->parseStatementDateToIso($toDateParam) : null;

            $openingBalance = 0.0;
            $runningBalance = 0.0;
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $firstDate = null;
            $lastDate = null;
            $allEntries = []; // كل الحركات المفلترة بالمدة (قبل الترقيم)

            foreach ($rows as $row) {
                $r = (array) $row;
                $rawDate = $r['RAW_DATE'] ?? $r['raw_date'] ?? null;
                $entryDate = $r['ENTRY_DATE'] ?? $r['entry_date'] ?? null;
                $mnt = (float) ($r['ENTRY_MNT'] ?? $r['entry_mnt'] ?? 0);
                $bal = (float) ($r['BALANCE_S'] ?? $r['balance_s'] ?? 0);

                // الحركات الأقدم من بداية الفترة تُحسب ضمن الرصيد الافتتاحي فقط
                if ($fromDateFilter && $rawDate && $rawDate < $fromDateFilter) {
                    $openingBalance = $bal;
                    $runningBalance = $bal;

                    continue;
                }

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

                $allEntries[] = [
                    'entry_no' => (int) ($r['ENTRY_NO'] ?? $r['entry_no'] ?? 0),
                    'sub_entry_no' => (string) ($r['SUB_ENTRY_NO'] ?? $r['sub_entry_no'] ?? ''),
                    'date' => $entryDate,
                    'entry_type_name' => ArabicEncoding::fixMojibake($r['ENTRY_TYPE_NAME'] ?? $r['entry_type_name'] ?? ''),
                    'description' => ArabicEncoding::fixMojibake(trim((string) ($r['DETAILS'] ?? $r['details'] ?? ''))),
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => $bal,
                ];
            }

            // ── الترقيم اليدوي (pagination) بعد الانتهاء من حساب الرصيد الافتتاحي ──
            $total = count($allEntries);
            $transactions = array_slice($allEntries, $offset, $limit);

            $finalBalance = $total > 0 ? $runningBalance : $openingBalance;

            // ── رمز/اسم العملة من آخر حركة (SEQ_PRICE -> CUR_PRICE -> CUR_CODE) ──
            $currency = 'يمني YR';
            try {
                $lastPriceSeq = null;
                foreach ($rows as $row) {
                    $r = (array) $row;
                    $sp = $r['SEQ_PRICE'] ?? $r['seq_price'] ?? null;
                    if ($sp) {
                        $lastPriceSeq = $sp;
                    }
                }
                if ($lastPriceSeq) {
                    $curRow = DB::connection('oracle')->selectOne(
                        'SELECT cc.CODE, c.CUR_NAME FROM CUR_PRICE cp
                         JOIN CURRENCY c ON c.CUR_NO = cp.CUR_NO
                         JOIN CUR_CODE cc ON cc.CUR_NO = cp.CUR_NO
                         WHERE cp.SEQ = :seq',
                        ['seq' => $lastPriceSeq]
                    );
                    if ($curRow) {
                        $cr = (array) $curRow;
                        $curName = ArabicEncoding::fixMojibake($cr['CUR_NAME'] ?? $cr['cur_name'] ?? '');
                        $curCode = $cr['CODE'] ?? $cr['code'] ?? '';
                        if ($curName !== '' || $curCode !== '') {
                            $currency = trim($curName.' '.$curCode);
                        }
                    }
                }
            } catch (\Exception) {
                // جدول/ربط العملة غير متاح - نبقى على القيمة الافتراضية
            }

            $accName = ArabicEncoding::fixMojibake($accArr['ACC_NAME'] ?? $accArr['acc_name'] ?? '');

            return response()->json([
                'success' => true,
                'data' => [
                    'account' => [
                        'acc_no' => (int) ($accArr['ACC_NO'] ?? $accArr['acc_no'] ?? $accNo),
                        'acc_name' => $accName,
                        'balance' => (float) ($accArr['BALANCE'] ?? $accArr['balance'] ?? 0),
                        'group_id' => isset($accArr['GROUP_ID']) || isset($accArr['group_id']) ? (int) ($accArr['GROUP_ID'] ?? $accArr['group_id']) : null,
                    ],
                    'transactions' => $transactions,
                    'summary' => [
                        'opening_balance' => $openingBalance,
                        'final_balance' => $finalBalance,
                        'total_debit' => $totalDebit,
                        'total_credit' => $totalCredit,
                        'current_balance' => $finalBalance,
                        'total_records' => $total,
                        'currency' => $currency,
                        'from_date' => $fromDateParam ?: ($firstDate ?? ''),
                        'to_date' => $toDateParam ?: ($lastDate ?? ''),
                        'defaulted' => $defaulted,
                    ],
                ],
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
     * تحويل نص تاريخ (DD/MM/YYYY أو YYYY-MM-DD أو YYYY/MM/DD) إلى YYYY-MM-DD
     * Date-string normalizer used by both statement endpoints.
     */
    private function parseStatementDateToIso(string $dateStr): ?string
    {
        $d = trim($dateStr);
        if ($d === '') {
            return null;
        }

        try {
            $normalized = str_replace('/', '-', $d);
            $parts = explode('-', $normalized);
            if (count($parts) === 3) {
                $year = null;
                if (strlen($parts[0]) === 4) {
                    // YYYY-MM-DD or YYYY/MM/DD
                    $year = (int) $parts[0];
                    $month = (int) $parts[1];
                    $day = (int) $parts[2];
                } else {
                    // DD-MM-YYYY
                    $year = (int) $parts[2];
                    $month = (int) $parts[1];
                    $day = (int) $parts[0];
                }

                // حماية من التواريخ المشوهة (سنة 93422 من عميل قديم، أو 0025 من
                // صفوف Oracle فاسدة): نرفض أي سنة/شهر/يوم خارج النطاق المنطقي.
                // Reject absurd dates (year 93422, corrupt year 0025 rows) so
                // garbage never reaches a filter comparison or the client UI.
                if ($year < 1900 || $year > 2100 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
                    return null;
                }

                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return Carbon::parse($d)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
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

            // قاعدة ترقيم الصفحة نفسها في كل المستويات: أرقام الحسابات ثم الأرصدة
            // The same rule for every level: resolve account numbers, then balances.
            $accNos = [];
            $rawRows = [];

            switch ($type) {
                case 'account':
                    // الرابطة الصحيحة: أول 3 أرقام من ACC_NO = ACC_PART. صيغة
                    // TRUNC(ACC_NO/10000) كانت تعطي 4 أرقام فلا تطابق جزئياً أبداً.
                    // The correct join is the 3-digit prefix; TRUNC/10000 yields 4 digits
                    // and never matched a 3-digit ACC_PART, so the section came back empty.
                    $rows = $id
                        ? DB::connection('oracle')->select('SELECT c.ACC_NO, c.ACC_NAME, c.BALANCE FROM CHART_ACC c
                                     INNER JOIN ACC_SUB s ON SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) = TO_CHAR(s.ACC_PART)
                                     INNER JOIN ACC_MAIN m ON s.ACC_SUB = m.ACC_SUB
                                     WHERE m.ACC_NO = :id ORDER BY c.ACC_NO', ['id' => $id])
                        : DB::connection('oracle')->select(
                            'SELECT * FROM (
                                SELECT a.*, ROWNUM rnum FROM (
                                    SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC ORDER BY ACC_NO
                                ) a
                                WHERE ROWNUM <= :max_row
                            )
                            WHERE rnum > :offset',
                            ['max_row' => $maxRow, 'offset' => $offset]
                        );
                    break;

                case 'main':
                    $rows = DB::connection('oracle')->select(
                        'SELECT c.ACC_NO, c.ACC_NAME, c.BALANCE FROM CHART_ACC c
                         INNER JOIN ACC_SUB s ON SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) = TO_CHAR(s.ACC_PART)
                         WHERE s.ACC_SUB = :id ORDER BY c.ACC_NO',
                        ['id' => $id]
                    );
                    break;

                case 'sub':
                    $rows = DB::connection('oracle')->select(
                        'SELECT ACC_NO, ACC_NAME, BALANCE FROM CHART_ACC c
                         WHERE SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) = TO_CHAR(:id) ORDER BY c.ACC_NO',
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
                $accNo = (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0);
                $accNos[] = $accNo;
                $rawRows[] = [
                    'acc_no' => $accNo,
                    'acc_name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'stored_balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                ];
            }

            // الرصيد المعروض يُحسب من الحركات ولا يعتمد على عمود BALANCE المخزّن،
            // لأن النظام التراثي يبقيه صفراً في أغلب الحسابات.
            // Balances come from ENTRY_SUB movements: the stored BALANCE column is
            // stale in the legacy book, so it is only carried for comparison.
            $fromIso = $this->normaliseOptionalDate($fromDate);
            $toIso = $this->normaliseOptionalDate($toDate);
            $buckets = $this->movementBalancesFor($accNos, $fromIso, $toIso);

            foreach ($rawRows as $i => $row) {
                $b = $buckets[$row['acc_no']] ?? $this->emptyBucket();
                $totalDebit += $b['debit'];
                $totalCredit += $b['credit'];
                $balance += $b['net'];
                $accounts[] = [
                    'acc_no' => $row['acc_no'],
                    'acc_name' => $row['acc_name'],
                    'debit' => $b['debit'],
                    'credit' => $b['credit'],
                    'balance' => $b['net'],
                    'movements' => $b['movements'],
                    'stored_balance' => $row['stored_balance'],
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
                        'from_date' => $fromIso,
                        'to_date' => $toIso,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================
    // ===     كشف حساب مجموعة حسابات جزئية (ميزان مراجعة)  ===
    // =========================================================

    /**
     * GET /accounts/group-statement
     * كشف حساب مجموعة حسابات جزئية - بأسلوب ميزان المراجعة
     * مطابق لتقرير اوراكل السابق TRIAL_BALANCE_REPORT.RDF/TRIAL_BALANCE_REPORT.pdf
     *
     * يحدد النطاق عبر اختيار متسلسل رباعي المستويات:
     * حساب رئيسي (ACCOUNT.ACC_NO) -> حساب فرعي (ACC_MAIN.ACC_SUB) ->
     * حساب جزئي (ACC_SUB.ACC_PART) -> مجموعات الحساب الجزئي (ACC_SUB_GROUP.GROUP_ID)
     * وكلها اختيارية حسب قاعدة الحل أدناه.
     *
     * يعرض لكل حساب مالي (CHART_ACC) داخل النطاق:
     * مدين/دائن مفصولين إلى عملة محلية (CUR_NO = 1 = يمني) وعملة أجنبية،
     * مع الرصيد. إن حُددت فترة زمنية تُحسب الإحصائيات من حركات الفترة فقط،
     * وإلا تُحسب من كامل السجل التاريخي للحساب.
     *
     * Query params: acc_no, acc_sub, acc_part, group_id, from_date, to_date
     */
    public function getGroupStatement(Request $request)
    {
        try {
            $accNo = $request->query('acc_no');
            $accSub = $request->query('acc_sub');
            $accPart = $request->query('acc_part');
            $groupId = $request->query('group_id');
            $fromDateParam = $request->query('from_date');
            $toDateParam = $request->query('to_date');

            // ── حلّ النطاق (Scope Resolution): أعمق مستوى مختار يفوز ──
            $scopeWhere = '';
            $scopeParams = [];
            $scopeLevel = '';
            $scopeName = '';

            if ($groupId) {
                $scopeWhere = 'c.GROUP_ID = :group_id';
                $scopeParams['group_id'] = $groupId;
                $scopeLevel = 'group';
                $gRow = DB::connection('oracle')->selectOne(
                    'SELECT ACC_GROUP_NAME FROM ACC_SUB_GROUP WHERE GROUP_ID = :id',
                    ['id' => $groupId]
                );
                $scopeName = $gRow ? ArabicEncoding::fixMojibake(((array) $gRow)['ACC_GROUP_NAME'] ?? ((array) $gRow)['acc_group_name'] ?? '') : '';
            } elseif ($accPart) {
                // الحساب الجزئي هو أول 3 أرقام من رقم الحساب الشجري (ACC_NO)، بغض
                // النظر عن طول الرقم (8 أو 9 أرقام). صيغة TRUNC/10000 السابقة كانت
                // تُعطي بادئة من 4 أرقام فلا تطابق أبداً معرّف الجزء ثلاثي الأرقام،
                // ما كان يُرجع نتيجة فارغة عند اختيار حساب جزئي بدون مجموعة.
                $scopeWhere = 'SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) = TO_CHAR(:acc_part)';
                $scopeParams['acc_part'] = $accPart;
                $scopeLevel = 'sub';
                $pRow = DB::connection('oracle')->selectOne(
                    'SELECT PART_NAME FROM ACC_SUB WHERE ACC_PART = :id',
                    ['id' => $accPart]
                );
                $scopeName = $pRow ? ArabicEncoding::fixMojibake(((array) $pRow)['PART_NAME'] ?? ((array) $pRow)['part_name'] ?? '') : '';
            } elseif ($accSub) {
                $scopeWhere = 'SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) IN (SELECT TO_CHAR(ACC_PART) FROM ACC_SUB WHERE ACC_SUB = :acc_sub)';
                $scopeParams['acc_sub'] = $accSub;
                $scopeLevel = 'main';
                $mRow = DB::connection('oracle')->selectOne(
                    'SELECT SUB_NAME FROM ACC_MAIN WHERE ACC_SUB = :id',
                    ['id' => $accSub]
                );
                $scopeName = $mRow ? ArabicEncoding::fixMojibake(((array) $mRow)['SUB_NAME'] ?? ((array) $mRow)['sub_name'] ?? '') : '';
            } elseif ($accNo) {
                $scopeWhere = 'SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) IN (
                    SELECT TO_CHAR(s.ACC_PART) FROM ACC_SUB s
                    INNER JOIN ACC_MAIN m ON s.ACC_SUB = m.ACC_SUB
                    WHERE m.ACC_NO = :acc_no
                )';
                $scopeParams['acc_no'] = $accNo;
                $scopeLevel = 'account';
                $aRow = DB::connection('oracle')->selectOne(
                    'SELECT ACC_NAME FROM ACCOUNT WHERE ACC_NO = :id',
                    ['id' => $accNo]
                );
                $scopeName = $aRow ? ArabicEncoding::fixMojibake(((array) $aRow)['ACC_NAME'] ?? ((array) $aRow)['acc_name'] ?? '') : '';
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب اختيار حساب رئيسي على الأقل لتحديد نطاق التقرير / Please select at least a main account to define the report scope.',
                ], 400);
            }

            // ── فترة اختيارية، تُبنى ديناميكياً داخل شرط الربط (وليس في WHERE)
            //    حتى تبقى الحسابات بدون حركات في الفترة ظاهرة بأصفار ──
            $fromIso = $fromDateParam ? $this->parseStatementDateToIso($fromDateParam) : null;
            $toIso = $toDateParam ? $this->parseStatementDateToIso($toDateParam) : null;

            $emDateCondition = '';
            if ($fromIso) {
                $emDateCondition .= " AND em.ENTRY_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')";
                $scopeParams['from_date'] = $fromIso;
            }
            if ($toIso) {
                $emDateCondition .= " AND em.ENTRY_DATE <= TO_DATE(:to_date, 'YYYY-MM-DD')";
                $scopeParams['to_date'] = $toIso;
            }

            // ملاحظة جوهرية تم التحقق منها من قاعدة البيانات: حركات العملة المحلية (يمني)
            // لا تحتوي على SEQ_PRICE على الإطلاق (يبقي NULL)، بينما حركات العملات الأجنبية
            // فقط هي من تحمل SEQ_PRICE مرتبط بصف في CUR_PRICE (CUR_NO = 2 أو 3).
            // Critical finding verified against live data: a movement's SEQ_PRICE is only
            // populated for FOREIGN currency entries (CUR_NO 2/3); local YR entries always
            // leave it NULL (no FX price row needed). So "no linked CUR_PRICE row" means
            // local currency, NOT unknown/foreign — the opposite of the initial assumption.
            //
            // يُربط ENTRY_SUB مع ENTRY_MAIN داخل مجموعة اقواس (joined table) لضمان ان
            // فلتر التاريخ يؤثر على es نفسه وليس على em فقط (كان الخطأ السابق ان
            // فلتر التاريخ داخل ON الخاص ب em وحده كان يُبقي صفوف es خارج الفترة
            // داخلة في المجاميع رغم استبعاد حقول em).
            $esJoin = "ENTRY_SUB es INNER JOIN ENTRY_MAIN em ON es.ENTRY_NO = em.ENTRY_NO{$emDateCondition}";

            $sql = "
                SELECT c.ACC_NO, c.ACC_NAME, c.BALANCE,
                       NVL(SUM(CASE WHEN (cp.CUR_NO IS NULL OR cp.CUR_NO = 1) AND es.ENTRY_MNT > 0 THEN es.ENTRY_MNT      ELSE 0 END), 0) AS LOCAL_DEBIT,
                       NVL(SUM(CASE WHEN (cp.CUR_NO IS NULL OR cp.CUR_NO = 1) AND es.ENTRY_MNT < 0 THEN ABS(es.ENTRY_MNT) ELSE 0 END), 0) AS LOCAL_CREDIT,
                       NVL(SUM(CASE WHEN cp.CUR_NO IS NOT NULL AND cp.CUR_NO <> 1 AND es.ENTRY_MNT > 0 THEN es.ENTRY_MNT      ELSE 0 END), 0) AS FOREIGN_DEBIT,
                       NVL(SUM(CASE WHEN cp.CUR_NO IS NOT NULL AND cp.CUR_NO <> 1 AND es.ENTRY_MNT < 0 THEN ABS(es.ENTRY_MNT) ELSE 0 END), 0) AS FOREIGN_CREDIT,
                       NVL(SUM(CASE WHEN cp.CUR_NO IS NULL OR cp.CUR_NO = 1 THEN es.ENTRY_MNT ELSE 0 END), 0) AS LOCAL_NET,
                       NVL(SUM(CASE WHEN cp.CUR_NO IS NOT NULL AND cp.CUR_NO <> 1 THEN es.ENTRY_MNT ELSE 0 END), 0) AS FOREIGN_NET,
                       MAX(CASE WHEN cp.CUR_NO IS NOT NULL AND cp.CUR_NO <> 1 THEN cc.CODE END) AS FOREIGN_CUR_CODE
                FROM CHART_ACC c
                LEFT JOIN ({$esJoin}) ON es.ACC_NO = c.ACC_NO
                LEFT JOIN CUR_PRICE cp ON cp.SEQ = es.SEQ_PRICE
                LEFT JOIN CUR_CODE cc ON cc.CUR_NO = cp.CUR_NO
                WHERE {$scopeWhere}
                GROUP BY c.ACC_NO, c.ACC_NAME, c.BALANCE
                ORDER BY c.ACC_NO
            ";

            $rows = DB::connection('oracle')->select($sql, $scopeParams);

            $result = [];
            $totals = [
                'local_debit' => 0.0,
                'local_credit' => 0.0,
                'foreign_debit' => 0.0,
                'foreign_credit' => 0.0,
                'balance' => 0.0,
            ];

            foreach ($rows as $row) {
                $r = (array) $row;
                $localDebit = (float) ($r['LOCAL_DEBIT'] ?? $r['local_debit'] ?? 0);
                $localCredit = (float) ($r['LOCAL_CREDIT'] ?? $r['local_credit'] ?? 0);
                $foreignDebit = (float) ($r['FOREIGN_DEBIT'] ?? $r['foreign_debit'] ?? 0);
                $foreignCredit = (float) ($r['FOREIGN_CREDIT'] ?? $r['foreign_credit'] ?? 0);
                $localNet = (float) ($r['LOCAL_NET'] ?? $r['local_net'] ?? 0);
                $foreignNet = (float) ($r['FOREIGN_NET'] ?? $r['foreign_net'] ?? 0);
                $balance = $localNet + $foreignNet;

                // رمز العملة المعروض للسطر: أجنبي إن وُجدت حركة أجنبية وحدها،
                // وإلا يمني (العملة المحلية الافتراضية للمؤسسة)
                $hasForeign = $foreignDebit != 0 || $foreignCredit != 0 || $foreignNet != 0;
                $hasLocal = $localDebit != 0 || $localCredit != 0 || $localNet != 0;
                $curCode = ($hasForeign && ! $hasLocal)
                    ? ($r['FOREIGN_CUR_CODE'] ?? $r['foreign_cur_code'] ?? '')
                    : 'YR';

                $totals['local_debit'] += $localDebit;
                $totals['local_credit'] += $localCredit;
                $totals['foreign_debit'] += $foreignDebit;
                $totals['foreign_credit'] += $foreignCredit;
                $totals['balance'] += $balance;

                $result[] = [
                    'acc_no' => (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0),
                    'acc_name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'cur_code' => $curCode ?: 'YR',
                    'local_debit' => $localDebit,
                    'local_credit' => $localCredit,
                    'foreign_debit' => $foreignDebit,
                    'foreign_credit' => $foreignCredit,
                    'balance' => $balance,
                    'current_balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                ];
            }

            $total = count($result);

            return response()->json([
                'success' => true,
                'data' => [
                    'scope_level' => $scopeLevel,
                    'scope_label' => $scopeName,
                    'as_of_date' => date('d/m/Y'),
                    'from_date' => $fromDateParam ?: null,
                    'to_date' => $toDateParam ?: null,
                    'rows' => $result,
                    'totals' => $totals,
                ],
                'meta' => [
                    'total' => $total,
                    'page' => 1,
                    'limit' => $total,
                    'total_pages' => 1,
                    'has_more' => false,
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
     * هيكل الشجرة المحاسبية فقط (الأقسام → الفروع → الجزئية → المجموعات) مع أرصدة
     * مجمّعة من حركات ENTRY_SUB. الحسابات المالية (CHART_ACC) تُجلب عند التوسيع عبر
     * GET /accounts/tree/children بترقيم صفحات.
     *
     * Why the rewrite: the previous version walked every CHART_ACC row inside nested
     * loops (≈140 queries + 20,954 nodes in one payload) and dropped any parent whose
     * children list came back empty, so whole sections silently disappeared. It also
     * read the BALANCE columns of ACCOUNT/ACC_MAIN/ACC_SUB, which the legacy ERP keeps
     * at zero — so no parent ever showed the sum of its children.
     *
     * القواعد المعتمدة هنا:
     *  1) بادئة الحساب المالي = أول 3 أرقام من ACC_NO وتساوي ACC_PART (وليس TRUNC/10000
     *     الذي يعطي 4 أرقام ولا يطابق أبداً معرف الجزء ثلاثي الأرقام).
     *  2) تجميع الأرصدة يتم من ENTRY_SUB حصراً، للأبناء المخفيين أيضاً.
     *  3) حسابات العملاء (الجزء 123) مستثناة من العرض فقط — يُعرض «عملاء الكبودي
     *     للخياطة» 12310000 — لكنها تدخل في التجميع.
     *
     * Query params: from_date, to_date (اختياري، YYYY-MM-DD أو DD/MM/YYYY).
     */
    public function getAccountTree(Request $request)
    {
        try {
            $fromIso = $this->normaliseOptionalDate($request->query('from_date'));
            $toIso = $this->normaliseOptionalDate($request->query('to_date'));

            // ── 1) الصفوف الهيكلية: استعلام واحد لكل مستوى، بترتيب حتمي بالرقم ──
            $accounts = $this->structureRows('ACCOUNT', 'ACC_NO, ACC_NAME, BALANCE, CUR_NO', 'ACC_NO');
            $mains = $this->structureRows('ACC_MAIN', 'ACC_NO, ACC_SUB, SUB_NAME, BALANCE, CUR_NO', 'ACC_NO, ACC_SUB');
            $subs = $this->structureRows('ACC_SUB', 'ACC_SUB, ACC_PART, PART_NAME, BALANCE, CUR_NO', 'ACC_SUB, ACC_PART');
            $groups = $this->structureRows('ACC_SUB_GROUP', 'GROUP_ID, ACC_PART, ACC_GROUP_NAME', 'ACC_PART, GROUP_ID');

            // ── 2) الأرصدة من الحركات + خريطة مجموعة كل حساب + عدادات الحسابات ──
            $chartBal = $this->movementBalancesByChart($fromIso, $toIso);
            $groupOfChart = $this->chartGroupMap();
            $counts = $this->chartCountBuckets();

            // تجميع لأسفل-أعلى: جزء ← فرعي ← قسم (نفس ترقية التصدير، لا نسختين)
            // Bottom-up roll-up: part → sub → section. Shared with the export endpoint
            // so a printed tree and the on-screen tree can never drift apart.
            $roll = $this->rollUpBalances($chartBal, $groupOfChart, $subs, $mains);
            $partBal = $roll['part'];
            $groupBal = $roll['group'];
            $subBal = $roll['sub'];
            $accBal = $roll['account'];

            // ── 3) بناء العقد: كل عقدة تظهر دائماً (حتى الفارغة) ──
            $tree = [];
            foreach ($accounts as $a) {
                $accNo = (int) $a['acc_no'];
                $accPathCode = (string) $accNo;

                $mainNodes = [];
                foreach ($mains as $m) {
                    if ((int) $m['acc_no'] !== $accNo) {
                        continue;
                    }
                    $accSub = (int) $m['acc_sub'];
                    $subPathCode = $accPathCode.' / '.$accSub;

                    $subNodes = [];
                    foreach ($subs as $s) {
                        if ((int) $s['acc_sub'] !== $accSub) {
                            continue;
                        }
                        $accPart = (int) $s['acc_part'];
                        $partPathCode = $subPathCode.' / '.$accPart;

                        $partAccounts = $counts['part'][$accPart] ?? 0;
                        $partChildren = [];
                        $directCount = $counts['direct'][$accPart] ?? 0;

                        if ($accPart === self::CUSTOMERS_ACC_PART) {
                            // استثناء العرض: مجموعة وحسابات العملاء مخفية، ويُضاف حساب
                            // الكبودي المفرد مباشرة كي يظهر في الهيكل بلا طلب إضافي.
                            // Display-only exclusion: the customer group and its 10k+
                            // accounts stay hidden, the single Koboudi account is
                            // emitted as an inline leaf so it needs no lazy fetch.
                            $koboudi = $this->chartLeafNode(self::KOBOUDI_CUSTOMER_ACC_NO, $chartBal, $partPathCode, $a, $m, $s, null);
                            if ($koboudi !== null) {
                                $partChildren[] = $koboudi;
                            }
                            $directCount = 0;
                        } else {
                            foreach ($groups as $g) {
                                if ((int) $g['acc_part'] !== $accPart) {
                                    continue;
                                }
                                $gid = (int) $g['group_id'];
                                $gb = $groupBal[$gid] ?? $this->emptyBucket();
                                $gCount = $counts['group'][$gid] ?? 0;
                                $partChildren[] = [
                                    'id' => 'group_'.$gid,
                                    'level' => 'group',
                                    'group_id' => $gid,
                                    'acc_no' => $accNo,
                                    'acc_sub' => $accSub,
                                    'acc_part' => $accPart,
                                    'name' => $g['acc_group_name'],
                                    'parent_path' => $this->pathName([$a['acc_name'], $m['sub_name'], $s['part_name']]),
                                    'code_path' => $partPathCode.' / G'.$gid,
                                    'stored_balance' => null,
                                    'cur_no' => null,
                                    'debit' => $gb['debit'],
                                    'credit' => $gb['credit'],
                                    'balance' => $gb['net'],
                                    'movements' => $gb['movements'],
                                    'account_count' => $gCount,
                                    'direct_count' => $gCount,
                                    'children_count' => 0,
                                    'has_children' => $gCount > 0,
                                    'children' => [],
                                ];
                            }
                        }

                        $pb = $partBal[$accPart] ?? $this->emptyBucket();
                        $visibleChildren = count($partChildren);
                        $subNodes[] = [
                            'id' => 'sub_'.$accPart,
                            'level' => 'sub',
                            'acc_part' => $accPart,
                            'acc_no' => $accNo,
                            'acc_sub' => $accSub,
                            'name' => $s['part_name'],
                            'parent_path' => $this->pathName([$a['acc_name'], $m['sub_name']]),
                            'code_path' => $partPathCode,
                            'stored_balance' => $s['balance'],
                            'cur_no' => $s['cur_no'],
                            'debit' => $pb['debit'],
                            'credit' => $pb['credit'],
                            'balance' => $pb['net'],
                            'movements' => $pb['movements'],
                            'account_count' => $partAccounts,
                            'direct_count' => $directCount,
                            'hidden_accounts' => max(0, $partAccounts - $this->visibleAccountCount($accPart, $visibleChildren, $directCount, $counts, $groups)),
                            'children_count' => $visibleChildren,
                            'has_children' => ($visibleChildren > 0 || $directCount > 0),
                            'children' => $partChildren,
                        ];
                    }

                    $sb = $subBal[$accSub] ?? $this->emptyBucket();
                    $mainNodes[] = [
                        'id' => 'main_'.$accSub,
                        'level' => 'main',
                        'acc_sub' => $accSub,
                        'acc_no' => $accNo,
                        'name' => $m['sub_name'],
                        'parent_path' => $this->pathName([$a['acc_name']]),
                        'code_path' => $subPathCode,
                        'stored_balance' => $m['balance'],
                        'cur_no' => $m['cur_no'],
                        'debit' => $sb['debit'],
                        'credit' => $sb['credit'],
                        'balance' => $sb['net'],
                        'movements' => $sb['movements'],
                        'account_count' => $this->accountCountForSub($accSub, $subs, $counts['part']),
                        'children_count' => count($subNodes),
                        'has_children' => true,
                        'children' => $subNodes,
                    ];
                }

                $ab = $accBal[$accNo] ?? $this->emptyBucket();
                $tree[] = [
                    'id' => 'acc_'.$accNo,
                    'level' => 'account',
                    'acc_no' => $accNo,
                    'name' => $a['acc_name'],
                    'parent_path' => '',
                    'code_path' => $accPathCode,
                    'stored_balance' => $a['balance'],
                    'cur_no' => $a['cur_no'],
                    'debit' => $ab['debit'],
                    'credit' => $ab['credit'],
                    'balance' => $ab['net'],
                    'movements' => $ab['movements'],
                    'account_count' => array_sum(array_map(
                        fn ($m) => $this->accountCountForSub((int) $m['acc_sub'], $subs, $counts['part']),
                        $mainNodes
                    )),
                    'children_count' => count($mainNodes),
                    'has_children' => true,
                    'children' => $mainNodes,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $tree,
                'meta' => [
                    'total_nodes' => $this->countTree($tree),
                    'loading' => 'lazy',
                    'from_date' => $fromIso,
                    'to_date' => $toIso,
                    'generated_at' => date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/export-tree
     * قائمة مسطّحة بكل الصفوف المطلوبة من مستويات الشجرة — للطباعة والتصدير فقط.
     *
     * Why this endpoint exists: the export menu used to serialize whatever the screen
     * had already rendered. The tree is lazy, so printing a collapsed tree wrote 134
     * structural rows and not a single account — exactly what the user reported. Export
     * has to ask the database, never the scroll position, and must say which levels it
     * wants.
     *
     * الأرصدة نفس مصدرها في الشجرة (ENTRY_SUB) ونفس الترقية (rollUpBalances)، فلا تتباعد
     * أرقام المطبوعة عن الشاشة. حسابات العملاء (123) مخفية عرضاً لكن التصدير يخيّر المستخدم.
     *
     * Query params:
     *   levels           csv of account|main|sub|group|chart — default all five
     *   section          optional ACC_NO restricting the whole export to one section
     *   include_customers 0/1 — include the 123 customer accounts (default 0)
     *   hide_zero        0/1 — drop rows whose net balance is zero
     *   from_date / to_date
     */
    public function exportTree(Request $request)
    {
        try {
            $allowed = ['account', 'main', 'sub', 'group', 'chart'];
            $requested = array_values(array_filter(
                array_map('trim', explode(',', (string) $request->query('levels', ''))),
                fn ($l) => in_array($l, $allowed, true)
            ));
            // مستوى واحد على الأقل دائماً، وإلا كانت المطبوعة فارغة بلا خطأ يشرح السبب
            $levels = $requested !== [] ? $requested : $allowed;

            $section = $request->query('section');
            $section = ($section === null || $section === '') ? null : (int) $section;
            $includeCustomers = filter_var($request->query('include_customers', '0'), FILTER_VALIDATE_BOOLEAN);
            $hideZero = filter_var($request->query('hide_zero', '0'), FILTER_VALIDATE_BOOLEAN);

            $fromIso = $this->normaliseOptionalDate($request->query('from_date'));
            $toIso = $this->normaliseOptionalDate($request->query('to_date'));

            $accounts = $this->structureRows('ACCOUNT', 'ACC_NO, ACC_NAME, BALANCE, CUR_NO', 'ACC_NO');
            $mains = $this->structureRows('ACC_MAIN', 'ACC_NO, ACC_SUB, SUB_NAME, BALANCE, CUR_NO', 'ACC_NO, ACC_SUB');
            $subs = $this->structureRows('ACC_SUB', 'ACC_SUB, ACC_PART, PART_NAME, BALANCE, CUR_NO', 'ACC_SUB, ACC_PART');
            $groups = $this->structureRows('ACC_SUB_GROUP', 'GROUP_ID, ACC_PART, ACC_GROUP_NAME', 'ACC_PART, GROUP_ID');

            $chartBal = $this->movementBalancesByChart($fromIso, $toIso);
            $groupOfChart = $this->chartGroupMap();
            $counts = $this->chartCountBuckets();
            $roll = $this->rollUpBalances($chartBal, $groupOfChart, $subs, $mains);
            $partBal = $roll['part'];
            $groupBal = $roll['group'];
            $subBal = $roll['sub'];
            $accBal = $roll['account'];

            // ── نطاق الأجزاء المطلوبة: يُفلتر في SQL لا في PHP (20 ألف صف بلا داعٍ) ──
            // حسابات العملاء تُستثنى من الصفوف لا من الأجزاء: فالجزء 123 يبقى مطلوباً
            // لسحب حساب الكبودي 12310000 وحده، والفلتر أدناه يضمن ذلك.
            $parts = [];
            foreach ($subs as $s) {
                $part = (int) $s['acc_part'];
                if ($section !== null && $this->sectionOfPart($part, $subs, $mains) !== $section) {
                    continue;
                }
                $parts[] = $part;
            }
            $parts = array_values(array_unique($parts));

            $binds = [];
            $inList = [];
            foreach ($parts as $i => $p) {
                $key = 'part_'.$i;
                $inList[] = ':'.$key;
                $binds[$key] = $p;
            }
            $partFilter = $inList === [] ? '1 = 0' : 'SUBSTR(TO_CHAR(ACC_NO), 1, 3) IN ('.implode(',', $inList).')';
            $koboudiGuard = $includeCustomers ? '' : ' AND (SUBSTR(TO_CHAR(ACC_NO), 1, 3) <> '.self::CUSTOMERS_ACC_PART.' OR ACC_NO = '.self::KOBOUDI_CUSTOMER_ACC_NO.')';

            // CHART_ACC لا يملك عمود عملة (8 أعمدة فقط): العملة تُورَّث من الحساب الجزئي
            // The financial table carries no currency column at all — a ledger account
            // inherits CUR_NO from its owning part, so the export reads it from $s.
            $chartRows = DB::connection('oracle')->select("
                SELECT ACC_NO, ACC_NAME, GROUP_ID, BALANCE, LIMITED_BALANCE, STOPPING_ACC, SEQ,
                       TO_CHAR(ACC_DATE, 'YYYY-MM-DD') AS ACC_DATE
                FROM CHART_ACC
                WHERE {$partFilter} {$koboudiGuard}
                ORDER BY ACC_NO
            ", $binds);

            // فهرسة مرة واحدة: مجموعة ← حسابات، وجزئي ← حسابات مباشرة (بلا مجموعة)
            $byGroup = [];
            $byPart = [];
            $chartList = [];
            $byAcc = [];
            foreach ($chartRows as $row) {
                $r = array_change_key_case((array) $row, CASE_LOWER);
                $accNo = (int) ($r['acc_no'] ?? 0);
                $gid = isset($r['group_id']) && $r['group_id'] !== null ? (int) $r['group_id'] : null;
                $item = [
                    'acc_no' => $accNo,
                    'name' => ArabicEncoding::fixMojibake($r['acc_name'] ?? ''),
                    'group_id' => $gid,
                    'part' => (int) substr((string) $accNo, 0, 3),
                    'stored_balance' => (float) ($r['balance'] ?? 0),
                    'limited_balance' => $r['limited_balance'] ?? null,
                    'stopping_acc' => isset($r['stopping_acc']) ? (int) $r['stopping_acc'] : null,
                    'seq' => isset($r['seq']) ? (int) $r['seq'] : null,
                    'acc_date' => $r['acc_date'] ?? null,
                ];
                $chartList[] = $item;
                $byAcc[$accNo] = $item;
                if ($gid !== null) {
                    $byGroup[$gid][] = $item;
                } else {
                    $byPart[$item['part']][] = $item;
                }
            }

            $rows = [];
            $levelCounts = [];
            // لكل صف يُضاف root_acc_no أثناء الدفع: الحقل الوحيد الذي يقول أي قسم يخدم
            // هذا الصف، لأن acc_no يعني شيئاً مختلفاً في كل مستوى (رقم الحساب المالي نفسه).
            $currentRoot = null;
            $push = function (array $row) use (&$rows, &$levelCounts, $hideZero, &$currentRoot): void {
                if ($hideZero && abs((float) $row['balance']) < 0.005) {
                    return;
                }
                $levelCounts[$row['level']] = ($levelCounts[$row['level']] ?? 0) + 1;
                $rows[] = $row + ['root_acc_no' => $currentRoot];
            };

            // فهرسة الهيكل: قسم ← فروعه، وفرع ← أجزاؤه. تُستعمل للعدّ الصادق ولتجنّب
            // مسح المصفوفات الكاملة عند كل أب (children_count لم يكن ليُحسب بدونه).
            $mainsByAcc = [];
            foreach ($mains as $m) {
                $mainsByAcc[(int) $m['acc_no']][] = $m;
            }
            $subsByMain = [];
            foreach ($subs as $s) {
                $subsByMain[(int) $s['acc_sub']][] = $s;
            }

            foreach ($accounts as $a) {
                $accNo = (int) $a['acc_no'];
                if ($section !== null && $accNo !== $section) {
                    continue;
                }
                $accMains = $mainsByAcc[$accNo] ?? [];
                $currentRoot = $accNo;
                $ab = $accBal[$accNo] ?? $this->emptyBucket();
                if (in_array('account', $levels, true)) {
                    // عدد الحسابات أسفل القسم = مجموع ما تحت فروعه من أجزاء، لا صفر مؤقت
                    $accAccounts = 0;
                    foreach ($accMains as $m) {
                        $accAccounts += $this->accountCountForSub((int) $m['acc_sub'], $subs, $counts['part']);
                    }
                    $push([
                        'depth' => 0, 'level' => 'account', 'code' => (string) $accNo, 'acc_no' => $accNo,
                        'name' => $a['name'], 'parent_path' => '', 'code_path' => (string) $accNo,
                        'debit' => $ab['debit'], 'credit' => $ab['credit'], 'balance' => $ab['net'],
                        'movements' => $ab['movements'], 'stored_balance' => (float) $a['balance'],
                        'cur_no' => $this->curNoOf($a['cur_no'] ?? null),
                        'children_count' => count($accMains), 'account_count' => $accAccounts,
                    ]);
                }

                foreach ($accMains as $m) {
                    $accSub = (int) $m['acc_sub'];
                    $mainPath = $accNo.' / '.$accSub;
                    $mainSubs = $subsByMain[$accSub] ?? [];
                    $sb = $subBal[$accSub] ?? $this->emptyBucket();
                    if (in_array('main', $levels, true)) {
                        $push([
                            'depth' => 1, 'level' => 'main', 'code' => (string) $accSub, 'acc_no' => $accNo,
                            'acc_sub' => $accSub, 'name' => $m['name'],
                            'parent_path' => $a['name'], 'code_path' => $mainPath,
                            'debit' => $sb['debit'], 'credit' => $sb['credit'], 'balance' => $sb['net'],
                            'movements' => $sb['movements'], 'stored_balance' => (float) $m['balance'],
                            'cur_no' => $this->curNoOf($m['cur_no'] ?? null), 'children_count' => count($mainSubs),
                            'account_count' => $this->accountCountForSub($accSub, $subs, $counts['part']),
                        ]);
                    }

                    foreach ($mainSubs as $s) {
                        $accPart = (int) $s['acc_part'];
                        $partPath = $mainPath.' / '.$accPart;
                        $names = [$a['name'], $m['name']];
                        $pb = $partBal[$accPart] ?? $this->emptyBucket();
                        $customers = $accPart === self::CUSTOMERS_ACC_PART;
                        $partGroups = $customers && ! $includeCustomers
                            ? []
                            : array_values(array_filter($groups, fn ($g) => (int) $g['acc_part'] === $accPart));

                        if (in_array('sub', $levels, true)) {
                            $push([
                                'depth' => 2, 'level' => 'sub', 'code' => (string) $accPart, 'acc_no' => $accNo,
                                'acc_sub' => $accSub, 'acc_part' => $accPart, 'name' => $s['name'],
                                'parent_path' => $this->pathName($names), 'code_path' => $partPath,
                                'debit' => $pb['debit'], 'credit' => $pb['credit'], 'balance' => $pb['net'],
                                'movements' => $pb['movements'], 'stored_balance' => (float) $s['balance'],
                                'cur_no' => $this->curNoOf($s['cur_no'] ?? null),
                                'children_count' => count($partGroups),
                                'account_count' => (int) ($counts['part'][$accPart] ?? 0),
                            ]);
                        }

                        foreach ($partGroups as $g) {
                            $gid = (int) $g['group_id'];
                            $gb = $groupBal[$gid] ?? $this->emptyBucket();
                            if (in_array('group', $levels, true)) {
                                $push([
                                    'depth' => 3, 'level' => 'group', 'code' => 'G'.$gid, 'acc_no' => $accNo,
                                    'acc_sub' => $accSub, 'acc_part' => $accPart, 'group_id' => $gid,
                                    'name' => $g['name'], 'parent_path' => $this->pathName(array_merge($names, [$s['name']])),
                                    'code_path' => $partPath.' / G'.$gid,
                                    'debit' => $gb['debit'], 'credit' => $gb['credit'], 'balance' => $gb['net'],
                                    'movements' => $gb['movements'], 'stored_balance' => null,
                                    'cur_no' => $this->curNoOf($s['cur_no'] ?? null),
                                    'children_count' => (int) ($counts['group'][$gid] ?? 0),
                                    'account_count' => (int) ($counts['group'][$gid] ?? 0),
                                ]);
                            }
                            if (! in_array('chart', $levels, true)) {
                                continue;
                            }
                            foreach ($byGroup[$gid] ?? [] as $c) {
                                $push($this->exportChartRow($c, 4, $chartBal, $this->pathName(array_merge($names, [$s['name'], $g['name']])), $partPath.' / G'.$gid.' / '.$c['acc_no'], $this->curNoOf($s['cur_no'] ?? null)));
                            }
                        }

                        // حسابات مباشرة بلا مجموعة (GROUP_ID IS NULL) — وهي حسابات العملاء
                        // حين يطلب المستخدم تضمينها، عدا الكبودي فقد جُعل مجموعة مفردة
                        if (in_array('chart', $levels, true) && (! $customers || $includeCustomers)) {
                            foreach ($byPart[$accPart] ?? [] as $c) {
                                $push($this->exportChartRow($c, 3, $chartBal, $this->pathName(array_merge($names, [$s['name']])), $partPath.' / '.$c['acc_no'], $this->curNoOf($s['cur_no'] ?? null)));
                            }
                        } elseif (in_array('chart', $levels, true) && $customers) {
                            // استثناء العرض: كبودى وحده، مطابق لما تعرضه الشاشة. وهو ليس
                            // حساباً مباشراً — GROUP_ID = 5 — فيُسحب بالرقم لا من $byPart،
                            // وإلا اختفى من كل مطبوعة لأن مجموعته أُسقطت من العرض.
                            $koboudi = $byAcc[self::KOBOUDI_CUSTOMER_ACC_NO] ?? null;
                            if ($koboudi !== null) {
                                $push($this->exportChartRow($koboudi, 3, $chartBal, $this->pathName(array_merge($names, [$s['name']])), $partPath.' / '.$koboudi['acc_no'], $this->curNoOf($s['cur_no'] ?? null)));
                            }
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $rows,
                'meta' => [
                    'total' => count($rows),
                    'levels' => $levels,
                    'level_counts' => $levelCounts,
                    'section' => $section,
                    'include_customers' => $includeCustomers,
                    'hide_zero' => $hideZero,
                    'from_date' => $fromIso,
                    'to_date' => $toIso,
                    'chart_source_rows' => count($chartList),
                    'generated_at' => date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/currencies
     * دليل العملات لملء حقل العملة في نموذجي الإنشاء والتعديل.
     *
     * Why not reuse /currencies: that route is gated by `currencies.view`, which an
     * accounts-only user does not have — a create form would silently fail to list the
     * currencies it is asking them to pick. This returns the two columns the form needs
     * under the accounts grants instead.
     */
    public function getCurrencyOptions(Request $request)
    {
        try {
            $rows = DB::connection('oracle')->select('SELECT CUR_NO, CUR_NAME FROM CURRENCY ORDER BY CUR_NO');
            $data = [];
            foreach ($rows as $row) {
                $r = array_change_key_case((array) $row, CASE_LOWER);
                $data[] = [
                    'cur_no' => (int) ($r['cur_no'] ?? 0),
                    'cur_name' => ArabicEncoding::fixMojibake((string) ($r['cur_name'] ?? '')),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => ['total' => count($data)],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/tree/children
     * أبناء عقدة هيكلية (حسابات CHART_ACC المالية) مع أرصدتهم المحسوبة من الحركات.
     * Financial leaves of one structural node, with balances computed from ENTRY_SUB.
     *
     * level=group → كل حسابات المجموعة (GROUP_ID).
     * level=sub   → الحسابات المباشرة للجزئي (GROUP_ID IS NULL وبادئتها = ACC_PART).
     *
     * Query params: level, group_id, acc_part, search, sort(acc_no|name|balance),
     *               dir(asc|desc), page, limit, from_date, to_date.
     */
    public function getTreeChildren(Request $request)
    {
        try {
            $level = (string) $request->query('level', 'group');
            $groupId = $request->query('group_id');
            $accPart = $request->query('acc_part');
            $search = trim((string) $request->query('search', ''));
            $page = max(1, (int) $request->query('page', 1));
            $limit = min(200, max(10, (int) $request->query('limit', 50)));
            $offset = ($page - 1) * $limit;
            $maxRow = $offset + $limit;

            $fromIso = $this->normaliseOptionalDate($request->query('from_date'));
            $toIso = $this->normaliseOptionalDate($request->query('to_date'));

            // ترتيب مسموح به فقط (منع SQL injection عبر معامل الترتيب)
            $sortCol = ['acc_no' => 'c.ACC_NO', 'name' => 'c.ACC_NAME', 'balance' => 'NVL(SUM(m.NET), 0)'][$request->query('sort', 'acc_no')] ?? 'c.ACC_NO';
            $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';

            $where = [];
            $params = [];

            if ($level === 'group') {
                if ($groupId === null || $groupId === '') {
                    return response()->json(['success' => false, 'message' => 'يجب تحديد معرّف المجموعة'], 422);
                }
                $where[] = 'c.GROUP_ID = :group_id';
                $params['group_id'] = (int) $groupId;
            } else {
                if ($accPart === null || $accPart === '') {
                    return response()->json(['success' => false, 'message' => 'يجب تحديد معرّف الحساب الجزئي'], 422);
                }
                $part = (int) $accPart;
                if ($part === self::CUSTOMERS_ACC_PART) {
                    // حسابات العملاء مخفية من العرض، والمعروض 12310000 مُدرَج في الهيكل
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'meta' => ['total' => 0, 'page' => $page, 'limit' => $limit, 'total_pages' => 0, 'has_more' => false],
                    ]);
                }
                $where[] = 'c.GROUP_ID IS NULL';
                $where[] = 'SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) = TO_CHAR(:acc_part)';
                $params['acc_part'] = $part;
            }

            if ($search !== '') {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $where[] = '(UPPER(c.ACC_NAME) LIKE UPPER(:search) OR c.ACC_NAME LIKE :search_enc OR TO_CHAR(c.ACC_NO) LIKE :search_no)';
                $params['search'] = '%'.$search.'%';
                $params['search_enc'] = '%'.$oracleSearch.'%';
                $params['search_no'] = '%'.$search.'%';
            }

            $whereClause = 'WHERE '.implode(' AND ', $where);
            $movementView = $this->movementAggregateSql($fromIso, $toIso);

            $countRows = DB::connection('oracle')->select(
                "SELECT COUNT(*) AS TOTAL FROM CHART_ACC c {$whereClause}",
                $params
            );
            $countArr = (array) ($countRows[0] ?? []);
            $total = (int) ($countArr['TOTAL'] ?? $countArr['total'] ?? 0);

            // وسائط تجميع الحركات (agg_from/agg_to) تُدمج مع وسائط الصفحة نفسها
            $fetchParams = array_merge($params, $this->movementAggregateParams);
            $fetchParams['max_row'] = $maxRow;
            $fetchParams['offset'] = $offset;

            $rows = DB::connection('oracle')->select("
                SELECT * FROM (
                    SELECT a.*, ROWNUM rnum FROM (
                        SELECT c.ACC_NO, c.ACC_NAME, c.BALANCE, c.GROUP_ID, c.SEQ, c.LIMITED_BALANCE, c.STOPPING_ACC,
                               TO_CHAR(c.ACC_DATE, 'YYYY-MM-DD') AS ACC_DATE,
                               NVL(SUM(m.DR), 0) AS DR, NVL(SUM(m.CR), 0) AS CR,
                               NVL(SUM(m.NET), 0) AS NET, NVL(SUM(m.CNT), 0) AS MOV_COUNT
                        FROM CHART_ACC c
                        LEFT JOIN ({$movementView}) m ON m.ACC_NO = c.ACC_NO
                        {$whereClause}
                        GROUP BY c.ACC_NO, c.ACC_NAME, c.BALANCE, c.GROUP_ID, c.SEQ,
                                 c.LIMITED_BALANCE, c.STOPPING_ACC, c.ACC_DATE
                        ORDER BY {$sortCol} {$dir}
                    ) a
                    WHERE ROWNUM <= :max_row
                )
                WHERE rnum > :offset
            ", $fetchParams);

            $data = [];
            foreach ($rows as $row) {
                $r = (array) $row;
                $accNo = (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0);
                $data[] = [
                    'id' => 'chart_'.$accNo,
                    'level' => 'chart',
                    'acc_no' => $accNo,
                    // العملة تُقرأ من الخانة الرابعة لرقم الحساب — لا عمود لها في CHART_ACC
                    'cur_no' => $this->currencyOfChartNumber($accNo),
                    'name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'group_id' => isset($r['GROUP_ID']) || isset($r['group_id']) ? (int) ($r['GROUP_ID'] ?? $r['group_id']) : null,
                    'stored_balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    'debit' => (float) ($r['DR'] ?? $r['dr'] ?? 0),
                    'credit' => (float) ($r['CR'] ?? $r['cr'] ?? 0),
                    'balance' => (float) ($r['NET'] ?? $r['net'] ?? 0),
                    'movements' => (int) ($r['MOV_COUNT'] ?? $r['mov_count'] ?? 0),
                    'seq' => isset($r['SEQ']) || isset($r['seq']) ? (int) ($r['SEQ'] ?? $r['seq']) : null,
                    'limited_balance' => isset($r['LIMITED_BALANCE']) || isset($r['limited_balance']) ? (float) ($r['LIMITED_BALANCE'] ?? $r['limited_balance']) : null,
                    'stopping_acc' => isset($r['STOPPING_ACC']) || isset($r['stopping_acc']) ? (int) ($r['STOPPING_ACC'] ?? $r['stopping_acc']) : null,
                    'acc_date' => $r['ACC_DATE'] ?? $r['acc_date'] ?? null,
                    'children_count' => 0,
                    'direct_count' => 0,
                    'has_children' => false,
                    'children' => [],
                ];
            }

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
     * GET /accounts/search
     * بحث شامل في كل مستويات الشجرة (هيكلية ومالية) مع مسار كل نتيجة.
     * Lets the tree screen search 20k+ accounts without downloading the tree.
     *
     * Query params: q, limit (افتراضي 60، أقصى 200), from_date, to_date.
     */
    public function searchAccounts(Request $request)
    {
        try {
            $q = trim((string) $request->query('q', ''));
            $limit = min(200, max(5, (int) $request->query('limit', 60)));
            if ($q === '') {
                return response()->json(['success' => true, 'data' => []]);
            }

            $fromIso = $this->normaliseOptionalDate($request->query('from_date'));
            $toIso = $this->normaliseOptionalDate($request->query('to_date'));

            $enc = ArabicEncoding::toOracleSearchChars($q);
            $like = '%'.$q.'%';
            $likeEnc = '%'.$enc.'%';

            // خرائط الأسماء لمسار الأب / parent-path lookups
            $partMeta = [];
            $subMeta = [];
            $accMeta = [];
            $groupMeta = [];
            foreach ($this->structureRows('ACCOUNT', 'ACC_NO, ACC_NAME', 'ACC_NO') as $a) {
                $accMeta[(int) $a['acc_no']] = $a['acc_name'];
            }
            foreach ($this->structureRows('ACC_MAIN', 'ACC_NO, ACC_SUB, SUB_NAME', 'ACC_SUB') as $m) {
                $subMeta[(int) $m['acc_sub']] = ['name' => $m['sub_name'], 'acc_no' => (int) $m['acc_no']];
            }
            foreach ($this->structureRows('ACC_SUB', 'ACC_SUB, ACC_PART, PART_NAME', 'ACC_PART') as $s) {
                $partMeta[(int) $s['acc_part']] = ['name' => $s['part_name'], 'acc_sub' => (int) $s['acc_sub']];
            }
            foreach ($this->structureRows('ACC_SUB_GROUP', 'GROUP_ID, ACC_PART, ACC_GROUP_NAME', 'GROUP_ID') as $g) {
                $groupMeta[(int) $g['group_id']] = ['name' => $g['acc_group_name'], 'acc_part' => (int) $g['acc_part']];
            }

            $out = [];

            // 1) المستويات الهيكلية — صغيرة جداً، تُجلب كلها وتُصفّى في PHP لتفادي
            //    مشاكل الترميز في LIKE على الأسماء العربية.
            $structural = [
                ['table' => 'ACCOUNT', 'cols' => 'ACC_NO, ACC_NAME, BALANCE, CUR_NO', 'idCol' => 'ACC_NO', 'nameCol' => 'ACC_NAME', 'level' => 'account'],
                ['table' => 'ACC_MAIN', 'cols' => 'ACC_NO, ACC_SUB, SUB_NAME, BALANCE, CUR_NO', 'idCol' => 'ACC_SUB', 'nameCol' => 'SUB_NAME', 'level' => 'main'],
                ['table' => 'ACC_SUB', 'cols' => 'ACC_SUB, ACC_PART, PART_NAME, BALANCE, CUR_NO', 'idCol' => 'ACC_PART', 'nameCol' => 'PART_NAME', 'level' => 'sub'],
                ['table' => 'ACC_SUB_GROUP', 'cols' => 'GROUP_ID, ACC_PART, ACC_GROUP_NAME', 'idCol' => 'GROUP_ID', 'nameCol' => 'ACC_GROUP_NAME', 'level' => 'group'],
            ];
            foreach ($structural as $spec) {
                $rows = $this->structureRows($spec['table'], $spec['cols'], $spec['idCol']);
                $idKey = strtolower($spec['idCol']);
                foreach ($rows as $r) {
                    $id = (int) ($r[$idKey] ?? 0);
                    $name = (string) $r['name'];
                    $hit = $this->matchesQuery($q, $name, (string) $id);
                    if (! $hit) {
                        continue;
                    }
                    // الصفوف الهيكلية تعرض الرصيد المخزّن فقط: تجميعها يحتاج مسح
                    // الحركات بالبادئة وهو أغلى من قيمة النتيجة في قائمة البحث.
                    $out[] = $this->searchNode($spec['level'], $id, $r, $accMeta, $subMeta, $partMeta, $groupMeta);
                }
            }

            // 2) الحسابات المالية — بحث SQL مقيّد بالعدد، مع استثناء العملاء ما عدا الكبودي
            $chartRows = DB::connection('oracle')->select("
                SELECT * FROM (
                    SELECT a.*, ROWNUM rnum FROM (
                        SELECT ACC_NO, ACC_NAME, BALANCE, GROUP_ID, LIMITED_BALANCE, STOPPING_ACC,
                               TO_CHAR(ACC_DATE, 'YYYY-MM-DD') AS ACC_DATE, SEQ
                        FROM CHART_ACC
                        WHERE (UPPER(ACC_NAME) LIKE UPPER(:search)
                               OR ACC_NAME LIKE :search_enc
                               OR TO_CHAR(ACC_NO) LIKE :search_no)
                          AND (SUBSTR(TO_CHAR(ACC_NO), 1, 3) <> TO_CHAR(:cust_part) OR ACC_NO = :koboudi)
                        ORDER BY ACC_NO
                    ) a
                    WHERE ROWNUM <= :max_rows
                )
                WHERE rnum > 0
            ", [
                'search' => $like,
                'search_enc' => $likeEnc,
                'search_no' => $like,
                'cust_part' => self::CUSTOMERS_ACC_PART,
                'koboudi' => self::KOBOUDI_CUSTOMER_ACC_NO,
                'max_rows' => $limit,
            ]);

            $chartAccNos = [];
            foreach ($chartRows as $row) {
                $r = (array) $row;
                $chartAccNos[] = (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0);
            }
            $balances = $this->movementBalancesFor($chartAccNos, $fromIso, $toIso);

            foreach ($chartRows as $row) {
                $r = (array) $row;
                $accNo = (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0);
                $b = $balances[$accNo] ?? $this->emptyBucket();
                $part = (int) substr((string) $accNo, 0, 3);
                $gid = isset($r['GROUP_ID']) || isset($r['group_id']) ? (int) ($r['GROUP_ID'] ?? $r['group_id']) : null;

                $chain = [];
                $codes = [(string) $accNo];
                if (isset($partMeta[$part])) {
                    $sub = $partMeta[$part]['acc_sub'];
                    $codes[] = (string) $sub;
                    if (isset($subMeta[$sub])) {
                        $acc = $subMeta[$sub]['acc_no'];
                        $codes[] = (string) $acc;
                        $chain = [$accMeta[$acc] ?? '', $subMeta[$sub]['name'], $partMeta[$part]['name']];
                    }
                }
                if ($gid !== null && isset($groupMeta[$gid])) {
                    $chain[] = $groupMeta[$gid]['name'];
                    $codes[] = 'G'.$gid;
                }

                $out[] = [
                    'id' => 'chart_'.$accNo,
                    'level' => 'chart',
                    'acc_no' => $accNo,
                    'cur_no' => $this->currencyOfChartNumber($accNo),
                    'group_id' => $gid,
                    'acc_part' => $part,
                    'name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'parent_path' => $this->pathName($chain),
                    'code_path' => implode(' / ', array_reverse($codes)),
                    'stored_balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    'limited_balance' => isset($r['LIMITED_BALANCE']) || isset($r['limited_balance']) ? (float) ($r['LIMITED_BALANCE'] ?? $r['limited_balance']) : null,
                    'stopping_acc' => isset($r['STOPPING_ACC']) || isset($r['stopping_acc']) ? (int) ($r['STOPPING_ACC'] ?? $r['stopping_acc']) : null,
                    'acc_date' => $r['ACC_DATE'] ?? $r['acc_date'] ?? null,
                    'seq' => isset($r['SEQ']) || isset($r['seq']) ? (int) ($r['SEQ'] ?? $r['seq']) : null,
                    'debit' => $b['debit'],
                    'credit' => $b['credit'],
                    'balance' => $b['net'],
                    'movements' => $b['movements'],
                    'children_count' => 0,
                    'direct_count' => 0,
                    'has_children' => false,
                    'children' => [],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $out,
                'meta' => ['total' => count($out), 'query' => $q],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /accounts/chart/{accNo}
     * تفاصيل حساب مالي واحد: كل أعمدة CHART_ACC + رصيده المحسوب من الحركات + سلسلة آبائه.
     * Full detail for the tree "information" sheet and the edit form pre-fill.
     */
    public function getChartAccountDetail(Request $request, $accNo)
    {
        try {
            $row = DB::connection('oracle')->selectOne("
                SELECT ACC_NO, ACC_NAME, BALANCE, GROUP_ID, SEQ, LIMITED_BALANCE, STOPPING_ACC,
                       TO_CHAR(ACC_DATE, 'YYYY-MM-DD') AS ACC_DATE
                FROM CHART_ACC WHERE ACC_NO = :no
            ", ['no' => (int) $accNo]);

            if (! $row) {
                return response()->json(['success' => false, 'message' => 'الحساب غير موجود'], 404);
            }
            $r = (array) $row;
            $accNoInt = (int) ($r['ACC_NO'] ?? $r['acc_no']);
            $groupId = isset($r['GROUP_ID']) || isset($r['group_id']) ? (int) ($r['GROUP_ID'] ?? $r['group_id']) : null;
            $part = (int) substr((string) $accNoInt, 0, 3);

            $fromIso = $this->normaliseOptionalDate($request->query('from_date'));
            $toIso = $this->normaliseOptionalDate($request->query('to_date'));
            $b = $this->movementBalancesFor([$accNoInt], $fromIso, $toIso)[$accNoInt] ?? $this->emptyBucket();

            // سلسلة الآباء: جزئي ← فرعي ← قسم (+ اسم المجموعة إن وُجدت)
            $chain = [];
            $subRow = DB::connection('oracle')->selectOne(
                'SELECT ACC_SUB, PART_NAME FROM ACC_SUB WHERE ACC_PART = :p', ['p' => $part]
            );
            if ($subRow) {
                $sr = (array) $subRow;
                $accSub = (int) ($sr['ACC_SUB'] ?? $sr['acc_sub']);
                $chain[] = ArabicEncoding::fixMojibake($sr['PART_NAME'] ?? $sr['part_name'] ?? '');
                $mainRow = DB::connection('oracle')->selectOne(
                    'SELECT ACC_NO, SUB_NAME FROM ACC_MAIN WHERE ACC_SUB = :s', ['s' => $accSub]
                );
                if ($mainRow) {
                    $mr = (array) $mainRow;
                    $chain[] = ArabicEncoding::fixMojibake($mr['SUB_NAME'] ?? $mr['sub_name'] ?? '');
                    $secRow = DB::connection('oracle')->selectOne(
                        'SELECT ACC_NAME FROM ACCOUNT WHERE ACC_NO = :a', ['a' => (int) ($mr['ACC_NO'] ?? $mr['acc_no'])]
                    );
                    if ($secRow) {
                        $chain[] = ArabicEncoding::fixMojibake(((array) $secRow)['ACC_NAME'] ?? ((array) $secRow)['acc_name'] ?? '');
                    }
                }
            }
            if ($groupId !== null) {
                $gRow = DB::connection('oracle')->selectOne(
                    'SELECT ACC_GROUP_NAME FROM ACC_SUB_GROUP WHERE GROUP_ID = :g', ['g' => $groupId]
                );
                if ($gRow) {
                    $chain[] = ArabicEncoding::fixMojibake(((array) $gRow)['ACC_GROUP_NAME'] ?? ((array) $gRow)['acc_group_name'] ?? '');
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'acc_no' => $accNoInt,
                    // عملة الحساب المالي مشفَّرة في رقمه (الخانة الرابعة) — لا عمود CUR_NO هنا
                    'cur_no' => $this->currencyOfChartNumber((int) $accNoInt),
                    'acc_name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
                    'balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
                    'group_id' => $groupId,
                    'acc_part' => $part,
                    'seq' => isset($r['SEQ']) || isset($r['seq']) ? (int) ($r['SEQ'] ?? $r['seq']) : null,
                    'limited_balance' => isset($r['LIMITED_BALANCE']) || isset($r['limited_balance']) ? (float) ($r['LIMITED_BALANCE'] ?? $r['limited_balance']) : null,
                    'stopping_acc' => isset($r['STOPPING_ACC']) || isset($r['stopping_acc']) ? (int) ($r['STOPPING_ACC'] ?? $r['stopping_acc']) : null,
                    'acc_date' => $r['ACC_DATE'] ?? $r['acc_date'] ?? null,
                    'parent_path' => $this->pathName(array_reverse($chain)),
                    'debit' => $b['debit'],
                    'credit' => $b['credit'],
                    'computed_balance' => $b['net'],
                    'movements' => $b['movements'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================
    // ===        هيكل الشجرة: إنشاء/تعديل المستويات         ===
    // =========================================================

    /**
     * GET /accounts/next-number?level=chart|group|sub|main&…
     * معاينة الرقم الذي سيُمنح للعقدة الجديدة، بنفس قاعدة التوليد المستخدمة في
     * create* أدناه تماماً — لا نسخة ثانية منها، وإلا خرجت المعاينة عن الواقع
     * وانشأ المستخدم رقماً يصادف محجوزاً. level=chart يطلب cur_no لأن الخانة الرابعة
     * من الرقم هي العملة، فبدونها لا يمكن تخمين الرقم أصلاً.
     *
     * Previews the number the create endpoints would assign. It reuses the very same
     * expressions rather than duplicating them in Dart: a second copy of a legacy
     * numbering rule drifts and the preview becomes a lie.
     */
    public function nextAccountNumber(Request $request)
    {
        $request->validate([
            'level' => 'required|in:chart,group,sub,main',
            'group_id' => 'nullable|integer',
            'acc_part' => 'nullable|integer',
            'acc_sub' => 'nullable|integer',
            'acc_no' => 'nullable|integer',
            'cur_no' => 'nullable|integer|min:1|max:9',
        ]);

        $level = $request->query('level');

        try {
            if ($level === 'main') {
                $accNo = (int) $request->query('acc_no');
                if (! $accNo) {
                    return response()->json(['success' => false, 'message' => 'acc_no مطلوب'], 422);
                }
                $row = (array) DB::connection('oracle')->selectOne(
                    'SELECT MAX(ACC_SUB) AS MAX_SUB FROM ACC_MAIN WHERE ACC_NO = :a', ['a' => $accNo]
                );
                $max = $row['MAX_SUB'] ?? $row['max_sub'] ?? null;
                $next = $max !== null ? ((int) $max + 1) : ($accNo * 10 + 1);

                return response()->json(['success' => true, 'data' => ['level' => $level, 'number' => $next]]);
            }

            if ($level === 'sub') {
                $accSub = (int) $request->query('acc_sub');
                if (! $accSub) {
                    return response()->json(['success' => false, 'message' => 'acc_sub مطلوب'], 422);
                }
                $next = null;
                for ($k = 0; $k <= 9; $k++) {
                    $candidate = $accSub * 10 + $k;
                    $taken = DB::connection('oracle')->selectOne(
                        'SELECT ACC_PART FROM ACC_SUB WHERE ACC_PART = :p', ['p' => $candidate]
                    );
                    if (! $taken) {
                        $next = $candidate;
                        break;
                    }
                }

                return response()->json([
                    'success' => $next !== null,
                    'message' => $next === null
                        ? 'لا توجد أرقام متاحة تحت الحساب الفرعي '.$accSub.' (عشرة أجزاء كحد أقصى)'
                        : '',
                    'data' => ['level' => $level, 'number' => $next],
                ]);
            }

            if ($level === 'group') {
                $row = (array) DB::connection('oracle')->selectOne(
                    'SELECT MAX(GROUP_ID) AS MAX_ID FROM ACC_SUB_GROUP'
                );
                $max = $row['MAX_ID'] ?? $row['max_id'] ?? null;

                return response()->json([
                    'success' => true,
                    'data' => ['level' => $level, 'number' => $max !== null ? (int) $max + 1 : 1],
                ]);
            }

            // chart: المجموعة تحكم البادئة، والعملة تحكم الخانة الرابعة من الرقم
            $accPart = null;
            $groupId = $request->query('group_id');
            if ($groupId) {
                $g = (array) DB::connection('oracle')->selectOne(
                    'SELECT ACC_PART FROM ACC_SUB_GROUP WHERE GROUP_ID = :gid', ['gid' => (int) $groupId]
                );
                $accPart = $g ? (int) ($g['ACC_PART'] ?? $g['acc_part']) : null;
            } elseif ($request->query('acc_part')) {
                $accPart = (int) $request->query('acc_part');
            }
            if ($accPart === null) {
                return response()->json(['success' => false, 'message' => 'group_id أو acc_part مطلوب'], 422);
            }

            $curNo = (int) $request->query('cur_no');
            if ($curNo < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'cur_no مطلوب لعرض رقم الحساب المالي الصحيح',
                ], 422);
            }

            $band = $this->nextChartAccountNumber($accPart, $curNo);
            if ($band['number'] === null) {
                return response()->json(['success' => false, 'message' => $band['error']], 422);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'level' => $level,
                    'number' => $band['number'],
                    'acc_part' => $accPart,
                    'cur_no' => $curNo,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /accounts/main — إنشاء حساب فرعي (ACC_MAIN) داخل قسم رئيسي.
     * Numbering: ACC_SUB is the legacy 2-digit branch code, so the next free value
     * inside the same section is used (MAX + 1), guarded against a PK collision.
     */
    public function createAccMain(Request $request)
    {
        try {
            $request->validate([
                'acc_no' => 'required|integer',
                'sub_name' => 'required|string|max:30',
            ]);

            $accNo = (int) $request->input('acc_no');
            $section = DB::connection('oracle')->selectOne('SELECT ACC_NO FROM ACCOUNT WHERE ACC_NO = :a', ['a' => $accNo]);
            if (! $section) {
                return response()->json(['success' => false, 'message' => 'القسم الرئيسي غير موجود'], 404);
            }

            $maxRow = DB::connection('oracle')->selectOne(
                'SELECT MAX(ACC_SUB) AS MAX_SUB FROM ACC_MAIN WHERE ACC_NO = :a', ['a' => $accNo]
            );
            $maxArr = (array) $maxRow;
            $next = (int) (($maxArr['MAX_SUB'] ?? $maxArr['max_sub'] ?? ($accNo * 10)) + 1);

            if ($this->accMainExists($next)) {
                return response()->json(['success' => false, 'message' => 'الرقم المحاسبي متكرر في الهيكل: '.$next], 422);
            }

            DB::connection('oracle')->insert(
                'INSERT INTO ACC_MAIN (ACC_NO, ACC_SUB, SUB_NAME, BALANCE, CUR_NO)
                 VALUES (:acc_no, :acc_sub, :sub_name, :balance, :cur_no)',
                [
                    'acc_no' => $accNo,
                    'acc_sub' => $next,
                    'sub_name' => ArabicEncoding::toOracleSearchChars($request->input('sub_name')),
                    'balance' => (float) $request->input('balance', 0),
                    'cur_no' => $request->input('cur_no') !== null ? (int) $request->input('cur_no') : null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء الحساب الفرعي بنجاح',
                'data' => ['acc_no' => $accNo, 'acc_sub' => $next],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /accounts/main/{accSub} — تعديل بيانات حساب فرعي.
     */
    public function updateAccMain(Request $request, $accSub)
    {
        return $this->updateStructure('ACC_MAIN', 'ACC_SUB', (int) $accSub, $request, [
            'sub_name' => ['SET' => 'SUB_NAME', 'arabic' => true],
            'balance' => ['SET' => 'BALANCE'],
            'cur_no' => ['SET' => 'CUR_NO'],
            'acc_no' => ['SET' => 'ACC_NO'],
        ], 'الحساب الفرعي');
    }

    /**
     * POST /accounts/sub — إنشاء حساب جزئي (ACC_SUB) داخل حساب فرعي.
     * Numbering: ACC_PART = ACC_SUB * 10 + next free digit (the legacy 3-digit rule).
     */
    public function createAccSub(Request $request)
    {
        try {
            $request->validate([
                'acc_sub' => 'required|integer',
                'part_name' => 'required|string|max:30',
            ]);

            $accSub = (int) $request->input('acc_sub');
            $main = DB::connection('oracle')->selectOne('SELECT ACC_SUB FROM ACC_MAIN WHERE ACC_SUB = :s', ['s' => $accSub]);
            if (! $main) {
                return response()->json(['success' => false, 'message' => 'الحساب الفرعي غير موجود'], 404);
            }

            $base = $accSub * 10;
            $next = null;
            for ($k = 0; $k <= 9; $k++) {
                $candidate = $base + $k;
                $taken = DB::connection('oracle')->selectOne(
                    'SELECT ACC_PART FROM ACC_SUB WHERE ACC_PART = :p', ['p' => $candidate]
                );
                if (! $taken) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد أرقام متاحة تحت الحساب الفرعي '.$accSub.' — القاعدة التراثية تسمح بعشرة أجزاء فقط (مثال 120..129)',
                ], 422);
            }

            DB::connection('oracle')->insert(
                'INSERT INTO ACC_SUB (ACC_SUB, ACC_PART, PART_NAME, BALANCE, CUR_NO)
                 VALUES (:acc_sub, :acc_part, :part_name, :balance, :cur_no)',
                [
                    'acc_sub' => $accSub,
                    'acc_part' => $next,
                    'part_name' => ArabicEncoding::toOracleSearchChars($request->input('part_name')),
                    'balance' => (float) $request->input('balance', 0),
                    'cur_no' => $request->input('cur_no') !== null ? (int) $request->input('cur_no') : null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء الحساب الجزئي بنجاح',
                'data' => ['acc_sub' => $accSub, 'acc_part' => $next],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /accounts/sub/{accPart} — تعديل بيانات حساب جزئي.
     */
    public function updateAccSub(Request $request, $accPart)
    {
        return $this->updateStructure('ACC_SUB', 'ACC_PART', (int) $accPart, $request, [
            'part_name' => ['SET' => 'PART_NAME', 'arabic' => true],
            'balance' => ['SET' => 'BALANCE'],
            'cur_no' => ['SET' => 'CUR_NO'],
            'acc_sub' => ['SET' => 'ACC_SUB'],
        ], 'الحساب الجزئي');
    }

    /**
     * PUT /accounts/account/{accNo} — تعديل قسم رئيسي (الاسم/الرصيد الافتتاحي/العملة).
     */
    public function updateAccount(Request $request, $accNo)
    {
        return $this->updateStructure('ACCOUNT', 'ACC_NO', (int) $accNo, $request, [
            'acc_name' => ['SET' => 'ACC_NAME', 'arabic' => true],
            'balance' => ['SET' => 'BALANCE'],
            'cur_no' => ['SET' => 'CUR_NO'],
        ], 'القسم الرئيسي');
    }

    // =========================================================
    // ===                   دوال مساعدة                     ===
    // =========================================================

    /** وسائط الربط الخاصة بـ movementAggregateSql (تُستخدم من الاستعلامات الداعية). */
    private array $movementAggregateParams = [];

    /**
     * نص الاستعلام المجمّع لحركات ENTRY_SUB لكل حساب مالي.
     * Inline view: one row per ledger account number with debit/credit/net/movements.
     * Without a period there is no reason to join ENTRY_MAIN at all (much faster over
     * the 422k movement rows, and ENTRY_SUB_BRW_I1 already covers ACC_NO).
     *
     * ملاحظة: يملأ $movementAggregateParams بالوسائط التي على المستدمج دمجها في
     * معاملاته إن احتوى النص عليها.
     */
    private function movementAggregateSql(?string $fromIso, ?string $toIso): string
    {
        $params = [];
        $dateCond = '';

        if ($fromIso) {
            $dateCond .= " AND em.ENTRY_DATE >= TO_DATE(:agg_from, 'YYYY-MM-DD')";
            $params['agg_from'] = $fromIso;
        }
        if ($toIso) {
            $dateCond .= " AND em.ENTRY_DATE <= TO_DATE(:agg_to, 'YYYY-MM-DD')";
            $params['agg_to'] = $toIso;
        }

        // بدون فترة: لا ربط بـ ENTRY_MAIN إطلاقاً / skip the header join when unfiltered
        $from = 'ENTRY_SUB es';
        if ($dateCond !== '') {
            $from = 'ENTRY_SUB es INNER JOIN ENTRY_MAIN em ON em.ENTRY_NO = es.ENTRY_NO';
        }

        $this->movementAggregateParams = $params;

        return "SELECT es.ACC_NO,
                       NVL(SUM(CASE WHEN es.ENTRY_MNT > 0 THEN es.ENTRY_MNT ELSE 0 END), 0) AS DR,
                       NVL(SUM(CASE WHEN es.ENTRY_MNT < 0 THEN ABS(es.ENTRY_MNT) ELSE 0 END), 0) AS CR,
                       NVL(SUM(es.ENTRY_MNT), 0) AS NET,
                       COUNT(*) AS CNT
                FROM {$from}"
            .($dateCond !== '' ? "
                WHERE 1 = 1{$dateCond}" : '').'
                GROUP BY es.ACC_NO';
    }

    /**
     * خريطة أرصدة كل حساب مالي من الحركات: [acc_no => bucket].
     */
    private function movementBalancesByChart(?string $fromIso, ?string $toIso): array
    {
        $sql = $this->movementAggregateSql($fromIso, $toIso);
        $rows = DB::connection('oracle')->select($sql, $this->movementAggregateParams);

        $map = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $accNo = (int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0);
            $map[$accNo] = [
                'debit' => (float) ($r['DR'] ?? $r['dr'] ?? 0),
                'credit' => (float) ($r['CR'] ?? $r['cr'] ?? 0),
                'net' => (float) ($r['NET'] ?? $r['net'] ?? 0),
                'movements' => (int) ($r['CNT'] ?? $r['cnt'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * أرصدة مجموعة محددة من الأرقام (لصفحة أبناء أو لنتائج بحث) — نفس القاعدة لكن
     * مقيّدة بـ IN حتى تظل النتيجة صغيرة وسريعة على الفهرس ENTRY_SUB_BRW_I1.
     */
    private function movementBalancesFor(array $accNos, ?string $fromIso, ?string $toIso): array
    {
        if (empty($accNos)) {
            return [];
        }

        $buckets = [];
        // Oracle يحدّ قائمة IN بألف عنصر — تُقسَّم الصفحة إلى دفعات آمنة
        foreach (array_chunk($accNos, 900) as $chunk) {
            $inNames = [];
            $params = [];
            foreach ($chunk as $i => $no) {
                $key = 'acc_no_'.$i;
                $inNames[] = ':'.$key;
                $params[$key] = (int) $no;
            }
            if ($fromIso) {
                $params['agg_from'] = $fromIso;
            }
            if ($toIso) {
                $params['agg_to'] = $toIso;
            }

            $dateCond = '';
            $from = 'ENTRY_SUB es';
            if ($fromIso || $toIso) {
                $from = 'ENTRY_SUB es INNER JOIN ENTRY_MAIN em ON em.ENTRY_NO = es.ENTRY_NO';
                if ($fromIso) {
                    $dateCond .= " AND em.ENTRY_DATE >= TO_DATE(:agg_from, 'YYYY-MM-DD')";
                }
                if ($toIso) {
                    $dateCond .= " AND em.ENTRY_DATE <= TO_DATE(:agg_to, 'YYYY-MM-DD')";
                }
            }

            $rows = DB::connection('oracle')->select("
                SELECT es.ACC_NO,
                       NVL(SUM(CASE WHEN es.ENTRY_MNT > 0 THEN es.ENTRY_MNT ELSE 0 END), 0) AS DR,
                       NVL(SUM(CASE WHEN es.ENTRY_MNT < 0 THEN ABS(es.ENTRY_MNT) ELSE 0 END), 0) AS CR,
                       NVL(SUM(es.ENTRY_MNT), 0) AS NET,
                       COUNT(*) AS CNT
                FROM {$from}
                WHERE es.ACC_NO IN (".implode(',', $inNames)."){$dateCond}
                GROUP BY es.ACC_NO
            ", $params);

            foreach ($rows as $row) {
                $r = (array) $row;
                $buckets[(int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0)] = [
                    'debit' => (float) ($r['DR'] ?? $r['dr'] ?? 0),
                    'credit' => (float) ($r['CR'] ?? $r['cr'] ?? 0),
                    'net' => (float) ($r['NET'] ?? $r['net'] ?? 0),
                    'movements' => (int) ($r['CNT'] ?? $r['cnt'] ?? 0),
                ];
            }
        }

        return $buckets;
    }

    /**
     * خريطة [acc_no => group_id] لكل الحسابات المالية (تُستخدم لترقية الرصيد مستوى المجموعة).
     */
    private function chartGroupMap(): array
    {
        $rows = DB::connection('oracle')->select('SELECT ACC_NO, GROUP_ID FROM CHART_ACC');
        $map = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $gid = $r['GROUP_ID'] ?? $r['group_id'] ?? null;
            $map[(int) ($r['ACC_NO'] ?? $r['acc_no'] ?? 0)] = $gid === null ? null : (int) $gid;
        }

        return $map;
    }

    /**
     * عدادات الحسابات المالية لكل (جزئي، مجموعة): تُغذي children_count/direct_count
     * بدون جلب كل الصفوف. COUNTs per prefix and per group in one small query.
     */
    private function chartCountBuckets(): array
    {
        $rows = DB::connection('oracle')->select('
            SELECT SUBSTR(TO_CHAR(ACC_NO), 1, 3) AS PART_CODE, GROUP_ID, COUNT(*) AS CNT
            FROM CHART_ACC
            GROUP BY SUBSTR(TO_CHAR(ACC_NO), 1, 3), GROUP_ID
        ');

        $part = [];
        $group = [];
        $direct = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $partCode = (int) ($r['PART_CODE'] ?? $r['part_code'] ?? 0);
            $cnt = (int) ($r['CNT'] ?? $r['cnt'] ?? 0);
            $gid = $r['GROUP_ID'] ?? $r['group_id'] ?? null;

            $part[$partCode] = ($part[$partCode] ?? 0) + $cnt;
            if ($gid === null) {
                $direct[$partCode] = ($direct[$partCode] ?? 0) + $cnt;
            } else {
                $group[(int) $gid] = ($group[(int) $gid] ?? 0) + $cnt;
            }
        }

        return ['part' => $part, 'group' => $group, 'direct' => $direct];
    }

    /**
     * جلب صفوف جدول هيكلي وتوحيدها لمفاتيح قياسية صغيرة (بإصلاح الترميز العربي).
     * Structural rows normalised to lowercase keys with the Arabic repair applied.
     */
    private function structureRows(string $table, string $columns, string $orderBy): array
    {
        $rows = DB::connection('oracle')->select("SELECT {$columns} FROM {$table} ORDER BY {$orderBy}");

        $out = [];
        foreach ($rows as $row) {
            $r = array_change_key_case((array) $row, CASE_LOWER);
            $item = [];
            foreach ($r as $k => $v) {
                $item[$k] = is_string($v) ? ArabicEncoding::fixMojibake($v) : $v;
            }
            // مفتاح الاسم الموحّد لتبسيط البناء لاحقاً (الأرقام تبقى بأسماء أعمدتها)
            $item['name'] = $item['acc_name'] ?? $item['sub_name'] ?? $item['part_name'] ?? $item['acc_group_name'] ?? '';
            $out[] = $item;
        }

        return $out;
    }

    /** دلو فارغ / empty roll-up bucket. */
    private function emptyBucket(): array
    {
        return ['debit' => 0.0, 'credit' => 0.0, 'net' => 0.0, 'movements' => 0];
    }

    /** إضافة دلو حساب إلى دلو أب / accumulate a child bucket into its parent. */
    private function addBucket(?array $into, array $add): array
    {
        $into = $into ?? $this->emptyBucket();
        $into['debit'] += (float) $add['debit'];
        $into['credit'] += (float) $add['credit'];
        $into['net'] += (float) $add['net'];
        $into['movements'] += (int) $add['movements'];

        return $into;
    }

    /**
     * ترقية الأرصدة من الحسابات المالية إلى كل مستوى فوقها:
     *  acc_part (أول 3 أرقام) ← acc_sub ← acc_no، و GROUP_ID ← acc_part للمجموعة.
     * Balance roll-up from the financial accounts upward. Shared by the lazy tree and
     * the flat export so both report the same numbers for the same node.
     *
     * @param  array<int,array{debit:float,credit:float,net:float,movements:int}>  $chartBal  keyed by ACC_NO
     * @param  array<int,int|null>  $groupOfChart  ACC_NO → GROUP_ID
     * @return array{part: array, group: array, sub: array, account: array}
     */
    private function rollUpBalances(array $chartBal, array $groupOfChart, array $subs, array $mains): array
    {
        $partBal = [];
        $groupBal = [];
        foreach ($chartBal as $accNo => $b) {
            $part = (int) substr((string) $accNo, 0, 3);
            $partBal[$part] = $this->addBucket($partBal[$part] ?? null, $b);

            $gid = $groupOfChart[$accNo] ?? null;
            if ($gid !== null) {
                $groupBal[(int) $gid] = $this->addBucket($groupBal[(int) $gid] ?? null, $b);
            }
        }

        $partToSub = [];
        foreach ($subs as $s) {
            $partToSub[(int) $s['acc_part']] = (int) $s['acc_sub'];
        }
        $subToAccount = [];
        foreach ($mains as $m) {
            $subToAccount[(int) $m['acc_sub']] = (int) $m['acc_no'];
        }

        $subBal = [];
        $accBal = [];
        foreach ($partBal as $part => $b) {
            $sub = $partToSub[$part] ?? null;
            if ($sub === null) {
                continue; // جزء بلا أب معروف في الهيكل / orphan prefix — يُتجاهل في الترقية
            }
            $subBal[$sub] = $this->addBucket($subBal[$sub] ?? null, $b);

            $acc = $subToAccount[$sub] ?? null;
            if ($acc !== null) {
                $accBal[$acc] = $this->addBucket($accBal[$acc] ?? null, $b);
            }
        }

        return ['part' => $partBal, 'group' => $groupBal, 'sub' => $subBal, 'account' => $accBal];
    }

    /**
     * سطر حساب مالي في قائمة التصدير: نفس أرقام الشاشة (محسوب من ENTRY_SUB) مع المخزّن
     * للمقارنة، لأن العمود المخزّن ميت في الجزء 123 وفي أغلب الجزئيات.
     * $curNoHere عملة الحساب من الخانة الرابعة من رقمه؛ $curNo الموروثة من الجزئي تبقى
     * احتياطاً للأرقام المخالفة للقاعدة (أقل من أربعة خانات)، لا بديلاً عنها.
     */
    private function exportChartRow(array $c, int $depth, array $chartBal, string $parentPath, string $codePath, ?int $curNo = null): array
    {
        $b = $chartBal[$c['acc_no']] ?? $this->emptyBucket();
        $curNoHere = $this->currencyOfChartNumber((int) $c['acc_no']) ?? $curNo;

        return [
            'depth' => $depth,
            'level' => 'chart',
            'code' => (string) $c['acc_no'],
            'acc_no' => $c['acc_no'],
            'group_id' => $c['group_id'],
            'name' => $c['name'],
            'parent_path' => $parentPath,
            'code_path' => $codePath,
            'debit' => $b['debit'],
            'credit' => $b['credit'],
            'balance' => $b['net'],
            'movements' => $b['movements'],
            'stored_balance' => $c['stored_balance'],
            'limited_balance' => $c['limited_balance'] !== null ? (float) $c['limited_balance'] : null,
            'stopping_acc' => $c['stopping_acc'],
            'seq' => $c['seq'],
            'cur_no' => $curNoHere,
            'acc_date' => $c['acc_date'],
            'children_count' => 0,
            'account_count' => 0,
        ];
    }

    /**
     * رقم العملة كنص OCI أو null → عدد صحيح أو null.
     * OCI8 returns scalars as strings; an empty CUR_NO must stay null (inherit), not 0.
     */
    private function curNoOf($raw): ?int
    {
        return ($raw === null || $raw === '') ? null : (int) $raw;
    }

    /** أي قسم يخدم هذا الجزء؟ (جزئي → فرعي → قسم) — للتقييد بفلتر القسم في التصدير. */
    private function sectionOfPart(int $accPart, array $subs, array $mains): ?int
    {
        $accSub = null;
        foreach ($subs as $s) {
            if ((int) $s['acc_part'] === $accPart) {
                $accSub = (int) $s['acc_sub'];
                break;
            }
        }
        if ($accSub === null) {
            return null;
        }
        foreach ($mains as $m) {
            if ((int) $m['acc_sub'] === $accSub) {
                return (int) $m['acc_no'];
            }
        }

        return null;
    }

    /**
     * عدد الحسابات المالية تحت فرع معين (لحقل account_count في مستوى الفرعي/القسم):
     * يُجمَّع من عدادات الأجزاء عبر خريطة جزئي←فرعي، فلا حاجة لجرد الحسابات.
     */
    private function accountCountForSub(int $accSub, array $subs, array $partCounts): int
    {
        $total = 0;
        foreach ($subs as $s) {
            if ((int) $s['acc_sub'] === $accSub) {
                $total += (int) ($partCounts[(int) $s['acc_part']] ?? 0);
            }
        }

        return $total;
    }

    /**
     * كم حساباً مالياً يظهر فعلاً من نطاق الجزء (الباقي مخفي باستثناء العملاء).
     */
    private function visibleAccountCount(int $accPart, int $groupChildren, int $directCount, array $counts, array $groups): int
    {
        if ($accPart === self::CUSTOMERS_ACC_PART) {
            return $groupChildren; // حساب الكبودي المفرد فقط
        }

        $visible = $directCount;
        foreach ($groups as $g) {
            if ((int) $g['acc_part'] === $accPart) {
                $visible += $counts['group'][(int) $g['group_id']] ?? 0;
            }
        }

        return $visible;
    }

    /**
     * سطر نصي للمسار: أسماء الآباء مفصولة بشَرطة مائلة / printable parent path.
     */
    private function pathName(array $parts): string
    {
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));

        return implode(' / ', array_reverse($parts));
    }

    /**
     * عقدة ورقة حساب مالي داخل الهيكل (تُستخدم لاستثناء «عملاء الكبودي» فقط).
     */
    private function chartLeafNode(int $accNo, array $chartBal, string $codePath, array $section, array $main, array $sub, ?int $groupId): ?array
    {
        $row = DB::connection('oracle')->selectOne("
            SELECT ACC_NO, ACC_NAME, BALANCE, LIMITED_BALANCE, STOPPING_ACC, SEQ,
                   TO_CHAR(ACC_DATE, 'YYYY-MM-DD') AS ACC_DATE
            FROM CHART_ACC WHERE ACC_NO = :no
        ", ['no' => $accNo]);
        if (! $row) {
            return null;
        }
        $r = (array) $row;
        $b = $chartBal[$accNo] ?? $this->emptyBucket();

        return [
            'id' => 'chart_'.$accNo,
            'level' => 'chart',
            'acc_no' => $accNo,
            'acc_part' => (int) ($sub['acc_part'] ?? null),
            'acc_sub' => (int) ($sub['acc_sub'] ?? null),
            'acc_no_section' => (int) ($section['acc_no'] ?? null),
            'group_id' => $groupId,
            'name' => ArabicEncoding::fixMojibake($r['ACC_NAME'] ?? $r['acc_name'] ?? ''),
            'parent_path' => $this->pathName([$section['acc_name'] ?? '', $main['sub_name'] ?? '', $sub['part_name'] ?? '']),
            'code_path' => $codePath.' / '.$accNo,
            'stored_balance' => (float) ($r['BALANCE'] ?? $r['balance'] ?? 0),
            'limited_balance' => isset($r['LIMITED_BALANCE']) || isset($r['limited_balance']) ? (float) ($r['LIMITED_BALANCE'] ?? $r['limited_balance']) : null,
            'stopping_acc' => isset($r['STOPPING_ACC']) || isset($r['stopping_acc']) ? (int) ($r['STOPPING_ACC'] ?? $r['stopping_acc']) : null,
            'seq' => isset($r['SEQ']) || isset($r['seq']) ? (int) ($r['SEQ'] ?? $r['seq']) : null,
            'acc_date' => $r['ACC_DATE'] ?? $r['acc_date'] ?? null,
            'cur_no' => $this->currencyOfChartNumber($accNo),
            'debit' => $b['debit'],
            'credit' => $b['credit'],
            'balance' => $b['net'],
            'movements' => $b['movements'],
            'account_count' => 1,
            'direct_count' => 0,
            'children_count' => 0,
            'has_children' => false,
            'children' => [],
        ];
    }

    /** مطابقة نصية للبحث في الأسماء/الأرقام داخل PHP (لتجنّب تعقيد LIKE العربي). */
    private function matchesQuery(string $q, string $name, string $code): bool
    {
        $needle = trim($q);
        if ($needle === '') {
            return false;
        }

        return mb_stripos($name, $needle) !== false || str_contains($code, $needle);
    }

    /**
     * توحيد نتيجة بحث هيكلية إلى نفس شكل عقد الشجرة.
     */
    private function searchNode(string $level, int $id, array $r, array $accMeta, array $subMeta, array $partMeta, array $groupMeta): array
    {
        $chain = [];
        $codes = [];

        if ($level === 'sub') {
            $part = $id;
            $sub = $partMeta[$part]['acc_sub'] ?? null;
            $codes[] = (string) $part;
            if ($sub !== null) {
                $codes[] = (string) $sub;
                $chain[] = $subMeta[$sub]['name'] ?? '';
                $acc = $subMeta[$sub]['acc_no'] ?? null;
                if ($acc !== null) {
                    $codes[] = (string) $acc;
                    $chain[] = $accMeta[$acc] ?? '';
                }
            }

            return [
                'id' => 'sub_'.$part, 'level' => 'sub', 'acc_part' => $part,
                'acc_sub' => $sub, 'acc_no' => $subMeta[$sub]['acc_no'] ?? null,
                'name' => $r['name'], 'parent_path' => $this->pathName($chain),
                'code_path' => implode(' / ', array_reverse($codes)),
                'stored_balance' => isset($r['balance']) ? (float) $r['balance'] : null,
                'cur_no' => isset($r['cur_no']) ? (int) $r['cur_no'] : null,
                'children_count' => 0, 'direct_count' => 0, 'has_children' => true, 'children' => [],
            ];
        }

        if ($level === 'main') {
            $sub = $id;
            $acc = $subMeta[$sub]['acc_no'] ?? (int) ($r['acc_no'] ?? 0);
            $codes = [(string) $sub, (string) $acc];
            $chain = [$accMeta[$acc] ?? ''];

            return [
                'id' => 'main_'.$sub, 'level' => 'main', 'acc_sub' => $sub, 'acc_no' => $acc,
                'name' => $r['name'], 'parent_path' => $this->pathName($chain),
                'code_path' => implode(' / ', array_reverse($codes)),
                'stored_balance' => isset($r['balance']) ? (float) $r['balance'] : null,
                'cur_no' => isset($r['cur_no']) ? (int) $r['cur_no'] : null,
                'children_count' => 0, 'direct_count' => 0, 'has_children' => true, 'children' => [],
            ];
        }

        if ($level === 'group') {
            $gid = $id;
            $part = $groupMeta[$gid]['acc_part'] ?? (int) ($r['acc_part'] ?? 0);
            $sub = $partMeta[$part]['acc_sub'] ?? null;
            $acc = $sub !== null ? ($subMeta[$sub]['acc_no'] ?? null) : null;
            $chain = [$accMeta[$acc] ?? '', $subMeta[$sub]['name'] ?? '', $partMeta[$part]['name'] ?? ''];

            return [
                'id' => 'group_'.$gid, 'level' => 'group', 'group_id' => $gid,
                'acc_part' => $part, 'acc_sub' => $sub, 'acc_no' => $acc,
                'name' => $r['name'], 'parent_path' => $this->pathName($chain),
                'code_path' => ($acc !== null ? $acc.' / ' : '').($sub !== null ? $sub.' / ' : '').($part !== null ? $part.' / ' : '').'G'.$gid,
                'stored_balance' => null, 'cur_no' => null,
                'children_count' => 0, 'direct_count' => 0, 'has_children' => true, 'children' => [],
            ];
        }

        return [
            'id' => 'acc_'.$id, 'level' => 'account', 'acc_no' => $id,
            'name' => $r['name'], 'parent_path' => '', 'code_path' => (string) $id,
            'stored_balance' => isset($r['balance']) ? (float) $r['balance'] : null,
            'cur_no' => isset($r['cur_no']) ? (int) $r['cur_no'] : null,
            'children_count' => 0, 'direct_count' => 0, 'has_children' => true, 'children' => [],
        ];
    }

    /** عدد عقد الشجرة (للـ meta). */
    private function countTree(array $nodes): int
    {
        $n = 0;
        foreach ($nodes as $node) {
            $n++;
            $n += $this->countTree($node['children'] ?? []);
        }

        return $n;
    }

    /** هل يوجد حساب فرعي بهذا الرقم؟ / ACC_MAIN primary key probe. */
    private function accMainExists(int $accSub): bool
    {
        return (bool) DB::connection('oracle')->selectOne(
            'SELECT ACC_SUB FROM ACC_MAIN WHERE ACC_SUB = :s', ['s' => $accSub]
        );
    }

    /**
     * تحديث صف هيكلي بأعمدة مختارة سلفاً (قائمة بيضاء) — يمنع أي حقن عبر أسماء الحقول.
     * Whitelisted structural update: only declared columns can ever reach the SQL.
     */
    private function updateStructure(string $table, string $pk, int $pkValue, Request $request, array $map, string $label)
    {
        try {
            $existing = DB::connection('oracle')->selectOne(
                "SELECT {$pk} FROM {$table} WHERE {$pk} = :pk", ['pk' => $pkValue]
            );
            if (! $existing) {
                return response()->json(['success' => false, 'message' => $label.' غير موجود'], 404);
            }

            $sets = [];
            $params = ['pk' => $pkValue];
            foreach ($map as $field => $spec) {
                if (! $request->has($field)) {
                    continue;
                }
                $sets[] = "{$spec['SET']} = :{$field}";
                $value = $request->input($field);
                if (! empty($spec['arabic'])) {
                    $value = ArabicEncoding::toOracleSearchChars((string) $value);
                }
                $params[$field] = $value;
            }

            if (empty($sets)) {
                return response()->json(['success' => false, 'message' => 'لا توجد بيانات للتعديل'], 400);
            }

            DB::connection('oracle')->update("UPDATE {$table} SET ".implode(', ', $sets)." WHERE {$pk} = :pk", $params);

            return response()->json(['success' => true, 'message' => 'تم تعديل '.$label.' بنجاح']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * تاريخ اختياري إلى ISO، أو null عند الغياب/الغيور غير المنطقي.
     */
    private function normaliseOptionalDate($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->parseStatementDateToIso($value);
    }
}
