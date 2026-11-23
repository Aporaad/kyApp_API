<?php

namespace App\Support\Messaging;

/**
 * الحالة «المؤثرة» للرسالة — المصدر الوحيد للحقيقة في الخادم والعميل
 * The *effective* message status — the single source of truth for server and client.
 *
 * لماذا لا نشتق الحالة من `IS_SENT` وحده؟ لأن جدول الطابور يحمل حقلين مختلفي الدلالة:
 *   - `IS_SENT`: راية نقل (`N` منتظرة، `P` قيد المعالجة، `F` آخر محاولة فشلت، `Y` مُرسلة).
 *   - `STATUS`: حالة المعالجة الحقيقية (`NEW`, `PROCESSING`, `SENDING`, `DEAD`, `UNKNOWN`, `PAUSED`).
 * الصف `STATUS='NEW'` مع `IS_SENT='F'` **منتظر** (المحرّك سيعيد محاولته) وليس فاشلاً؛ وقراءة
 * `IS_SENT` وحدها هي ما جعل سبع رسائل حيّة تظهر «فاشلة» في الشاشة القديمة.
 * Why not derive from `IS_SENT` alone? The queue carries two fields with different meanings: `IS_SENT`
 * is a transport flag (`N` pending, `P` processing, `F` last attempt failed, `Y` sent) while `STATUS`
 * is the real processing state. A row with `STATUS='NEW'` + `IS_SENT='F'` is **pending** (the engine
 * will retry it), not failed — reading `IS_SENT` alone is what made seven live rows look failed.
 */
final class MessageStatus
{
    public const PENDING = 'pending';

    public const PAUSED = 'paused';

    public const SENDING = 'sending';

    public const UNKNOWN = 'unknown';

    public const FAILED = 'failed';

    public const SENT = 'sent';

    public const INVALID = 'invalid';

    public const DUPLICATE = 'duplicate';

    /**
     * كل الحالات المقبولة كفلتر — الترتيب هو ترتيب الشرائح في الشاشة
     * Every filterable status, in the order the screen shows its chips.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PAUSED,
            self::SENDING,
            self::FAILED,
            self::UNKNOWN,
            self::SENT,
            self::INVALID,
            self::DUPLICATE,
        ];
    }

    /**
     * تعبير حالة صف الطابور — يُبنى بـ`CASE` في أوراكل 11g (لا أنواع enum)
     * Queue-row status expression — a plain Oracle `CASE` (11g has no enums).
     *
     * @param  string  $alias  اسم/كنية جدول الطابور في الاستعلام
     */
    public static function queueExpression(string $alias = 'q'): string
    {
        return "CASE
                    WHEN {$alias}.STATUS IN ('PROCESSING','SENDING') THEN '".self::SENDING."'
                    WHEN {$alias}.STATUS = 'PAUSED' THEN '".self::PAUSED."'
                    WHEN {$alias}.STATUS = 'UNKNOWN' THEN '".self::UNKNOWN."'
                    WHEN {$alias}.STATUS = 'DEAD' THEN '".self::FAILED."'
                    WHEN {$alias}.STATUS = 'NEW' THEN '".self::PENDING."'
                    WHEN UPPER({$alias}.IS_SENT) = 'F' THEN '".self::FAILED."'
                    ELSE '".self::PENDING."'
                END";
    }

    /**
     * تعبير حالة صف الأرشيف — `IS_SENT` هناك نهائي: Y مُرسلة، N فاشلة، NV رقم غير صالح، DUP مكررة
     * Archive-row status expression — `IS_SENT` is final there: Y sent, N failed, NV invalid, DUP dup.
     */
    public static function archiveExpression(string $alias = 'a'): string
    {
        return "CASE UPPER({$alias}.IS_SENT)
                    WHEN 'Y' THEN '".self::SENT."'
                    WHEN 'N' THEN '".self::FAILED."'
                    WHEN 'NV' THEN '".self::INVALID."'
                    WHEN 'DUP' THEN '".self::DUPLICATE."'
                    ELSE '".self::UNKNOWN."'
                END";
    }

    /**
     * الحالة المؤثرة لصف واحد في PHP (نفس منطق تعبيرَي SQL أعلاه)
     * The effective status for a single row in PHP — mirrors the SQL expressions above.
     *
     * @param  array<string, mixed>  $row
     */
    public static function of(array $row): string
    {
        $src = strtolower((string) ($row['SRC'] ?? $row['src'] ?? 'queue'));

        if ($src === 'archive') {
            return match (strtoupper((string) ($row['IS_SENT'] ?? $row['is_sent'] ?? ''))) {
                'Y' => self::SENT,
                'N' => self::FAILED,
                'NV' => self::INVALID,
                'DUP' => self::DUPLICATE,
                default => self::UNKNOWN,
            };
        }

        $status = strtoupper((string) ($row['QUEUE_STATUS'] ?? $row['queue_status'] ?? $row['STATUS'] ?? $row['status'] ?? ''));
        $isSent = strtoupper((string) ($row['IS_SENT'] ?? $row['is_sent'] ?? ''));

        return match (true) {
            in_array($status, ['PROCESSING', 'SENDING'], true) => self::SENDING,
            $status === 'PAUSED' => self::PAUSED,
            $status === 'UNKNOWN' => self::UNKNOWN,
            $status === 'DEAD' => self::FAILED,
            $status === 'NEW' => self::PENDING,
            $isSent === 'F' => self::FAILED,
            default => self::PENDING,
        };
    }

    /**
     * التسمية العربية لكل حالة — تُستخدم في التدقيق ورسائل الخطأ
     * Arabic label per status — used by the audit trail and error messages.
     */
    public static function labelAr(string $status): string
    {
        return match ($status) {
            self::PENDING => 'منتظرة',
            self::PAUSED => 'موقوفة مؤقتاً',
            self::SENDING => 'قيد الإرسال',
            self::UNKNOWN => 'تحتاج قراراً',
            self::FAILED => 'فاشلة',
            self::SENT => 'مُرسلة',
            self::INVALID => 'الرقم ليس على واتساب',
            self::DUPLICATE => 'مكررة',
            default => $status,
        };
    }

    /**
     * هل الصف في الطابور قيد المعالجة الآن؟ (لا يُعدَّل ولا يُحذف ولا يُوقَف)
     * Is the queue row being processed right now (no edit/delete/pause allowed)?
     */
    public static function isBusy(array $row): bool
    {
        $status = strtoupper((string) ($row['STATUS'] ?? $row['status'] ?? ''));
        $lock = strtoupper(trim((string) ($row['SEND_LOCK'] ?? $row['send_lock'] ?? 'N')));

        return in_array($status, ['PROCESSING', 'SENDING'], true) || $lock === 'Y';
    }
}
