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
 * اعتماد طلبات التفصيل — 0 ⇒ 1
 * Approving tailoring requests — 0 ⇒ 1.
 */
final class ApprovalController extends ProductionBaseController
{
    public function __construct(
        private readonly ProductionQuery $query,
        private readonly ProductionLineWriter $writer
    ) {}

    public function invoices(Request $request): JsonResponse
    {
        [$page, $limit] = $this->page($request);

        try {
            [$rows, $total] = $this->query->approvalInvoices([
                'search' => trim((string) $request->input('search', '')),
                'status' => (string) $request->input('status', 'unapproved'),
                'classification' => (string) $request->input('classification', 'all'),
                'sort' => $request->input('sort') === 'desc' ? 'desc' : 'asc',
            ], $page, $limit);

            return $this->ok(
                $this->cleanRows($rows),
                'تم جلب طلبات الاعتماد / Approval requests retrieved',
                $this->meta($total, $page, $limit)
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب طلبات الاعتماد / Failed to load approval requests');
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

            $rows = $this->query->pieces(ProductionPolicy::SECTION_APPROVAL, $ohid);

            return $this->ok(
                $this->cleanRows($rows),
                $rows === []
                    ? 'لا توجد قطع بانتظار الاعتماد في هذه الفاتورة / No pieces awaiting approval'
                    : 'تم جلب قطع الفاتورة / Bill pieces retrieved'
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر جلب قطع الفاتورة / Failed to load bill pieces');
        }
    }

    public function approve(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required_without:pieces', 'array', 'min:1', 'max:200'],
            'items.*.ohid' => ['required', 'integer', 'min:1'],
            'items.*.ohdids' => ['nullable', 'array'],
            'items.*.ohdids.*' => ['integer', 'min:1'],
            'pieces' => ['required_without:items', 'array', 'min:1', 'max:200'],
            'pieces.*.ohid' => ['required', 'integer', 'min:1'],
            'pieces.*.ohdid' => ['required', 'integer', 'min:1'],
            'pieces.*.gabzor_hand' => ['nullable', 'boolean'],
            'pieces.*.gabzor_hand_amt' => ['nullable', 'numeric'],
            'pieces.*.tatryz_in' => ['nullable', 'boolean'],
            'pieces.*.tatryz_amt' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return $this->fail(
                'بيانات الاعتماد غير صالحة / The approval payload is invalid',
                422,
                $validator->errors()->toArray()
            );
        }

        $data = $validator->validated();
        $items = $data['items'] ?? [];
        $flags = [];

        if (! empty($data['pieces'])) {
            $grouped = [];

            foreach ($data['pieces'] as $piece) {
                $ohid = (int) $piece['ohid'];
                $ohdid = (int) $piece['ohdid'];
                $grouped[$ohid]['ohid'] = $ohid;
                $grouped[$ohid]['ohdids'][] = $ohdid;

                $flag = array_filter([
                    'gabzor_hand' => $piece['gabzor_hand'] ?? null,
                    'gabzor_hand_amt' => $piece['gabzor_hand_amt'] ?? null,
                    'tatryz_in' => $piece['tatryz_in'] ?? null,
                    'tatryz_amt' => $piece['tatryz_amt'] ?? null,
                ], static fn (mixed $value): bool => $value !== null);

                if ($flag !== []) {
                    $flags[$ohdid] = $flag;
                }
            }

            $items = array_values($grouped);
        }

        [$userId, $userName] = $this->actor($request);

        try {
            $result = $this->writer->approve($items, $flags, $userId, $userName, $request);

            return $this->ok(
                $result,
                'تم اعتماد '.$result['pieces'].' قطعة / Approved'
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر اعتماد القطع / Failed to approve pieces');
        }
    }

    public function unapprove(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.ohid' => ['required', 'integer', 'min:1'],
            'items.*.ohdids' => ['nullable', 'array'],
            'items.*.ohdids.*' => ['integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->fail(
                'بيانات إلغاء الاعتماد غير صالحة / The unapprove payload is invalid',
                422,
                $validator->errors()->toArray()
            );
        }

        [$userId, $userName] = $this->actor($request);
        $data = $validator->validated();

        try {
            $result = $this->writer->unapprove($data['items'], $userId, $userName, $request);

            return $this->ok(
                $result,
                'تم إلغاء اعتماد '.$result['pieces'].' قطعة / Unapproved'
            );
        } catch (Throwable $e) {
            return $this->handle($e, 'تعذر إلغاء الاعتماد / Failed to unapprove pieces');
        }
    }
}
