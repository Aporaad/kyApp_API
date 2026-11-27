<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Support\ArabicEncoding;
use App\Support\Production\ProductionConflictException;
use App\Support\Production\ProductionLockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * أساس متحكمات خط الإنتاج — غلاف الرد وأخطاء الحالة والترقيم
 * Base for production-line controllers — envelope, state conflicts and lock errors.
 */
abstract class ProductionBaseController extends Controller
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function ok(mixed $data, string $message, array $meta = []): JsonResponse
    {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, 200);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    protected function fail(string $message, int $status, array $errors = [], mixed $data = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function page(Request $request): array
    {
        $limit = (int) $request->input('limit', config('production.limits.page', 20));
        $limit = max(1, min($limit, (int) config('production.limits.max_page', 100)));
        $page = max(1, (int) $request->input('page', 1));

        return [$page, $limit];
    }

    /**
     * @return array{total: int, page: int, limit: int, pages: int}
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
     * @param  list<object|array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function cleanRows(array $rows): array
    {
        return ArabicEncoding::cleanData(array_map(
            static fn (object|array $row): array => (array) $row,
            $rows
        ));
    }

    /**
     * ترجمة أخطاء الكاتب إلى ردود مفهومة للواجهة
     * Translate writer failures into responses the UI can act on.
     */
    protected function handle(Throwable $e, string $fallback): JsonResponse
    {
        if ($e instanceof ProductionConflictException) {
            return $this->fail($e->getMessage(), 409, [], $e->details);
        }

        if ($e instanceof ProductionLockException) {
            return $this->fail($e->getMessage(), 409);
        }

        if ($e instanceof InvalidArgumentException) {
            return $this->fail($e->getMessage(), 422);
        }

        report($e);

        return $this->fail($fallback.' / '.$e->getMessage(), 500);
    }

    /**
     * هوية المنفّذ واسمه للتدقيق
     * The actor id and display name for the audit trail.
     *
     * @return array{0: int|null, 1: string|null}
     */
    protected function actor(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [null, null];
        }

        $id = $user->getKey();
        $name = $user->user_name_ar
            ?? $user->USER_NAME_AR
            ?? $user->user_name_en
            ?? $user->USER_NAME_EN;

        return [$id !== null ? (int) $id : null, $name !== null ? (string) $name : null];
    }
}
