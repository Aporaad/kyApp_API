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
 * دعم «أَيٍّ من» / any-of support:
 *  - `permission:accounts.view`                         ⇒صلاحية واحدة (كما كان).
 *  - `permission:accounts.view,accounts.statement`      ⇒يكفي أن يملك المستخدم واحدة منها (OR).
 *    Laravel يمرّر الفواصل كوسائط متعدّدة، وندعم أيضاً الترميز بفاصلة داخل وسيط واحد.
 *    A route may be opened by ANY of the listed codes — this is what lets a statement-only user
 *    load the account picker (an endpoint that is otherwise gated on the module's `.view`).
 *
 * تراتبية الرفض / rejection ladder:
 *  1) لا مستخدم مصادق عليه          ⇒ 401 — المسار يجب أن يكون خلف `auth:sanctum`
 *  2) لا صلاحية من القائمة ممنوحة    ⇒ 403 — مع أسماء الصلاحيات المطلوبة بالعربية
 *  3) خلاف ذلك                         ⇒ تمرير الطلب
 * مدير النظام (`IS_ADMIN=1`) يمرّ دائماً لأن `PermissionResolver` يعيده قبل فحص المنح.
 * The system administrator always passes, because `PermissionResolver` short-circuits on `IS_ADMIN`.
 */
class EnsurePermission
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /**
     * @param  string  ...$codes  أكواد الصلاحية المقبولة (OR) / acceptable permission codes (any-of)
     */
    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'جلسة غير صالحة، يرجى تسجيل الدخول من جديد / Unauthenticated.',
            ], 401);
        }

        // تفكيك الوسيط إلى قائمة أكواد نظيفة: ندعم الفاصلة كفاصل بين الوسائط وداخل وسيط واحد
        // Flatten the arguments into a clean code list: commas separate both arguments and tokens
        // within a single argument, so `permission:a,b` and `->middleware('permission:a', 'b')` match.
        $required = [];

        foreach ($codes as $batch) {
            foreach (explode(',', $batch) as $code) {
                $code = trim($code);

                if ($code !== '') {
                    $required[] = $code;
                }
            }
        }

        // قائمة فارغة = رفض (لا شيء يُرضي الشرط)، اتباعاً للرفض الافتراضي
        // An empty code list denies, consistent with deny-by-default.
        if ($required === [] || ! $this->permissions->canAny($user, $required)) {
            $names = array_map([PermissionCatalog::class, 'arabicName'], $required);

            return response()->json([
                'success' => false,
                'message' => 'لا تملك صلاحية «'.implode(' أو ', $names).'» / Permission denied: '.implode(' or ', $required),
                'errors' => ['permission' => $required],
            ], 403);
        }

        return $next($request);
    }
}
