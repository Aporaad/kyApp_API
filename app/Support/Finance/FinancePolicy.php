<?php

namespace App\Support\Finance;

use App\Support\SalesPolicy;
use Illuminate\Support\Facades\DB;

/**
 * سياسة القيود والسندات — القيم المرجعية التي تحكم الترقيم والأنواع وقابلية التعديل
 * Entries & vouchers policy — the reference values that govern numbering, types and editability.
 *
 * ── كل قيمة هنا مقروءة من مخطط ACCOUNTS الحيّ (المرحلة 0) لا مُخمَّنة:
 *    • رقم القيد = بادئة + سنة(2) + عدّاد(7) بـ MAX+1، والتسلسلات الموجودة لا تطابق الأرقام.
 *    • المؤقت (ENTRY_MAIN2) والمرحل (ENTRY_MAIN) يتشاركان سلسلة الأرقام (الـ view ENTRY_MAIN_ALL).
 *    • لكل سند قيد واحد (ENTRY_NO فريد في كل جدول سندات)، ورقم السند MAX+1 داخل السنة.
 *    • العملة هي الخانة الرابعة من رقم الحساب: SUBSTR(ACC_NO,4,1)، والعملة 1 هي المحلية.
 * ── Every value below was read from the live ACCOUNTS schema (phase 0): entry number = prefix
 *    + 2-digit year + 7-digit counter by MAX+1; temporary and posted entries share one number
 *    space; each voucher owns exactly one entry; a voucher number is MAX+1 within its year; the
 *    currency is the 4th digit of the account number and currency 1 is local.
 */
final class FinancePolicy
{
    /** أنواع القيود في ENTRY_TYPE / entry types in ENTRY_TYPE */
    public const TYPE_JOURNAL = 1;

    public const TYPE_CASH_RECEIVE = 2;

    public const TYPE_CASH_SARF = 3;

    public const TYPE_BANK_RECEIVE = 4;

    public const TYPE_BANK_SARF = 5;

    /** «قيد يومي» — موجود وغير مستخدم، ويُخصَّص لقيد الإقفال / reserved for closing entries */
    public const TYPE_CLOSING = 100;

    /** الأنواع اليدوية التي يملك هذا القسم تعديلها وحذفها / manual types this module may edit */
    public const EDITABLE_TYPES = [self::TYPE_JOURNAL, self::TYPE_CLOSING];

    /** بادئة قيود اليومية والمؤقتة والإقفال / prefix of journal, temporary and closing entries */
    public const PREFIX_JOURNAL = 4;

    public const PREFIX_CASH_RECEIVE = 1;

    public const PREFIX_CASH_SARF = 2;

    public const PREFIX_BANK_SARF = 3;

    /** العملة المحلية / the local currency */
    public const LOCAL_CURRENCY = 1;

    /** سقف سطور القيد الواحد / hard cap on lines per entry */
    public const MAX_LINES = 200;

    /** حد التوازن المقبول بعد التقريب / accepted balance tolerance after rounding */
    public const BALANCE_TOLERANCE = 0.01;

    /** أقصى محاولات عند تصادم الترقيم / retries on a numbering collision */
    public const NUMBER_RETRIES = 3;

    /** نطاق حسابات الصناديق (121) والبنوك (122) / cash-box (121) and bank (122) account ranges */
    public const CASH_RANGE = [12100000, 12199999];

    public const BANK_RANGE = [12200000, 12299999];

    /** أنواع الشيك/الإشعار في سندات البنك / cheque kinds on bank vouchers */
    public const CHEQUE_TYPES = [
        1 => 'حوالة / إشعار',
        2 => 'شيك',
    ];

    /**
     * تعريف جداول السندات الأربعة — اتجاه (قبض/صرف) × مصدر (نقدي/بنكي)
     * The four voucher tables — direction (receipt/payment) × source (cash/bank).
     *
     * `words` عمود التفقيط، و`*_len` سعة الأعمدة النصية بالبايت كما في المخطط الحي.
     * `words` is the amount-in-words column; `*_len` are the live byte capacities.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public const VOUCHERS = [
        'receipt' => [
            'cash' => [
                'table' => 'ACCOUNTS.CASH_RECEIVE', 'no' => 'C_NO', 'date' => 'C_DATE',
                'words' => 'TYPE', 'words_len' => 250, 'details_len' => 250, 'name_len' => 150,
                'creator_len' => 100, 'type' => self::TYPE_CASH_RECEIVE, 'entity' => 'cash_receive',
                'acc_digits' => 14,
            ],
            'bank' => [
                'table' => 'ACCOUNTS.BANK_RECEIVE', 'no' => 'B_NO', 'date' => 'B_DATE',
                'words' => 'TAFKEET', 'words_len' => 250, 'details_len' => 250, 'name_len' => 100,
                'creator_len' => 60, 'type' => self::TYPE_BANK_RECEIVE, 'entity' => 'bank_receive',
                'acc_digits' => 15,
            ],
        ],
        'payment' => [
            'cash' => [
                'table' => 'ACCOUNTS.CASH_SARF', 'no' => 'C_NO', 'date' => 'C_DATE',
                'words' => 'TYPE', 'words_len' => 150, 'details_len' => 150, 'name_len' => 150,
                'creator_len' => 50, 'type' => self::TYPE_CASH_SARF, 'entity' => 'cash_sarf',
                'acc_digits' => 14,
            ],
            'bank' => [
                'table' => 'ACCOUNTS.BANK_SARF', 'no' => 'B_NO', 'date' => 'B_DATE',
                'words' => 'TAFKEET', 'words_len' => 150, 'details_len' => 150, 'name_len' => 100,
                'creator_len' => 60, 'type' => self::TYPE_BANK_SARF, 'entity' => 'bank_sarf',
                // BANK_SARF.ACC_NO هو NUMBER(8) فقط — حساب من 9 خانات يُرفض قبل الكتابة
                // BANK_SARF.ACC_NO is NUMBER(8) only — a 9-digit account is refused up front.
                'acc_digits' => 8,
            ],
        ],
    ];

    /** تعريف جدول سند / one voucher table definition */
    public static function voucher(string $direction, string $source): array
    {
        return self::VOUCHERS[$direction][$source];
    }

