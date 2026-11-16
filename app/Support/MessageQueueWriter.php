<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * كاتب طابور الرسائل — القناة (نصية/واتساب) وحساب المعرّف والإدراج في مكان واحد
 * Message-queue writer — channel, id computation, and enqueue in one place.
 *
 * التصميم الجوهري الموروث من `MessageChannelController`: الـ API لا يرسل شبكياً أبلاً؛ الإدراج
 * بـ `IS_SENT='N'` يكفي، ومهام أوراكل (`SEND_MSG` كل 3 ثوانٍ و`W_SEND_MSG` كل 20 ثانية) تلتهم
 * الطابور وترسل وتؤرشف. أي إرسال فعلي من هنا كان سيتجاوز اشتراكات `PROVIDERS_DTL` وسجل الأرشفة.
 * The inherited core design (from MessageChannelController): the API never sends over the network;
 * inserting with `IS_SENT='N'` is enough — the Oracle jobs drain, transmit, and archive. Any direct
 * send from here would bypass the PROVIDERS_DTL subscriptions and the archive trail.
 *
 * قاعدة المعرّف (مطلوب صراحةً): أكبر تسلسل بين جدول الطابور وجدول الأرشيف + 1. الطابور يُفرَّغ
 * بعد الأرشفة، فاقتصار الحساب على `MAX(queue.ID)` وحده كان يعيد معرّفاً موجوداً في الأرشيف —
 * فيتكرر المعرّف بين جدولين تعاملهما الشاشات كسجل واحد (`show` يبحث في الطابور ثم الأرشيف،
 * وتبويب «الكل» يدمجهما) وإعادة إرسال مؤرشفة كانت ستتصادم مع صف منتظر. `GREATEST` بين الجدولين
 * يجعل المعرّف فريداً في السجل المنطقي الموحد لا في جدول واحد.
 * The id rule (explicit requirement): the highest sequence across the queue and archive tables, + 1.
 * The queue is drained after archiving, so MAX(queue.ID) alone could return an id that already
 * exists in the archive — duplicated across two tables the screens treat as one log (`show`
 * searches queue then archive, and the "all" tab merges both), and retrying an archived message
 * would collide with a pending row. GREATEST keeps the id unique in the unified logical log.
 *
 * وجهة الإرسال (SERVICE_ID/PROVIDER_ID): مهمة `SEND_MSG` تطابق الصف مع اشتراك نشط في
 * `PROVIDERS_DTL` بنفس المفتاحين، فبلا قيمتين صحيحتين يعلق الصف في الطابور بلا إرسال أبداً؛
 * ومهمة `W_SEND_MSG` تقرأ الاشتراك من القيم المكتوبة نفسها (`NVL(SERVICE_ID,'3')` و
 * `NVL(PROVIDER_ID,'1')`). لذلك تُحلَّل القيمتان من الاشتراك النشط الأول لخدمة القناة
 * (`SERVICES.SERVICE_TYPE`) ولا تُتركا NULL حيثاً — انظر `resolveDestination()`.
 * The send destination (SERVICE_ID/PROVIDER_ID): SEND_MSG matches the row against an active
 * PROVIDERS_DTL subscription by these two keys — without correct values the row hangs in the
 * queue forever; W_SEND_MSG reads the subscription from the very values written
 * (NVL(SERVICE_ID,'3') / NVL(PROVIDER_ID,'1')). Both are therefore resolved from the first
 * active subscription of the channel's service (SERVICES.SERVICE_TYPE) and never left NULL —
 * see resolveDestination().
 *
 * الترميز / encoding:
 *  - `MSG_TEXT` يأتي نصاً عربياً من الطلب فيُشفَّر هنا بـ `toOracleSearchChars()`.
 *    `MSG_TEXT` arrives as request Arabic and is encoded here.
 *  - `CUSTOMER_NAME` يأتي منسوخاً من عمود أوراكل قائم (بايتات CP1256 صحيحة أصلاً)، فلا يُعاد
 *    تحويله — التحويل المضاعف هو ما يفسد العربية في هذا المخطط.
 *    `CUSTOMER_NAME` is copied raw from an existing Oracle column (already valid CP1256 bytes);
 *    re-encoding it would corrupt the Arabic — the classic double-conversion bug in this schema.
 */
class MessageQueueWriter
{
    /** قناة الرسائل النصية / the SMS channel */
    public const CHANNEL_SMS = 'sms';

    /** قناة واتساب / the WhatsApp channel */
    public const CHANNEL_WHATSAPP = 'whatsapp';

