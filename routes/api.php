<?php

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TableQueryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

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
