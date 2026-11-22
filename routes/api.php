<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerMeasurementController;
use App\Http\Controllers\Finance\FinanceReferenceController;
use App\Http\Controllers\Finance\FinancialSearchController;
use App\Http\Controllers\Finance\JournalEntryController;
use App\Http\Controllers\Finance\TempEntryController;
use App\Http\Controllers\Finance\VoucherController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MyStatementController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Purchases\PurchaseInvoiceController;
use App\Http\Controllers\Purchases\PurchaseReportController;
use App\Http\Controllers\Purchases\PurchaseReturnController;
use App\Http\Controllers\Purchases\SupplierController;
use App\Http\Controllers\Sales\SalesInvoiceController;
use App\Http\Controllers\Sales\SalesReportController;
use App\Http\Controllers\Sales\SalesReturnController;
use App\Http\Controllers\Services\JobController;
use App\Http\Controllers\Services\ProviderController;
use App\Http\Controllers\Services\ServiceConfigController;
use App\Http\Controllers\Services\SmsController;
use App\Http\Controllers\Services\WhatsappController;
use App\Http\Controllers\SessionAdminController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Stores\IssueOrderController;
use App\Http\Controllers\Stores\ReceiveOrderController;
use App\Http\Controllers\Stores\StockSheetController;
use App\Http\Controllers\Stores\StoreCatalogController;
use App\Http\Controllers\Stores\StoreController;
use App\Http\Controllers\Stores\StoreItemController;
use App\Http\Controllers\Stores\StoreReportController;
use App\Http\Controllers\Stores\TransferOrderController;
use App\Http\Controllers\SupportInquiryController;
use App\Http\Controllers\TableQueryController;
use App\Http\Controllers\Tailoring\TailoringCreateController;
use App\Http\Controllers\Tailoring\TailoringLookupController;
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

    // سجل النشاط العام وخيارات الفلترة: مساران ثابتان، يُسجَّلان قبل `/users/{userId}`
    // General audit trail and its filter options. Literal segments, registered ahead of /users/{id}.
    Route::get('/activity', [UserAdminController::class, 'activity'])
        ->middleware('permission:users.activity_view');

    Route::get('/activity/filters', [UserAdminController::class, 'activityFilters'])
        ->middleware('permission:users.activity_view');

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
    // خيارات الفلترة (facets): الأنواع والسنوات والفروع المميزة — تُملأ منها القوائم المنسدلة.
    // Filter options (facets): distinct kinds, years and branches — they fill the dropdowns.
    Route::get('/tailoring/bills/filters', [TailoringController::class, 'filterOptions'])
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
// مسارات إنشاء فاتورة التفصيل - Tailoring creation routes
// كلها تحت صلاحية tailoring.create وحدها (لا customers.view ولا stores.*) — الخادم هو من
// يقرأ العملاء والأقمشة والمقاسات السابقة ويقرر، فالإنشاء لا يحتاج منحاً أوسع.
// All under the tailoring.create grant alone (no customers.view, no stores.*) — the server
// reads customers, fabrics and previous models itself, so creation needs no broader grant.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tailoring/create/reference', [TailoringLookupController::class, 'reference'])
        ->middleware('permission:tailoring.create');
    Route::get('/tailoring/create/next-numbers', [TailoringLookupController::class, 'nextNumbers'])
        ->middleware('permission:tailoring.create');
    Route::get('/tailoring/create/customers', [TailoringLookupController::class, 'customers'])
        ->middleware('permission:tailoring.create');
    Route::get('/tailoring/create/customers/{custNo}/phones', [TailoringLookupController::class, 'phones'])
        ->middleware('permission:tailoring.create')
        ->where(['custNo' => '[0-9]+']);
    Route::get('/tailoring/create/customers/{custNo}/previous-models', [TailoringLookupController::class, 'previousModels'])
        ->middleware('permission:tailoring.create')
        ->where(['custNo' => '[0-9]+']);
    Route::get('/tailoring/create/customers/{custNo}/previous-models/{billNo}/{year}/{seqNo}', [TailoringLookupController::class, 'previousModelDetail'])
        ->middleware('permission:tailoring.create')
        ->where(['custNo' => '[0-9]+', 'billNo' => '[0-9]+', 'year' => '[0-9]{4}', 'seqNo' => '[0-9]+']);
    Route::get('/tailoring/create/customers/{custNo}/beneficiaries', [TailoringLookupController::class, 'beneficiaries'])
        ->middleware('permission:tailoring.create')
        ->where(['custNo' => '[0-9]+']);
    Route::get('/tailoring/create/workers', [TailoringLookupController::class, 'workers'])
        ->middleware('permission:tailoring.create');
    Route::get('/tailoring/create/fabrics', [TailoringLookupController::class, 'fabrics'])
        ->middleware('permission:tailoring.create');
    Route::get('/tailoring/create/fabrics/{sanfNo}/variants', [TailoringLookupController::class, 'fabricVariants'])
        ->middleware('permission:tailoring.create')
        ->where(['sanfNo' => '[0-9]+']);
    Route::post('/tailoring/create/quote', [TailoringCreateController::class, 'quote'])
        ->middleware('permission:tailoring.create');
    Route::post('/tailoring/create/bills', [TailoringCreateController::class, 'store'])
        ->middleware('permission:tailoring.create');
});

