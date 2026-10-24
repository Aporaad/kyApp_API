<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\PermissionCatalog;
use App\Support\PermissionGrantSync;
use App\Support\PermissionResolver;
use App\Support\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * متحكم إدارة مستخدمي التطبيق — القلب الإداري لنظام الصلاحيات (IBAC)
 * Application user administration controller — the administrative core of the IBAC system.
 *
 * يعمل على الجدول الإرثي `ACCOUNTS.APP_USERS` (نفسه الذي يقرأ منه النظام القديم) لذلك تُطبَّق
 * هنا قيود صارمة:
 * It writes to the legacy `ACCOUNTS.APP_USERS` table that the ERP also uses, so these rules apply:
 *  1) لا تُسرَّب كلمة المرور ولا `DEVICE_TOKEN` في أي رد (`$hidden` + اختيار أعمدة صريح).
 *     No password or `DEVICE_TOKEN` ever leaves the API (explicit column lists, never `SELECT *`).
 *  2) العربية تُكتب بـ `ArabicEncoding::toOracleSearchChars()` وتُقرأ بـ `cleanData()`.
 *     Arabic is written through `toOracleSearchChars()` and read back through `cleanData()`.
 *  3) حراس ضد قفل النظام: لا حذف للذات، لا تعطيل للذات، ولا إسواء لآخر مدير.
 *     Anti-lockout guards: no self-delete, no self-deactivate, never demote/delete the last admin.
 *  4) منع التصعيد: غير المدير لا يمنح ما لا يملك، ولا يرفع صفة الإدارة عن أحد.
 *     Anti-escalation: a non-admin cannot grant what it does not hold, nor set the admin flag.
 */
class UserAdminController extends Controller
{
    /**
     * حدود التصفح بأسلوب `CustomerController`/`WorkerController` (نفس شكل `meta` الذي يقرأه العميل)
     * Pagination bounds matching Customer/Worker, whose `meta` shape the Flutter client parses
     */
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;

    /**
     * الأعمدة المسموح الفرز بها — قائمة بيضاء صارمة، لا يُمرَّر أي نص خام إلى `ORDER BY`
     * Whitelisted sort columns; raw input never reaches `ORDER BY`
     */
    private const SORTABLE = [
        'user_id' => 'x.USER_ID',
        'user_name_ar' => 'x.USER_NAME_AR',
        'user_name_en' => 'x.USER_NAME_EN',
        'created_on' => 'x.CREATED_ON',
        'is_activated' => 'x.IS_ACTIVATED',
        'user_type' => 'x.USER_TYPE',
    ];

    /**
     * استعلام القائمة: عدّاد المنح + الفرز + قصّ الصفوف بـ `ROW_NUMBER` (11g لا تعرف OFFSET/FETCH)
     * Listing query: grant counter, deterministic ordering, and `ROW_NUMBER` slicing (11g has no OFFSET/FETCH)
     */
    private const LIST_SQL = <<<'SQL'
        SELECT *
          FROM (
                SELECT x.*, ROW_NUMBER() OVER (ORDER BY __ORDER__) AS RN
                  FROM (
                        SELECT u.USER_ID, u.USER_NAME, u.USER_NAME_EN, u.USER_NAME_AR,
                               u.USER_TYPE, u.BRANCH_NO, u.IS_ACTIVATED, u.IS_ADMIN, u.NOTE,
                               u.CASH_ACC_NO, u.ACC_NO, u.ALLOW_BANK_ACC, u.BIOMETRIC_ENABLED,
                               u.DEVICE_NAME, u.PLATFORM,
                               u.CREATED_ON, u.CREATED_BY, u.LAST_UPDATED_ON, u.LAST_UPDATED_BY,
                               NVL(g.GRANT_COUNT, 0) AS GRANT_COUNT
                          FROM ACCOUNTS.APP_USERS u
                          LEFT JOIN (
                                    SELECT USER_ID, COUNT(*) AS GRANT_COUNT
                                      FROM ACCOUNTS.APP_USERS_PERMISSIONS
                                     WHERE IS_ACTIVE = 1
                                       AND (EXPIRES_ON IS NULL OR EXPIRES_ON > SYSDATE)
                                     GROUP BY USER_ID
                                   ) g ON g.USER_ID = u.USER_ID
                         WHERE __WHERE__
                       ) x
               )
         WHERE RN > :rn_from AND RN <= :rn_to
         ORDER BY RN
        SQL;

