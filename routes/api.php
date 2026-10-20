<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\SessionAdminController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TableQueryController;
use App\Http\Controllers\UserAdminController;
use App\Http\Controllers\WorkerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// مسارات المصادقة وتسجيل الدخول
// Authentication & Login routes
Route::post('/auth/login', [AuthController::class, 'login']);

// مسار تسجيل الدخول بالبصمة (عام — بدون توكن)
Route::post('/auth/biometric/login', [AuthController::class, 'biometricLogin']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // مسارات البصمة المحمية بالتوكن
    Route::post('/auth/biometric/register', [AuthController::class, 'registerBiometric']);
    Route::delete('/auth/biometric/revoke', [AuthController::class, 'revokeBiometric']);
});

// ============================================================
// مسارات إدارة المستخدمين والصلاحيات (IBAC)
// User & permission administration routes (IBAC)
//
// كل مسار هنا خلف طبقتين: `auth:sanctum` للجلسة ثم `permission:<code>` للصلاحية،
// والرفض الافتراضي سارٍ: لا صلاحية ⇒ 403 بلا أي استثناء.
// Every route here sits behind two layers: `auth:sanctum` for the session, then `permission:<code>`
// for the grant. Deny-by-default holds: no permission means 403 with no exceptions.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // حالتي: صلاحيات المستخدم الحالي مقروءة من القاعدة
    // My own permissions, read straight from the grant table
    Route::get('/auth/permissions', [AuthController::class, 'myPermissions']);

    // كتالوج الصلاحيات ومنح المستخدمين / permission catalog and user grants
    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:users.view');

    Route::get('/users/{userId}/permissions', [PermissionController::class, 'forUser'])
        ->middleware('permission:users.view')
        ->where('userId', '[0-9]+');

    Route::put('/users/{userId}/permissions', [PermissionController::class, 'syncUser'])
        ->middleware('permission:users.permissions_manage')
        ->where('userId', '[0-9]+');

    // إدارة المستخدمين / user administration
    Route::get('/users', [UserAdminController::class, 'index'])
        ->middleware('permission:users.view');

    Route::get('/users/{userId}', [UserAdminController::class, 'show'])
        ->middleware('permission:users.view')
        ->where('userId', '[0-9]+');

    Route::post('/users', [UserAdminController::class, 'store'])
        ->middleware('permission:users.create');

    Route::put('/users/{userId}', [UserAdminController::class, 'update'])
        ->middleware('permission:users.update')
        ->where('userId', '[0-9]+');

    Route::patch('/users/{userId}/toggle', [UserAdminController::class, 'toggle'])
        ->middleware('permission:users.toggle')
        ->where('userId', '[0-9]+');

    Route::delete('/users/{userId}', [UserAdminController::class, 'destroy'])
        ->middleware('permission:users.delete')
        ->where('userId', '[0-9]+');

    Route::get('/users/{userId}/activity', [UserAdminController::class, 'activity'])
        ->middleware('permission:users.activity_view')
        ->where('userId', '[0-9]+');
});

// ============================================================
// مسارات الجلسات النشطة وإعدادات الأمان (IBAC — مرحلة 4)
// Active sessions & security settings routes (IBAC — phase 4)
//
// الجلسات والاعدادات حساستان بدرجة أعلى من قائمة المستخدمين: الإنهاء يطرد مستخدماً من جهازه،
// وتغيير `session_idle_minutes` يغيّر متى تسقط كل الجلسات القائمة — فلا تُتركان لصلاحية عرض.
// These two are more sensitive than the user list: terminating evicts a device, and editing the
// idle window changes when every live session drops — so neither is left to a view permission.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sessions', [SessionAdminController::class, 'index'])
        ->middleware('permission:sessions.view');

    Route::delete('/sessions/user/{userId}', [SessionAdminController::class, 'terminateUser'])
        ->middleware('permission:sessions.terminate')
        ->where('userId', '[0-9]+');

    Route::delete('/sessions/{tokenId}', [SessionAdminController::class, 'terminate'])
        ->middleware('permission:sessions.terminate')
        ->where('tokenId', '[0-9]+');

    Route::get('/settings', [SettingController::class, 'index'])
        ->middleware('permission:settings.view');

    Route::put('/settings', [SettingController::class, 'update'])
        ->middleware('permission:settings.update');
});

