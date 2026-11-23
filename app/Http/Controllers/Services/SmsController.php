<?php

namespace App\Http\Controllers\Services;

use App\Support\Messaging\ChannelSpec;

/**
 * قناة الرسائل النصية SMS — الجدولان MSG_QUEUE/MSG_ARCHIVE
 * SMS channel — MSG_QUEUE / MSG_ARCHIVE.
 *
 * القناة بلا حالة تشغيلية (لا `STATUS/ATTEMPTS/CLAIMED_*`) وبلا حقول ملفات، فترث من الفئة
 * المجرّدة كل شيء عدا المواصفات. الإرسال الفوري والإيقاف المؤقت غير مدعومين هنا ويعيدان 422.
 * The channel carries no worker state and no file fields, so it only supplies its spec. Direct send
 * and pause are unsupported here and answer 422.
 */
class SmsController extends MessageChannelController
{
    protected function spec(): ChannelSpec
    {
        return ChannelSpec::sms();
    }
}
