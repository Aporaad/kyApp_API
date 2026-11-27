<?php

namespace App\Http\Controllers\Tailoring;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\Tailoring\ClothConsumption;
use App\Support\Tailoring\ClothPricing;
use App\Support\Tailoring\DefaultMeasurements;
use App\Support\Tailoring\TailoringCustomerResolver;
use App\Support\Tailoring\TailoringDocumentWriter;
use App\Support\Tailoring\TailoringModelItemResolver;
use App\Support\Tailoring\TailoringNumbering;
use App\Support\Tailoring\TailoringPolicy;
use App\Support\Tailoring\TailoringPosting;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * متحكم إنشاء فاتورة التفصيل — الاقتباس (المرحلة 2) والحفظ (المرحلة 3)
 * The tailoring-creation controller — the quote (Phase 2) and the save (Phase 3).
 *
 * ── التسعير والاستهلاك على الخادم دائماً (قرار D3): الواجهة تعرض الناتج قابلاً للتعديل
 *    ولا تحسب شيئاً بنفسها.
 * ── Pricing and consumption always run server-side (decision D3): the UI displays the
 *    result as editable and computes nothing itself.
 */
class TailoringCreateController extends Controller
{
    /** الجداول / the tables */
    private const TABLE_SANFS = 'ACCOUNTS.SANFS';

    private const TABLE_SANFS_D = 'ACCOUNTS.SANFS_D';

    private const TABLE_PAL = 'ACCOUNTS.SANFS_D_PAL';