// ============================================================
// مسارات المبيعات - Sales Routes (المرحلة أ: قراءة + تقارير + نشاط)
// Read-only surface: invoices, returns and reports. Writes land in a later phase.
//
// هوية الفاتورة والمردود مركّبة: (رقم، سنة). المسارات الحرفية (filters) تُسجَّل قبل {param}.
// The invoice/return identity is composite (number, year); literal paths precede {param} ones.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // مراجع النموذجين: الأنواع والحسابات الافتراضية والمخازن والعملات (قراءة قبل الكتابة)
    // Form references for both documents (read before writing)
    Route::get('/sales/reference', [SalesInvoiceController::class, 'reference'])
        ->middleware('permission:sales.view,sales.create,sales.returns_create');

    // بحث الأصناف والعملاء لنموذجَي الإنشاء — بصلاحيات المبيعات لا صلاحيات المخازن/العملاء
    // Item and customer lookups for the two forms — gated by sales grants, not stores/customers.
    Route::get('/sales/items', [SalesInvoiceController::class, 'items'])
        ->middleware('permission:sales.view,sales.create,sales.returns_create');
    Route::get('/sales/customers', [SalesInvoiceController::class, 'customers'])
        ->middleware('permission:sales.view,sales.create,sales.returns_create');

    // فواتير المبيعات / sales invoices
    Route::get('/sales/invoices/filters', [SalesInvoiceController::class, 'filterOptions'])
        ->middleware('permission:sales.view');
    Route::get('/sales/invoices', [SalesInvoiceController::class, 'index'])
        ->middleware('permission:sales.view');
    // الكتابة: إنشاء/تعديل/حذف — المرحلة ب
    // Writes: create / update / delete — phase B
    Route::post('/sales/invoices', [SalesInvoiceController::class, 'store'])
        ->middleware('permission:sales.create');
    Route::put('/sales/invoices/{billNo}/{year}', [SalesInvoiceController::class, 'update'])
        ->middleware('permission:sales.update')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::delete('/sales/invoices/{billNo}/{year}', [SalesInvoiceController::class, 'destroy'])
        ->middleware('permission:sales.delete')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::post('/sales/invoices/{billNo}/{year}/activity', [SalesInvoiceController::class, 'activity'])
        ->middleware('permission:sales.print,sales.export')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::get('/sales/invoices/{billNo}/{year}', [SalesInvoiceController::class, 'show'])
        ->middleware('permission:sales.view')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);

    // مردودات المبيعات / sales returns
    Route::get('/sales/returns/filters', [SalesReturnController::class, 'filterOptions'])
        ->middleware('permission:sales.returns_view');
    Route::get('/sales/returns', [SalesReturnController::class, 'index'])
        ->middleware('permission:sales.returns_view');
    Route::post('/sales/returns', [SalesReturnController::class, 'store'])
        ->middleware('permission:sales.returns_create');
    Route::put('/sales/returns/{returnNo}/{year}', [SalesReturnController::class, 'update'])
        ->middleware('permission:sales.returns_update')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::delete('/sales/returns/{returnNo}/{year}', [SalesReturnController::class, 'destroy'])
        ->middleware('permission:sales.returns_delete')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::post('/sales/returns/{returnNo}/{year}/activity', [SalesReturnController::class, 'activity'])
        ->middleware('permission:sales.returns_print,sales.returns_export')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::get('/sales/returns/{returnNo}/{year}', [SalesReturnController::class, 'show'])
        ->middleware('permission:sales.returns_view')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);

    // تقارير المبيعات / sales reports
    Route::get('/sales/reports', [SalesReportController::class, 'report'])
        ->middleware('permission:sales.reports_view');
    Route::post('/sales/reports/activity', [SalesReportController::class, 'activity'])
        ->middleware('permission:sales.reports_print,sales.reports_export');
});

