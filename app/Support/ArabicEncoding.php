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
        0xF8 => 'ّ', 0xF9 => 'ù', 0xFA => 'ْ', 0xFB => 'û', 0xFC => 'ü', 0xFD => '',  0xFE => '',  0xFF => 'ے'
    ];

    /**
     * تحويل نصوص Windows-1256 المشوهة (Mojibake) القادمة من أوراكل إلى UTF-8 سليم
     */
    public static function fixMojibake(?string $str): ?string
    {
        if ($str === null || $str === '') {
            return $str;
        }

        // إذا كان النص يحتوي بالفعل على حروف عربية صريحة ولا يحتوي على رموز مشوهة
        if (preg_match('/^[\x{0600}-\x{06FF}\s\d\p{P}]+$/u', $str)) {
            return $str;
        }

        // 1. محاولة تحويل البايتات عبر جدول CP1256_MAP
        $result = '';
        $len = strlen($str);
        $convertedAny = false;

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);
            if ($byte >= 0x80 && isset(self::CP1256_MAP[$byte])) {
                $result .= self::CP1256_MAP[$byte];
                $convertedAny = true;
            } else {
                $result .= $str[$i];
            }
        }

        if ($convertedAny && preg_match('/[\x{0600}-\x{06FF}]/u', $result)) {
            return $result;
        }

        // 2. إذا كانت السلسلة UTF-8 ولكنها تمثل محارف ISO-8859-1 (مثل Î, Ç, á)
        // نحول من UTF-8 (Latin1 chars) إلى بايتات خام ثم نترجمها عبر CP1256
        $utf8ToRaw = '';
        $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
        $hasLatin1Mojibake = false;

        if ($chars) {
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

        // 3. محاولة أخيرة عبر mb_convert_encoding و iconv
        try {
            $conv = @mb_convert_encoding($str, 'UTF-8', 'Windows-1256');
            if ($conv && preg_match('/[\x{0600}-\x{06FF}]/u', $conv)) {
                return $conv;
            }
        } catch (\Throwable $e) {}

        return $str;
    }

    /**
     * تحويل النص العربي UTF-8 إلى ترميز Windows-1256 لقاعدة بيانات Oracle
     */
    public static function encodeForOracle(?string $str): ?string
    {
        if ($str === null || $str === '') {
            return $str;
        }

        try {
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $str)) {
                $converted = @iconv('UTF-8', 'Windows-1256//IGNORE', $str);
                if ($converted !== false && $converted !== '') {
                    return $converted;
                }
                $mb = @mb_convert_encoding($str, 'Windows-1256', 'UTF-8');
                if ($mb !== false && $mb !== '') {
                    return $mb;
                }
            }
        } catch (\Throwable $e) {}

        return $str;
    }

    /**
     * معالجة كائن أو مصفوفة بيانات كاملة وإصلاح كل الحقول النصية
     */
    public static function cleanData($data)
    {
        if (is_null($data)) return null;
        if (is_string($data)) return self::fixMojibake($data);
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
