<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * سياسة المبيعات — القيم المرجعية التي تحكم إنشاء الفواتير والمردودات وترحيلها
 * Sales policy — the reference values that govern invoice/return creation and posting.
 *
 * ── كل رقم هنا مقروء من مخطط ACCOUNTS الحيّ لا مُخمَّن (المرحلة 0 من الخطة):
 *    • `BILL_NO` عدّاد سنوي كثيف يبدأ من 1 ⇒ `MAX+1` داخل السنة نفسها (لا تسلسل).
 *    • `MANUAL_BILL_NO` عدّاد «دفتر» لكل (سنة، نوع فاتورة) ⇒ `MAX+1` داخل المجموعة.
 *    • `OHID` مفتاح أساسي يُركّبه زناد `SEQ_BILL_TRG` قبل الإدراج، فلا يُكتب من التطبيق.
 *    • الترحيل ليس زناداً: الفاتورة تحتاج `ENTRY_MAIN` + `ENTRY_SUB` صراحةً (FK على ENTRY_NO).
 * ── Every constant below was read from the live ACCOUNTS schema (Phase 0 of the plan), not
 *    guessed: BILL_NO is a dense per-year counter (MAX+1), MANUAL_BILL_NO is a per-(year, type)
 *    book counter, OHID is composed by the BEFORE INSERT trigger, and posting is NOT trigger
 *    driven — a bill needs an explicit ENTRY_MAIN + ENTRY_SUB because ENTRY_NO is a foreign key.
 */
final class SalesPolicy
{
    /** تمييز صفوف المبيعات عن التفصيل داخل الجدول المشترك SALES_BILL */
    /** The discriminator separating sales rows from cutting rows in the shared SALES_BILL. */
    public const CUTTING_OR_SALES = 2;

    /** فاتورة نقدية / cash invoice */
    public const BILL_CASH = 1;

    /** فاتورة آجلة (على حساب العميل) / credit invoice */
    public const BILL_CREDIT = 2;

    /**
     * أنواع الفواتير المسموح إنشاؤها وترحيلها — النوع 3 (تفصيل) لا يدخل هذه الوحدة
     * The bill types this module may create and post; type 3 (tailoring) is out of scope.
     *
     * @var array<int, string>
     */
    public const BILL_TYPE_LABELS = [
        self::BILL_CASH => 'نقدي',
        self::BILL_CREDIT => 'آجل',
    ];

    /**
     * حساب الإيراد الدائن الافتراضي لكل نوع — كما في فواتير النظام القديم
     * The default revenue (credit) account per bill type, exactly as the legacy bills post it.
     *
     * 41110001 مبيعات نقدية · 41210001 مبيعات آجلة
     *
     * @var array<int, int>
     */
    public const REVENUE_ACCOUNTS = [
        self::BILL_CASH => 41110001,
        self::BILL_CREDIT => 41210001,
    ];

    /**
     * حساب المردودات المدين الافتراضي لكل نوع
     * The default returns (debit) account per bill type.
     *
     * 37110001 مردودات مبيعات نقدية · 37210001 مردودات مبيعات آجلة
     *
     * @var array<int, int>
     */
    public const RETURN_ACCOUNTS = [
        self::BILL_CASH => 37110001,
        self::BILL_CREDIT => 37210001,
    ];

    /**
     * صندوق النقدية الافتراضي عندما لا يختار المستخدم حساباً مديناً لفاتورة نقدية
     * The default cash box when the user picks no debit account on a cash invoice.
     */
    public const DEFAULT_CASH_ACCOUNT = 12110001;

    /**
     * نوعا القيد في ENTRY_TYPE: 12 مبيعات · 11 مردود مبيعات
     * The two journal entry types: 12 sales · 11 sales return.
     */
    public const ENTRY_TYPE_INVOICE = 12;

    public const ENTRY_TYPE_RETURN = 11;

    /**
     * بادئة رقم القيد لكل نوع فاتورة — أول رقم في ENTRY_NO كما يُصدره النظام القديم:
     * نقدي ⇒ 1xxxxxxx، آجل ⇒ 4xxxxxxx، والمردود ⇒ 5xxxxxxx.
     * The first digit of ENTRY_NO per document kind, matching the legacy issuer: cash ⇒ 1,
     * credit ⇒ 4, and returns ⇒ 5.
     *
     * @var array<int, int>
     */
    public const ENTRY_PREFIX = [
        self::BILL_CASH => 1,
        self::BILL_CREDIT => 4,
    ];

    public const ENTRY_PREFIX_RETURN = 5;

    /**
     * حساب المخزون (من STORES.INV_ACC_NO) وحساب تكلفة المبيعات — يُستخدمان في قيد المردود
     * الذي يعكس التكلفة، بينما قيد الفاتورة لا يمسّ التكلفة في هذا النظام.
     * The stock account (STORES.INV_ACC_NO) and the cost-of-sales account, used by the return
     * entry which reverses cost; the invoice entry itself never touches cost in this ERP.
     */
    public const STOCK_ACCOUNT = 12410001;

    public const COST_OF_SALES_ACCOUNT = 36110007;

    /** سقف سطور المستند الواحد — حماية من الطلبات الضخمة */
    /** Hard cap on lines per document — protects against oversized payloads. */
    public const MAX_LINES = 200;

    /** أقصى محاولات عند تصادم الترقيم (قيد فريد) / retries when a numbering collision occurs */
    public const NUMBER_RETRIES = 3;

    /**
     * السنة المحاسبية من ساعة أوراكل — لا من ساعة التطبيق (UTC)
     * The accounting year from the Oracle clock, never the app clock (UTC).
     */
    public static function dbYear(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            "SELECT TO_NUMBER(TO_CHAR(SYSDATE,'YYYY')) AS Y FROM DUAL"
        );

