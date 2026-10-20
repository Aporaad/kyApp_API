<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * مسجّل نشاط مستخدمي التطبيق في جدول APP_USER_ACTIVITY
 * Writes the application user activity/audit trail into APP_USER_ACTIVITY.
 *
 * قاعدة أمان-استمرارية: لا يجوز أن يُفشل تسجيلُ النشاط طلباً ناجحاً. لذلك كل العمليات داخل
 * try/catch صامت يسجّل تحذيره في log() فقط، وتُستخدم استعلامات مربوطة حصراً.
 * Availability rule: logging must never break a successful request. Every write is wrapped in a
 * silent try/catch that only reports to the application log, and bound statements are mandatory.
 */
class ActivityLogger
{
    /**
     * تسجيل حدث واحد
     * Record a single activity event
     *
     * @param array{
     *     user_id?: int|null,
     *     target_user_id?: int|null,
     *     module_code?: string|null,
     *     entity_type?: string|null,
     *     entity_id?: string|int|null,
     *     description_ar?: string|null,
     *     status?: string,
     *     platform?: string|null,
     *     ip?: string|null,
     *     user_agent?: string|null
     * } $context
     */
    public function record(string $actionCode, array $context = [], ?Request $request = null): void
    {
        $status = ($context['status'] ?? 'success') === 'failed' ? 'failed' : 'success';

        try {
            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.APP_USER_ACTIVITY
                    (USER_ID, TARGET_USER_ID, ACTION_CODE, MODULE_CODE, ENTITY_TYPE, ENTITY_ID,
                     DESCRIPTION_AR, STATUS, IP_ADDRESS, USER_AGENT, PLATFORM, CREATED_ON)
                 VALUES
                    (:user_id, :target_user_id, :action_code, :module_code, :entity_type, :entity_id,
                     :description_ar, :status, :ip, :user_agent, :platform, SYSDATE)',
                [
                    'user_id' => $this->nullableInt($context['user_id'] ?? null),
                    'target_user_id' => $this->nullableInt($context['target_user_id'] ?? null),
                    'action_code' => $this->truncate($actionCode, 60),
                    'module_code' => $this->truncate($context['module_code'] ?? null, 30),
                    'entity_type' => $this->truncate($context['entity_type'] ?? null, 40),
                    'entity_id' => $this->truncate(isset($context['entity_id']) ? (string) $context['entity_id'] : null, 60),
                    // العربية تُخزَّن كبايتات CP1256 متوافقة مع النظام القديم وتُصلَح عند القراءة
                    // Arabic lands as CP1256 bytes like the legacy system; repaired on read
                    'description_ar' => $this->truncate(
                        ArabicEncoding::toOracleSearchChars($this->limitArabic($context['description_ar'] ?? null)),
                        1000
                    ),
                    'status' => $status,
                    'ip' => $this->ipOf($context, $request),
                    'user_agent' => $this->truncate($this->userAgentOf($context, $request), 200),
                    'platform' => $this->truncate($context['platform'] ?? null, 20),
                ]
            );
        } catch (Throwable $e) {
            // السجل ثانوي بالنسبة لخدمة المستخدم: نفشله ولا نفشل الطلب
            // The audit trail is secondary: we log the failure, never the user's request
            report($e);
        }
    }

    /**
     * حدث نجاح مختصر
     * Short-hand success event
     *
     * @param  array<string, mixed>  $context
     */
    public function success(string $actionCode, array $context = [], ?Request $request = null): void
    {
        $this->record($actionCode, $context, $request);
    }

    /**
     * حدث فشل (محاولة دخول خاطئة، رفض صلاحية، …)
     * Failure event (bad credentials, denied permission, …)
     *
     * @param  array<string, mixed>  $context
     */
    public function failed(string $actionCode, array $context = [], ?Request $request = null): void
    {
        $this->record($actionCode, array_merge($context, ['status' => 'failed']), $request);
    }

    /**
     * عنوان الطلب مع احترام مسارات الثقة المعروفة فقط
     * Client IP, trusting only the known proxy header when present
     */
    private function ipOf(array $context, ?Request $request): ?string
    {
        $ip = $context['ip'] ?? null;

        if (is_string($ip) && $ip !== '') {
            return $this->truncate($ip, 45);
        }

        return $request ? $this->truncate($request->ip(), 45) : null;
    }

    /**
     * وكالة المستخدم من الطلب أو من السياق
     * User agent from the request or the context
     */
    private function userAgentOf(array $context, ?Request $request): ?string
    {
        $agent = $context['user_agent'] ?? null;

        if (is_string($agent) && $agent !== '') {
            return $agent;
        }

        return $request?->userAgent();
    }

    /**
     * نص عربي داخل سعة العمود (يُحسب بالمحارف لا بالبايتات)
     * Arabic text kept inside the column width (counted in characters, not bytes)
     */
    private function limitArabic(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return mb_substr($text, 0, 480);
    }

    /**
     * قص إلى حد العمود باعتماد البايتات بعد التحويل
     * Truncate to the column byte limit after encoding
     */
    private function truncate(?string $value, int $bytes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) <= $bytes) {
            return $value;
        }

        return substr($value, 0, $bytes);
    }

    /**
     * معرّف صحيح أو null
     * Integer id or null
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
