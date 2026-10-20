<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;
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
 */
class SessionPolicy
{
    public function __construct(private readonly SettingsStore $settings) {}

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

        if ($createdAt !== null && $createdAt->lt(now()->subDays($absoluteDays))) {
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
     * التنظيف يكنس البقايا حتى لو لم يعد أصحابها يفتحون التطبيق أبداً. لا يمسّ الجلسات الحية ولا
     * القديمة بلا موعد (`EXPIRES_AT IS NULL`).
     * "Immediately on expiry" in practice: the expired token is erased by the first failing request
     * (in `validate()`), and this sweep catches the leftovers even when their owners never reopen
     * the app. Live sessions and legacy NULL-expiry rows are untouched.
     */
    public function purgeExpired(): void
    {
        try {
            PersonalAccessToken::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
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
        $newExpiry = now()->addMinutes($idleMinutes);

        $expiresAt = self::toDate($token->expires_at);

        // لا كتابة إذا كان الانتهاء الحالي أبعد من الأفق الجديد مطروحاً منه العتبة
        // Skip the write when the current expiry already reaches beyond the new horizon
        if ($expiresAt !== null && $expiresAt->gte($newExpiry->copy()->subMinutes($thresholdMinutes))) {
            return;
        }

        $token->forceFill([
            'expires_at' => $newExpiry,
            'updated_at' => now(),
        ])->saveQuietly();
    }
}
