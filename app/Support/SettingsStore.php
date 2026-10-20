<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * مخزن إعدادات الأمان والجلسات — القراءة من APP_SETTINGS مع نسخة كاش قصيرة
 * Security/session settings store backed by APP_SETTINGS with a short-lived cache.
 *
 * لماذا الكاش؟ الإعدادات تُقرأ في كل طلب مصادق عليه (سياسة الجلسة) وفي كل تحقق من البصمة،
 * وكتابة الإعدادات نادرة جداً (من شاشة «إعدادات الأمان» فقط). المدة 300 ثانية تعني أن أي تعديل
 * يظهر خلال خمس دقائق على الأكثر، أو فوراً عند الحفظ لأن `set()` يُسقط النسخة.
 * Why cached: settings are read on every authenticated request (session policy) yet change rarely.
 * The 300 s TTL bounds staleness, and saving a setting drops the cache immediately.
 *
 * ⚠️ حدود أوراكل المفروضة / the Oracle constraint that shapes this class:
 * جدول `CACHE` يملك `VALUE CLOB`، لكن Laravel يكتب عبر `MERGE ... USING (SELECT :value FROM dual)`،
 * وأوراكل ترفض ربط نص أطول من 4000 بايت في هذا السياق (`ORA-01461`). لذلك تُخزَّن في الكاش
 * الحقول الرقمية فقط (بلا أسماء ولا أوصاف عربية)، أما `definitions()` — التي تحتاج التسميات
 * العربية لشاشة الإعدادات — فتقرأ من القاعدة مباشرة لأنها نادرة والاستعلام رخيص.
 * The database cache store writes through a MERGE-select-from-dual, and Oracle rejects a bound
 * value longer than 4000 bytes there (ORA-01461). So the cache holds the numeric fields only,
 * while `definitions()` — which needs the Arabic labels — reads Oracle directly.
 */
class SettingsStore
{
    /**
     * مدة نسخة الكاش (ثوانٍ)
     * Cache lifetime in seconds
     */
    private const CACHE_TTL = 300;

    /**
     * مفتاح الكاش الوحيد لكل الإعدادات
     * Single cache key for the whole settings map
     */
    private const CACHE_KEY = 'ky_ibac_settings';

    /**
     * أقصى حجم آمن للقيمة المخزّنة في الكاش (بايت) — انظر `ORA-01461` أعلى الملف
     * Safe upper bound for a cached payload, in bytes (see the ORA-01461 note above)
     */
    private const MAX_CACHE_BYTES = 3000;

    /**
     * الحقول التي يحتاجها المنطق السريع فقط — بلا نصوص عربية
     * The fields fast paths need — no Arabic text, so the cache stays small
     */
    private const CACHED_FIELDS = [
        'setting_id',
        'key',
        'value',
        'value_type',
        'min_value',
        'max_value',
        'lockable',
        'sort_order',
    ];

    /**
     * المفاتيح «الساخنة» التي يقرؤها مسار الطلبات (سياسة الجلسة وتفعيل البصمة)
     * The "hot" keys read on the request path (session policy + the biometric kill-switch).
     *
     * لماذا قائمة صريحة؟ تهيئة التطبيق (`company_*` و`theme_*`) أُضيفت إلى الجدول نفسه، ونصوصها
     * (كالعنوان) قد تدفع حمولة الكاش فوق سقف `ORA-01461`. بحصر الكاش في المفاتيح الساخنة تبقى
     * نسخة سياسة الجلسة صغيرة ومستقرة مهما زاد نص العلامة التجارية، وتُقرأ بقية المفاتيح من
     * القاعدة مباشرة في المسارات النادرة (الإقلاع/الإدارة).
     * Why an explicit list: app-config keys (company_* and theme_*) share the same table, and their
     * (an address, say) can push the cached blob past the ORA-01461 bound. Caching only the hot keys
     * keeps the session policy's copy small and stable no matter how much branding text is added;
     * the rest are read straight from Oracle on rare paths (startup/admin).
     *
     * @var list<string>
     */
    private const HOT_KEYS = [
        'session_idle_minutes',
        'session_absolute_days',
        'session_touch_threshold_minutes',
        'single_active_session_per_device',
        'password_min_length',
        'max_failed_logins',
        'lockout_minutes',
        'biometric_login_enabled',
        'activity_log_retention_days',
        'maintenance_message',
    ];

