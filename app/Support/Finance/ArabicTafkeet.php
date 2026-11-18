<?php

namespace App\Support\Finance;

/**
 * تفقيط المبلغ بالعربية — نسخة PHP مطابقة لـ `ArabicNumberWords` في التطبيق
 * Arabic amount in words — the PHP twin of the client's `ArabicNumberWords`.
 *
 * ── لماذا على الخادم أيضاً؟ لأن أعمدة السندات `TYPE` و`TAFKEET` إلزامية (NOT NULL) ويطبعها
 *    النظام المكتبي كما هي، فيجب أن تُكتب لحظة الحفظ لا أن تُحسب عند العرض فقط.
 * ── Why on the server too: the voucher columns TYPE / TAFKEET are NOT NULL and the desktop
 *    prints them verbatim, so they must be written at save time, not only computed on display.
 *
 * ── العملة تُكتب بالاسم لا برمز «﷼» لأن الرمز خارج ترميز CP1256 فيسقط عند الكتابة.
 * ── The currency is spelled as a word, never "﷼", because that glyph is outside CP1256 and
 *    would be dropped on write.
 */
final class ArabicTafkeet
{
    private const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];

    private const TEENS = [
        'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر',
        'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر',
    ];

    private const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];

    private const HUNDREDS = [
        '', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة',
    ];

    /** اسم العملة كما يطبعه السند القديم / the currency label the legacy voucher prints */
    public const CURRENCY_NAMES = [
        1 => 'ريال يمني',
        2 => 'دولار أمريكي',
        3 => 'ريال سعودي',
    ];

    /** تفقيط مبلغ كامل مع العملة / spell a whole amount with its currency */
    public static function amount(float $value, string $currency = 'ريال يمني', string $minorUnit = 'هللة'): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return '—';
        }

        if ($value < 0) {
            return self::amount(-$value, $currency, $minorUnit).' فقط لا غير (سالب)';
        }

        // الكسر من الأصل لا من قيمة مُقرَّبة — كما في نسخة التطبيق تماماً
        // The fraction comes from the original, exactly as in the client twin.
        $whole = (int) floor($value);
        $fraction = (int) round(abs($value - $whole) * 100);

        $words = $whole === 0 ? 'صفر' : self::spellWhole($whole);
        $withCurrency = $currency === '' ? $words : "{$words} {$currency}";

        return $fraction > 0
            ? "{$withCurrency} و ".self::below1000($fraction)." {$minorUnit}"
            : $withCurrency;
    }

    /** تفقيط بعملة رقمية / spell an amount in a currency given by number */
    public static function forCurrency(float $value, int $curNo, ?string $fallbackName = null): string
    {
        $name = self::CURRENCY_NAMES[$curNo] ?? ($fallbackName ?: self::CURRENCY_NAMES[1]);

        return self::amount($value, $name);
    }

    private static function spellWhole(int $value): string
    {
        $pieces = [];
        $rest = $value;

        foreach ([[1000000, 'مليون'], [1000, 'ألف']] as [$unit, $label]) {
            $count = intdiv($rest, $unit);
            $rest %= $unit;

            if ($count > 0) {
                $pieces[] = self::groupWord($count, $label);
            }
        }

        $tail = self::below1000($rest);
        if ($tail !== '') {
            $pieces[] = $tail;
        }

        return implode(' و ', array_filter($pieces, static fn (string $p): bool => $p !== ''));
    }

    private static function groupWord(int $count, string $label): string
    {
        if ($count === 1) {
            return $label;
        }

        if ($count === 2) {
            return $label === 'مليون' ? 'مليونان' : 'ألفان';
        }

        if ($count >= 3 && $count <= 10) {
            $plural = $label === 'مليون' ? 'ملايين' : 'آلاف';

            return self::below1000($count).' '.$plural;
        }

        return self::below1000($count).' '.$label;
    }

    private static function below1000(int $value): string
    {
        if ($value <= 0 || $value > 999) {
            return '';
        }

        $hundreds = intdiv($value, 100);
        $remainder = $value % 100;
        $parts = [];

        if ($hundreds > 0) {
            $parts[] = self::HUNDREDS[$hundreds];
        }

        if ($remainder > 0) {
            if ($remainder < 10) {
                $parts[] = self::ONES[$remainder];
            } elseif ($remainder < 20) {
                $parts[] = self::TEENS[$remainder - 10];
            } else {
                $unit = $remainder % 10;
                $ten = intdiv($remainder, 10);
                // الآحاد قبل العشرات: «أحد وعشرون» / units before tens
                $parts[] = $unit > 0 ? self::ONES[$unit].' و '.self::TENS[$ten] : self::TENS[$ten];
            }
        }

        return implode(' و ', $parts);
    }
}
