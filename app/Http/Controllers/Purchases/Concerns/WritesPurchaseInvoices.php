<?php

namespace App\Http\Controllers\Purchases\Concerns;

use App\Support\ChartAccountNumbering;
use App\Support\InventoryLineGuard;
use App\Support\JournalWriter;
use App\Support\PermissionResolver;
use App\Support\PurchaseDocumentWriter;
use App\Support\PurchasePolicy;
use App\Support\PurchasePosting;
use App\Support\StorePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * كتابة فواتير المشتريات — إنشاء وتعديل وحذف، وحمولة الكتابة
 * Purchase invoice writes — create, update, delete, and the write payload.
 *
 * ── فصلت عن المتحكم لأن القراءة والكتابة مسؤوليتان مختلفتان، ولأن هذا الملف يحمل كل قرارات
 *    السلامة المحاسبية في مكان واحد: الترقيم، والترحيل، والحفاظ على `SEQ`، وحدود المردودات.
 * ── Split from the controller because reads and writes are different responsibilities, and
 *    because every accounting-safety decision lives here: numbering, posting, SEQ preservation
 *    and the returned-quantity bounds.
 */
trait WritesPurchaseInvoices
{
    /**
     * إنشاء فاتورة مشتريات وترحيلها
     * Create and post a purchase invoice.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->writePayload($request, null);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(
                fn (): array => $this->createInvoice($payload, $request)
            ));

            $this->logActivity($request, 'purchases.invoice_created', self::ENTITY_INVOICE, $result['bill_no'].'/'.$result['year'], 'إنشاء فاتورة مشتريات');

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء فاتورة المشتريات بنجاح / Purchase invoice created',
                'data' => $result,
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->invalid($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء فاتورة المشتريات / Failed to create the purchase invoice', $e);
        }
    }

    /**
     * تعديل فاتورة: قيد جديد ثم تحديث الرأس ثم حذف القديم، مع الحفاظ على `SEQ` للسطور
     * Update an invoice: post a new entry, update the header, drop the old entry, keep line SEQ.
     */
    public function update(Request $request, int $billNo, int $year): JsonResponse
    {
        $header = $this->invoiceHeader($billNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'فاتورة المشتريات غير موجودة / Purchase invoice not found',
            ], 404);
        }

        $payload = $this->writePayload($request, $header);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $oldEntryNo = $this->int($header, 'ENTRY_NO');

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(
                fn (): array => $this->updateInvoice($payload, $header, $billNo, $year, $oldEntryNo, $request)
            ));

            $this->logActivity($request, 'purchases.invoice_updated', self::ENTITY_INVOICE, "$billNo/$year", 'تعديل فاتورة مشتريات');

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل فاتورة المشتريات بنجاح / Purchase invoice updated',
                'data' => $result,
            ], 200);
        } catch (\RuntimeException $e) {
            return $this->invalid($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل فاتورة المشتريات / Failed to update the purchase invoice', $e);
        }
    }

    /**
     * حذف فاتورة: السطور ثم الرأس ثم القيد — حذف فيزيائي لأن واجهة الحركة تتجاهل علم الإلغاء
     * Delete an invoice: lines, header, then entry — physical, because the movement view ignores
     * any cancellation flag.
     */
    public function destroy(Request $request, int $billNo, int $year): JsonResponse
    {
        $header = $this->invoiceHeader($billNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'فاتورة المشتريات غير موجودة / Purchase invoice not found',
            ], 404);
        }

        $linked = DB::connection('oracle')->select(
            'SELECT RETURN_NO FROM ACCOUNTS.RETURN_BUY WHERE BILL_NO = :bill_no AND BILL_YEAR = :year',
            ['bill_no' => $billNo, 'year' => $year]
        );

        if ($linked !== []) {
            $numbers = implode(', ', array_map(static function ($r): string {
                $row = array_change_key_case((array) $r, CASE_UPPER);

                return (string) ($row['RETURN_NO'] ?? '');
            }, $linked));

            return $this->invalid(
                "لا يمكن حذف الفاتورة لوجود مردودات مرتبطة بها: {$numbers} / The invoice has linked returns.",
                ['returns' => $numbers]
            );
        }

        $storeNo = (int) ($this->int($header, 'STORE_NO') ?? 0);
        $allowNegative = app(PermissionResolver::class)->can($request->user(), 'purchases.*');

        try {
            if (StorePolicy::isStockStore($storeNo) && ! $allowNegative) {
                $shortages = $this->deletionShortages($billNo, $year, $storeNo);

                if ($shortages !== []) {
                    return $this->invalid(
                        'حذف الفاتورة يجعل رصيد صنف سالباً / Deleting this invoice would drive an item balance negative.',
                        ['stock' => $shortages]
                    );
                }
            }

            $entryNo = $this->int($header, 'ENTRY_NO');

            DB::connection('oracle')->transaction(function () use ($billNo, $year, $entryNo): void {
                PurchaseDocumentWriter::deleteLines('BUY_DETAILES', 'BILL_NO', $billNo, $year);

                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.BUY_BILL WHERE BILL_NO = :bill_no AND YEAR = :year',
                    ['bill_no' => $billNo, 'year' => $year]
                );

                JournalWriter::deleteEntry($entryNo);
            });

            $this->logActivity($request, 'purchases.invoice_deleted', self::ENTITY_INVOICE, "$billNo/$year", 'حذف فاتورة مشتريات');

            return response()->json([
                'success' => true,
                'message' => 'تم حذف فاتورة المشتريات / Purchase invoice deleted',
                'data' => ['bill_no' => $billNo, 'year' => $year],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف فاتورة المشتريات / Failed to delete the purchase invoice', $e);
        }
    }

    /** تسجيل طباعة/تصدير على الفاتورة / log a print or export on the invoice */
    public function activity(Request $request, int $billNo, int $year): JsonResponse
    {
        $this->logActivity($request, 'purchases.invoice_printed', self::ENTITY_INVOICE, "$billNo/$year", 'طباعة/تصدير فاتورة مشتريات');

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل النشاط / Activity logged',
        ], 200);
    }

    /**
     * التحقق من حمولة الكتابة وتجهيزها أو رد 422
     * Validate and prepare the write payload, or return a 422.
     *
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|JsonResponse
     */
    private function writePayload(Request $request, ?array $existing)
    {
        $validator = Validator::make($request->all(), [
            'bill_type' => 'required|integer|in:1,2',
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'store_no' => 'required|integer|exists:oracle.STORES,STORE_NO',
            'branch_no' => 'nullable|integer',
            'prov_no' => 'nullable|integer|exists:oracle.PROV,PROV_NO',
            'prov_name' => 'nullable|string|max:80',
            'prov_bill' => 'nullable|string|max:15',
            'acc_no1' => 'required|integer|exists:oracle.CHART_ACC,ACC_NO',
            'acc_no' => 'nullable|integer|exists:oracle.CHART_ACC,ACC_NO',
            'cur_price' => 'nullable|numeric|min:0.0001',
            'discount' => 'nullable|numeric|min:0',
            'details' => 'nullable|string|max:100',
            'manual_bill_no' => 'nullable|integer|min:1',
            'lines' => 'required|array|min:1',
            'lines.*.sanf_no' => 'required|integer',
            'lines.*.sanf_dno' => 'required|integer',
            'lines.*.qty' => 'required|numeric|gt:0',
            'lines.*.price' => 'required|numeric|min:0',
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return $this->invalid('بيانات الفاتورة غير صالحة / The invoice data is invalid.', $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $billType = (int) $data['bill_type'];
        $storeNo = (int) $data['store_no'];
        $accNo1 = (int) $data['acc_no1'];

        // 1) المخزن: الإنشاء على مخزن مخزوني فقط، والتعديل قد يُبقي مخزناً قديماً مستثنى
        //    Store: creation needs a stock store; an update may keep an excluded legacy store.
        if (! StorePolicy::isStockStore($storeNo)) {
            $currentStore = $existing === null ? null : $this->int($existing, 'STORE_NO');

            if ($currentStore === null || $currentStore !== $storeNo) {
                return $this->invalid(
                    'لا يُسمح بفاتورة مشتريات على مخزن غير مخزوني / Purchases are refused on a non-stock store.',
                    ['store_no' => 'المخزن مستثنى من الأرصدة / the store is excluded from balances']
                );
            }
        }

        // 2) الحساب الدافع: مورد في الآجل، وصندوق/بنك في النقد
        //    The paying account: a supplier on credit, a cash/bank account on cash.
        if ($billType === PurchasePolicy::BILL_CREDIT && ! PurchasePolicy::isSupplierAccount($accNo1)) {
            return $this->invalid(
                'الفاتورة الآجلة تتطلب حساب مورد من الجزء 251 / A credit invoice needs a part-251 supplier account.',
                ['acc_no1' => 'ليس حساب مورد / not a supplier account']
            );
        }

        if ($billType === PurchasePolicy::BILL_CASH && ! PurchasePolicy::isCashAccount($accNo1)) {
            return $this->invalid(
                'الفاتورة النقدية تتطلب حساب صندوق أو بنك / A cash invoice needs a cash/bank account.',
                ['acc_no1' => 'ليس حساب صندوق/بنك / not a cash account']
            );
        }

        // 3) العملة تُشتق من رقم الحساب الدافع: لا تُقبل عملة من العميل
        //    The currency derives from the paying account number; the client never sends one.
        $curNo = ChartAccountNumbering::currencyOf($accNo1) ?? 1;
        $rate = $curNo === 1
            ? 1.0
            : (float) ($data['cur_price'] ?? PurchasePosting::suggestRate($curNo, (string) $data['date']));

        if ($rate <= 0) {
            $rate = PurchasePosting::suggestRate($curNo, (string) $data['date']);
        }

        // 4) السطور عبر الحارس المشترك (يضمن وجود الصنف الفرعي وتبعيته)
        //    Lines go through the shared guard, which enforces the sub item and its ownership.
        $storeForCheck = StorePolicy::isStockStore($storeNo) ? $storeNo : 0;
        $normalized = PurchaseDocumentWriter::normalizeLines(
            (array) $data['lines'],
            StorePolicy::KIND_RECEIVE,
            $storeForCheck,
            true
        );

        if (! ($normalized['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $normalized['message'],
                'errors' => $normalized['errors'],
            ], 422);
        }

        $totals = PurchaseDocumentWriter::totals($normalized['lines'], (float) ($data['discount'] ?? 0));
        $rate = round($rate, 4);

        $manualNo = isset($data['manual_bill_no']) && (int) $data['manual_bill_no'] > 0
            ? (int) $data['manual_bill_no']
            : null;

        return [
            'header' => [
                'bill_type' => $billType,
                'date' => (string) $data['date'],
                'store_no' => $storeNo,
                'branch_no' => (int) ($data['branch_no'] ?? PurchasePolicy::DEFAULT_BRANCH),
                'prov_no' => isset($data['prov_no']) ? (int) $data['prov_no'] : null,
                'prov_name' => $this->resolveSupplierName($data, $accNo1),
                'prov_bill' => $data['prov_bill'] ?? null,
                'acc_no1' => $accNo1,
                'acc_no' => $this->resolveStockAccount($data, $storeNo, $existing),
                'cur_no' => 1,
                'cur_price' => 1.0,
                'cur_no1' => $curNo,
                'cur_price1' => $rate,
                'details' => $data['details'] ?? null,
                'manual_no' => $manualNo,
            ],
            'lines' => $normalized['lines'],
            'totals' => $totals,
        ];
    }

    /**
     * إنشاء الفاتورة داخل معاملة: قيد ← رأس ← سطور
     * Create the invoice inside a transaction: entry, header, lines.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createInvoice(array $payload, Request $request): array
    {
        $year = PurchasePolicy::dbYear();
        $billNo = PurchasePolicy::nextBillNo($year);
        $h = $payload['header'];

        $h['manual_no'] = $h['manual_no'] ?? PurchasePolicy::manualBillNo($h['prov_bill'], $billNo);

        $note = PurchaseDocumentWriter::clip($h['details'], PurchasePolicy::DETAILES_LEN)
            ?? PurchasePolicy::invoiceNote($h['bill_type'], (int) $h['manual_no'], $h['prov_bill']);

        $netBase = round((float) $payload['totals']['net'] * (float) $h['cur_price1'], 2);
        $seqPrice = PurchasePosting::resolveRateSeq((int) $h['cur_no1'], (float) $h['cur_price1'], (string) $h['date']);

        $entryNo = PurchasePosting::postInvoice([
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'manual_no' => (int) $h['manual_no'],
            'net' => (float) $payload['totals']['net'],
            'net_base' => $netBase,
            'stock_account' => (int) $h['acc_no'],
            'party_account' => (int) $h['acc_no1'],
            'seq_price' => $seqPrice,
            'note' => $note,
        ]);

        $user = $request->user();

        PurchaseDocumentWriter::insertInvoice([
            'bill_no' => $billNo,
            'year' => $year,
            'bill_type' => $h['bill_type'],
            'date' => $h['date'],
            'prov_no' => $h['prov_no'],
            'total' => $payload['totals']['total'],
            'cur_no' => $h['cur_no'],
            'cur_price' => $h['cur_price'],
            'prov_bill' => $h['prov_bill'],
            'details' => $note,
            'store_no' => $h['store_no'],
            'entry_no' => $entryNo,
            'prov_name' => $h['prov_name'],
            'dis_rate' => $payload['totals']['rate'],
            'discount' => $payload['totals']['discount'],
            'net' => $payload['totals']['net'],
            'cur_no1' => $h['cur_no1'],
            'cur_price1' => $h['cur_price1'],
            'seq_price1' => $seqPrice,
            'acc_no' => $h['acc_no'],
            'acc_no1' => $h['acc_no1'],
            'manual_no' => $h['manual_no'],
            'created_by_name' => $user?->USER_NAME_AR ?? $user?->name ?? null,
            'updated_by_name' => null,
            'branch_no' => $h['branch_no'],
            'created_by' => PurchasePolicy::legacyUserId($user?->getAuthIdentifier()),
            'the_time' => PurchasePolicy::dbTime(),
            'seq_bil' => PurchasePolicy::seqBil($year, $billNo),
        ]);

        PurchaseDocumentWriter::insertInvoiceLines($billNo, $year, $payload['lines'], (float) $h['cur_price1']);

        return [
            'bill_no' => $billNo,
            'year' => $year,
            'manual_bill_no' => (int) $h['manual_no'],
            'entry_no' => $entryNo,
            'net' => $payload['totals']['net'],
            'net_base' => $netBase,
        ];
    }

    /**
     * تعديل الفاتورة داخل معاملة
     * Update the invoice inside a transaction.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function updateInvoice(array $payload, array $header, int $billNo, int $year, ?int $oldEntryNo, Request $request): array
    {
        $h = $payload['header'];

        // الفاتورة التي عليها مردودات: لا تغيير للمخزن ولا للطرف ولا للنوع، ولا إنقاص كمية
        // تحت المردود، وإلا صار المردود يشير إلى ما لم يُشترَ.
        // An invoice with returns keeps its store, party and type, and no line may drop below
        // what was already returned.
        if ($this->returnsCount($billNo, $year) > 0) {
            $locked = [
                'store_no' => [$this->int($header, 'STORE_NO'), (int) $h['store_no']],
                'bill_type' => [$this->int($header, 'BILL_TYPE'), (int) $h['bill_type']],
                'acc_no1' => [$this->int($header, 'ACC_NO1'), (int) $h['acc_no1']],
            ];

            foreach ($locked as $field => [$before, $after]) {
                if ($before !== $after) {
                    throw new \RuntimeException("لا يمكن تغيير {$field} لفاتورة عليها مردودات / A field of an invoice with returns cannot change.");
                }
            }

            $this->assertReturnedQuantitiesRespected($billNo, $year, $payload['lines']);
        }

        $h['manual_no'] = $this->int($header, 'MANUAL_BILL_NO')
            ?? PurchasePolicy::manualBillNo($h['prov_bill'], $billNo);

        $note = PurchaseDocumentWriter::clip($h['details'], PurchasePolicy::DETAILES_LEN)
            ?? PurchasePolicy::invoiceNote($h['bill_type'], (int) $h['manual_no'], $h['prov_bill']);

        $netBase = round((float) $payload['totals']['net'] * (float) $h['cur_price1'], 2);
        $seqPrice = PurchasePosting::resolveRateSeq((int) $h['cur_no1'], (float) $h['cur_price1'], (string) $h['date']);

        $entryNo = PurchasePosting::postInvoice([
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'manual_no' => (int) $h['manual_no'],
            'net' => (float) $payload['totals']['net'],
            'net_base' => $netBase,
            'stock_account' => (int) $h['acc_no'],
            'party_account' => (int) $h['acc_no1'],
            'seq_price' => $seqPrice,
            'note' => $note,
        ]);

        $user = $request->user();

        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.BUY_BILL
                SET BILL_TYPE = :bill_type, DAT = TO_DATE(:dat, \'YYYY-MM-DD\'), PROV_NO = :prov_no,
                    TOTAL = :total, CUR_NO = :cur_no, CUR_PRICE = :cur_price, PROV_BILL = :prov_bill,
                    SANF_NUMS = 1, DETAILES = :details, STORE_NO = :store_no, ENTRY_NO = :entry_no,
                    PROV_NAME = :prov_name, DIS_RATE = :dis_rate, DISCOUNT = :discount, NET = :net,
                    CUR_NO1 = :cur_no1, CUR_PRICE1 = :cur_price1, SEQ_PRICE1 = :seq_price1,
                    ACC_NO = :acc_no, ACC_NO1 = :acc_no1, MANUAL_BILL_NO = :manual_no,
                    LAST_UPDATED_BY_NAME = :updated_by_name, BRANCH_NO = :branch_no,
                    LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :updated_by,
                    LAST_UPDATE_TIME = :the_time
              WHERE BILL_NO = :bill_no AND YEAR = :year',
            PurchaseDocumentWriter::bindings([
                'bill_type' => $h['bill_type'],
                'dat' => $h['date'],
                'prov_no' => $h['prov_no'],
                'total' => $payload['totals']['total'],
                'cur_no' => $h['cur_no'],
                'cur_price' => $h['cur_price'],
                'prov_bill' => PurchaseDocumentWriter::clip($h['prov_bill'], PurchasePolicy::PROV_BILL_LEN),
                'details' => $note,
                'store_no' => $h['store_no'],
                'entry_no' => $entryNo,
                'prov_name' => PurchaseDocumentWriter::clip($h['prov_name'], PurchasePolicy::PROV_NAME_LEN),
                'dis_rate' => $payload['totals']['rate'],
                'discount' => $payload['totals']['discount'],
                'net' => $payload['totals']['net'],
                'cur_no1' => $h['cur_no1'],
                'cur_price1' => $h['cur_price1'],
                'seq_price1' => $seqPrice,
                'acc_no' => $h['acc_no'],
                'acc_no1' => $h['acc_no1'],
                'manual_no' => $h['manual_no'],
                'updated_by_name' => PurchaseDocumentWriter::clip($user?->USER_NAME_AR ?? $user?->name ?? null, PurchasePolicy::CREATED_BY_NAME_LEN),
                'branch_no' => $h['branch_no'],
                'updated_by' => PurchasePolicy::legacyUserId($user?->getAuthIdentifier()),
                'the_time' => PurchasePolicy::dbTime(),
                'bill_no' => $billNo,
                'year' => $year,
            ])
        );

        // القيد القديم يُحذف بعد أن صار الرأس يشير إلى الجديد (المفتاح الأجنبي غير فارغ)
        // The old entry goes only after the header points at the new one (the FK is NOT NULL).
        if ($oldEntryNo !== null && $oldEntryNo !== $entryNo) {
            JournalWriter::deleteEntry($oldEntryNo);
        }

        PurchaseDocumentWriter::syncInvoiceLines($billNo, $year, $payload['lines'], (float) $h['cur_price1']);

        return [
            'bill_no' => $billNo,
            'year' => $year,
            'manual_bill_no' => (int) $h['manual_no'],
            'entry_no' => $entryNo,
            'net' => $payload['totals']['net'],
            'net_base' => $netBase,
        ];
    }

    /**
     * التحقق من أن كل مجموعة سطور لم تنقص تحت المردود منها
     * Assert no line group dropped below what was already returned.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertReturnedQuantitiesRespected(int $billNo, int $year, array $lines): void
    {
        $returned = [];

        foreach (DB::connection('oracle')->select(
            'SELECT r.SANF_NO, r.SANF_DNO, r.CAP, SUM(r.RET_QTY) AS QTY
               FROM ACCOUNTS.RETURN_BUY_DETAILES r
               JOIN ACCOUNTS.RETURN_BUY h ON h.RETURN_NO = r.RETURN_NO AND h.YEAR = r.YEAR
              WHERE h.BILL_NO = :bill_no AND h.BILL_YEAR = :year
              GROUP BY r.SANF_NO, r.SANF_DNO, r.CAP',
            ['bill_no' => $billNo, 'year' => $year]
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $returned[$r['SANF_NO'].'|'.$r['SANF_DNO'].'|'.$r['CAP']] = (float) $r['QTY'];
        }

        if ($returned === []) {
            return;
        }

        $incoming = [];

        foreach ($lines as $line) {
            $key = $line['sanf_no'].'|'.$line['sanf_dno'].'|'.(float) $line['cap'];
            $incoming[$key] = ($incoming[$key] ?? 0) + (float) $line['qty'];
        }

        foreach ($returned as $key => $qty) {
            if (($incoming[$key] ?? 0) + 0.0001 < $qty) {
                throw new \RuntimeException(
                    'لا يمكن إنقاص كمية سطر مردود منه بالكامل / A line already returned cannot drop below the returned quantity.'
                );
            }
        }
    }

    /**
     * أصناف يصبح رصيدها سالباً لو حُذفت الفاتورة
     * Items whose balance would go negative if the invoice were deleted.
     *
     * @return array<int, string>
     */
    private function deletionShortages(int $billNo, int $year, int $storeNo): array
    {
        $needed = [];

        foreach (DB::connection('oracle')->select(
            'SELECT SANF_NO, SUM(QTY) AS QTY FROM ACCOUNTS.BUY_DETAILES WHERE BILL_NO = :bill_no AND YEAR = :year GROUP BY SANF_NO',
            ['bill_no' => $billNo, 'year' => $year]
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $needed[(int) $r['SANF_NO']] = (float) ($r['QTY'] ?? 0);
        }

        if ($needed === []) {
            return [];
        }

        $available = InventoryLineGuard::availableQuantities($storeNo, array_keys($needed));
        $shortages = [];

        foreach ($needed as $sanfNo => $qty) {
            $onHand = $available[$sanfNo] ?? 0.0;

            if (round($onHand - $qty, 3) < 0) {
                $shortages[] = "الصنف [{$sanfNo}]: المتاح ".$onHand.' والمطلوب حذفه '.$qty;
            }
        }

        return $shortages;
    }

    /**
     * اسم المورد: من ربط `PROV_NO` أو من حساب الطرف أو نص الحمولة
     * The supplier name: from the PROV link, the party account, or the payload text.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSupplierName(array $data, int $accNo1): string
    {
        $name = trim((string) ($data['prov_name'] ?? ''));

        if (($data['prov_no'] ?? null) !== null) {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT PROV_NAME FROM ACCOUNTS.PROV WHERE PROV_NO = :prov',
                ['prov' => (int) $data['prov_no']]
            );

            $provName = trim((string) (reset($row) ?: ''));

            if ($provName !== '') {
                return $provName;
            }
        }

        if ($name === '') {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT ACC_NAME FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :acc',
                ['acc' => $accNo1]
            );

            $name = trim((string) (reset($row) ?: ''));
        }

        return $name === '' ? 'مورد' : $name;
    }

    /**
     * الحساب المدين (المخزون): يُحفظ القديم ما لم يتغيّر المخزن
     * The debit (stock) account: the old one is kept unless the store changed.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $existing
     */
    private function resolveStockAccount(array $data, int $storeNo, ?array $existing): int
    {
        if (isset($data['acc_no']) && (int) $data['acc_no'] > 0) {
            return (int) $data['acc_no'];
        }

        if ($existing !== null) {
            $currentStore = $this->int($existing, 'STORE_NO');
            $currentAcc = $this->int($existing, 'ACC_NO');

            // مخزن لم يتغيّر ⇒ يبقى الحساب كما رحّله النظام القديم (بعض الفواتير على 36110001)
            // Same store keeps the account the legacy posted (some bills debit 36110001).
            if ($currentStore === $storeNo && $currentAcc !== null) {
                return $currentAcc;
            }
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT INV_ACC_NO FROM ACCOUNTS.STORES WHERE STORE_NO = :store',
            ['store' => $storeNo]
        );

        $acc = (int) (reset($row) ?: 0);

        return $acc > 0 ? $acc : PurchasePolicy::STOCK_ACCOUNT;
    }
}
