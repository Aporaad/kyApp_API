<?php

namespace App\Http\Middleware;

use App\Support\PermissionCatalog;
use App\Support\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حارس الصلاحيات — يمنع الوصول لأي مسار لا يملك المستخدم صلاحيته (رفض افتراضي)
 * Permission guard — deny-by-default gate for any route the user is not granted.
 *
 * يُستخدم على المسارات بصيغة `->middleware('permission:customers.view')` داخل مجموعة `auth:sanctum`.
 * Registered as the `permission` alias; usage: `->middleware('permission:customers.view')`.
 *
 * تراتبية الرفض / rejection ladder:
 *  1) لا مستخدم مصادق عليه          ⇒ 401 — المسار يجب أن يكون خلف `auth:sanctum`
 *  2) صلاحية غير ممنوحة               ⇒ 403 — مع اسم الصلاحية بالعربية حتى يفهم المستخدم الناقص
 *  3) خلاف ذلك                         ⇒ تمرير الطلب
 * مدير النظام (`IS_ADMIN=1`) يمرّ دائماً لأن `PermissionResolver` يعيده قبل فحص المنح.
 * The system administrator always passes, because `PermissionResolver` short-circuits on `IS_ADMIN`.
 */
class EnsurePermission
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /**
     * @param  string  $code  كود الصلاحية المطلوبة / required permission code (module.action)
     */
    public function handle(Request $request, Closure $next, string $code): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'جلسة غير صالحة، يرجى تسجيل الدخول من جديد / Unauthenticated.',
            ], 401);
        }

        if (! $this->permissions->can($user, $code)) {
            return response()->json([
                'success' => false,
                'message' => 'لا تملك صلاحية «'.PermissionCatalog::arabicName($code).'» / Permission denied: '.$code,
                'errors' => ['permission' => [$code]],
            ], 403);
        }

        return $next($request);
    }
}
