<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MyStatementController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Services\JobController;
use App\Http\Controllers\Services\ProviderController;
use App\Http\Controllers\Services\ServiceConfigController;
use App\Http\Controllers\Services\SmsController;
use App\Http\Controllers\Services\WhatsappController;
use App\Http\Controllers\SessionAdminController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SupportInquiryController;
use App\Http\Controllers\TableQueryController;
use App\Http\Controllers\TailoringController;
use App\Http\Controllers\UserAdminController;
use App\Http\Controllers\WorkerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// مسارات المصادقة وتسجيل الدخول
// Authentication & Login routes
Route::post('/auth/login', [AuthController::class, 'login']);

// مسار تسجيل الدخول بالبصمة (عام — بدون توكن)
Route::post('/auth/biometric/login', [AuthController::class, 'biometricLogin']);

// ============================================================
// تهيئة التطبيق العامة — تُقرأ قبل المصادقة لرسم شاشة الدخول بالهوية الصحيحة
// Public application configuration — read before auth to paint the login screen correctly.
//
// `GET /app-config` حمولة بلا سر (تستثني كلمة سر API)، و`verify-api-password` عامة ومقيّدة المعدل
// لأن شاشة الدخول قد تحتاج فتح إعدادات API بينما الرابط الحالي خاطئ (بلا توكن بعد).
// GET /app-config is a secret-free payload; verify-api-password is public and rate-limited because
// the login screen may need to unlock the API settings while the current URL is wrong (no token yet).
// ============================================================
Route::get('/app-config', [AppConfigController::class, 'publicConfig']);
Route::post('/app-config/verify-api-password', [AppConfigController::class, 'verifyApiPassword'])
    ->middleware('throttle:5,1');

// ============================================================
// استفسارات الدعم — الإرسال عام كي يعمل من شاشة «معلومات البرنامج والدعم» قبل الدخول
// Support inquiries — submission is public so it works from the app-info screen before login.
//
// التوكن يُقرأ اختيارياً داخل المتحكم (بلا وسيط `auth:sanctum`): تذكرة صاحب الجلسة تُربَط
// بحسابه، وتذكرة الزائر تُحفظ باسمه وهاتفه. المعدل 5/دقيقة لكل IP يكفي إنساناً ويكفي
// لكبح أي إغراق آلي على مسار يكتب في القاعدة.
// The token is read optionally inside the controller (no `auth:sanctum` middleware): a signed-in
// submitter's ticket binds to the account, a guest's ticket keeps the typed name and phone. The
// 5-per-minute throttle is generous for a human and still caps automated flooding of a route
// that writes to the database.
// ============================================================
Route::post('/support/inquiries', [SupportInquiryController::class, 'store'])
    ->middleware('throttle:5,1');

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

// ============================================================
// كتابة تهيئة التطبيق وكلمة سر إعدادات API (خلف auth + permission)
// Writing app configuration and the API-settings password (behind auth + permission).
//
// العلامة التجارية خلف `app_config.update`، وكلمة سر API المقفلة خلف `app_config.api_manage` الأرفع:
// من يعدّل الألوان لا يملك بالضرورة تغيير كلمة سر اتصال الخادم.
// Branding sits behind app_config.update; the locked API password behind the higher app_config.api_manage:
// whoever edits the colors does not necessarily hold the power to change the server-connection password.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/app-config', [AppConfigController::class, 'update'])
        ->middleware('permission:app_config.update');
    Route::put('/app-config/api-password', [AppConfigController::class, 'updateApiPassword'])
        ->middleware('permission:app_config.api_manage');
});