        return (int) (reset($row) ?: date('Y'));
    }

    /**
     * الرقم التالي للفاتورة داخل سنة — العدّاد كثيف ويبدأ من 1، والقيد الفريد (BILL_NO, YEAR)
     * The next bill number within a year. The counter is dense from 1 and the unique key is
     * (BILL_NO, YEAR), so MAX+1 inside the year is the legacy behaviour.
     */
    public static function nextBillNo(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(BILL_NO), 0) AS N FROM ACCOUNTS.SALES_BILL WHERE YEAR = :y',
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * الرقم اليدوي التالي (رقم الدفتر) لكل (سنة، نوع، مبيعات)
     * The next manual (book) number per (year, bill type) inside the sales rows.
     *
     * ── لماذا منفصل عن BILL_NO؟ لأن `OHID` (المفتاح الأساسي) يُركّب من الرقم اليدوي، فرقمان
     *    مختلفان ضروريان: الأول هوية الصف والثاني رقم الدفتر الذي يراه المحاسب.
     * ── Why separate from BILL_NO: OHID (the primary key) is composed from the manual number, so
     *    the two must differ — one identifies the row, the other is the accountant's book number.
     */
    public static function nextManualBillNo(int $year, int $billType): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(MANUAL_BILL_NO), 0) AS N
               FROM ACCOUNTS.SALES_BILL
              WHERE YEAR = :y AND BILL_TYPE = :t AND CUTTING_OR_SLAES = :k',
            ['y' => $year, 't' => $billType, 'k' => self::CUTTING_OR_SALES]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /** الرقم التالي للمردود داخل سنة / the next return number within a year */
    public static function nextReturnNo(int $year): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(RETURN_NO), 0) AS N FROM ACCOUNTS.RETURN_SALES WHERE YEAR = :y',
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /** الرقم اليدوي التالي للمردود لكل (سنة، نوع) / next manual return number per (year, type) */
    public static function nextManualReturnNo(int $year, int $billType): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(MANUAL_RETURN_NO), 0) AS N
               FROM ACCOUNTS.RETURN_SALES
              WHERE YEAR = :y AND BILL_TYPE = :t',
            ['y' => $year, 't' => $billType]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * رقم القيد التالي: `بادئة + سنة(2) + عدّاد(7)` — نفس صيغة النظام القديم بالضبط
     * The next journal entry number: prefix + 2-digit year + 7-digit counter, the exact legacy
     * shape (e.g. 1260016588 for prefix 1, year 2026, counter 16588).
     */
    public static function nextEntryNo(int $prefix, int $year): int
    {
        $head = (int) sprintf('%d%02d', $prefix, $year % 100);

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(ENTRY_NO), 0) AS N
               FROM ACCOUNTS.ENTRY_MAIN
              WHERE ENTRY_NO BETWEEN :lo AND :hi',
            ['lo' => $head * 10000000, 'hi' => $head * 10000000 + 9999999]
        );

        $max = (int) (reset($row) ?: 0);
        $counter = $max > 0 ? ($max % 10000000) + 1 : 1;

        return $head * 10000000 + $counter;
    }

    /**
     * تسلسل سطر القيد التالي — `ENTRY_SUB.SEQ` عدّاد عام مشترك مع النظام المكتبي
     * The next journal line sequence — ENTRY_SUB.SEQ is a global counter shared with the desktop.
     */
    public static function nextEntrySeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.ENTRY_SUB'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * تسلسل سطر الفاتورة التالي — `SALES_DETAILES.SEQ` عدّاد عام (القيد الفريد: رقم+سنة+تسلسل)
     * The next invoice line sequence — SALES_DETAILES.SEQ is a global counter, and its unique key
     * is (BILL_NO, YEAR, SEQ).
     */
    public static function nextDetailSeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.SALES_DETAILES'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /** تسلسل سطر المردود التالي / the next return line sequence */
    public static function nextReturnDetailSeq(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(SEQ), 0) AS N FROM ACCOUNTS.RETURN_SALES_DETAILES'
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /**
     * هل هذا النوع قابل للترحيل؟ (نقدي/آجل فقط)
     * Is this bill type postable? (cash / credit only)
     */
    public static function isPostableType(mixed $billType): bool
    {
        return in_array((int) $billType, array_keys(self::BILL_TYPE_LABELS), true);
    }

    /** بادئة رقم القيد لفاتورة / the entry-number prefix of an invoice */
    public static function invoiceEntryPrefix(int $billType): int
    {
        return self::ENTRY_PREFIX[$billType] ?? self::ENTRY_PREFIX[self::BILL_CASH];
    }

    /** حساب الإيراد لفاتورة — مع تجاوز يحدده المستخدم / an invoice's revenue account, overridable */
    public static function revenueAccount(int $billType): int
    {
        return self::REVENUE_ACCOUNTS[$billType] ?? self::REVENUE_ACCOUNTS[self::BILL_CASH];
    }

    /** حساب المردودات / the returns account */
    public static function returnAccount(int $billType): int
    {
        return self::RETURN_ACCOUNTS[$billType] ?? self::RETURN_ACCOUNTS[self::BILL_CASH];
    }

    /**
     * قاموس جاهز للعميل: الأنواع، الحسابات الافتراضية، حدود السطور
     * A client-ready payload: bill types, default accounts and line limits.
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
            'revenue_accounts' => self::REVENUE_ACCOUNTS,
            'return_accounts' => self::RETURN_ACCOUNTS,
            'entry_types' => [
                'invoice' => self::ENTRY_TYPE_INVOICE,
                'return' => self::ENTRY_TYPE_RETURN,
            ],
            'max_lines' => self::MAX_LINES,
        ];
    }
}
