<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * كاتب مستندات فاتورة التفصيل — الطبقات الثلاث المقيسة من §15.أ (خرائط الأعمدة مؤكدة
 * ضد ALL_TAB_COLUMNS في المرحلة 0: 41/41 و42/42 و32/32 عموداً موجودة فعلاً).
 * The cutting-bill document writer — the three measured layers of §15.أ (column maps
 * verified against ALL_TAB_COLUMNS in Phase 0).
 *
 * ── كل نص عربي يمر عبر toOracleSearchChars عند الكتابة (عقد الترميز المعروف C1)، وكل
 *    نص يُقصّ لسعة عموده: DETAILES(180)، CUST_NAME(150)، BENEFICIARYNAME(100)،
 *    NOTES2(150)، المواصفات(30)، STYLE_NAME(30)، CLOTH_NAME(100)، CREATED_BY_NAME(20
 *    على MULTI_CLOTHS و100 على SALES_BILL)، SHARKH(20).
 * ── Every Arabic string goes through toOracleSearchChars on write and is clipped to its
 *    column capacity — including the easily-missed 20-char CREATED_BY_NAME on MULTI_CLOTHS.
 *
 * ── الثوابت المخفية كما يكتبها النظام القديم: CUTTING_OR_SLAES=1، SELECT1=1، STORE_NO=1،
 *    CUR_NO=1، CUR_PRICE=1، ORDSID=1، DIS_RATE/DISCOUNT=0، HANDING_DONE=0، F_CANCEL=0،
 *    MAIN_BILL=0 صريح، وGIVING_DATE تاريخ حفظ الفاتورة (لا علاقة له بتاريخ المستعجل)،
 *    CREATED_ON تاريخ بلا وقت TRUNC(SYSDATE) وTHE_TIME فارغ كما في صفوف القديم،
 *    وSALES_DETAILES بلا OHID كما في القديم، وQTY للموديل يُترك NULL (العدد الحقيقي من
 *    القطع)، وQTY القطعة=1.
 * ── Hidden constants exactly as the legacy writes them; the model QTY stays NULL (the piece
 *    count is honest), and the piece QTY is 1.
 */
final class TailoringDocumentWriter
{
    /**
     * إدراج رأس الفاتورة — القيد أولاً خارجاً لأن ENTRY_NO مفتاح أجنبي غير فارغ
     * Insert the bill header — the entry precedes it outside, ENTRY_NO being a NOT NULL FK.
     *
     * @param  array<string, mixed>  $b  الربطيات الجاهزة من المتحكم / the prepared bindings
     */
    public static function insertBill(array $b): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.SALES_BILL
                 (BILL_NO, YEAR, BILL_TYPE, DAT, CUST_NO, CUST_NAME, TEL_NO1, ADDRESS,
                  REF_NO, DETAILES, TOTAL, SANF_NUMS, DIS_RATE, DISCOUNT, NET,
                  CUR_NO, CUR_PRICE, CUR_NO1, CUR_PRICE1, STORE_NO, ENTRY_NO, ACC_NO, ACC_NO1, ACC_NO2,
                  MANUAL_BILL_NO, AMOUNT_PAID, VOUCHER_NO, TRANSFER_THROUGH,
                  FIRST_TRANSFER,
                  URGENT, GIVING_IN, GIVING_DATE, AM_PM, PERMANENT_CUST, CUST_KNOWN, MAIN_BILL,
                  BILLS_KINDS, BRANCH_NO, CUTTING_OR_SLAES, SELECT1, ORDSID,
                  CREATED_ON, CREATED_BY, CREATED_BY_NAME)
             VALUES (:bill_no, :year, :bill_type, TO_DATE(:dat, \'YYYY-MM-DD\'), :cust_no, :cust_name,
                     :tel_no1, :address, :ref_no, :details, :total, :sanf_nums, 0, 0, :net,
                     1, 1, 1, 1, 1, :entry_no, :acc_no, :acc_no1, :acc_no2,
                     :manual_bill_no, :amount_paid, :voucher_no, :transfer_through,
                     :transfer_no,
                     :urgent, :giving_in, TO_DATE(:giving_date, \'YYYY-MM-DD\'), :am_pm, :permanent_cust, :cust_known, 0,
                     :bills_kinds, :branch_no, 1, 1, 0,
                     TRUNC(SYSDATE), :created_by, :created_by_name)',
            self::bindings($b)
        );
    }

