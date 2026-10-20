<?php

use App\Support\ArabicEncoding;

/**
 * عقد قراءة العربية: إصلاحٌ واحد لكل قيمة، والإصلاح المتكرّر لا يُفسد السليم
 * The Arabic read contract: one repair per value, and a repeated repair must not corrupt good text.
 *
 * السبب: `fixMojibake()` يحوّل كل محرف في المدى 0x80–0xFF عبر جدول CP1256. النص العربي السليم لا يحوي
 * مثل هذا المحرف **كنص**، لكنه يحويه **كبايتات UTF-8** — فمن دون حصانة التكرار تُقلب `موظف` إلى
 * `ظ…ظˆط¸ظپ` و`نشطة` إلى `ظ†ط´ط·ط©` و`ارسلان` إلى `ط§ط±ط³ظ„ط§ظ†`. حدث هذا فعلاً في ثلاث شاشات حين
 * مُرّت قيم مُصلَحة مسبقاً على `cleanData()` ثانية (الجلسات، إعدادات الأمان، وتسميات المتحكمات).
 * Why: fixMojibake maps every 0x80–0xFF *character* through the CP1256 table. Correct Arabic has no
 * such characters, but its UTF-8 *bytes* do, so without the guard `موظف` became `ظ…ظˆط¸ظپ` and
 * `ارسلان` became `ط§ط±ط³ظ„ط§ظ†`. This happened live on three screens whenever an already-repaired
 * payload passed cleanData() a second time.
 */

/** قيم عربية كما تُعرض على الشاشة — كلها UTF-8 سليم */
const CORRECT_ARABIC_SAMPLES = ['موظف', 'نشطة', 'ارسلان', 'خالد سالم', 'عملاء الكبودي للخياطة', 'إعدادات الأمان'];

/** شكل التشويه كما يصل من عمود WE8MSWIN1252 عبر اتصال AL32UTF8 (محارف لاتينية بنفس بايتات CP1256) */
function mojibakeOf(string $text): string
{
    return ArabicEncoding::toOracleSearchChars($text);
}

it('repairs the oracle latin-1 form back to proper arabic', function (string $text) {
    expect(ArabicEncoding::fixMojibake(mojibakeOf($text)))->toBe($text);
})->with(CORRECT_ARABIC_SAMPLES);

it('is idempotent: repairing arabic that is already correct changes nothing', function (string $text) {
    $once = ArabicEncoding::fixMojibake($text);

    // القيمة الأولى نفسها: لا قلب لبايتات UTF-8 السليمة إلى رموز
    // The first pass is already the answer: good UTF-8 bytes must never be flipped into symbols.
    expect($once)->toBe($text)
        ->and(ArabicEncoding::fixMojibake($once))->toBe($text)
        ->and(ArabicEncoding::fixMojibake(ArabicEncoding::fixMojibake($once)))->toBe($text);
})->with(CORRECT_ARABIC_SAMPLES);

it('is idempotent all the way through cleanData', function (string $text) {
    // المتحكمات تُصلح حقلًا حقلًا ثم قد تُمرّر الصفحة كلها على cleanData — يجب أن يبقى الناتج واحداً
    // Controllers repair field by field and may still pass the whole payload through cleanData once
    $row = ['name_ar' => $text, 'label' => $text, 'nested' => ['note' => $text]];

    $once = ArabicEncoding::cleanData($row);
    $twice = ArabicEncoding::cleanData($once);
    $thrice = ArabicEncoding::cleanData($twice);

    expect($once['name_ar'])->toBe($text)
        ->and($twice)->toBe($once)
        ->and($thrice)->toBe($once);
})->with(CORRECT_ARABIC_SAMPLES);

it('still repairs a row that mixes arabic and latin-1 mojibake', function () {
    // حالة واقعية: اسمٌ فيه عربي سليم مع جزءٍ لاتيني مشوّه — الحاصرة لا تُفعَّل لأن المدى 0x80–0xFF موجود
    // A real shape: correct Arabic beside a mangled Latin-1 fragment — the guard stays off since 0x80–0xFF
    // appears in the string, so step 1 still runs and repairs only the mangled half.
    $mangled = 'علي '.ArabicEncoding::toOracleSearchChars('خالد');

    expect(ArabicEncoding::fixMojibake($mangled))->toBe('علي خالد')
        ->and(ArabicEncoding::fixMojibake('علي خالد'))->toBe('علي خالد');
});

it('leaves ascii, null and empty untouched', function () {
    expect(ArabicEncoding::fixMojibake('CUST-2026'))->toBe('CUST-2026')
        ->and(ArabicEncoding::fixMojibake(''))->toBe('')
        ->and(ArabicEncoding::fixMojibake(null))->toBeNull();
});