Route::get('/hello', function () {
    return response()->json([
        'message' => 'Hello from Laravel API',
    ]);
});

// ============================================================
// مسارات الأعمال مقفلة بالصلاحيات (المرحلة 7 — القرار D3)
// Business routes sealed by permissions (Phase 7 — decision D3)
//
// كل مجموعة خلف طبقتين: `auth:sanctum` للجلسة ثم `permission:<code>` لكل مسار.
// الرفض الافتراضي سارٍ هنا أيضاً: لا منح ⇒ 403، ومدير النظام يمرّ لأن المحلّ يفحص `IS_ADMIN` أولاً.
// Every group sits behind two layers: `auth:sanctum` for the session, then `permission:<code>` per
// route. Deny-by-default holds: no grant means 403, and the system admin passes because the resolver
// checks `IS_ADMIN` before any grant lookup.
// ============================================================

// ============================================================
// مسارات الفواتير - Invoice Routes (قراءة فقط)
// ============================================================
Route::middleware(['auth:sanctum', 'permission:invoices.view'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{billNo}', [InvoiceController::class, 'show'])
        ->where('billNo', '[0-9]+');
});

// ============================================================
// المسار التشخيصي العام — مقيد بصلاحية النظام الكاملة + قائمة رفض جداول
// The diagnostic query route — gated by the system-wide wildcard plus a table deny list
//
// يكشف هذا المسار أعلى N صفاً من أي جدول في المخطط، ومنها جداول الاعتمادات والجلسات؛ لذلك لا
// يُترك لصلاحية وحدة عادية، وتُستثنى جداوله الحساسة داخل المتحكم نفسه.
// It returns the top-N rows of any schema table — including credential and session tables — so it is
// not left to an ordinary module permission, and its sensitive tables are refused inside the
// controller itself.
// ============================================================
Route::match(['get', 'post'], '/selectFromTable', [TableQueryController::class, 'selectFromTable'])
    ->middleware(['auth:sanctum', 'permission:system.*']);

// ============================================================
// مسارات العملاء - Customer Management Routes
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/customers', [CustomerController::class, 'index'])
        ->middleware('permission:customers.view');
    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.create');
    // الكشف مسار قراءة مستقل الصلاحية: من يرى القائمة لا يرى بالضرورة كشوف أرصدتهم
    // The statement is a separately-granted read: list visibility does not imply balance visibility.
    Route::get('/customers/{custNo}/statement', [CustomerController::class, 'statement'])
        ->middleware('permission:customers.statement')
        ->where('custNo', '[0-9]+');
    Route::get('/customers/{custNo}', [CustomerController::class, 'show'])
        ->middleware('permission:customers.view')
        ->where('custNo', '[0-9]+');
    Route::put('/customers/{custNo}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.update')
        ->where('custNo', '[0-9]+');
    Route::delete('/customers/{custNo}', [CustomerController::class, 'destroy'])
        ->middleware('permission:customers.delete')
        ->where('custNo', '[0-9]+');
});