// ============================================================
// مسارات الكشوفات الشخصية — مالي وعمل — مقصورة على المستخدم الحالي
// Personal statements — financial and work — scoped to the authenticated user only.
//
// لا تقبل أي بارامتر حساب: تُقرأ ACC_NO من صف المستخدم المصادق عليه حصراً.
// Accepts no account parameter: it reads ACC_NO from the authenticated user row exclusively.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/my-statement/financial', [MyStatementController::class, 'financialStatement'])
        ->middleware('permission:my_statement.financial');
    Route::get('/my-statement/work', [MyStatementController::class, 'workStatement'])
        ->middleware('permission:my_statement.work');
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
// مسارات التفصيل - Tailoring Routes (قراءة فقط)
// Read-only, because cutting bills are authored in the legacy ERP.
//
// هوية الفاتورة مركّبة: (BILL_NO, YEAR) — وكل مسار هنا يحمل الرقمين، لأن BILL_NO وحده غير
// فريد في SALES_BILL ويعيد فاتورة سنة أخرى.
// The bill identity is the composite (BILL_NO, YEAR); BILL_NO alone is not unique and would
// return another year's bill.
//
// المرجعيات تخدم شاشة الاستعراض (view) ونموذج الإضافة (create) معاً، فتُفتح للصلاحيتين.
// Reference data serves both the view screen and the add form, so either grant opens it.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tailoring/reference', [TailoringController::class, 'reference'])
        ->middleware('permission:tailoring.view,tailoring.create');
    Route::get('/tailoring/bills', [TailoringController::class, 'index'])
        ->middleware('permission:tailoring.view');
    Route::get('/tailoring/bills/{billNo}/{year}/movements', [TailoringController::class, 'movements'])
        ->middleware('permission:tailoring.view')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::post('/tailoring/bills/{billNo}/{year}/activity', [TailoringController::class, 'activity'])
        ->middleware('permission:tailoring.print,tailoring.export')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::get('/tailoring/bills/{billNo}/{year}', [TailoringController::class, 'show'])
        ->middleware('permission:tailoring.view')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
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
        ->middleware('permission:employees.statement_financial')
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
        ->middleware('permission:cutters.statement_financial')
        ->where('id', '[0-9]+');
    Route::get('/workers/cutters/{id}/work-report', [WorkerController::class, 'workReportCutter'])
        ->middleware('permission:cutters.statement_work')
        ->where('id', '[0-9]+');
    Route::get('/workers/cutters/group-work-report', [WorkerController::class, 'groupWorkReportCutter'])
        ->middleware('permission:cutters.group_work_report');
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
        ->middleware('permission:tailors.statement_financial')
        ->where('id', '[0-9]+');
    Route::get('/workers/tailors/{id}/work-report', [WorkerController::class, 'workReportTailor'])
        ->middleware('permission:tailors.statement_work')
        ->where('id', '[0-9]+');
    Route::get('/workers/tailors/group-work-report', [WorkerController::class, 'groupWorkReportTailor'])
        ->middleware('permission:tailors.group_work_report');
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
// ولكل كشف مالي صلاحية مستقلة: `statement_financial` / `statement_group` / `statement_detail`.
// The split follows risk: reading the tree is `accounts.view`, its rows are `create/update`,
// reshaping the hierarchy itself is `accounts.structure_manage`, and each financial statement has
// its own grant: `statement_financial` / `statement_group` / `statement_detail`.
//
// القوائم المساعدة (اختر الحساب/التسلسل الهرمي) تُفتح بـ «أيٍّ من» العرض أو صلاحية الكشف التي
// تحتاجها، حتى لا تظهر شاشة الكشف لحامل صلاحية كشف وحده بقوائم اختيار فارغة (403 صامت).
// Helper/picker reads accept ANY-of (view OR the statement that needs them), so a statement-only
// user never opens a statement screen with silently-empty pickers.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // هيكل الشجرة المحاسبية / chart-of-accounts structure reads
    Route::get('/accounts/account', [AccountController::class, 'getAccounts'])
        ->middleware('permission:accounts.view,accounts.structure_manage,accounts.statement_group,accounts.statement_detail');
    Route::get('/accounts/main', [AccountController::class, 'getAccMain'])
        ->middleware('permission:accounts.view,accounts.structure_manage,accounts.statement_group,accounts.statement_detail');
    Route::get('/accounts/sub', [AccountController::class, 'getAccSub'])
        ->middleware('permission:accounts.view,accounts.structure_manage,accounts.statement_group,accounts.statement_detail');
    Route::get('/accounts/sub-group', [AccountController::class, 'getAccSubGroup'])
        ->middleware('permission:accounts.view,accounts.structure_manage,accounts.statement_group,accounts.statement_detail');
    Route::get('/accounts/tree', [AccountController::class, 'getAccountTree'])
        ->middleware('permission:accounts.view,accounts.structure_manage,accounts.statement_group,accounts.statement_detail');
    // أبناء عقدة هيكلية محمّلون عند التوسيع — نفس قراءة الشجرة لأنهم جزء منها
    // Lazy children of one structural node: same grant as the tree, since they are the tree.
    Route::get('/accounts/tree/children', [AccountController::class, 'getTreeChildren'])
        ->middleware('permission:accounts.view,accounts.structure_manage,accounts.statement_group,accounts.statement_detail');
    // بحث عميق في كل المستويات بلا تحميل الشجرة / deep search without walking the tree
    Route::get('/accounts/search', [AccountController::class, 'searchAccounts'])
        ->middleware('permission:accounts.view,accounts.structure_manage,accounts.statement_group,accounts.statement_detail');

    // تصدير الشجرة مسطّح — يطالب الخادم بكل المستويات المختارة بدل ما عرضه الشاشة
    // Flat tree export: the server answers for the selected levels. The lazy screen only
    // holds the branches the user actually opened, so exporting from it printed headers
    // only. Gated by the export/print grants because those are exactly the buttons that
    // call it — a read-only user never sees the menu.
    Route::get('/accounts/export-tree', [AccountController::class, 'exportTree'])
        ->middleware('permission:accounts.export,accounts.print');

    // دليل العملات لنموذج الحساب — صلاحيات الحسابات لا العملات، فالاستعلام يتبع النموذج
    // Currency picker for the account form. It cannot reuse /currencies (gated by
    // currencies.view, which an accounts-only user does not hold), or the form would
    // show an empty dropdown while asking the user to choose a currency.
    Route::get('/accounts/currencies', [AccountController::class, 'getCurrencyOptions'])
        ->middleware('permission:accounts.view,accounts.create,accounts.update,accounts.structure_manage');

    // معاينة الرقم المتوقع قبل الإنشاء — نفس قواعد create* لا نسخة في العميل
    // Number preview for the create form: the server owns the legacy numbering rules.
    Route::get('/accounts/next-number', [AccountController::class, 'nextAccountNumber'])
        ->middleware('permission:accounts.view,accounts.create,accounts.structure_manage');

    // نقطة بيانات مفردة لملء شاشة التعديل / single account payload for the edit screen
    Route::get('/accounts/chart/{accNo}', [AccountController::class, 'getChartAccountDetail'])
        ->middleware('permission:accounts.view,accounts.statement_financial,accounts.update')
        ->where('accNo', '[0-9]+');

    // الحسابات المالية (CHART_ACC) / ledger accounts
    // قائمة الحسابات تُستخدم في منتقي «كشف حساب مالي»، فتقبل صلاحية الكشف أيضاً
    // The chart list powers the financial-statement picker, so it also accepts that grant.
    Route::get('/accounts/chart', [AccountController::class, 'getChartAccounts'])
        ->middleware('permission:accounts.view,accounts.statement_financial');
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

    // فروع وأجزاء الهيكل — إعادة تشكيل للشجرة نفسها لا لمحتواها
    // Branch (ACC_MAIN) and part (ACC_SUB) nodes: reshaping the hierarchy itself,
    // hence `accounts.structure_manage` rather than the ordinary create/update rows.
    Route::post('/accounts/main', [AccountController::class, 'createAccMain'])
        ->middleware('permission:accounts.structure_manage');
    Route::put('/accounts/main/{accSub}', [AccountController::class, 'updateAccMain'])
        ->middleware('permission:accounts.structure_manage')
        ->where('accSub', '[0-9]+');
    Route::post('/accounts/sub', [AccountController::class, 'createAccSub'])
        ->middleware('permission:accounts.structure_manage');
    Route::put('/accounts/sub/{accPart}', [AccountController::class, 'updateAccSub'])
        ->middleware('permission:accounts.structure_manage')
        ->where('accPart', '[0-9]+');
    Route::put('/accounts/account/{accNo}', [AccountController::class, 'updateAccount'])
        ->middleware('permission:accounts.structure_manage')
        ->where('accNo', '[0-9]+');

    // كشوفات الحسابات / statements — لكل كشف صلاحيته الدقيقة
    // Each statement carries its own precise grant.
    Route::get('/accounts/chart/{accNo}/statement', [AccountController::class, 'getAccountStatement'])
        ->middleware('permission:accounts.statement_financial')
        ->where('accNo', '[0-9]+');
    Route::get('/accounts/statement-detail', [AccountController::class, 'getDetailedStatement'])
        ->middleware('permission:accounts.statement_detail');
    Route::get('/accounts/group-statement', [AccountController::class, 'getGroupStatement'])
        ->middleware('permission:accounts.statement_group');
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

