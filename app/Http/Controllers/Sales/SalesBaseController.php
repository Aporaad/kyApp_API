<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * القاعدة المشتركة لمتحكمات المبيعات — مساعدات قراءة وتوحيد الأخطاء والتدقيق
 * Shared base for the sales controllers — read helpers, error unification and audit logging.
 *
 * المبيعات تُقرأ من جدول واحد لكل فاتورة (`SALES_BILL`) لا من واجهة تجميعية كما في التفصيل،
 * فكل استعلام هنا صفٌ واحد بالهوية المركّبة (BILL_NO, YEAR). لا إنشاء ولا تعديل ولا حذف في
 * هذه المرحلة: السطح قراءةٌ فقط، والكتابة تأتي في مرحلة لاحقة بعد تثبيت آلية الترحيل.
 * Sales reads one row per bill (`SALES_BILL`) — not an aggregate view like tailoring — so every
 * lookup here is a single row by the composite key (BILL_NO, YEAR). Nothing here writes: the
 * surface is read-only in this phase; writes land in a later phase once the posting mechanism is
 * confirmed.
 */
abstract class SalesBaseController extends Controller
{
    /** صنف المستند في سجل النشاط — يبقى ثابتاً لكل عمليات القسم */
    /** The document discriminator in the activity log — stable across the module. */
    protected const ENTITY_INVOICE = 'sales_invoice';

    protected const ENTITY_RETURN = 'sales_return';

    protected const ENTITY_REPORT = 'sales_report';

    /** وضع الفاتورة التي تميّز المبيعات عن التفصيل في الجدول المشترك */
    /** The row discriminator that separates sales bills from cutting bills in the shared table. */
    protected const CUTTING_OR_SALES = 2;

    protected const DEFAULT_LIMIT = 20;

    protected const MAX_LIMIT = 200;

    /**
     * قراءة رقم من صف أوراكل قد يصل نصاً (Oracle يعيد NUMBER أحياناً كسلسلة)
     * Read a number from an Oracle row that may arrive as a string.
     *
     * @param  array<string, mixed>  $row
     */
    protected function num(array $row, string $key): ?float
    {
        $value = $row[$key] ?? $row[strtoupper($key)] ?? $row[strtolower($key)] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * قراءة عدد صحيح — للأرقام والمفاتيح
     * Read an integer — for numbers and keys.
     *
     * @param  array<string, mixed>  $row
     */
    protected function int(array $row, string $key): ?int
    {
        $value = $this->num($row, $key);

        return $value === null ? null : (int) $value;
    }

    /**
     * قراءة نص خام — الترميز يُصلَح لاحقاً بـ ArabicEncoding::cleanData على الحمولة كاملة
     * Read a raw string — encoding is repaired later by cleanData over the whole payload.
     *
     * @param  array<string, mixed>  $row
     */
    protected function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? $row[strtoupper($key)] ?? $row[strtolower($key)] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * حالة السداد مشتقّة: NET هو الإجمالي لا الرصيد، والمدفوع يقابله AMOUNT_PAID.
     * Payment status is derived: NET is the gross (not the balance), paid sits in AMOUNT_PAID.
     */
    protected function paymentStatus(?float $net, ?float $paid): string
    {
        $net ??= 0.0;
        $paid ??= 0.0;

        if ($net <= 0) {
            return 'paid';
        }
        if ($paid >= $net - 0.001) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    /**
     * توحيد أخطاء القاعدة في غلاف واحد — نفس نمط باقي المتحكمات
     * Unify database errors into one envelope — the same shape the other controllers use.
     */
    protected function errorResponse(string $msg, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $msg.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }

    /**
     * تسجيل حدث طباعة/تصدير/عرض في APP_USER_ACTIVITY — لا يمسّ أي جدول إرث ولا يُفشل الإجراء
     * Log a print/export/view event in APP_USER_ACTIVITY — touches no legacy table and never fails.
     */
    protected function logActivity(Request $request, string $actionCode, string $entityType, string $entityId, string $description): void
    {
        try {
            app(ActivityLogger::class)->success($actionCode, [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'module_code' => 'sales',
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description_ar' => $description.' '.$entityId,
                'platform' => 'mobile',
            ], $request);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
