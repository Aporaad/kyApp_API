<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\SessionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * متحكم الجلسات النشطة — قائمة الجلسات وإنهاء أي جلسة فوراً
 * Active sessions controller: list every live session and cut any of them immediately.
 *
 * لا جدولجلسات منفصل: الجلسة هي صف في `PERSONAL_ACCESS_TOKENS`، وموعد نهايتها في `EXPIRES_AT`
 * الذي تجيّده `SessionPolicy` انزلاقياً. فالإنهاء هنا ليس حذف صف بل دفع `EXPIRES_AT` إلى الماضي:
 * أول طلب بعد ذلك يُرفض 401، فيمسحه العميل بمساره القائم (`ErrorInterceptor` ⇒ `clearAll()` ⇒ `/login`).
 * There is no sessions table: a session IS a row in PERSONAL_ACCESS_TOKENS whose EXPIRES_AT the
 * session policy slides. Terminating therefore means pushing that column into the past — the next
 * request 401s and the existing client path logs the device out.
 *
 * حذف صف التوكن كان ممكناً لكنه يفقد الأثر التدقيقي، ويترك `LAST_USED_AT` بلا سرد.
 * Deleting the row would also work but destroys the audit trail of that device's activity.
 *
 * ⚠️ لا يُقرأ `TOKEN` ولا `ABILITIES` أبداً: الأول بصمة التوكن الحساسة والثاني CLOB بلا فائدة للواجهة.
 * Never read TOKEN (the secret) or ABILITIES (a CLOB the screen does not need).
 */
class SessionAdminController extends Controller
{
    /**
     * حجم الصفحة الافتراضي وسقفها — نفس نمط شاشة المستخدمين لأنهما تُستهلكان في الواجهة نفسها
     * Default and maximum page size — same shape as the users screen, since both feed one admin UI
     */
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    /**
     * نوع المستند المخزّن على صفوف التوكن لمستخدمي التطبيق
     * The polymorphic type stored on token rows for application users
     */
    private const TOKENABLE_TYPE = 'App\\Models\\User';

    /**
     * حالة واحدة فقط مقبولة في الفلترة، وغيرها يعامل كـ «الكل»
     * Only these status filters change the query; anything else means "all"
     */
    private const STATUSES = ['active', 'expired', 'disabled'];

    /**
     * قائمة الأعمدة الآمنة — بلا `TOKEN` وبلا `ABILITIES`
     * The safe column list — no TOKEN, no ABILITIES
     *
     * `NAME` عمود CLOB، فيُقرأ بـ `DBMS_LOB.SUBSTR` لأن أوراكل 11g ترفض مقارنته مباشرة، والتواريخ
     * تُخرج بـ `TO_CHAR` بصيغة ثابتة بدل ما يفرضه `NLS_TIMESTAMP_FORMAT` من الجلسة.
     * NAME is a CLOB (11g cannot compare it inline) so it is substringed, and dates are formatted
     * explicitly instead of inheriting the session's NLS_TIMESTAMP_FORMAT.
     */
    private const SELECT_COLUMNS = <<<'SQL'
        SELECT t.ID,
               t.TOKENABLE_ID AS USER_ID,
               DBMS_LOB.SUBSTR(t.NAME, 60, 1) AS TOKEN_NAME,
               TO_CHAR(t.CREATED_AT, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
               TO_CHAR(NVL(t.LAST_USED_AT, t.CREATED_AT), 'YYYY-MM-DD HH24:MI:SS') AS LAST_ACTIVITY_AT,
               TO_CHAR(t.EXPIRES_AT, 'YYYY-MM-DD HH24:MI:SS') AS EXPIRES_AT,
               CASE
                 WHEN u.IS_ACTIVATED <> 1 THEN 'disabled'
                 WHEN t.EXPIRES_AT IS NOT NULL AND t.EXPIRES_AT <= SYSDATE THEN 'expired'
                 ELSE 'active'
               END AS SESSION_STATUS,
               u.USER_NAME_EN, u.USER_NAME_AR, u.USER_TYPE, u.IS_ADMIN, u.IS_ACTIVATED,
               u.BRANCH_NO, u.DEVICE_NAME, u.PLATFORM,
               ROW_NUMBER() OVER (ORDER BY NVL(t.LAST_USED_AT, t.CREATED_AT) DESC, t.ID DESC) AS RN
          FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS t
          JOIN ACCOUNTS.APP_USERS u ON u.USER_ID = t.TOKENABLE_ID
    SQL;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SessionPolicy $sessions,
    ) {}

