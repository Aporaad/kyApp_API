<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * سياسة المشتريات — القيم المرجعية التي تحكم فواتير الشراء ومردوداتها
 * Purchases policy — the reference values that govern purchase invoices and returns.
 *
 * ── كل رقم هنا مقروء من مخطط ACCOUNTS الحيّ (663 فاتورة · 3,699 سطراً · 113 مردوداً ·
 *    203 أسطر · 48 مورداً)، لا مُخمَّن:
 *    • `BUY_BILL` قيمته فارغة من الزنادات والتسلسلات ⇒ الترقيم `MAX+1` داخل معاملة.
 *    • `BILL_NO` عدّاد سنوي كثيف يبدأ من 1 (2026: 196 فاتورة حتى 199).
 *    • `BUY_DETAILES.SEQ` عدّاد **سنوي** أيضاً (2026: 1..849)، لا عام.
 *    • `MANUAL_BILL_NO` ليس عدّاد دفتر: هو `PROV_BILL` كما كتبه المستخدم (حتى 101010)،
 *      و340 صفاً يساوي رقم الفاتورة.
 *    • `SANF_NUMS` إلزامي وقيمته 1 في كل الصفوف الـ663 رغم تعدد سطور 322 منها.
 *    • `ENTRY_NO` مفتاح أجنبي على ENTRY_MAIN، والترحيل ليس زناداً.
 * ── Every constant below was read from the live schema; the invoice is not trigger-numbered, so
 *    numbering is MAX+1 inside a transaction and posting is explicit.
 */
final class PurchasePolicy
{
    /** فاتورة نقدية / cash purchase invoice */
    public const BILL_CASH = 1;

    /** فاتورة آجلة (على حساب المورد) / credit purchase invoice */
    public const BILL_CREDIT = 2;

    /** @var array<int, string> */
    public const BILL_TYPE_LABELS = [
        self::BILL_CASH => 'نقدي',
        self::BILL_CREDIT => 'آجل',
    ];

    /** نوع القيد في ENTRY_TYPE: 22 «توريد مخزني» · 13 «مردود مشتريات» */
    public const ENTRY_TYPE_INVOICE = 22;

    public const ENTRY_TYPE_RETURN = 13;

    /** بادئة رقم القيد: الفاتورة 4 والمردود 5 (كما في القيود الحية) */
    public const ENTRY_PREFIX_INVOICE = 4;

    public const ENTRY_PREFIX_RETURN = 5;

    /** القيمة الثابتة في كل صفوف النظام القديم / the constant every legacy row carries */
    public const SELECT1 = 2;

    /** نوع الحركة في TRANS_TYPE: 0 مشتريات · 3 مردودات مشتريات */
    public const MOVEMENT_INVOICE = 0;

    public const MOVEMENT_RETURN = 3;

    /** الجزء 251 هو دائنو الموردين في دليل الحسابات / suppliers live in part 251 */
    public const SUPPLIER_ACC_PART = 251;

    /**
     * أجزاء الصناديق والبنوك المسموح بها كحساب دافع في الفاتورة النقدية
     * The cash/bank account parts allowed as the paying account on a cash invoice.
     *
     * @var array<int, int>
     */
    public const CASH_ACC_PARTS = [121, 122];

    /** حساب المخزون الافتراضي (كل مخازن هذه القاعدة تشير إليه) / default stock account */
    public const STOCK_ACCOUNT = 12410001;

    /** الفرع الافتراضي في كل المستندات القديمة / the branch every legacy document carries */
    public const DEFAULT_BRANCH = 1;

    /** `BUY_BILL.TR_TYPE_ID` = 0 في كل الصفوف / always zero in legacy rows */
    public const TR_TYPE_ID = 0;

    /** سعة أعمدة النصوص التي تفرض القصّ / the text capacities that force clipping */
    public const DETAILES_LEN = 100;

    public const PROV_NAME_LEN = 80;

    public const CREATED_BY_NAME_LEN = 30;

    public const RETURN_BY_NAME_LEN = 50;

    public const PROV_BILL_LEN = 15;

    public const THE_TIME_LEN = 20;

    /** سقف سطور المستند الواحد — حماية من الطلبات الضخمة / hard line cap per document */
    public const MAX_LINES = 200;

