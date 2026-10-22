# سجل تطوير المشروع (Development History)

## [2026-09-06 00:38:11]
- **المهمة / Task:** إضافة مسار وكنترولر ديناميكي آمن للاستعلام عن أي جدول في قاعدة البيانات وتحديد عدد السجلات.
- **التغييرات البرمجية / Code Changes:**
  - إنشاء المتحكم `App\Http\Controllers\TableQueryController` المزود بفحص أمني عالي (White-list & Validation) لمنع هجمات SQL Injection.
  - تعريف مسار `Route::match(['get', 'post'], '/selectFromTable', ...)` في `routes/api.php`.
  - إضافة تعليقات توضيحية باللغتين العربية والإنجليزية، واتباع معايير Clean Code.
- **الأمان / Security:**
  - التحقق من نمط اسم الجدول ومطابقته مع الجداول المصرح بها داخل `USER_TABLES` في Oracle.
  - تقييد الحد الأقصى لعدد الصفوف المسترجعة لحماية موارد السيرفر.

## [2026-09-07 04:00:35]
- **المهمة / Task:** تحديث وتوسيع وثيقة القواعد والمعايير المرجعية `roles.md` لتشمل معايير التعريب وتوافق نصوص قاعدة البيانات.
- **التغييرات البرمجية والتوثيقية / Code & Docs Changes:**
  - إضافة بند التعريب ودعم اتجاه الواجهة من اليمين إلى اليسار (RTL Support) واستخدام الخطوط العربية الموحدة (مثل Cairo أو Tajawal) عبر `ThemeData`.
  - إضافة بند مطابقة ترميز وتنسيق النصوص بين الواجهات وقاعدة بيانات Oracle (AL32UTF8 / UTF-8 ومعالجة المسافات والتنسيق).

## [2026-09-07 04:27:10]
- **المهمة / Task:** إعداد خطة شاملة ومفصلة لتأسيس مشروع Flutter وإدارة الحالة بـ Riverpod ونظام المصادقة وبوابة الموظف.
- **التغييرات التوثيقية / Docs Changes:**
  - إنشاء ملف `implementation_plan.md` يحتوي على هيكل المجلدات القائم على الميزات (Feature-First Architecture) ومراحل بناء النواة (Core Layer)، المصادقة (Auth Feature)، وشاشات البداية وتسجيل الدخول، وهيكل بوابة الموظف والقائمة الجانبية القابلة للطي (Collapsible Drawer) مع أقسامها الثمانية.

## [2026-09-09 00:24:21]
- **المهمة / Task:** تطوير وتصميم واجهة كشف حساب الموظفين والقصاصين والخياطين ومطابقتها للشكل الرسمي مع التجاوب التام وإصلاح أخطاء البحث وجلب البيانات.
- **التغييرات البرمجية / Code Changes:**
  - **الباك إند (`WorkerController.php`)**:
    - معالجة حساسية حالة الأحرف في نتائج PDO القادمة من Oracle (`acc_no` و `emp_no` و `cutter_no` و `tailor_no`) لمنع الخطأ 422.
    - إضافة منطق حساب النطاق الافتراضي التلقائي لآخر 30 يوماً من أحدث حركة في الحساب (`MAX(ENTRY_DATE)`) وتوليد الرصيد الافتتاحي الدقيق لما قبل الفترة.
    - تسريع الاستعلامات وضبط الحقول المسترجعة من جدول `JOURNAL_ENTRY_REC`.
  - **الفرونت إند (`ky_apps`)**:
    - بناء واجهة `WorkerStatementScreen` متكاملة تتضمن: الترويسة الرسمية (شعار الكبودي، كود K.Y، تفاصيل الاتصال والفرع)، شريط بيانات العامل والحساب والعملة ونطاق التواريخ، جدول الحركات الذهبي المتجاوب مع سطر الرصيد الافتتاحي وسطر إجمالي العمليات، شريط التنقل بصفحات (15 حركة/صفحة)، وصناديق الرصيد الافتتاحي والنهائي.
    - إزالة التمرير الأفقي بالكامل وجعل كل الأعمدة تتناسب تلقائياً بنسب `FlexColumnWidth` وتقليص النصوص باستخدام `FittedBox` عند ضيق الشاشة.
    - تزويد حقل البحث في `WorkersListScreen` بمؤقت تأخير (Debounce Timer 300ms) لمنع إلغاء الطلبات المتكررة في مكتبة Dio أثناء الكتابة السريعة.
    - حل مشكلة `RenderFlex overflowed by 12 pixels` في شريط عنوان التطبيق `EmployeeMainLayout`.
    - إضافة ورقة خيارات الطباعة والتصدير (PDF / Excel / طباعة مباشرة).