    /**
     * الاقتباس: الاستهلاك + التسعير + التوفّر لقطعة واحدة — قراءة فقط لا يكتب شيئاً
     * The quote: consumption + pricing + availability for one piece — reads nothing back.
     */
    public function quote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shape_style_no' => 'required|integer|min:1|max:12',
            'cloth_type' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! isset(TailoringPolicy::WIDTH_TYPES[(string) $value])) {
                    $fail('عرض القماش غير مدعوم / Unsupported fabric width');
                }
            }],
            'sanf_no' => 'required|integer|min:1',
            'sanf_dno' => 'required|integer|min:1',
            'from_store' => 'nullable|integer|in:1,2',
            'qty' => 'nullable|integer|min:1',
            'l1' => 'required|numeric|min:0',
            'l2' => 'nullable|numeric|min:0',
            'l3' => 'nullable|numeric|min:0',
            'l5' => 'required|numeric|min:0',
            'l8' => 'nullable|numeric|min:0',
            'com_cabak_type' => 'nullable|string|max:30',
            'pocket_or_without' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الاقتباس غير صالحة / The quote payload is invalid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $family = (int) $data['shape_style_no'];
            $itemTypeNo = 300 + $family;
            // نوع الاحتساب من TYPES.CALC_TYPE — قطريA/B/C/جاهز تحسب الاستهلاك بقواعد قطري
            // The consumption type via TYPES.CALC_TYPE — A/B/C/Ready consume as plain Qatari.
            $consumptionTypeNo = TailoringPolicy::consumptionTypeNo($family);
            $l1 = (float) $data['l1'];
            $l5 = (float) $data['l5'];
            $fromStore = (int) ($data['from_store'] ?? TailoringPolicy::CLOTH_FROM_SHOP);
            $qty = (int) ($data['qty'] ?? 1);

            // القماش الفرعي يجب أن يعود للقماش الرئيسي — إعادة التحقق لا الثقة بالواجهة
            // The variant must belong to the main fabric — revalidated, never trusted from UI.
            $variant = (array) DB::connection('oracle')->selectOne(
                'SELECT SANF_DNO, SANF_DNAME, PART_NO, COLOR_NO, DCOLOR
                   FROM ACCOUNTS.SANFS_D
                  WHERE SANF_DNO = :dno AND SANF_NO = :no',
                ['dno' => (int) $data['sanf_dno'], 'no' => (int) $data['sanf_no']]
            );

            if ($variant === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'القماش الفرعي لا يعود للقماش الرئيسي / The fabric variant does not belong to the main fabric',
                ], 422);
            }

            // القماش الرئيسي: PART_NO للتسعير والفئة
            // The main fabric: PART_NO for pricing and the category.
            $fabric = (array) DB::connection('oracle')->selectOne(
                'SELECT SANF_NO, SANF_NAME, PART_NO, CATEGORY
                   FROM ACCOUNTS.SANFS
                  WHERE SANF_NO = :no',
                ['no' => (int) $data['sanf_no']]
            );

            if ($fabric === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'القماش الرئيسي غير موجود / The main fabric was not found',
                ], 422);
            }

            $partNo = ArabicEncoding::normalizeStatementText(
                $this->pick($fabric, 'PART_NO')
            );

            // STYL_NO للموديل من نمط العائلة نفسه والحزام الحجمي — كما يحدده قاموس STYLE
            // The model's STYL_NO from the family's own type and the size band — as STYLE names it.
            $stylNo = $itemTypeNo * 100 + TailoringPolicy::sizeBand($l1);

            // مجموعات المواصفات من PRO_DTYPE — تُقرأ لا تُخمَّن
            // The spec groups from PRO_DTYPE — read, never guessed.
            $proGroup2 = ClothConsumption::proGroup(
                2,
                ArabicEncoding::normalizeStatementText($data['com_cabak_type'] ?? null)
            );
            $proGroup4 = ClothConsumption::proGroup(
                4,
                ArabicEncoding::normalizeStatementText($data['pocket_or_without'] ?? null)
            );

            // الاستهلاك — النقل الحرفي للإجراء القديم بنوع الاحتساب المُعيَّن
            // Consumption — the literal port, with the mapped consumption type.
            $consumption = ClothConsumption::calculate([
                'typeno' => $consumptionTypeNo,
                'cloth_type' => (string) $data['cloth_type'],
                'l1' => $l1,
                'l2' => isset($data['l2']) ? (float) $data['l2'] : null,
                'l3' => isset($data['l3']) ? (float) $data['l3'] : null,
                'l5' => $l5,
                'l8' => isset($data['l8']) ? (float) $data['l8'] : null,
                'pro_group2' => $proGroup2,
                'pro_group4' => $proGroup4,
                'qty' => $qty,
            ]);

            // التوفّر — مجموع PAL خارج المخازن المستثناة، وياردات لا هنش
            // Availability — the PAL sum outside excluded stores, in yards not hinsh.
            $available = $this->available((int) $data['sanf_dno']);
            $enough = $consumption['yards'] !== null && $available !== null
                && (float) $available >= (float) $consumption['yards'];

            // التسعيرة — من العمل كما يفعل النموذج القديم
            // Pricing — from the fabric's PART_NO as the legacy form does.
            $pricing = ClothPricing::price([
                'part_no' => $partNo,
                'styl_no' => $stylNo,
                'yards' => $consumption['yards'] === null ? null : (float) $consumption['yards'],
                'from_store' => $fromStore,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم حساب الاقتباس / Quote calculated',
                'data' => [
                    'hinsh' => $consumption['hinsh'],
                    'yards' => $consumption['yards'] === null ? null : round((float) $consumption['yards'], 2),
                    'hinsh1' => $consumption['hinsh1'],
                    'sharkh' => $consumption['sharkh'],
                    'available' => $available,
                    'enough' => $enough,
                    'typeno' => $itemTypeNo,
                    'consumption_typeno' => $consumptionTypeNo,
                    'styl_no' => $stylNo,
                    'sanf_no' => (int) $data['sanf_no'],
                    'sanf_dno' => (int) $data['sanf_dno'],
                    'part_no' => $partNo,
                    'stk_cost' => $pricing['stk_cost'],
                    'estimated_cost' => $pricing['estimated_cost'],
                    'sanf_cost' => $pricing['sanf_cost'],
                    'default_price' => $pricing['default_price'],
                    'pricing_status' => $pricing['pricing_status'],
                    'pricing_message' => $pricing['pricing_message'],
                    'ohdid_preview' => $this->nextOhdidPreview(),
                ],
            ], 200);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حساب الاقتباس / Failed to calculate the quote', $e);
        }
    }

    /**
     * معاينة رقم الطلب الفرعي (MULTI_CLOTHS.OHDID) — الأكبر الحالي + 1، تقديرية لأن
     * الاعتماد الفعلي يُركَّب من OHID وترتيب القطع عند الحفظ.
     * The sub-order number (MULTI_CLOTHS.OHDID) preview — current max + 1, an estimate
     * because the real one composes from OHID and the piece order at save time.
     */
    private function nextOhdidPreview(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(OHDID), 0) + 1 AS N FROM ACCOUNTS.MULTI_CLOTHS'
        );

        return (int) (reset($row) ?: 1);
    }

    /**
     * توفّر قماش فرعي — مجموع PAL خارج المخازن المستثناة كما في التسعيرة القديمة
     * A fabric variant's availability — the PAL sum outside excluded stores, as the legacy.
     */
    private function available(int $sanfDno): ?float
    {
        $excluded = implode(',', TailoringPolicy::EXCLUDED_STORES);

        try {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT SUM(PAL) AS P
                   FROM ACCOUNTS.SANFS_D_PAL
                  WHERE SANF_DNO = :dno AND STORE_NO NOT IN ('.$excluded.')',
                ['dno' => $sanfDno]
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $value = reset($row);

        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }

    /**
     * إنشاء فاتورة تفصيل — معاملة واحدة: قيد ثم رأس ثم موديلات ثم قطع، بلا كتابة مخزون مباشرة
     * Create a cutting bill — one transaction: entry, header, models, pieces; no direct stock write.
     *
     * ── القرارات المعتمدة: BILLS_KINDS=1 حصراً، وITEM_MOVE_D VIEW مشتق من الفاتورة وقطعها
     *    (لا نكتب مستند مخزون مستقلاً)، والاستهلاك والتعبئة الافتراضية يعيد الخادم حسابها.
     * ── Approved decisions: kind 1 only, ITEM_MOVE_D is a view derived from the bill and its
     *    pieces (no separate stock document is written), and the server recomputes consumption.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->prepareWritePayload($request, true);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(
                fn (): array => $this->createBill($payload, $request)
            ));

            // التدقيق بعد نجاح المعاملة فقط، وفشله لا يُفشل الطلب
            // Audit only after a successful transaction; its failure never fails the request.
            $this->logActivity($request, 'tailoring.invoice_created', [
                'entity_type' => 'cutting_bill',
                'entity_id' => $result['bill_no'].'/'.$result['year'],
                'description_ar' => 'إنشاء فاتورة تفصيل رقم '.$result['manual_bill_no']
                    .' — عميل '.$result['cust_name'].' إجمالي '.$result['total'].' عدد '.$result['pieces_count'],
            ]);

            if ($result['customer_created'] === true) {
                $this->logActivity($request, 'tailoring.customer_created', [
                    'entity_type' => 'customer',
                    'entity_id' => (string) $result['cust_no'],
                    'description_ar' => 'إنشاء عميل جديد من فاتورة التفصيل: '.$result['cust_name'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء فاتورة التفصيل بنجاح / Cutting bill created',
                'data' => $result,
            ], 201);
        } catch (Throwable $e) {
            $this->logActivity($request, 'tailoring.invoice_create_failed', [
                'entity_type' => 'cutting_bill',
                'entity_id' => 'failed',
                'description_ar' => 'فشل إنشاء فاتورة تفصيل: '.mb_substr($e->getMessage(), 0, 180),
                'status' => 'failed',
            ]);

            return $this->errorResponse('خطأ في إنشاء فاتورة التفصيل / Failed to create the cutting bill', $e);
        }
    }

    /**
     * بناء الفاتورة داخل المعاملة — الترتيب الإلزامي: القيد ثم الرأس ثم OHID ثم الموديلات
     * Build the bill inside the transaction — the mandatory order: entry, header, OHID, models.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createBill(array $payload, Request $request): array
    {
        $this->materializePreparedPayload($payload);

        $customer = $payload['customer'];
        $header = $payload['header'];
        $finance = $payload['finance'];

        $year = (int) substr((string) $payload['date'], 0, 4);
        $billNo = TailoringNumbering::nextBillNo($year);
        $manualNo = ! empty($payload['manual_no'])
            ? (int) $payload['manual_no']
            : TailoringNumbering::nextManualNo($year);

        $total = round((float) $payload['total'], 2);
        $paid = round((float) $finance['paid'], 2);
        $net = round($total - $paid, 2);

        // نص البيان: مُدخل المستخدم أو صياغة النظام القديم
        // The narration: the user's text or the legacy wording.
        $details = $header['details'] !== null && trim((string) $header['details']) !== ''
            ? (string) $header['details']
            : 'عليكم فاتورة تفصيل آجلة رقم '.$manualNo;

        // 1) القيد أولاً — ENTRY_NO مفتاح أجنبي غير فارغ على الرأس
        // 1) The entry first — ENTRY_NO is a NOT NULL foreign key on the header.
        $entryNo = TailoringPosting::postBill([
            'date' => (string) $payload['date'],
            'manual_no' => $manualNo,
            'cust_no' => $customer['cust_no'],
            'total' => $total,
            'paid' => $paid,
            'party_account' => (int) $payload['party_account'],
            'credit_account' => (int) $finance['credit_account'],
            'cash_account' => $paid > 0 ? (int) $finance['cash_account'] : null,
            'note' => $details,
            'cust_name' => (string) ($customer['cust_name'] ?? ''),
            'pieces' => $payload['pieces_count'],
        ]);

        // 2) الرأس — OHID يتركه الزناد
        // 2) The header — the trigger composes OHID.
        TailoringDocumentWriter::insertBill([
            'bill_no' => $billNo,
            'year' => $year,
            'bill_type' => TailoringPolicy::BILL_TYPE,
            'dat' => $payload['date'],
            'cust_no' => $customer['cust_no'],
            'cust_name' => TailoringDocumentWriter::clip((string) ($customer['cust_name'] ?? ''), 150),
            'tel_no1' => TailoringDocumentWriter::clip($customer['phone'] ?? null, 15),
            'address' => TailoringDocumentWriter::clip($customer['address'] ?? null, 120),
            'ref_no' => TailoringDocumentWriter::clip($header['ref_no'] ?? null, 20),
            'details' => TailoringDocumentWriter::clip($details, 180),
            'total' => $total,
            'sanf_nums' => $payload['pieces_count'],
            'net' => $net,
            'entry_no' => $entryNo,
            'acc_no' => (int) $payload['party_account'],
            'acc_no1' => (int) $finance['credit_account'],
            'acc_no2' => $paid > 0 ? (int) $finance['cash_account'] : null,
            'manual_bill_no' => $manualNo,
            'amount_paid' => $paid,
            // «اخذ المقاسات» يُكتب في VOUCHER_NO كما في النموذج القديم، ورقم الحوالة البنكية
            // في FIRST_TRANSFER (طلب المستخدم الحرفي 2026-11-22).
            // "Taken measurements" writes VOUCHER_NO like the legacy form; the bank
            // transfer number writes FIRST_TRANSFER (the user's literal request).
            'voucher_no' => TailoringDocumentWriter::clip((string) ($header['measurements_by'] ?? ''), 20),
            'transfer_through' => TailoringDocumentWriter::clip($finance['transfer_through'] ?? null, 30),
            'transfer_no' => TailoringDocumentWriter::clip($finance['transfer_no'] ?? null, 15),
            'urgent' => $header['urgent'] ? 1 : 0,
            // تاريخ/وقت المستعجل لا يُحفظان في أي عمود: يسبقان ملاحظة المستعجل نصاً،
            // وGIVING_DATE يحمل تاريخ حفظ الفاتورة نفسه كما يفعل القديم (لا علاقة له
            // بتاريخ المستعجل)، وMAIN_BILL=0 صريح وCREATED_ON تاريخ بلا وقت وTHE_TIME فارغ.
            // The urgent date/time are never stored: they prefix the urgent note as text.
            // GIVING_DATE carries the bill's save date as the legacy does (nothing to do
            // with the urgent date), MAIN_BILL is an explicit 0, CREATED_ON is a bare
            // date and THE_TIME stays empty — exactly like the legacy rows.
            'giving_in' => TailoringDocumentWriter::clip($header['giving_note'] ?? null, 100),
            'giving_date' => $payload['date'],
            // AM_PM نص ثابت كما يكتبه القديم، وGIVING_TIME يُترك فارغاً (طلب المستخدم)
            // AM_PM is the legacy's fixed text, and GIVING_TIME stays empty (user request).
            'am_pm' => TailoringPolicy::AM_PM_TEXT,
            'permanent_cust' => $header['permanent_cust'] ? 1 : 0,
            'cust_known' => $finance['cust_known'] ? 1 : 0,
            'bills_kinds' => TailoringPolicy::BILL_KIND,
            'branch_no' => $payload['branch_no'],
            'created_by' => $payload['created_by'],
            'created_by_name' => TailoringDocumentWriter::clip($payload['created_by_name'], 100),
        ]);

        // 3) صف صلاحيات الفاتورة — صف واحد لكل فاتورة كما في القديم
        // 3) The bill's privilege row — one per bill, as the legacy.
        TailoringDocumentWriter::insertPrivilege([
            'seq' => TailoringNumbering::nextPrivilegeSeq(),
            'bill_no' => $billNo,
            'year' => $year,
        ]);

        // 4) OHID الفعلي من الزناد — بدونه تنكسر سلسلة الموديلات والقطع
        // 4) The trigger's actual OHID — without it the model/piece chain breaks.
        $ohid = TailoringNumbering::ohidOf($billNo, $year);

        if ($ohid === null) {
            throw new \RuntimeException('لم يُركّب الزناد OHID للفاتورة / The trigger did not compose OHID');
        }

        // 4 و 5) الموديلات ثم قطعها — SEQ/SEQ_NO1 عدّادات عامة وOHDID ربط نصي
        // 4/5) Models then their pieces — global counters and the concat OHDID.
        $piecesCount = 0;
        $firstStkCost = null;

        foreach ($payload['models'] as $index => $model) {
            $seqNo = TailoringNumbering::nextShapeSeq();
            $ohdid = TailoringNumbering::modelOhdid($ohid, $index + 1);

            TailoringDocumentWriter::insertShape([
                'bill_no' => $billNo,
                'year' => $year,
                'seq_no' => $seqNo,
                'ohid' => $ohid,
                'ohdid' => $ohdid,
                'shape_style_no' => $model['family'],
                'styl_no' => $model['styl_no'],
                'rquest_no' => 1,
                'style_name' => TailoringDocumentWriter::clip((string) $model['style_label'], 30),
                'beneficiaryname' => TailoringDocumentWriter::clip($model['beneficiary'] ?? null, 100),
                'notes2' => TailoringDocumentWriter::clip($model['notes'] ?? null, 150),
                'button_type' => TailoringDocumentWriter::clip($model['specs']['button_type'] ?? null, 30),
                'button_no' => TailoringDocumentWriter::clip($model['specs']['button_no'] ?? null, 30),
                'buttons_num' => isset($model['specs']['buttons_num']) ? (int) $model['specs']['buttons_num'] : null,
                'com_cabak_type' => TailoringDocumentWriter::clip($model['specs']['com_cabak_type'] ?? null, 30),
                'neck_type' => TailoringDocumentWriter::clip($model['specs']['neck_type'] ?? null, 30),
                'pocket_or_without' => TailoringDocumentWriter::clip($model['specs']['pocket_or_without'] ?? null, 30),
                'gabzor_type' => TailoringDocumentWriter::clip($model['specs']['gabzor_type'] ?? null, 30),
                'mobile_pocket' => TailoringDocumentWriter::clip($model['specs']['mobile_pocket'] ?? null, 30),
                // فئة الخياط: حرفها (A/B/AA/BB) في TIPPER_TYPE كما يخزنه القديم
                // The tailor grade: its letter (A/B/AA/BB) in TIPPER_TYPE, as the legacy stores it.
                'tipper_type' => TailoringDocumentWriter::clip((string) ($model['specs']['tipper_type'] ?? '') ?: null, 30),
                'pen_pocket' => TailoringDocumentWriter::clip($model['specs']['pen_pocket'] ?? null, 30),
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
                'cloth_type' => null,
                'shape_gabroz_no' => $model['gabzor_hand'] ? 1 : 0,
            ]);

            foreach ($model['pieces'] as $pieceIndex => $piece) {
                $seqNo1 = TailoringNumbering::nextPieceSeq();

                TailoringDocumentWriter::insertPiece([
                    'seq_no1' => $seqNo1,
                    'seq_no' => $seqNo,
                    'bill_no' => $billNo,
                    'year' => $year,
                    'ohid' => $ohid,
                    // ربط نصي يبدأ 1 بلا تبطين — مثال مقيس: 262112846+39 = 26211284639
                    // String concat from 1, no padding — measured: 262112846+39 = 26211284639.
                    'ohdid' => TailoringNumbering::pieceOhdid($ohid, $piecesCount + $pieceIndex + 1),
                    'sanf_no' => $piece['sanf_no'],
                    'sanf_dno' => $piece['sanf_dno'],
                    // رقم صنف المقاس للموديل كما تكتبه الصفوف القديمة (PROD_NO)
                    // The model's size-item number exactly as the legacy rows write it.
                    'prod_no' => $model['item']['sanf_dno'],
                    'cloth_name' => TailoringDocumentWriter::clip((string) $piece['cloth_name'], 100),
                    'cloth_type' => TailoringDocumentWriter::clip((string) $piece['cloth_type'], 50),
                    'cloths_color' => $piece['color_no'],
                    'hinsh' => $piece['hinsh'] === null ? null : round((float) $piece['hinsh'], 2),
                    'yards' => $piece['yards'] === null ? null : round((float) $piece['yards'], 2),
                    'hinsh1' => $piece['hinsh1'] === null ? null : round((float) $piece['hinsh1'], 2),
                    'sharkh' => TailoringDocumentWriter::clip($piece['sharkh'], 20),
                    'gabzor_hand' => $piece['gabzor_hand'] ? 1 : 0,
                    'tatryz_in' => $piece['tatryz_in'] ? 1 : 0,
                    'tatryz_no' => $piece['tatryz_no'],
                    'habna_duabl' => $piece['habna_duabl'] ? 1 : 0,
                    // رقم الزر لهذه القطعة وحدها / this piece's own button number.
                    'button_no' => $piece['button_no'],
                    'sanfprice' => round((float) $piece['price'], 2),
                    'sanf_cost' => $piece['sanf_cost'] === null ? null : round((float) $piece['sanf_cost'], 2),
                    'stk_cost' => $piece['stk_cost'] === null ? null : round((float) $piece['stk_cost'], 2),
                    'shape_style_no' => $model['family'],
                    'style_name' => TailoringDocumentWriter::clip((string) $model['style_label'], 30),
                    'cloth_frm_local_store' => $piece['from_store'],
                    'shelf_no' => null,
                    'created_by_name' => TailoringDocumentWriter::clip($payload['created_by_name'], 20),
                ]);

                $piecesCount++;

                if ($firstStkCost === null) {
                    $firstStkCost = $piece['stk_cost'] === null ? null : round((float) $piece['stk_cost'], 2);
                }
            }
        }

        // 6) سطر بيانات الفاتورة — صف واحد لكل فاتورة كما في الصفوف القديمة:
        //    SANF_PRICE = STK_COST للقطعة الأولى (وليس سعر القطعة ولا مجموع التكاليف) وبلا OHID.
        //    القديم غير ثابت في الفواتير متعددة القطع (متوسط سعر أو تكلفة قطعة أو صفر)،
        //    والمقيس المؤكد: قطعة واحدة ⇒ STK_COST الخاص بها، وقطعتان ⇒ تكلفة الأولى (22188).
        // 6) The bill's detail row — one row per bill exactly as the legacy rows:
        //    SANF_PRICE is the first piece's STK_COST (not the piece price, not the cost
        //    sum) and no OHID. The legacy is inconsistent on multi-piece bills; the
        //    measured cases are one piece ⇒ its STK_COST, two pieces ⇒ the first's (22188).
        TailoringDocumentWriter::insertDetail([
            'bill_no' => $billNo,
            'year' => $year,
            'sanf_price' => $firstStkCost,
            'qty' => $piecesCount,
            'pcs' => $piecesCount,
            'seq' => TailoringNumbering::nextDetailSeq(),
        ]);

        return [
            'bill_no' => $billNo,
            'year' => $year,
            'manual_bill_no' => $manualNo,
            'ohid' => $ohid,
            'entry_no' => $entryNo,
            'total' => $total,
            'paid' => $paid,
            'remaining' => $net,
            'models_count' => count($payload['models']),
            'pieces_count' => $piecesCount,
            'cust_no' => $customer['cust_no'],
            'cust_name' => $customer['cust_name'],
            'customer_created' => $payload['customer_created'],
        ];
    }

    /**
     * التحقق من حمولة الكتابة وتجهيزها — أو رد 422/409
     * Validate and prepare the write payload — or a 422/409 response.
     *
     * @return array<string, mixed>|JsonResponse
     */
    public function prepareWritePayload(Request $request, bool $deferCreation = false, bool $forEdit = false)
    {
        $validator = Validator::make($request->all(), [
            'customer.mode' => 'required|in:existing,new',
            'customer.cust_no' => 'required_if:customer.mode,existing|integer|min:1',
            'customer.name' => 'required_if:customer.mode,new|string|max:150',
            'customer.phone' => 'nullable|string|max:15',
            'customer.address' => 'nullable|string|max:120',
            'customer.worker_type' => 'nullable|in:employee,cutter,tailor',
            'customer.worker_no' => 'nullable|integer|min:1',
            'header.bill_kind' => $forEdit ? 'required|integer|in:1,2,3,4' : 'required|integer|in:1',
            'header.manual_bill_no' => $forEdit ? 'nullable|integer|min:1' : 'nullable',
            'header.date' => $forEdit ? 'nullable|date_format:Y-m-d' : 'nullable',
            'header.ref_no' => 'nullable|string|max:20',
            'header.permanent_cust' => 'nullable|boolean',
            'header.urgent' => 'nullable|boolean',
            'header.giving_note' => 'nullable|string|max:100',
            'header.details' => 'nullable|string|max:180',
            'models' => $forEdit
                ? 'present|array|max:'.TailoringPolicy::MAX_MODELS
                : 'required|array|min:1|max:'.TailoringPolicy::MAX_MODELS,
            'models.*.seq_no' => $forEdit ? 'nullable|integer|min:1' : 'nullable',
            'models.*.shape_style_no' => 'required|integer|min:1|max:12',
            // «تأجيل الطلبات»: موديل يُحفظ بلا الحقول الإلزامية
            // "Postpone orders": a model saved without the mandatory fields.
            'models.*.deferred' => 'nullable|boolean',
            'models.*.beneficiary' => 'nullable|string|max:100',
            'models.*.notes' => 'nullable|string|max:150',
            'models.*.gabzor_hand' => 'nullable|boolean',
            'models.*.measurements.l1' => 'nullable|numeric|between:2,70',
            'models.*.measurements.l5' => 'nullable|numeric|between:10,31.5',
            'models.*.measurements.l2' => 'nullable|numeric|between:1,23',
            'models.*.measurements.l3' => 'nullable|numeric|between:1,30',
            'models.*.measurements.l4' => 'nullable|numeric|between:1,23',
            'models.*.measurements.l6' => 'nullable|numeric|between:1,15',
            'models.*.measurements.l7' => 'nullable|numeric|between:1,10',
            'models.*.measurements.l8' => 'nullable|numeric|between:1,40',
            'models.*.measurements.l9' => 'nullable|numeric|between:1,20',
            'models.*.measurements.l10' => 'nullable|numeric|between:1,13',
            'models.*.measurements.*' => 'nullable|numeric|min:0',
            'models.*.specs.button_type' => 'nullable|string|max:30',
            'models.*.specs.button_no' => 'nullable|string|max:30',
            'models.*.specs.buttons_num' => 'nullable|integer|min:0',
            'models.*.specs.com_cabak_type' => 'nullable|string|max:30',
            'models.*.specs.neck_type' => 'nullable|string|max:30',
            'models.*.specs.pocket_or_without' => 'nullable|string|max:30',
            'models.*.specs.gabzor_type' => 'nullable|string|max:30',
            'models.*.specs.hegazi_type' => 'nullable|string|max:30',
            'models.*.specs.mobile_pocket' => 'nullable|string|max:30',
            'models.*.specs.tipper_type' => $forEdit ? 'nullable|string|max:30' : 'nullable|string|in:A,B,AA,BB',
            'models.*.specs.pen_pocket' => 'nullable|string|max:30',
            'models.*.pieces' => $forEdit
                ? 'present|array|max:'.TailoringPolicy::MAX_PIECES
                : 'required|array|min:1',
            'models.*.pieces.*.seq_no1' => $forEdit ? 'nullable|integer|min:1' : 'nullable',
            'models.*.pieces.*.sanf_no' => 'required|integer|min:1',
            'models.*.pieces.*.sanf_dno' => 'required|integer|min:1',
            'models.*.pieces.*.cloth_type' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! isset(TailoringPolicy::WIDTH_TYPES[(string) $value])) {
                    $fail('عرض القماش غير مدعوم / Unsupported fabric width');
                }
            }],
            'models.*.pieces.*.price' => 'required|numeric|gt:0',
            'models.*.pieces.*.button_no' => 'nullable|integer|min:0',
            'models.*.pieces.*.from_store' => 'nullable|integer|in:1,2',
            'models.*.pieces.*.gabzor_hand' => 'nullable|boolean',
            'models.*.pieces.*.tatryz_in' => 'nullable|boolean',
            'models.*.pieces.*.tatryz_no' => 'nullable|integer|min:1',
            'models.*.pieces.*.habna_duabl' => 'nullable|boolean',
            'finance.paid' => 'required|numeric|min:0',
            'finance.cust_known' => 'nullable|boolean',
            'finance.acc_no' => $forEdit ? 'nullable|integer|min:1' : 'nullable',
            'finance.total' => $forEdit ? 'nullable|numeric|min:0' : 'nullable',
            'finance.credit_account' => 'required|integer|min:1',
            'finance.cash_account' => 'nullable|integer|min:1',
            'finance.discount' => $forEdit ? 'nullable|numeric|min:0' : 'nullable',
            'finance.discount_rate' => $forEdit ? 'nullable|numeric|min:0|max:100' : 'nullable',
            'finance.line_discount' => $forEdit ? 'nullable|numeric|min:0' : 'nullable',
            'finance.cur_no' => $forEdit ? 'nullable|integer|min:1' : 'nullable',
            'finance.cur_price' => $forEdit ? 'nullable|numeric|gt:0' : 'nullable',
            'finance.cur_no1' => $forEdit ? 'nullable|integer|min:1' : 'nullable',
            'finance.cur_price1' => $forEdit ? 'nullable|numeric|gt:0' : 'nullable',
            'finance.select1' => $forEdit ? 'nullable|integer|in:1,2' : 'nullable',
            'finance.transfer_through' => $forEdit ? 'nullable|string|max:30' : 'nullable',
            // رقم الحوالة البنكية يُكتب في FIRST_TRANSFER و«اخذ المقاسات» في VOUCHER_NO
            // The bank transfer number writes FIRST_TRANSFER; "taken measurements" writes
            // VOUCHER_NO.
            'finance.transfer_no' => 'nullable|string|max:15',
            'header.measurements_by' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الفاتورة غير صالحة / The bill payload is invalid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // ── العميل ── / ── The customer ──
        $customerResult = $this->resolveCustomer($request, $data['customer'], $deferCreation, $forEdit);

        if ($customerResult instanceof JsonResponse) {
            return $customerResult;
        }

        $customer = $customerResult['customer'];
        $customerCreated = $customerResult['created'];

        // ── ربط جاري العاملين (قرار D7): ACC_NO يصبح حساب العامل وCUST_NO يبقى للعميل ──
        // ── The worker link (decision D7): ACC_NO becomes the worker's ledger. ──
        $partyAccount = (int) ($customer['acc_no'] ?? TailoringPolicy::CUSTOMER_CONTROL_ACCOUNT);

        if (($data['customer']['worker_type'] ?? null) !== null && ($data['customer']['worker_no'] ?? null) !== null) {
            $worker = TailoringCustomerResolver::findWorker(
                (string) $data['customer']['worker_type'],
                (int) $data['customer']['worker_no']
            );

            if ($worker === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'العامل المطلوب غير موجود / The requested worker was not found',
                ], 422);
            }

            if ($worker['acc_no'] === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'العامل المطلوب لا يحمل حساباً مالياً / The requested worker has no ledger account',
                ], 422);
            }

            $partyAccount = (int) $worker['acc_no'];
        }

        // في التعديل يمكن تجاوز حساب الطرف صراحة مع بقاء ربط العامل مرئياً.
        // An edit may explicitly override the party ledger while retaining the worker link.
        if ($forEdit && isset($data['finance']['acc_no'])) {
            $partyAccount = (int) $data['finance']['acc_no'];
            $partyExists = (array) DB::connection('oracle')->selectOne(
                'SELECT ACC_NO FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :no',
                ['no' => $partyAccount]
            );
            if ($partyExists === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'حساب الطرف غير موجود / The party account does not exist',
                    'errors' => ['finance.acc_no' => 'unknown account'],
                ], 422);
            }
        }

        // ── المال ── / ── The money ──
        $finance = $data['finance'];
        $paid = (float) $finance['paid'];
        $custKnown = (bool) ($finance['cust_known'] ?? false);
        $workerLinked = ($data['customer']['worker_type'] ?? null) !== null;

        if (! $forEdit && $paid === 0.0 && ! $custKnown && ! $workerLinked) {
            return response()->json([
                'success' => false,
                'message' => 'العربون صفر يتطلب عميلاً معروفاً أو ربطاً بجاري العاملين'
                    .' / A zero deposit requires a known customer or a worker link',
                'errors' => ['finance.paid' => 'zero deposit requires cust_known or a worker link'],
            ], 422);
        }

        if ($paid > 0) {
            if (($finance['cash_account'] ?? null) === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'حساب الصندوق مطلوب عند وجود عربون / A cash account is required with a deposit',
                    'errors' => ['finance.cash_account' => 'required when paid > 0'],
                ], 422);
            }

            $cashExists = (array) DB::connection('oracle')->selectOne(
                'SELECT ACC_NO FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :no',
                ['no' => (int) $finance['cash_account']]
            );

            if ($cashExists === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'حساب الصندوق غير موجود / The cash account does not exist',
                    'errors' => ['finance.cash_account' => 'unknown account'],
                ], 422);
            }
        }

        $creditExists = (array) DB::connection('oracle')->selectOne(
            'SELECT ACC_NO FROM ACCOUNTS.CHART_ACC WHERE ACC_NO = :no',
            ['no' => (int) $finance['credit_account']]
        );

        if ($creditExists === []) {
            return response()->json([
                'success' => false,
                'message' => 'الحساب الدائن غير موجود / The credit account does not exist',
                'errors' => ['finance.credit_account' => 'unknown account'],
            ], 422);
        }

        // طريقة الدفع لم تعد تُحفظ في SALES_BILL.PAYMENT_METHOD (العمود لعملية أخرى)
        // The payment method is no longer written to SALES_BILL.PAYMENT_METHOD (other process).

        // ── الموديلات: الحل والتحقق على الخادم (مصدر الحقيقة) ──
        // ── Models: resolve and verify server-side (the truth source). ──
        $preparedModels = [];
        $total = 0.0;
        $piecesCount = 0;
        $now = TailoringPolicy::dbYear();

        foreach ($data['models'] as $model) {
            $family = (int) $model['shape_style_no'];
            $deferred = (bool) ($model['deferred'] ?? false);
            $l1Raw = $model['measurements']['l1'] ?? null;
            $l5Raw = $model['measurements']['l5'] ?? null;

            // الطول والعرض ونوع الأزرار إلزامية إلا في الموديل المؤجَّل
            // Length, width and the button type are mandatory unless the model is deferred.
            if (! $deferred && ($l1Raw === null || $l5Raw === null)) {
                return response()->json([
                    'success' => false,
                    'message' => 'الطول والعرض إلزاميان — أو فعّل «تأجيل الطلبات» للموديل'
                        .' / Length and width are required — or defer the model',
                    'errors' => ['models.*.measurements.l1' => 'required unless deferred'],
                ], 422);
            }

            if (! $deferred && trim((string) ($model['specs']['button_type'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'نوع الأزرار إلزامي — أو فعّل «تأجيل الطلبات» للموديل'
                        .' / The button type is required — or defer the model',
                    'errors' => ['models.*.specs.button_type' => 'required unless deferred'],
                ], 422);
            }

            $itemTypeNo = 300 + $family;
            $consumptionTypeNo = TailoringPolicy::consumptionTypeNo($family);
            // الموديل المؤجَّل بلا طول يستخدم الطول الافتراضي لحل الصنف فقط
            // A deferred model without a length uses the fallback for item resolution only.
            $l1 = $l1Raw === null ? TailoringPolicy::DEFERRED_L1 : (float) $l1Raw;
            $l5 = $l5Raw === null ? TailoringPolicy::DEFERRED_L5 : (float) $l5Raw;
            $hasMeasurements = $l1Raw !== null && $l5Raw !== null;
            $stylNo = $itemTypeNo * 100 + TailoringPolicy::sizeBand($l1);

            // صنف النمط يجب أن يكون موجوداً — لا اختراع أصناف أنماط
            // The style item must exist — style items are never invented.
            $styleItem = TailoringModelItemResolver::resolveStyleItem($itemTypeNo, $stylNo);

            if ($styleItem === null) {
                return response()->json([
                    'success' => false,
                    'message' => "صنف النمط [{$itemTypeNo}/{$stylNo}] غير موجود في SANFS"
                        .' / The style item does not exist in SANFS',
                ], 422);
            }

            // المقاسات الافتراضية: الخادم يملأ الفراغ فقط (مصدر الحقيقة)
            // Default measurements: the server fills the blanks only (truth source).
            $measures = [];

            foreach (['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7', 'L8', 'L9', 'L10'] as $slot) {
                $key = strtolower($slot);
                $value = $model['measurements'][$key] ?? null;
                $measures[$slot] = $value === null ? null : (float) $value;
            }

            $measures = DefaultMeasurements::fill($measures);

            // صنف المقاس: حل ثم إنشاء تلقائي بقالب النظام القديم (القرار المعتمد)
            // The size item: resolve then auto-create with the legacy template (approved).
            // بلا مقاسات لا يُنشأ صنف مقاس جديد — صنف قائم إن وُجد وإلا صنف النمط نفسه
            // Without measurements no new size item is created — an existing one, else the style item.
            $existingSizeItem = TailoringModelItemResolver::findSizeItem((int) $styleItem['sanf_no'], $l1, $l5);
            $sizeItem = $hasMeasurements && ! $deferCreation && $existingSizeItem === null
                ? TailoringModelItemResolver::resolveSizeItem($styleItem, $l1, $l5)
                : ['sanf_dno' => $existingSizeItem ?? ($hasMeasurements ? 0 : (int) $styleItem['sanf_no']), 'created' => false];

            // مجموعات المواصفات للاستهلاك — تُقرأ لا تُخمَّن
            // The spec groups for consumption — read, never guessed.
            $proGroup2 = ClothConsumption::proGroup(
                2,
                ArabicEncoding::normalizeStatementText($model['specs']['com_cabak_type'] ?? null)
            );
            $proGroup4 = ClothConsumption::proGroup(
                4,
                ArabicEncoding::normalizeStatementText($model['specs']['pocket_or_without'] ?? null)
            );

            $preparedPieces = [];

            foreach ($model['pieces'] as $piece) {
                $sanfNo = (int) $piece['sanf_no'];
                $sanfDno = (int) $piece['sanf_dno'];
                $fromStore = (int) ($piece['from_store'] ?? TailoringPolicy::CLOTH_FROM_SHOP);

                // القماش الفرعي يجب أن يعود للقماش الرئيسي — إعادة التحقق لا الثقة بالواجهة
                // The variant must belong to the main fabric — revalidated, never trusted.
                $variant = (array) DB::connection('oracle')->selectOne(
                    'SELECT SANF_DNO, SANF_DNAME, PART_NO, COLOR_NO, DCOLOR
                       FROM ACCOUNTS.SANFS_D
                      WHERE SANF_DNO = :dno AND SANF_NO = :no',
                    ['dno' => $sanfDno, 'no' => $sanfNo]
                );

                if ($variant === []) {
                    return response()->json([
                        'success' => false,
                        'message' => "القماش الفرعي [{$sanfDno}] لا يعود للقماش الرئيسي [{$sanfNo}]"
                            .' / The fabric variant does not belong to the main fabric',
                    ], 422);
                }

                $fabric = (array) DB::connection('oracle')->selectOne(
                    'SELECT SANF_NO, SANF_NAME, PART_NO, CATEGORY FROM ACCOUNTS.SANFS WHERE SANF_NO = :no',
                    ['no' => $sanfNo]
                );

                if ($fabric === []) {
                    return response()->json([
                        'success' => false,
                        'message' => "القماش الرئيسي [{$sanfNo}] غير موجود / The main fabric was not found",
                    ], 422);
                }

                $partNo = $this->pick($fabric, 'PART_NO');

                // الاستهلاك يعاد حسابه من الخادم — قيم الواجهة لا تُؤخذ
                // Consumption is recomputed server-side — UI values are not taken.
                // الموديل المؤجَّل بلا مقاسات: لا استهلاك ولا اقتباس سعر آلي
                // A deferred model without measurements: no consumption, no automatic quote.
                $consumption = $hasMeasurements ? ClothConsumption::calculate([
                    'typeno' => $consumptionTypeNo,
                    'cloth_type' => (string) $piece['cloth_type'],
                    'l1' => $l1,
                    'l2' => $measures['L2'],
                    'l3' => $measures['L3'],
                    'l5' => $l5,
                    'l8' => $measures['L8'],
                    'pro_group2' => $proGroup2,
                    'pro_group4' => $proGroup4,
                    'qty' => 1,
                ]) : ['hinsh' => null, 'yards' => null, 'hinsh1' => null, 'sharkh' => null];

                // التسعير المرجعي من الخادم (يُخزن للإثراء، والسعر النهائي من المستخدم)
                // The reference pricing server-side (stored for enrichment; the final price is the user's).
                $pricing = ClothPricing::price([
                    'part_no' => $partNo,
                    'styl_no' => $stylNo,
                    'yards' => $consumption['yards'] === null ? null : (float) $consumption['yards'],
                    'from_store' => $fromStore,
                ]);

                $price = round((float) $piece['price'], 2);
                $total += $price;
                $buttonNo = isset($piece['button_no']) ? (int) $piece['button_no'] : null;
                $piecesCount++;

                $preparedPieces[] = [
                    'seq_no1' => isset($piece['seq_no1']) ? (int) $piece['seq_no1'] : null,
                    'sanf_no' => $sanfNo,
                    'sanf_dno' => $sanfDno,
                    'cloth_name' => $this->pick($variant, 'SANF_DNAME') ?? $this->pick($fabric, 'SANF_NAME') ?? '',
                    'color_no' => $this->pickNum($variant, 'COLOR_NO') === null ? null : (int) $this->pickNum($variant, 'COLOR_NO'),
                    'color_text' => $this->pick($variant, 'DCOLOR'),
                    'cloth_type' => (string) $piece['cloth_type'],
                    'gabzor_hand' => (bool) ($piece['gabzor_hand'] ?? false),
                    'tatryz_in' => (bool) ($piece['tatryz_in'] ?? false),
                    'tatryz_no' => isset($piece['tatryz_no']) ? (int) $piece['tatryz_no'] : null,
                    'habna_duabl' => (bool) ($piece['habna_duabl'] ?? false),
                    'button_no' => $buttonNo,
                    'hinsh' => $consumption['hinsh'],
                    'yards' => $consumption['yards'] === null ? null : round((float) $consumption['yards'], 2),
                    'hinsh1' => $consumption['hinsh1'],
                    'sharkh' => $consumption['sharkh'],
                    'price' => $price,
                    'from_store' => $fromStore,
                    'sanf_cost' => $pricing['sanf_cost'],
                    'stk_cost' => $pricing['stk_cost'],
                ];
            }

            $preparedModels[] = [
                'seq_no' => isset($model['seq_no']) ? (int) $model['seq_no'] : null,
                'family' => $family,
                'styl_no' => $stylNo,
                // اسم النوع وحده («قطري») لا وصف الشريحة الحجمية («قطري رجالي من 50 الى 70»)
                // The type name alone («قطري»), never the size-band description.
                'style_label' => TailoringPolicy::styleName($family, $styleItem['sanf_name']),
                'beneficiary' => $model['beneficiary'] ?? null,
                'notes' => $model['notes'] ?? null,
                'gabzor_hand' => (bool) ($model['gabzor_hand'] ?? false),
                'measurements' => $measures,
                'specs' => $model['specs'],
                // صنف النمط وأصناف المقاس هويتان مختلفتان — نحتفظ بالرقمين معاً
                // Style and size items have separate identities — preserve both numbers.
                'item' => [
                    'sanf_no' => (int) $styleItem['sanf_no'],
                    'sanf_dno' => (int) $sizeItem['sanf_dno'],
                ],
                'size_item_pending' => (int) $sizeItem['sanf_dno'] === 0 ? [
                    'style_item' => $styleItem,
                    'l1' => $l1,
                    'l5' => $l5,
                ] : null,
                'pieces' => $preparedPieces,
            ];
        }

        $gross = round($total, 2);
        $lineDiscount = $forEdit ? round((float) ($data['finance']['line_discount'] ?? 0), 2) : 0.0;
        $headerDiscount = $forEdit ? round((float) ($data['finance']['discount'] ?? 0), 2) : 0.0;

        if ($forEdit && ! isset($data['finance']['discount']) && isset($data['finance']['discount_rate'])) {
            $headerDiscount = round($gross * (float) $data['finance']['discount_rate'] / 100, 2);
        }
        $requestedTotal = $forEdit && isset($data['finance']['total'])
            ? round((float) $data['finance']['total'], 2)
            : null;
        if ($requestedTotal !== null) {
            if ($gross > 0 && $requestedTotal > $gross - $lineDiscount + 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'الإجمالي النهائي يتجاوز إجمالي الأقمشة بعد الخصم / Final total exceeds gross less line discount',
                    'errors' => ['finance.total' => 'total exceeds gross less line discount'],
                ], 422);
            }
            $headerDiscount = $gross > 0
                ? round(max(0, $gross - $lineDiscount - $requestedTotal), 2)
                : 0.0;
        }

        if ($lineDiscount + $headerDiscount > $gross) {
            return response()->json([
                'success' => false,
                'message' => 'الخصم أكبر من إجمالي الفاتورة / The discount exceeds the invoice total',
                'errors' => ['finance.discount' => 'discount exceeds gross total'],
            ], 422);
        }

        $total = round($gross - $lineDiscount - $headerDiscount, 2);

        if ($piecesCount > TailoringPolicy::MAX_PIECES) {
            return response()->json([
                'success' => false,
                'message' => 'عدد القطع يتجاوز الحد المسموح ('.TailoringPolicy::MAX_PIECES.')'
                    .' / The piece count exceeds the cap',
            ], 422);
        }

        $header = $data['header'];
        $urgent = (bool) ($header['urgent'] ?? false);

        // المستعجل: ملاحظة الموعد تحمل التاريخ والوقت نصاً (لا عمود لهما)
        // Urgent: the note carries the date and time as text (they have no column).
        if ($urgent && trim((string) ($header['giving_note'] ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'موعد الاستلام مطلوب عند الاستعجال / A delivery note is required for urgent bills',
                'errors' => ['header.giving_note' => 'required when urgent'],
            ], 422);
        }

        $user = $request->user();
        $userId = $user?->getAuthIdentifier();

        // CREATED_BY يُكتب فقط إن وُجد المستخدم في NEW_USERS وإلا NULL (قرار D8)
        // CREATED_BY is written only when the user exists in NEW_USERS (decision D8).
        $createdBy = null;

        if ($userId !== null) {
            $legacyUser = (array) DB::connection('oracle')->selectOne(
                'SELECT USER_ID FROM ACCOUNTS.NEW_USERS WHERE USER_ID = :id',
                ['id' => (int) $userId]
            );

            if ($legacyUser !== []) {
                $createdBy = (int) $userId;
            }
        }

        return [
            'customer' => $customer,
            'customer_created' => $customerCreated,
            'party_account' => $partyAccount,
            'header' => [
                'bill_kind' => (int) $header['bill_kind'],
                'date' => $header['date'] ?? null,
                'manual_bill_no' => $header['manual_bill_no'] ?? null,
                'ref_no' => $header['ref_no'] ?? null,
                'permanent_cust' => (bool) ($header['permanent_cust'] ?? false),
                'urgent' => $urgent,
                'giving_note' => $header['giving_note'] ?? null,
                'details' => $header['details'] ?? null,
                // «اخذ المقاسات» — يُكتب في SALES_BILL.VOUCHER_NO (طلب المستخدم الحرفي)
                // "Taken measurements" — written to SALES_BILL.VOUCHER_NO (user request).
                'measurements_by' => isset($header['measurements_by'])
                    ? TailoringDocumentWriter::clip((string) $header['measurements_by'], 20)
                    : null,
            ],
            'finance' => [
                'paid' => $paid,
                'cust_known' => $custKnown,
                'acc_no' => $partyAccount,
                'credit_account' => (int) $finance['credit_account'],
                'acc_no1' => (int) $finance['credit_account'],
                'cash_account' => isset($finance['cash_account']) ? (int) $finance['cash_account'] : null,
                'acc_no2' => isset($finance['cash_account']) ? (int) $finance['cash_account'] : null,
                // رقم الحوالة البنكية يُكتب في SALES_BILL.FIRST_TRANSFER
                // The bank transfer number writes SALES_BILL.FIRST_TRANSFER.
                'transfer_no' => isset($finance['transfer_no'])
                    ? TailoringDocumentWriter::clip((string) $finance['transfer_no'], 15)
                    : null,
                'voucher_no' => $finance['voucher_no'] ?? null,
                'transfer_through' => $finance['transfer_through'] ?? null,
                'discount' => $headerDiscount,
                'discount_rate' => $forEdit
                    ? ($requestedTotal !== null
                        ? ($gross > 0 ? round($headerDiscount / $gross * 100, 2) : 0.0)
                        : (float) ($data['finance']['discount_rate'] ?? 0))
                    : 0.0,
                'line_discount' => $lineDiscount,
                'cur_no' => $forEdit ? (int) ($data['finance']['cur_no'] ?? 1) : 1,
                'cur_price' => $forEdit ? (float) ($data['finance']['cur_price'] ?? 1) : 1.0,
                'cur_no1' => $forEdit ? (int) ($data['finance']['cur_no1'] ?? 1) : 1,
                'cur_price1' => $forEdit ? (float) ($data['finance']['cur_price1'] ?? 1) : 1.0,
                'select1' => $forEdit ? (int) ($data['finance']['select1'] ?? 1) : 1,
                'gross' => $gross,
                'total' => round($total, 2),
                'net' => round($total - $paid, 2),
                'remaining' => round($total - $paid, 2),
            ],
            'models' => $preparedModels,
            'gross' => $gross,
            'total' => round($total, 2),
            'pieces_count' => $piecesCount,
            'date' => $forEdit && ! empty($header['date']) ? (string) $header['date'] : TailoringPolicy::dbYear().'-'.date('m-d'),
            'manual_no' => $forEdit && ! empty($header['manual_bill_no']) ? (int) $header['manual_bill_no'] : null,
            'branch_no' => 1,
            'created_by' => $createdBy,
            'created_by_name' => $user?->USER_NAME_AR ?? null,
        ];
    }

    /**
     * حل العميل: موجود أو جديد مع حماية الاسم الفريد — أو رد 422/409
     * Resolve the customer: existing or new with the unique-name guard — or 422/409.
     *
     * @param  array<string, mixed>  $input
     * @return array{customer: array<string, mixed>, created: bool}|JsonResponse
     */
    public function materializePreparedPayload(array &$payload): void
    {
        if (($payload['customer']['mode'] ?? null) === 'new' && empty($payload['customer']['cust_no'])) {
            $payload['customer'] = TailoringCustomerResolver::create(
                (string) $payload['customer']['name'],
                $payload['customer']['phone'] ?? null,
                null,
                $payload['customer']['address'] ?? null
            );
            $payload['customer_created'] = true;
        }

        foreach ($payload['models'] as &$model) {
            if (! empty($model['size_item_pending'])) {
                $pending = $model['size_item_pending'];
                $resolved = TailoringModelItemResolver::resolveSizeItem(
                    $pending['style_item'],
                    (float) $pending['l1'],
                    (float) $pending['l5']
                );
                $model['item']['sanf_dno'] = (int) $resolved['sanf_dno'];
                $model['size_item_pending'] = null;
            }
        }
        unset($model);
    }

    private function resolveCustomer(Request $request, array $input, bool $deferCreation = false, bool $forEdit = false)
    {
        $mode = (string) $input['mode'];

        if ($mode === 'existing') {
            $customer = TailoringCustomerResolver::find((int) $input['cust_no']);

            if ($customer === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'العميل غير موجود / The customer was not found',
                    'errors' => ['customer.cust_no' => 'unknown customer'],
                ], 422);
            }

            if (! $forEdit && (int) ($customer['stopping_cust'] ?? 0) === 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'العميل موقوف — لا يمكن إصدار فاتورة له'
                        .' / The customer is stopped — no bill can be issued',
                ], 422);
            }

            // هاتف الفاتورة وعنوانها من النموذج أولاً ثم من ملف العميل — يُكتبان في
            // SALES_BILL.TEL_NO1 و SALES_BILL.ADDRESS (طلب المستخدم الحرفي).
            // The bill phone and address come from the form first, then the customer file —
            // they are written to SALES_BILL.TEL_NO1 and SALES_BILL.ADDRESS.
            $customer = self::withBillContact($customer, $input);

            return ['customer' => $customer, 'created' => false];
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return response()->json([
                'success' => false,
                'message' => 'اسم العميل الجديد مطلوب / The new customer name is required',
                'errors' => ['customer.name' => 'required'],
            ], 422);
        }

        // CUST_NAME فريد (CUST_BRW_U1): التطابق يعيد استخدام العميل بقرار المستخدم (D6)
        // CUST_NAME is unique (CUST_BRW_U1): a match reuses the customer (decision D6).
        $existing = TailoringCustomerResolver::findByName($name);

        if ($existing !== null) {
            return response()->json([
                'success' => false,
                'message' => 'يوجد عميل بهذا الاسم — اختر استخدام العميل الموجود'
                    .' / A customer with this name exists — choose to reuse it',
                'errors' => ['customer.name' => 'duplicate name'],
                'data' => ['existing_customer' => $existing],
            ], 409);
        }

        $phone = $input['phone'] ?? null;

        if ($deferCreation) {
            return [
                'customer' => [
                    'mode' => 'new',
                    'name' => $name,
                    'phone' => $phone !== null && $phone !== '' ? $phone : null,
                    'address' => $input['address'] ?? null,
                    'acc_no' => TailoringPolicy::CUSTOMER_CONTROL_ACCOUNT,
                    'stopping_cust' => 0,
                ],
                'created' => false,
            ];
        }

        $customer = TailoringCustomerResolver::create(
            $name,
            $phone !== null && $phone !== '' ? $phone : null,
            null,
            $input['address'] ?? null
        );

        $customer = self::withBillContact($customer, $input);

        return ['customer' => $customer, 'created' => true];
    }

    /**
     * تثبيت هاتف الفاتورة وعنوانها من حقول النموذج أولاً ثم من ملف العميل
     * Pin the bill's phone and address from the form first, then from the customer file.
     *
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function withBillContact(array $customer, array $input): array
    {
        $phone = isset($input['phone']) ? trim((string) $input['phone']) : '';
        $address = isset($input['address']) ? trim((string) $input['address']) : '';

        $customer['phone'] = $phone !== '' ? $phone : ($customer['tel1'] ?? null);
        $customer['address'] = $address !== '' ? $address : ($customer['addr'] ?? null);

        return $customer;
    }

    /**
     * إعادة المحاولة على ORA-00001 فقط — نفس منطق المبيعات (الخطر R2)
     * Retry on ORA-00001 only — the same logic as sales (risk R2).
     *
     * @return array<string, mixed>
     */
    private function withNumberRetry(callable $operation): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (QueryException $e) {
                $unique = (int) ($e->errorInfo[1] ?? 0) === 1 || str_contains($e->getMessage(), 'ORA-00001');

                if (! $unique || $attempt >= TailoringPolicy::NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /**
     * تسجيل حدث في سجل النشاط — لا يُفشل الطلب عند فشله
     * Write an activity event — never breaks the request.
     *
     * @param  array<string, mixed>  $context
     */
    private function logActivity(Request $request, string $actionCode, array $context): void
    {
        try {
            app(ActivityLogger::class)->success($actionCode, array_merge([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'module_code' => 'tailoring',
                'entity_type' => 'cutting_bill',
                'platform' => 'mobile',
            ], $context), $request);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * قراءة قيمة بحالة غير حساسة / case-insensitive value read.
     *
     * @param  array<string, mixed>  $row
     */
    private function pick(array $row, string $key): ?string
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null) {
                return (string) $v;
            }
        }

        return null;
    }

    /**
     * قراءة رقم بحالة غير حساسة / case-insensitive numeric read.
     *
     * @param  array<string, mixed>  $row
     */
    private function pickNum(array $row, string $key): float|int|null
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null && is_numeric($v)) {
                $f = (float) $v;

                return floor($f) === $f ? (int) $f : $f;
            }
        }

        return null;
    }

    /**
     * معالج الأخطاء المركزي — نفس مغلّف بقية المتحكمات
     * Central error handler — the same envelope as the other controllers.
     */
    private function errorResponse(string $msg, Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $msg.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
