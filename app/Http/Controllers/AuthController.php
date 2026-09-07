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
        if ($user->IS_ACTIVATED != 1) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب معطل، يرجى مراجعة إدارة النظام / Account is deactivated.',
            ], 403);
        }

        // مطابقة كلمة المرور (دعم التشفير أو الكلمات المخزنة في النظام الحالي)
        // Verify password (supports Hash or plain-text legacy database storage)
        $passwordMatches = Hash::check($password, $user->USER_PASSWORD) || $user->USER_PASSWORD === $password;

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
                'id' => $user->USER_ID,
                'user_no' => $user->USER_NO,
                'username' => $user->USER_NAME_EN ?? $user->USER_NAME_AR,
                'name_ar' => $user->USER_NAME_AR,
                'name_en' => $user->USER_NAME_EN,
                'user_type' => $user->USER_TYPE,
                'branch_no' => $user->BRANCH_NO,
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
                'id' => $user->USER_ID,
                'user_no' => $user->USER_NO,
                'username' => $user->USER_NAME_EN ?? $user->USER_NAME_AR,
                'name_ar' => $user->USER_NAME_AR,
                'name_en' => $user->USER_NAME_EN,
                'user_type' => $user->USER_TYPE,
                'branch_no' => $user->BRANCH_NO,
            ],
        ]);
    }

    /**
     * تسجيل الخروج وإلغاء صلاحية التوكن الحالي
     * Logout and revoke current access token
     */
    public function logout(Request $request): JsonResponse
    {
        // حذف التوكن الحالي المستخدم في الطلب
        // Delete the current access token used for this request
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الخروج بنجاح / Logged out successfully.',
        ]);
    }
}