// ============================================================
// مسارات القيود والسندات - Entries & Vouchers Routes
// القيود المرحلة (journal) والمؤقتة (journal_temp) وحدتان منفصلتان، والقبض (receipts) غير الصرف (payments).
// Posted and temporary entries are separate modules; receipts and payments are separate too.
// المسارات الحرفية تُسجَّل قبل {param}. / Literal paths precede {param} ones.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    $financeView = 'permission:journal.view,journal_temp.view,receipts.view,payments.view,fin_search.view,'
        .'journal.create,journal.closing_create,journal_temp.create,receipts.create,payments.create';
    $financeWrite = 'permission:journal.create,journal.update,journal.closing_create,journal_temp.create,'
        .'journal_temp.update,receipts.create,receipts.update,payments.create,payments.update';

    // المراجع والبحث عن حساب/عميل للنماذج / references and form lookups
    Route::get('/finance/reference', [FinanceReferenceController::class, 'reference'])->middleware($financeView);
    Route::get('/finance/accounts', [FinanceReferenceController::class, 'accounts'])->middleware($financeWrite);
    Route::get('/finance/customers', [FinanceReferenceController::class, 'customers'])->middleware($financeWrite);
    Route::post('/finance/activity', [FinanceReferenceController::class, 'listActivity'])
        ->middleware('permission:journal.print,journal.export,journal_temp.print,journal_temp.export,receipts.print,receipts.export,payments.print,payments.export');

    // البحث عن عملية مالية / financial-transaction search
    Route::get('/finance/search', [FinancialSearchController::class, 'search'])->middleware('permission:fin_search.view');

    // القيود المرحلة / posted entries
    Route::get('/finance/entries', [JournalEntryController::class, 'index'])->middleware('permission:journal.view');
    Route::post('/finance/entries', [JournalEntryController::class, 'store'])
        ->middleware('permission:journal.create,journal.closing_create');
    Route::post('/finance/entries/{entryNo}/activity', [JournalEntryController::class, 'activity'])
        ->middleware('permission:journal.print,journal.export')->where('entryNo', '[0-9]+');
    Route::get('/finance/entries/{entryNo}', [JournalEntryController::class, 'show'])
        ->middleware('permission:journal.view')->where('entryNo', '[0-9]+');
    Route::put('/finance/entries/{entryNo}', [JournalEntryController::class, 'update'])
        ->middleware('permission:journal.update')->where('entryNo', '[0-9]+');
    Route::delete('/finance/entries/{entryNo}', [JournalEntryController::class, 'destroy'])
        ->middleware('permission:journal.delete')->where('entryNo', '[0-9]+');

    // القيود المؤقتة / temporary entries
    Route::get('/finance/temp-entries', [TempEntryController::class, 'index'])->middleware('permission:journal_temp.view');
    Route::post('/finance/temp-entries', [TempEntryController::class, 'store'])->middleware('permission:journal_temp.create');
    Route::post('/finance/temp-entries/{entryNo}/post', [TempEntryController::class, 'post'])
        ->middleware('permission:journal_temp.post')->where('entryNo', '[0-9]+');
    Route::post('/finance/temp-entries/{entryNo}/activity', [TempEntryController::class, 'activity'])
        ->middleware('permission:journal_temp.print,journal_temp.export')->where('entryNo', '[0-9]+');
    Route::get('/finance/temp-entries/{entryNo}', [TempEntryController::class, 'show'])
        ->middleware('permission:journal_temp.view')->where('entryNo', '[0-9]+');
    Route::put('/finance/temp-entries/{entryNo}', [TempEntryController::class, 'update'])
        ->middleware('permission:journal_temp.update')->where('entryNo', '[0-9]+');
    Route::delete('/finance/temp-entries/{entryNo}', [TempEntryController::class, 'destroy'])
        ->middleware('permission:journal_temp.delete')->where('entryNo', '[0-9]+');

    // سندات القبض والصرف — الاتجاه من الـ defaults والمصدر (cash/bank) من المسار
    // Receipt and payment vouchers — direction from route defaults, source from the path.
    foreach (['receipts' => 'receipt', 'payments' => 'payment'] as $module => $direction) {
        $voucher = ['source' => 'cash|bank', 'no' => '[0-9]+', 'year' => '[0-9]{4}'];

        Route::get("/finance/{$module}", [VoucherController::class, 'index'])
            ->middleware("permission:{$module}.view")->defaults('direction', $direction);
        Route::post("/finance/{$module}", [VoucherController::class, 'store'])
            ->middleware("permission:{$module}.create")->defaults('direction', $direction);
        Route::post("/finance/{$module}/{source}/{no}/{year}/activity", [VoucherController::class, 'activity'])
            ->middleware("permission:{$module}.print,{$module}.export")->defaults('direction', $direction)->where($voucher);
        Route::get("/finance/{$module}/{source}/{no}/{year}", [VoucherController::class, 'show'])
            ->middleware("permission:{$module}.view")->defaults('direction', $direction)->where($voucher);
        Route::put("/finance/{$module}/{source}/{no}/{year}", [VoucherController::class, 'update'])
            ->middleware("permission:{$module}.update")->defaults('direction', $direction)->where($voucher);
        Route::delete("/finance/{$module}/{source}/{no}/{year}", [VoucherController::class, 'destroy'])
            ->middleware("permission:{$module}.delete")->defaults('direction', $direction)->where($voucher);
    }
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
    // مقاسات العملاء — وحدة قراءة مستقلة الصلاحية داخل قسم العملاء، تُسجَّل قبل
    // `/customers/{custNo}` كي لا يفسّر المسار العام كلمة `measurements` رقم عميل.
    // Customer measurements — a separately-granted read inside the customers module, registered
    // before `/customers/{custNo}` so the generic route never reads `measurements` as a number.
    Route::get('/customers/measurements', [CustomerMeasurementController::class, 'index'])
        ->middleware('permission:customers.measurements_view');
    Route::get('/customers/measurements/{custNo}/models', [CustomerMeasurementController::class, 'models'])
        ->middleware('permission:customers.measurements_view')
        ->where('custNo', '[0-9]+');
    Route::get('/customers/measurements/{custNo}/models/{billNo}/{year}/{seqNo}', [CustomerMeasurementController::class, 'show'])
        ->middleware('permission:customers.measurements_view')
        ->where(['custNo' => '[0-9]+', 'billNo' => '[0-9]+', 'year' => '[0-9]{4}', 'seqNo' => '[0-9]+']);
    Route::post('/customers/measurements/{custNo}/models/{billNo}/{year}/{seqNo}/activity', [CustomerMeasurementController::class, 'activity'])
        ->middleware('permission:customers.measurements_print,customers.measurements_export')
        ->where(['custNo' => '[0-9]+', 'billNo' => '[0-9]+', 'year' => '[0-9]{4}', 'seqNo' => '[0-9]+']);
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
    // احتساب الأجور (wages.view) يعرض الكشف نفسه / wage calculation reuses this report
    Route::get('/workers/cutters/group-work-report', [WorkerController::class, 'groupWorkReportCutter'])
        ->middleware('permission:cutters.group_work_report,wages.view');
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
        ->middleware('permission:tailors.group_work_report,wages.view');
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

    // ============================================================
    // المخازن / Stores
    // ============================================================
    // ترتيب التسجيل مقصود: المسارات الحرفية أولاً (keepers · reference · catalogs · items ·
    // balances · reports · stock-sheet) ثم مسارات `{kind}` المحصورة، وإلا التهم `{kind}`
    // المسار الحرفي.
    // Registration order matters: literal paths first, then the constrained {kind} routes —
    // otherwise the wildcard swallows the literal path.

    // المخازن / store registry
    Route::get('/stores', [StoreController::class, 'index'])->middleware('permission:stores.view');
    Route::post('/stores', [StoreController::class, 'store'])->middleware('permission:stores.create');
    Route::put('/stores/{storeNo}', [StoreController::class, 'update'])
        ->middleware('permission:stores.update')->where('storeNo', '[0-9]+');
    Route::delete('/stores/{storeNo}', [StoreController::class, 'destroy'])
        ->middleware('permission:stores.delete')->where('storeNo', '[0-9]+');

    // أمناء المخازن / store keepers
    Route::get('/stores/keepers', [StoreController::class, 'keepers'])
        ->middleware('permission:stores.keepers_view');
    Route::post('/stores/keepers', [StoreController::class, 'storeKeeper'])
        ->middleware('permission:stores.keepers_manage');
    Route::put('/stores/keepers/{empNo}', [StoreController::class, 'updateKeeper'])
        ->middleware('permission:stores.keepers_manage')->where('empNo', '[0-9]+');
    Route::delete('/stores/keepers/{empNo}', [StoreController::class, 'destroyKeeper'])
        ->middleware('permission:stores.keepers_manage')->where('empNo', '[0-9]+');

    // المراجع المشتركة بين كل شاشات القسم / shared reference payload
    Route::get('/stores/reference', [StoreReportController::class, 'reference'])
        ->middleware('permission:stores.view,stores.items_view,stores.reports_view,stores.stocktake_view');

    // بحث الحساب لمُنتقي «حساب المخزن / حساب دائن» في نموذج المستند — 30 صفاً بلا أرصدة
    // Account lookup for the document form's pickers — 30 rows, no balances exposed.
    Route::get('/stores/accounts', [StoreReportController::class, 'accounts'])
        ->middleware('permission:stores.view,stores.receiving_view,stores.issuing_view,stores.transfer_view');

    // المرجعيات: مجموعات · أنواع · فئات · وحدات / catalogs
    Route::get('/stores/catalogs/{kind}', [StoreCatalogController::class, 'index'])
        ->middleware('permission:stores.catalogs_view,stores.catalogs_manage');
    Route::post('/stores/catalogs/{kind}', [StoreCatalogController::class, 'store'])
        ->middleware('permission:stores.catalogs_manage');
    Route::put('/stores/catalogs/{kind}/{id}', [StoreCatalogController::class, 'update'])
        ->middleware('permission:stores.catalogs_manage')->where('id', '[0-9]+');
    Route::delete('/stores/catalogs/{kind}/{id}', [StoreCatalogController::class, 'destroy'])
        ->middleware('permission:stores.catalogs_manage')->where('id', '[0-9]+');

    // الأصناف / items
    Route::get('/stores/items', [StoreItemController::class, 'index'])
        ->middleware('permission:stores.items_view');
    // تبويب الأصناف الفرعية — مسار حرفي قبل `{sanfNo}` حتى لا يُطابق كرقم
    // The sub-items tab — a literal path registered before {sanfNo} so it is never read as an id.
    Route::get('/stores/items/sub', [StoreItemController::class, 'subIndex'])
        ->middleware('permission:stores.items_view');
    Route::post('/stores/items', [StoreItemController::class, 'store'])
        ->middleware('permission:stores.items_create');
    Route::get('/stores/items/{sanfNo}', [StoreItemController::class, 'show'])
        ->middleware('permission:stores.items_view')->where('sanfNo', '[0-9]+');
    Route::put('/stores/items/{sanfNo}', [StoreItemController::class, 'update'])
        ->middleware('permission:stores.items_update')->where('sanfNo', '[0-9]+');
    Route::delete('/stores/items/{sanfNo}', [StoreItemController::class, 'destroy'])
        ->middleware('permission:stores.items_delete')->where('sanfNo', '[0-9]+');
    Route::post('/stores/items/{sanfNo}/sub', [StoreItemController::class, 'storeSub'])
        ->middleware('permission:stores.items_create')->where('sanfNo', '[0-9]+');
    Route::put('/stores/items/{sanfNo}/sub/{sanfDno}', [StoreItemController::class, 'updateSub'])
        ->middleware('permission:stores.items_update')
        ->where('sanfNo', '[0-9]+')->where('sanfDno', '[0-9]+');
    Route::delete('/stores/items/{sanfNo}/sub/{sanfDno}', [StoreItemController::class, 'destroySub'])
        ->middleware('permission:stores.items_delete')
        ->where('sanfNo', '[0-9]+')->where('sanfDno', '[0-9]+');
    Route::post('/stores/items/{sanfNo}/units', [StoreItemController::class, 'storeUnit'])
        ->middleware('permission:stores.items_update')->where('sanfNo', '[0-9]+');
    Route::delete('/stores/items/{sanfNo}/units/{cap}', [StoreItemController::class, 'destroyUnit'])
        ->middleware('permission:stores.items_update')
        ->where('sanfNo', '[0-9]+')->where('cap', '[0-9.]+');
    Route::get('/stores/items/{sanfNo}/movements', [StoreItemController::class, 'movements'])
        ->middleware('permission:stores.items_view')->where('sanfNo', '[0-9]+');

    // الأرصدة / balances (AVVG)
    Route::get('/stores/balances', [StoreReportController::class, 'balances'])
        ->middleware('permission:stores.reports_view');

    // التقارير: حركة · خلاصة · تسجيل تصدير / movement · summary · export log
    Route::get('/stores/reports/movement', [StoreReportController::class, 'movement'])
        ->middleware('permission:stores.reports_view');
    Route::get('/stores/reports/summary', [StoreReportController::class, 'summary'])
        ->middleware('permission:stores.reports_view');
    Route::post('/stores/reports/export-log', [StoreReportController::class, 'exportLog'])
        ->middleware('permission:stores.reports_export,stores.reports_print');

    // الجرد: كشف عدّ حيّ فقط — لا نقطة كتابة (D9 + D13)
    Route::get('/stores/stock-sheet', [StockSheetController::class, 'index'])
        ->middleware('permission:stores.stocktake_view');
    Route::post('/stores/stock-sheet/log', [StockSheetController::class, 'logAction'])
        ->middleware('permission:stores.stocktake_print,stores.stocktake_export');

    // الأوامر: توريد · صرف · تحويل / receive · issue · transfer
    Route::get('/stores/receive', [ReceiveOrderController::class, 'index'])
        ->middleware('permission:stores.receiving_view');
    Route::post('/stores/receive', [ReceiveOrderController::class, 'store'])
        ->middleware('permission:stores.receiving_create');

    Route::get('/stores/issue', [IssueOrderController::class, 'index'])
        ->middleware('permission:stores.issuing_view');
    Route::post('/stores/issue', [IssueOrderController::class, 'store'])
        ->middleware('permission:stores.issuing_create');
    Route::get('/stores/issue/{billNo}', [IssueOrderController::class, 'show'])
        ->middleware('permission:stores.issuing_view')->where('billNo', '[0-9]+');
    Route::put('/stores/issue/{billNo}', [IssueOrderController::class, 'update'])
        ->middleware('permission:stores.issuing_update')->where('billNo', '[0-9]+');
    Route::delete('/stores/issue/{billNo}', [IssueOrderController::class, 'destroy'])
        ->middleware('permission:stores.issuing_delete')->where('billNo', '[0-9]+');

    Route::get('/stores/receive/{billNo}', [ReceiveOrderController::class, 'show'])
        ->middleware('permission:stores.receiving_view')->where('billNo', '[0-9]+');
    Route::put('/stores/receive/{billNo}', [ReceiveOrderController::class, 'update'])
        ->middleware('permission:stores.receiving_update')->where('billNo', '[0-9]+');
    Route::delete('/stores/receive/{billNo}', [ReceiveOrderController::class, 'destroy'])
        ->middleware('permission:stores.receiving_delete')->where('billNo', '[0-9]+');

    Route::get('/stores/transfer', [TransferOrderController::class, 'index'])
        ->middleware('permission:stores.transfer_view');
    Route::post('/stores/transfer', [TransferOrderController::class, 'store'])
        ->middleware('permission:stores.transfer_create');
    Route::get('/stores/transfer/{billNo}', [TransferOrderController::class, 'show'])
        ->middleware('permission:stores.transfer_view')->where('billNo', '[0-9]+');
    Route::put('/stores/transfer/{billNo}', [TransferOrderController::class, 'update'])
        ->middleware('permission:stores.transfer_update')->where('billNo', '[0-9]+');
    Route::delete('/stores/transfer/{billNo}', [TransferOrderController::class, 'destroy'])
        ->middleware('permission:stores.transfer_delete')->where('billNo', '[0-9]+');
});