    /** أقصى محاولات عند تصادم الترقيم / retries when a numbering collision occurs */
    public const NUMBER_RETRIES = 3;

    /** السنة المحاسبية من ساعة أوراكل لا من ساعة التطبيق (UTC) */
    /** The accounting year from the Oracle clock, never the app clock (UTC). */
    public static function dbYear(): int
    {
        return SalesPolicy::dbYear();
    }

    /** تاريخ اليوم من ساعة أوراكل بصيغة YYYY-MM-DD / today from the Oracle clock */
    public static function dbToday(): string
    {
        $row = (array) DB::connection('oracle')->selectOne(
            "SELECT TO_CHAR(SYSDATE, 'YYYY-MM-DD') AS D FROM DUAL"
        );

        return (string) (reset($row) ?: '');
    }

    /**
     * وقت اليوم من ساعة أوراكل بصيغة `HH:MI:SS AM` — سعة `THE_TIME` عشرون محرفاً
     * The time of day from the Oracle clock in the legacy `HH:MI:SS AM` shape (THE_TIME holds 20).
     */
    public static function dbTime(): string
    {
        $row = (array) DB::connection('oracle')->selectOne(
            "SELECT TO_CHAR(SYSDATE, 'HH:MI:SS AM') AS T FROM DUAL"
        );

        return (string) (reset($row) ?: '');
    }