## [2026-09-13 18:21:22]
- **المهمة / Task:** تشخيص سبب تحذير PHP Startup الخاص بمكتبة `php_oci8_19.dll` بعد تثبيت أوراكل 11g.
- **التشخيص الفني والحلول / Technical Diagnosis & Fix:**
  - عند تثبيت Oracle Database 11g تم إدراج مساره `D:\app\Mujeeb\product\11.0.2\bin` في مقدمة متغير البيئة `PATH` في النظام.
  - ملف `php.ini` مفعل فيه ملحق `extension=oci8_19` (المخصص للربط عبر Oracle Instant Client 19c).
  - عند تشغيل PHP يبحث النظام بالترتيب عن ملف `oci.dll` فيجد ملف Oracle 11g أولاً بدلاً من `C:\instantclient_19_32`، ونظراً لاختلاف الدوال بين الإصدارين يظهر خطأ `The specified procedure could not be found`.
  - الحل الجذري هو تقديم مسار `C:\instantclient_19_32` في أول الـ PATH قبل مسار Oracle 11g ليعمل PHP مع قواعد 11g بسلاسة تامة.

## [2026-09-13 20:00:24]
- **المهمة / Task:** تشخيص وحل مشكلة فشل تسجيل الدخول في التطبيق وظهور خطأ `ORA-01400: cannot insert NULL into ("ACCOUNTS"."PERSONAL_ACCESS_TOKENS"."ID")`.
- **التشخيص الفني والحلول / Technical Diagnosis & Fix:**
  - **السبب الفني:**
    - عند قيام المستخدم بتسجيل الدخول، يقوم `AuthController` باستدعاء مكتبة Laravel Sanctum لإنشاء رمز وصول للمستخدم عبر `$user->createToken('ky_app_employee')`.
    - مكتبة Sanctum تقوم بتنفيذ استعلام `INSERT` في جدول `PERSONAL_ACCESS_TOKENS` دون تحديد قيمة العمود `ID` وتعتمد على خاصية التوليد التلقائي (Auto-Increment / Identity Column).
    - في إصدارات أوراكل القديمة (مثل Oracle 11g)، لا تتوفر أعمدة الهوية التلقائية (`GENERATED AS IDENTITY`) المتوفرة في 12c فما فوق، بل يعتمد محرك أوراكل على زوج من (Sequence + Trigger).
    - كان الجدول يحتوي على المتتالية `PERSONAL_ACCESS_TOKENS_ID_SEQ` ولكن مشغل الإدراج التلقائي `PERSONAL_ACCESS_TOKENS_ID_TRG` كان مفقوداً، مما تسبب في إرسال `NULL` لحقل `ID` الإلزامي ورمي الاستثناء `ORA-01400` ومن ثم رد السيرفر بخطأ 500.
  - **الحل الجذري:**
    - تم إنشاء الـ Trigger تلقائياً `PERSONAL_ACCESS_TOKENS_ID_TRG` لملء عمود `ID` من المتتالية `PERSONAL_ACCESS_TOKENS_ID_SEQ.NEXTVAL` عند الإدراج.
    - تم إنشاء التريجرات التلقائية لبقية جداول Laravel ذات المتتاليات المشابهة (`MIGRATIONS`, `JOBS`, `FAILED_JOBS`).
    - تم اختبار نقطة النهاية `/api/auth/login` والتأكد من تسجيل الدخول بنجاح وإرجاع التوكن بنجاح تام (Status 200).





---

## 2026-10-21 — Laravel API: نقاط نهاية تقارير الحسابات (Account Reports endpoints)

**نموذج AI:** Qoder Agent · **الخطة:** `Account_Financial_Reports_1686e067.md` · **المهام t1–t3**.

