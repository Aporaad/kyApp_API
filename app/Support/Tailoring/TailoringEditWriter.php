<?php

namespace App\Support\Tailoring;

use App\Http\Controllers\Tailoring\TailoringCreateController;
use App\Support\ArabicEncoding;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * كاتب تعديل فاتورة التفصيل — معاملة واحدة للمحتوى والمال.
 * The tailoring edit writer — one transaction for content and finance.
 */
final class TailoringEditWriter
{
    /**
     * تعديل كامل قبل «تسليم للقص».
     * Full edit before cutting handover.
     */
    public function fullUpdate(Request $request, int $billNo, int $year): array
    {
        $expected = (string) $request->input('expected_revision', '');
        $prepared = app(TailoringCreateController::class)->prepareWritePayload($request, true, true);
        if ($prepared instanceof JsonResponse) {
            $this->throwResponse($prepared);
        }

        $preparedTemplate = $prepared;

        return $this->transaction(function () use ($request, $billNo, $year, $expected, $preparedTemplate): array {
            // كل محاولة تبدأ من الحمولة الأصلية؛ أي عميل/صنف أُنشئ في محاولة ملغاة لا يُعاد استخدامه.
            // Every attempt starts from the original payload; rolled-back customers/items are never reused.
            $prepared = $preparedTemplate;
            $old = $this->lockedSnapshot($billNo, $year, $expected);
            if (! TailoringEditPolicy::canEditContent($old)) {
                throw new TailoringEditException(
                    'وصلت الفاتورة إلى «تسليم للقص»؛ لا يمكن تعديل بنيتها / Cutting handover blocks content edits',
                    409,
                    'cutting_handover_reached'
                );
            }
            $prepared['date'] = (string) $old['identity']['date'];
            $prepared['manual_no'] = (int) $old['identity']['manual_bill_no'];
            $prepared['header']['bill_kind'] = (int) $old['identity']['bill_kind'];
            $this->assertEditableIdentity($request, $old);
            $this->preserveRemovedEditFields($prepared, $old, $request);
            if ($prepared['models'] === [] && $old['models'] !== []) {
                throw new TailoringEditException(
                    'لا يمكن حذف كل موديلات الفاتورة / An invoice cannot be left without models',
                    422,
                    'models_required'
                );
            }
            if (($prepared['customer']['mode'] ?? null) === 'existing'
                && (int) $prepared['customer']['cust_no'] !== (int) $old['header']['cust_no']
                && (int) ($prepared['customer']['stopping_cust'] ?? 0) === 1) {
                throw new TailoringEditException(
                    'لا يمكن إسناد الفاتورة إلى عميل موقوف / A stopped customer cannot be assigned',
                    422,
                    'stopped_customer'
                );
            }
            app(TailoringCreateController::class)->materializePreparedPayload($prepared);
            $this->assertAccounts($prepared['finance']);
            $this->syncCustomerName($request, $prepared, $old);
            $this->upsertModels($prepared, $old);
            $this->applyHeader($prepared, $old, $request);
            $this->applySalesDetail($prepared, $old);

            $preserve = $this->mustPreserveJournal($old);
            TailoringFinancialUpdate::apply(
                $old,
                $prepared['finance'],
                (string) ($prepared['header']['details'] ?? $old['header']['details']),
                (string) $prepared['date'],
                (int) $prepared['manual_no'],
                (int) $prepared['customer']['cust_no'],
                (string) $prepared['customer']['cust_name'],
                $preserve
            );
            $this->touchAudit($old, $request);

            return TailoringEditSnapshot::read($billNo, $year);
        });
    }

    /**
     * تعديل مالي وآمن في جميع المراحل.
     * Financial and safe-field edit in every workflow stage.
     */
    public function financialUpdate(Request $request, int $billNo, int $year): array
    {
        $expected = (string) $request->input('expected_revision', '');
        $this->validateFinancialRequest($request);

        return $this->transaction(function () use ($request, $billNo, $year, $expected): array {
            $old = $this->lockedSnapshot($billNo, $year, $expected);
            $finance = $this->financialPayload($request, $old);
            $this->applyPiecePrices($request, $billNo, $year);
            $gross = $this->grossFromPieces($billNo, $year);
            $lineDiscount = round((float) ($finance['line_discount'] ?? 0), 2);
            $discount = (float) ($finance['discount'] ?? 0);
            if ($lineDiscount + $discount > $gross) {
                throw new TailoringEditException('الخصم أكبر من إجمالي الفاتورة / Discount exceeds gross total');
            }
            $total = $gross > 0
                ? round($gross - $lineDiscount - $discount, 2)
                : round((float) ($old['finance']['total'] ?? 0), 2);
            if ($request->input('finance.total') !== null) {
                $total = round((float) $request->input('finance.total'), 2);
                if ($gross > 0 && $total > $gross - $lineDiscount + 0.01) {
                    throw new TailoringEditException(
                        'الإجمالي النهائي يتجاوز إجمالي الأقمشة بعد الخصم / Final total exceeds gross less line discount',
                        422,
                        'invalid_total'
                    );
                }
                $discount = $gross > 0
                    ? round(max(0, $gross - $lineDiscount - $total), 2)
                    : 0.0;
                $finance['discount'] = $discount;
                $finance['discount_rate'] = $gross > 0
                    ? round($discount / $gross * 100, 2)
                    : 0.0;
            }
            $finance['total'] = $total;
            $finance['net'] = round($total - (float) $finance['paid'], 2);
            $finance['gross'] = $gross;
            $finance['line_discount'] = $lineDiscount;
            $finance['discount'] = $discount;
            $finance['line_discount'] = $lineDiscount;
            $finance['remaining'] = round($total - (float) $finance['paid'], 2);
            $this->assertAccounts($finance);
            $this->applyFinancialHeader($request, $finance, $old);
            $this->applySalesDetail(['finance' => $finance, 'pieces_count' => $this->pieceCount($billNo, $year), 'gross' => $gross], $old);

            TailoringFinancialUpdate::apply(
                $old,
                $finance,
                (string) ($request->input('header.details') ?? $old['header']['details']),
                (string) $old['identity']['date'],
                (int) $old['identity']['manual_bill_no'],
                (int) $old['header']['cust_no'],
                (string) $old['header']['cust_name'],
                $this->mustPreserveJournal($old)
            );
            $this->touchAudit($old, $request);

            return TailoringEditSnapshot::read($billNo, $year);
        });
    }

