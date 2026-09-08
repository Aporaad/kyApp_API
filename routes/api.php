<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TableQueryController;
use App\Http\Controllers\WorkerController;
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

// ============================================================
// مسارات العملاء - Customer Management Routes
// ============================================================
Route::get('/customers', [CustomerController::class, 'index']);
Route::get('/customers/{custNo}', [CustomerController::class, 'show'])->where('custNo', '[0-9]+');
Route::post('/customers', [CustomerController::class, 'store']);
Route::put('/customers/{custNo}', [CustomerController::class, 'update'])->where('custNo', '[0-9]+');
Route::delete('/customers/{custNo}', [CustomerController::class, 'destroy'])->where('custNo', '[0-9]+');
Route::get('/customers/{custNo}/statement', [CustomerController::class, 'statement'])->where('custNo', '[0-9]+');

// ============================================================
// مسارات جاري العاملين - Workers Management Routes
// ============================================================

// الموظفين - Employees (TAILOR_EMPLOYEES)
Route::get('/workers/employees',                  [WorkerController::class, 'employees']);
Route::post('/workers/employees',                 [WorkerController::class, 'storeEmployee']);
Route::put('/workers/employees/{id}',             [WorkerController::class, 'updateEmployee'])->where('id', '[0-9]+');
Route::delete('/workers/employees/{id}',          [WorkerController::class, 'destroyEmployee'])->where('id', '[0-9]+');
Route::patch('/workers/employees/{id}/toggle',    [WorkerController::class, 'toggleEmployee'])->where('id', '[0-9]+');
Route::get('/workers/employees/{id}/statement',   [WorkerController::class, 'statementEmployee'])->where('id', '[0-9]+');

// القصاصين - Cutters (CUTTER)
Route::get('/workers/cutters',                    [WorkerController::class, 'cutters']);
Route::post('/workers/cutters',                   [WorkerController::class, 'storeCutter']);
Route::put('/workers/cutters/{id}',               [WorkerController::class, 'updateCutter'])->where('id', '[0-9]+');
Route::delete('/workers/cutters/{id}',            [WorkerController::class, 'destroyCutter'])->where('id', '[0-9]+');
Route::patch('/workers/cutters/{id}/toggle',      [WorkerController::class, 'toggleCutter'])->where('id', '[0-9]+');
Route::get('/workers/cutters/{id}/statement',     [WorkerController::class, 'statementCutter'])->where('id', '[0-9]+');

// الخياطين - Tailors (TAILOR)
Route::get('/workers/tailors',                    [WorkerController::class, 'tailors']);
Route::post('/workers/tailors',                   [WorkerController::class, 'storeTailor']);
Route::put('/workers/tailors/{id}',               [WorkerController::class, 'updateTailor'])->where('id', '[0-9]+');
Route::delete('/workers/tailors/{id}',            [WorkerController::class, 'destroyTailor'])->where('id', '[0-9]+');
Route::patch('/workers/tailors/{id}/toggle',      [WorkerController::class, 'toggleTailor'])->where('id', '[0-9]+');
Route::get('/workers/tailors/{id}/statement',     [WorkerController::class, 'statementTailor'])->where('id', '[0-9]+');

