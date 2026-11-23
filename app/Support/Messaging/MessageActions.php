<?php

namespace App\Support\Messaging;

/**
 * مصفوفة الإجراءات المتاحة لكل حالة رسالة — تُغذّي أيقونات الشاشة وتحقق الخادم معاً
 * The per-status action matrix — feeds both the screen's icons and the server-side guards.
 *
 * الشاشة لا تخمّن: الخادم يُرسل `actions` لكل صف محسوبة من هذه المصفوفة **مضروبة في صلاحيات
 * المستخدم**، والأيقونة التي لا تظهر في المصفوفة لا تُرسم، والمسار الذي لا يملك كوده يُعيد 403.
 * The client never guesses: the server returns an `actions` list per row, computed here and
 * intersected with the caller's grants. An action absent from the matrix is never rendered, and its
 * route answers 403 without the matching code.
 */
final class MessageActions
{
    public const VIEW = 'view';

    public const EDIT = 'edit';

    public const DELETE = 'delete';

    public const PAUSE = 'pause';

    public const RESUME = 'resume';

    public const SEND_NOW = 'send_now';

    public const RESEND = 'resend';

    /**
     * حالات الطابور → إجراءاتها
     * Queue statuses → their actions.
     *
     * @var array<string, list<string>>
     */
    private const QUEUE = [
        MessageStatus::PENDING => [self::VIEW, self::EDIT, self::DELETE, self::PAUSE, self::SEND_NOW, self::RESEND],
        MessageStatus::PAUSED => [self::VIEW, self::EDIT, self::DELETE, self::RESUME, self::SEND_NOW, self::RESEND],
        MessageStatus::SENDING => [self::VIEW],
        MessageStatus::FAILED => [self::VIEW, self::EDIT, self::DELETE, self::SEND_NOW, self::RESEND],
        MessageStatus::UNKNOWN => [self::VIEW, self::EDIT, self::DELETE, self::RESEND],
    ];

    /**
     * حالات الأرشيف → إجراءاتها
     * Archive statuses → their actions.
     *
     * @var array<string, list<string>>
     */
    private const ARCHIVE = [
        MessageStatus::SENT => [self::VIEW, self::DELETE],
        MessageStatus::FAILED => [self::VIEW, self::EDIT, self::DELETE, self::RESEND],
        MessageStatus::INVALID => [self::VIEW, self::DELETE, self::RESEND],
        MessageStatus::DUPLICATE => [self::VIEW, self::DELETE, self::RESEND],
        MessageStatus::UNKNOWN => [self::VIEW, self::DELETE],
    ];

    /**
     * العملية → كود الصلاحية داخل الوحدة
     * Operation → permission code inside the module.
     */
    private const PERMISSION_ACTION = [
        self::VIEW => 'view',
        self::EDIT => 'update',
        self::DELETE => 'delete',
        self::PAUSE => 'toggle',
        self::RESUME => 'toggle',
        self::SEND_NOW => 'toggle',
        self::RESEND => 'toggle',
    ];

    /**
     * الإجراءات المسموحة لصف واحد
     * The allowed actions for one row.
     *
     * @param  callable(string): bool  $can  دالة الإذن (تُغلَّف على PermissionResolver)
     * @return list<string>
     */
    public static function forRow(MessageStatus|string $status, string $source, string $module, callable $can, bool $supportsWorkerActions = true): array
    {
        $status = $status instanceof MessageStatus ? $status->value : $status;
        $table = strtolower($source) === 'archive' ? self::ARCHIVE : self::QUEUE;

        $allowed = $table[$status] ?? [self::VIEW];

        $result = [];
        foreach ($allowed as $action) {
            // قناة بلا حالة تشغيلية (SMS) لا تعرف الإيقاف/الاستئناف/الإرسال الفوري
            // A channel with no worker state (SMS) has no pause/resume/send-now.
            if (! $supportsWorkerActions && in_array($action, [self::PAUSE, self::RESUME, self::SEND_NOW], true)) {
                continue;
            }
            $suffix = self::PERMISSION_ACTION[$action] ?? 'view';
            if ($can($module.'.'.$suffix)) {
                $result[] = $action;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * هل العملية مسموحة لهذه الحالة/المصدر؟ (حارس المسار قبل أي كتابة)
     * Is the operation allowed for this status/source? (The route guard before any write.)
     */
    public static function isAllowed(string $action, string $status, string $source, bool $supportsWorkerActions = true): bool
    {
        if (! $supportsWorkerActions && in_array($action, [self::PAUSE, self::RESUME, self::SEND_NOW], true)) {
            return false;
        }

        $table = strtolower($source) === 'archive' ? self::ARCHIVE : self::QUEUE;

        return in_array($action, $table[$status] ?? [], true);
    }
}