    /**
     * جداول كل قناة: الطابور (منتظرة) والأرشيف (مُرسلة/فاشلة)
     * Per-channel tables: queue (pending) and archive (sent/failed)
     */
    private const TABLES = [
        self::CHANNEL_SMS => [
            'queue' => 'ACCOUNTS.MSG_QUEUE',
            'archive' => 'ACCOUNTS.MSG_ARCHIVE',
        ],
        self::CHANNEL_WHATSAPP => [
            'queue' => 'ACCOUNTS.W_MSG_QUEUE',
            'archive' => 'ACCOUNTS.W_MSG_ARCHIVE',
        ],
    ];

    /**
     * أنماط خدمة كل قناة في كتالوج `ACCOUNTS.SERVICES` — النصية `URL` (وبديلها `FIREBASE`)،
     * والواتساب `WHATSAPP` (خدمة 3 كما تثبّت مهمة `W_SEND_MSG` بمفتاحها).
     * Per-channel service types in the ACCOUNTS.SERVICES catalog — SMS is URL (FIREBASE as a
     * fallback), WhatsApp is WHATSAPP (service 3, as the W_SEND_MSG job fixes its key).
     */
    private const SERVICE_TYPES = [
        self::CHANNEL_SMS => ['URL', 'FIREBASE'],
        self::CHANNEL_WHATSAPP => ['WHATSAPP'],
    ];

    /**
     * المعرّف التالي في الطابور: أكبر تسلسل بين الطابور والأرشيف + 1
     * Next queue id: the highest sequence across queue and archive, + 1
     *
     * @param  string  $channel  قناة محددة (`sms` أو `whatsapp`) / a declared channel
     */
    public static function nextId(string $channel): int
    {
        ['queue' => $queue, 'archive' => $archive] = self::tables($channel);

        $row = (array) DB::connection('oracle')->selectOne(sprintf(
            'SELECT GREATEST(
                        (SELECT NVL(MAX(ID), 0) FROM %s),
                        (SELECT NVL(MAX(ID), 0) FROM %s)
                    ) AS NEXT_ID
               FROM DUAL',
            $queue,
            $archive
        ));

        $value = $row['NEXT_ID'] ?? $row['next_id'] ?? 0;

