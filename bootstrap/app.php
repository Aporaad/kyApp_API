<?php

use App\Http\Middleware\AppendSessionExpiry;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // اختصار «permission» لحارس الصلاحيات — يُستخدم بصيغة permission:customers.view
        // The `permission` alias for the IBAC guard, used as `permission:customers.view`.
        $middleware->alias([
            'permission' => EnsurePermission::class,
        ]);

        // ترويسة موعد الجلسة على مسارات الـ API وحدها: لا شأن لـ `/` ولا `/up` بالجلسات
        // The session deadline header belongs to the API surface only: `/` and `/up` have no session.
        // `append` يكفي لأن القراءة تتم بعد `next()`، فالجلسة تكون حُلّت في حلقة `auth:sanctum`.
        // append is enough because the token is read after next(), by which time auth:sanctum resolved it.
        $middleware->api(append: [
            AppendSessionExpiry::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
