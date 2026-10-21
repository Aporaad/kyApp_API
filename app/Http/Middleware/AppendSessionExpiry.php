<?php

namespace App\Http\Middleware;

use App\Support\SessionPolicy;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * ترويسة «موعد انتهاء الجلسة» على كل رد مُصادق عليه
 * Attach the session deadline to every authenticated response.
 *
 * لماذا يحتاجها العميل؟ لأن الخادم يجدد `EXPIRES_AT` انزلاقياً مع كل طلب، فلا يعرف الجهاز موعد
 * نهايته الحالي إلا من الرد نفسه. بدونه لا يملك العميل إلا موعد تسجيل الدخول، فيطرد مستخدماً
 * نشيطاً بعد `session_idle_minutes` من دخوله رغم أن جلساته ممدّدة — والعكس أن خاملًا يبقى
 * مفتوح الشاشة حتى أول طلب. الترويسة تجعل الحارس المحلي يقرأ نفس الرقم الذي يحكم به الخادم.
 * Why the client needs it: the server slides EXPIRES_AT on every request, so the device can only
 * learn the *current* deadline from the response itself. Without it a client would fall back to its
 * sign-in time and evict an active user after one idle window, while an idle one would keep their
 * screen open until their next request. The header makes the local watchdog read the same number the
 * server rules on.
 *
 * لا تُقرأ القاعدة هنا أبداً: التوكن محلول مسبقاً في حلقة `auth:sanctum`، ف`currentAccessToken()`
 * رجوع للذاكرة. والقيمة نص `YYYY-MM-DD HH24:MI:SS` على ساعة القاعدة نفسها التي يكتب بها
 * `SessionPolicy`، وهي ساعة جهاز المستخدم، فلا تحويل منطقة زمنية في أي طرف.
 * The database is never queried here: the token was already resolved by `auth:sanctum`, so
 * `currentAccessToken()` is an in-memory read. The value is plain `YYYY-MM-DD HH24:MI:SS` text on the
 * same database clock SessionPolicy writes, which is the user's device clock — no timezone conversion
 * on either side.
 *
 * الترويسة معلوماتية للراحة فقط، لا للأمان: الرفض ما زال قرار الخادم عند كل طلب، ولا شيء في العميل
 * يوثق بموعد محلي ليمنح حقاً.
 * The header is a convenience, not a security boundary: the server still decides every request, and no
 * client-side deadline is ever trusted to grant access.
 */
class AppendSessionExpiry
{
    /**
     * اسم الترويسة — عرف بين الخادم وكلب حراسة الموعد في العميل
     * Header name — the contract between the API and the client's session watchdog
     */
    public const HEADER = 'X-Session-Expires-At';

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $response = $next($request);

        $deadline = $this->deadlineOf($request);

        if ($deadline !== null) {
            $response->headers->set(self::HEADER, $deadline);

            // بناء الويب (flutter run -d chrome) لا يقرأ ترويسة مخصصة إلا لو أُذنت صراحة؛ بقية
            // المنصات لا تفرّق، فالتصريح هنا يوفّر مساراً مختلفاً للعميل الواحد
            // Only the web build refuses to read a custom response header unless it is exposed
            // explicitly; other platforms do not care, so this keeps one client code path.
            $response->headers->set('Access-Control-Expose-Headers', self::HEADER);
        }

        return $response;
    }

    /**
     * موعد انتهاء التوكن المستخدم في الطلب، أو null إن لم تكن جلسة مُصادق عليها
     * The current request's session deadline, or null when there is no authenticated session
     *
     * الجلسة القديمة بلا `EXPIRES_AT` لا تُرسل ترويسة: لا موعد لها يُبلَّغ، والعميل يكتفي برفض
     * الخادم عند أول طلب بدل أن يخترع مهلة محلياً.
     * A legacy session with no EXPIRES_AT sends no header: there is no deadline to report, and the
     * client falls back to the server's own rejection instead of inventing a local window.
     */
    private function deadlineOf(Request $request): ?string
    {
        if (! $request->hasHeader('Authorization')) {
            return null;
        }

        try {
            $token = $request->user()?->currentAccessToken();

            if (! $token instanceof PersonalAccessToken) {
                return null;
            }

            return SessionPolicy::toDate($token->expires_at)?->toDateTimeString();
        } catch (\Throwable) {
            // ترويسة تجميلية: أي فشل هنا لا يجوز أن يُسقط الرد الناجح
            // A cosmetic header: nothing here may turn a good response into a failure
            return null;
        }
    }
}