    /**
     * إدراج موديل واحد — SEQ_NO/OHDID تصل جاهزين من المتحكم، وصنف الموديل محلولاً
     * Insert one model — SEQ_NO/OHDID arrive ready, the model item already resolved.
     *
     * @param  array<string, mixed>  $b
     */
    public static function insertShape(array $b): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.SALES_DETAILES_SHAPES
                 (BILL_NO, YEAR, SEQ_NO, OHID, OHDID, SHAPE_STYLE_NO, STYL_NO, RQUEST_NO, STYLE_NAME,
                  BENEFICIARYNAME, NOTES2, QTY,
                  BUTTON_TYPE, BUTTON_NO, BUTTONS_NUM,
                  COM_CABAK_TYPE, NECK_TYPE, POCKET_OR_WITHOUT, GABZOR_TYPE,
                  MOBILE_POCKET, PEN_POCKET, HEGAZI_TYPE, TIPPER_TYPE,
                  L1, L2, L3, L4, L5, L6, L7, L8, L9, L10, L11, L12,
                  SANF_NO, SANF_DNO, CLOTH_TYPE, SHAPE_GABROZ_NO)
             VALUES (:bill_no, :year, :seq_no, :ohid, :ohdid, :shape_style_no, :styl_no, :rquest_no, :style_name,
                     :beneficiaryname, :notes2, NULL,
                     :button_type, :button_no, :buttons_num,
                     :com_cabak_type, :neck_type, :pocket_or_without, :gabzor_type,
                     :mobile_pocket, :pen_pocket, NULL, NULL,
                     :l1, :l2, :l3, :l4, :l5, :l6, :l7, :l8, :l9, :l10, NULL, NULL,
                     :sanf_no, :sanf_dno, :cloth_type, :shape_gabroz_no)',
            self::bindings($b)
        );
    }

    /**
     * إدراج قطعة واحدة (قماش) — الاستهلاك والتسعير محسوبان من الخادم
     * Insert one piece (fabric) — consumption and pricing computed server-side.
     *
     * @param  array<string, mixed>  $b
     */
    public static function insertPiece(array $b): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.MULTI_CLOTHS
                 (SEQ_NO1, SEQ_NO, BILL_NO, YEAR, OHID, OHDID,
                  SANF_NO, SANF_DNO, PROD_NO, CLOTH_NAME, CLOTH_TYPE, CLOTHS_COLOR,
                  QTY, CONSUMED_CLOTH_QTY_HINSH, CONSUMED_CLOTH_QTY_YARDS,
                  CONSUMED_CLOTH_QTY_HINSH1, SHARKH, GABZOR_HAND, TATRYZ_IN, TATRYZ_NO,
                  HABNA_DUABL, SANFPRICE, SANF_COST, STK_COST, SHAPE_STYLE_NO, STYLE_NAME,
                  CLOTH_FRM_LOCAL_STORE, SHELF_NO, CLOTHS_OUR_SELECTION, ORDSDID,
                  CREATED_BY_NAME, F_CANCEL)
             VALUES (:seq_no1, :seq_no, :bill_no, :year, :ohid, :ohdid,
                     :sanf_no, :sanf_dno, :prod_no, :cloth_name, :cloth_type, :cloths_color,
                     1, :hinsh, :yards,
                     :hinsh1, :sharkh, :gabzor_hand, :tatryz_in, :tatryz_no,
                     :habna_duabl, :sanfprice, :sanf_cost, :stk_cost, :shape_style_no, :style_name,
                     :cloth_frm_local_store, :shelf_no, NULL, 0,
                     :created_by_name, 0)',
            self::bindings($b)
        );
    }

    /**
     * إدراج سطر بيانات الفاتورة (SALES_DETAILES) — صف واحد لكل فاتورة كما في النظام
     * القديم المقيس: صنف 1، والسعر = تكلفة الصنف STK_COST كما يكتب القديم (وليس سعر
     * القطعة)، والكمية عدد القطع، وبلا OHID كما في القديم.
     * Insert the bill's detail row (SALES_DETAILES) — one row per bill as measured on the
     * legacy rows: item 1, the price is the item's STK_COST (not the piece price), the
     * quantity is the piece count, and no OHID — as the legacy writes it.
     *
     * @param  array<string, mixed>  $b
     */
    public static function insertDetail(array $b): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.SALES_DETAILES
                 (BILL_NO, YEAR, SANF_NO, SANF_PRICE, QTY, CAP, PCS, TOTAL, SEQ, IS_LOCAL, STK_COST)
             VALUES (:bill_no, :year, 1, :sanf_price, :qty, 1, :pcs, 0, :seq, 1, 0)',
            self::bindings($b)
        );
    }

    /**
     * إدراج صف صلاحيات الفاتورة (SALES_BILL_PRIVLAGE) — صف واحد لكل فاتورة كما في القديم
     * Insert the bill's privilege row (SALES_BILL_PRIVLAGE) — one row per bill, as the legacy.
     *
     * @param  array<string, mixed>  $b
     */
    public static function insertPrivilege(array $b): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.SALES_BILL_PRIVILAGE (SEQ, BILL_NO, YEAR)
             VALUES (:seq, :bill_no, :year)',
            self::bindings($b)
        );
    }

    /**
     * تحويل القيم: نص عربي ⇒ صيغة أوراكل، وبقية الأنواع كما هي
     * Convert the values: Arabic text to the Oracle form, other types as-is.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public static function bindings(array $b): array
    {
        $out = [];

        foreach ($b as $key => $value) {
            if ($value === null) {
                $out[$key] = null;

                continue;
            }

            if (is_string($value)) {
                // لا أرقام ولا أكواد ASCII تمر عبر المحوّل — النص العربي فقط
                // Numbers and ASCII codes never pass through the converter — Arabic only.
                $out[$key] = preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1
                    ? ArabicEncoding::toOracleSearchChars($value)
                    : $value;

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * قصّ نص لسعة عمود بلا كسر محرف / clip text to a column capacity without splitting.
     */
    public static function clip(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }

    /**
     * تمرير آمن للقيم الرقمية أو null / a safe numeric-or-null pass-through.
     */
    public static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'errors' => []];
    }
}
