<?php

use App\Support\ArabicEncoding;

/**
 * عقد كتابة العربية في أوراكل — يحمي من الانحدار إلى بايتات CP1256 الخام
 * The Arabic write contract for Oracle — guards against regressing to raw CP1256 bytes.
 *
 * الاتصال بترميز عميل AL32UTF8 والقاعدة WE8MSWIN1252، فإرسال بايتات Windows-1256 الخام
 * يجعل أوراكل يستبدلها بعلامة الاستبدال 0xBF (تم إثباته بـ DUMP على اتصال حقيقي).
 * الصحيح هو ما تثبته هذه الاختبارات: كل بايت CP1256 يُربط كمحرف لاتيني بنفس الكود.
 * The connection speaks AL32UTF8 while the database is WE8MSWIN1252, so raw Windows-1256 bytes are
 * replaced by 0xBF (proven with DUMP on a live connection). What these tests pin is the correct
 * form: each CP1256 byte is bound as the Latin-1 character carrying the same code.
 */

/** الأسماء المستخدمة في الشاشة وفي قاعدة البيانات معاً */
const ARABIC_SAMPLES = ['خالد', 'عملاء الكبودي للخياطة', 'كشف حساب', 'عبد الله سالم'];

it('binds arabic as the exact cp1256 bytes the legacy system stores', function (string $text) {
    // ما يخزّنه النظام القديم: بايتات Windows-1256
    // What the legacy system stores: Windows-1256 bytes.
    $storedBytes = unpack('C*', iconv('UTF-8', 'Windows-1256', $text));
    $storedBytes = array_values($storedBytes ?: []);

    // وما يُربَط فعلياً: كل محرف لاتيني كودُه هو البايت المقصود، لأن العمود WE8MSWIN1252
    // فيستقر في القاعدة على نفس البايت بلا إتلاف.
    // And what we actually bind: one Latin-1 char whose codepoint *is* the intended byte, since the
    // column is WE8MSWIN1252 — so it lands in the database as that same byte, undamaged.
    $boundBytes = array_map(
        fn (string $char): int => mb_ord($char, 'UTF-8'),
        mb_str_split(ArabicEncoding::toOracleSearchChars($text))
    );

    expect($boundBytes)->toBe($storedBytes);
})->with(ARABIC_SAMPLES);

it('never sends raw high bytes as the bound value', function (string $text) {
    $bound = ArabicEncoding::toOracleSearchChars($text);

    // المحارف المربوطة كلها <= 0xFF، وكل منها يمثل بايتاً واحداً في القاعدة
    // Every bound character is <= 0xFF, and each one represents exactly one stored byte.
    foreach (mb_str_split($bound) as $char) {
        expect(mb_ord($char, 'UTF-8'))->toBeLessThanOrEqual(0xFF);
    }

    expect($bound)->not->toBe(iconv('UTF-8', 'Windows-1256', $text));
})->with(ARABIC_SAMPLES);

it('reads back exactly what it wrote', function (string $text) {
    $written = ArabicEncoding::toOracleSearchChars($text);

    // ما يصل من العمود afterwards هو نفس شكل الكتابة، فيعيده fixMojibake إلى UTF-8
    // What comes back out of the column has the same shape, so fixMojibake restores the UTF-8.
    expect(ArabicEncoding::fixMojibake($written))->toBe($text);
})->with(ARABIC_SAMPLES);

it('keeps ascii and empty values untouched', function () {
    expect(ArabicEncoding::toOracleSearchChars('CUST-2026'))->toBe('CUST-2026')
        ->and(ArabicEncoding::toOracleSearchChars(''))->toBe('')
        ->and(ArabicEncoding::toOracleSearchChars(null))->toBeNull();
});

it('has no raw-byte write helper left to misuse', function () {
    // الطريقة الخاطئة حُذفت من الفئة: أي مسار كتابة جديد مضبوط عليها يفشل بصوت عالٍ بدل إتلاف البيانات
    // The unsafe helper was deleted, so any code still aiming at it fails loudly instead of corrupting
    expect(method_exists(ArabicEncoding::class, 'encodeForOracle'))->toBeFalse();
});
