<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\Finance\FinancePolicy;
use App\Support\PermissionResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * القاعدة المشتركة لمتحكمات القيود والسندات — قراءة القيم، توحيد الأخطاء، الترقيم، التدقيق
 * Shared base for the entries & vouchers controllers — value readers, error unification,
 * numbering retries and activity logging.
 */
abstract class FinanceBaseController extends Controller
{
    protected const DEFAULT_LIMIT = 20;

    protected const MAX_LIMIT = 100;

    /** @param  array<string, mixed>  $row */
    protected function num(array $row, string $key): ?float
    {
        $value = $row[$key] ?? $row[strtoupper($key)] ?? $row[strtolower($key)] ?? null;

        return ($value === null || $value === '' || ! is_numeric($value)) ? null : (float) $value;
    }

    /** @param  array<string, mixed>  $row */
    protected function int(array $row, string $key): ?int
    {
        $value = $this->num($row, $key);

        return $value === null ? null : (int) $value;
    }

    /** @param  array<string, mixed>  $row */
    protected function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? $row[strtoupper($key)] ?? $row[strtolower($key)] ?? null;

        if ($value === null) {
            return null;
        }

        $text = ArabicEncoding::normalizeStatementText($value);

        return $text === '' ? null : $text;
    }

    /** تاريخ بصيغة YYYY-MM-DD أو null / a YYYY-MM-DD date or null */
    protected function dateInput(Request $request, string $key): ?string
    {
        $value = trim((string) ($request->input($key) ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** @return array{0: int, 1: int, 2: int} [page, limit, offset] */
    protected function paging(Request $request): array
    {
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);

        return [$page, $limit, ($page - 1) * $limit];
    }

    /** @return array<string, int> */
    protected function meta(int $total, int $page, int $limit): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
        ];
    }

    /** ردّ نجاح موحّد / a unified success envelope */
    protected function ok(string $message, mixed $data = null, int $status = 200, ?array $meta = null): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => ArabicEncoding::cleanData($data)];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /** ردّ رفض موحّد / a unified failure envelope */
    protected function fail(string $message, int $status = 422, array $errors = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => (object) $errors], $status);
    }

    /**
     * توحيد أخطاء القاعدة — مع ترجمة رفض المفتاح الأجنبي (ORA-02292) إلى رسالة مفهومة
     * Unify database errors — translating a child-record refusal (ORA-02292) into plain words.
     */
    protected function errorResponse(string $msg, \Throwable $e): JsonResponse
    {
        if ($e instanceof QueryException && str_contains($e->getMessage(), 'ORA-02292')) {
            return $this->fail('لا يمكن الحذف لارتباط المستند بمستندات أخرى في النظام / The document is referenced by other records.', 422);
        }

        report($e);

        return response()->json([
            'success' => false,
            'message' => $msg.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }

    /**
     * تنفيذ كتابة حساسة للترقيم مع إعادة المحاولة عند ORA-00001 (تزامن مع النظام المكتبي)
     * Run a numbering-sensitive write, retrying on ORA-00001 (concurrency with the desktop).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    protected function withNumberRetry(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (QueryException $e) {
                $unique = (int) ($e->errorInfo[1] ?? 0) === 1 || str_contains($e->getMessage(), 'ORA-00001');

                if (! $unique || $attempt >= FinancePolicy::NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /** هل يملك المستخدم الصلاحية؟ (للتمييز داخل المسار متعدد الصلاحيات) / does the user hold it? */
    protected function can(Request $request, string $code): bool
    {
        $user = $request->user();

        return $user !== null && app(PermissionResolver::class)->can($user, $code);
    }

    /** ردّ 403 بصيغة الوسيط نفسه / a 403 in the middleware's own shape */
    protected function forbidden(string $nameAr): JsonResponse
    {
        return $this->fail("ليس لديك صلاحية: {$nameAr} / Permission denied.", 403);
    }

    /**
     * تسجيل حدث في APP_USER_ACTIVITY — لا يُفشل الإجراء أبداً
     * Log an event into APP_USER_ACTIVITY — never fails the request.
     */
    protected function logActivity(Request $request, string $module, string $action, string $entityType, string $entityId, string $description, bool $failed = false): void
    {
        try {
            $context = [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'module_code' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description_ar' => $description,
                'platform' => 'mobile',
            ];

            $logger = app(ActivityLogger::class);
            $failed
                ? $logger->failed("{$module}.{$action}", $context, $request)
                : $logger->success("{$module}.{$action}", $context, $request);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * تحقق نصّي من حقل مطلوب بعد التشذيب / a required trimmed text field
     */
    protected function text(Request $request, string $key): string
    {
        return trim((string) ($request->input($key) ?? ''));
    }

    /**
     * سطر بحث نصي على عمود بصيغتيه (المخزَّنة CP1256 والنص كما هو)
     * A LIKE predicate on a column in both forms (stored CP1256 and the raw text).
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function likeBoth(string $column, string $search): array
    {
        $oracle = (string) ArabicEncoding::toOracleSearchChars($search);

        return ["({$column} LIKE ? OR {$column} LIKE ?)", ['%'.$oracle.'%', '%'.$search.'%']];
    }

    /**
     * علامات استفهام مربوطة لقائمة قيم / bound placeholders for a value list
     *
     * @param  array<int, mixed>  $values
     */
    protected function marks(array $values): string
    {
        return implode(',', array_fill(0, max(count($values), 1), '?'));
    }

    /**
     * إحصاءات قيود الصفحة: عدد السطور ومجموع المدين بالمعادل المحلي — استعلام واحد
     * لأن ENTRY_SUB بلا فهرس على ENTRY_NO (المرحلة 0)، فالاستعلام المترابط لكل صف ممنوع.
     * Page entry stats (line count + local debit total) in ONE query, because ENTRY_SUB has no
     * ENTRY_NO index (phase 0) and a correlated sub-query per row would scan it every time.
     *
     * @param  array<int, int>  $entryNos
     * @return array<int, array{lines: int, debit: float}>
     */
    protected function entryStats(array $entryNos): array
    {
        if ($entryNos === []) {
            return [];
        }

        $rows = DB::connection('oracle')->select(
            "SELECT s.ENTRY_NO, COUNT(*) AS N,
                    SUM(CASE WHEN s.ENTRY_MNT > 0 THEN s.ENTRY_MNT *
                        (CASE WHEN SUBSTR(TO_CHAR(s.ACC_NO), 4, 1) <> '1' AND p.PRICE > 0 THEN p.PRICE ELSE 1 END)
                        ELSE 0 END) AS DEBIT
               FROM ACCOUNTS.ENTRY_SUB s
               LEFT JOIN ACCOUNTS.CUR_PRICE p ON p.SEQ = s.SEQ_PRICE
              WHERE s.ENTRY_NO IN (".$this->marks($entryNos).')
              GROUP BY s.ENTRY_NO',
            array_values($entryNos)
        );

        $stats = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $stats[(int) $this->int($r, 'ENTRY_NO')] = [
                'lines' => (int) $this->int($r, 'N'),
                'debit' => round((float) $this->num($r, 'DEBIT'), 2),
            ];
        }

        return $stats;
    }

    /**
     * ربط القيود بسنداتها (لكل سند قيد واحد) — استعلام لكل جدول سندات
     * Link entries to their vouchers (one voucher per entry) — one query per voucher table.
     *
     * @param  array<int, int>  $entryNos
     * @return array<int, array{direction: string, source: string, no: int, year: int, bill_no: ?int}>
     */
    protected function voucherLinks(array $entryNos): array
    {
        if ($entryNos === []) {
            return [];
        }

        $links = [];

        foreach (FinancePolicy::VOUCHERS as $direction => $sources) {
            foreach ($sources as $source => $def) {
                $bill = $direction === 'payment' ? 'NULL' : 'BILL_NO';
                $rows = DB::connection('oracle')->select(
                    "SELECT ENTRY_NO, {$def['no']} AS V_NO, YEAR, {$bill} AS BILL_NO FROM {$def['table']}
                      WHERE ENTRY_NO IN (".$this->marks($entryNos).')',
                    array_values($entryNos)
                );

                foreach ($rows as $row) {
                    $r = (array) $row;
                    $links[(int) $this->int($r, 'ENTRY_NO')] = [
                        'direction' => $direction,
                        'source' => $source,
                        'no' => (int) $this->int($r, 'V_NO'),
                        'year' => (int) $this->int($r, 'YEAR'),
                        'bill_no' => $this->int($r, 'BILL_NO'),
                    ];
                }
            }
        }

        return $links;
    }
}
