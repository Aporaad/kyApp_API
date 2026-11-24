<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * سياسة التفصيل — القيم المرجعية التي تحكم إنشاء فواتير التفصيل وترحيلها
 * Tailoring policy — the reference values governing cutting-bill creation and posting.
 *
 * ── كل رقم هنا مقروء من مخطط ACCOUNTS الحيّ لا مُخمَّن (المرحلة 0 من الخطة):
 *    • `CUTTING_OR_SLAES = 1` يميّز صفوف التفصيل داخل SALES_BILL المشترك (المبيعات = 2).
 *    • كل فواتير التفصيل في 2026 نوعها `BILL_TYPE = 2` (آجل) — مقيس.
 *    • هذه المرحلة تقبل `BILLS_KINDS = 1` (عادية) حصراً بقرار المستخدم؛ الفئات 2/3/4 مؤجلة.
 *    • لا نكتب مستند مخزون مستقلاً؛ ITEM_MOVE_D VIEW مشتق من الفاتورة وقطعها تلقائياً.
 *    • `OHID` مفتاح أساسي يُركّبه زناد SEQ_BILL_TRG قبل الإدراج، فلا يُكتب من التطبيق.
 *    • الترحيل ليس زناداً: الفاتورة تحتاج ENTRY_MAIN + ENTRY_SUB صراحةً (FK على ENTRY_NO).
 * ── Every constant below was measured on the live ACCOUNTS schema (Phase 0), not guessed:
 *    CUTTING_OR_SLAES = 1 discriminates tailoring rows in the shared SALES_BILL (sales = 2),
 *    every 2026 tailoring bill is BILL_TYPE = 2 (credit), this phase accepts BILLS_KINDS = 1
 *    only (user decision; kinds 2/3/4 deferred), no separate stock document is written (the
 *    ITEM_MOVE_D view derives movements), OHID is composed by the BEFORE INSERT trigger, and posting
 *    is NOT trigger driven — a bill needs an explicit ENTRY_MAIN + ENTRY_SUB (ENTRY_NO is a FK).
 */
final class TailoringPolicy
{
    /** تمييز صفوف التفصيل داخل الجدول المشترك SALES_BILL */
    /** The discriminator separating cutting rows inside the shared SALES_BILL. */
    public const CUTTING_OR_SLAES = 1;

    /** فاتورة آجلة — كل فواتير التفصيل المقيسة نوعها آجل */
    /** Credit bill — every measured tailoring bill is credit. */
    public const BILL_TYPE = 2;

    /** تسمية نوع الفاتورة كما يقرأها العاملون / the bill-type label as the staff read it */
    public const BILL_TYPE_LABEL = 'آجل';

    /** فئة الفاتورة الوحيدة المقبولة في هذه المرحلة: 1 عادية (القرار المعتمد) */
    /** The only bill kind accepted in this phase: 1 (normal) — the approved decision. */
    public const BILL_KIND = 1;

    /** فئة الفاتورة الافتراضية المعروضة في الواجهة / the default bill kind shown in the UI */
    public const DEFAULT_BILL_KIND = 1;

    /**
     * نوع القيد في ENTRY_TYPE: 6 تفصيل — كما في آخر الفواتير المقيسة
     * The journal entry type: 6 (tailoring) — as measured on the latest bills.
     */
    public const ENTRY_TYPE_TAILORING = 6;

    /**
     * بادئة رقم القيد — أول رقم في ENTRY_NO كما يُصدره النظام القديم: التفصيل ⇒ 5xxxxxxx
     * The entry-number prefix: tailoring entries start with 5, exactly like the legacy issuer.
     */
    public const ENTRY_PREFIX = 5;

    /**
     * الحسابات الافتراضية كما في فواتير النظام القديم المقيسة:
     * 12310000 عملاء الكبودي للخياطة (حساب مراقبة العملاء) · 41210001 مبيعات آجلة (دائن)
     * 12110005 صندوق محمد السريحي (الافتراضي في آخر الفواتير) · 12410001 المخزون · 36110007 تكلفة المبيعات
     * The default accounts of the measured legacy bills: the customer control account, the
     * credit-revenue account, the default cash box, stock and cost of sales.
     */
    public const CUSTOMER_CONTROL_ACCOUNT = 12310000;

    public const REVENUE_ACCOUNT = 41210001;

    public const DEFAULT_CASH_ACCOUNT = 12110005;

    /** الصندوق الرئيسي بالريال اليمني — الافتراضي عند غياب صندوق المستخدم */
    /** The main YER cash box — the default when the user has no cash account. */
    public const MAIN_CASH_ACCOUNT = 12110001;

