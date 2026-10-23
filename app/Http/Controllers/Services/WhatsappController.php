<?php

namespace App\Http\Controllers\Services;

/**
 * قناة رسائل واتساب — الجدولان W_MSG_QUEUE/W_MSG_ARCHIVE (مع حقول ملف ورسالة خطأ)
 * WhatsApp channel — W_MSG_QUEUE / W_MSG_ARCHIVE, with file fields and an ERROR_MSG column.
 */
class WhatsappController extends MessageChannelController
{
    protected function queueTable(): string
    {
        return 'ACCOUNTS.W_MSG_QUEUE';
    }

    protected function archiveTable(): string
    {
        return 'ACCOUNTS.W_MSG_ARCHIVE';
    }

    protected function module(): string
    {
        return 'whatsapp';
    }

    /**
     * أعمدة واتساب الإضافية: خرائط عمود القاعدة => مفتاح الطلب
     * Extra whatsapp columns: DB column => request key
     */
    protected function extraColumns(): array
    {
        return [
            'FILE_PATH' => 'file_path',
            'FILE_NAME' => 'file_name',
            'FILE_TYPE' => 'file_type',
            'CAPTION' => 'caption',
        ];
    }

    protected function hasErrorColumn(): bool
    {
        return true;
    }
}
