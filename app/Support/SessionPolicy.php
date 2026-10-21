<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * سياسة الجلسات: تجديد انزلاقي صامت للنشيط، وانتهاء بالمهلة لغير النشيط (D7)
 * Session policy: silent sliding renewal while active, idle expiry when not (D7).
 *
 * لماذا لا نضبط `config('sanctum.expiration')`؟ لأن `Sanctum\Guard` يقارنها بـ `created_at`
 * أي بعمر مطلق ثابت: لو كانت 240 دقيقة لطُرد المستخدم النشيط كل أربع ساعات وهو يعمل —
 * وهذا مرفوض صراحة في المتطلب. لذلك يبقى `expiration => null` ونستخدم عمود `EXPIRES_AT`
 * الذي يحترمه Sanctum فعلاً، ونجدّده انزلاقياً.
 * Why not `sanctum.expiration`: Guard.php compares it against `created_at`, so any value becomes a
 * hard absolute age that would kick an active user out. We keep it null and drive `EXPIRES_AT`.
 *
 * المنطق / logic:
 *  1) معطّل (`IS_ACTIVATED != 1`)             ⇒ رفض 401
 *  2) `EXPIRES_AT` في الماضي                   ⇒ رفض 401 (انتهت مهلة الخمول)
 *  3) `CREATED_AT + session_absolute_days` مضى ⇒ رفض 401 (انتهى العمر المطلق)
 *  4) خلاف ذلك ⇒ قبول، وتحديث انزلاقي محدود الكتابة (`session_touch_threshold_minutes`)
 *
 * الرفض يمرّ بالمسار القائم في العميل: 401 ⇒ ErrorInterceptor ⇒ clearAll() ⇒ forceLogout() ⇒ /login
 * Rejection travels the existing client path: 401 → ErrorInterceptor → clearAll → forceLogout → /login.
 *
 * كل قراءات التواريخ تمرّ عبر `toDate()` لأن أوراكل تُعيد العمود الفارغ كـ `false` لا كـ null،
 * فالتعامل المباشر معها يُفجّر الطلبات (`Call to a member function isPast() on bool`).
 * Every date read goes through `toDate()`: Oracle returns a NULL column as `false`, not null, and
 * using it directly would 500 the request (`Call to a member function isPast() on bool`).
 *
 * الجلسة المنتهية تُحذف فور رفضها — لم تعد الصفوف الفائضة تتراكم في `PERSONAL_ACCESS_TOKENS`:
 * الرفض بسبب انتهاء الخمول أو العمر المطلق يمسح التوكن نفسه، و`purgeExpired()` ينظّف البقايا عند
 * الدخول وعند فتح شاشة الجلسات. الحذف «بأفضل جهد»: فشله لا يمنع الرفض ولا يُفجّر الطلب.
 * An expired session is deleted the moment it is rejected, so stale rows no longer pile up in
 * PERSONAL_ACCESS_TOKENS: idle/absolute rejection erases the token itself, and purgeExpired() sweeps
 * the leftovers on login and on the sessions screen. Every deletion is best-effort — a failed delete
 * neither blocks the rejection nor blows up the request.
 *
 * ⏰ كل حساب زمني هنا يمرّ بـ `dbNow()` لا `now()`. التطبيق مضبوط على `timezone => 'UTC'` بينما
 * `SYSDATE` في القاعدة `+03:00`، وكان الخلل مزدوجاً: كتابةُ `now()` تجعل الموعد يخزّن متأخراً ثلاث
 * ساعات فتبدو الجلسات «منتهية» على الشاشة التي تقارن بـ `SYSDATE`، وكتابةُ `expireTokens()` لـ
 * `SYSDATE - 1` تُقرأ هنا كأنها في المستقبل ثلاث ساعات فلا تُرفض الجلسة المنهية ولا يسقط المستخدم.
 * `dbNow()` يزيح ساعة PHP إلى زمن القاعدة بإزاحة تُقاس مرة واحدة في الطلب، فيتطابق Writer/Reader/
 * الشاشة/العميل على مرجع واحد دون تغيير `app.timezone` العام (كان سيُعيد تأريخ بقية الأقسام).
 * Every time read/write here goes through `dbNow()`, never `now()`: the app runs on UTC while Oracle
 * runs at +03:00, and that split broke both directions — PHP-written deadlines looked expired to the
 * screen's SYSDATE comparison, and a SYSDATE-1 termination looked like a future expiry here, so a
 * cut session kept working for three hours. dbNow() shifts PHP time onto the database clock with an
 * offset measured once per request, aligning writer, reader, sessions screen and client watchdog
 * without touching the global app.timezone (which would re-date every other module).
 */