    /** نطاق صناديق النقدية المعروضة في قائمة الحسابات / the cash-box range offered in the picker */
    public const CASH_ACCOUNT_LO = 12110000;

    public const CASH_ACCOUNT_HI = 12119999;

    /** نطاق حسابات الإيراد (المبيعات) الدائنة / the credit revenue-account range */
    public const REVENUE_ACCOUNT_LO = 41210000;

    public const REVENUE_ACCOUNT_HI = 41219999;

    /**
     * نطاق حسابات المحافظ والصرافة — «الحسابات البنكية» في قائمة حساب العربون
     * (1221: جوالي، ون كاش، سبأ كاش، موبايل موني…) — مقيس من CHART_ACC الحيّة.
     * The wallet/exchange range — the "bank accounts" of the deposit-account picker
     * (1221: Jawali, OneCash, SabaCash…), measured live from CHART_ACC.
     */
    public const BANK_ACCOUNT_LO = 12210000;

    public const BANK_ACCOUNT_HI = 12219999;

    /**
     * طرق الدفع كما تُكتب حرفياً في فواتير النظام القديم (مقيسة من البيانات)
     * Payment methods exactly as the legacy bills store them (measured).
     *
     * @var array<int, string>
     */
    public const PAYMENT_METHODS = [
        '1-سند قبض نقدي',
        '2-بواسطة حوالة',
        '3-يقيد على الحساب',
        '4-قسط والباقي بضمانة',
    ];

    /** طريقة الدفع عند العربون > 0 / the method when the deposit is paid */
    public const PAYMENT_METHOD_CASH = '1-سند قبض نقدي';

    /** طريقة الدفع عند الدفع بحوالة / the method when paid by transfer */
    public const PAYMENT_METHOD_TRANSFER = '2-بواسطة حوالة';

    /** طريقة الدفع عند العربون 0 / the method when nothing is paid */
    public const PAYMENT_METHOD_ON_ACCOUNT = '3-يقيد على الحساب';

    /**
     * أنواع عرض القماش كما تخزنها الفواتير فعلاً في CLOTH_TYPE، مع تسمياتها وترتيبها
     * في جدول الاحتساب الاحتياطي CALC_BASE_MEASURES (WIDTH_TYPE).
     * Fabric widths exactly as bills store them in CLOTH_TYPE, with the labels and their
     * WIDTH_TYPE codes in the fallback calculation table.
     *
     * @var array<string, array{label: string, width_type: int}>
     */
    public const WIDTH_TYPES = [
        'عرضين' => ['label' => 'قماش عرضين 58', 'width_type' => 1],
        'عرض' => ['label' => 'قماش عرض 38', 'width_type' => 2],
        'عرض44' => ['label' => 'قماش عرض 42-44', 'width_type' => 3],
        'عرض68' => ['label' => 'قماش عرض 68', 'width_type' => 4],
    ];

    /** مصدر القماش: من المحل / من الزبون — كما يخزنها النظام القديم */
    /** Fabric source: from the shop / from the customer, as the legacy system stores it. */
    public const CLOTH_FROM_SHOP = 1;

    public const CLOTH_FROM_CUSTOMER = 2;

    /**
     * عائلات الموديل (فئة الخياط مدمجة في التسمية كما في الفواتير السابقة المقيسة):
     * الفهرس 1..12 هو SHAPE_STYLE_NO على صفوف الفواتير، وTYPE_NO = 300 + الفهرس.
     * تسميات الحروف (قطريA/B/C) من قاموس STYLE وDEFAULT_REQUESTS والفواتير الحيّة.
     * Model families — the tailor grade is embedded in the label, as measured on previous
     * bills: index 1..12 is SHAPE_STYLE_NO on bill rows and TYPE_NO = 300 + index.
     *
     * @var array<int, array{label: string, type_no: int}>
     */
    public const MODEL_FAMILIES = [
        1 => ['label' => 'قطري', 'type_no' => 301],
        2 => ['label' => 'حجازي', 'type_no' => 302],
        3 => ['label' => 'حجازي جنز', 'type_no' => 303],
        4 => ['label' => 'قطريA', 'type_no' => 304],
        5 => ['label' => 'قطريB', 'type_no' => 305],
        6 => ['label' => 'قطريC', 'type_no' => 306],
        7 => ['label' => 'كويتي', 'type_no' => 307],
        8 => ['label' => 'عماني', 'type_no' => 308],
        9 => ['label' => 'قطري جاهز', 'type_no' => 309],
        10 => ['label' => 'قطري دبل', 'type_no' => 310],
        11 => ['label' => 'لابكوت', 'type_no' => 311],
        12 => ['label' => 'معوز وشميز', 'type_no' => 312],
    ];

