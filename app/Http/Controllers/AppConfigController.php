<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\AppConfig;
use App\Support\PermissionResolver;
use App\Support\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/**
 * متحكم تهيئة التطبيق — هوية الشركة، المظهر، وكلمة سر إعدادات اتصال API
 * Application-configuration controller — company identity, theming, and the API-settings password.
 *
 * القيم كلها صفوف في `APP_SETTINGS` القائمة (لا جدول جديد)، وتفصل هذه الطبقة ثلاثة مستويات من
 * الوصول: حمولة عامة بلا سر (`GET /app-config`) تُرسم منها شاشة الدخول قبل المصادقة، وكتابة
 * للعلامة التجارية خلف `permission:app_config.update`، وكلمة سر API المقفلة خلف نقطتَيها الخاصتين.
 * Every value is an APP_SETTINGS row (no new table). This layer separates three access levels: a
 * secret-free public payload (`GET /app-config`) that paints the login screen before auth, branding
 * writes behind `permission:app_config.update`, and the locked API password behind its own two routes.
 *
 * لماذا نقطة تحقق عامة؟ شاشة الدخول تحتاج فتح «إعدادات اتصال API» بكلمة سر ثابتة حتى حين يكون
 * الرابط الحالي خاطئاً — أي قبل أن يملك العميل توكن — لذلك التحقق عام ومقيّد المعدل، ويُعيد
 * `success` داخل جسم 200 (لا 401/422) ليفرّق العميل بين «كلمة سر خاطئة» و«الخادم غير متاح».
 * Why a public verify route: the login screen must unlock the API-settings with a static password
 * even when the current URL is wrong — i.e. before the client holds a token. So verification is
 * public and rate-limited, and answers `success` inside a 200 body (not 401/422) so the client can
 * tell "wrong password" apart from "server unreachable" and fall back to its local hash.
 */