class SessionPolicy
{
    /**
     * إزاحة ساعة PHP عن ساعة القاعدة بالثواني، محسوبة مرة واحدة لكل طلب
     * PHP-to-database clock offset in seconds, measured once per request
     *
     * `null` تعني لم تُقَس بعد، وقيمتها `0` عند تعذّر القراءة (اختبارات بلا أوراكل، أو عطل اتصال)
     * فالسلوك يرجع إلى ساعة PHP كما كان — لا يتعطّل الدخول بسبب قياس الإزاحة.
     * null means not measured yet; it falls back to 0 when the read fails (Oracle-less tests, or a
     * connection problem), so the policy degrades to plain PHP time rather than blocking sign-in.
     */
    private static ?int $clockOffset = null;

    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * «الآن» بمرجع زمن القاعدة — المصدر الوحيد للوقت داخل سياسة الجلسات
     * "Now" on the database clock — the single source of time inside the session policy
     */
    public static function dbNow(): Carbon
    {
        return Carbon::now()->addSeconds(self::clockOffset());
    }

    /**
     * تاريخ من أي شكل يعيده بهائق: null/false/نص/كائن تاريخ ⇒ Carbon أو null
     * Normalise a date from whatever the driver handed back: null/false/string/date object → Carbon|null
     */
    public static function toDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        return Carbon::parse((string) $value);
    }

    /**
     * فرق الثواني بين `SYSDATE` وساعة PHP، بإشارة موجبة حين تكون القاعدة متقدمة
     * Signed seconds from PHP time to SYSDATE, positive when the database clock is ahead
     *
     * `SYSDATE` تخدمها أوراكل كـ TEXT هنا لا كـ DATE، فالتحويل نصّي بصيغة ثابتة لا يعتمد على
     * `NLS_DATE_FORMAT` الخاص بالجلسة. والنتيجة تُخزَّن ساكنة لأن الطلب الواحد لا يحتاج أدق من
     * ثانية، ولأن قياسها عند كل `slide()` يعني استعلاماً إضافياً على كل طلب مُصادَق.
     * Oracle serves SYSDATE as text here, so it is formatted explicitly instead of trusting the
     * session's NLS_DATE_FORMAT. The result is cached per request: one request never needs finer
     * precision, and measuring inside slide() would add a query to every authenticated call.
     */
    private static function clockOffset(): int
    {
        if (self::$clockOffset !== null) {
            return self::$clockOffset;
        }

        try {
            $row = (array) DB::connection('oracle')->selectOne(
                "SELECT TO_CHAR(SYSDATE, 'YYYY-MM-DD HH24:MI:SS') AS DB_NOW FROM DUAL"
            );

            $dbNow = Carbon::parse((string) ($row['DB_NOW'] ?? $row['db_now'] ?? ''));

            self::$clockOffset = $dbNow->getTimestamp() - Carbon::now()->getTimestamp();
        } catch (\Throwable) {
            self::$clockOffset = 0;
        }

        return self::$clockOffset;
    }

    /**
     * تُستدعى من `Sanctum::$accessTokenAuthenticationCallback` بعد كل تحقق من التوكن
     * Invoked from `Sanctum::$accessTokenAuthenticationCallback` after each token lookup
     *
     * @param  PersonalAccessToken|null  $accessToken  التوكن المستخدم في الطلب
     * @param  bool  $isValid  نتيجة تحقق Sanctum الأولية
     */
    public function validate(mixed $accessToken, bool $isValid): bool
    {
        if (! $isValid || ! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        $user = $accessToken->tokenable;

        // الحساب المعطّل يفقد صلاحيته فوراً حتى لو كان التوكن صحيحاً
        // A deactivated account loses access immediately, even with a valid token
        if (! $user || (int) ($user->getAttribute('IS_ACTIVATED') ?? 0) !== 1) {
            return false;
        }

        $idleMinutes = max(1, $this->settings->int('session_idle_minutes', 240));
        $absoluteDays = max(1, $this->settings->int('session_absolute_days', 14));

        $expiresAt = self::toDate($accessToken->expires_at);

        // الجلسات القديمة المنشأة قبل هذا النظام (`EXPIRES_AT IS NULL`) تُقبل وتمنح مهلة كاملة
        // Legacy sessions without an expiry are accepted and granted a full idle window
        if ($expiresAt !== null && $expiresAt->isPast()) {
            $this->discard($accessToken);

            return false;
        }

        $createdAt = self::toDate($accessToken->created_at);

        if ($createdAt !== null && $createdAt->lt(self::dbNow()->subDays($absoluteDays))) {
            $this->discard($accessToken);

            return false;
        }

        $this->slide($accessToken, $idleMinutes);

        return true;
    }

    /**
     * توليد جلسة جديدة بمهلة كاملة: يُستدعى مباشرة بعد إصدار التوكن في الدخول العادي والبصمة
     * Prime a freshly issued token with the full idle window (called right after `createToken`).
     */
    public function prime(PersonalAccessToken $token): void
    {
        $this->slide($token, max(1, $this->settings->int('session_idle_minutes', 240)));
    }

    /**
     * تنظيف شامل للجلسات المنتهية: يُستدعى عند نجاح الدخول وعند فتح شاشة الجلسات النشطة
     * Sweep every expired session: called on successful sign-in and on the sessions screen
     *
     * «فور انتهائها» عملياً: الجلسة المنتهية تُمسح عند أول طلب يفشل بها (في `validate()`)، وهذا
     * التنظيف يكنس البقايا حتى لو لم يعد أصحابها يفتحون التطبيق أبداً.
     * "Immediately on expiry" in practice: the expired token is erased by the first failing request
     * (in `validate()`), and this sweep catches the leftovers even when their owners never reopen
     * the app.
     *
     * فرعان للانتهاء، وكلاهما على ساعة القاعدة:
     *  1) `EXPIRES_AT` تجاوز `SYSDATE` — نهاية خمول معروفة الموعد.
     *  2) `EXPIRES_AT` فارغ مع خمول يتجاوز المهلة — وهذه هي التراكمات القديمة تحديداً: كل جلسة
     *     حيّة تحصل على موعد من `slide()` في أول طلب بعد عمل السياسة، فصفّ بلا موعد يعني أنه لم
     *     يُستخدم منذ ذلك الحين. تركها يعني «جلسات لا تنتهي أبداً» تظهر على الشاشة «نشطة» للأبد.
     * Two expiry branches, both measured on the database clock:
     *  1) EXPIRES_AT already past SYSDATE — a dated idle expiry.
     *  2) EXPIRES_AT is NULL and the session has been idle beyond the window — this is precisely the
     *     accumulation: any live session gets a deadline from slide() on its first request after the
     *     policy went live, so a NULL deadline means it has not been used since. Leaving those rows
     *     puts sessions on the admin screen that never end and show as "active" forever.
     */
    public function purgeExpired(): void
    {
        $idleMinutes = max(1, $this->settings->int('session_idle_minutes', 240));

        try {
            PersonalAccessToken::query()
                ->where(function ($query) use ($idleMinutes) {
                    $query
                        ->where(function ($dated) {
                            $dated->whereNotNull('expires_at')
                                ->where('expires_at', '<', self::dbNow());
                        })
                        ->orWhere(function ($legacy) use ($idleMinutes) {
                            $legacy->whereNull('expires_at')
                                ->whereRaw(
                                    'COALESCE(last_used_at, created_at) < ?',
                                    [self::dbNow()->subMinutes($idleMinutes)]
                                );
                        });
                })
                ->delete();
        } catch (\Throwable) {
            // تنظيف بيتي بأفضل جهد: فشله لا يجوز أن يمنع الدخول أو يُفجّر القائمة
            // Best-effort housekeeping: a failed sweep must not block login or break the listing
        }
    }

    /**
     * محو التوكن المرفوض: الرفض قائم بصرف النظر عن نجاح الحذف
     * Erase the rejected token: the rejection stands whether or not the delete succeeds
     */
    private function discard(PersonalAccessToken $token): void
    {
        try {
            $token->delete();
        } catch (\Throwable) {
            // أوراكل قد ترفض الحذف في ظرف ما — نكتفي بالرفض ولا نُسقط الطلب باستثناء التنظيف
            // Oracle may refuse the delete in some corner — the 401 still stands, so swallow it
        }
    }

    /**
     * تجديد انزلاقي مع تحديد عدد الكتابات: تحديث واحد لكل جلسة كل عتبة دقائق تقريباً
     * Sliding renewal, write-throttled: roughly one update per session per threshold window.
     *
     * `saveQuietly` يتجاوز حدث النموذج، و `updated_at` يُكتب صراحة لأن عمود `UPDATED_AT`
     * لا يملك قيمة افتراضية في Oracle ولا يملؤه Sanctum تلقائياً هنا.
     * `saveQuietly` skips model events; `updated_at` is set explicitly because Oracle gives the
     * column no default and Sanctum does not fill it on this path.
     */
    private function slide(PersonalAccessToken $token, int $idleMinutes): void
    {
        $thresholdMinutes = max(1, $this->settings->int('session_touch_threshold_minutes', 5));

        // الموعد يُحسب على ساعة القاعدة لا ساعة PHP: العمود يقارَن لاحقاً بـ `SYSDATE` في شاشة
        // الجلسات، وبكتابة واحدة متّقة المرجع تصحّ القراءة والمقارنة والصدور للعميل معاً
        // The deadline is computed on the database clock, not PHP's: the column is later compared to
        // SYSDATE by the sessions screen, so one write reference keeps read, compare and header right.
        $newExpiry = self::dbNow()->addMinutes($idleMinutes);

        $expiresAt = self::toDate($token->expires_at);

        // لا كتابة إذا كان الانتهاء الحالي أبعد من الأفق الجديد مطروحاً منه العتبة
        // Skip the write when the current expiry already reaches beyond the new horizon
        if ($expiresAt !== null && $expiresAt->gte($newExpiry->copy()->subMinutes($thresholdMinutes))) {
            return;
        }

        $token->forceFill([
            'expires_at' => $newExpiry,
            'updated_at' => self::dbNow(),
        ])->saveQuietly();
    }
}
