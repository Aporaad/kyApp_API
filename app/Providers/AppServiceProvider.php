<?php

namespace App\Providers;

use App\Support\ActivityLogger;
use App\Support\PermissionGrantSync;
use App\Support\PermissionResolver;
use App\Support\SessionPolicy;
use App\Support\SettingsStore;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Throwable;

/**
 * ربط طبقات IBAC الداعمة وتفعيل سياسة الجلسات
 * Binds the IBAC support layer and activates the session policy.
 *
 * الكائنات المسجّلة singletons لأنها بلا حالة: تُبنى مرة لكل طلب وتُستخدم في كل مكان
 * (middleware، controllers، Sanctum guard) بتكلفة استعلام واحد مشترك عبر الكاش.
 * These four are singletons: stateless, resolved by middleware, controllers and the Sanctum
 * guard, so one shared build per request keeps their cache-backed reads to a single instance.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * تسجيل طبقات الصلاحيات والإعدادات والجلسات والنشاط
     * Register the permission, settings, session and activity layers
     */
    public function register(): void
    {
        $this->app->singleton(SettingsStore::class);
        $this->app->singleton(PermissionResolver::class);
        $this->app->singleton(PermissionGrantSync::class);
        $this->app->singleton(ActivityLogger::class);
        $this->app->singleton(SessionPolicy::class);
    }

    /**
     * تعليق سياسة الجلسة في Sanctum
     * Hook the session policy into Sanctum's token validation.
     *
     * Sanctum يمرر التوكن ونتيجته الأولية إلى هذا الخطاف بعد كل تحقق (`Guard::isValidAccessToken`)،
     * فنرفض الجلسات المنتهية أو القديمة جداً أو التابعة لحساب معطّل، ونجدّد الباقي انزلاقياً.
     * Sanctum hands the token and its own verdict to this callback after every check, letting us
     * reject idle/expired/disabled sessions while silently renewing the rest.
     *
     * الفشل الاستثنائي هنا يُرجع نتيجة Sanctum الأصلية بدل تفجير الطلب: لا نريد أن تتحول
     * عثرة في قراءة الإعدادات إلى خطأ 500 لكل المستخدمين.
     * An unexpected failure falls back to Sanctum's own verdict rather than 500-ing every request.
     */
    public function boot(): void
    {
        Sanctum::$accessTokenAuthenticationCallback = function (mixed $accessToken, bool $isValid): bool {
            try {
                return $this->app->make(SessionPolicy::class)->validate($accessToken, $isValid);
            } catch (Throwable $e) {
                report($e);

                return $isValid;
            }
        };
    }
}
