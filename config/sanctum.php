<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    | ⚠️ يجب أن تبقى null — نظام الجلسات في ky_apps لا يعمل هنا.
    | `Sanctum\Guard` يقارن هذه القيمة بـ `created_at` أي بعمر مطلق ثابت: أي رقم يجعل
    | التطبيق يطرد المستخدم النشيط بعد انتهائه أثناء عمله، وهذا يخالف المتطلب.
    | التجديد الانزلاقي ومهلة الخمول يديرهما `App\Support\SessionPolicy` عبر عمود
    | `EXPIRES_AT` المرتبط بـ `session_idle_minutes` في جدول APP_SETTINGS.
    | MUST stay null. Any number here becomes a hard absolute age (Guard compares it to
    | `created_at`) and would disconnect active users. Sliding renewal and idle expiry are
    | implemented by `App\Support\SessionPolicy` on the `expires_at` column, driven by the
    | `session_idle_minutes` row of APP_SETTINGS.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Track Last Used At
    |--------------------------------------------------------------------------
    |
    | Sanctum يكتب `LAST_USED_AT` عند كل طلب مصادق عليه، وشاشة «الجلسات النشطة»
    | تعتمد عليه لعرض آخر نشاط حقيقي لكل جلسة. لا تعطّل هذه القيمة.
    | Sanctum stamps `LAST_USED_AT` on every authenticated request; the active-sessions
    | screen reads it as the real "last activity" value. Do not disable.
    |
    */

    'last_used_at' => true,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