class AppConfigController extends Controller
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly PermissionResolver $permissions,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * الحمولة العامة للتهيئة — بلا مصادقة وبلا أي سر
     * The public configuration payload — unauthenticated and carrying no secret.
     *
     * تُقرأ عند إقلاع العميل ولرسم شاشة الدخول؛ تستثني `api_settings_password` نهائياً، والقيم
     * الشاغرة تُعوَّض بالافتراضي حتى تظهر هوية صحيحة قبل أول حفظ.
     * Read at client startup and to paint the login screen; it never exposes the API password, and
     * blank values fall back to the defaults so a correct identity shows before the first save.
     */
    public function publicConfig(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'تم جلب تهيئة التطبيق / Application configuration retrieved.',
                'data' => AppConfig::publicPayload($this->settings),
                'meta' => ['fonts' => AppConfig::ALLOWED_FONTS],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب تهيئة التطبيق', $e);
        }
    }

    /**
     * حفظ معلومات الشركة والمظهر (خلف `permission:app_config.update`)
     * Save the company information and theming (behind `permission:app_config.update`).
     *
     * الجسم خريطة مسطّحة لأي مجموعة من المفاتيح القابلة للكتابة. تُرفض كلمة سر API هنا صراحةً لأن
     * لها مسارها المخصّص، وتُتحقق الألوان بصيغة `#RRGGBB` والخط ضمن القائمة المسموحة. كل الكتابات
     * في معاملة واحدة: فشل مفتاح يُلغي الكل فلا تظهر هوية نصف مُطبَّقة.
     * The body is a flat map of any subset of writable keys. The API password is refused here
     * explicitly (it has its own route); colors are checked as `#RRGGBB` and the font against the
     * allow-list. One transaction: a single bad key rolls everything back, so no half-applied identity.
     */
    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->all();

        // المفتاح المقفل لا يُلمس من المسار العام: له نقطة نهاية مخصّصة بصلاحية أرفع
        // The locked key is untouchable on the general path; it has a dedicated, higher-grant route.
        if (array_key_exists(AppConfig::API_SETTINGS_PASSWORD, $payload)) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة سر إعدادات API تُغيَّر من مسارها المخصّص فقط / The API password has its own endpoint.',
                'errors' => [AppConfig::API_SETTINGS_PASSWORD => ['locked']],
            ], 403);
        }

        [$rules, $messages, $present] = $this->rulesFor($payload);

        if ($present === []) {
            return response()->json([
                'success' => false,
                'message' => 'لم يُرسل أي مفتاح تهيئة صالح / No valid configuration key submitted.',
                'errors' => ['config' => ['empty']],
            ], 422);
        }

        $validator = Validator::make($payload, $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'قيم تهيئة غير مقبولة / Invalid configuration values',
                'errors' => $validator->errors(),
            ], 422);
        }

        $before = $this->currentValues($present);
        $names = $this->labels();
        $changes = [];

        try {
            DB::connection('oracle')->transaction(function () use ($present, $payload, $actor, $before, &$changes) {
                foreach ($present as $key) {
                    $value = $payload[$key];

                    // `null` تعني «لم يُرسل هذا الحقل» فلا تلمسه؛ النص الفارغ قصد صريح بالمسح
                    // null means "not submitted", so skip; an empty string is a deliberate clear.
                    if ($value === null) {
                        continue;
                    }

                    $saved = $this->settings->set($key, $value, $actor ? $this->idOf($actor) : null);

                    if ((string) ($before[$key] ?? '') !== (string) $saved['value']) {
                        $changes[] = [
                            'key' => $key,
                            'from' => (string) ($before[$key] ?? ''),
                            'to' => (string) $saved['value'],
                        ];
                    }
                }
            });

            $this->settings->flush();

            $this->activity->success('app_config.update', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'module_code' => 'app_config',
                'entity_type' => 'APP_SETTINGS',
                'entity_id' => $changes === [] ? null : implode(',', array_column($changes, 'key')),
                'description_ar' => $this->describe($changes, $names),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => $changes === []
                    ? 'لا تغيير في تهيئة التطبيق / No configuration changed.'
                    : 'تم حفظ تهيئة التطبيق / Application configuration saved.',
                'data' => AppConfig::publicPayload($this->settings),
                'meta' => ['changed' => count($changes)],
            ]);
        } catch (InvalidArgumentException $e) {
            // القيمة رُفضت قبل أن تُكتب (طول أو صيغة): 422 مفصّل ولا شيء كُتب
            // Rejected by length/format before landing: a detailed 422, nothing written.
            $this->settings->flush();

            return response()->json([
                'success' => false,
                'message' => 'قيمة تهيئة غير مقبولة / Invalid configuration value',
                'errors' => ['config' => [$e->getMessage()]],
            ], 422);
        } catch (Throwable $e) {
            $this->settings->flush();

            return $this->errorResponse('خطأ في حفظ تهيئة التطبيق', $e);
        }
    }

    /**
     * التحقق من كلمة سر إعدادات API — عام ومقيّد المعدل (`throttle` على المسار)
     * Verify the API-settings password — public and rate-limited (`throttle` on the route).
     *
     * يُعيد 200 دائماً مع `success` في الجسم (صحيحة/خاطئة) بدل 401/422، حتى يميّز العميل بين
     * رفض كلمة السر وتعذّر الوصول للخادم فيلجأ إلى تجزئته المحلية. تُسجَّل المحاولات الفاشلة
     * للتدقيق بلا كشف أي سبب في الرد.
     * Always answers 200 with `success` in the body (right/wrong) instead of 401/422, so the client
     * can distinguish a rejected password from an unreachable server and fall back to its local hash.
     * Failed attempts are audited without leaking any reason in the response.
     */
    public function verifyApiPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'max:200'],
        ], [
            'password.required' => 'كلمة السر مطلوبة / The password is required.',
            'password.max' => 'كلمة السر أطول من المسموح / The password is too long.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة / Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $password = (string) $request->input('password');
        $hash = $this->settings->raw(AppConfig::API_SETTINGS_PASSWORD);

        $verified = is_string($hash) && $hash !== '' && Hash::check($password, $hash);

        if (! $verified) {
            $this->activity->failed('app_config.api_verify', [
                'module_code' => 'app_config',
                'entity_type' => 'APP_SETTINGS',
                'entity_id' => AppConfig::API_SETTINGS_PASSWORD,
                'description_ar' => 'محاولة تحقق فاشلة من كلمة سر إعدادات اتصال API',
            ], $request);
        }

        return response()->json([
            'success' => $verified,
            'message' => $verified
                ? 'كلمة السر صحيحة / Password accepted.'
                : 'كلمة السر غير صحيحة / Password rejected.',
            'data' => ['verified' => $verified],
        ]);
    }

    /**
     * تغيير كلمة سر إعدادات API (خلف `permission:app_config.api_manage`)
     * Change the API-settings password (behind `permission:app_config.api_manage`).
     *
     * تُجزّأ bcrypt وتُكتب بتجاوز القفل (`ignoreLock`) لأن هذا هو المسار المصرَّح الوحيد للمفتاح
     * المقفل. لا نُعيد التجزئة في الرد: العميل يحسب تجزئته المحلية (SHA-256+salt) من النص الذي
     * أدخله للتو، فعدم شحن تجزئة bcrypt إلى الجهاز يقلّص سطح الهجوم دون أن يفقد العميل احتياطه.
     * The value is bcrypt-hashed and written past the lock (`ignoreLock`) because this is the only
     * route authorized for the locked key. The hash is not returned: the client derives its own local
     * SHA-256 fallback from the plaintext it just typed, so not shipping a bcrypt digest to the device
     * shrinks the attack surface without costing the client its offline copy.
     */
    public function updateApiPassword(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:4', 'max:200'],
        ], [
            'password.required' => 'كلمة السر الجديدة مطلوبة / The new password is required.',
            'password.min' => 'كلمة السر أقصر من 4 محارف / The password is shorter than 4 characters.',
            'password.max' => 'كلمة السر أطول من المسموح / The password is too long.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة السر الجديدة غير مقبولة / Invalid new password',
                'errors' => $validator->errors(),
            ], 422);
        }

        $hash = Hash::make((string) $request->input('password'));

        try {
            DB::connection('oracle')->transaction(function () use ($hash, $actor) {
                $this->settings->set(
                    AppConfig::API_SETTINGS_PASSWORD,
                    $hash,
                    $actor ? $this->idOf($actor) : null,
                    true
                );
            });

            $this->settings->flush();

            $this->activity->success('app_config.api_password', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'module_code' => 'app_config',
                'entity_type' => 'APP_SETTINGS',
                'entity_id' => AppConfig::API_SETTINGS_PASSWORD,
                'description_ar' => 'تغيير كلمة سر إعدادات اتصال API',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => 'تم تغيير كلمة سر إعدادات API / API settings password updated.',
                'data' => ['updated' => true],
            ]);
        } catch (Throwable $e) {
            $this->settings->flush();

            return $this->errorResponse('خطأ في تغيير كلمة سر إعدادات API', $e);
        }
    }

    // ---------------------------------------------------------------------
    // المساعدات الداخلية / internal helpers
    // ---------------------------------------------------------------------

    /**
     * بناء قواعد التحقق للمفاتيح الحاضرة فقط، وجمع أسمائها
     * Build validation rules for the present keys only, and collect their names.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<string>}
     */
    private function rulesFor(array $payload): array
    {
        $rules = [];
        $messages = [];
        $present = [];

        foreach (AppConfig::WRITABLE_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $present[] = $key;

            if (in_array($key, AppConfig::COLOR_KEYS, true)) {
                $rules[$key] = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
                $messages[$key.'.regex'] = 'اللون يجب أن يكون بصيغة #RRGGBB / The color must be #RRGGBB.';
                $messages[$key.'.required'] = 'اللون مطلوب / The color is required.';
            } elseif ($key === AppConfig::THEME_FONT_FAMILY) {
                $rules[$key] = ['required', 'string', Rule::in(AppConfig::ALLOWED_FONTS)];
                $messages[$key.'.in'] = 'الخط غير مدعوم / The font family is not supported.';
                $messages[$key.'.required'] = 'الخط مطلوب / The font family is required.';
            } else {
                $rules[$key] = ['nullable', 'string', 'max:'.$this->textLimit($key)];
                $messages[$key.'.max'] = 'النص أطول من المسموح / The text is too long.';
            }
        }

        return [$rules, $messages, $present];
    }

    /**
     * حد طول النص لكل مفتاح — أرقام عملية أدنى من سعة العمود (500 بايت)
     * Per-key text length limit — practical bounds below the column width (500 bytes).
     */
    private function textLimit(string $key): int
    {
        return match ($key) {
            AppConfig::COMPANY_PHONE => 50,
            AppConfig::COMPANY_ADDRESS => 300,
            default => 200,
        };
    }

    /**
     * القيم الحالية للمفاتيح المرسلة — أساس وصف «من … إلى …»
     * Current values of the submitted keys — the "from" half of the audit description.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function currentValues(array $keys): array
    {
        $all = $this->settings->all();
        $values = [];

        foreach ($keys as $key) {
            if (isset($all[$key]['value'])) {
                $values[$key] = (string) $all[$key]['value'];
            }
        }

        return $values;
    }

    /**
     * تسميات عربية مفهرسة بالمفتاح — تُقرأ من `definitions()` لتبقى متزامنة مع البذر
     * Arabic labels keyed by setting key, read from definitions() so they stay in sync with the seed.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        $names = [];

        foreach ($this->settings->definitions() as $setting) {
            $names[$setting['key']] = (string) $setting['name_ar'];
        }

        return $names;
    }

    /**
     * وصف عربي للسجل: كل مفتاح من قيمته إلى قيمتها
     * Arabic audit description: each key's from/to pair.
     *
     * @param  list<array<string, string>>  $changes
     * @param  array<string, string>  $names
     */
    private function describe(array $changes, array $names): string
    {
        if ($changes === []) {
            return 'حفظ تهيئة التطبيق دون أي تغيير فعلي';
        }

        $parts = [];

        foreach ($changes as $change) {
            $parts[] = ($names[$change['key']] ?? $change['key']).' من «'.
                ($change['from'] === '' ? 'فارغ' : $change['from']).'» إلى «'.$change['to'].'»';
        }

        return 'تحديث تهيئة التطبيق: '.implode(' · ', $parts);
    }

    private function idOf(User $user): int
    {
        return (int) ($user->getAttribute('USER_ID') ?? $user->getKey());
    }

    private function errorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
