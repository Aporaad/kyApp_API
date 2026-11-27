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
 * التسليم إلى القص — 1 ⇒ 2
 * Delivering to cutting — 1 ⇒ 2.
 */
final class CutDeliveryController extends ProductionBaseController
{
    public function __construct(
        private readonly ProductionQuery $query,
        private readonly ProductionLineWriter $writer
    ) {}

    public function invoices(Request $request): JsonResponse
    {
        [$page, $limit] = $this->page($request);

        try {
            [$rows, $total] = $this->query->deliveryInvoices([
                'search' => trim((string) $request->input('search', '')),
            ], $page, $limit);

            return $this->ok(
                $this->cleanRows($rows),
                'تم جلب الطلبات الجاهزة للقص / Requests ready for cutting retrieved',
                $this->meta($total, $page, $limit)
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب الطلبات الجاهزة للقص / Failed to load delivery requests');
        }
    }

    public function pieces(Request $request, int $ohid): JsonResponse
    {
        try {
            $exists = (array) DB::connection('oracle')->selectOne(
                'SELECT COUNT(*) AS C FROM ACCOUNTS.SALES_BILL WHERE OHID = :ohid',
                ['ohid' => $ohid]
            );

            if ((int) ($exists['C'] ?? $exists['c'] ?? 0) === 0) {
                return $this->fail('الفاتورة غير موجودة / The bill was not found', 404);
            }

            $rows = $this->query->pieces(ProductionPolicy::SECTION_TO_CUT, $ohid);

            return $this->ok(
                $this->cleanRows($rows),
                $rows === []
                    ? 'لا توجد قطع جاهزة للتسليم في هذه الفاتورة / No pieces ready for delivery'
                    : 'تم جلب قطع الفاتورة / Bill pieces retrieved'
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب قطع الفاتورة / Failed to load bill pieces');
        }
    }

    public function cutters(): JsonResponse
    {
        try {
            $rows = $this->query->cuttersForDelivery();

            return $this->ok(
                $this->cleanRows($rows),
                'تم جلب قائمة القصاصين / Cutters retrieved'
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب قائمة القصاصين / Failed to load cutters');
        }
    }

    public function deliver(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cutter_no' => ['required', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.ohid' => ['required', 'integer', 'min:1'],
            'items.*.ohdids' => ['nullable', 'array'],
            'items.*.ohdids.*' => ['integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->fail(
                'بيانات التسليم غير صالحة / The delivery payload is invalid',
                422,
                $validator->errors()->toArray()
            );
        }

        $data = $validator->validated();
        [$userId, $userName] = $this->actor($request);

        try {
            $result = $this->writer->deliver(
                (int) $data['cutter_no'],
                $data['date'] ?? null,
                $data['items'],
                $userId,
                $userName,
                $request
            );

            return $this->ok(
                $result,
                'تم تسليم '.$result['pieces'].' قطعة إلى القص — السند '.$result['document_no']
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر تسليم القطع إلى القص / Failed to deliver pieces');
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
            $result = $this->writer->undoDelivery(
                $data['items'],
                $data['reason'] ?? null,
                $userId,
                $userName,
                $request
            );

            return $this->ok($result, 'تم التراجع عن تسليم '.$result['pieces'].' قطعة');
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر التراجع عن التسليم / Failed to undo the delivery');
        }
    }
}
