<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\PermissionResolver;
use App\Support\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * متحكم إعدادات الأمان — شاشة «اعدادات الامان» في الإدارة
 * Security settings controller — backs the admin "security settings" screen.
 *
 * الإعدادات ليست ملف `.env` ولا `config()`: هي صفوف في `APP_SETTINGS` يحرّرها المدير من الواجهة
 * وتسري على الفور، لأنها تُقرأ في مسار الطلبات نفسه (سياسة الجلسة تتحقق من مهلة الخمول لكل طلب).
 * These are not .env values: they are APP_SETTINGS rows an admin edits live, read on the request
 * path itself — the session policy consults the idle window on every authenticated call.
 *
 * كل حفظ يمرّ بـ `SettingsStore::set()` الذي يفرض النوع والحدود (`MIN_VALUE`/`MAX_VALUE`)، فهنا
 * تُضاف القرارات التي لا تعرفها الطبقة: من يملك المفتاح المقفل، وكيف يبدو السجل التدقيقي.
 * SettingsStore::set() enforces type and bounds; this controller adds what that layer cannot know:
 * who may touch a locked key and how the audit entry should read.
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly PermissionResolver $permissions,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * كل الإعدادات بتسمياتها العربية وقيمها المحوّلة لأنواعها
     * Every setting with its Arabic labels and its value cast to the declared type
     *
     * `definitions()` تقرأ من القاعدة مباشرة (لا كاش): الحمل فيه عربية، وكاش أوراكل يسع 4000 بايت
     * فقط — انظر `ORA-01461` في رأس `SettingsStore`.
     * definitions() reads Oracle directly: this payload carries Arabic and the database cache
     * column cannot take a bind beyond 4000 bytes (ORA-01461, see the SettingsStore header).
     */
    public function index(): JsonResponse
    {
        try {
            $data = [];

            // التسميات العربية أصلُها سليم: `definitions()` يصلحها مرة واحدة، فأي `cleanData`
            // إضافية هنا تُعيد تشويهها إلى رموز
            // definitions() already repaired these once: a second cleanData() re-corrupts them.
            foreach ($this->settings->definitions() as $setting) {
                $data[] = [
                    'setting_id' => (int) $setting['setting_id'],
                    'key' => $setting['key'],
                    'name_ar' => $setting['name_ar'],
                    'description_ar' => $setting['description_ar'],
                    'value_type' => $setting['value_type'],
                    'value' => $this->settings->get($setting['key']),
                    'raw_value' => $setting['value'],
                    'min_value' => $setting['min_value'],
                    'max_value' => $setting['max_value'],
                    'is_lockable' => (int) $setting['lockable'] === 1,
                    'sort_order' => (int) $setting['sort_order'],
                    'updated_by' => $setting['updated_by'],
                    'updated_on' => $setting['updated_on'],
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب إعدادات الأمان / Security settings retrieved.',
                'data' => $data,
                'meta' => ['total' => count($data)],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب إعدادات الأمان', $e);
        }
    }

    /**
     * حفظ مجموعة إعدادات دفعة واحدة
     * Save a batch of settings in one request
     *
     * `items` مصفوفة `{key, value}`: ترسل الواجهة كل الحقول مهما لم تتغير، لأن إرسال حقل واحد فقط
     * يخفي عن السجل التدقيقي أي شيء تغيّر فعلاً.
     * The screen submits every field rather than only the touched ones, so the audit description
     * can name exactly what changed instead of guessing.
     *
     * العبارة كلها في معاملة واحدة: فشل مفتاح واحد يُلغي الكل، فلا تبقى سياسة الجلسة على مزيج
     * من قيمتين (خمول جديد مع عمر مطلق قديم مثلاً).
     * One transaction: a single bad key rolls the batch back, so the session policy never runs on
     * a half-applied mix (a new idle window with the old absolute lifetime, for example).
     */
    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validator = Validator::make($request->all(), [
            // `present` بدل `required`: المصفوفة الفارغة «موجودة» وتمرّ من required ثم تسقط على min،
            // فتنقسم الرسالتان بوضوح — غياب المفتاح خطأ طلب، والقائمة الفارغة خطأ حفظ بلا تغيير.
            // present, not required: an empty array is "present" and would fail required before min
            // ever ran, so the two cases (no key at all / saved nothing) get separate messages.
            'items' => ['present', 'array', 'min:1'],
            'items.*.key' => ['required', 'string', 'max:60'],
            'items.*.value' => ['nullable'],
        ], [
            'items.present' => 'قائمة الإعدادات مطلوبة / The settings list is required.',
            'items.array' => 'الإعدادات يجب أن تكون قائمة / The settings must be a list.',
            'items.min' => 'إعداد واحد على الأقل / At least one setting is required.',
            'items.*.key.required' => 'مفتاح الإعداد مطلوب / The setting key is required.',
            'items.*.key.max' => 'المفتاح أطول من 60 محرفاً / The key is too long.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة / Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $items = $request->input('items', []);

        // المفتاح المقفل لا يُلمس إلا بصلاحية حساسة صريحة: قفل «مفتاح في السلوك الأمني» ليس زينة
        // A locked key needs the sensitive-management grant explicitly: IS_LOCKABLE is a control.
        $lockable = $this->lockedKeysRequested($items);

        if ($lockable !== [] && ! $this->mayManageLocked($actor)) {
            return response()->json([
                'success' => false,
                'message' => 'بعض الإعدادات مقفلة وتحتاج صلاحية إدارة الإعدادات الحساسة / Locked settings require settings.security_manage',
                'errors' => ['locked_keys' => $lockable],
            ], 403);
        }

        $before = $this->currentValues($items);
        $names = $this->labels();
        $changes = [];

        try {
            DB::connection('oracle')->transaction(function () use ($items, $actor, $lockable, $before, $names, &$changes) {
                foreach ($items as $item) {
                    $key = trim((string) ($item['key'] ?? ''));
                    $value = $item['value'] ?? null;

                    // `null` تعني «لم يُرسل هذا الحقل» فلا تلمسه؛ أما النص الفارغ فقصد صريح بمسحه
                    // (تحديداً `maintenance_message`). النوع `int` يرفض الفراغ بنفسه فيردّ 422.
                    // null means "not submitted" so we skip it; an empty string is a deliberate
                    // clear (maintenance_message). Numeric keys reject the blank on their own.
                    if ($key === '' || $value === null) {
                        continue;
                    }

                    $saved = $this->settings->set(
                        $key,
                        $value,
                        $actor ? $this->idOf($actor) : null,
                        in_array($key, $lockable, true)
                    );

                    if ((string) ($before[$key] ?? '') !== (string) $saved['value']) {
                        $changes[] = [
                            'key' => $key,
                            'name_ar' => $names[$key] ?? $key,
                            'from' => (string) ($before[$key] ?? ''),
                            'to' => (string) $saved['value'],
                        ];
                    }
                }
            });

            $this->settings->flush();

            $this->activity->success('settings.update', [
                'user_id' => $actor ? $this->idOf($actor) : null,
                'module_code' => 'settings',
                'entity_type' => 'APP_SETTINGS',
                'entity_id' => $changes === [] ? null : implode(',', array_column($changes, 'key')),
                'description_ar' => $this->describe($changes),
            ], $request);

            return response()->json([
                'success' => true,
                'message' => $changes === []
                    ? 'لا تغيير في الإعدادات / No settings changed.'
                    : 'تم حفظ إعدادات الأمان / Security settings saved.',
                'data' => $this->after($changes),
                'meta' => ['changed' => count($changes)],
            ]);
        } catch (InvalidArgumentException $e) {
            // القيمة رُفضت قبل أن تُكتب (نوع أو حد): نعيد 422 مفصّلاً ولا نلمس شيئاً
            // The value was rejected by type or bound before landing: 422, nothing written.
            $this->settings->flush();

            return response()->json([
                'success' => false,
                'message' => 'قيمة الإعداد غير مقبولة / Invalid setting value',
                'errors' => ['setting' => [$e->getMessage()]],
            ], 422);
        } catch (Throwable $e) {
            $this->settings->flush();

            return $this->errorResponse('خطأ في حفظ إعدادات الأمان', $e);
        }
    }

    // ---------------------------------------------------------------------
    // المساعدات الداخلية / internal helpers
    // ---------------------------------------------------------------------

    /**
     * المفاتيح المقفلة المطلوبة في هذه الطلب
     * The locked keys this request tries to write
     *
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    private function lockedKeysRequested(array $items): array
    {
        $all = $this->settings->all();
        $locked = [];

        foreach ($items as $item) {
            $key = trim((string) ($item['key'] ?? ''));

            if ($key !== '' && (int) ($all[$key]['lockable'] ?? 0) === 1 && ! in_array($key, $locked, true)) {
                $locked[] = $key;
            }
        }

        return $locked;
    }

    /**
     * هل يملك المانح صلاحية الإعدادات الحساسة؟ (المدير يمرّ دائماً)
     * Does the actor hold the sensitive-settings grant? (system admins always do)
     */
    private function mayManageLocked(?User $actor): bool
    {
        return $actor !== null && $this->permissions->can($actor, 'settings.security_manage');
    }

    /**
     * القيم الحالية للمفاتيح المرسلة — أساس وصف «من … إلى …»
     * Current values of the submitted keys — the "from" half of the audit description
     *
     * @param  array<int, mixed>  $items
     * @return array<string, string>
     */
    private function currentValues(array $items): array
    {
        $all = $this->settings->all();
        $values = [];

        foreach ($items as $item) {
            $key = trim((string) ($item['key'] ?? ''));

            if ($key !== '' && isset($all[$key]['value'])) {
                $values[$key] = (string) $all[$key]['value'];
            }
        }

        return $values;
    }

    /**
     * وصف عربي للسجل: كل مفتاح من قيمته إلى قيمتها
     * Arabic audit description: each key's from/to pair
     *
     * @param  list<array<string, string>>  $changes
     */
    private function describe(array $changes): string
    {
        if ($changes === []) {
            return 'حفظ إعدادات الأمان دون أي تغيير فعلي';
        }

        $parts = [];

        foreach ($changes as $change) {
            $parts[] = $change['name_ar'].' من «'.($change['from'] === '' ? 'فارغ' : $change['from']).
                '» إلى «'.$change['to'].'»';
        }

        return 'تحديث إعدادات الأمان: '.implode(' · ', $parts);
    }

    /**
     * تسميات عربية مفهرسة بالمفتاح — تُقرأ مرة واحدة لأن نسخة الكاش لا تحمل العربية
     * Arabic labels keyed by setting key, read once because the cached copy holds no Arabic
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
     * الحالة النهائية للمفاتيح المتغيرة بعد الحفظ
     * Final state of the changed keys, after the write
     *
     * @param  list<array<string, string>>  $changes
     * @return list<array<string, mixed>>
     */
    private function after(array $changes): array
    {
        if ($changes === []) {
            return [];
        }

        $keys = array_column($changes, 'key');
        $out = [];

        foreach ($this->settings->definitions() as $setting) {
            if (! in_array($setting['key'], $keys, true)) {
                continue;
            }

            $out[] = [
                'key' => $setting['key'],
                'name_ar' => $setting['name_ar'],
                'value' => $this->settings->get($setting['key']),
                'value_type' => $setting['value_type'],
            ];
        }

        return $out;
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
