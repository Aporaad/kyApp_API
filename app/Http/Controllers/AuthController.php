<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\PermissionResolver;
use App\Support\SessionPolicy;
use App\Support\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * متحكم المصادقة وإدارة التوكنات لتطبيق Flutter
 * Authentication and token management controller for the Flutter application.
 *
 * إضافة IBAC (مرحلة 2): استجابات الدخول و `/auth/me` تحمل الآن حالة الصلاحيات
 * (`permissions` + `modules` + `is_admin`) حتى تُبنى واجهة العميل على المنح الفعلي لا على التخمين،
 * وكل توكن يُصدر بمهلة خمول حقيقية (`EXPIRES_AT`) تديرها `SessionPolicy` انزلاقياً،
 * وكل حدث مصادقة يُسجَّل في سجل نشاط المستخدمين.
 * IBAC additions (phase 2): sign-in and `/auth/me` carry the effective permission state so the
 * client gates its UI on real grants, every issued token gets a real idle expiry driven by
 * `SessionPolicy`, and every authentication event lands in the user activity log.
 *
 * ملاحظة ترميز / encoding note: أسماء المستخدمين العربية تُترك كما جاءت من أوراكل لأن العميل يطبّق
 * `ArabicEncodingUtils.fix()` عليها، والإصلاح هنا يعني إصلاحاً مزدوجاً يفسد النص.
 * Arabic names are returned exactly as Oracle provided them: the Flutter side already repairs them
 * and repairing twice corrupts the text. Do not add `fixMojibake()` here without client changes.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly SessionPolicy $sessions,
        private readonly ActivityLogger $activity,
        private readonly SettingsStore $settings,
    ) {}

    /**
     * تسجيل الدخول والتحقق من المستخدم وإصدار التوكن
     * Authenticate the user and issue a Sanctum token
     */
    public function login(Request $request): JsonResponse
    {
        // التحقق من صحة المدخلات
        // Validate request input
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
            'user_type' => ['nullable', 'string', 'in:employee,customer,1,2'],
        ], [
            'username.required' => 'اسم المستخدم مطلوب / Username is required.',
            'password.required' => 'كلمة المرور مطلوبة / Password is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الدخول غير مكتملة / Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password');

        // البحث باسم المستخدم العربي أو الإنجليزي، وبـ USER_ID إذا كان المدخلاً رقمياً (D6)
        // Query by AR/EN username, or by USER_ID when the input is numeric.
        // عمود USER_NO لم يعد موجوداً في APP_USERS، فالمعرّف الصحيح هو USER_ID.
        // USER_NO no longer exists on APP_USERS — USER_ID is the identifier.
        $user = User::query()
            ->where(function ($query) use ($username) {
                $query->whereRaw('UPPER(USER_NAME_EN) = ?', [strtoupper($username)])
                    ->orWhere('USER_NAME_AR', $username);

                if (is_numeric($username)) {
                    $query->orWhere('USER_ID', (int) $username);
                }
            })
            ->first();

        // المستخدم غير موجود: نفس رسالة كلمة المرور الخاطئة حتى لا تُهرم أسماء المستخدمين الصالحة
        // An unknown user replies exactly like a bad password, so valid usernames stay unenumerable.
        if (! $user) {
            $this->activity->failed('login_failed', [
                'module_code' => 'system',
                'description_ar' => "محاولة دخول فاشلة: اسم مستخدم غير موجود («{$username}»)",
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة / Invalid username or password.',
            ], 401);
        }

        // التحقق من حالة تفعيل الحساب
        // Check whether the account is active
        if (! $this->isActivated($user)) {
            $this->activity->failed('login_failed', [
                'user_id' => $this->idOf($user),
                'module_code' => 'system',
                'description_ar' => 'محاولة دخول على حساب معطّل',
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب معطل، يرجى مراجعة إدارة النظام / Account is deactivated.',
            ], 403);
        }

        // مطابقة كلمة المرور (دعم التشفير أو الكلمات المخزنة كنص صريح في النظام القديم)
        // Verify the password (hashed, or legacy plain-text rows written by the old system)
        // ⚠️ فرع النص الصريح متعمَّد (S3): حذفه يقفل مستخدمين حقيقيين حتى تُرحَّل كلمات المرور.
        $userPassword = (string) ($user->user_password ?? $user->USER_PASSWORD ?? '');
        $passwordMatches = ($userPassword === $password);

        if (! $passwordMatches && $this->looksHashed($userPassword)) {
            try {
                $passwordMatches = Hash::check($password, $userPassword);
            } catch (Throwable) {
                $passwordMatches = false;
            }
        }

        if (! $passwordMatches) {
            $this->activity->failed('login_failed', [
                'user_id' => $this->idOf($user),
                'module_code' => 'system',
                'description_ar' => 'محاولة دخول فاشلة: كلمة مرور غير مطابقة',
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة / Invalid username or password.',
            ], 401);
        }

        // إنشاء رمز وصول شخصي عبر Sanctum ثم منحه مهلة جلسة حقيقية قابلة للتجديد الانزلاقي
        // Issue a Sanctum token, then give it a real, sliding session horizon.
        // اسم التوكن يبقى ky_app_{user_type} ليظل متميزاً في شاشة الجلسات النشطة.
        $accessToken = $user->createToken('ky_app_'.($request->input('user_type') ?? 'employee'));
        $this->sessions->prime($accessToken->accessToken);
        // الكنس يأتي بعد إصدار التوكن حتى لا يمسّ التوكن الجديد لو تشابهت اللحظات
        // Purge after issuing so the fresh token can never be swept by its own login
        $this->sessions->purgeExpired();

        $this->activity->success('login', [
            'user_id' => $this->idOf($user),
            'module_code' => 'system',
            'description_ar' => 'تسجيل دخول بكلمة المرور',
            'platform' => $request->input('platform'),
        ], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح / Logged in successfully.',
            'token' => $accessToken->plainTextToken,
            'user' => $this->userPayload($user),
        ], 200);
    }

    /**
     * استرجاع بيانات المستخدم الحالي المصادق عليه مع حالته من IBAC
     * Get the authenticated user's profile together with its IBAC state
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * صلاحيات المستخدم الحالية تُقرأ طازجة من القاعدة
     * The current user's permissions, read fresh from the database.
     *
     * تُستدعى عند فتح التطبيق وبعد أي تعديل على المنح: العميل يخزّن نسخة من الأكواد وقد تسقط
     * قديمة، فنهمل نسخة المحلّل هنا لنضمن أن الرد يعكس جدول المنح لحظياً.
     * The client caches permission codes, which can go stale after a grant change, so the resolver's
     * copy is dropped here to make the response reflect the grant table right now.
     */
    public function myPermissions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->permissions->forget($this->idOf($user));

        $token = $user->currentAccessToken();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الصلاحيات / Permissions loaded.',
            'data' => array_merge($this->permissions->payloadFor($user), [
                'is_admin' => $user->isAdmin(),
                // نهاية الجلسة الحالية: يعرف منها العميل كم بقي له قبل أن يُطلب منه الدخول من جديد
                // القراءة عبر `SessionPolicy::toDate()` لأن أوراكل تُعيد العمود الفارغ بصيغة لا تصلح
                // للسلسلة الاختيارية.
                // Current session deadline: lets the client know how long before re-authentication.
                // Read through `SessionPolicy::toDate()` because Oracle returns an empty date column
                // in a shape the nullsafe operator cannot call a method on.
                'session_expires_at' => SessionPolicy::toDate(
                    $token instanceof PersonalAccessToken ? $token->expires_at : null
                )?->toDateTimeString(),
            ]),
        ]);
    }

    /**
     * تسجيل الخروج وإلغاء صلاحية التوكن الحالي
     * Logout and revoke the current access token
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();

            $this->activity->success('logout', [
                'user_id' => $this->idOf($user),
                'module_code' => 'system',
                'description_ar' => 'تسجيل خروج',
            ], $request);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الخروج بنجاح / Logged out successfully.',
        ]);
    }

    /**
     * تسجيل جهاز المستخدم للمصادقة البيومترية
     * Register a device for biometric authentication
     */
    public function registerBiometric(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_token' => ['required', 'string', 'max:512'],
            'device_name' => ['required', 'string', 'max:200'],
            'platform' => ['required', 'string', 'in:android,ios'],
        ], [
            'device_token.required' => 'معرّف الجهاز مطلوب.',
            'device_name.required' => 'اسم الجهاز مطلوب.',
            'platform.required' => 'المنصة مطلوبة (android أو ios).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        // التحقق من عدم استخدام نفس device_token من قِبل مستخدم آخر (جهاز واحد لكل مستخدم)
        // Reject a device token already bound to another account: one device per user.
        $existingUser = User::query()
            ->whereRaw('DEVICE_TOKEN = ?', [(string) $request->input('device_token')])
            ->where('USER_ID', '!=', $this->idOf($user))
            ->first();

        if ($existingUser) {
            $this->activity->failed('biometric_enroll', [
                'user_id' => $this->idOf($user),
                'module_code' => 'system',
                'description_ar' => 'رفض تسجيل البصمة: الجهاز مرتبط بمستخدم آخر',
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز مسجّل لمستخدم آخر، يرجى التواصل مع الإدارة.',
            ], 409);
        }

        // حفظ بيانات البصمة في APP_USERS
        // Persist the biometric enrollment on APP_USERS
        User::where('USER_ID', $this->idOf($user))
            ->update([
                'DEVICE_TOKEN' => $request->input('device_token'),
                'DEVICE_NAME' => $request->input('device_name'),
                'PLATFORM' => $request->input('platform'),
                'BIOMETRIC_ENABLED' => 1,
                'BIOMETRIC_REGISTERED_AT' => now(),
            ]);

        $this->activity->success('biometric_enroll', [
            'user_id' => $this->idOf($user),
            'module_code' => 'system',
            'description_ar' => 'تسجيل بصمة الجهاز: '.$request->input('device_name'),
            'platform' => $request->input('platform'),
        ], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل البصمة بنجاح / Biometric registered successfully.',
        ]);
    }

    /**
     * تسجيل الدخول باستخدام المقاييس الحيوية
     * Login using biometric authentication
     */
    public function biometricLogin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required'],
            'device_token' => ['required', 'string', 'max:512'],
        ], [
            'user_id.required' => 'معرّف المستخدم مطلوب.',
            'device_token.required' => 'معرّف الجهاز مطلوب.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // القفل النظامي للبصمة: biometric_login_enabled يعطّل المسار لدى كل المستخدمين
        // Platform kill-switch: biometric_login_enabled turns fingerprint sign-in off for everyone.
        if (! $this->settings->bool('biometric_login_enabled', true)) {
            $this->activity->failed('biometric_login', [
                'user_id' => (int) $request->input('user_id'),
                'module_code' => 'system',
                'description_ar' => 'محاولة دخول بالبصمة والنظام أوقفها من إعدادات الأمان',
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'الدخول بالبصمة موقوف حالياً، يرجى استخدام كلمة المرور.',
            ], 403);
        }

        $userId = (int) $request->input('user_id');
        $deviceToken = (string) $request->input('device_token');

        // مطابقة USER_ID مع device_token معاً: البصمة لا تعمل إلا على الجهاز المسجَّل لصاحبها
        // USER_ID and DEVICE_TOKEN must match together: a fingerprint only works on its own device.
        $user = User::query()
            ->where('USER_ID', $userId)
            ->whereRaw('DEVICE_TOKEN = ?', [$deviceToken])
            ->where('BIOMETRIC_ENABLED', 1)
            ->first();

        if (! $user) {
            $this->activity->failed('biometric_login', [
                'user_id' => $userId,
                'module_code' => 'system',
                'description_ar' => 'فشل الدخول بالبصمة: الجهاز غير مسجَّل أو ألغي تسجيله',
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'فشل التحقق، يرجى إعادة تفعيل البصمة من الإعدادات.',
            ], 401);
        }

        // الحساب المعطّل لا يدخل حتى بالبصمة
        // A deactivated account cannot sign in even with a fingerprint.
        if (! $this->isActivated($user)) {
            $this->activity->failed('biometric_login', [
                'user_id' => $userId,
                'module_code' => 'system',
                'description_ar' => 'محاولة دخول بالبصمة على حساب معطّل',
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب معطل، يرجى مراجعة إدارة النظام.',
            ], 403);
        }

        // تحديث تاريخ آخر استخدام للبصمة
        // Stamp the last fingerprint use
        User::where('USER_ID', $userId)->update(['BIOMETRIC_LAST_USED' => now()]);

        // إصدار توكن جديد بنفس سياسة الجلسة المتبعة في الدخول بكلمة المرور
        // Issue the token under the same session policy as password login
        $accessToken = $user->createToken('ky_biometric_login');
        $this->sessions->prime($accessToken->accessToken);
        $this->sessions->purgeExpired();

        $this->activity->success('biometric_login', [
            'user_id' => $userId,
            'module_code' => 'system',
            'description_ar' => 'تسجيل دخول بالبصمة',
            'platform' => $user->getAttribute('PLATFORM'),
        ], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بالبصمة بنجاح / Biometric login successful.',
            'token' => $accessToken->plainTextToken,
            'user' => array_merge($this->userPayload($user), ['biometric_enabled' => true]),
        ], 200);
    }

    /**
     * إلغاء تسجيل البصمة للجهاز الحالي
     * Revoke the biometric enrollment for the current device
     */
    public function revokeBiometric(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        User::where('USER_ID', $this->idOf($user))
            ->update([
                'DEVICE_TOKEN' => null,
                'DEVICE_NAME' => null,
                'PLATFORM' => null,
                'BIOMETRIC_ENABLED' => 0,
                'BIOMETRIC_REGISTERED_AT' => null,
            ]);

        $this->activity->success('biometric_revoke', [
            'user_id' => $this->idOf($user),
            'module_code' => 'system',
            'description_ar' => 'إلغاء تسجيل البصمة لهذا الجهاز',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء تسجيل البصمة بنجاح / Biometric revoked successfully.',
        ]);
    }

    /**
     * حمولة المستخدم الموحّدة: بيانات الحساب + حالة IBAC (المنح والوحدات وصفة المدير)
     * Unified user payload: account data plus the IBAC state (grants, modules, admin flag).
     *
     * تستعملها login و me و biometricLogin فلا تتباعد الاستجابات الثلاث عن بعضها.
     * Shared by the three sign-in/profile responses so they can never drift apart.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return array_merge([
            'id' => $this->idOf($user),
            'username' => $user->user_name_en ?? $user->USER_NAME_EN ?? $user->user_name ?? $user->USER_NAME ?? $user->user_name_ar ?? $user->USER_NAME_AR,
            'name_ar' => $user->user_name_ar ?? $user->USER_NAME_AR,
            'name_en' => $user->user_name_en ?? $user->USER_NAME_EN,
            'user_type' => $user->user_type ?? $user->USER_TYPE,
            'branch_no' => $user->branch_no ?? $user->BRANCH_NO,
            'is_admin' => $user->isAdmin(),
        ], $this->permissions->payloadFor($user));
    }

    /**
     * هل الحساب مفعّل؟ (أوراكل قد يعيد اسم العمود بأي حالة أحرف)
     * Is the account active? Oracle may hand the column back in either case.
     */
    private function isActivated(User $user): bool
    {
        return (int) ($user->is_activated ?? $user->IS_ACTIVATED ?? 0) === 1;
    }

    /**
     * معرّف المستخدم الصحيح
     * Resolve USER_ID
     */
    private function idOf(User $user): int
    {
        return (int) ($user->user_id ?? $user->USER_ID ?? $user->getKey());
    }

    /**
     * هل القيمة المخزّنة تشبه بصمة hash معروفة؟
     * Does the stored value look like a known password hash?
     */
    private function looksHashed(string $stored): bool
    {
        return str_starts_with($stored, '$2y$')
            || str_starts_with($stored, '$2a$')
            || str_starts_with($stored, '$argon2');
    }
}
