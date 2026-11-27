<?php

namespace App\Http\Controllers\Production;

use App\Support\PermissionResolver;
use App\Support\Production\ProductionPolicy;
use App\Support\Production\ProductionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * عدادات الأقسام — تُقرأ فقط للأقسام التي يملك المستخدم كود عرضها
 * Section counters — only the sections whose view code the user actually holds.
 */
final class ProductionSummaryController extends ProductionBaseController
{
    public function __construct(
        private readonly ProductionQuery $query,
        private readonly PermissionResolver $permissions
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $all = $this->query->summary();
            $visible = [];

            foreach ($all as $section => $counts) {
                $code = ProductionPolicy::SECTIONS[$section]['view'] ?? null;

                if ($code !== null && $this->permissions->can($user, $code)) {
                    $visible[$section] = $counts;
                }
            }

            return $this->ok($visible, 'تم جلب عدادات خط الإنتاج / Production counters retrieved');
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب عدادات خط الإنتاج / Failed to load production counters');
        }
    }
}