    /** الرقم التالي للفاتورة داخل سنة / the next invoice number within a year */
    public static function nextBillNo(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(BILL_NO), 0) AS N FROM ACCOUNTS.BUY_BILL WHERE YEAR = :y',
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /** الرقم التالي للمردود داخل سنة / the next return number within a year */
    public static function nextReturnNo(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(RETURN_NO), 0) AS N FROM ACCOUNTS.RETURN_BUY WHERE YEAR = :y',
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * تسلسل سطر الفاتورة التالي — `BUY_DETAILES.SEQ` عدّاد داخل السنة لا عام
     * The next invoice line sequence — BUY_DETAILES.SEQ counts within a year, not globally.
     */
    public static function nextDetailSeq(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.BUY_DETAILES WHERE YEAR = :y',
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /** تسلسل سطر المردود التالي / the next return line sequence */
    public static function nextReturnDetailSeq(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.RETURN_BUY_DETAILES WHERE YEAR = :y',
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /** رقم المورد التالي — `PROV_BRW_U1` يمنع تكرار الاسم لا الرقم */
    /** The next supplier number — the unique key guards the name, not the number. */
    public static function nextProvNo(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(PROV_NO), 0) AS N FROM ACCOUNTS.PROV'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * الرقم اليدوي للفاتورة كما يكتبه النظام القديم: رقم فاتورة المورد إن كان رقماً، وإلا رقم الفاتورة
     * The manual invoice number as the legacy system writes it: the supplier's bill number when it
     * is numeric, otherwise the invoice number itself.
     *
     * ── ملاحظة مقيسة: `MANUAL_BILL_NO` يصل إلى 101010 في هذه القاعدة، فهو رقم دفتر حرّ لا عدّاد.
     * ── Measured: MANUAL_BILL_NO reaches 101010, so it is a free book number, not a counter.
     */
    public static function manualBillNo(mixed $provBill, int $billNo): int
    {
        $text = trim((string) ($provBill ?? ''));

        if ($text !== '' && ctype_digit($text)) {
            return (int) $text;
        }

        return $billNo;
    }

    /** الرقم اليدوي للمردود: رقم المستند نفسه (لا يقابل مردوداً رقم فاتورة المورد) */
    /** The manual return number: the document's own number (a return has no supplier bill no). */
    public static function manualReturnNo(int $returnNo): int
    {
        return $returnNo;
    }

    /**
     * `SEQBIL` كما يكتبه النظام القديم: سنة(2) + «10» + رقم الفاتورة
     * SEQBIL as the legacy writes it: two-digit year + "10" + the invoice number.
     *
     * ── مقيس: 2610199 للفاتورة 199 سنة 2026، بصرف النظر عن شهرها.
     * ── Measured: 2610199 for invoice 199/2026 regardless of its month.
     */
    public static function seqBil(int $year, int $billNo): int
    {
        return (int) (sprintf('%02d', $year % 100).'10'.$billNo);
    }

    /**
     * هل يمنح هذا النوع ترحيماً؟ (نقدي/آجل فقط)
     * Is this bill type postable? (cash / credit only)
     */
    public static function isPostableType(mixed $billType): bool
    {
        return in_array((int) $billType, array_keys(self::BILL_TYPE_LABELS), true);
    }

    /** نوع الحساب: صندوق/بنك أم مورد / the account kind: cash-bank or supplier */
    public static function accountPart(int $accNo): int
    {
        return (int) substr((string) $accNo, 0, 3);
    }

    /** هل هو حساب نقدي (صندوق/بنك)؟ / is it a cash or bank account? */
    public static function isCashAccount(int $accNo): bool
    {
        return in_array(self::accountPart($accNo), self::CASH_ACC_PARTS, true);
    }

    /** هل هو حساب مورد (الجزء 251)؟ / is it a supplier account (part 251)? */
    public static function isSupplierAccount(int $accNo): bool
    {
        return self::accountPart($accNo) === self::SUPPLIER_ACC_PART;
    }

    /**
     * رقم المستخدم في `NEW_USERS` أو null — حماية المفتاح الأجنبي
     * The user id inside NEW_USERS, or null — guards the foreign key.
     *
     * ── مقيس: 14 من 15 مستخدماً في APP_USERS غير موجودين في NEW_USERS، والكتابة المباشرة تكسر
     *    `BUY_BILL_BRW_F5`. الاسم يُكتب دائماً في `CREATED_BY_NAME` بدلاً من الرقم.
     * ── Measured: 14 of 15 app users are absent from NEW_USERS, so writing the raw id breaks the
     *    foreign key. The name always lands in CREATED_BY_NAME instead.
     */
    public static function legacyUserId(mixed $appUserId): ?int
    {
        $id = (int) ($appUserId ?? 0);

        if ($id <= 0) {
            return null;
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT COUNT(*) AS C FROM ACCOUNTS.NEW_USERS WHERE USER_ID = :id',
            ['id' => $id]
        );

        return ((int) (reset($row) ?: 0)) > 0 ? $id : null;
    }

    /**
     * بيان الفاتورة كما يصوغه النظام القديم
     * The invoice note in the legacy wording.
     */
    public static function invoiceNote(int $billType, int $manualNo, mixed $provBill = null): string
    {
        $label = self::BILL_TYPE_LABELS[$billType] ?? self::BILL_TYPE_LABELS[self::BILL_CASH];
        $note = 'فاتورة مشتريات '.$label.' رقم '.$manualNo;

        $supplierBill = trim((string) ($provBill ?? ''));

        if ($supplierBill !== '' && $supplierBill !== (string) $manualNo) {
            $note .= ' فاتورة '.$supplierBill;
        }

        return $note;
    }

    /**
     * بيان المردود كما يصوغه النظام القديم
     * The return note in the legacy wording.
     */
    public static function returnNote(int $billType, int $returnNo, int $invoiceManualNo): string
    {
        $label = $billType === self::BILL_CREDIT ? 'آجلة' : 'نقدية';

        return 'مردود مشتريات '.$label.' رقم '.$returnNo.' من فاتورة مشتريات رقم '.$invoiceManualNo;
    }

    /**
     * قاموس جاهز للعميل: الأنواع، الحدود، حسابات المخزون
     * A client-ready payload: bill types, limits, stock accounts.
     *
     * @return array<string, mixed>
     */
    public static function reference(): array
    {
        return [
            'bill_types' => collect(self::BILL_TYPE_LABELS)
                ->map(fn (string $label, int $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'entry_types' => [
                'invoice' => self::ENTRY_TYPE_INVOICE,
                'return' => self::ENTRY_TYPE_RETURN,
            ],
            'entry_prefixes' => [
                'invoice' => self::ENTRY_PREFIX_INVOICE,
                'return' => self::ENTRY_PREFIX_RETURN,
            ],
            'max_lines' => self::MAX_LINES,
            'supplier_acc_part' => self::SUPPLIER_ACC_PART,
            'cash_acc_parts' => self::CASH_ACC_PARTS,
            'stock_account' => self::STOCK_ACCOUNT,
        ];
    }
}