    /**
     * عدّ نفس شروط القائمة لتوليد `meta.total`
     * Counts the same filtered set so `meta.total` matches the listing
     */
    private const COUNT_SQL = <<<'SQL'
        SELECT COUNT(*) AS TOTAL
          FROM ACCOUNTS.APP_USERS u
         WHERE __WHERE__
        SQL;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly PermissionGrantSync $grants,
        private readonly ActivityLogger $activity,
        private readonly SettingsStore $settings,
    ) {}

    /**
     * قائمة المستخدمين مع البحث والفلترة والفرز والتصفح
     * List users with search, filters, sorting and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->input('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $page = max((int) $request->input('page', 1), 1);

        try {
            [$where, $bindings] = $this->conditions($request);

            $total = (int) $this->scalar(
                str_replace('__WHERE__', $where, self::COUNT_SQL),
                $bindings,
                'TOTAL'
            );

            $rows = DB::connection('oracle')->select(
                str_replace(['__ORDER__', '__WHERE__'], [$this->orderBy($request), $where], self::LIST_SQL),
                array_merge($bindings, ['rn_from' => ($page - 1) * $limit, 'rn_to' => $page * $limit])
            );

            $data = array_map(
                function ($row): array {
                    return $this->shape((array) $row);
                },
                $rows
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب المستخدمين / Users retrieved.',
                'data' => $data,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة المستخدمين', $e);
        }
    }

    /**
     * بيانات مستخدم واحد مع منحَه وآخر نشاط له
     * A single user with its grants and latest activity
     */
    public function show(Request $request, int $userId): JsonResponse
    {
        $row = $this->findUser($userId);

        if ($row === null) {
            return $this->notFound($userId);
        }

        try {
            $granted = $this->grants->currentFor($userId);
            $latest = DB::connection('oracle')->selectOne(
                'SELECT MAX(CREATED_ON) AS LAST_ACTIVITY FROM ACCOUNTS.APP_USER_ACTIVITY
                  WHERE USER_ID = :user_id OR TARGET_USER_ID = :user_id',
                ['user_id' => $userId]
            );

            $data = $this->shape($row);
            $data['granted_codes'] = $granted['codes'];
            $data['granted_ids'] = $granted['ids'];
            $data['last_activity_on'] = $this->stringOf((array) $latest, 'LAST_ACTIVITY');

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب بيانات المستخدم', $e);
        }
    }

    /**
     * إنشاء مستخدم جديد ومنحُه صلاحيات في نفس الذرّية
     * Create a user and grant its permissions inside one transaction
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $minLength = max(4, $this->settings->int('password_min_length', 4));

        $validator = Validator::make($request->all(), [
            'user_name_en' => ['required', 'string', 'min:2', 'max:50'],
            'user_name_ar' => ['nullable', 'string', 'max:120'],
            'user_password' => ['required', 'string', 'min:'.$minLength, 'max:100'],
            'user_type' => ['nullable', 'integer', 'in:1,2'],
            'is_activated' => ['nullable', 'integer', 'in:0,1'],
            'is_admin' => ['nullable', 'integer', 'in:0,1'],
            'branch_no' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:100'],
            'cash_acc_no' => ['nullable', 'integer', 'min:0'],
            'allow_bank_acc' => ['nullable', 'integer', 'in:0,1'],
            'acc_no' => ['nullable', 'integer', 'min:0'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:60'],
        ], [
            'user_name_en.required' => 'اسم المستخدم الإنجليزي مطلوب / Username is required.',
            'user_name_en.max' => 'اسم المستخدم أطول من 50 محرفاً / Username is too long.',
            'user_password.required' => 'كلمة المرور مطلوبة / Password is required.',
            'user_password.min' => "كلمة المرور أقصر من {$minLength} محارف / Password is too short.",
            'user_type.in' => 'نوع المستخدم يجب أن يكون 1 (موظف) أو 2 (عميل) / User type must be 1 or 2.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة / Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $input = $request->all();
        $username = $this->normalizeUsername((string) $input['user_name_en']);

        // الاسم معرّف الدخول: تفرّد بحالة غير حساسة (UPPER) كما يفعل تسجيل الدخول
        // The username is the login key: uniqueness is case-insensitive, exactly like login lookup.
        if ($this->usernameTaken($username) !== null) {
            return response()->json([
                'success' => false,
                'message' => 'اسم المستخدم مستخدم بالفعل / Username already exists.',
                'errors' => ['user_name_en' => [$username]],
            ], 409);
        }

        // راية الإدارة: لا يرفعها إلا مدير يملك `users.admin_flag_manage` (منع التصعيد)
        // Admin flag: only an admin holding `users.admin_flag_manage` may raise it.
        $wantsAdmin = (int) ($input['is_admin'] ?? 0) === 1;

        if ($wantsAdmin && ! $this->maySetAdminFlag($actor)) {
            return $this->denied('users.admin_flag_manage', 'لا تملك صلاحية تعيين صفة مدير النظام / Only an administrator may grant admin status.');
        }

        [$grantIds, $invalid] = $this->prepareGrants($actor, $input['permissions'] ?? []);

        if ($invalid !== []) {
            return $invalid;
        }

        try {
            $userId = $this->nextUserId();

            DB::connection('oracle')->transaction(function () use ($userId, $username, $input, $actor, $grantIds): void {
                DB::connection('oracle')->insert(
                    'INSERT INTO ACCOUNTS.APP_USERS
                        (USER_ID, USER_NAME, USER_NAME_EN, USER_NAME_AR, USER_PASSWORD, IS_ACTIVATED,
                         IS_ADMIN, USER_TYPE, BRANCH_NO, NOTE, CASH_ACC_NO, ALLOW_BANK_ACC, ACC_NO,
                         CREATED_ON, CREATED_BY)
                     VALUES
                        (:user_id, :user_name, :user_name_en, :user_name_ar, :user_password, :is_activated,
                         :is_admin, :user_type, :branch_no, :note, :cash_acc_no, :allow_bank_acc, :acc_no,
                         SYSDATE, :created_by)',
                    [
                        'user_id' => $userId,
                        // العمود الإرثي `USER_NAME` يحمل الاسم اللاتيني نفسه لأن النظام القديم يقرأه
                        // The legacy `USER_NAME` column mirrors the latin name the old system reads
                        'user_name' => $username,
                        'user_name_en' => $username,
                        'user_name_ar' => ArabicEncoding::toOracleSearchChars($this->textOf($input, 'user_name_ar')),
                        // كلمة المرور نص صريح مؤقتاً (D2) توافقاً مع أصفار النظام القديم
                        // Password stays plaintext for now (D2) to match legacy rows
                        'user_password' => (string) $input['user_password'],
                        'is_activated' => (int) ($input['is_activated'] ?? 1),
                        'is_admin' => (int) ($input['is_admin'] ?? 0),
                        'user_type' => (int) ($input['user_type'] ?? 1),
                        'branch_no' => $this->intOrNull($input['branch_no'] ?? null),
                        'note' => ArabicEncoding::toOracleSearchChars($this->textOf($input, 'note')),
                        'cash_acc_no' => $this->intOrNull($input['cash_acc_no'] ?? null),
                        'allow_bank_acc' => (int) ($input['allow_bank_acc'] ?? 0),
                        'acc_no' => $this->intOrNull($input['acc_no'] ?? null),
                        'created_by' => $actor ? $this->idOf($actor) : null,
                    ]
                );

                foreach ($grantIds as $permissionId) {
                    DB::connection('oracle')->insert(
                        'INSERT INTO ACCOUNTS.APP_USERS_PERMISSIONS
                            (USER_ID, PERMISSION_ID, GRANTED_BY)
                         VALUES (:user_id, :permission_id, :granted_by)',
                        ['user_id' => $userId, 'permission_id' => $permissionId, 'granted_by' => $actor ? $this->idOf($actor) : null]
                    );
                }
            });

            $this->activity->success('users.create', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'target_user_id' => $userId,
                'module_code' => 'users',
                'entity_type' => 'APP_USERS',
                'entity_id' => $userId,
                'description_ar' => 'إنشاء مستخدم «'.$username.'» مع '.count($grantIds).' صلاحية',
            ], $request);

            $row = $this->findUser($userId);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء المستخدم بنجاح / User created.',
                'data' => $row === null ? ['user_id' => $userId] : $this->shape($row),
            ], 201);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء المستخدم', $e);
        }
    }

    /**
     * تعديل بيانات مستخدم، والمنح تُحدَّث إن أُرسلت صراحة
     * Update a user; permissions are replaced only when explicitly sent
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $row = $this->findUser($userId);

        if ($row === null) {
            return $this->notFound($userId);
        }

        $input = $request->all();
        $self = $actor && $this->idOf($actor) === $userId;

        // تعديل الذات لا يغيّر نوع الحساب ولا صفة الإدارة: يمنع التصعيد الذاتي ومهزل آخر مدير
        // Self-edits may not change account type or admin flag: blocks self-escalation.
        if ($self && (array_key_exists('user_type', $input) || array_key_exists('is_admin', $input))) {
            return $this->denied('users.update', 'لا يمكنك تعديل نوع حسابك أو صفة الإدارة لنفسك / You cannot change your own account type or admin flag.');
        }

        if (array_key_exists('is_admin', $input) && (int) $input['is_admin'] !== $this->intOf($row, 'IS_ADMIN') && ! $this->maySetAdminFlag($actor)) {
            return $this->denied('users.admin_flag_manage', 'لا تملك صلاحية تغيير صفة مدير النظام / Only an administrator may change admin status.');
        }

        // إسواء آخر مدير يقفل النظام ⇒ مرفوض دائماً
        // Demoting the last administrator would lock the system out — always refused.
        if (array_key_exists('is_admin', $input)
            && (int) ($input['is_admin'] ?? 0) === 0
            && $this->intOf($row, 'IS_ADMIN') === 1
            && $this->adminCount() <= 1) {
            return $this->denied('users.admin_flag_manage', 'لا يمكن إلغاء صفة الإدارة عن آخر مدير في النظام / Cannot remove the last administrator.');
        }

        $minLength = max(4, $this->settings->int('password_min_length', 4));

        $validator = Validator::make($input, [
            'user_name_en' => ['sometimes', 'string', 'min:2', 'max:50'],
            'user_name_ar' => ['nullable', 'string', 'max:120'],
            'user_password' => ['nullable', 'string', 'min:'.$minLength, 'max:100'],
            'user_type' => ['sometimes', 'integer', 'in:1,2'],
            'is_activated' => ['nullable', 'integer', 'in:0,1'],
            'is_admin' => ['nullable', 'integer', 'in:0,1'],
            'branch_no' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:100'],
            'cash_acc_no' => ['nullable', 'integer', 'min:0'],
            'allow_bank_acc' => ['nullable', 'integer', 'in:0,1'],
            'acc_no' => ['nullable', 'integer', 'min:0'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:60'],
        ], [
            'user_password.min' => "كلمة المرور أقصر من {$minLength} محارف / Password is too short.",
            'user_type.in' => 'نوع المستخدم يجب أن يكون 1 (موظف) أو 2 (عميل) / User type must be 1 or 2.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة / Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $username = array_key_exists('user_name_en', $input)
            ? $this->normalizeUsername((string) $input['user_name_en'])
            : null;

        if ($username !== null && $username !== $this->stringOf($row, 'USER_NAME_EN')) {
            $taken = $this->usernameTaken($username);

            if ($taken !== null && (int) $this->stringOf($taken, 'USER_ID') !== $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'اسم المستخدم مستخدم بالفعل / Username already exists.',
                    'errors' => ['user_name_en' => [$username]],
                ], 409);
            }
        }

        [$grantIds, $invalid] = $this->prepareGrants($actor, $input['permissions'] ?? null);

        if ($invalid !== []) {
            return $invalid;
        }

        // الأعمدة القابلة للكتابة تباعاً: لا كتابة لعمود لم يُرسل، فلا تُمحى بيانات خطأ
        // Columns are set one by one: an absent input never blanks a stored value.
        $sets = [];
        $bindings = ['user_id' => $userId, 'updated_by' => $actor ? $this->idOf($actor) : null];

        if ($username !== null) {
            $sets[] = 'USER_NAME = :user_name';
            $sets[] = 'USER_NAME_EN = :user_name_en';
            $bindings['user_name'] = $username;
            $bindings['user_name_en'] = $username;
        }

        $plainWrites = [
            'user_name_ar' => 'USER_NAME_AR',
            'branch_no' => 'BRANCH_NO',
            'cash_acc_no' => 'CASH_ACC_NO',
            'allow_bank_acc' => 'ALLOW_BANK_ACC',
            'acc_no' => 'ACC_NO',
            'is_activated' => 'IS_ACTIVATED',
            'is_admin' => 'IS_ADMIN',
            'user_type' => 'USER_TYPE',
            'note' => 'NOTE',
        ];

        foreach ($plainWrites as $field => $column) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            // العربية تمرّ بطبقة الترميز الصحيحة، والرقمية تُحوَّل بحذر
            // Arabic goes through the correct encoding path; numerics are cast carefully.
            $value = in_array($field, ['user_name_ar', 'note'], true)
                ? ArabicEncoding::toOracleSearchChars($this->textOf($input, $field))
                : $this->intOrNull($input[$field]);

            $sets[] = "{$column} = :{$field}";
            $bindings[$field] = $value;
        }

        if (! empty($input['user_password'])) {
            $sets[] = 'USER_PASSWORD = :user_password';
            $bindings['user_password'] = (string) $input['user_password'];
        }

        $grantsChanged = $grantIds !== null;

        if ($sets === [] && ! $grantsChanged) {
            return response()->json([
                'success' => false,
                'message' => 'لم يُرسل أي تعديل / Nothing to update.',
            ], 400);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($userId, $sets, $bindings, $grantsChanged, $grantIds, $actor): void {
                if ($sets !== []) {
                    DB::connection('oracle')->update(
                        'UPDATE ACCOUNTS.APP_USERS
                            SET '.implode(', ', $sets).',
                                LAST_UPDATED_ON = SYSDATE,
                                LAST_UPDATED_BY = :updated_by
                          WHERE USER_ID = :user_id',
                        $bindings
                    );
                }

                if ($grantsChanged) {
                    $this->grants->replace($userId, $grantIds, $actor ? $this->idOf($actor) : null);
                }
            });

            // تعطيل الحساب يدفَن جلساته فوراً حتى لا يبقى توكن صالحاً بغير صلاحية
            // Deactivating an account kills its sessions immediately: a live token must not outlive access.
            $becameInactive = array_key_exists('is_activated', $input) && (int) $input['is_activated'] === 0;

            if ($becameInactive || $grantsChanged) {
                $this->terminateSessions($userId);
            }

            $this->activity->success('users.update', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'target_user_id' => $userId,
                'module_code' => 'users',
                'entity_type' => 'APP_USERS',
                'entity_id' => $userId,
                'description_ar' => $this->describeUpdate($input, $grantsChanged, $userId),
            ], $request);

            $row = $this->findUser($userId);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث المستخدم / User updated.',
                'data' => $row === null ? null : $this->shape($row),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تحديث المستخدم', $e);
        }
    }

    /**
     * تبديل حالة تفعيل المستخدم (0 ↔ 1)
     * Toggle the user's activated flag (0 ↔ 1)
     */
    public function toggle(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $row = $this->findUser($userId);

        if ($row === null) {
            return $this->notFound($userId);
        }

        if ($actor && $this->idOf($actor) === $userId) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك تعطيل حسابك الحالي / You cannot deactivate your own account.',
            ], 422);
        }

        $isActivated = $this->intOf($row, 'IS_ACTIVATED');
        $becomingAdminLess = $isActivated === 1 && $this->intOf($row, 'IS_ADMIN') === 1 && $this->adminCount() <= 1;

        // تعطيل آخر مدير = لا أحد يستطيع الدخول لاحقاً ⇒ مرفوض
        // Deactivating the last admin means nobody can sign in later — refused.
        if ($becomingAdminLess) {
            return $this->denied('users.toggle', 'لا يمكن تعطيل آخر مدير في النظام / Cannot deactivate the last administrator.');
        }

        $next = $isActivated === 1 ? 0 : 1;

        try {
            DB::connection('oracle')->update(
                // أسماء المُربَطات تتجنّب الكلمات المحجوزة: `:by` و`:next` كانا ينتجان ORA-01745
                // Bind names avoid Oracle reserved words: :by and :next used to fail with ORA-01745.
                'UPDATE ACCOUNTS.APP_USERS
                    SET IS_ACTIVATED = :activated, LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :updated_by
                  WHERE USER_ID = :user_id',
                ['activated' => $next, 'updated_by' => $actor ? $this->idOf($actor) : null, 'user_id' => $userId]
            );

            // التعطيل يُنهي الجلسات؛ التفعيل لا يُنشئ شيئاً — المستخدم يسجّل دخوله من جديد
            // Deactivation ends sessions; activation creates none — the user signs in again.
            $sessions = $next === 0 ? $this->terminateSessions($userId) : 0;

            $this->activity->success('users.toggle', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'target_user_id' => $userId,
                'module_code' => 'users',
                'entity_type' => 'APP_USERS',
                'entity_id' => $userId,
                'description_ar' => ($next === 1 ? 'تفعيل' : 'تعطيل').' المستخدم «'.$this->stringOf($row, 'USER_NAME_EN').'»'.$this->sessionsNote($sessions),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => $next === 1 ? 'تم تفعيل المستخدم / User activated.' : 'تم تعطيل المستخدم / User deactivated.',
                'data' => ['user_id' => $userId, 'is_activated' => $next, 'sessions_ended' => $sessions],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تبديل حالة المستخدم', $e);
        }
    }

    /**
     * حذف مستخدم مع منحِه وتوكناته
     * Delete a user together with its grants and tokens
     */
    public function destroy(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $row = $this->findUser($userId);

        if ($row === null) {
            return $this->notFound($userId);
        }

        if ($actor && $this->idOf($actor) === $userId) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك حذف حسابك الحالي / You cannot delete your own account.',
            ], 422);
        }

        if ($this->intOf($row, 'IS_ADMIN') === 1 && $this->adminCount() <= 1) {
            return $this->denied('users.delete', 'لا يمكن حذف آخر مدير في النظام / Cannot delete the last administrator.');
        }

        try {
            DB::connection('oracle')->transaction(function () use ($userId): void {
                // الترتيب مقصود: المنح ثم الجلسات ثم الحساب، فلا بقيّات تشير لمستخدم محذوف
                // Ordered on purpose: grants, then sessions, then the account — no orphan rows.
                DB::connection('oracle')->statement(
                    'DELETE FROM ACCOUNTS.APP_USERS_PERMISSIONS WHERE USER_ID = :user_id',
                    ['user_id' => $userId]
                );

                DB::connection('oracle')->statement(
                    "DELETE FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS
                      WHERE TOKENABLE_ID = :user_id AND TOKENABLE_TYPE = 'App\\Models\\User'",
                    ['user_id' => $userId]
                );

                DB::connection('oracle')->statement(
                    'DELETE FROM ACCOUNTS.APP_USERS WHERE USER_ID = :user_id',
                    ['user_id' => $userId]
                );
            });

            $this->permissions->forget($userId);

            $this->activity->success('users.delete', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'target_user_id' => $userId,
                'module_code' => 'users',
                'entity_type' => 'APP_USERS',
                'entity_id' => $userId,
                'description_ar' => 'حذف المستخدم «'.$this->stringOf($row, 'USER_NAME_EN').'» مع منحِه وجلساته',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف المستخدم / User deleted.',
                'data' => ['user_id' => $userId],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حذف المستخدم', $e);
        }
    }

    /**
     * سجل نشاط مستخدم (ما نفذه وما نُفِّذ عليه)
     * Activity trail for a user (what it did and what was done to it)
     */
    public function activity(Request $request, int $userId): JsonResponse
    {
        $limit = min(max((int) $request->input('limit', 50), 1), self::MAX_LIMIT);
        $page = max((int) $request->input('page', 1), 1);

        $bindings = ['user_id' => $userId, 'rn_from' => ($page - 1) * $limit, 'rn_to' => $page * $limit];

        try {
            $total = (int) $this->scalar(
                'SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.APP_USER_ACTIVITY
                  WHERE USER_ID = :user_id OR TARGET_USER_ID = :user_id',
                ['user_id' => $userId],
                'TOTAL'
            );

            $rows = DB::connection('oracle')->select(
                'SELECT *
                   FROM (
                         SELECT a.*, ROW_NUMBER() OVER (ORDER BY a.CREATED_ON DESC, a.ACTIVITY_ID DESC) AS RN
                           FROM ACCOUNTS.APP_USER_ACTIVITY a
                          WHERE a.USER_ID = :user_id OR a.TARGET_USER_ID = :user_id
                        )
                  WHERE RN > :rn_from AND RN <= :rn_to
                  ORDER BY RN',
                $bindings
            );

            $data = array_map(
                function ($row): array {
                    return $this->shapeActivity((array) $row);
                },
                $rows
            );

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب سجل النشاط', $e);
        }
    }

    // ---------------------------------------------------------------------
    // المساعدات الداخلية / internal helpers
    // ---------------------------------------------------------------------

    /**
     * بناء شروط WHERE من معامَلات الفلترة والبحث مع مربوطاتها
     * Build the WHERE clause from the search/filter params, with its bindings
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(Request $request): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        $status = (string) $request->input('status', 'all');

        if ($status === 'active') {
            $where[] = 'u.IS_ACTIVATED = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.IS_ACTIVATED = 0';
        }

        $userType = (string) $request->input('user_type', 'all');

        if (in_array($userType, ['1', '2'], true)) {
            $bindings['user_type'] = (int) $userType;
            $where[] = 'u.USER_TYPE = :user_type';
        }

        if ($request->boolean('admin_only')) {
            $where[] = 'u.IS_ADMIN = 1';
        }

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            // البحث يغطي الوجهين: الاسم اللاتيني والاسم العربي ببايتاته المخزّنة كما كُتب
            // Search covers both forms: the latin name and the Arabic name as its stored bytes
            $bindings['search_en'] = '%'.strtoupper($search).'%';
            $bindings['search_ar'] = '%'.ArabicEncoding::toOracleSearchChars($search).'%';
            $bindings['search_raw'] = '%'.$search.'%';

            $clause = '(UPPER(u.USER_NAME_EN) LIKE :search_en
                     OR u.USER_NAME_AR LIKE :search_ar
                     OR u.USER_NAME_AR LIKE :search_raw
                     OR u.USER_NAME LIKE :search_raw';

            if (is_numeric($search)) {
                $bindings['search_id'] = (int) $search;
                $clause .= ' OR u.USER_ID = :search_id';
            }

            $where[] = $clause.')';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * عبارة الفرز من القائمة البيضاء فقط، مع تسلسل ثابت عند التساوي
     * Ordering expression built only from the whitelist, with a stable tie-break
     */
    private function orderBy(Request $request): string
    {
        $column = self::SORTABLE[(string) $request->input('sort', 'user_id')] ?? self::SORTABLE['user_id'];
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // الفرز بالاسم العربي يكون على المحارف المخزّنة (CP1256) كما في بقية الشاشات
        // Arabic sorting compares the stored CP1256 bytes, like every other list here.
        return $column.' '.$direction.', x.USER_ID ASC';
    }

    /**
     * صف واحد من `APP_USERS` بلا أي عمود حساس
     * One `APP_USERS` row without any sensitive column
     *
     * @return array<string, mixed>|null
     */
    private function findUser(int $userId): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT USER_ID, USER_NAME, USER_NAME_EN, USER_NAME_AR, USER_TYPE, BRANCH_NO,
                    IS_ACTIVATED, IS_ADMIN, NOTE, CASH_ACC_NO, ACC_NO, ALLOW_BANK_ACC, BIOMETRIC_ENABLED,
                    DEVICE_NAME, PLATFORM, CREATED_ON, CREATED_BY, LAST_UPDATED_ON, LAST_UPDATED_BY
               FROM ACCOUNTS.APP_USERS
              WHERE USER_ID = :user_id',
            ['user_id' => $userId]
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * هل الاسم مستخدم؟ يعيد صف التعارض أو null
     * Is the username taken? Returns the conflicting row or null
     *
     * @return array<string, mixed>|null
     */
    private function usernameTaken(string $username): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT USER_ID, USER_NAME_EN FROM ACCOUNTS.APP_USERS
              WHERE UPPER(USER_NAME_EN) = UPPER(:username)',
            ['username' => $username]
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * المعرّف التالي من التسلسل المخصص لـ `APP_USERS` (بلا محفِّز لأن النظام القديم يُدخل على الجدول)
     * Next id from the dedicated sequence (no trigger, since the legacy ERP inserts into this table)
     */
    private function nextUserId(): int
    {
        $row = (array) DB::connection('oracle')->selectOne('SELECT SEQ_APP_USERS_ID.NEXTVAL AS ID FROM DUAL');

        return (int) ($row['ID'] ?? $row['id'] ?? 0);
    }

    /**
     * عدد مديري النظام — أساس كل حارس «آخر مدير»
     * How many system administrators exist — the basis of every last-admin guard
     */
    private function adminCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) AS TOTAL FROM ACCOUNTS.APP_USERS WHERE IS_ADMIN = 1', [], 'TOTAL');
    }

    /**
     * التحقق من أكواد المنح وتصفيتها بمنع التصعيد
     * Validate requested grant codes and apply the anti-escalation filter.
     *
     * `null` تعني «لم تُرسل قائمة» فلا تلمس المنح الحالية.
     * A `null` list means "not sent", so current grants stay untouched.
     *
     * @param  array<int, mixed>|null  $codes
     * @return array{0: list<int>|null, 1: JsonResponse|null}
     */
    private function prepareGrants(?User $actor, ?array $codes): array
    {
        // خانة «لا خطأ» هي مصفوفة فارغة لا null: المتحكمات تختبر `$invalid !== []`
        // The "no error" slot is an empty array, not null: callers test `$invalid !== []`.
        if ($codes === null) {
            return [null, []];
        }

        $resolved = $this->grants->resolve($codes);

        if ($resolved['unknown'] !== []) {
            return [null, response()->json([
                'success' => false,
                'message' => 'بعض أكواد الصلاحيات غير معروفة / Unknown permission codes',
                'errors' => ['unknown_codes' => $resolved['unknown']],
            ], 422)];
        }

        if ($resolved['inactive'] !== []) {
            return [null, response()->json([
                'success' => false,
                'message' => 'بعض الصلاحيات معطّلة ولا يمكن منحها / Inactive permissions cannot be granted',
                'errors' => ['inactive_codes' => $resolved['inactive']],
            ], 422)];
        }

        // منع التصعيد: غير المدير لا يمنح إلا ما يملكه فعلاً
        // Anti-escalation: a non-admin may only grant what it already holds.
        if ($actor !== null && ! $this->permissions->isAdmin($actor)) {
            $notHeld = [];

            foreach ($this->cleanCodes($codes) as $code) {
                if (! $this->permissions->can($actor, $code)) {
                    $notHeld[] = $code;
                }
            }

            if ($notHeld !== []) {
                return [null, $this->denied('users.permissions_manage', 'لا يمكنك منح صلاحيات لا تملكها / You cannot grant permissions you do not hold.', $notHeld)];
            }
        }

        return [$resolved['ids'], []];
    }

    /**
     * الأكواد المرسلة نظيفة وفريدة (نفس ما يحققه `PermissionGrantSync`)
     * The submitted codes, trimmed and de-duplicated (same cleaning the grant writer applies)
     *
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    private function cleanCodes(array $codes): array
    {
        $clean = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = trim($code);

            if ($code !== '' && ! in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        return $clean;
    }

    /**
     * تغيير صفة الإدارة مسموح لمدير يملك `users.admin_flag_manage`
     * Changing the admin flag requires an admin holding `users.admin_flag_manage`
     */
    private function maySetAdminFlag(?User $actor): bool
    {
        return $actor !== null
            && $this->permissions->isAdmin($actor)
            && $this->permissions->can($actor, 'users.admin_flag_manage');
    }

    /**
     * إنهاء كل جلسات المستخدم بدفع `EXPIRES_AT` إلى الماضي:رفض Sanctum التالي = 401 فوري
     * End every session for a user by pushing `EXPIRES_AT` into the past: the next request is 401.
     */
    private function terminateSessions(int $userId): int
    {
        return DB::connection('oracle')->update(
            "UPDATE ACCOUNTS.PERSONAL_ACCESS_TOKENS
                SET EXPIRES_AT = SYSDATE - 1, UPDATED_AT = SYSDATE
              WHERE TOKENABLE_ID = :user_id
                AND TOKENABLE_TYPE = 'App\\Models\\User'
                AND (EXPIRES_AT IS NULL OR EXPIRES_AT > SYSDATE)",
            ['user_id' => $userId]
        );
    }

    /**
     * تحويل الصف الخام إلى حمولة snake_case نظيفة وبعربية مقروءة
     * Turn a raw row into a clean snake_case payload with readable Arabic
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $row = $this->lowercaseKeys($row);
        $userType = (int) ($row['user_type'] ?? 1);

        return [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'user_name_en' => $this->fix($row['user_name_en'] ?? $row['user_name'] ?? null),
            'user_name_ar' => $this->fix($row['user_name_ar'] ?? null),
            'user_type' => $userType,
            'user_type_label' => $userType === 2 ? 'عميل' : 'موظف',
            'is_activated' => (int) ($row['is_activated'] ?? 0),
            'is_admin' => (int) ($row['is_admin'] ?? 0) === 1,
            'branch_no' => isset($row['branch_no']) ? (int) $row['branch_no'] : null,
            'note' => $this->fix($row['note'] ?? null),
            // حسابان مختلفان تماماً: cash_acc_no هو صندوق المستخدم الافتراضي (CASH_ACC_NO)،
            // وacc_no هو حسابه المالي في شجرة الحسابات (ACC_NO) الذي تُقرأ منه كشوفاته الشخصية.
            // Two distinct columns: cash_acc_no is the user's default treasury (CASH_ACC_NO),
            // acc_no is their ledger account (ACC_NO) that the personal statements resolve from.
            'cash_acc_no' => isset($row['cash_acc_no']) ? (int) $row['cash_acc_no'] : null,
            'acc_no' => isset($row['acc_no']) ? (int) $row['acc_no'] : null,
            'allow_bank_acc' => (int) ($row['allow_bank_acc'] ?? 0),
            'biometric_enabled' => (int) ($row['biometric_enabled'] ?? 0),
            'device_name' => $this->fix($row['device_name'] ?? null),
            'platform' => $row['platform'] ?? null,
            'grants_count' => (int) ($row['grant_count'] ?? 0),
            'created_on' => $row['created_on'] ?? null,
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'last_updated_on' => $row['last_updated_on'] ?? null,
            'last_updated_by' => isset($row['last_updated_by']) ? (int) $row['last_updated_by'] : null,
        ];
    }

    /**
     * توحيد شكل صف النشاط قبل الإرسال
     * Normalise an activity row before it leaves the API
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shapeActivity(array $row): array
    {
        $row = $this->lowercaseKeys($row);

        return [
            'activity_id' => (int) ($row['activity_id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'target_user_id' => isset($row['target_user_id']) ? (int) $row['target_user_id'] : null,
            'action_code' => $row['action_code'] ?? null,
            'module_code' => $row['module_code'] ?? null,
            'entity_type' => $row['entity_type'] ?? null,
            'entity_id' => $row['entity_id'] ?? null,
            'description_ar' => $this->fix($row['description_ar'] ?? null),
            'status' => $row['status'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'platform' => $row['platform'] ?? null,
            'created_on' => $row['created_on'] ?? null,
        ];
    }

    /**
     * وصف عربي مختصر لما تغيّر، يُخزَّن في سجل التدقيق
     * Short Arabic summary of what changed, stored in the audit trail
     *
     * @param  array<string, mixed>  $input
     */
    private function describeUpdate(array $input, bool $grantsChanged, int $userId): string
    {
        $labels = [
            'user_name_en' => 'الاسم',
            'user_name_ar' => 'الاسم العربي',
            'user_password' => 'كلمة المرور',
            'user_type' => 'نوع المستخدم',
            'is_activated' => 'حالة التفعيل',
            'is_admin' => 'صفة المدير',
            'branch_no' => 'الفرع',
            'note' => 'الملاحظة',
        ];

        $changed = [];

        foreach ($labels as $field => $label) {
            if (array_key_exists($field, $input)) {
                $changed[] = $label;
            }
        }

        if ($grantsChanged) {
            $changed[] = 'الصلاحيات';
        }

        $prefix = 'تحديث المستخدم رقم '.$userId;

        return $changed === [] ? $prefix : $prefix.': '.implode('، ', $changed);
    }

    /**
     * ذيل وصف النشاط بعدد الجلسات التي أُنهيت
     * Audit description suffix recording how many sessions were ended
     */
    private function sessionsNote(int $ended): string
    {
        return $ended > 0 ? ' — أُنهيت '.$ended.' جلسة' : '';
    }

    /**
     * رفض صلاحية برسالة ثنائية اللغة تُسمّي الصلاحية بالعربية
     * Permission denial with a bilingual message naming the permission in Arabic
     *
     * @param  list<string>|null  $codes
     */
    private function denied(string $code, string $message, ?array $codes = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message.' ('.PermissionCatalog::arabicName($code).')',
            'errors' => ['permission' => $codes ?? [$code]],
        ], 403);
    }

    /**
     * مستخدم غير موجود
     * Unknown user id
     */
    private function notFound(int $userId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "المستخدم رقم [{$userId}] غير موجود / User [{$userId}] not found.",
        ], 404);
    }

    /**
     * قراءة عمود واحد (عدّاد أو قيمة) من استعلام
     * Read a single column (a count or a value) from a query
     *
     * @param  array<string, mixed>  $bindings
     */
    private function scalar(string $sql, array $bindings, string $column): mixed
    {
        return $this->columnOf((array) DB::connection('oracle')->selectOne($sql, $bindings), $column);
    }

    /**
     * نص عمود من صف خام بحالة أعمدة غير مستقرة في أوراكل
     * Column text from a raw row, tolerating Oracle's unstable column casing
     *
     * @param  array<string, mixed>  $row
     */
    private function columnOf(array $row, string $column): mixed
    {
        return $row[$column] ?? $this->lowercaseKeys($row)[strtolower($column)] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringOf(array $row, string $column): ?string
    {
        $value = $this->columnOf($row, $column);

        return $value === null ? null : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intOf(array $row, string $column): int
    {
        return (int) ($this->stringOf($row, $column) ?? 0);
    }

    /**
     * نص اختياري من المدخل، أو null عند الغياب أو الفراغ
     * Optional input text, or null when absent or blank
     *
     * @param  array<string, mixed>  $input
     */
    private function textOf(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * اسم الدخول كما يتعامل معه تسجيل الدخول: مسافات مضغوطة وحالة محفوظة
     * The login name exactly as `login` treats it: collapsed whitespace, case preserved for storage
     */
    private function normalizeUsername(string $username): string
    {
        return trim(preg_replace('/\s+/', ' ', $username) ?? $username);
    }

    /**
     * إصلاح العربية مع تجاوز أي قيمة غير نصية
     * Repair Arabic, skipping any non-string value
     */
    private function fix(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return ArabicEncoding::fixMojibake($value);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function lowercaseKeys(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[strtolower((string) $key)] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function idOf(User $user): int
    {
        return (int) ($user->getAttribute('USER_ID') ?? $user->getKey());
    }

    private function errorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
