<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم المصادقة وإدارة التوكنات لتطبيق Flutter
 * Authentication and token management controller for Flutter application
 */
class AuthController extends Controller
{
    /**
     * تسجيل الدخول والتحقق من المستخدم وإصدار التوكن
     * Authenticate user and issue Sanctum token
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

        $username = trim($request->input('username'));
        $password = (string) $request->input('password');

        // البحث عن المستخدم باسم المستخدم العربي أو الإنجليزي أو رقم المستخدم (مع مراعاة توافق الأنواع في أوراكل)
        // Query user by username (EN or AR) or user number (strictly typed for Oracle)
        $user = User::query()
            ->where(function ($query) use ($username) {
                $query->whereRaw('UPPER(USER_NAME_EN) = ?', [strtoupper($username)])
                    ->orWhere('USER_NAME_AR', $username);

                if (is_numeric($username)) {
                    $query->orWhere('USER_NO', (int) $username);
                }
            })
            ->first();

        // التحقق من وجود المستخدم
        // Verify user existence
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة / Invalid username or password.',
            ], 401);
        }

        // التحقق من حالة تفعيل الحساب
        // Check if the user account is active
        $isActivated = (int) ($user->is_activated ?? $user->IS_ACTIVATED ?? 0);
        if ($isActivated !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب معطل، يرجى مراجعة إدارة النظام / Account is deactivated.',
            ], 403);
        }

        // مطابقة كلمة المرور (دعم التشفير أو الكلمات المخزنة كنص صريح في النظام القديم)
        // Verify password (supports Hash or plain-text legacy database storage safely)
        $userPassword = (string) ($user->user_password ?? $user->USER_PASSWORD ?? '');
        $passwordMatches = ($userPassword === $password);

        if (! $passwordMatches && (str_starts_with($userPassword, '$2y$') || str_starts_with($userPassword, '$2a$') || str_starts_with($userPassword, '$argon2'))) {
            try {
                $passwordMatches = Hash::check($password, $userPassword);
            } catch (\Throwable $e) {
                $passwordMatches = false;
            }
        }

        if (! $passwordMatches) {
            return response()->json([
                'success' => false,
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة / Invalid username or password.',
            ], 401);
        }

        // إنشاء رمز وصول شخصي عبر Laravel Sanctum
        // Generate personal access token via Laravel Sanctum
        $tokenName = 'ky_app_'.($request->input('user_type') ?? 'employee');
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح / Logged in successfully.',
            'token' => $token,
            'user' => [
                'id' => $user->user_id ?? $user->USER_ID ?? $user->getKey(),
                'user_no' => $user->user_no ?? $user->USER_NO,
                'username' => $user->user_name_en ?? $user->USER_NAME_EN ?? $user->user_name_ar ?? $user->USER_NAME_AR,
                'name_ar' => $user->user_name_ar ?? $user->USER_NAME_AR,
                'name_en' => $user->user_name_en ?? $user->USER_NAME_EN,
                'user_type' => $user->user_type ?? $user->USER_TYPE,
                'branch_no' => $user->branch_no ?? $user->BRANCH_NO,
            ],
        ], 200);
    }

    /**
     * استرجاع بيانات المستخدم الحالي المصادق عليه
     * Get authenticated user profile
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->user_id ?? $user->USER_ID ?? $user->getKey(),
                'user_no' => $user->user_no ?? $user->USER_NO,
                'username' => $user->user_name_en ?? $user->USER_NAME_EN ?? $user->user_name_ar ?? $user->USER_NAME_AR,
                'name_ar' => $user->user_name_ar ?? $user->USER_NAME_AR,
                'name_en' => $user->user_name_en ?? $user->USER_NAME_EN,
                'user_type' => $user->user_type ?? $user->USER_TYPE,
                'branch_no' => $user->branch_no ?? $user->BRANCH_NO,
            ],
        ]);
    }

    /**
     * تسجيل الخروج وإلغاء صلاحية التوكن الحالي
     * Logout and revoke current access token
     */
    public function logout(Request $request): JsonResponse
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الخروج بنجاح / Logged out successfully.',
        ]);
    }

    /**
     * تسجيل جهاز المستخدم للمصادقة البيومترية
     * Register device for biometric authentication
     */
    public function registerBiometric(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_token' => ['required', 'string', 'max:512'],
            'device_name'  => ['required', 'string', 'max:200'],
            'platform'     => ['required', 'string', 'in:android,ios'],
        ], [
            'device_token.required' => 'معرّف الجهاز مطلوب.',
            'device_name.required'  => 'اسم الجهاز مطلوب.',
            'platform.required'     => 'المنصة مطلوبة (android أو ios).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // التحقق من عدم استخدام نفس الـ device_token من قِبل مستخدم آخر
        $existingUser = \App\Models\User::query()
            ->whereRaw('DEVICE_TOKEN = ?', [$request->input('device_token')])
            ->where('user_id', '!=', $user->user_id ?? $user->USER_ID)
            ->first();

        if ($existingUser) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز مسجّل لمستخدم آخر، يرجى التواصل مع الإدارة.',
            ], 409);
        }

        // حفظ بيانات البصمة في APP_USERS
        \App\Models\User::where('user_id', $user->user_id ?? $user->USER_ID)
            ->update([
                'DEVICE_TOKEN'          => $request->input('device_token'),
                'DEVICE_NAME'           => $request->input('device_name'),
                'PLATFORM'              => $request->input('platform'),
                'BIOMETRIC_ENABLED'     => 1,
                'BIOMETRIC_REGISTERED_AT' => now(),
            ]);

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
            'user_id'      => ['required'],
            'device_token' => ['required', 'string', 'max:512'],
        ], [
            'user_id.required'      => 'معرّف المستخدم مطلوب.',
            'device_token.required' => 'معرّف الجهاز مطلوب.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير مكتملة.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $userId      = (int) $request->input('user_id');
        $deviceToken = (string) $request->input('device_token');

        // البحث عن المستخدم بالـ user_id والـ device_token
        $user = \App\Models\User::query()
            ->where('user_id', $userId)
            ->whereRaw('DEVICE_TOKEN = ?', [$deviceToken])
            ->where('biometric_enabled', 1)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'فشل التحقق، يرجى إعادة تفعيل البصمة من الإعدادات.',
            ], 401);
        }

        // التحقق من حالة تفعيل الحساب
        $isActivated = (int) ($user->is_activated ?? $user->IS_ACTIVATED ?? 0);
        if ($isActivated !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب معطل، يرجى مراجعة إدارة النظام.',
            ], 403);
        }

        // تحديث تاريخ آخر استخدام للبصمة
        \App\Models\User::where('user_id', $userId)
            ->update(['BIOMETRIC_LAST_USED' => now()]);

        // إصدار توكن جديد
        $token = $user->createToken('ky_biometric_login')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بالبصمة بنجاح / Biometric login successful.',
            'token'   => $token,
            'user'    => [
                'id'        => $user->user_id ?? $user->USER_ID ?? $user->getKey(),
                'user_no'   => $user->user_no ?? $user->USER_NO,
                'username'  => $user->user_name_en ?? $user->USER_NAME_EN ?? $user->user_name ?? $user->USER_NAME,
                'name_ar'   => $user->user_name_ar ?? $user->USER_NAME_AR,
                'name_en'   => $user->user_name_en ?? $user->USER_NAME_EN,
                'user_type' => $user->user_type ?? $user->USER_TYPE,
                'branch_no' => $user->branch_no ?? $user->BRANCH_NO,
                'biometric_enabled' => true,
            ],
        ], 200);
    }

    /**
     * إلغاء تسجيل البصمة للجهاز الحالي
     * Revoke biometric registration for current device
     */
    public function revokeBiometric(Request $request): JsonResponse
    {
        $user = $request->user();

        \App\Models\User::where('user_id', $user->user_id ?? $user->USER_ID)
            ->update([
                'DEVICE_TOKEN'            => null,
                'DEVICE_NAME'             => null,
                'PLATFORM'                => null,
                'BIOMETRIC_ENABLED'       => 0,
                'BIOMETRIC_REGISTERED_AT' => null,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء تسجيل البصمة بنجاح / Biometric revoked successfully.',
        ]);
    }
}

