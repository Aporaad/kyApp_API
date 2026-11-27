<?php

namespace App\Http\Controllers\Production;

use App\Support\Production\ProductionLineWriter;
use App\Support\Production\ProductionPolicy;
use App\Support\Production\ProductionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * الاستلام من القص — 2 ⇒ 4، مع حركتي صرف وتوريد
 * Receiving from cutting — 2 ⇒ 4, with an issue/receipt pair.
 */
final class CutReceiptController extends ProductionBaseController
{
    public function __construct(
        private readonly ProductionQuery $query,
        private readonly ProductionLineWriter $writer
    ) {}

    public function cutters(): JsonResponse
    {
        try {
            $rows = $this->query->cuttersWithWork();

            return $this->ok(
                $this->cleanRows($rows),
                'تم جلب القصاصين الذين لديهم قطع / Cutters holding pieces retrieved'
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب القصاصين / Failed to load cutters');
        }
    }

    public function invoices(Request $request): JsonResponse
    {
        [$page, $limit] = $this->page($request);
        $cutterNo = $request->filled('cutter_no') ? (int) $request->input('cutter_no') : null;

        try {
            [$rows, $total] = $this->query->receiptInvoices([
                'search' => trim((string) $request->input('search', '')),
                'cutter_no' => $cutterNo !== null && $cutterNo > 0 ? $cutterNo : null,
            ], $page, $limit);

            return $this->ok(
                $this->cleanRows($rows),
                'تم جلب القطع الموجودة عند القصاصين / Pieces at cutters retrieved',
                $this->meta($total, $page, $limit)
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب القطع من القص / Failed to load pieces from cutting');
        }
    }

    public function pieces(Request $request, int $ohid): JsonResponse
    {
        $cutterNo = $request->filled('cutter_no') ? (int) $request->input('cutter_no') : null;

        try {
            $exists = (array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS C FROM ACCOUNTS.SALES_BILL WHERE OHID = :ohid',
                ['ohid' => $ohid]
            );

            if ((int) ($exists['C'] ?? $exists['c'] ?? 0) === 0) {
                return $this->fail('الفاتورة غير موجودة / The bill was not found', 404);
            }

            $rows = $this->query->pieces(
                ProductionPolicy::SECTION_FROM_CUT,
                $ohid,
                $cutterNo !== null && $cutterNo > 0 ? $cutterNo : null
            );

            return $this->ok(
                $this->cleanRows($rows),
                $rows === []
                    ? 'لا توجد قطع عند هذا القصاص في الفاتورة / No pieces at this cutter'
                    : 'تم جلب قطع الفاتورة / Bill pieces retrieved'
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب قطع الفاتورة / Failed to load bill pieces');
        }
    }

    public function receive(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cutter_no' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.ohid' => ['required', 'integer', 'min:1'],
            'items.*.ohdids' => ['nullable', 'array'],
            'items.*.ohdids.*' => ['integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->fail(
                'بيانات الاستلام غير صالحة / The receipt payload is invalid',
                422,
                $validator->errors()->toArray()
            );
        }

        $data = $validator->validated();
        [$userId, $userName] = $this->actor($request);

        try {
            $result = $this->writer->receive(
                (int) $data['cutter_no'],
                (string) $data['date'],
                $data['items'],
                $userId,
                $userName,
                $request
            );

            return $this->ok(
                $result,
                'تم استلام '.$result['pieces'].' قطعة من القص — السند '.$result['document_no']
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر استلام القطع من القص / Failed to receive pieces');
        }
    }

    public function undo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.ohid' => ['required', 'integer', 'min:1'],
            'items.*.ohdids' => ['nullable', 'array'],
            'items.*.ohdids.*' => ['integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->fail(
                'بيانات التراجع غير صالحة / The undo payload is invalid',
                422,
                $validator->errors()->toArray()
            );
        }

        $data = $validator->validated();
        [$userId, $userName] = $this->actor($request);

        try {
            $result = $this->writer->undoReceipt(
                $data['items'],
                $data['reason'] ?? null,
                $userId,
                $userName,
                $request
            );

            return $this->ok($result, 'تم التراجع عن استلام '.$result['pieces'].' قطعة');
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر التراجع عن الاستلام / Failed to undo the receipt');
        }
    }
}
