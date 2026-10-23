<?php

namespace App\Http\Controllers\Services;

/**
 * قناة الرسائل النصية SMS — الجدولان MSG_QUEUE/MSG_ARCHIVE
 * SMS channel — MSG_QUEUE / MSG_ARCHIVE tables.
 */
class SmsController extends MessageChannelController
{
    protected function queueTable(): string
    {
        return 'ACCOUNTS.MSG_QUEUE';
    }

    protected function archiveTable(): string
    {
        return 'ACCOUNTS.MSG_ARCHIVE';
    }

    protected function module(): string
    {
        return 'sms';
    }

    protected function extraColumns(): array
    {
        return [];
    }
}