    /**
     * اسم نوع الموديل كما يُكتب في STYLE_NAME على الموديل وقطعه: اسم العائلة وحده
     * («قطري») لا وصف شريحة المقاس («قطري رجالي من 50 الى 70») الذي يحمله صنف SANFS.
     * The model type name written to STYLE_NAME on the model and its pieces: the family
     * label alone («قطري»), never the size-band item description held by SANFS.
     */
    public static function styleName(int $family, ?string $fallback = null): string
    {
        $label = self::MODEL_FAMILIES[$family]['label'] ?? null;

        if ($label !== null && trim($label) !== '') {
            return $label;
        }

        return $fallback !== null && trim($fallback) !== '' ? $fallback : 'موديل';
    }

    /**
     * نطاقات المقاس الحجمي (STYL_NO = TYPE_NO*100 + الحزام): الحزام يُشتق من L1 كما في
     * أسماء قاموس STYLE (رجالي 50-68، شبابي كبير 45-49، شبابي 40-44، ولادي كبير 35-39،
     * ولادي واسط 30-34، ولادي 18-29). مثال مقيس: L1=38 ⇒ حزام 4 ⇒ 30204.
     * The size band (STYL_NO = TYPE_NO*100 + band): derived from L1 exactly as the STYLE
     * dictionary names its rows. Measured example: L1 = 38 ⇒ band 4 ⇒ 30204.
     */
    public static function sizeBand(?float $l1): int
    {
        $l1 = (float) $l1;

        if ($l1 >= 50) {
            return 1;
        }

        if ($l1 >= 45) {
            return 2;
        }

        if ($l1 >= 40) {
            return 3;
        }

        if ($l1 >= 35) {
            return 4;
        }

        if ($l1 >= 30) {
            return 5;
        }

        return 6;
    }

    /** حدود النموذج الواحد / the per-model caps */
    public const MAX_MODELS = 20;

    public const MAX_PIECES = 200;

    /** سقف نص البيان والحقول النصية — كما في SALES_BILL.DETAILES(180) */
    /** The narration cap — SALES_BILL.DETAILES is 180 chars. */
    public const MAX_TEXT = 180;

    /** أقصى محاولات عند تصادم الترقيم (قيد فريد) / retries when a numbering collision occurs */
    public const NUMBER_RETRIES = 3;

    /** المخازن المستثناة من حساب التوفّر — كما في التسعيرة القديمة (مقيسة) */
    /** Stores excluded from the availability sum — as measured in the legacy pricing. */
    public const EXCLUDED_STORES = [11, 12, 2];

    /**
     * السنة المحاسبية من ساعة أوراكل — لا من ساعة التطبيق (UTC، فخ C13)
     * The accounting year from the Oracle clock, never the app clock (UTC — trap C13).
     */
    public static function dbYear(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            "SELECT TO_NUMBER(TO_CHAR(SYSDATE,'YYYY')) AS Y FROM DUAL"
        );

