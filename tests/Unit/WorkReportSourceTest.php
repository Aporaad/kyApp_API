<?php

use App\Support\WorkReportSource;

/**
 * قواعد أنواع كشف العمل الثلاثة — اختبار خالص بلا قاعدة بيانات.
 * The three work-report kinds — a pure test, no database involved.
 *
 * هذه القواعد تُبنى منها استعلامات كشف العامل وكشف المجموعة والكشف الشخصي معاً،
 * فأي انحراف فيها يظهر في الثلاثة دفعة واحدة.
 * All three reports (single worker, group, personal) build their queries from these
 * rules, so a drift here would show up in all of them at once.
 */
test('accepts exactly the three kinds and falls back to posted', function () {
    expect(WorkReportSource::resolve('calc'))->toBe(WorkReportSource::POSTED)
        ->and(WorkReportSource::resolve('uncalc'))->toBe(WorkReportSource::UNPOSTED)
        ->and(WorkReportSource::resolve('undone'))->toBe(WorkReportSource::UNDONE)
        ->and(WorkReportSource::resolve('  UNDONE '))->toBe(WorkReportSource::UNDONE)
        ->and(WorkReportSource::resolve('unfinished'))->toBe(WorkReportSource::POSTED)
        ->and(WorkReportSource::resolve(''))->toBe(WorkReportSource::POSTED)
        ->and(WorkReportSource::resolve(null))->toBe(WorkReportSource::POSTED);
});

test('labels each kind with its Arabic name', function () {
    expect(WorkReportSource::label(WorkReportSource::POSTED))->toBe('المرحل')
        ->and(WorkReportSource::label(WorkReportSource::UNPOSTED))->toBe('الغير مرحل')
        ->and(WorkReportSource::label(WorkReportSource::UNDONE))->toBe('الغير منجز');
});

test('only posted reads the CALC table', function () {
    expect(WorkReportSource::usesUnpostedTable(WorkReportSource::POSTED))->toBeFalse()
        ->and(WorkReportSource::usesUnpostedTable(WorkReportSource::UNPOSTED))->toBeTrue()
        ->and(WorkReportSource::usesUnpostedTable(WorkReportSource::UNDONE))->toBeTrue();
});

test('the UN column splits unposted from not-done, and posted stays unconditional', function () {
    expect(WorkReportSource::rowCondition(WorkReportSource::POSTED))->toBeNull()
        ->and(WorkReportSource::rowCondition(WorkReportSource::UNPOSTED))
        ->toBe('NVL(w.UN, 0) = 0')
        ->and(WorkReportSource::rowCondition(WorkReportSource::UNDONE))
        ->toBe('NVL(w.UN, 0) <> 0');
});

test('unposted filters on the work date while not-done filters on DDATE', function () {
    // غير المرحَّل: تاريخ القص/الخياطة — وغير المنجز: تاريخ المستند.
    expect(WorkReportSource::dateExpression(WorkReportSource::UNPOSTED, 'CUTTING_DATE'))
        ->toBe('w.CUTTING_DATE')
        ->and(WorkReportSource::dateExpression(WorkReportSource::UNPOSTED, 'TAILOR_DATE'))
        ->toBe('w.TAILOR_DATE')
        ->and(WorkReportSource::dateExpression(WorkReportSource::UNDONE, 'TAILOR_DATE'))
        ->toBe('w.DDATE')
        ->and(WorkReportSource::dateExpression(WorkReportSource::POSTED, 'CUTTING_DATE'))
        ->toBe('w.CUTTING_DATE');
});

test('not-done reports the remaining UN quantity with no amount', function () {
    expect(WorkReportSource::quantityExpression(WorkReportSource::UNDONE, 'POST'))
        ->toBe('NVL(w.UN, 0)')
        ->and(WorkReportSource::quantityExpression(WorkReportSource::UNPOSTED, 'POST'))
        ->toBe('NVL(w.POST, 0)')
        ->and(WorkReportSource::totalExpression(WorkReportSource::UNDONE, 'AMT_ALL'))
        ->toBe('0')
        ->and(WorkReportSource::totalExpression(WorkReportSource::UNPOSTED, 'AMT_ALL'))
        ->toBe('NVL(w.AMT_ALL, 0)')
        ->and(WorkReportSource::totalExpression(WorkReportSource::POSTED, 'AMT'))
        ->toBe('NVL(w.AMT, 0)');
});
