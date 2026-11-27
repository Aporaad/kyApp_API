<?php

namespace App\Http\Controllers\Tailoring;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\Tailoring\TailoringEditException;
use App\Support\Tailoring\TailoringEditSnapshot;
use App\Support\Tailoring\TailoringEditWriter;
use App\Support\Tailoring\TailoringPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * متحكم تعديل فاتورة التفصيل — قراءة وتعديل كامل وتعديل مالي دائم.
 * Tailoring invoice edit controller — snapshot, full edit, and always-on financial edit.
 */
class TailoringEditController extends Controller
{
    public function filters(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'تم جلب خيارات فلاتر التعديل / Edit filters retrieved',
            'data' => TailoringEditSnapshot::filters(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $result = TailoringEditSnapshot::list([
                'search' => (string) $request->input('search', ''),
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date'),
                'year' => $request->input('year'),
                'bill_kind' => $request->input('bill_kind'),
                'delivery' => $request->input('delivery'),
                'stage' => $request->input('stage'),
                'payment' => $request->input('payment'),
                'urgent' => $request->input('urgent'),
                'branch' => $request->input('branch'),
                'sort' => (string) $request->input('sort', 'date'),
                'dir' => (string) $request->input('dir', 'desc'),
                'page' => (int) $request->input('page', 1),
                'limit' => (int) $request->input('limit', 20),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب فواتير التعديل / Edit invoices retrieved',
                'data' => $result['items'],
                'meta' => $result['meta'],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب فواتير التعديل / Failed to retrieve edit invoices', $e);
        }
    }

    public function show(int $billNo, int $year): JsonResponse
    {
        try {
            $snapshot = TailoringEditSnapshot::read($billNo, $year);
            if ($snapshot === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'فاتورة التفصيل غير موجودة / Cutting bill not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب لقطة تعديل الفاتورة / Edit snapshot retrieved',
                'data' => $snapshot,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب لقطة التعديل / Failed to retrieve the edit snapshot', $e);
        }
    }

    public function update(Request $request, int $billNo, int $year): JsonResponse
    {
        try {
            $snapshot = app(TailoringEditWriter::class)->fullUpdate($request, $billNo, $year);
            $this->logActivity($request, 'tailoring.invoice_updated', $billNo, $year, 'تعديل فاتورة تفصيل');

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل فاتورة التفصيل بنجاح / Cutting bill updated',
                'data' => $snapshot,
            ]);
        } catch (TailoringEditException $e) {
            return $this->editError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تعديل فاتورة التفصيل / Failed to update the cutting bill', $e);
        }
    }

    public function patch(Request $request, int $billNo, int $year): JsonResponse
    {
        try {
            $snapshot = app(TailoringEditWriter::class)->financialUpdate($request, $billNo, $year);
            $this->logActivity($request, 'tailoring.invoice_financial_updated', $billNo, $year, 'تعديل الحقول المالية لفاتورة تفصيل');

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الحقول المالية بنجاح / Financial fields updated',
                'data' => $snapshot,
            ]);
        } catch (TailoringEditException $e) {
            return $this->editError($e);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في تعديل الحقول المالية / Failed to update financial fields', $e);
        }
    }

    /**
     * مرجع العملات والحسابات وفروقات العملة للتعديل المالي.
     * Currency, account, and exchange-difference reference for financial edits.
     */
    public function currencyReference(): JsonResponse
    {
        try {
            $currencies = DB::connection('oracle')->select('SELECT CUR_NO, CUR_NAME FROM ACCOUNTS.CURRENCY ORDER BY CUR_NO');
            $prices = DB::connection('oracle')->select(
                'SELECT SEQ, CUR_NO, PRICE, TO_CHAR(DAY_DATE, \'YYYY-MM-DD\') AS DAY_DATE
                   FROM ACCOUNTS.CUR_PRICE
                  ORDER BY DAY_DATE DESC, SEQ DESC'
            );
            $accounts = TailoringPolicy::accounts();
            $difference = (array) DB::connection('oracle')->selectOne(
                'SELECT ACC_NO FROM ACCOUNTS.CASH_ACC WHERE TYPE_NO = 12 AND ROWNUM <= 1'
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مرجع التعديل المالي / Financial edit reference retrieved',
                'data' => ArabicEncoding::cleanData([
                    'currencies' => array_map(static fn ($row): array => (array) $row, $currencies),
                    'prices' => array_map(static fn ($row): array => (array) $row, $prices),
                    'accounts' => $accounts,
                    'exchange_difference_account' => $difference === [] ? null : (int) reset($difference),
                ]),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب مرجع التعديل المالي / Failed to retrieve financial edit reference', $e);
        }
    }

    private function editError(TailoringEditException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'reason' => $e->reason,
            'errors' => $e->errors,
        ], $e->status);
    }

    private function logActivity(Request $request, string $action, int $billNo, int $year, string $description): void
    {
        try {
            app(ActivityLogger::class)->success($action, [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'module_code' => 'tailoring',
                'entity_type' => 'cutting_bill',
                'entity_id' => $billNo.'/'.$year,
                'description_ar' => $description.' '.$billNo.'/'.$year,
                'platform' => 'mobile',
            ], $request);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function errorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