    /**
     * الاستعلام المرجعي لكل الإعدادات بترتيب العرض
     * Canonical query for all settings in display order
     */
    private const ALL_SQL = <<<'SQL'
        SELECT SETTING_ID, SETTING_KEY, SETTING_VALUE, VALUE_TYPE, NAME_AR, DESCRIPTION_AR,
               MIN_VALUE, MAX_VALUE, IS_LOCKABLE, SORT_ORDER, UPDATED_BY, UPDATED_ON
          FROM ACCOUNTS.APP_SETTINGS
         ORDER BY SORT_ORDER, SETTING_ID
        SQL;

    /**
     * الإعدادات السريعة مفهرسة بمفتاحها (نسخة مصغّرة قابلة للتخزين في الكاش)
     * The slim settings map keyed by setting key, small enough to cache
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $slim = [];

        foreach ($this->fetch() as $key => $setting) {
            $slim[$key] = $this->slim($setting);
        }

        return $slim;
    }

    /**
     * الإعدادات الساخنة فقط (المقروءة في مسار الطلبات) مع نسخة كاش قصيرة
     * Only the hot settings (read on the request path), with a short-lived cache.
     *
     * حجم هذه الحمولة محصور بعدد المفاتيح الساخنة وقيمها الرقمية/القصيرة، فلا يقترب من سقف
     * `ORA-01461` مهما أُضيفت إعدادات تهيئة طويلة.
     * This payload is bounded by the hot keys and their short/numeric values, so it never approaches
     * the ORA-01461 ceiling however many long branding settings are added.
     *
     * @return array<string, array<string, mixed>>
     */
    public function hot(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $slim = [];

        foreach ($this->fetch() as $key => $setting) {
            if (! in_array($key, self::HOT_KEYS, true)) {
                continue;
            }

            $slim[$key] = $this->slim($setting);
        }

        if (strlen(serialize($slim)) <= self::MAX_CACHE_BYTES) {
            Cache::put(self::CACHE_KEY, $slim, self::CACHE_TTL);
        }

        return $slim;
    }

    /**
     * نسخة مصغّرة لصف إعداد تقتصر على حقول الكاش
     * Slim a setting row down to the cached fields
     *
     * @param  array<string, mixed>  $setting
     * @return array<string, mixed>
     */
    private function slim(array $setting): array
    {
        return array_intersect_key($setting, array_flip(self::CACHED_FIELDS));
    }

