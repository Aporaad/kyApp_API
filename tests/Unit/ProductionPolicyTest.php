<?php

use App\Support\Production\ProductionPolicy;

/**
 * خريطة خط الإنتاج — الحالات والأقسام والوجهات
 * The production-line map — statuses, stations and destinations.
 *
 * لا تلمس قاعدة البيانات: هذه عقود ثابتة يحرسها الاختبار قبل أي كتابة.
 * No database: these are fixed contracts the test guards before any write.
 */
it('maps the eight stations with their from/to statuses', function () {
    expect(ProductionPolicy::SECTIONS)->toHaveCount(8);

    expect(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_APPROVAL]['from'])
        ->toBe(ProductionPolicy::STATUS_NEW);
    expect(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_APPROVAL]['to'])
        ->toBe(ProductionPolicy::STATUS_APPROVED);

    expect(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_TO_CUT]['to'])
        ->toBe(ProductionPolicy::STATUS_AT_CUTTER);
    expect(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_FROM_CUT]['to'])
        ->toBe(ProductionPolicy::STATUS_RECEIVED_FROM_CUT);

    expect(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_CUT_TRANSFER]['from'])
        ->toBe(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_CUT_TRANSFER]['to']);

    expect(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_READY]['from'])
        ->toBe(ProductionPolicy::STATUS_RECEIVED_FROM_TAILOR);
    expect(ProductionPolicy::SECTIONS[ProductionPolicy::SECTION_READY]['to'])
        ->toBe(ProductionPolicy::STATUS_READY);
});

it('implements exactly the first three stations in this phase', function () {
    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_APPROVAL))->toBeTrue();
    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_TO_CUT))->toBeTrue();
    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_FROM_CUT))->toBeTrue();

    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_CUT_TRANSFER))->toBeFalse();
    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_TO_TAILOR))->toBeFalse();
    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_FROM_TAILOR))->toBeFalse();
    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_TAILOR_TRANSFER))->toBeFalse();
    expect(ProductionPolicy::isAvailable(ProductionPolicy::SECTION_READY))->toBeFalse();
});

it('carries unique view codes for every station', function () {
    $codes = ProductionPolicy::viewCodes();

    expect($codes)->toHaveCount(8);
    expect(array_unique($codes))->toHaveCount(8);

    foreach (ProductionPolicy::SECTIONS as $section) {
        expect($section['view'])->toStartWith('prod_');
        expect($section['view'])->toEndWith('.view');
    }
});

it('labels every legacy status, including the odd stock movements', function () {
    $expected = [
        0 => 'جديد',
        1 => 'معتمد',
        2 => 'تسليم للقص',
        3 => 'تم القص',
        4 => 'استلام من القص',
        5 => 'تم التجهيز1',
        6 => 'تسليم للخياط',
        7 => 'تم الخياط',
        8 => 'استلام من الخياط',
        9 => 'تجهيز 2',
        10 => 'انتظار التسليم',
        11 => 'تم التسليم',
    ];

    foreach ($expected as $status => $label) {
        expect(ProductionPolicy::statusLabel($status))->toBe($label);
    }

    expect(ProductionPolicy::statusLabel(99))->toBe('حالة 99');
});

it('clips Arabic without splitting a character', function () {
    expect(ProductionPolicy::clip('خالد', 3))->toBe('خال');
    expect(ProductionPolicy::clip('خالد', 10))->toBe('خالد');
    expect(ProductionPolicy::clip(null, 5))->toBeNull();
});