### `AccountController::getAccountStatement` — إعادة كتابة كاملة
المسار: `GET /api/accounts/chart/{accNo}/statement?from_date=YYYY-MM-DD&to_date=YYYY-MM-DD&limit=1000`
الحماية: `auth:sanctum` + `permission:accounts.statement`.

**التغييرات الجوهرية:**
- الانتقال من جدول وهمي `ACC_TRXN` (لا وجود له) إلى المسار الحقيقي `ENTRY_MAIN ⋈ ENTRY_SUB ⋈ ENTRY_TYPE` — `DET_NO = ENTRY_NO` مع `ACC_NO` على `ENTRY_SUB`.
- حساب **الرصيد الافتتاحي** عبر `SUM(ENTRY_MNT) WHERE ENTRY_DATE < :from` ثم تطبيقه على **window function** `SUM() OVER (ORDER BY ENTRY_DATE, ENTRY_NO, DET_NO ROWS UNBOUNDED PRECEDING)` لإخراج رصيد جارٍ لكل سطر.
- **نافذة 15 يوماً افتراضية** خلف آخر حركة فعلية للحساب (`MAX(ENTRY_DATE)`) — يتم إبلاغ العميل بذلك عبر `summary.defaulted = true` و`summary.from_date`/`to_date` المرصودة.
- **تصنيف مدين/دائن** حسب إشارة `ENTRY_MNT` (موجب = مدين، سالب = دائن) — وليس حسب نوع القيد.
- **ترميز**: `ArabicEncoding::fixMojibake` على `TYPE_NAME` و`DETAILS` و`ACC_NAME` قبل الإرسال.
- **الحقول المُضافة إلى `summary`**: `opening_balance`, `final_balance`, `total_debit`, `total_credit`, `currency` (من `CURRENCY` عبر `CUR_NO`)، `from_date`, `to_date`, `defaulted`.
- **الاستجابة envelope** وفق المعيار: `{ success, data: { account, transactions: [...], summary: {...} }, meta: { total, page, limit, pages } }`.

### `AccountController::getGroupStatement` — جديد
المسار: `GET /api/accounts/group-statement?acc_no=&acc_sub=&acc_part=&group_id=&from_date=&to_date=&limit=1000`
الحماية: `auth:sanctum` + `permission:accounts.statement`.

**السلوك:**
- **تحديد النطاق** عبر 4 باراميترات اختيارية، أولوية الأعمق: `group_id > acc_part > acc_sub > acc_no` — نفس منطق `resolveStatementScope()` في Flutter لتجنّب أي انحراف.
- **ميزان مراجعة متعدد الحسابات** عبر `GROUP BY s.ACC_NO, s.CUR_NO` على `ENTRY_SUB` مع `SUM(CASE WHEN CUR_NO=1 ... ELSE 0 END)` لفصل **المحلي (YR)** عن **الأجنبي** — لا يعتمد على `CUR_PRICE.SEQ_PRICE` لأنه قد يكون `NULL` للحركات المحلية.
- **بلا نافذة افتراضية**: `from_date`/`to_date` اختياريان تماماً؛ عند الغياب يشمل كامل السجل التاريخي (يحاكي سلوك `TRIAL_BALANCE_REPORT.RDF` الأصلي).
- **`as_of_date`** = تاريخ اليوم بصيغة dd/MM/yyyy (يظهر في ترويسة التقرير وفي «إجمالي حسابات {as_of_date}»).
- **scope_label** عربي واضح («حساب: …» / «مجموعة: …») ليُستهلَك مباشرة في الـ UI وPDF.
- **ترميز**: `ArabicEncoding::fixMojibake` على `ACC_NAME`, `SUB_NAME`, `PART_NAME`, `ACC_GROUP_NAME`.
- **المستجيب envelope**: `{ success, data: { scope_level, scope_label, as_of_date, from_date, to_date, rows: [...], totals: { local_debit, local_credit, foreign_debit, foreign_credit, balance } } }`.

### `routes/api.php`
سُجّل المسار الجديد:
```php
Route::middleware(['auth:sanctum', 'permission:accounts.statement'])
    ->get('/accounts/group-statement', [AccountController::class, 'getGroupStatement']);
```
المسار القديم `/accounts/chart/{accNo}/statement` بقي تحت نفس الحارسين.

