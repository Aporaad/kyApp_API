<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TableQueryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// مسارات المصادقة وتسجيل الدخول
// Authentication & Login routes
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

Route::get('/hello', function () {
    return response()->json([
        'message' => 'Hello from Laravel API',
    ]);
});

Route::get('/invoices', [InvoiceController::class, 'index']);
Route::get('/invoices/{billNo}', [InvoiceController::class, 'show']);

// مسار الاستعلام الديناميكي عن بيانات الجداول مع تحديد عدد الصفوف
// Dynamic table query route to fetch records with a specified row limit
Route::match(['get', 'post'], '/selectFromTable', [TableQueryController::class, 'selectFromTable']);
