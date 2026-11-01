<?php

use Illuminate\Support\Facades\DB;

/**
 * سلامة تسلسلات التطبيق: كل تسلسل يجب أن يسبق أعلى معرّف في جدوله
 * Application sequence integrity: every sequence must stay ahead of its table's highest id.
 *
 * ── لماذا هذا الاختبار موجود؟ لأن هذا العطل وقع فعلاً مرتين في هذا المشروع، وفي كل مرة فشل
 *    إدراجُ صفوفٍ جديدة بصمت: تسلسل الكتالوج أخرج `ORA-00001` عند بذر صلاحيات المخازن، وتسلسل
 *    سجل النشاط كان متأخراً بـ271 رقماً فصار `ActivityLogger` يبتلع الخطأ ولا يكتب صفاً واحداً
 *    منذ ذلك الحين — أي أن التدقيق كله ميت والجميع يظنه يعمل.
 * ── Why this test exists: the defect already happened twice here, each time silently blocking
 *    new rows. The catalog sequence raised ORA-00001 while seeding the stores codes, and the
 *    activity sequence sat 271 numbers behind its table, so ActivityLogger swallowed the error
 *    and wrote nothing at all — the whole audit trail was dead while looking healthy.
 *
 * ── القاعدة عادلة ومستقرة: `NEXTVAL` يتقدم دائماً ولا يعود، و`MAX(id)` لا يمكن أن يتجاوز
 *    التسلسل ما دام الإدراج يمرّ من خلاله. أي فشل هنا يعني إدراجاً معطلاً في الإنتاج.
 * ── The invariant is stable: NEXTVAL only grows, and MAX(id) cannot pass it while inserts go
 *    through the sequence. A failure here means inserts are broken in production.
 */
dataset('application sequences', [
    'activity audit' => ['SEQ_APP_ACTIVITY', 'ACCOUNTS.APP_USER_ACTIVITY', 'ACTIVITY_ID'],
    'permission catalog' => ['SEQ_APP_PERMISSIONS', 'ACCOUNTS.APP_PERMISSIONS', 'PERMISSION_ID'],
    'users' => ['SEQ_APP_USERS_ID', 'ACCOUNTS.APP_USERS', 'USER_ID'],
    'user grants' => ['SEQ_APP_USERS_PERM', 'ACCOUNTS.APP_USERS_PERMISSIONS', 'ID'],
    'settings' => ['SEQ_APP_SETTINGS', 'ACCOUNTS.APP_SETTINGS', 'SETTING_ID'],
    'support tickets' => ['SEQ_APP_SUPPORT_TICKETS', 'ACCOUNTS.APP_SUPPORT_TICKETS', 'TICKET_ID'],
]);

function sequenceScalar(string $sql): ?int
{
    try {
        $row = array_change_key_case(
            (array) DB::connection('oracle')->selectOne($sql),
            CASE_UPPER
        );

        return (int) (reset($row) ?: 0);
    } catch (Throwable) {
        return null;
    }
}

test('a sequence stays ahead of its table', function (string $sequence, string $table, string $column) {
    $max = sequenceScalar("SELECT NVL(MAX({$column}), 0) AS M FROM {$table}");

    if ($max === null) {
        test()->markTestSkipped("تعذر قراءة {$table} — قاعدة oracle غير متاحة.");
    }

    $next = sequenceScalar("SELECT {$sequence}.NEXTVAL AS N FROM DUAL");

    expect($next)->not->toBeNull()
        ->and($next)->toBeGreaterThan($max);
})->with('application sequences');

test('an activity row can actually be written', function () {
    $oracle = DB::connection('oracle');
    $before = sequenceScalar('SELECT COUNT(*) AS C FROM ACCOUNTS.APP_USER_ACTIVITY');

    if ($before === null) {
        test()->markTestSkipped('قاعدة oracle غير متاحة.');
    }

    $oracle->beginTransaction();

    try {
        // إدراج بمخطط الجدول نفسه الذي يكتب به ActivityLogger
        // Insert using the same column shape ActivityLogger writes.
        $oracle->insert(
            "INSERT INTO ACCOUNTS.APP_USER_ACTIVITY
                (ACTION_CODE, MODULE_CODE, ENTITY_TYPE, ENTITY_ID, DESCRIPTION_AR, STATUS, CREATED_ON)
             VALUES ('sequence.probe', 'system', 'probe', '0', 'مسبار', 'success', SYSDATE)"
        );

        $during = sequenceScalar('SELECT COUNT(*) AS C FROM ACCOUNTS.APP_USER_ACTIVITY');

        expect($during)->toBe($before + 1);
    } finally {
        $oracle->rollBack();
    }

    // التراجع يعيد العدد كما كان: الاختبار لا يترك أثراً
    // The rollback restores the count: the test leaves nothing behind.
    expect(sequenceScalar('SELECT COUNT(*) AS C FROM ACCOUNTS.APP_USER_ACTIVITY'))->toBe($before);
});