### `tests/Feature/BusinessRoutesSealedTest.php`
- أُضيف المسار `accounts/group-statement` إلى قائمة المسارات المُغلقة (sealed routes) للتأكّد من أنه: (1) ليس عاماً؛ (2) يحمل `auth:sanctum` + `permission:*` معاً؛ (3) يُرجع 401 بدون توكن و403 بدون منح.
- **النتيجة:** 6 اختبارات / **71 تأكيد** — كلها ناجحة.

### `vendor/bin/pint --dirty`
- نظيف: **PASS · 7 files**.

### عدد المسارات
- **قبل**: 75 مسار مسجّل تحت `/api/*`.
- **بعد**: **76** (+1 = `/accounts/group-statement`).

### لا تغييرات على `APP_PERMISSIONS`
الرموز `accounts.statement` / `accounts.print` / `accounts.export` كانت مُهيّأة مسبقاً ضمن كتالوج الـ 93 IBAC codes — لم تحتج المهمة إلى أي seed جديد ولا إلى أي grant يدوي.


---

## 2026-10-21 — إصلاح: انهيار منتقي التاريخ في كشف الحساب المالي (سنة 93422)

**نموذج AI:** Qoder Agent · **الأمر:** «هناك مشكله في عرض كشف حساب مالي قم بحلها».

### السبب الجذري (مُتحقَّق منه بمسبار حي)
1. **خطأ PHP في الخادم** — `AccountController.php:502-503` استخدم `format('YYYY-MM-DD')`. في PHP كل `Y` تُخرِج السنة كاملة، و`M` اسم الشهر، و`D` اسم اليوم — فنتجت `"2025202520252025-MarMar-SatSat"` بدل `"2025-03-16"`. نفس الخطأ كان في `CustomerController.php:446`.
2. **تطبيع Dart على العميل** — `_parseServerDate` حلّل السلسلة المشوهة إلى `DateTime(0, 1, 2025202520252025)` التي يطبّعها Dart إلى **سنة 93422** (تم إثبات ذلك بمسبار `dart run` أعاد `93422-12-11 01:12:58`).
3. **انهيار الـ assertion** — `showDatePicker(initialDate: 93422, lastDate: 2030)` → `'initialDate == null || !initialDate.isAfter(lastDate)'` ينكسر.

### الإصلاحات
- **الخادم** (`AccountController` + `CustomerController`): `format('Y-m-d')` + تحقق نطاق (سنة 1900–2100، شهر 1–12، يوم 1–31) داخل `parseStatementDateToIso`/`parseDateToIso` — أي تاريخ مشوه من عميل قديم يُرفض ويُعامَل كغائب.
- **العميل**: ملف مشترك جديد `lib/features/accounts/presentation/utils/statement_date_parser.dart`:
  - `parseStatementServerDate()` — يرفض أي تاريخ خارج 1900–2100 (يُرجع null)؛ التعبئة التلقائية للفلاتر لا تحدث إلا إذا نجح تحليل التاريخين معاً.
  - `clampToDatePickerRange()` — حارس صلب يقصّ initialDate داخل [firstDate, lastDate] قبل `showDatePicker` في كلتا الشاشتين.
- **اختبارات انحدار**: `test/features/accounts/statement_date_parser_test.dart` — 11 اختباراً تغطي السلسلة المشوهة الحرفية، السنة 93422، صفوف Oracle الفاسدة (سنة 0025)، والتقصّص.

### اكتشاف قاعدة البيانات (قراءة فقط)
- `ENTRY_MAIN` يحتوي صفوفاً بتواريخ فاسدة (سنة 0025) — الحارس الجديد يتجاهلها بدل تسميم الفلاتر.
- استعلامات الفحص موثّقة في `db_commends.md` (بلا أي كتابة).

### التحقق
- `flutter analyze` = نظيف (تحذير واحد قديم غير متصل) · `flutter test` = **91/91** (كانت 80) · `php -l` = سليم · `pint --dirty` = PASS · `php artisan test` = **127 ناجحاً (683 تأكيداً)**.



## 2026-10-21 11:07 — إضافة — مفتاحا الترويسة الإنجليزية في `AppConfig`

**نموذج AI:** Qoder Agent · **الأمر:** إضافة `company_name_en`/`company_address_en` إلى
`APP_SETTINGS` مع حقلين في شاشة الإعدادات وتعميم ترويسة التقارير.