    /**
     * قائمة الجلسات مع البحث والفلترة والترقيم — الأحدث نشاطاً أولاً
     * Session list with search, filters and pagination — most recently active first
     *
     * الترتيب ثابت (آخر نشاط ثم المعرّف) ولا يُقبل فرز من المعامَل: أي عمود يقبله المستخدم يدخل
     * العبارة نصاً، وقائمة بيضاء صغيرة لا تغطي تعبير `NVL` المستخدم هنا.
     * Ordering is fixed (last activity, then id) and is not a request parameter: any user-supplied
     * column would be spliced into the SQL, and a small whitelist cannot cover the NVL expression.
     *
     * القائمة تكنس المنتهي قبل أن تقرأ: «فور انتهائها» عملياً تحتاج من يفتح الشاشة، لأن صاحب الجلسة
     * المنتهية قد لا يفتح التطبيق مرة أخرى فلا يمرّ أبداً بمسار الرفض الذي يحذفها.
     * The listing sweeps expired tokens before reading: "immediately on expiry" still needs someone to
     * open the screen, because the owner of an expired session may never use the app again and so
     * never hits the rejection path that deletes it.
     */
    public function index(Request $request): JsonResponse
    {
        $this->sessions->purgeExpired();

        $limit = min(max((int) $request->input('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $page = max((int) $request->input('page', 1), 1);

        [$where, $bindings] = $this->conditions($request);

        $bindings['rn_from'] = ($page - 1) * $limit;
        $bindings['rn_to'] = $page * $limit;

        try {
            $total = $this->countMatching($where, $bindings);

            // طبقتان بلا `OFFSET/FETCH` لأن أوراكل 11g لا تعرفهما؛ `RN` يُحسب داخل الاستعلام الداخلي
            // Two nested levels because 11g has no OFFSET/FETCH: RN is computed in the inner query.
            $rows = DB::connection('oracle')->select(
                'SELECT *
                   FROM ('.$this->innerQuery($where).')
                  WHERE RN > :rn_from AND RN <= :rn_to
                  ORDER BY RN',
                $bindings
            );

            $currentTokenId = $this->currentTokenId($request);

            // الإصلاح مرة واحدة داخل `shape()`: تمرير الناتج على `cleanData()` ثانيةً يُفسد
            // العربية السليمة ويحوّل البدائل النصية («موظف، نشطة») إلى رموز
            // Repaired once inside shape(): a second cleanData() pass corrupts good Arabic and
            // mangles the literal labels («موظف», «نشطة») into symbols.
            $data = array_map(
                fn (array $row): array => $this->shape($row, $currentTokenId),
                array_map(static fn ($row): array => (array) $row, $rows)
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الجلسات النشطة / Sessions retrieved.',
                'data' => $data,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب الجلسات النشطة', $e);
        }
    }

    /**
     * إنهاء جلسة واحدة بمعرّف التوكن
     * Terminate a single session by token id
     */
    public function terminate(Request $request, int $tokenId): JsonResponse
    {
        $actor = $request->user();

        $session = $this->findSession($tokenId);

        if ($session === null) {
            return response()->json([
                'success' => false,
                'message' => "الجلسة رقم [{$tokenId}] غير موجودة / Session [{$tokenId}] not found.",
            ], 404);
        }

        $targetUserId = (int) $this->columnOf($session, 'USER_ID');

        try {
            $affected = $this->expireTokens('ID = :id', ['id' => $tokenId]);

            $this->activity->success('sessions.terminate', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'target_user_id' => $targetUserId,
                'module_code' => 'sessions',
                'entity_type' => 'PERSONAL_ACCESS_TOKENS',
                'entity_id' => $tokenId,
                'description_ar' => 'إنهاء جلسة المستخدم «'.$this->stringOf($session, 'USER_NAME_EN').'» (التوكن '.$tokenId.')',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إنهاء الجلسة / Session terminated.',
                'data' => ['token_id' => $tokenId, 'user_id' => $targetUserId, 'sessions_ended' => $affected],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في إنهاء الجلسة', $e);
        }
    }

    /**
     * إنهاء كل جلسات مستخدم — يُخرج جهازه من التطبيق في أول طلب
     * Terminate every session of a user: their devices are out on their next request.
     *
     * مسار منفصل عن `PATCH /users/{id}/toggle` لأن الإدارة قد تطرد جلسة دون تعطيل الحساب
     * (جهاز ضائع مثلاً)، والتعطيل وحده لا يكفي: الحارس يرفض الجلسات الجديدة لا القديمة.
     * Separate from the user toggle because an admin may evict a lost device without disabling the
     * account, and disabling alone would not cut sessions that are already sliding.
     */
    public function terminateUser(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();

        $user = DB::connection('oracle')->selectOne(
            'SELECT USER_ID, USER_NAME_EN, USER_NAME_AR FROM ACCOUNTS.APP_USERS WHERE USER_ID = :user_id',
            ['user_id' => $userId]
        );

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => "المستخدم رقم [{$userId}] غير موجود / User [{$userId}] not found.",
            ], 404);
        }

        $user = (array) $user;

        try {
            $affected = $this->expireTokens('TOKENABLE_ID = :user_id', ['user_id' => $userId]);

            // نفس رمز النشاط `sessions.terminate`: الكتالوج لا يملك رمزاً ثانياً، والوصف يفرّق المدى
            // Same action code as the single-session verb: the catalog has only one, the text differs.
            $this->activity->success('sessions.terminate', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'target_user_id' => $userId,
                'module_code' => 'sessions',
                'entity_type' => 'PERSONAL_ACCESS_TOKENS',
                'entity_id' => $userId,
                'description_ar' => 'إنهاء كل جلسات المستخدم «'.$this->stringOf($user, 'USER_NAME_EN').'» ('.$affected.' جلسة)',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم إنهاء جلسات المستخدم / User sessions terminated.',
                'data' => ['user_id' => $userId, 'sessions_ended' => $affected],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في إنهاء جلسات المستخدم', $e);
        }
    }

    // ---------------------------------------------------------------------
    // المساعدات الداخلية / internal helpers
    // ---------------------------------------------------------------------

    /**
     * شروط WHERE من الفلاتر مع مربوطاتها
     * Build the WHERE fragment from filters, with its bindings
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(Request $request): array
    {
        $where = ['t.TOKENABLE_TYPE = :type'];
        $bindings = ['type' => self::TOKENABLE_TYPE];

        $userId = (int) $request->input('user_id', 0);

        if ($userId > 0) {
            $bindings['user_id'] = $userId;
            $where[] = 't.TOKENABLE_ID = :user_id';
        }

        $status = (string) $request->input('status', 'all');

        if ($status === 'active') {
            $where[] = '(u.IS_ACTIVATED = 1 AND (t.EXPIRES_AT IS NULL OR t.EXPIRES_AT > SYSDATE))';
        } elseif ($status === 'expired') {
            $where[] = '(u.IS_ACTIVATED = 1 AND t.EXPIRES_AT IS NOT NULL AND t.EXPIRES_AT <= SYSDATE)';
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'u.IS_ACTIVATED <> 1';
        }

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            // الوجهان معاً: لاتيني بالمحارف وكبير، وعربي بوجهيه كما يُخزَّن في CP1256
            // Latin uppercased, Arabic in both stored and typed byte forms.
            $bindings['search_en'] = '%'.strtoupper($search).'%';
            $bindings['search_ar'] = '%'.ArabicEncoding::toOracleSearchChars($search).'%';
            $bindings['search_raw'] = '%'.$search.'%';

            $where[] = '(UPPER(u.USER_NAME_EN) LIKE :search_en
                      OR u.USER_NAME_AR LIKE :search_ar
                      OR u.USER_NAME_AR LIKE :search_raw
                      OR UPPER(NVL(u.DEVICE_NAME, \' \')) LIKE :search_en)';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * الاستعلام الداخلي بعد حصر الأعمدة الآمنة
     * The inner query, restricted to the safe column list
     */
    private function innerQuery(string $where): string
    {
        return self::SELECT_COLUMNS.' WHERE '.$where;
    }

    /**
     * العدّاد بنفس شروط القائمة — بلا مربوطات الترقيم
     * Count with the same filters, minus the pagination binds
     *
     * أوراكل ترفض مربوطاً لا يظهر في العبارة (`ORA-01008`)، لذلك تُحذف `rn_from`/`rn_to` هنا.
     * Oracle rejects a bind the statement never references (ORA-01008), so the pagination binds are dropped.
     *
     * @param  array<string, mixed>  $bindings
     */
    private function countMatching(string $where, array $bindings): int
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS TOTAL
               FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS t
               JOIN ACCOUNTS.APP_USERS u ON u.USER_ID = t.TOKENABLE_ID
              WHERE '.$where,
            array_diff_key($bindings, ['rn_from' => null, 'rn_to' => null])
        );

        return (int) ($this->columnOf($row === null ? [] : (array) $row, 'TOTAL') ?? 0);
    }

    /**
     * صف جلسة واحد بمعرّف توكن (للتأكد والاسم قبل الإنهاء)
     * One session row by token id — used to prove existence and name the audit entry
     *
     * @return array<string, mixed>|null
     */
    private function findSession(int $tokenId): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT t.ID AS TOKEN_ID, t.TOKENABLE_ID AS USER_ID,
                    DBMS_LOB.SUBSTR(t.NAME, 60, 1) AS TOKEN_NAME, u.USER_NAME_EN, u.USER_NAME_AR
               FROM ACCOUNTS.PERSONAL_ACCESS_TOKENS t
               JOIN ACCOUNTS.APP_USERS u ON u.USER_ID = t.TOKENABLE_ID
              WHERE t.ID = :token_id AND t.TOKENABLE_TYPE = :type',
            ['token_id' => $tokenId, 'type' => self::TOKENABLE_TYPE]
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * دفع انتهاء التوكنات إلى الماضي — العبارة الوحيدة التي تُنهي جلسة
     * Push token expiry into the past — the only statement that ends a session
     *
     * `SYSDATE - 1` لا `= NULL` لأن `NULL` يعني «بلا انتهاء» في نظرية Sanctum، فيعود التوكن صالحاً.
     * SYSDATE - 1, not NULL: Sanctum reads NULL as "never expires", which would reopen the session.
     */
    private function expireTokens(string $scope, array $bindings): int
    {
        $bindings['type'] = self::TOKENABLE_TYPE;

        return DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.PERSONAL_ACCESS_TOKENS
                SET EXPIRES_AT = SYSDATE - 1, UPDATED_AT = SYSDATE
              WHERE '.$scope.'
                AND TOKENABLE_TYPE = :type
                AND (EXPIRES_AT IS NULL OR EXPIRES_AT > SYSDATE)',
            $bindings
        );
    }