    // / معاملة واحدة مع إعادة محاولة تصادم المعرّفات فقط (ORA-00001).
    // / One transaction, retrying identity collisions only (ORA-00001).
    private function transaction(callable $callback): array
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::connection('oracle')->transaction($callback);
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! str_contains((string) $e->getMessage(), 'ORA-00001')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('تعذّر تنفيذ معاملة التعديل / Edit transaction failed');
    }

    // / تحقق صارم لحمولة التعديل المالي مع رفض أي حقول غير معروفة.
    // / Strict financial-edit validation, rejecting unknown fields.
    private function validateFinancialRequest(Request $request): void
    {
        $allowed = [
            'expected_revision' => [],
            'header' => ['ref_no', 'details', 'permanent_cust', 'urgent', 'giving_note', 'measurements_by'],
            'customer' => ['phone'],
            'finance' => ['paid', 'cust_known', 'acc_no1', 'acc_no2', 'transfer_no', 'total'],
            'piece_prices' => ['seq_no1', 'price'],
        ];
        $errors = [];
        foreach ($request->all() as $key => $value) {
            if (! array_key_exists($key, $allowed)) {
                $errors[$key] = ['unknown field'];

                continue;
            }
            if ($allowed[$key] === []) {
                continue;
            }
            if (! is_array($value)) {
                $errors[$key] = ['must be an object'];

                continue;
            }
            if ($key === 'piece_prices') {
                foreach ($value as $index => $row) {
                    if (! is_array($row)) {
                        $errors[$key.'.'.$index] = ['must be an object'];

                        continue;
                    }
                    foreach (array_keys($row) as $field) {
                        if (! in_array($field, $allowed[$key], true)) {
                            $errors[$key.'.'.$index.'.'.$field] = ['unknown field'];
                        }
                    }
                }

                continue;
            }
            foreach (array_keys($value) as $field) {
                if (! in_array($field, $allowed[$key], true)) {
                    $errors[$key.'.'.$field] = ['unknown field'];
                }
            }
        }
        $validator = Validator::make($request->all(), [
            'expected_revision' => 'required|string|size:64',
            'header.ref_no' => 'nullable|string|max:20',
            'header.details' => 'nullable|string|max:180',
            'header.permanent_cust' => 'nullable|boolean',
            'header.urgent' => 'nullable|boolean',
            'header.giving_note' => 'nullable|string|max:100',
            'header.measurements_by' => 'nullable|string|max:20',
            'customer.phone' => 'nullable|string|max:15',
            'finance.paid' => 'nullable|numeric|min:0',
            'finance.cust_known' => 'nullable|boolean',
            'finance.acc_no1' => 'nullable|integer|min:1',
            'finance.acc_no2' => 'nullable|integer|min:1',
            'finance.transfer_no' => 'nullable|string|max:15',
            'finance.total' => 'nullable|numeric|min:0',
            'piece_prices' => 'nullable|array|max:200',
            'piece_prices.*.seq_no1' => 'required|integer|min:1',
            'piece_prices.*.price' => 'required|numeric|gt:0',
        ]);
        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->toArray());
        }
        if ($request->boolean('header.urgent') && trim((string) $request->input('header.giving_note', '')) === '') {
            $errors['header.giving_note'] = ['required when urgent'];
        }
        $ids = [];
        foreach ((array) $request->input('piece_prices', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ids[] = (int) ($row['seq_no1'] ?? 0);
        }
        if (count($ids) !== count(array_unique($ids))) {
            $errors['piece_prices'] = ['duplicate piece id'];
        }
        if ($errors !== []) {
            throw new TailoringEditException(
                'بيانات التعديل المالي غير صالحة / Invalid financial edit payload',
                422,
                'invalid_payload',
                $errors
            );
        }
    }

    private function lockedSnapshot(int $billNo, int $year, string $expected): array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT BILL_NO, YEAR FROM ACCOUNTS.SALES_BILL
              WHERE BILL_NO = :bill_no AND YEAR = :year AND CUTTING_OR_SLAES = 1
              FOR UPDATE',
            ['bill_no' => $billNo, 'year' => $year]
        );
        if ($row === null || $row === []) {
            throw new TailoringEditException('فاتورة التفصيل غير موجودة / Cutting bill not found', 404, 'not_found');
        }
        $snapshot = TailoringEditSnapshot::read($billNo, $year);
        if ($snapshot === null) {
            throw new TailoringEditException('فاتورة التفصيل غير موجودة / Cutting bill not found', 404, 'not_found');
        }
        if ($expected === '' || ! hash_equals($snapshot['revision'], $expected)) {
            throw new TailoringEditException(
                'تغيّرت الفاتورة من مستخدم آخر؛ حدّث البيانات ثم أعد المحاولة / The invoice changed; refresh before retrying',
                409,
                'stale_revision'
            );
        }

        return $snapshot;
    }

    private function assertEditableIdentity(Request $request, array $old): void
    {
        $date = (string) ($request->input('header.date') ?? $old['identity']['date']);
        $manual = (int) ($request->input('header.manual_bill_no') ?? $old['identity']['manual_bill_no']);
        $kind = (int) ($request->input('header.bill_kind') ?? $old['identity']['bill_kind']);
        if ($date !== (string) $old['identity']['date']
            || $manual !== (int) $old['identity']['manual_bill_no']
            || $kind !== (int) $old['identity']['bill_kind']) {
            throw new TailoringEditException(
                'هوية الفاتورة والرقم اليدوي والتاريخ والفئة ثابتة / Invoice identity, manual number, date and kind are immutable',
                409,
                'immutable_identity'
            );
        }
    }

    /**
     * الحفاظ على الحقول المحذوفة من واجهة التعديل بقيمها المخزّنة، وحفظ إجمالي الفاتورة القديمة بلا قطع.
     * Preserve removed edit fields at their stored values and keep the legacy total for piece-less bills.
     */
    private function preserveRemovedEditFields(array &$prepared, array $old, Request $request): void
    {
        $prepared['customer']['address'] = $old['header']['address'] ?? null;
        $prepared['party_account'] = $old['finance']['acc_no'] ?? $prepared['party_account'];

        $defaults = [
            'acc_no' => $prepared['party_account'],
            'cur_no' => 1,
            'cur_price' => 1.0,
            'cur_no1' => 1,
            'cur_price1' => 1.0,
            'select1' => 1,
            'transfer_through' => null,
            'line_discount' => 0.0,
        ];
        foreach ($defaults as $key => $default) {
            $prepared['finance'][$key] = $old['finance'][$key] ?? $default;
        }

        $gross = round((float) ($prepared['finance']['gross'] ?? $prepared['gross'] ?? 0), 2);
        $lineDiscount = round((float) $prepared['finance']['line_discount'], 2);
        $requestedTotal = $request->input('finance.total');
        if (is_numeric($requestedTotal)) {
            $total = round((float) $requestedTotal, 2);
            if ($gross > 0 && $total > $gross - $lineDiscount + 0.01) {
                throw new TailoringEditException(
                    'الإجمالي النهائي يتجاوز إجمالي الأقمشة بعد الخصم / Final total exceeds gross less line discount',
                    422,
                    'invalid_total'
                );
            }
            $discount = $gross > 0 ? round(max(0, $gross - $lineDiscount - $total), 2) : 0.0;
        } else {
            $discount = round((float) ($old['finance']['discount'] ?? 0), 2);
            $total = $gross > 0
                ? round($gross - $lineDiscount - $discount, 2)
                : round((float) ($old['finance']['total'] ?? 0), 2);
        }
        if ($total < 0) {
            throw new TailoringEditException('الخصم أكبر من إجمالي الفاتورة / Discount exceeds gross total');
        }

        $prepared['finance']['discount'] = $discount;
        $prepared['finance']['discount_rate'] = $gross > 0 ? round($discount / $gross * 100, 2) : 0.0;
        $prepared['finance']['total'] = $total;
        $prepared['finance']['net'] = round($total - (float) $prepared['finance']['paid'], 2);
        $prepared['finance']['remaining'] = $prepared['finance']['net'];
        $prepared['total'] = $total;
    }

    private function syncCustomerName(Request $request, array &$prepared, array $old): void
    {
        $name = trim((string) $request->input('customer.name', ''));
        if ($name === '' || ($prepared['customer']['mode'] ?? null) !== 'existing') {
            return;
        }
        if ($name === (string) $old['header']['cust_name']) {
            return;
        }
        $duplicate = (array) DB::connection('oracle')->selectOne(
            'SELECT CUST_NO FROM ACCOUNTS.CUST
              WHERE (CUST_NAME = :oracle OR CUST_NAME = :plain) AND CUST_NO <> :cust_no',
            [
                'oracle' => ArabicEncoding::toOracleSearchChars($name),
                'plain' => $name,
                'cust_no' => (int) $prepared['customer']['cust_no'],
            ]
        );
        if ($duplicate !== []) {
            throw new TailoringEditException('يوجد عميل آخر بهذا الاسم / Another customer already uses this name');
        }
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.CUST SET CUST_NAME = :name WHERE CUST_NO = :cust_no',
            [
                'name' => ArabicEncoding::toOracleSearchChars($name),
                'cust_no' => (int) $prepared['customer']['cust_no'],
            ]
        );
        $prepared['customer']['cust_name'] = $name;
    }

    private function upsertModels(array $prepared, array $old): void
    {
        $billNo = (int) $old['identity']['bill_no'];
        $year = (int) $old['identity']['year'];
        $ohid = (int) $old['identity']['ohid'];
        $oldModels = [];
        $oldPieces = [];
        $maxModelSuffix = 0;
        foreach ($old['models'] as $model) {
            $oldModels[(int) $model['seq_no']] = $model;
            $suffix = self::ohdidSuffix((int) $ohid, $model['ohdid'] ?? null);
            $maxModelSuffix = max($maxModelSuffix, $suffix);
            foreach ($model['pieces'] as $piece) {
                $oldPieces[(int) $piece['seq_no1']] = $piece;
            }
        }

        $keepModels = [];
        $keepPieces = [];
        foreach ($prepared['models'] as &$model) {
            $seqNo = $model['seq_no'] ?? null;
            if ($seqNo !== null && ! isset($oldModels[$seqNo])) {
                throw new TailoringEditException('الموديل لا يتبع هذه الفاتورة / The model does not belong to this invoice');
            }
            if ($seqNo === null) {
                $seqNo = TailoringNumbering::nextShapeSeq();
                $maxModelSuffix++;
                $model['seq_no'] = $seqNo;
                $model['ohdid'] = TailoringNumbering::modelOhdid($ohid, $maxModelSuffix);
                $this->insertShape($model, $old);
            } else {
                $this->updateShape($model, $old);
            }
            $keepModels[$seqNo] = true;
            foreach ($model['pieces'] as &$piece) {
                $pieceSeq = $piece['seq_no1'] ?? null;
                if ($pieceSeq !== null && ! isset($oldPieces[$pieceSeq])) {
                    throw new TailoringEditException('القطعة لا تتبع هذه الفاتورة / The piece does not belong to this invoice');
                }
                if ($pieceSeq === null) {
                    $pieceSeq = TailoringNumbering::nextPieceSeq();
                    $piece['seq_no1'] = $pieceSeq;
                    // OHDID بترقيم القديم المقيس: كل أقمشة الموديل تشترك برقم الموديل
                    // نفسه (مقيس على فاتورة قديمة: ست قطع بموديل واحد بـOHDID واحداً)
                    // OHDID in the measured legacy numbering: every fabric of the model
                    // shares the model's own number (measured on a legacy bill: six
                    // pieces of one model carrying one OHDID).
                    $piece['ohdid'] = $model['ohdid'];
                    $this->insertPiece($piece, $seqNo, $model, $old);
                } else {
                    $this->updatePiece($piece, $seqNo, $model, $oldPieces[$pieceSeq], $old);
                }
                $keepPieces[$pieceSeq] = true;
            }
            unset($piece);
        }
        unset($model);

        foreach ($oldPieces as $seqNo1 => $piece) {
            if (isset($keepPieces[$seqNo1])) {
                continue;
            }
            if ((int) ($old['stage']['movement_count'] ?? 0) > 0 || (int) ($piece['f_cancel'] ?? 0) !== 0 || (int) ($piece['ordsdid'] ?? 0) >= TailoringEditPolicy::CUTTING_HANDOVER_ORDSID) {
                throw new TailoringEditException('لا يمكن حذف قطعة ملغاة أو منفذة / Cancelled or executed pieces cannot be deleted');
            }
            DB::connection('oracle')->delete(
                'DELETE FROM ACCOUNTS.MULTI_CLOTHS WHERE SEQ_NO1 = :seq AND BILL_NO = :bill AND YEAR = :year',
                ['seq' => $seqNo1, 'bill' => $billNo, 'year' => $year]
            );
        }
        foreach ($oldModels as $seqNo => $model) {
            if (isset($keepModels[$seqNo])) {
                continue;
            }
            $remaining = (array) DB::connection('oracle')->selectOne(
                'SELECT SEQ_NO1 FROM ACCOUNTS.MULTI_CLOTHS WHERE SEQ_NO = :seq AND ROWNUM <= 1',
                ['seq' => $seqNo]
            );
            if ($remaining !== []) {
                throw new TailoringEditException('لا يمكن حذف موديل ما زالت له قطع / A model with remaining pieces cannot be deleted');
            }
            DB::connection('oracle')->delete(
                'DELETE FROM ACCOUNTS.SALES_DETAILES_SHAPES WHERE SEQ_NO = :seq AND BILL_NO = :bill AND YEAR = :year',
                ['seq' => $seqNo, 'bill' => $billNo, 'year' => $year]
            );
        }
    }

    private function insertShape(array $model, array $old): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.SALES_DETAILES_SHAPES
                (BILL_NO, YEAR, SEQ_NO, OHID, OHDID, SHAPE_STYLE_NO, STYL_NO, RQUEST_NO, STYLE_NAME,
                 BENEFICIARYNAME, NOTES2, QTY, BUTTON_TYPE, BUTTON_NO, BUTTONS_NUM,
                 COM_CABAK_TYPE, NECK_TYPE, POCKET_OR_WITHOUT, GABZOR_TYPE, MOBILE_POCKET, PEN_POCKET,
                 HEGAZI_TYPE, TIPPER_TYPE, L1, L2, L3, L4, L5, L6, L7, L8, L9, L10, L11, L12,
                 SANF_NO, SANF_DNO, CLOTH_TYPE, SHAPE_GABROZ_NO)
             VALUES (:bill_no, :year, :seq_no, :ohid, :ohdid, :family, :styl_no, 1, :style_name,
                     :beneficiary, :notes, NULL, :button_type, :button_no, :buttons_num,
                     :com_cabak_type, :neck_type, :pocket_or_without, :gabzor_type, :mobile_pocket, :pen_pocket,
                     :hegazi_type, :tipper_type, :l1, :l2, :l3, :l4, :l5, :l6, :l7, :l8, :l9, :l10, NULL, NULL,
                     :sanf_no, :sanf_dno, NULL, :gabzor_hand)',
            TailoringDocumentWriter::bindings($this->shapeBindings($model, $old))
        );
    }

    private function updateShape(array $model, array $old): void
    {
        $bindings = $this->shapeBindings($model, $old);
        // OHID/OHDID خاصان بالإدراج فقط؛ إرسالهما إلى UPDATE بلا Placeholders يسبب ORA-01036.
        // OHID/OHDID are insert-only; binding them to this UPDATE causes ORA-01036.
        unset($bindings['ohid'], $bindings['ohdid']);

        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.SALES_DETAILES_SHAPES
                SET SHAPE_STYLE_NO = :family, STYL_NO = :styl_no, STYLE_NAME = :style_name,
                    BENEFICIARYNAME = :beneficiary, NOTES2 = :notes,
                    BUTTON_TYPE = :button_type, BUTTON_NO = :button_no, BUTTONS_NUM = :buttons_num,
                    COM_CABAK_TYPE = :com_cabak_type, NECK_TYPE = :neck_type,
                    POCKET_OR_WITHOUT = :pocket_or_without, GABZOR_TYPE = :gabzor_type,
                    MOBILE_POCKET = :mobile_pocket, PEN_POCKET = :pen_pocket,
                    HEGAZI_TYPE = :hegazi_type, TIPPER_TYPE = :tipper_type,
                    L1 = :l1, L2 = :l2, L3 = :l3, L4 = :l4, L5 = :l5, L6 = :l6, L7 = :l7, L8 = :l8, L9 = :l9, L10 = :l10,
                    SANF_NO = :sanf_no, SANF_DNO = :sanf_dno, SHAPE_GABROZ_NO = :gabzor_hand
              WHERE SEQ_NO = :seq_no AND BILL_NO = :bill_no AND YEAR = :year',
            TailoringDocumentWriter::bindings($bindings)
        );
    }

    private function shapeBindings(array $model, array $old): array
    {
        return [
            'bill_no' => $old['identity']['bill_no'],
            'year' => $old['identity']['year'],
            'seq_no' => $model['seq_no'],
            'ohid' => $old['identity']['ohid'],
            'ohdid' => $model['ohdid'] ?? null,
            'family' => $model['family'],
            'styl_no' => $model['styl_no'],
            'style_name' => TailoringDocumentWriter::clip($model['style_label'], 30),
            'beneficiary' => TailoringDocumentWriter::clip($model['beneficiary'], 100),
            'notes' => TailoringDocumentWriter::clip($model['notes'], 150),
            'button_type' => TailoringDocumentWriter::clip($model['specs']['button_type'] ?? '', 30),
            'button_no' => TailoringDocumentWriter::clip($model['specs']['button_no'] ?? null, 30),
            'buttons_num' => $model['specs']['buttons_num'] ?? null,
            'com_cabak_type' => TailoringDocumentWriter::clip($model['specs']['com_cabak_type'] ?? null, 30),
            'neck_type' => TailoringDocumentWriter::clip($model['specs']['neck_type'] ?? null, 30),
            'pocket_or_without' => TailoringDocumentWriter::clip($model['specs']['pocket_or_without'] ?? null, 30),
            'gabzor_type' => TailoringDocumentWriter::clip($model['specs']['gabzor_type'] ?? null, 30),
            'mobile_pocket' => TailoringDocumentWriter::clip($model['specs']['mobile_pocket'] ?? null, 30),
            'pen_pocket' => TailoringDocumentWriter::clip($model['specs']['pen_pocket'] ?? null, 30),
            'hegazi_type' => TailoringDocumentWriter::clip($model['specs']['hegazi_type'] ?? null, 30),
            'tipper_type' => TailoringDocumentWriter::clip($model['specs']['tipper_type'] ?? null, 30),
            'l1' => $model['measurements']['L1'],
            'l2' => $model['measurements']['L2'],
            'l3' => $model['measurements']['L3'],
            'l4' => $model['measurements']['L4'],
            'l5' => $model['measurements']['L5'],
            'l6' => $model['measurements']['L6'],
            'l7' => $model['measurements']['L7'],
            'l8' => $model['measurements']['L8'],
            'l9' => $model['measurements']['L9'],
            'l10' => $model['measurements']['L10'],
            'sanf_no' => $model['item']['sanf_no'],
            'sanf_dno' => $model['item']['sanf_dno'],
            'gabzor_hand' => $model['gabzor_hand'] ? 1 : 0,
        ];
    }

    private function insertPiece(array $piece, int $seqNo, array $model, array $old): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.MULTI_CLOTHS
                (SEQ_NO1, SEQ_NO, BILL_NO, YEAR, OHID, OHDID, SANF_NO, SANF_DNO, PROD_NO,
                 CLOTH_NAME, CLOTH_TYPE, CLOTHS_COLOR, QTY, CONSUMED_CLOTH_QTY_HINSH,
                 CONSUMED_CLOTH_QTY_YARDS, CONSUMED_CLOTH_QTY_HINSH1, SHARKH, GABZOR_HAND,
                 TATRYZ_IN, TATRYZ_NO, HABNA_DUABL, BUTTONNO, SANFPRICE, SANF_COST, STK_COST,
                 SHAPE_STYLE_NO, STYLE_NAME, CLOTH_FRM_LOCAL_STORE, SHELF_NO,
                 CREATED_BY_NAME, F_CANCEL)
             VALUES (:seq_no1, :seq_no, :bill_no, :year, :ohid, :ohdid, :sanf_no, :sanf_dno, :prod_no,
                     :cloth_name, :cloth_type, :color_no, 1, :hinsh, :yards, :hinsh1, :sharkh, :gabzor_hand,
                     :tatryz_in, :tatryz_no, :habna_duabl, :button_no, :price, :sanf_cost, :stk_cost,
                     :family, :style_name, :from_store, NULL, :created_by_name, 0)',
            TailoringDocumentWriter::bindings($this->pieceBindings($piece, $seqNo, $model, $old))
        );
    }

    private function updatePiece(array $piece, int $seqNo, array $model, array $oldPiece, array $old): void
    {
        if ((int) ($oldPiece['f_cancel'] ?? 0) !== 0) {
            DB::connection('oracle')->update(
                'UPDATE ACCOUNTS.MULTI_CLOTHS SET SANFPRICE = :price
                  WHERE SEQ_NO1 = :seq AND BILL_NO = :bill AND YEAR = :year',
                ['price' => round((float) $piece['price'], 2), 'seq' => (int) $piece['seq_no1'], 'bill' => (int) $old['identity']['bill_no'], 'year' => (int) $old['identity']['year']]
            );

            return;
        }
        $bindings = $this->pieceBindings($piece, $seqNo, $model, $old);
        // هذه المفاتيح للإدراج فقط؛ وجودها في UPDATE بلا Placeholders يسبب ORA-01036.
        // These keys are insert-only; binding them to this UPDATE causes ORA-01036.
        unset($bindings['seq_no'], $bindings['ohid'], $bindings['ohdid'], $bindings['created_by_name']);

        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.MULTI_CLOTHS
                SET SANF_NO = :sanf_no, SANF_DNO = :sanf_dno, PROD_NO = :prod_no,
                    CLOTH_NAME = :cloth_name, CLOTH_TYPE = :cloth_type, CLOTHS_COLOR = :color_no,
                    CONSUMED_CLOTH_QTY_HINSH = :hinsh, CONSUMED_CLOTH_QTY_YARDS = :yards,
                    CONSUMED_CLOTH_QTY_HINSH1 = :hinsh1, SHARKH = :sharkh,
                    GABZOR_HAND = :gabzor_hand, TATRYZ_IN = :tatryz_in, TATRYZ_NO = :tatryz_no,
                    HABNA_DUABL = :habna_duabl, BUTTONNO = :button_no, SANFPRICE = :price, SANF_COST = :sanf_cost,
                    STK_COST = :stk_cost, SHAPE_STYLE_NO = :family, STYLE_NAME = :style_name,
                    CLOTH_FRM_LOCAL_STORE = :from_store
              WHERE SEQ_NO1 = :seq_no1 AND BILL_NO = :bill_no AND YEAR = :year',
            TailoringDocumentWriter::bindings($bindings)
        );
    }

    private function pieceBindings(array $piece, int $seqNo, array $model, array $old): array
    {
        return [
            'seq_no1' => $piece['seq_no1'],
            'seq_no' => $seqNo,
            'bill_no' => $old['identity']['bill_no'],
            'year' => $old['identity']['year'],
            'ohid' => $old['identity']['ohid'],
            'ohdid' => $piece['ohdid'] ?? null,
            'sanf_no' => $piece['sanf_no'],
            'sanf_dno' => $piece['sanf_dno'],
            'prod_no' => $model['item']['sanf_dno'],
            'cloth_name' => TailoringDocumentWriter::clip($piece['cloth_name'], 100),
            'cloth_type' => TailoringDocumentWriter::clip($piece['cloth_type'], 50),
            'color_no' => $piece['color_no'],
            'hinsh' => $piece['hinsh'],
            'yards' => $piece['yards'],
            'hinsh1' => $piece['hinsh1'],
            'sharkh' => TailoringDocumentWriter::clip($piece['sharkh'], 20),
            'gabzor_hand' => $piece['gabzor_hand'] ? 1 : 0,
            'tatryz_in' => $piece['tatryz_in'] ? 1 : 0,
            'tatryz_no' => $piece['tatryz_no'],
            'habna_duabl' => $piece['habna_duabl'] ? 1 : 0,
            'button_no' => $piece['button_no'] ?? null,
            'price' => $piece['price'],
            'sanf_cost' => $piece['sanf_cost'],
            'stk_cost' => $piece['stk_cost'],
            'family' => $model['family'],
            'style_name' => TailoringDocumentWriter::clip($model['style_label'], 30),
            'from_store' => $piece['from_store'],
            'created_by_name' => TailoringDocumentWriter::clip($old['created_by_name'] ?? null, 20),
        ];
    }

    private function applyHeader(array $prepared, array $old, Request $request): void
    {
        $customer = $prepared['customer'];
        $header = $prepared['header'];
        $finance = $prepared['finance'];
        $user = $this->legacyUser($request);
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.SALES_BILL
                SET MANUAL_BILL_NO = :manual_no, DAT = TO_DATE(:date, \'YYYY-MM-DD\'),
                    CUST_NO = :cust_no, CUST_NAME = :cust_name, TEL_NO1 = :phone, ADDRESS = :address,
                    REF_NO = :ref_no, DETAILES = :details, TOTAL = :total, NET = :net,
                    SANF_NUMS = :pieces, AMOUNT_PAID = :paid, DIS_RATE = :discount_rate,
                    DISCOUNT = :discount, CUR_NO = :cur_no, CUR_PRICE = :cur_price,
                    CUR_NO1 = :cur_no1, CUR_PRICE1 = :cur_price1, SELECT1 = :select1,
                    ACC_NO = :acc_no, ACC_NO1 = :acc_no1, ACC_NO2 = :acc_no2,
                    FIRST_TRANSFER = :transfer_no, TRANSFER_THROUGH = :transfer_through,
                    PERMANENT_CUST = :permanent, URGENT = :urgent, GIVING_IN = :giving_note,
                    VOUCHER_NO = :measurements_by, CUST_KNOWN = :cust_known,
                    BILLS_KINDS = :bill_kind,
                    LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :updated_by,
                    LAST_UPDATED_BY_NAME = :updated_by_name
              WHERE BILL_NO = :bill_no AND YEAR = :year AND CUTTING_OR_SLAES = 1',
            TailoringDocumentWriter::bindings([
                'manual_no' => $prepared['manual_no'],
                'date' => $prepared['date'],
                'cust_no' => $customer['cust_no'],
                'cust_name' => TailoringDocumentWriter::clip($customer['cust_name'], 150),
                'phone' => TailoringDocumentWriter::clip($customer['phone'] ?? null, 15),
                'address' => TailoringDocumentWriter::clip($customer['address'] ?? null, 120),
                'ref_no' => TailoringDocumentWriter::clip($header['ref_no'], 20),
                'details' => TailoringDocumentWriter::clip($header['details'], 180),
                'total' => $prepared['total'],
                'net' => round($prepared['total'] - $finance['paid'], 2),
                'pieces' => $prepared['pieces_count'],
                'paid' => $finance['paid'],
                'discount_rate' => $finance['discount_rate'],
                'discount' => $finance['discount'],
                'cur_no' => $finance['cur_no'],
                'cur_price' => $finance['cur_price'],
                'cur_no1' => $finance['cur_no1'],
                'cur_price1' => $finance['cur_price1'],
                'select1' => $finance['select1'],
                'acc_no' => $finance['acc_no'] ?? $prepared['party_account'],
                'acc_no1' => $finance['credit_account'],
                'acc_no2' => $finance['paid'] > 0 ? $finance['cash_account'] : null,
                'transfer_no' => $finance['transfer_no'],
                'transfer_through' => $finance['transfer_through'],
                'permanent' => $header['permanent_cust'] ? 1 : 0,
                'urgent' => $header['urgent'] ? 1 : 0,
                'giving_note' => TailoringDocumentWriter::clip($header['giving_note'], 100),
                'measurements_by' => TailoringDocumentWriter::clip($header['measurements_by'], 20),
                'cust_known' => $finance['cust_known'] ? 1 : 0,
                'bill_kind' => $header['bill_kind'],
                'updated_by' => $user['id'],
                'updated_by_name' => TailoringDocumentWriter::clip($user['name'], 100),
                'bill_no' => $old['identity']['bill_no'],
                'year' => $old['identity']['year'],
            ])
        );
    }

    private function applyFinancialHeader(Request $request, array $finance, array $old): void
    {
        $set = [];
        $bindings = ['bill_no' => $old['identity']['bill_no'], 'year' => $old['identity']['year']];
        $allowed = [
            'paid' => 'AMOUNT_PAID', 'cust_known' => 'CUST_KNOWN',
            'acc_no1' => 'ACC_NO1', 'acc_no2' => 'ACC_NO2',
            'transfer_no' => 'FIRST_TRANSFER',
        ];
        foreach ($allowed as $key => $column) {
            if ($request->exists("finance.$key")) {
                $set[] = "$column = :$key";
                $bindings[$key] = $finance[$key] ?? null;
            }
        }
        $set[] = 'TOTAL = :total';
        $set[] = 'NET = :net';
        $set[] = 'DISCOUNT = :discount_value';
        $set[] = 'DIS_RATE = :discount_rate_value';
        $bindings['total'] = $finance['total'];
        $bindings['net'] = $finance['net'];
        $bindings['discount_value'] = $finance['discount'];
        $bindings['discount_rate_value'] = $finance['discount_rate'];
        if ($request->input('customer.phone') !== null) {
            $set[] = 'TEL_NO1 = :phone';
            $bindings['phone'] = TailoringDocumentWriter::clip((string) $request->input('customer.phone'), 15);
        }
        foreach (['ref_no' => 'REF_NO', 'details' => 'DETAILES', 'urgent' => 'URGENT', 'giving_in' => 'GIVING_IN', 'measurements_by' => 'VOUCHER_NO', 'permanent_cust' => 'PERMANENT_CUST'] as $key => $column) {
            if ($request->exists("header.$key")) {
                $set[] = "$column = :$key";
                $bindings[$key] = TailoringDocumentWriter::bindings([$key => $request->input("header.$key")])[$key];
            }
        }
        $user = $this->legacyUser($request);
        $set[] = 'LAST_UPDATED_ON = SYSDATE';
        $set[] = 'LAST_UPDATED_BY = :updated_by';
        $set[] = 'LAST_UPDATED_BY_NAME = :updated_by_name';
        $bindings['updated_by'] = $user['id'];
        $bindings['updated_by_name'] = TailoringDocumentWriter::clip($user['name'], 100);
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.SALES_BILL SET '.implode(', ', $set).' WHERE BILL_NO = :bill_no AND YEAR = :year',
            TailoringDocumentWriter::bindings($bindings)
        );
    }

    private function applySalesDetail(array $prepared, array $old): void
    {
        $pieces = (int) $prepared['pieces_count'];
        $gross = (float) ($prepared['gross'] ?? $prepared['finance']['gross'] ?? $prepared['total']);
        $finance = $prepared['finance'];
        $existing = (array) DB::connection('oracle')->selectOne(
            'SELECT SEQ FROM ACCOUNTS.SALES_DETAILES WHERE BILL_NO = :bill_no AND YEAR = :year AND ROWNUM <= 1',
            ['bill_no' => $old['identity']['bill_no'], 'year' => $old['identity']['year']]
        );
        if ($existing === []) {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.SALES_DETAILES
                    (BILL_NO, YEAR, SANF_NO, SANF_PRICE, QTY, CAP, PCS, TOTAL, SEQ, IS_LOCAL, STK_COST, OHID,
                     DISCOUNT_RATE, DISCOUNT1)
                 VALUES (:bill_no, :year, 1, :price, :qty, 1, :pcs, :total, :seq, 1, 0, :ohid, :rate, :discount)',
                TailoringDocumentWriter::bindings([
                    'bill_no' => $old['identity']['bill_no'], 'year' => $old['identity']['year'],
                    'price' => $pieces > 0 ? round($gross / $pieces, 2) : 0,
                    'qty' => $pieces, 'pcs' => $pieces, 'total' => $finance['total'],
                    'seq' => TailoringNumbering::nextDetailSeq(), 'ohid' => $old['identity']['ohid'],
                    'rate' => $gross > 0 ? round($finance['line_discount'] / $gross * 100, 2) : 0,
                    'discount' => $finance['line_discount'],
                ])
            );

            return;
        }
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.SALES_DETAILES
                SET SANF_PRICE = :price, QTY = :qty, PCS = :pcs, TOTAL = :total,
                    DISCOUNT_RATE = :rate, DISCOUNT1 = :discount, OHID = :ohid
              WHERE BILL_NO = :bill_no AND YEAR = :year',
            TailoringDocumentWriter::bindings([
                'price' => $pieces > 0 ? round($gross / $pieces, 2) : 0,
                'qty' => $pieces, 'pcs' => $pieces, 'total' => $finance['total'],
                'rate' => $gross > 0 ? round($finance['line_discount'] / $gross * 100, 2) : 0,
                'discount' => $finance['line_discount'], 'ohid' => $old['identity']['ohid'],
                'bill_no' => $old['identity']['bill_no'], 'year' => $old['identity']['year'],
            ])
        );
    }

    private function applyPiecePrices(Request $request, int $billNo, int $year): void
    {
        $prices = $request->input('piece_prices', []);
        if (! is_array($prices)) {
            throw new TailoringEditException('أسعار القطع غير صالحة / Piece prices are invalid');
        }
        foreach ($prices as $item) {
            $seqNo1 = (int) ($item['seq_no1'] ?? 0);
            $price = (float) ($item['price'] ?? -1);
            if ($seqNo1 <= 0 || $price <= 0) {
                throw new TailoringEditException('سعر القطعة ورقمها مطلوبان / Piece number and positive price are required');
            }
            $updated = DB::connection('oracle')->update(
                'UPDATE ACCOUNTS.MULTI_CLOTHS SET SANFPRICE = :price
                  WHERE SEQ_NO1 = :seq AND BILL_NO = :bill AND YEAR = :year',
                ['price' => round($price, 2), 'seq' => $seqNo1, 'bill' => $billNo, 'year' => $year]
            );
            if ($updated === 0) {
                throw new TailoringEditException('القطعة لا تتبع هذه الفاتورة / The piece does not belong to this invoice');
            }
        }
    }

    private function financialPayload(Request $request, array $old): array
    {
        $finance = $old['finance'];
        foreach (['paid', 'cust_known', 'acc_no1', 'acc_no2', 'transfer_no'] as $key) {
            if (! $request->exists("finance.$key")) {
                continue;
            }
            $value = $request->input("finance.$key");
            $finance[$key] = match ($key) {
                'cust_known' => (bool) $value,
                'acc_no1', 'acc_no2' => $value === null ? null : (int) $value,
                'transfer_no' => $value,
                default => (float) $value,
            };
        }
        $finance['paid'] = (float) $finance['paid'];
        if ($finance['paid'] < 0) {
            throw new TailoringEditException('العربون لا يمكن أن يكون سالباً / Deposit cannot be negative');
        }
        if ($finance['paid'] > 0 && empty($finance['acc_no2'])) {
            throw new TailoringEditException('حساب العربون مطلوب / Deposit account is required');
        }
        $this->assertAccounts($finance);

        return $finance;
    }

    private function assertAccounts(array $finance): void
    {
        foreach (['cur_no', 'cur_no1'] as $key) {
            if (empty($finance[$key])) {
                continue;
            }
            $currency = (array) DB::connection('oracle')->selectOne(
                'SELECT CUR_NO FROM ACCOUNTS.CURRENCY WHERE CUR_NO = :cur_no',
                ['cur_no' => (int) $finance[$key]]
            );
            if ($currency === []) {
                throw new TailoringEditException('العملة غير موجودة / The currency does not exist', 422, 'invalid_currency');
            }
        }
        foreach (['acc_no', 'acc_no1', 'acc_no2'] as $key) {
            if (empty($finance[$key])) {
                continue;
            }
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT ACC_NO FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :acc',
                ['acc' => (int) $finance[$key]]
            );
            if ($row === []) {
                throw new TailoringEditException('الحساب المالي غير موجود / The financial account does not exist');
            }
        }
    }

    private function grossFromPieces(int $billNo, int $year): float
    {
        return round((float) self::first(DB::connection('oracle')->selectOne(
            'SELECT NVL(SUM(SANFPRICE), 0) AS N FROM ACCOUNTS.MULTI_CLOTHS WHERE BILL_NO = :bill AND YEAR = :year',
            ['bill' => $billNo, 'year' => $year]
        )), 2);
    }

    private function pieceCount(int $billNo, int $year): int
    {
        return (int) self::first(DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS N FROM ACCOUNTS.MULTI_CLOTHS WHERE BILL_NO = :bill AND YEAR = :year',
            ['bill' => $billNo, 'year' => $year]
        ));
    }

    private function mustPreserveJournal(array $snapshot): bool
    {
        if ($snapshot['capability']['cutting_handover_reached']) {
            return true;
        }
        foreach ($snapshot['external_links'] as $table => $count) {
            if ($table !== 'entry_audit' && (int) $count > 0) {
                return true;
            }
        }

        return ! TailoringFinancialUpdate::matchesInvoice($snapshot);
    }

    private function touchAudit(array $old, Request $request): void
    {
        $user = $this->legacyUser($request);
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.SALES_BILL_PRIVILAGE
                SET LAST_UPDATED_ON_HAND_S = SYSDATE, LAST_UPDATED_BY_HAND_S = :user_id,
                    LAST_UPDATED_BY_NAME_HAND_S = :user_name
              WHERE BILL_NO = :bill_no AND YEAR = :year',
            [
                'user_id' => $user['id'], 'user_name' => TailoringDocumentWriter::clip($user['name'], 60),
                'bill_no' => $old['identity']['bill_no'], 'year' => $old['identity']['year'],
            ]
        );
        if (! empty($old['journal']['entry'])) {
            DB::connection('oracle')->update(
                'UPDATE ACCOUNTS.ENTRY_MAIN_PRIVILAGE
                    SET LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :user_id,
                        LAST_UPDATED_BY_NAME = :user_name
                  WHERE ENTRY_NO = :entry_no',
                [
                    'user_id' => $user['id'], 'user_name' => TailoringDocumentWriter::clip($user['name'], 100),
                    'entry_no' => $old['identity']['entry_no'],
                ]
            );
        }
    }

    private function legacyUser(Request $request): array
    {
        $id = $request->user()?->getAuthIdentifier();
        $name = $request->user()?->USER_NAME_AR ?? null;
        if ($id !== null) {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT USER_ID, USER_NAME_AR FROM ACCOUNTS.NEW_USERS WHERE USER_ID = :id',
                ['id' => (int) $id]
            );
            if ($row !== []) {
                return ['id' => (int) reset($row), 'name' => ArabicEncoding::fixMojibake((string) ($row['USER_NAME_AR'] ?? $row['user_name_ar'] ?? $name))];
            }
        }

        return ['id' => null, 'name' => $name];
    }

    private static function first(mixed $row): mixed
    {
        return $row === null ? null : array_values((array) $row)[0] ?? null;
    }

    private static function ohdidSuffix(int $ohid, int|float|null $ohdid): int
    {
        if ($ohdid === null) {
            return 0;
        }
        $prefix = (string) $ohid;
        $value = (string) ((int) $ohdid);
        if (! str_starts_with($value, $prefix)) {
            return 0;
        }

        return (int) substr($value, strlen($prefix));
    }

    private function throwResponse(JsonResponse $response): never
    {
        $data = $response->getData(true);
        throw new TailoringEditException(
            (string) ($data['message'] ?? 'بيانات التعديل غير صالحة / Invalid edit payload'),
            $response->getStatusCode(),
            'invalid_payload',
            (array) ($data['errors'] ?? [])
        );
    }
}
