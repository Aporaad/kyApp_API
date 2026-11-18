<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\PurchasePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * القاعدة المشتركة لمتحكمات المشتريات — قراءة الحقول وتوحيد الأخطاء والتدقيق
 * Shared base for the purchases controllers — field readers, error unification and auditing.
 *
 * ── لماذا قراءة ثلاثية؟ لأن OCI8 يعيد مفاتيح الجدول بحالة غير مضمونة (كبرى أو صغرى)، ولأن
 *    أرقام NUMBER تصل نصوصاً في بعض الأحيان. كل متحكم في القسم يقرأ عبر هذه الدوال وحدها.
 * ── Why triple-case reads: OCI8 returns column keys in an unreliable case and NUMBER values may
 *    arrive as strings; every controller in the module reads through these helpers only.
 */
abstract class PurchaseBaseController extends Controller
{
    protected const ENTITY_INVOICE = 'purchase_invoice';

    protected const ENTITY_RETURN = 'purchase_return';

    protected const ENTITY_SUPPLIER = 'purchase_supplier';

    protected const ENTITY_REPORT = 'purchase_report';

    protected const DEFAULT_LIMIT = 20;

    protected const MAX_LIMIT = 200;

    /**
     * رقم من صف أوراكل قد يصل نصاً
     * A number from an Oracle row that may arrive as a string.
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
     * عدد صحيح — للأرقام والمفاتيح
     * An integer — for numbers and keys.
     *
     * @param  array<string, mixed>  $row
     */
    protected function int(array $row, string $key): ?int
    {
        $value = $this->num($row, $key);

        return $value === null ? null : (int) $value;
    }

    /**
     * نص خام — الترميز يُصلَح لاحقاً بـ cleanData على الحمولة كاملة
     * A raw string — the encoding is repaired later by cleanData over the whole payload.
     *
     * @param  array<string, mixed>  $row
     */
    protected function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? $row[strtoupper($key)] ?? $row[strtolower($key)] ?? null;

        $value = $value === null ? null : trim((string) $value);

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * صف أوراكل بمفاتيح كبيرة موحّدة
     * An Oracle row normalised to uppercase keys.
     *
     * @return array<string, mixed>|null
     */
    protected function upperRow(mixed $row): ?array
    {
        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * مدخل تاريخ بصيغة YYYY-MM-DD أو null — يرفض أي صيغة أخرى بدل تمرير نص حر إلى Oracle
     * A YYYY-MM-DD date input or null; refuses any other shape rather than binding free text.
     */
    protected function dateInput(Request $request, string $key): ?string
    {
        $value = trim((string) ($request->input($key) ?? ''));

        if ($value === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * توحيد أخطاء القاعدة في غلاف واحد
     * Unify database errors into one envelope.
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
     * رد رفض بقواعد التحقق بصيغة الغلاف نفسها
     * A validation refusal in the same envelope shape.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function invalid(string $message, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    /**
     * تنفيذ عملية ترقيم بإعادة المحاولة عند تصادم القيد الفريد (ORA-00001)
     * Run a numbering-sensitive write, retrying when the unique key collides (ORA-00001).
     *
     * ── لماذا؟ عدّاد MAX+1 يتصادم تحت التزامن مع النظام المكتبي، والقيد الفريد يحمي البيانات:
     *    تُرفض المعاملة كاملة وتُعاد بأرقام جديدة.
     * ── Why: a MAX+1 counter collides under concurrent desktop use; the unique key protects the
     *    data — the whole transaction rolls back and is retried with fresh numbers.
     *
     * @param  callable(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    protected function withNumberRetry(callable $operation): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (QueryException $e) {
                $unique = (int) ($e->errorInfo[1] ?? 0) === 1 || str_contains($e->getMessage(), 'ORA-00001');

                if (! $unique || $attempt >= PurchasePolicy::NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /**
     * تسجيل حدث في APP_USER_ACTIVITY — لا يُفشل الطلب أبداً
     * Log an event in APP_USER_ACTIVITY — never fails the request.
     */
    protected function logActivity(Request $request, string $actionCode, string $entityType, string $entityId, string $description): void
    {
        try {
            app(ActivityLogger::class)->success($actionCode, [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'module_code' => 'purchases',
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description_ar' => $description.' '.$entityId,
                'platform' => 'mobile',
            ], $request);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * نص عربي جاهز للبحث في القاعدة القديمة (بصيغتيه)
     * An Arabic search needle prepared for the legacy database (both byte forms).
     */
    protected function arabicNeedle(string $text): string
    {
        return (string) ArabicEncoding::toOracleSearchChars($text);
    }
}