    /**
     * معرّف التوكن الجاري استخدامه الآن — لتعليم الجلسة في الواجهة بـ «هذا الجهاز»
     * The token id serving this request, so the UI can flag "this device"
     */
    private function currentTokenId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return (int) $token->getKey();
    }

    /**
     * شكل صف الجلسة: أسماء مقروءة + حالة + دقائق متبقية + تلميح «هذا الجهاز»
     * Shape a session row: readable names, status, minutes left, and the "this device" hint
     *
     * الدقائق المتبقية من `SessionPolicy::toDate()` نفسها التي تحمي قراءة التواريخ الفارغة، وتُقارن
     * بـ `SessionPolicy::dbNow()` لا `now()`: العمود مكتوب على ساعة القاعدة، فمقارنته بساعة PHP
     * كانت تُظهر «منتهية» و`minutes_left = 0` لكل جلسة قبل أوانها بثلاث ساعات.
     * Minutes remaining reuse SessionPolicy::toDate(), the reader that makes empty Oracle dates safe,
     * and compare against dbNow() rather than now(): the column is written on the database clock, so
     * comparing it to PHP's UTC clock marked every session expired three hours early.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row, ?int $currentTokenId): array
    {
        $tokenId = (int) ($this->columnOf($row, 'ID') ?? 0);
        $userType = (int) ($this->columnOf($row, 'USER_TYPE') ?? 1);
        $expiresAt = SessionPolicy::toDate($this->columnOf($row, 'EXPIRES_AT'));
        $status = (string) ($this->columnOf($row, 'SESSION_STATUS') ?? 'active');
        $branch = $this->columnOf($row, 'BRANCH_NO');

        return [
            'token_id' => $tokenId,
            'user_id' => (int) ($this->columnOf($row, 'USER_ID') ?? 0),
            'token_name' => $this->stringOf($row, 'TOKEN_NAME'),
            'user_name_en' => $this->fix($this->stringOf($row, 'USER_NAME_EN')),
            'user_name_ar' => $this->fix($this->stringOf($row, 'USER_NAME_AR')),
            'user_type' => $userType,
            'user_type_label' => $userType === 2 ? 'عميل' : 'موظف',
            'is_admin' => (int) ($this->columnOf($row, 'IS_ADMIN') ?? 0) === 1,
            'is_activated' => (int) ($this->columnOf($row, 'IS_ACTIVATED') ?? 0),
            'branch_no' => $branch === null ? null : (int) $branch,
            'device_name' => $this->fix($this->stringOf($row, 'DEVICE_NAME')),
            'platform' => $this->stringOf($row, 'PLATFORM'),
            'created_at' => $this->stringOf($row, 'CREATED_AT'),
            'last_activity_at' => $this->stringOf($row, 'LAST_ACTIVITY_AT'),
            'expires_at' => $this->stringOf($row, 'EXPIRES_AT'),
            'status' => $status,
            'status_label' => match ($status) {
                'expired' => 'منتهية المهلة',
                'disabled' => 'حساب معطّل',
                default => 'نشطة',
            },
            'minutes_left' => $expiresAt === null ? null : max(0, $expiresAt->diffInMinutes(SessionPolicy::dbNow(), false)),
            'is_current' => $currentTokenId !== null && $currentTokenId === $tokenId,
        ];
    }

    /**
     * إصلاح العربية مع تجاوز أي قيمة غير نصية
     * Repair Arabic, skipping any non-string value
     */
    private function fix(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ArabicEncoding::fixMojibake($value);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function columnOf(array $row, string $column): mixed
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        foreach ($row as $key => $value) {
            if (strtolower((string) $key) === strtolower($column)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringOf(array $row, string $column): ?string
    {
        $value = $this->columnOf($row, $column);

        return $value === null ? null : (string) $value;
    }

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
