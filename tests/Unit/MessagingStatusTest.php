<?php

use App\Support\Messaging\MessageActions;
use App\Support\Messaging\MessageStatus;
use App\Support\Messaging\WhatsAppDirectSender;

/**
 * الحالة المؤثرة والإجراءات وتصنيف ردود البوابة — كلها دوال نقية بلا قاعدة
 * Effective status, actions, and gateway-response classification — pure functions, no database.
 *
 * أهم حالة في الملف هي `IS_SENT='F'` مع `STATUS='NEW'`: هي البيانات الحقيقية في `W_MSG_QUEUE`
 * اليوم، وكانت الشاشة القديمة تعرضها «فاشلة» وهي منتظرة. هذا الاختبار يثبّت القراءة الصحيحة.
 * The most important case here is `IS_SENT='F'` with `STATUS='NEW'` — the live state of the queue
 * rows today, which the old screen rendered as failed while they were merely waiting for a retry.
 */
test('a queue row that failed its last attempt is still pending', function () {
    // الحالة الحيّة: 7 صفوف في الطابور بهذين الحقلين بالضبط
    // The live state: seven queue rows carrying exactly these two values.
    expect(MessageStatus::of(['SRC' => 'queue', 'STATUS' => 'NEW', 'IS_SENT' => 'F']))
        ->toBe(MessageStatus::PENDING);
});

test('every queue status maps to its effective status', function (string $queueStatus, string $isSent, string $expected) {
    expect(MessageStatus::of(['SRC' => 'queue', 'STATUS' => $queueStatus, 'IS_SENT' => $isSent]))->toBe($expected);
})->with([
    ['NEW', 'N', MessageStatus::PENDING],
    ['NEW', 'F', MessageStatus::PENDING],
    ['PAUSED', 'N', MessageStatus::PAUSED],
    ['PROCESSING', 'P', MessageStatus::SENDING],
    ['SENDING', 'P', MessageStatus::SENDING],
    ['UNKNOWN', 'F', MessageStatus::UNKNOWN],
    ['DEAD', 'F', MessageStatus::FAILED],
    ['TRANSIENT_NET', 'F', MessageStatus::FAILED],
]);

test('archive statuses come from the final IS_SENT value', function (string $isSent, string $expected) {
    expect(MessageStatus::of(['SRC' => 'archive', 'IS_SENT' => $isSent]))->toBe($expected);
})->with([
    ['Y', MessageStatus::SENT],
    ['N', MessageStatus::FAILED],
    ['NV', MessageStatus::INVALID],
    ['DUP', MessageStatus::DUPLICATE],
    ['X', MessageStatus::UNKNOWN],
]);

test('a locked or processing row is busy and cannot be touched', function () {
    expect(MessageStatus::isBusy(['STATUS' => 'SENDING', 'SEND_LOCK' => 'N']))->toBeTrue()
        ->and(MessageStatus::isBusy(['STATUS' => 'PROCESSING', 'SEND_LOCK' => 'N']))->toBeTrue()
        ->and(MessageStatus::isBusy(['STATUS' => 'NEW', 'SEND_LOCK' => 'Y']))->toBeTrue()
        ->and(MessageStatus::isBusy(['STATUS' => 'NEW', 'SEND_LOCK' => 'N']))->toBeFalse();
});

test('the action matrix never offers an edit or a delete while a row is sending', function () {
    $all = fn (string $code): bool => true;

    expect(MessageActions::forRow(MessageStatus::SENDING, 'queue', 'whatsapp', $all, true))
        ->toBe([MessageActions::VIEW]);
});

test('the action matrix is intersected with the caller grants', function () {
    $viewOnly = fn (string $code): bool => $code === 'whatsapp.view';
    $updateOnly = fn (string $code): bool => $code === 'whatsapp.update';

    expect(MessageActions::forRow(MessageStatus::PENDING, 'queue', 'whatsapp', $viewOnly, true))
        ->toBe([MessageActions::VIEW]);

    expect(MessageActions::forRow(MessageStatus::PENDING, 'queue', 'whatsapp', $updateOnly, true))
        ->toBe([MessageActions::EDIT]);

    $everything = fn (string $code): bool => true;
    expect(MessageActions::forRow(MessageStatus::FAILED, 'queue', 'whatsapp', $everything, true))
        ->toBe([
            MessageActions::VIEW,
            MessageActions::EDIT,
            MessageActions::DELETE,
            MessageActions::SEND_NOW,
            MessageActions::RESEND,
        ]);
});

test('the sms channel never offers pause, resume, or send-now', function () {
    $everything = fn (string $code): bool => true;

    expect(MessageActions::forRow(MessageStatus::PENDING, 'queue', 'sms', $everything, false))
        ->toBe([MessageActions::VIEW, MessageActions::EDIT, MessageActions::DELETE, MessageActions::RESEND]);

    expect(MessageActions::isAllowed(MessageActions::PAUSE, MessageStatus::PENDING, 'queue', false))->toBeFalse()
        ->and(MessageActions::isAllowed(MessageActions::PAUSE, MessageStatus::PENDING, 'queue', true))->toBeTrue();
});

test('a gateway success carrying a message id is a send', function () {
    $result = WhatsAppDirectSender::classify('OK:{"messageId":"false_9677@c.us_3A","timestamp":1794104822}');

    expect($result['outcome'])->toBe('sent')
        ->and($result['provider_msg_id'])->toBe('false_9677@c.us_3A')
        ->and($result['http'])->toBe(201);
});

test('a success without a message id is not proof of delivery', function () {
    expect(WhatsAppDirectSender::classify('OK:{}')['outcome'])->toBe('unknown');
});

test('gateway failures map to the documented outcomes', function (string $raw, string $outcome, ?int $http) {
    $result = WhatsAppDirectSender::classify($raw);

    expect($result['outcome'])->toBe($outcome)
        ->and($result['http'])->toBe($http);
})->with([
    ['ERROR:HTTP_409:{"message":"already in progress"}', 'duplicate', 409],
    ['ERROR:HTTP_400:{"message":"bad chat id"}', 'permanent', 400],
    ['ERROR:HTTP_404:{"message":"session not found"}', 'permanent', 404],
    ['ERROR:HTTP_413:{"message":"too large"}', 'permanent', 413],
    ['ERROR:HTTP_414:{"message":"uri too long"}', 'permanent', 414],
    ['ERROR:HTTP_429:{"message":"rate limited"}', 'retry', 429],
    ['ERROR:HTTP_500:{"message":"boom"}', 'unknown', 500],
    ['ERROR:HTTP_401:{"message":"API key has expired"}', 'auth', 401],
    ['ERROR:NET_ERR:Connection refused', 'network', null],
    ['', 'unknown', null],
]);
