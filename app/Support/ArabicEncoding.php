<?php

namespace App\Support;

/**
 * فئة مساعدة لمعالجة ترميز النصوص العربية بين Oracle (Windows-1256) و UTF-8
 * Arabic Encoding Helper for Oracle (Windows-1256 / CP1256) & UTF-8
 */
class ArabicEncoding
{
    /**
     * جدول مطابقة بايتات Windows-1256 (0x80 - 0xFF) إلى محارف UTF-8 العربية
     */
    private const CP1256_MAP = [
        0x80 => '€', 0x81 => 'پ', 0x82 => '‚', 0x83 => 'ƒ', 0x84 => '„', 0x85 => '…', 0x86 => '†', 0x87 => '‡',
        0x88 => 'ˆ', 0x89 => '‰', 0x8A => 'ٹ', 0x8B => '‹', 0x8C => 'Œ', 0x8D => 'چ', 0x8E => 'ژ', 0x8F => 'ڈ',
        0x90 => 'گ', 0x91 => '‘', 0x92 => '’', 0x93 => '“', 0x94 => '”', 0x95 => '•', 0x96 => '–', 0x97 => '—',
        0x98 => 'ک', 0x99 => '™', 0x9A => 'ڑ', 0x9B => '›', 0x9C => 'œ', 0x9D => '',  0x9E => 'ـ', 0x9F => 'ے',
        0xA0 => ' ', 0xA1 => '،', 0xA2 => '¢', 0xA3 => '£', 0xA4 => '¤', 0xA5 => '¥', 0xA6 => '¦', 0xA7 => '§',
        0xA8 => '¨', 0xA9 => '©', 0xAA => 'ھ', 0xAB => '«', 0xAC => '¬', 0xAD => '­', 0xAE => '®', 0xAF => '¯',
        0xB0 => '°', 0xB1 => '±', 0xB2 => '²', 0xB3 => '³', 0xB4 => '´', 0xB5 => 'µ', 0xB6 => '¶', 0xB7 => '·',
        0xB8 => '¸', 0xB9 => '¹', 0xBA => '؛', 0xBB => '»', 0xBC => '¼', 0xBD => '½', 0xBE => '¾', 0xBF => '؟',
        0xC0 => 'ہ', 0xC1 => 'ء', 0xC2 => 'آ', 0xC3 => 'أ', 0xC4 => 'ؤ', 0xC5 => 'إ', 0xC6 => 'ئ', 0xC7 => 'ا',
        0xC8 => 'ب', 0xC9 => 'ة', 0xCA => 'ت', 0xCB => 'ث', 0xCC => 'ج', 0xCD => 'ح', 0xCE => 'خ', 0xCF => 'د',
        0xD0 => 'ذ', 0xD1 => 'ر', 0xD2 => 'ز', 0xD3 => 'س', 0xD4 => 'ش', 0xD5 => 'ص', 0xD6 => 'ض', 0xD7 => '×',
        0xD8 => 'ط', 0xD9 => 'ظ', 0xDA => 'ع', 0xDB => 'غ', 0xDC => 'ـ', 0xDD => 'ف', 0xDE => 'ق', 0xDF => 'ك',
        0xE0 => 'à', 0xE1 => 'ل', 0xE2 => 'â', 0xE3 => 'م', 0xE4 => 'ن', 0xE5 => 'ه', 0xE6 => 'و', 0xE7 => 'ç',
        0xE8 => 'è', 0xE9 => 'é', 0xEA => 'ê', 0xEB => 'ë', 0xEC => 'ى', 0xED => 'ي', 0xEE => 'î', 0xEF => 'ï',
        0xF0 => 'ً', 0xF1 => 'ٌ', 0xF2 => 'ٍ', 0xF3 => 'َ', 0xF4 => 'ô', 0xF5 => 'ُ', 0xF6 => 'ِ', 0xF7 => '÷',
        0xF8 => 'ّ', 0xF9 => 'ù', 0xFA => 'ْ', 0xFB => 'û', 0xFC => 'ü', 0xFD => '',  0xFE => '',  0xFF => 'ے',
    ];

    /**
     * تحويل نصوص Windows-1256 المشوهة (Mojibake) القادمة من أوراكل إلى UTF-8 سليم
     */
    public static function fixMojibake(?string $str): ?string
    {
        if ($str === null || $str === '') {
            return $str;
        }

        // إذا كان النص لا يحتوي على أي محارف أعلى من 127
        $hasHighAscii = false;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            if (ord($str[$i]) >= 0x80) {
                $hasHighAscii = true;
                break;
            }
        }
        if (! $hasHighAscii) {
            return $str;
        }

        // حصانة التكرار: نص سليم فعلاً (محارف عربية UTF-8 بلا أي محرف لاتيني أعلى من 127) لا يُصلَح مرةً
        // ثانية. إصلاحُ السليم يُنتج رموزاً: `موظف` بايتاتها UTF-8 (D9 88…) تنطبق عليها مطابقة CP1256 فتصير
        // `ظ…ظˆط¸ظپ`، وهو بالضبط ما ظهر في شاشتي الجلسات وإعدادات الأمان حين مرّت القيم المُصلَحة على
        // `cleanData()` مرة أخرى. المعيار الآمن: UTF-8 صالح + يحوي عرب + لا يحوي محرفاً في 0x80–0xFF؛
        // أمّا التشويه الحقيقي فيأتي دائماً كمحارف لاتينية في هذا المدى، فيمرّ من الحاصرات ويُسْتَمّ.
        // Idempotence guard: already-correct Arabic (valid UTF-8, Arabic letters, no Latin-1 char in
        // 0x80–0xFF) must never be repaired a second time — repairing it maps its UTF-8 bytes through
        // CP1256 and produces symbols (`موظف` → `ظ…ظˆط¸ظپ`), which is exactly what the sessions and
        // security-settings screens showed when an already-repaired payload passed `cleanData()` again.
        // Genuine mojibake always carries a 0x80–0xFF character, so it still falls through to the steps
        // below; raw CP1256 bytes are not valid UTF-8 in practice, so this never hides a real repair.
        if (mb_check_encoding($str, 'UTF-8')
            && preg_match('/[\x{0600}-\x{06FF}]/u', $str) === 1
            && preg_match('/[\x{0080}-\x{00FF}]/u', $str) !== 1) {
            return $str;
        }

