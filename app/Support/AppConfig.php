<?php

namespace App\Support;

/**
 * تعريف تهيئة التطبيق — المصدر الوحيد لمفاتيح الهوية/المظهر وقيمها الافتراضية وحمولتها العامة
 * Application configuration definition — the single source of truth for the branding/theming
 * setting keys, their defaults, and the shape of the public payload served to the Flutter client.
 *
 * تُخزَّن القيم كلها في `APP_SETTINGS` القائمة (لا جدول جديد)، وهنا نُعرِّف أيّها يُكتب من
 * الواجهة، وأيّها يُقرأ عاماً، وأي صيغة تُقبل (ألوان `#RRGGBB` وخطوط ضمن قائمة مسموحة).
 * All values live in the existing `APP_SETTINGS` table (no new table); this class decides which
 * keys are writable from the UI, which are served publicly, and what formats are accepted.
 *
 * ⚠️ مفتاح `api_settings_password` حساس: لا يظهر في الحمولة العامة أبداً، ولا يُكتب إلا من
 * نقطة نهاية مخصّصة خلف `permission:app_config.api_manage`.
 * The `api_settings_password` key is sensitive: it never appears in the public payload and is only
 * written through a dedicated endpoint behind `permission:app_config.api_manage`.
 */
final class AppConfig
{
    /**
     * مفاتيح معلومات الشركة واسم التطبيق
     * Company information and application-name keys
     */
    public const COMPANY_NAME = 'company_name';

    public const COMPANY_ADDRESS = 'company_address';

    public const COMPANY_WEBSITE = 'company_website';

    public const COMPANY_PHONE = 'company_phone';

    public const APP_NAME = 'app_name';

    /**
     * مفاتيح المظهر (خمسة ألوان + الخط)
     * Theming keys (five colors plus the font family)
     */
    public const THEME_PRIMARY_COLOR = 'theme_primary_color';

    public const THEME_SECONDARY_COLOR = 'theme_secondary_color';

    public const THEME_BACKGROUND_COLOR = 'theme_background_color';

    public const THEME_TEXT_PRIMARY_COLOR = 'theme_text_primary_color';

    public const THEME_TEXT_SECONDARY_COLOR = 'theme_text_secondary_color';

    public const THEME_FONT_FAMILY = 'theme_font_family';

    /**
     * مفتاح كلمة سر إعدادات اتصال API (مقفل ومجزّأ — لا يُكتب من مسار التهيئة العام)
     * The API-connection settings password key (locked and hashed — never written via the general path)
     */
    public const API_SETTINGS_PASSWORD = 'api_settings_password';

    /**
     * القيم الافتراضية: تطابق ألوان `AppColors` القائمة واسم الشركة الحالي في العميل
     * Defaults, matching the client's existing `AppColors` palette and current company name.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        self::COMPANY_NAME => 'الكبودي للخياطة الرجالية',
        self::COMPANY_ADDRESS => '',
        self::COMPANY_WEBSITE => '',
        self::COMPANY_PHONE => '',
        self::APP_NAME => 'الكبودي للخياطة الرجاليه',
        self::THEME_PRIMARY_COLOR => '#A27E4E',
        self::THEME_SECONDARY_COLOR => '#0D9488',
        self::THEME_BACKGROUND_COLOR => '#F8FAFC',
        self::THEME_TEXT_PRIMARY_COLOR => '#0F172A',
        self::THEME_TEXT_SECONDARY_COLOR => '#475569',
        self::THEME_FONT_FAMILY => 'Cairo',
    ];

    /**
     * الخطوط المسموحة (عربية عبر google_fonts) — أي خط خارجها مرفوض
     * Allowed font families (Arabic-capable, via google_fonts); anything else is rejected.
     *
     * @var list<string>
     */
    public const ALLOWED_FONTS = ['Cairo', 'Tajawal', 'Almarai', 'Amiri', 'Changa'];

    /**
     * المفاتيح القابلة للكتابة من شاشة التهيئة (تستثني كلمة سر API المقفلة)
     * Keys writable from the branding screen (excludes the locked API password).
     *
     * @var list<string>
     */
    public const WRITABLE_KEYS = [
        self::COMPANY_NAME,
        self::COMPANY_ADDRESS,
        self::COMPANY_WEBSITE,
        self::COMPANY_PHONE,
        self::APP_NAME,
        self::THEME_PRIMARY_COLOR,
        self::THEME_SECONDARY_COLOR,
        self::THEME_BACKGROUND_COLOR,
        self::THEME_TEXT_PRIMARY_COLOR,
        self::THEME_TEXT_SECONDARY_COLOR,
        self::THEME_FONT_FAMILY,
    ];

    /**
     * المفاتيح التي تحمل لوناً بصيغة `#RRGGBB`
     * The keys that must hold a `#RRGGBB` color.
     *
     * @var list<string>
     */
    public const COLOR_KEYS = [
        self::THEME_PRIMARY_COLOR,
        self::THEME_SECONDARY_COLOR,
        self::THEME_BACKGROUND_COLOR,
        self::THEME_TEXT_PRIMARY_COLOR,
        self::THEME_TEXT_SECONDARY_COLOR,
    ];

    /**
     * الحمولة العامة الآمنة: تُقدَّم للعميل عند الإقلاع ولشاشة الدخول بلا مصادقة
     * The safe public payload: served to the client at startup and to the login screen unauthenticated.
     *
     * لا تحتوي كلمة سر API ولا أي إعداد أمان؛ القيم الشاغرة تُعوَّض بالافتراضي حتى يرسم العميل
     * هوية صحيحة حتى قبل أول حفظ.
     * It carries no API password and no security setting; blank values fall back to the defaults so
     * the client can render a correct identity even before the first save.
     *
     * @return array<string, string>
     */
    public static function publicPayload(SettingsStore $settings): array
    {
        $payload = [];

        foreach (self::WRITABLE_KEYS as $key) {
            $value = $settings->get($key, self::DEFAULTS[$key] ?? '');
            $value = is_string($value) ? trim($value) : (string) $value;

            $payload[$key] = $value !== '' ? $value : (self::DEFAULTS[$key] ?? '');
        }

        return $payload;
    }

    /**
     * كل مفاتيح التهيئة بما فيها كلمة سر API — تُستخدم لعزلها عن شاشة «إعدادات الأمان»
     * Every app-config key including the API password — used to keep them off the security screen.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_merge(self::WRITABLE_KEYS, [self::API_SETTINGS_PASSWORD]);
    }

    /**
     * هل النص لون صالح بصيغة `#RRGGBB`؟
     * Is this a valid `#RRGGBB` color string?
     */
    public static function isValidColor(string $value): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    /**
     * هل الخط ضمن القائمة المسموحة؟
     * Is the font family in the allow-list?
     */
    public static function isValidFont(string $value): bool
    {
        return in_array($value, self::ALLOWED_FONTS, true);
    }
}