    /**
     * قراءة صف إعداد واحد غير ساخن مباشرة من القاعدة (بلا كاش)
     * Read a single non-hot setting straight from Oracle (uncached)
     *
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $key): ?array
    {
        $rows = DB::connection('oracle')->select(
            'SELECT SETTING_ID, SETTING_KEY, SETTING_VALUE, VALUE_TYPE, MIN_VALUE, MAX_VALUE,
                    IS_LOCKABLE, SORT_ORDER
               FROM ACCOUNTS.APP_SETTINGS
              WHERE SETTING_KEY = :setting_key',
            ['setting_key' => $key]
        );

        if ($rows === []) {
            return null;
        }

        $row = $this->lowercaseKeys((array) $rows[0]);

        return $this->slim([
            'setting_id' => (int) ($row['setting_id'] ?? 0),
            'key' => (string) ($row['setting_key'] ?? $key),
            'value' => $row['setting_value'] ?? '',
            'value_type' => (string) ($row['value_type'] ?? 'string'),
            'min_value' => isset($row['min_value']) ? (int) $row['min_value'] : null,
            'max_value' => isset($row['max_value']) ? (int) $row['max_value'] : null,
            'lockable' => (int) ($row['is_lockable'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ]);
    }

    /**
     * قيمة الإعداد بعد تحويله إلى نوعه المعلن (int|bool|string|json)
     * The setting value cast to its declared type (int|bool|string|json)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // الساخنة من الكاش، وغيرها قراءة مباشرة نادرة (الإقلاع/الإدارة)
        // Hot keys come from the cache; the rest are a rare direct read (startup/admin).
        $setting = $this->hot()[$key] ?? $this->fetchOne($key);

        if ($setting === null) {
            return $default;
        }

        return $this->cast($setting['value_type'], $setting['value'], $default);
    }

    /**
     * قيمة رقمية مع ضمان الحد الأدنى
     * Numeric value with a guaranteed fallback
     */
    public function int(string $key, int $default): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * قيمة منطقية
     * Boolean value
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes'], true);
    }

    /**
     * القيمة الخام لإعداد كما هي مخزّنة (بلا تحويل نوع ولا إصلاح عربية)
     * The raw stored value of a setting — no type cast, no mojibake repair.
     *
     * تُستخدم لقراءة تجزئة كلمة سر إعدادات API: تجزئة bcrypt نص ASCII صرف يجب أن يُقرأ حرفياً،
     * وتمريره عبر `fixMojibake` خطر لا داعي له لأنه قد يمسّ تسلسلاً يبدو كعربية مشوّهة.
     * Used for the API-settings password hash: a bcrypt digest is pure ASCII and must be read
     * verbatim, so running fixMojibake over it is an unnecessary risk.
     */
    public function raw(string $key, ?string $default = null): ?string
    {
        // الساخنة من الكاش، وغيرها قراءة مباشرة نادرة — نفس مسار `get()` بلا التحويل النهائي
        // Hot keys from the cache, the rest a rare direct read — same path as get(), minus the cast.
        $setting = $this->hot()[$key] ?? $this->fetchOne($key);

        if ($setting === null) {
            return $default;
        }

        $value = $setting['value'] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * قائمة الإعدادات كاملة مع التسميات العربية، جاهزة لشاشة «إعدادات الأمان»
     * Full settings list with Arabic labels, ready for the security-settings screen.
     *
     * تُقرأ من القاعدة مباشرة (بلا كاش) لأنها تحمل نصاً عربياً يتجاوز حد أوراكل، ولأنها تُستهلك
     * في شاشة إدارية واحدة وليست في مسار الطلبات السريع.
     * Read straight from Oracle: these rows carry Arabic text too big for the cache column, and
     * they serve one admin screen rather than the hot request path.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values($this->fetch());
    }

    /**
     * حفظ قيمة إعداد مع التحقق من نوعه وحدوده وإسقاط الكاش
     * Persist a setting after validating its type and bounds, then drop the cache.
     *
     * @throws InvalidArgumentException عند مفتاح غير معروف أو قيمة خارج الحدود أو إعداد مقفل
     *                                  Thrown for unknown keys, out-of-range values, or locked settings.
     */
    public function set(string $key, mixed $value, ?int $updatedBy = null, bool $ignoreLock = false): array
    {
        $setting = $this->all()[$key] ?? null;

        if ($setting === null) {
            throw new InvalidArgumentException("الإعداد غير معروف / Unknown setting: {$key}");
        }

        if ((int) $setting['lockable'] === 1 && ! $ignoreLock) {
            throw new InvalidArgumentException('هذا الإعداد مقفل ولا يعدَّل من الواجهة / Setting is locked');
        }

        $normalized = $this->normalize($setting, $value);

        // اسم المُربَط لا يكون كلمة محجوزة في أوراكل: `:by` كان ينتج ORA-01745 في كل محاولة حفظ
        // A bind must not be an Oracle reserved word: :by used to fail every save with ORA-01745.
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.APP_SETTINGS
                SET SETTING_VALUE = :setting_value, UPDATED_BY = :updated_by, UPDATED_ON = SYSDATE
              WHERE SETTING_KEY = :setting_key',
            [
                'setting_value' => $normalized,
                'updated_by' => $updatedBy,
                'setting_key' => $key,
            ]
        );

        Cache::forget(self::CACHE_KEY);

        return $this->all()[$key] ?? array_merge($setting, ['value' => $normalized]);
    }

    /**
     * إسقاط نسخة الكاش يدوياً
     * Drop the cached copy on demand.
     *
     * `set()` يُسقطها بعد كل حفظ، لكن المتحكم يحتاج إسقاطاً بعد تراجع المعاملة: الكاش تغيّر قبل
     * أن ترجع أوراكل، فبقاؤه تعني أن سياسة الجلسة تقرأ قيماً لم تُكتب فعلياً.
     * `set()` already drops it, but a controller needs an explicit drop after a transaction
     * rollback: the cache moved while Oracle did not, and the stale copy would feed the session
     * policy values that were never actually written.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * تحويل القيمة الخام إلى النوع المعلن والتحقق من الحدود
     * Cast the raw value to its declared type and enforce bounds
     *
     * @param  array<string, mixed>  $setting
     */
    private function normalize(array $setting, mixed $value): string
    {
        $type = (string) ($setting['value_type'] ?? 'string');
        $label = (string) ($setting['name_ar'] ?? $setting['key'] ?? '');

        if ($type === 'bool') {
            // قائمة صريحة بدل `filter_var`: المسموح معروَض في الواجهة بـ true/false أو "1"/"0"،
            // وأي نص آخر يجب أن يُرفض لا أن يُفهم خطأً كـ false بصمت.
            // An explicit allow-list, not filter_var: the screen sends true/false or "1"/"0", and
            // anything else must be rejected rather than silently coerced to false.
            $token = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => strtolower(trim($value)),
                default => null,
            };

            if (in_array($token, ['1', 'true', 'yes', 'on'], true)) {
                return '1';
            }

            if (in_array($token, ['0', 'false', 'no', 'off', ''], true)) {
                return '0';
            }

            throw new InvalidArgumentException("قيمة منطقية غير صالحة / Invalid boolean for {$label}");
        }

        if ($type === 'int') {
            if (! is_numeric($value) || (int) $value != (float) $value) {
                throw new InvalidArgumentException("قيمة رقمية صحيحة مطلوبة / Integer value required for {$label}");
            }

            $number = (int) $value;
            $min = isset($setting['min_value']) ? (int) $setting['min_value'] : null;
            $max = isset($setting['max_value']) ? (int) $setting['max_value'] : null;

            if ($min !== null && $number < $min) {
                throw new InvalidArgumentException("القيمة أقل من الحد الأدنى ($min) / Below minimum for {$label}");
            }

            if ($max !== null && $number > $max) {
                throw new InvalidArgumentException("القيمة أكبر من الحد الأقصى ($max) / Above maximum for {$label}");
            }

            return (string) $number;
        }

        if ($type === 'json') {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                throw new InvalidArgumentException("قيمة JSON غير صالحة / Invalid JSON for {$label}");
            }

            return $this->limitToColumn($encoded, $label);
        }

        // قيمة نصية قد تكون عربية (رسالة الصيانة): مسافة البحث عن الكتابة نفسها — محارف CP1256
        // في ثوب لاتيني، لا بايتات خام تُستبدل بـ 0xBF على اتصال AL32UTF8.
        // A string value may be Arabic (the maintenance message): the write path is the same as the
        // search path — CP1256 bytes dressed as Latin-1 chars, never raw bytes that 0xBF on AL32UTF8.
        return $this->limitToColumn(ArabicEncoding::toOracleSearchChars((string) $value), $label);
    }

    /**
     * قص القيم النصية إلى سعة العمود بدل فشل الإدراج بصوت عالٍ
     * Trim text values to the column width instead of failing loudly
     *
     * السعة تُقاس بالمحارف لا بالبايتات: العمود 500 بايت في WE8MSWIN1252، والعربية بعد
     * `toOracleSearchChars()` محرف لاتيني واحد لكل بايت منها (UTF-8 بايتان في PHP).
     * Capacity is counted in characters, not bytes: the column holds 500 bytes of WE8MSWIN1252, and
     * Arabic after `toOracleSearchChars()` is one Latin-1 char per stored byte (two PHP UTF-8 bytes).
     */
    private function limitToColumn(string $value, string $label): string
    {
        if (mb_strlen($value, 'UTF-8') > 500) {
            throw new InvalidArgumentException("النص أطول من 500 محرف / Too long for {$label}");
        }

        return $value;
    }

    /**
     * قراءة الصفوف وتحويل المفاتيح إلى snake_case وإصلاح العربية
     * Read rows, normalise keys to snake_case, and repair Arabic text
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetch(): array
    {
        $rows = DB::connection('oracle')->select(self::ALL_SQL);

        $settings = [];

        foreach ($rows as $raw) {
            $row = $this->lowercaseKeys((array) $raw);

            $key = (string) ($row['setting_key'] ?? '');

            if ($key === '') {
                continue;
            }

            $settings[$key] = [
                'setting_id' => (int) ($row['setting_id'] ?? 0),
                'key' => $key,
                'value' => $row['setting_value'] ?? '',
                'value_type' => (string) ($row['value_type'] ?? 'string'),
                'name_ar' => ArabicEncoding::fixMojibake((string) ($row['name_ar'] ?? '')),
                'description_ar' => ArabicEncoding::fixMojibake((string) ($row['description_ar'] ?? '')),
                'min_value' => isset($row['min_value']) ? (int) $row['min_value'] : null,
                'max_value' => isset($row['max_value']) ? (int) $row['max_value'] : null,
                'lockable' => (int) ($row['is_lockable'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
                'updated_on' => $row['updated_on'] ?? null,
            ];
        }

        return $settings;
    }

    /**
     * توحيد أسماء المفاتيح القادمة من أوراكل (قد تكون UPPER أو lower)
     * Normalise Oracle column keys (may arrive as UPPER or lower case)
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function lowercaseKeys(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[strtolower((string) $key)] = $value;
        }

        return $out;
    }

    /**
     * تحويل نص القاعدة إلى نوعه المعلن
     * Convert the stored string into its declared type
     */
    private function cast(string $type, mixed $value, mixed $default): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : $default,
            'bool' => in_array((string) $value, ['1', 'true', 'yes', 'on'], true),
            'json' => is_string($value) && $value !== '' ? (json_decode($value, true) ?? $default) : $default,
            default => is_string($value) ? ArabicEncoding::fixMojibake($value) : $value,
        };
    }
}