        // 1. أولاً: فحص المحارف إذا كانت UTF-8 مشوهة قادمة من تحويل Oracle (Latin-1 chars like Î, Ç, á, etc.)
        // يتم تفكيك السلسلة كمحارف UTF-8 وفحص أكوادها بين 0x80 و 0xFF
        $utf8ToRaw = '';
        $hasLatin1Mojibake = false;
        $chars = @preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($chars) && ! empty($chars)) {
            foreach ($chars as $ch) {
                $code = mb_ord($ch, 'UTF-8');
                if ($code >= 0x80 && $code <= 0xFF && isset(self::CP1256_MAP[$code])) {
                    $utf8ToRaw .= self::CP1256_MAP[$code];
                    $hasLatin1Mojibake = true;
                } else {
                    $utf8ToRaw .= $ch;
                }
            }
            if ($hasLatin1Mojibake && preg_match('/[\x{0600}-\x{06FF}]/u', $utf8ToRaw)) {
                return $utf8ToRaw;
            }
        }

        // 2. إذا كانت السلسلة عبارة عن بايتات خام Windows-1256 وليست UTF-8
        $rawConverted = '';
        $rawHasArabic = false;
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);
            if ($byte >= 0x80 && isset(self::CP1256_MAP[$byte])) {
                $rawConverted .= self::CP1256_MAP[$byte];
                $rawHasArabic = true;
            } else {
                $rawConverted .= $str[$i];
            }
        }

        if ($rawHasArabic && preg_match('/[\x{0600}-\x{06FF}]/u', $rawConverted)) {
            return $rawConverted;
        }

        // 3. محاولة تحويل عبر mb_convert_encoding أو iconv
        try {
            $conv = @mb_convert_encoding($str, 'UTF-8', 'Windows-1256');
            if ($conv && preg_match('/[\x{0600}-\x{06FF}]/u', $conv)) {
                return $conv;
            }
        } catch (\Throwable $e) {
        }

        return $str;
    }

    /**
     * المسار الوحيد الصحيح للتعامل مع العربية عند البحث **وعند الكتابة** في أوراكل
     * The only correct path for Arabic — both searching and writing to Oracle.
     *
     * لماذا لا نرسل بايتات Windows-1256 الخام؟ لأن اتصال التطبيق بترميز عميل `AL32UTF8`
     * وقاعدة البيانات `WE8MSWIN1252`، فأي بايت >= 0x80 يصل كأbytes UTF-8 غير صالحة تستبدلها
     * أوراكل بعلامة الاستبدال `0xBF` (تم إثباته: `خالد سالم` ⇒ `DUMP` = `bf,bf,bf` أي ثلاثة
     * محارف بدل تسعة، والبيانات تُتلف).
     * Why not raw CP1256 bytes: the app connects as AL32UTF8 while the database is WE8MSWIN1252,
     * so every byte >= 0x80 arrives as an invalid UTF-8 sequence and Oracle substitutes 0xBF,
     * destroying the value (proved: `خالد سالم` → DUMP `bf,bf,bf`, three chars instead of nine).
     *
     * الصحيح: تحويل كل بايت CP1256 إلى المحرف اللاتيني الذي يحمل نفس الكود (`0xD6` ⇒ `U+00D6`)
     * فيرسله العميل كـ UTF-8 من محرفين، وتستقر أوراكل على البايت الأصلي `0xD6` تماماً كما تخزّنه
     * أنظمة الشركة القديمة. الناتج صالح للقراءة المزدوجة (`LIKE`) وللكتابة (`INSERT`/`UPDATE`).
     * Correct approach: map each CP1256 byte to the Latin-1 char with the same code, which the
     * AL32UTF8 client transports so that it lands as the original byte — exactly what the legacy
     * system stores. The result is valid both for `LIKE` searches and for INSERT/UPDATE.
     *
     * Convert UTF-8 Arabic to the Oracle CP1256 Latin-1 chars (search + write).
     */
    public static function toOracleSearchChars(?string $str): ?string
    {
        if ($str === null || $str === '') {
            return $str;
        }

        $res = '';
        $cp1256Bytes = @iconv('UTF-8', 'Windows-1256//IGNORE', $str);
        if ($cp1256Bytes !== false && $cp1256Bytes !== '') {
            $len = strlen($cp1256Bytes);
            for ($i = 0; $i < $len; $i++) {
                $byte = ord($cp1256Bytes[$i]);
                $res .= mb_chr($byte, 'UTF-8');
            }

            return $res;
        }

        return $str;
    }

    /**
     * معالجة كائن أو مصفوفة بيانات كاملة وإصلاح كل الحقول النصية
     */
    public static function cleanData($data)
    {
        if (is_null($data)) {
            return null;
        }
        if (is_string($data)) {
            return self::fixMojibake($data);
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::cleanData($v);
            }

            return $data;
        }
        if (is_object($data)) {
            $arr = (array) $data;
            foreach ($arr as $k => $v) {
                $arr[$k] = self::cleanData($v);
            }

            return (object) $arr;
        }

        return $data;
    }
}