// ============================================================
// مسارات جاري العاملين - Workers Management Routes
//
// السجلات الثلاثة لكل منها وحدة صلاحيات مستقلة (employees / cutters / tailors) لأن صلاحية
// إدارة الخيّاط لا تعني صلاحية إدارة القصاص.
// Each registry has its own module (employees / cutters / tailors): being able to manage a tailor
// must not imply being able to manage a cutter.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // الموظفين - Employees (TAILOR_EMPLOYEES)
    Route::get('/workers/employees', [WorkerController::class, 'employees'])
        ->middleware('permission:employees.view');
    Route::post('/workers/employees', [WorkerController::class, 'storeEmployee'])
        ->middleware('permission:employees.create');
    Route::get('/workers/employees/{id}/statement', [WorkerController::class, 'statementEmployee'])
        ->middleware('permission:employees.statement')
        ->where('id', '[0-9]+');
    Route::put('/workers/employees/{id}', [WorkerController::class, 'updateEmployee'])
        ->middleware('permission:employees.update')
        ->where('id', '[0-9]+');
    Route::delete('/workers/employees/{id}', [WorkerController::class, 'destroyEmployee'])
        ->middleware('permission:employees.delete')
        ->where('id', '[0-9]+');
    Route::patch('/workers/employees/{id}/toggle', [WorkerController::class, 'toggleEmployee'])
        ->middleware('permission:employees.toggle')
        ->where('id', '[0-9]+');

    // القصاصين - Cutters (CUTTER)
    Route::get('/workers/cutters', [WorkerController::class, 'cutters'])
        ->middleware('permission:cutters.view');
    Route::post('/workers/cutters', [WorkerController::class, 'storeCutter'])
        ->middleware('permission:cutters.create');
    Route::get('/workers/cutters/{id}/statement', [WorkerController::class, 'statementCutter'])
        ->middleware('permission:cutters.statement')
        ->where('id', '[0-9]+');
    Route::put('/workers/cutters/{id}', [WorkerController::class, 'updateCutter'])
        ->middleware('permission:cutters.update')
        ->where('id', '[0-9]+');
    Route::delete('/workers/cutters/{id}', [WorkerController::class, 'destroyCutter'])
        ->middleware('permission:cutters.delete')
        ->where('id', '[0-9]+');
    Route::patch('/workers/cutters/{id}/toggle', [WorkerController::class, 'toggleCutter'])
        ->middleware('permission:cutters.toggle')
        ->where('id', '[0-9]+');

    // الخياطين - Tailors (TAILOR)
    Route::get('/workers/tailors', [WorkerController::class, 'tailors'])
        ->middleware('permission:tailors.view');
    Route::post('/workers/tailors', [WorkerController::class, 'storeTailor'])
        ->middleware('permission:tailors.create');
    Route::get('/workers/tailors/{id}/statement', [WorkerController::class, 'statementTailor'])
        ->middleware('permission:tailors.statement')
        ->where('id', '[0-9]+');
    Route::put('/workers/tailors/{id}', [WorkerController::class, 'updateTailor'])
        ->middleware('permission:tailors.update')
        ->where('id', '[0-9]+');
    Route::delete('/workers/tailors/{id}', [WorkerController::class, 'destroyTailor'])
        ->middleware('permission:tailors.delete')
        ->where('id', '[0-9]+');
    Route::patch('/workers/tailors/{id}/toggle', [WorkerController::class, 'toggleTailor'])
        ->middleware('permission:tailors.toggle')
        ->where('id', '[0-9]+');
});