// ============================================================
// مسارات المشتريات - Purchases Routes
//
// هوية الفاتورة والمردود مركّبة: (رقم، سنة)، والمورد رقمه مفرد (PROV_NO).
// المسارات الحرفية (reference/items/filters/next-number) تُسجَّل قبل {param} وإلا التقطها الموجّه.
// The invoice/return identity is composite (number, year); the supplier key is a single PROV_NO.
// Literal paths are registered before {param} ones or the router would swallow them.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // مراجع النماذج: الأنواع والحدود وحسابات المخزون + الأصناف + حسابات الدفع النقدي + المخازن
    // Form references: types/limits/stock accounts, items, cash accounts and stores.
    Route::get('/purchases/reference', [PurchaseInvoiceController::class, 'reference'])
        ->middleware('permission:purchases.view,purchases.create,purchases.returns_create,purchases.suppliers_create,purchases.suppliers_view');
    Route::get('/purchases/items', [PurchaseInvoiceController::class, 'items'])
        ->middleware('permission:purchases.view,purchases.create,purchases.returns_create,purchases.returns_update');
    Route::get('/purchases/cash-accounts', [PurchaseInvoiceController::class, 'cashAccounts'])
        ->middleware('permission:purchases.view,purchases.create,purchases.returns_create');
    Route::get('/purchases/suppliers/options', [SupplierController::class, 'options'])
        ->middleware('permission:purchases.view,purchases.create,purchases.returns_create,purchases.suppliers_create,purchases.suppliers_view');

    // فواتير المشتريات / purchase invoices
    Route::get('/purchases/invoices/filters', [PurchaseInvoiceController::class, 'filterOptions'])
        ->middleware('permission:purchases.view');
    Route::get('/purchases/invoices', [PurchaseInvoiceController::class, 'index'])
        ->middleware('permission:purchases.view');
    Route::post('/purchases/invoices', [PurchaseInvoiceController::class, 'store'])
        ->middleware('permission:purchases.create');
    Route::put('/purchases/invoices/{billNo}/{year}', [PurchaseInvoiceController::class, 'update'])
        ->middleware('permission:purchases.update')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::delete('/purchases/invoices/{billNo}/{year}', [PurchaseInvoiceController::class, 'destroy'])
        ->middleware('permission:purchases.delete')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::post('/purchases/invoices/{billNo}/{year}/activity', [PurchaseInvoiceController::class, 'activity'])
        ->middleware('permission:purchases.print,purchases.export')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::get('/purchases/invoices/{billNo}/{year}/returnable', [PurchaseInvoiceController::class, 'returnable'])
        ->middleware('permission:purchases.returns_create,purchases.returns_update')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::get('/purchases/invoices/{billNo}/{year}', [PurchaseInvoiceController::class, 'show'])
        ->middleware('permission:purchases.view')
        ->where(['billNo' => '[0-9]+', 'year' => '[0-9]{4}']);

    // مردودات المشتريات / purchase returns
    Route::get('/purchases/returns/filters', [PurchaseReturnController::class, 'filterOptions'])
        ->middleware('permission:purchases.returns_view');
    Route::get('/purchases/returns', [PurchaseReturnController::class, 'index'])
        ->middleware('permission:purchases.returns_view');
    Route::post('/purchases/returns', [PurchaseReturnController::class, 'store'])
        ->middleware('permission:purchases.returns_create');
    Route::put('/purchases/returns/{returnNo}/{year}', [PurchaseReturnController::class, 'update'])
        ->middleware('permission:purchases.returns_update')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::delete('/purchases/returns/{returnNo}/{year}', [PurchaseReturnController::class, 'destroy'])
        ->middleware('permission:purchases.returns_delete')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::post('/purchases/returns/{returnNo}/{year}/activity', [PurchaseReturnController::class, 'activity'])
        ->middleware('permission:purchases.returns_print,purchases.returns_export')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);
    Route::get('/purchases/returns/{returnNo}/{year}', [PurchaseReturnController::class, 'show'])
        ->middleware('permission:purchases.returns_view')
        ->where(['returnNo' => '[0-9]+', 'year' => '[0-9]{4}']);

    // تقارير المشتريات / purchase reports
    Route::get('/purchases/reports', [PurchaseReportController::class, 'report'])
        ->middleware('permission:purchases.reports_view');
    Route::post('/purchases/reports/activity', [PurchaseReportController::class, 'activity'])
        ->middleware('permission:purchases.reports_print,purchases.reports_export');

    // الموردون وكشوفهم / suppliers and their statements
    Route::get('/purchases/suppliers/filters', [SupplierController::class, 'filterOptions'])
        ->middleware('permission:purchases.suppliers_view');
    Route::get('/purchases/suppliers/next-number', [SupplierController::class, 'nextNumber'])
        ->middleware('permission:purchases.suppliers_create');
    Route::get('/purchases/suppliers', [SupplierController::class, 'index'])
        ->middleware('permission:purchases.suppliers_view');
    Route::post('/purchases/suppliers', [SupplierController::class, 'store'])
        ->middleware('permission:purchases.suppliers_create');
    Route::put('/purchases/suppliers/{provNo}', [SupplierController::class, 'update'])
        ->middleware('permission:purchases.suppliers_update')
        ->where('provNo', '[0-9]+');
    Route::delete('/purchases/suppliers/{provNo}', [SupplierController::class, 'destroy'])
        ->middleware('permission:purchases.suppliers_delete')
        ->where('provNo', '[0-9]+');
    Route::post('/purchases/suppliers/{provNo}/activity', [SupplierController::class, 'activity'])
        ->middleware('permission:purchases.suppliers_print,purchases.suppliers_export')
        ->where('provNo', '[0-9]+');
    Route::get('/purchases/suppliers/{provNo}/statement', [SupplierController::class, 'statement'])
        ->middleware('permission:purchases.suppliers_statement')
        ->where('provNo', '[0-9]+');
    Route::get('/purchases/suppliers/{provNo}', [SupplierController::class, 'show'])
        ->middleware('permission:purchases.suppliers_view')
        ->where('provNo', '[0-9]+');
});
