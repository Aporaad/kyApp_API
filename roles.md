إليك **الدليل المرجعي الشامل والمضبوط للتعليمات والقواعد البرمجية** لبناء تطبيق Flutter وربطه بالـ API بعد تصحيح وتنسيق كافة البنود:

---

# 📘 الدليل المرجعي لمعايير وتطوير تطبيق Flutter والربط مع الـ API

---

### 1. هيكلية المشروع وهندسة الكود (Architecture)
* **الهيكلية القائمة على الميزات (Feature-First Architecture):**
  * تقسيم مجلدات التطبيق حسب الميزات (`features/auth`, `features/invoices`, `features/reports`, ...) بدلاً من تقسيمها حسب نوع الملفات فقط.
  * كل ميزة تحتوي على طبقاتها المستقلة:
    * `data/`: الـ DataSources، الـ Repositories، ونماذج الـ Models / DTOs.
    * `presentation/`: الشاشات (Screens)، الـ Widgets، ومزودي الحالة (Providers).
* **فصل منطق الأعمال عن الواجهات (Separation of Concerns):**
  * الواجهات (`Widgets`) تعرض البيانات وتستقبل أحداث المستخدم فقط، ولا تتصل بالـ API مباشرة.
  * الـ `Widget` يستمع للـ `StateNotifier / Notifier` عبر **Riverpod**.
  * الـ `Notifier` يطلب البيانات من الـ `Repository`.
  * الـ `Repository` يتصل بالـ API عبر عميل مركزي موحد `DioClient`.
* **إدارة الحالة (State Management):**
  * استخدام **Riverpod** مع `AsyncNotifierProvider` أو `NotifierProvider` لإدارة الحالات غير المتزامنة ومراقبتها بدقة.
* **إلغاء الطلبات غير المكتملة (Request Cancellation):**
  * استخدام `CancelToken` مع Dio داخل الـ Providers؛ بحيث إذا غادر المستخدم الشاشة قبل انتهاء التحميل، يتم إلغاء الطلب تلقائياً لتوفير موارد السيرفر وشبكة الهاتف.

---

### 2. القواعد البرمجية ومعايير الكود (Coding Standards & Conventions)
* **معايير التسمية المعتمدة (Dart Naming Conventions):**
  * **`camelCase` (الحرف الأول صغير):** للمتغيرات، المعاملات، والدوال (مثل: `invoiceList`, `fetchInvoices()`, `customerName`).
  * **`PascalCase` (الحرف الأول كبير):** لأسماء الفئات والواجهات والـ Enums (مثل: `InvoiceDetailsScreen`, `AuthRepository`, `InvoiceStatus`).
  * **`snake_case` (أحرف صغيرة مفصولة بشرطة سفلية):** **فقط** لأسماء الملفات والمجلدات وحزم الصور (مثل: `invoice_repository.dart`, `custom_button.dart`).
* **سلامة القيم الفارغة (Sound Null Safety):**
  * الالتزام الصارم بالـ Null Safety.
  * منع استخدام معامل التأكيد `!` إلا في حالات نادرة جداً مع وجود فحص مسبق، واستبداله بالقيمة الافتراضية `??` أو المعامل الشرطي `?.`.
* **النماذج ونقل البيانات (Models & DTOs):**
  * استخدام حزمة `freezed` مع `json_serializable` لإنشاء كائنات غير قابلة للتعديل (Immutable) وتوليد دوال `fromJson` و `toJson` و `copyWith` بأمان تام.
* **معالجة الأخطاء (Error Handling):**
  * استخدام `try-catch` لجميع العمليات غير المتزامنة (`async/await`).
  * إنشاء فئات مخصصة للأخطاء (`AppException`, `NetworkException`, `ServerException`, `AuthException`) لتقديم رسائل واضحة ومحددة.