        return (int) $value + 1;
    }

    /**
     * إضافة رسالة إلى الطابور بـ `IS_SENT='N'` — المهمة الزمنية هي التي ترسلها فعلاً
     * Enqueue a message with `IS_SENT='N'` — the scheduler job is what actually transmits it.
     *
     * @param  string  $channel  `sms` أو `whatsapp` / a declared channel
     * @param  string  $text  نص الرسالة (عربي من الطلب، يُشفَّر هنا) / message text (request Arabic, encoded here)
     * @param  int  $mobile  رقم الجوال مطبَّعاً بأرقام فقط / normalised digits-only mobile number
     * @param  string|null  $customerName  اسم العميل منسوخاً خاماً من عمود أوراكل / customer name copied raw from an Oracle column
     * @param  string|null  $createdBy  اسم الفاعل لتدقيق `CREATED_BY` / actor name for the CREATED_BY audit column
     * @param  array{provider_id: int, service_id: int}|null  $destination  وجهة الإرسال المحلَّلة مسبقاً (اختيارية — تُحلَّل داخلياً عند تركها) / pre-resolved send destination (optional — resolved internally when omitted)
     * @return int معرّف الصف المُدرَج في الطابور / the inserted queue row id
     *
     * @throws \RuntimeException عند غياب اشتراك نشط للقناة / when the channel has no active subscription
     */
    public static function enqueue(
        string $channel,
        string $text,
        int $mobile,
        ?string $customerName = null,
        ?string $createdBy = null,
        ?array $destination = null,
    ): int {
        ['queue' => $queue] = self::tables($channel);

        // الوجهة تُحلَّل من الاشتراك النشط، والإدراج بلا وجهة مرفوض: صف لا يطابق اشتراكاً نشطاً
        // يعلق في الطابور بلا إرسال أبداً (مسار HTTP يتحقق مبكراً ويعطي 422)
        // The destination is resolved from the active subscription, and inserting without one is
        // refused: a row matching no active subscription hangs in the queue forever (the HTTP path
        // pre-checks this and answers 422).
        $destination ??= self::resolveDestination($channel);

        if ($destination === null) {
            throw new \RuntimeException('لا يوجد اشتراك إرسال نشط لهذه القناة / No active send subscription for this channel.');
        }

        $id = self::nextId($channel);

        // المعرّف يُسحب قبل الإدراج ويعاد استخدامه هنا — لا تسلسل على هذه الجداول الإرثية
        // The id is computed before the insert and reused here — no sequence on these legacy tables.
        DB::connection('oracle')->table($queue)->insert([
            'ID' => $id,
            'MSG_TEXT' => ArabicEncoding::toOracleSearchChars($text),
            'MOBILE_NO' => $mobile,
            'IS_SENT' => 'N',
            'SEND_ATTEMPT' => 0,
            // SERVICE_ID/PROVIDER_ID من الاشتراك النشط — مهمة الإرسال تطابق الصف بهذين
            // المفتاحين، وبلا مطابقة يعلق الصف في الطابور بلا إرسال أبداً
            // SERVICE_ID/PROVIDER_ID come from the active subscription — the send job matches the
            // row by these two keys; without a match the row hangs in the queue forever.
            'SERVICE_ID' => $destination['service_id'],
            'PROVIDER_ID' => $destination['provider_id'],
            'CUSTOMER_NAME' => $customerName,
            'CREATED_AT' => DB::raw('SYSDATE'),
            'CREATED_BY' => $createdBy,
        ]);

        return $id;
    }

    /**
     * وجهة الإرسال الصحيحة للقناة: اشتراك نشط في `PROVIDERS_DTL` لخدمة القناة في كتالوج
     * `SERVICES` (النصية `URL` وبديل `FIREBASE`، وواتساب `WHATSAPP`). مهمة `SEND_MSG` تطابق
     * الصف مع اشتراك نشط بنفس `PROVIDER_ID/SERVICE_ID`، ومهمة `W_SEND_MSG` تقرأ الاشتراك من
     * القيم المكتوبة نفسها (`NVL(SERVICE_ID,'3')` و`NVL(PROVIDER_ID,'1')`) — فبلا قيمتين
     * صحيحتين يعلق الصف في الطابور بلا إرسال أبداً، لذا تُحلَّلان ولا تُتركا NULL حيثاً.
     * The channel's correct send destination: an active PROVIDERS_DTL subscription for the
     * channel's service in the SERVICES catalog (SMS is URL with FIREBASE as fallback, WhatsApp
     * is WHATSAPP). SEND_MSG matches the row against an active subscription by the same
     * PROVIDER_ID/SERVICE_ID, and W_SEND_MSG reads the subscription from the very values written
     * (NVL(SERVICE_ID,'3') / NVL(PROVIDER_ID,'1')) — without correct values the row hangs in the
     * queue forever, so both are resolved here and never left NULL.
     *
     * @return array{provider_id: int, service_id: int}|null null عند غياب اشتراك نشط / null when no active subscription exists
     */
    public static function resolveDestination(string $channel): ?array
    {
        // رفض القناة غير المعلنة قبل أي استعلام / refuse an undeclared channel before any query
        self::tables($channel);

        $types = implode(', ', array_map(
            static fn (string $type): string => "'{$type}'",
            self::SERVICE_TYPES[$channel]
        ));

        // أول اشتراك نشط بترتيب ثابت (d.ID) — صفّا DTL 4/5 (خدمتان غير موجودتين) يسقطان بالربط
        // The first active subscription in a stable order (d.ID) — DTL rows 4/5 (two unknown
        // services) drop out through the join.
        $row = (array) DB::connection('oracle')->selectOne(sprintf(
            'SELECT PROVIDER_ID, SERVICE_ID
               FROM (
                     SELECT d.PROVIDER_ID, d.SERVICE_ID
                       FROM ACCOUNTS.PROVIDERS_DTL d
                       JOIN ACCOUNTS.SERVICES s ON s.SERVICE_ID = d.SERVICE_ID
                      WHERE s.SERVICE_TYPE IN (%s)
                        AND d.STATUS = 1
                      ORDER BY d.ID
                    )
              WHERE ROWNUM = 1',
            $types
        ));

        $providerId = $row['PROVIDER_ID'] ?? $row['provider_id'] ?? null;
        $serviceId = $row['SERVICE_ID'] ?? $row['service_id'] ?? null;

        if ($providerId === null || $serviceId === null) {
            return null;
        }

        return ['provider_id' => (int) $providerId, 'service_id' => (int) $serviceId];
    }

    /**
     * أسماء جداول القناة، مع رفض أي قناة غير معلنة قبل لمس القاعدة
     * Channel table names, refusing any undeclared channel before the database is touched
     *
     * @return array{queue: string, archive: string}
     */
    private static function tables(string $channel): array
    {
        $tables = self::TABLES[$channel] ?? null;

        if ($tables === null) {
            throw new \InvalidArgumentException("قناة رسائل غير معروفة / Unknown message channel [{$channel}].");
        }

        return $tables;
    }
}
