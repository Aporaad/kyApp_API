<?php

namespace App\Http\Controllers\Services;

use App\Support\Messaging\ChannelSpec;

/**
 * قناة رسائل واتساب — الجدولان W_MSG_QUEUE/W_MSG_ARCHIVE (بحالة تشغيلية وحقول ملف وخطأ)
 * WhatsApp channel — W_MSG_QUEUE / W_MSG_ARCHIVE (with worker state, file fields, and an error column).
 */
class WhatsappController extends MessageChannelController
{
    protected function spec(): ChannelSpec
    {
        return ChannelSpec::whatsapp();
    }
}
