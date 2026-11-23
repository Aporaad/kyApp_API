<?php

namespace App\Support\Messaging;

/**
 * مواصفات قناة الرسائل — الجدولان والوحدة وحقول القناة الخاصة في كائن واحد
 * A message channel's spec — both tables, its module code, and channel-specific fields.
 *
 * الغرض: إبقاء المتحكّم المجرّد مشتركاً بين SMS وواتساب بلا `if ($channel)` متكررة، ولأن الاختلاف
 * الجوهري بينهما محصور في ثلاثة أشياء: وجود حالة تشغيلية (`STATUS/ATTEMPTS/CLAIMED_*`) في واتساب،
 * ووجود حقول ملف، ووجود عمود `ERROR_MSG`. كل ما عدا ذلك متطابق.
 * The point: keep the abstract controller shared without repeated `if ($channel)`, because the two
 * channels differ in exactly three ways — WhatsApp carries a worker state, file fields, and an error
 * column. Everything else is identical.
 */
final class ChannelSpec
{
    /**
     * @param  list<string>  $serviceTypes  أنماط `SERVICES.SERVICE_TYPE` التي تخدم القناة
     */
    private function __construct(
        public readonly string $key,
        public readonly string $queue,
        public readonly string $archive,
        public readonly string $module,
        public readonly bool $hasWorkerState,
        public readonly bool $hasFileFields,
        public readonly bool $hasErrorColumn,
        public readonly array $serviceTypes,
    ) {}

    public static function sms(): self
    {
        return new self(
            key: 'sms',
            queue: 'ACCOUNTS.MSG_QUEUE',
            archive: 'ACCOUNTS.MSG_ARCHIVE',
            module: 'sms',
            hasWorkerState: false,
            hasFileFields: false,
            hasErrorColumn: false,
            serviceTypes: ['URL', 'FIREBASE'],
        );
    }

    public static function whatsapp(): self
    {
        return new self(
            key: 'whatsapp',
            queue: 'ACCOUNTS.W_MSG_QUEUE',
            archive: 'ACCOUNTS.W_MSG_ARCHIVE',
            module: 'whatsapp',
            hasWorkerState: true,
            hasFileFields: true,
            hasErrorColumn: true,
            serviceTypes: ['WHATSAPP'],
        );
    }

    /**
     * هل تدعم القناة الإيقاف المؤقت والإرسال الفوري؟ (حالة تشغيلية موجودة = نعم)
     * Does the channel support pause and send-now? (A worker state exists = yes.)
     */
    public function supportsWorkerActions(): bool
    {
        return $this->hasWorkerState;
    }
}
