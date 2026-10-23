<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * قاعدة مشتركة لمتحكّمات قسم «الخدمات والمزودين»
 * Shared base for the Services & Providers section controllers.
 *
 * الغلاف اليدوي موحّد هنا (`success/message/data/meta`) بنفس صيغة `CustomerController`
 * (`{total,page,limit,pages}`)، وكذلك `errorResponse()` الخاص بكل متحكّم لأن الكلاس الأساسي
 * `App\Http\Controllers\Controller` فارغ عمداً.
 * The hand-written envelope (success/message/data/meta with {total,page,limit,pages}) lives here to
 * match CustomerController, along with the per-controller errorResponse() the empty base lacks.
 */
abstract class ServiceBaseController extends Controller
{
    protected const MAX_LIMIT = 500;

    protected const DEFAULT_LIMIT = 100;

    /**
     * معالجة بارامترات الترقيم (page/limit) مع السقف الآمن
     * Resolve pagination params with a safe ceiling
     *
     * @return array{0: int, 1: int, 2: int} [page, limit, offset]
     */
    protected function pagination(Request $request): array
    {
        $limit = min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $limit = $limit > 0 ? $limit : self::DEFAULT_LIMIT;
        $page = max((int) ($request->input('page') ?? 1), 1);

        return [$page, $limit, ($page - 1) * $limit];
    }

    /**
     * كتلة meta الموحّدة للقوائم
     * Uniform pagination meta block
     */
    protected function meta(int $total, int $page, int $limit): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit),
        ];
    }

    /**
     * اسم الفاعل الحالي لتعبئة أعمدة التدقيق CREATED_BY/UPDATED_BY
     * Current actor name for the CREATED_BY/UPDATED_BY audit columns
     */
    protected function actor(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $name = $user->getAttribute('USER_NAME_EN') ?? $user->getAttribute('USER_NAME_AR');

        return $name !== null ? (string) $name : null;
    }

    /**
     * معالج الأخطاء المركزي — غلاف موحّد 500
     * Central error handler — uniform 500 envelope
     */
    protected function errorResponse(string $msg, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $msg.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
