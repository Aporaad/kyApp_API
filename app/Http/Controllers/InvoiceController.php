<?php

namespace App\Http\Controllers;

use App\Support\ArabicEncoding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * متحكم الفواتير — قراءة فقط من جدول ACCOUNTS.SALES_BILL
 * Invoice controller — read-only over ACCOUNTS.SALES_BILL
 *
 * الفواتير مصدرها نظام المحاسبة القديم فلا إنشاء ولا تعديل ولا حذف من هنا: المسار يقرأ فقط.
 * Invoices originate in the legacy accounting system, so nothing here creates, updates, or deletes
 * a bill — the surface is read-only by design.
 */
class InvoiceController extends Controller
{
    private const TABLE_SALES_BILL = 'ACCOUNTS.SALES_BILL';

    private const MAX_LIMIT = 500;

    private const DEFAULT_LIMIT = 100;

    /**
     * الأعمدة المطلوبة في القائمة وفي التفصيل معاً: تكرارها حرفياً كان أول سبب لانحراف المسارين
     * Columns shared by the list and the detail: repeating them literally was the first source of
     * drift between the two endpoints.
     *
     * @var array<int, string>
     */
    private const COLUMNS = [
        'BILL_NO',
        'YEAR',
        'DAT',
        'CUST_NO',
        'CUST_NAME',
        'TOTAL',
        'DISCOUNT',
        'NET',
        'AMOUNT_PAID',
        'STORE_NO',
        'HANDING_DONE',
    ];

    /**
     * جلب قائمة الفواتير مع البحث والتصفح
     * List invoices with search and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? ''));
        $limit = min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $query = DB::connection('oracle')->table(self::TABLE_SALES_BILL);

            // البحث: برقم الفاتورة رقمياً أو باسم العميل نصاً — والصيغتان معاً لأن العربية مخزّنة
            // بايتات CP1256 فالنص المكتوب UTF-8 لا يطابقها مباشرة
            // Search by bill number or customer name, in both byte forms: Arabic is stored as
            // CP1256 bytes, so the typed UTF-8 term does not match it directly.
            if ($search !== '') {
                $oracleSearch = ArabicEncoding::toOracleSearchChars($search);
                $query->where(function ($q) use ($search, $oracleSearch) {
                    if (is_numeric($search)) {
                        $q->where('BILL_NO', (int) $search);
                    } else {
                        $q->where('CUST_NAME', 'LIKE', '%'.$oracleSearch.'%')
                            ->orWhere('CUST_NAME', 'LIKE', '%'.$search.'%');
                    }
                });
            }

            $total = (clone $query)->count();

            $invoices = $query
                ->select([...self::COLUMNS, DB::raw('(NET - AMOUNT_PAID) AS REMAINING')])
                ->orderByDesc('DAT')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الفواتير بنجاح / Invoices retrieved',
                'data' => ArabicEncoding::cleanData($invoices),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب الفواتير / Failed to retrieve invoices',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * تفاصيل فاتورة واحدة برقمها
     * One invoice by its bill number
     */
    public function show(Request $request, int $billNo): JsonResponse
    {
        try {
            $invoice = DB::connection('oracle')
                ->table(self::TABLE_SALES_BILL)
                ->select([...self::COLUMNS, DB::raw('(NET - AMOUNT_PAID) AS REMAINING')])
                ->where('BILL_NO', $billNo)
                ->first();

            if (! $invoice) {
                return response()->json([
                    'success' => false,
                    'message' => "الفاتورة رقم [{$billNo}] غير موجودة / Invoice [{$billNo}] not found",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الفاتورة بنجاح / Invoice retrieved',
                'data' => ArabicEncoding::cleanData($invoice),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب الفاتورة / Failed to retrieve invoice',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
