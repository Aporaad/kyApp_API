<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * ترقيم الحسابات المالية — القاعدة المشفَّرة في رقم الحساب نفسه
 * Chart-of-accounts numbering — the rule encoded inside the account number.
 *
 * ── CHART_ACC لا يملك عمود عملة: الخانة الرابعة من اليسار هي رقم العملة في CURRENCY.
 *    25120016 = الجزء 251 + العملة 2 (دولار) + التسلسل 0016، ويُقرأ من اليسار لا من اليمين لأن
 *    أرقام العملاء تسعة خانات وتتبع القاعدة نفسها.
 * ── CHART_ACC carries no currency column: the 4th digit from the left is CURRENCY.CUR_NO.
 *    Read positionally so 8- and 9-digit numbers agree with the same rule.
 *
 * ── نُقلت هنا حرفياً من AccountController كي يشترك فيها إنشاء الموردين دون تكرار منطق.
 * ── Moved here verbatim from AccountController so supplier creation shares one implementation.
 */
final class ChartAccountNumbering
{
    /**
     * عملة الحساب من رقمه — null إن كان الرقم أقصر من أن يحمل خانة عملة
     * The account currency from its number — null when the number cannot carry one.
     */
    public static function currencyOf(int $accNo): ?int
    {
        $digits = (string) $accNo;

        if (strlen($digits) < 4) {
            return null;
        }

        $curNo = (int) $digits[3];

        return $curNo > 0 ? $curNo : null;
    }

    /**
     * تالية رقم حساب داخل نطاق (جزء, عملة) واحد: المُنخفض = جزء×100000 + عملة×10000،
     * والسقف عشرة آلاف رقم (أربعة أرقام تسلسل).
     * Next ACC_NO inside a single (part, currency) band; ten thousand numbers per band.
     *
     * @return array{number: int|null, error: string|null}
     */
    public static function next(int $accPart, int $curNo): array
    {
        $lo = $accPart * 100000 + $curNo * 10000;
        $hi = $lo + 10000;

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT MAX(ACC_NO) AS MAX_NO FROM ACCOUNTS.CHART_ACC WHERE ACC_NO >= :lo AND ACC_NO < :hi',
            ['lo' => $lo, 'hi' => $hi]
        );
        $max = $row['MAX_NO'] ?? $row['max_no'] ?? null;

        // أول رقم في النطاق = تسلسل 0001، مطابق لأول حساب في كل جزء قديم (11010001)
        $nextNumber = $max !== null ? (int) $max + 1 : $lo + 1;

        if ($nextNumber >= $hi) {
            return [
                'number' => null,
                'error' => 'نفدت أرقام العملة '.$curNo.' داخل الحساب الجزئي '.$accPart
                    .' (عشرة آلاف حساب لكل عملة) — لن يُمنح رقم يخرق قاعدته',
            ];
        }

        return ['number' => $nextNumber, 'error' => null];
    }
}