    /** هل الاتجاه والمصدر معروفان؟ / are the direction and source known? */
    public static function isVoucher(string $direction, string $source): bool
    {
        return isset(self::VOUCHERS[$direction][$source]);
    }

    /** بادئة رقم قيد السند / the entry-number prefix of a voucher */
    public static function voucherPrefix(string $direction, string $source): int
    {
        return match ("{$direction}.{$source}") {
            'receipt.cash' => self::PREFIX_CASH_RECEIVE,
            'receipt.bank' => self::bankReceivePrefix(),
            'payment.cash' => self::PREFIX_CASH_SARF,
            default => self::PREFIX_BANK_SARF,
        };
    }

    /** بادئة قيد القبض البنكي من الإعدادات (1 أو 3) / the configurable bank-receipt prefix */
    public static function bankReceivePrefix(): int
    {
        $prefix = (int) config('finance.bank_receive_prefix', 1);

        return in_array($prefix, [1, 3], true) ? $prefix : 1;
    }

    /** نوع قيد السند ⇒ (اتجاه، مصدر) أو null / voucher entry type ⇒ (direction, source) or null */
    public static function voucherOfType(?int $typeNo): ?array
    {
        return match ($typeNo) {
            self::TYPE_CASH_RECEIVE => ['receipt', 'cash'],
            self::TYPE_BANK_RECEIVE => ['receipt', 'bank'],
            self::TYPE_CASH_SARF => ['payment', 'cash'],
            self::TYPE_BANK_SARF => ['payment', 'bank'],
            default => null,
        };
    }

    /** هل يملك هذا القسم تعديل القيد المرحل؟ / may this module edit the posted entry? */
    public static function isEditableType(?int $typeNo): bool
    {
        return in_array((int) $typeNo, self::EDITABLE_TYPES, true);
    }

    /** عملة الحساب من خانته الرابعة / the account's currency from its 4th digit */
    public static function currencyOf(int $accNo): int
    {
        $digits = (string) abs($accNo);
        $digit = strlen($digits) >= 4 ? (int) $digits[3] : self::LOCAL_CURRENCY;

        return $digit >= 1 ? $digit : self::LOCAL_CURRENCY;
    }

    /** هل الحساب صندوق نقدي أو بنك؟ / is the account a cash box or a bank? */
    public static function boxKind(int $accNo): ?string
    {
        if ($accNo >= self::CASH_RANGE[0] && $accNo <= self::CASH_RANGE[1]) {
            return 'cash';
        }

        if ($accNo >= self::BANK_RANGE[0] && $accNo <= self::BANK_RANGE[1]) {
            return 'bank';
        }

        return null;
    }

    /**
     * رقم القيد التالي لليومية/المؤقت/الإقفال — الأكبر عبر ENTRY_MAIN و ENTRY_MAIN2 معاً
     * The next journal/temporary/closing number — the max across ENTRY_MAIN and ENTRY_MAIN2.
     *
     * ── لماذا الجدولان؟ لأن المؤقت يحتفظ برقمه عند الترحيل، فرقم مؤقت لم يُرحَّل بعد لا يجوز
     *    أن يُعطى لقيد مرحل جديد وإلا تصادم الرقمان يوم الترحيل.
     * ── Why both tables: a temporary entry keeps its number when posted, so a pending temporary
     *    number must never be handed to a new posted entry or the two collide at posting time.
     */
    public static function nextJournalNo(int $year): int
    {
        $head = (int) sprintf('%d%02d', self::PREFIX_JOURNAL, $year % 100);
        $lo = $head * 10000000;
        $hi = $lo + 9999999;

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT GREATEST(
                        NVL((SELECT MAX(ENTRY_NO) FROM ACCOUNTS.ENTRY_MAIN WHERE ENTRY_NO BETWEEN :lo1 AND :hi1), 0),
                        NVL((SELECT MAX(ENTRY_NO) FROM ACCOUNTS.ENTRY_MAIN2 WHERE ENTRY_NO BETWEEN :lo2 AND :hi2), 0)
                    ) AS N
               FROM DUAL',
            ['lo1' => $lo, 'hi1' => $hi, 'lo2' => $lo, 'hi2' => $hi]
        );

        return self::counterAfter((int) (reset($row) ?: 0), $head);
    }

    /** رقم قيد السند التالي / the next voucher entry number */
    public static function nextVoucherEntryNo(string $direction, string $source, int $year): int
    {
        return SalesPolicy::nextEntryNo(self::voucherPrefix($direction, $source), $year);
    }

    /** رقم السند التالي داخل السنة / the next voucher number within its year */
    public static function nextVoucherNo(string $direction, string $source, int $year): int
    {
        $def = self::voucher($direction, $source);

        $row = (array) DB::connection('oracle')->selectOne(
            "SELECT NVL(MAX({$def['no']}), 0) AS N FROM {$def['table']} WHERE YEAR = :y",
            ['y' => $year]
        );

        return (int) (reset($row) ?: 0) + 1;
    }

    /** العدّاد التالي بعد أكبر رقم في نطاق البادئة / the next counter after the range max */
    public static function counterAfter(int $max, int $head): int
    {
        $counter = $max > 0 ? ($max % 10000000) + 1 : 1;

        return $head * 10000000 + $counter;
    }
}