// ============================================================
// مسارات قسم «الخدمات والمزودين» — مزودون/رسائل/واتساب/إعدادات/مهام
// Services & Providers section routes — providers / sms / whatsapp / config / jobs
//
// كل مسار خلف `auth:sanctum` + `permission:<code>`. الفصل بدقة «لكل قسم ولكل عملية»: التحكم
// بالمهام وإظهار بيانات اعتماد المزودين أخطر من مجرد عرض القسم، لهما أكواد مستقلة.
// Every route sits behind auth:sanctum + permission:<code>, split per section and per operation:
// controlling scheduler jobs or revealing provider credentials is far more dangerous than merely
// viewing the section, so each carries its own code.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // المزودون / Providers (PROVIDERS + PROVIDERS_DTL)
    Route::get('/providers', [ProviderController::class, 'index'])
        ->middleware('permission:providers.view');
    Route::post('/providers', [ProviderController::class, 'store'])
        ->middleware('permission:providers.create');
    Route::get('/providers/{id}', [ProviderController::class, 'show'])
        ->middleware('permission:providers.view')
        ->where('id', '[0-9]+');
    Route::put('/providers/{id}', [ProviderController::class, 'update'])
        ->middleware('permission:providers.update')
        ->where('id', '[0-9]+');
    Route::patch('/providers/{id}/toggle', [ProviderController::class, 'toggle'])
        ->middleware('permission:providers.toggle')
        ->where('id', '[0-9]+');
    Route::delete('/providers/{id}', [ProviderController::class, 'destroy'])
        ->middleware('permission:providers.delete')
        ->where('id', '[0-9]+');

    // اشتراكات المزود / provider subscriptions (PROVIDERS_DTL)
    Route::get('/providers/{id}/subscriptions', [ProviderController::class, 'subscriptions'])
        ->middleware('permission:providers.view')
        ->where('id', '[0-9]+');
    Route::post('/providers/{id}/subscriptions', [ProviderController::class, 'storeSubscription'])
        ->middleware('permission:providers.subscriptions_manage')
        ->where('id', '[0-9]+');
    Route::put('/provider-subscriptions/{dtlId}', [ProviderController::class, 'updateSubscription'])
        ->middleware('permission:providers.subscriptions_manage')
        ->where('dtlId', '[0-9]+');
    Route::delete('/provider-subscriptions/{dtlId}', [ProviderController::class, 'deleteSubscription'])
        ->middleware('permission:providers.subscriptions_manage')
        ->where('dtlId', '[0-9]+');

    // الرسائل النصية / SMS (MSG_QUEUE + MSG_ARCHIVE)
    Route::get('/sms/messages', [SmsController::class, 'index'])
        ->middleware('permission:sms.view');
    Route::post('/sms/messages', [SmsController::class, 'store'])
        ->middleware('permission:sms.create');
    Route::get('/sms/messages/{id}', [SmsController::class, 'show'])
        ->middleware('permission:sms.view')
        ->where('id', '[0-9]+');
    Route::put('/sms/messages/{id}', [SmsController::class, 'update'])
        ->middleware('permission:sms.update')
        ->where('id', '[0-9]+');
    Route::patch('/sms/messages/{id}/toggle', [SmsController::class, 'toggle'])
        ->middleware('permission:sms.toggle')
        ->where('id', '[0-9]+');
    Route::delete('/sms/messages/{id}', [SmsController::class, 'destroy'])
        ->middleware('permission:sms.delete')
        ->where('id', '[0-9]+');

    // رسائل واتساب / WhatsApp (W_MSG_QUEUE + W_MSG_ARCHIVE)
    Route::get('/whatsapp/messages', [WhatsappController::class, 'index'])
        ->middleware('permission:whatsapp.view');
    Route::post('/whatsapp/messages', [WhatsappController::class, 'store'])
        ->middleware('permission:whatsapp.create');
    Route::get('/whatsapp/messages/{id}', [WhatsappController::class, 'show'])
        ->middleware('permission:whatsapp.view')
        ->where('id', '[0-9]+');
    Route::put('/whatsapp/messages/{id}', [WhatsappController::class, 'update'])
        ->middleware('permission:whatsapp.update')
        ->where('id', '[0-9]+');
    Route::patch('/whatsapp/messages/{id}/toggle', [WhatsappController::class, 'toggle'])
        ->middleware('permission:whatsapp.toggle')
        ->where('id', '[0-9]+');
    Route::delete('/whatsapp/messages/{id}', [WhatsappController::class, 'destroy'])
        ->middleware('permission:whatsapp.delete')
        ->where('id', '[0-9]+');

    // إعدادات الخدمات / service config (CORP + SERVICES)
    Route::get('/service-config', [ServiceConfigController::class, 'show'])
        ->middleware('permission:services.view');
    Route::put('/service-config/corp', [ServiceConfigController::class, 'updateCorp'])
        ->middleware('permission:services.settings_manage');
    Route::post('/services', [ServiceConfigController::class, 'storeService'])
        ->middleware('permission:services.update');
    Route::put('/services/{id}', [ServiceConfigController::class, 'updateService'])
        ->middleware('permission:services.update')
        ->where('id', '[0-9]+');
    Route::delete('/services/{id}', [ServiceConfigController::class, 'destroyService'])
        ->middleware('permission:services.update')
        ->where('id', '[0-9]+');

    // المهام الزمنية / scheduler jobs (DBMS_SCHEDULER)
    Route::get('/jobs', [JobController::class, 'index'])
        ->middleware('permission:jobs.view');
    Route::get('/jobs/{name}/history', [JobController::class, 'history'])
        ->middleware('permission:jobs.view');
    Route::post('/jobs', [JobController::class, 'store'])
        ->middleware('permission:jobs.create');
    Route::put('/jobs/{name}', [JobController::class, 'update'])
        ->middleware('permission:jobs.update');
    Route::patch('/jobs/{name}/toggle', [JobController::class, 'toggle'])
        ->middleware('permission:jobs.toggle');
    Route::delete('/jobs/{name}', [JobController::class, 'destroy'])
        ->middleware('permission:jobs.delete');

    // استفسارات الدعم: القراءة والرد في لوحة الإدارة (الإرسال العام في أعلى الملف)
    // Support inquiries: reading and replying in the management dashboard (public submit is at the top)
    Route::get('/support/inquiries', [SupportInquiryController::class, 'index'])
        ->middleware('permission:support.view');
    Route::put('/support/inquiries/{ticketId}', [SupportInquiryController::class, 'reply'])
        ->middleware('permission:support.update')
        ->where('ticketId', '[0-9]+');
});
