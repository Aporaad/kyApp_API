<?php

use Illuminate\Support\Facades\Route;

// هذا خادم API فقط — لا صفحة رئيسية عامة
// This is an API-only server — no public landing page
Route::get('/', fn () => response('', 204));

// نقطة فحص الاتصال للتطبيق — يجب بقاؤها عامة
// Connectivity probe for the Flutter client — must remain public
Route::get('/hello', fn () => 'ok');