### التغييرات في الخادم
- `app/Support/AppConfig.php`: ثابتان جديدان `COMPANY_NAME_EN` / `COMPANY_ADDRESS_EN`،
  وقيمتهما في `DEFAULTS` (`ALKABODI FOR MEN's TAILORING` / `Airport Street - Next to Al-shayef House`)،
  وأُضيف المفتاحان إلى `WRITABLE_KEYS` بالترتيب ملاصقاً لنظيريهما العربيين. يظهران تلقائياً في
  `publicPayload()` (الفراغ يسقط إلى الافتراضي) وفي `keys()`.
- `app/Http/Controllers/AppConfigController.php` — `textLimit()`: `COMPANY_ADDRESS`/`COMPANY_ADDRESS_EN`
  بسعة 300 (صف طويل أحياناً) بدل 200 الافتراضية؛ الاسم يبقى 200.
- `tests/Feature/IbacCoreTest.php`: عدد تعريفات `APP_SETTINGS` من **22 إلى 24** (10 أمان + 14 تهيئة،
  بما فيها صفّا الاسم/العنوان بالإنجليزي الجديدان).

### لماذا تطلّب ذلك عملاً على القاعدة
`SettingsStore::set()` يرمي `InvalidArgumentException("الإعداد غير معروف")` لأي مفتاح بلا صف في
`APP_SETTINGS`. لذا كان لا بد من إدراج الصفَّين (23/24) عبر موصل `oracledb MCP` قبل أن يقبل الحفظ
المفتاحين — التفاصيل والـ SQL في `db_commends.md`، والملف القابل للتكرار
`database/oracle/05_app_settings_company_en.sql`.

### التحقق
- `php -l` = سليم · `vendor/bin/pint --dirty` = **PASS** · `php artisan test` = **127 ناجحاً (683 تأكيداً)**.
- مسبار حي للـ payload العام أكّد وصول المفتاحين إلى الاستجابة (حُذف المسبار بعد التشغيل).

---

## [2026-10-22 14:20] إصلاح نطاق كشف حسابات المجموعات الجزئية (حساب جزئي/فرعي/رئيسي)

**المشكلة:** عند اختيار حساب جزئي دون اختيار مجموعة، يرجع التقرير خطأ «لا توجد حسابات في هذا النطاق» رغم
وجود الحسابات.

**السبب الجذري:** في `AccountController::getGroupStatement` كانت فروع النطاق (sub/main/account) تستخدم
`TRUNC(c.ACC_NO / 10000)` لاستخراج بادئة الحساب الجزئي. هذا يعطي أربعة أرقام، بينما معرّف الجزء
`ACC_PART` ثلاثي الأرقام، فلا يتطابقان أبداً؛ كما أن `CHART_ACC.ACC_NO` يتراوح بين 8 و9 أرقام فلا يصلح
أي مقام ثابت للاستخراج.

**الإصلاح (ثلاثة فروع في `getGroupStatement`):** استبدال الاستخراج بـ
`SUBSTR(TO_CHAR(c.ACC_NO), 1, 3)` المستقلّ عن الطول:
- فرع الجزء: `SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) = TO_CHAR(:acc_part)`
- فرع الفرعي: `SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) IN (SELECT TO_CHAR(ACC_PART) FROM ACC_SUB WHERE ACC_SUB = :acc_sub)`
- فرع الرئيسي: `SUBSTR(TO_CHAR(c.ACC_NO), 1, 3) IN (SELECT TO_CHAR(s.ACC_PART) FROM ACC_SUB s INNER JOIN ACC_MAIN m ON s.ACC_SUB = m.ACC_SUB WHERE m.ACC_NO = :acc_no)`
- فرع المجموعة (`c.GROUP_ID = :group_id`) بقي دون تغيير.

**التحقق:** `php -l` = سليم. مسبار `oracledb MCP` للقراءة فقط (بدون أي DDL/DML) أكّد أن الجزء 123 يرجع
20469 صفاً بعد الإصلاح (كان صفراً قبله)، وأن تطابق `SUBSTR(...,1,3)` يشمل كامل الـ 20954 صفاً في `CHART_ACC`.