        return (int) (reset($row) ?: date('Y'));
    }

    /**
     * نوع الاحتساب (TYPENO) لعائلة الموديل — كما يقرأه النموذج من TYPES.CALC_TYPE:
     * `SELECT TYPE_NO FROM types WHERE CALC_TYPE = :family` ومع غيابه يعود 301
     * (سلوك استثناء NO_DATA_FOUND الحرفي في KEY-NEXT-ITEM).
     *
     * ── هذا ليس TYPE_NO للعائلة! قطريA/B/C/جاهز (304/305/306/309) تحسب الاستهلاك بقواعد
     *    قطري (301)، ولابكوت ومعوز وشميز بلا CALC_TYPE تعود 301 أيضاً. قيس حيّاً:
     *    199/200 قطعة «جاهز» طابقت فرع 301 — وهذا ما فكّ لغز البوابة الذهبية.
     * ── This is NOT the family's TYPE_NO! QatariA/B/C/Ready consume by the plain Qatari
     *    rules (301), and La-boot/Moawz (no CALC_TYPE) fall back to 301 as well. Measured
     *    live: 199/200 ready pieces matched the 301 branch.
     */
    public static function consumptionTypeNo(int $familyIndex): int
    {
        try {
            $row = (array) DB::connection('oracle')->selectOne(
                'SELECT TYPE_NO FROM ACCOUNTS.TYPES
                  WHERE CALC_TYPE = :family AND ACTIVE = 1
                  ORDER BY TYPE_NO',
                ['family' => $familyIndex]
            );
        } catch (\Throwable $e) {
            report($e);

            return 301;
        }

        $typeNo = $row === [] ? null : reset($row);

        return $typeNo === null || ! is_numeric($typeNo) ? 301 : (int) $typeNo;
    }

    /**
     * قاموس جاهز للعميل: الأنواع والحسابات والحدود وطرق الدفع
     * A client-ready payload: kinds, payment methods, accounts and limits.
     *
     * @return array<string, mixed>
     */
    public static function reference(): array
    {
        $kinds = [
            ['value' => 1, 'label' => 'عادية'],
        ];

        return [
            'bill_kind' => ['value' => self::BILL_KIND, 'label' => 'عادية'],
            'bill_kinds' => $kinds,
            'bill_type' => ['value' => self::BILL_TYPE, 'label' => self::BILL_TYPE_LABEL],
            'payment_methods' => self::PAYMENT_METHODS,
            'width_types' => collect(self::WIDTH_TYPES)
                ->map(fn (array $def, string $value): array => [
                    'value' => $value,
                    'label' => $def['label'],
                    'width_type' => $def['width_type'],
                ])
                ->values()
                ->all(),
            'model_families' => collect(self::MODEL_FAMILIES)
                ->map(fn (array $def, int $index): array => [
                    'value' => $index,
                    'label' => $def['label'],
                    'type_no' => $def['type_no'],
                ])
                ->values()
                ->all(),
            'accounts' => self::accounts(),
            'limits' => [
                'max_models' => self::MAX_MODELS,
                'max_pieces' => self::MAX_PIECES,
                'max_text' => self::MAX_TEXT,
            ],
        ];
    }

    /**
     * قائمة الحسابات المعروضة: الصناديق النقدية والمحافظ/البنوك وحسابات الإيراد من CHART_ACC
     * The offered accounts: cash boxes, wallet/bank accounts and revenue accounts live
     * from CHART_ACC.
     *
     * الصناديق النقدية: 1211 (ريال يمني) + 1212 (دولار) + 1213 (ريال سعودي).
     * الحسابات البنكية (المحافظ والصرافة): 1221 — اختيارها يُظهر رقم الحوالة.
     * Cash: 1211 (YER) + 1212 (USD) + 1213 (SAR). Banks (wallets/exchange): 1221 — picking
     * one reveals the transfer-number field.
     *
     * فشل القراءة لا يكسر المرجع: تُعاد القيم الافتراضية الثابتة.
     * A failed read never breaks the reference: the static defaults return instead.
     *
     * @return array{credit: array<int, array<string, mixed>>, cash: array<int, array<string, mixed>>, bank: array<int, array<string, mixed>>}
     */
    public static function accounts(): array
    {
        $credit = [['acc_no' => self::REVENUE_ACCOUNT, 'name' => 'مبيعات آجلة']];
        $cash = [['acc_no' => self::DEFAULT_CASH_ACCOUNT, 'name' => 'صندوق محمد السريحي']];
        $bank = [];

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT ACC_NO, ACC_NAME FROM ACCOUNTS.CHART_ACC
                  WHERE (ACC_NO BETWEEN :clo AND :chi)
                     OR (ACC_NO BETWEEN :dlo AND :dhi)
                     OR (ACC_NO BETWEEN :slo AND :shi)
                     OR (ACC_NO BETWEEN :blo AND :bhi)
                     OR (ACC_NO BETWEEN :rlo AND :rhi)
                  ORDER BY ACC_NO',
                [
                    'clo' => self::CASH_ACCOUNT_LO,
                    'chi' => self::CASH_ACCOUNT_HI,
                    'dlo' => 12120000,
                    'dhi' => 12129999,
                    'slo' => 12130000,
                    'shi' => 12139999,
                    'blo' => self::BANK_ACCOUNT_LO,
                    'bhi' => self::BANK_ACCOUNT_HI,
                    'rlo' => self::REVENUE_ACCOUNT_LO,
                    'rhi' => self::REVENUE_ACCOUNT_HI,
                ]
            );

            $credit = [];
            $cash = [];
            $bank = [];

            foreach ($rows as $row) {
                $data = (array) $row;
                $accNo = isset($data['ACC_NO']) ? (int) $data['ACC_NO'] : (int) ($data['acc_no'] ?? 0);
                $rawName = $data['ACC_NAME'] ?? $data['acc_name'] ?? null;
                $name = ArabicEncoding::fixMojibake($rawName === null ? null : (string) $rawName);

                if ($accNo >= self::REVENUE_ACCOUNT_LO) {
                    $credit[] = ['acc_no' => $accNo, 'name' => $name];
                } elseif ($accNo >= self::BANK_ACCOUNT_LO) {
                    $bank[] = ['acc_no' => $accNo, 'name' => $name];
                } else {
                    $cash[] = ['acc_no' => $accNo, 'name' => $name];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['credit' => $credit, 'cash' => $cash, 'bank' => $bank];
    }
}