// ============================================================
// مسارات الحسابات - Accounts Management Routes
//
// تقسيم الصلاحية هنا على وزن الخطر: قراءة الهيكل `accounts.view`، وبنوده كتابةً
// `accounts.create/update`، وتعديل الشجرة نفسها (مجموعات جزئية) `accounts.structure_manage`،
// والكشوف `accounts.statement` لأنها تُخرج أرصدة كل المحفظة.
// The split follows risk: reading the tree is `accounts.view`, its rows are `create/update`,
// reshaping the hierarchy itself is `accounts.structure_manage`, and statements are
// `accounts.statement` because they expose every balance in the book.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // هيكل الشجرة المحاسبية / chart-of-accounts structure reads
    Route::get('/accounts/account', [AccountController::class, 'getAccounts'])
        ->middleware('permission:accounts.view');
    Route::get('/accounts/main', [AccountController::class, 'getAccMain'])
        ->middleware('permission:accounts.view');
    Route::get('/accounts/sub', [AccountController::class, 'getAccSub'])
        ->middleware('permission:accounts.view');
    Route::get('/accounts/sub-group', [AccountController::class, 'getAccSubGroup'])
        ->middleware('permission:accounts.view');
    Route::get('/accounts/tree', [AccountController::class, 'getAccountTree'])
        ->middleware('permission:accounts.view');

    // الحسابات المالية (CHART_ACC) / ledger accounts
    Route::get('/accounts/chart', [AccountController::class, 'getChartAccounts'])
        ->middleware('permission:accounts.view');
    Route::post('/accounts/chart', [AccountController::class, 'createChartAccount'])
        ->middleware('permission:accounts.create');
    Route::put('/accounts/chart/{accNo}', [AccountController::class, 'updateChartAccount'])
        ->middleware('permission:accounts.update')
        ->where('accNo', '[0-9]+');

    // مجموعات الحسابات الجزئية — تعديل هيكل لا محتوى
    // Account sub-groups — this reshapes the structure, not its contents
    Route::post('/accounts/sub-group', [AccountController::class, 'createSubGroup'])
        ->middleware('permission:accounts.structure_manage');
    Route::put('/accounts/sub-group/{groupId}', [AccountController::class, 'updateSubGroup'])
        ->middleware('permission:accounts.structure_manage')
        ->where('groupId', '[0-9]+');

    // كشوفات الحسابات / statements
    Route::get('/accounts/chart/{accNo}/statement', [AccountController::class, 'getAccountStatement'])
        ->middleware('permission:accounts.statement')
        ->where('accNo', '[0-9]+');
    Route::get('/accounts/statement-detail', [AccountController::class, 'getDetailedStatement'])
        ->middleware('permission:accounts.statement');
});

// ============================================================
// مسارات العملات وأسعار الصرف - Currency Routes
//
// دليل العملات شيء وأسعار صرفه اليومية شيء آخر: `currencies.*` للمفردات و`currencies.prices_*`
// للأسعار، لأن من يضيف عملة لا يملك بالتحديد تعديل سعر اليوم الذي تُقيَّم به كل الحركات.
// The currency directory and its daily FX rates are separate grants: `currencies.*` for the
// vocabulary and `currencies.prices_*` for the rates — adding a currency is not the same power as
// rewriting the rate every movement in the book is valued at.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/currencies', [CurrencyController::class, 'index'])
        ->middleware('permission:currencies.view');
    Route::post('/currencies', [CurrencyController::class, 'store'])
        ->middleware('permission:currencies.create');
    Route::put('/currencies/{curNo}', [CurrencyController::class, 'update'])
        ->middleware('permission:currencies.update')
        ->where('curNo', '[0-9]+');
    Route::delete('/currencies/{curNo}', [CurrencyController::class, 'destroy'])
        ->middleware('permission:currencies.delete')
        ->where('curNo', '[0-9]+');

    Route::get('/currencies/{curNo}/prices', [CurrencyController::class, 'getPrices'])
        ->middleware('permission:currencies.prices_view')
        ->where('curNo', '[0-9]+');
    Route::post('/currencies/{curNo}/prices', [CurrencyController::class, 'addPrice'])
        ->middleware('permission:currencies.prices_manage')
        ->where('curNo', '[0-9]+');
    Route::put('/currencies/{curNo}/prices/{seq}', [CurrencyController::class, 'updatePrice'])
        ->middleware('permission:currencies.prices_manage')
        ->where('curNo', '[0-9]+')->where('seq', '[0-9]+');
    Route::delete('/currencies/{curNo}/prices/{seq}', [CurrencyController::class, 'deletePrice'])
        ->middleware('permission:currencies.prices_manage')
        ->where('curNo', '[0-9]+')->where('seq', '[0-9]+');
});