* **مبدأ DRY (Don't Repeat Yourself):**
  * تجميع الدوال والأدوات المساعدة المكررة داخل مجلد `core/utils/` أو `core/helpers/`.

---

### 3. واجهة وتجربة المستخدم (UI / UX Standards)
* **التوافق والتعريب الكامل (Arabic Localization & RTL Support):**
  * إعداد بيئة التطبيق لتدعم اللغة العربية بشكل افتراضي وكامل (`locale: Locale('ar', 'SA')`).
  * تفعيل حزم `flutter_localizations` و `GlobalMaterialLocalizations` لضمان صحة اتجاه الواجهات من اليمين إلى اليسار (**RTL**) لجميع العناصر والأيقونات والقوائم.
  * اعتماد خط عربي موحد واحترافي (مثل `Cairo` أو `Tajawal`) يتم تضمينه وتطبيقه مركزياً عبر `ThemeData.fontFamily` و `TextTheme`.
* **التجاوب ومرونة الشاشات (Responsiveness):**
  * استخدام `LayoutBuilder` و `OrientationBuilder` لتكييف الواجهات مع مختلف أحجام الشاشات وتجنب تجاوز حدود الشاشة (Overflows).
  * تحديد Breakpoints واضحة للتفريق بين الهواتف (Mobile) والأجهزة اللوحية (Tablet).
* **تفكيك الواجهات (Widget Decomposition):**
  * تقسيم الشاشات الكبيرة إلى `StatelessWidget` صغيرة ومستقلة لتسهيل الصيانة وتحسين كفاءة إعادة البناء (Rebuild Performance).
* **نظام تصميم موحد (Centralized Design System):**
  * الاعتماد الكامل على `ThemeData` المعرف في التطبيق (الألوان، الخطوط، أحجام النصوص، أشكال الحقول والأزرار).
  * منع كتابة ألوان أو أحجام نصوص ثابتة (Hardcoded) داخل الـ Widgets.
* **الوظائف الأساسية في كل شاشة بيانات:**
  * توفير خيارات **البحث (Search)**، **الفرز (Sorting)**، **الفلترة (Filtering)**، و**التصدير/الطباعة (Export/Print)** في جداول وقوائم البيانات.
* **معالجة الحالات الأربع للواجهة:**
  1. **التحميل (Loading):** عرض مؤشر تحميل متناسق أو Skeleton Shimmer.
  2. **النجاح (Success):** عرض البيانات بسلاسة.
  3. **البيانات الفارغة (Empty State):** عرض شاشة توضيحية عندما لا توجد نتائج.
  4. **الخطأ (Error State):** عرض رسالة مفهومة وزر لإعادة المحاولة (Retry Button).

---

### 4. إدارة الشبكة وجلب البيانات (Networking & API Management)
* **العميل المركزي (Dio Client):**
  * استخدام حزمة `dio` كعميل وحيد لإرسال جميع طلبات الـ HTTP.
  * ضبط مهلة زمنية للاتصال والاستجابة (`ConnectTimeout = 15s`, `ReceiveTimeout = 15s`).
* **المعالجات الوسيطة المركزية (Interceptors):**
  1. **`AuthInterceptor`:** قراءة التوكن من الذاكرة المشفرة وإضافته تلقائياً في ترويسة الطلب `Authorization: Bearer <token>`.
  2. **`ErrorInterceptor`:** معالجة أخطاء السيرفر (401, 403, 404, 422, 500) بشكل مركزي.
  3. **`RetryInterceptor`:** إعادة المحاولة التلقائية عند انقطاع الشبكة بفواصل زمنية متزايدة (Exponential Backoff).
* **تطابق تنسيق وترميز النصوص مع قاعدة البيانات (Database Text Formatting & Encoding):**
  * التأكد التام من توافق ترميز النصوص العربي (UTF-8 / AL16UTF16) بين مدخلات التطبيق وواجهة Laravel وقاعدة بيانات Oracle لتجنب تشوه الحروف.
  * مطابقة معالجة المسافات البيضاء والرموز الخاصة وتنسيق السلاسل النصية (String Trimming & Normalization) عند البحث والاسترجاع.
* **تقسيم الصفحات والتحميل الكسول (Pagination & Lazy Loading):**
  * جلب البيانات على دفعات محددة (مثل 30 إلى 50 سجلاً لكل دفعة) باستخدام `ScrollController` لحماية أداء الهاتف وخادم أوراكل.
* **مطابقة أنواع البيانات (Data Types Mapping):**
  * التعامل بحذر مع أنواع بيانات أوراكل الصارمة، واستخدام دوال تحويل آمنة في Flutter مثل `num.tryParse()` و `DateTime.tryParse()` لمنع انهيار التطبيق إذا تغيّر نوع الحقل.

---

### 5. المصادقة والأمان (Authentication & Security)
* **نظام التوثيق والمصادقة (Laravel Sanctum):**
  * التحقق من المستخدم وإصدار رمز وصول فريد (Personal Access Token).
* **التخزين الآمن للتوكن (Secure Token Storage):**
  * استخدام حزمة **`flutter_secure_storage`** لحفظ التوكن في الذاكرة المشفرة (`Keychain` لنظام iOS و `EncryptedSharedPreferences / KeyStore` لنظام Android).
  * يُمنع منعاً باتاً استخدام `SharedPreferences` العادية لتخزين بيانات الاعتماد أو التوكنات.
* **إدارة حالة المصادقة (Auth State Notifier):**
  * إنشاء `AuthNotifier` يراقب حالة تسجيل الدخول، ليوجه المستخدم تلقائياً إلى الشاشة الرئيسية أو شاشة تسجيل الدخول فوراً.
* **انتهاء الصلاحية وتسجيل الخروج التلقائي (Auto Logout on 401):**
  * عند استلام خطأ `401 Unauthorized` من السيرفر، يقوم الـ Interceptor بحذف التوكن المشفر فوراً وإعادة توجيه المستخدم لشاشة تسجيل الدخول مع تنبيهه بانتهاء الجلسة.
* **حماية المتغيرات الحساسة (Environment Variables):**
  * استخدام ملف `.env` عبر حزمة `flutter_dotenv` أو وسائط البناء (`--dart-define`) لتمرير روابط الـ API والمفاتيح السرية، وتجنب كتابتها مباشرة داخل الكود.

---

### 6. المراقبة وتتبع الأخطاء (Monitoring & Logging)
* **المسجل المركزي (Logging):**
  * استخدام مسجل مخصص (مثل حزمة `talker_flutter` أو `logger`) لطباعة تفاصيل الطلبات والأخطاء في وضع التطوير (Debug Mode) فقط، وتعطيلها تماماً في وضع الإنتاج (Release Mode).
* **تتبع الأداء:**
  * تسجيل زمن استجابة الطلبات البطيئة للمساعدة في تحسين أداء استعلامات قاعدة البيانات وتجربة المستخدم.