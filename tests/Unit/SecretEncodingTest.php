<?php

use App\Support\ArabicEncoding;

/**
 * عقد الأسرار مع أوراكل — نفس عقد النصوص: تُرمَّز عند الكتابة وتُصلَّح عند القراءة
 * The secrets contract with Oracle — the same as text: encoded on write, repaired on read.
 *
 * سبب الاختبار: كان مسار كتابة `PROVIDERS_DTL.USER_NAME/PASSWORD/TOKEN` يربط القيمة كما هي، فأي
 * سرّ فيه حرف غير ASCII يُخزّن `¿` لأن الجدول WE8MSWIN1252. الأسرار الآن تمرّ على
 * `toOracleSearchChars` مثل أي نص عربي، والـASCII يبقى كما هو (وهو حال المفاتيح الفعلية).
 * Why: the subscription secrets were bound raw, so any non-ASCII secret landed as `¿` in a
 * WE8MSWIN1252 column. They now go through the same encoder as any Arabic text, and pure ASCII
 * (the real keys) is unchanged.
 */
test('a non-ascii secret survives the oracle round trip', function () {
    $secret = 'كلمة سر 12';

    $encoded = ArabicEncoding::toOracleSearchChars($secret);

    expect($encoded)->not->toBe($secret)
        ->and(ArabicEncoding::fixMojibake($encoded))->toBe($secret)
        ->and(ArabicEncoding::fixMojibake(ArabicEncoding::fixMojibake($encoded)))->toBe($secret);
});

test('a plain ascii secret is written byte-for-byte', function () {
    foreach (['wapi_abc123', '9677-0000-1111', 'P@ssw0rd!', ''] as $secret) {
        expect(ArabicEncoding::toOracleSearchChars($secret))->toBe($secret);
    }
});
