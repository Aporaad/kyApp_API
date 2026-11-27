<?php

namespace App\Support;

/**
 * الكتالوج المرجعي للصلاحيات — المصدر الوحيد الصحيح لأكواد وعناوين الصلاحيات
 * Canonical permission catalog — the single source of truth for permission codes and labels.
 *
 * يُستخدم هذا الكتالوج في ثلاثة أماكن متطابقة ولا تتباعد أبداً:
 * This same catalog feeds three consumers so they can never drift apart:
 *  1. بذر جدول APP_PERMISSIONS في أوراكل (database/oracle/02_ibac_seed.php)
 *     Oracle seeding of APP_PERMISSIONS.
 *  2. التحقق من صحة منح الصلاحيات في الـ API (PermissionResolver / UserAdminController)
 *     Server-side grant validation.
 *  3. بناء شاشات اختيار الصلاحيات (قائمة الوحدات وأفعالها)
 *     Building the permission-picker payloads.
 *
 * نمط الكود / Code pattern:  "<module>.<action>"  بحروف صغيرة ونقطة واحدة، مثال: customers.statement
 * الصلاحيات العامة (Wildcard) مسموحة بشكل واحد فقط: "<module>.*" أو "system.*".
 * Wildcards are allowed only in the exact "<module>.*" / "system.*" form.
 */
final class PermissionCatalog
{
    /**
     * الأفعال الأساسية التي تُمنح لكل قسم قبل أي صلاحية أخرى (نمط الطلب)
     * Core actions granted first for every section (view · create · update · delete)
     */
    public const CORE_ACTIONS = ['view', 'create', 'update', 'delete'];

    /**
     * الرمز الخاص بمنح شامل على مستوى النظام
     * Reserved code meaning "everything"
     */
    public const SYSTEM_WILDCARD = 'system.*';

    /**
     * رموز الأفعال المخزّنة لصلاحيات المنح العام في جدول APP_PERMISSIONS
     * Stored action token used for wildcard rows
     */
    public const WILDCARD_ACTION = '*';

    /**
     * الوحدات مرتّبة حسب ظهورها في واجهة البوابة.
     * Modules ordered exactly as they appear in the portal UI.
     *
     * 'name_ar'  => اسم الوحدة للعرض / module display name
     * 'core'     => الأفعال الأساسية (ترتيبها ثابت: view,create,update,delete) / core actions
     * 'extra'    => الصلاحيات الإضافية التي تأتي بعد الأساسية / additional actions
     *
     * @var array<string, array{name_ar: string, core: array<string,string>, extra: array<string,string>}>
     */
    private const MODULES = [
        'dashboard' => [
            'name_ar' => 'الرئيسية',
            'core' => ['view' => 'عرض لوحة المعلومات الرئيسية'],
            'extra' => [],
        ],
        'sales' => [
            'name_ar' => 'المبيعات',
            'core' => [
                'view' => 'عرض قائمة المبيعات',
                'create' => 'إضافة عملية مبيعات',
                'update' => 'تعديل عملية مبيعات',
                'delete' => 'حذف عملية مبيعات',
            ],
            'extra' => [
                'print' => 'طباعة فواتير المبيعات',
                'export' => 'تصدير بيانات المبيعات',
                // مردودات المبيعات عمليات مستقلة داخل القسم: من يرى الفواتير لا يرى بالضرورة
                // المردودات، وكل منها له طباعته وتصديره.
                // Sales returns are their own operation: seeing invoices is not seeing returns.
                'returns_view' => 'عرض مردودات المبيعات',
                'returns_create' => 'إضافة مردود مبيعات',
                'returns_update' => 'تعديل مردود مبيعات',
                'returns_delete' => 'حذف مردود مبيعات',
                'returns_print' => 'طباعة مردودات المبيعات',
                'returns_export' => 'تصدير مردودات المبيعات',
                // تقارير المبيعات لوحة إحصائية مستقلة: عرضها وطباعتها وتصديرها ثلاثة منح.
                // Sales reports are their own dashboard — view/print/export are three grants.
                'reports_view' => 'عرض تقارير المبيعات',
                'reports_print' => 'طباعة تقارير المبيعات',
                'reports_export' => 'تصدير تقارير المبيعات',
            ],
        ],
        // المشتريات تقابل بنية المبيعات بثلاث عمليات مستقلة: الفواتير والمردودات والموردون،
        // ولكل منها طباعتها وتصديرها، فوق تقارير المشتريات.
        // Purchases mirror sales with three independent operations — invoices, returns and
        // suppliers — each with its own print/export, plus the purchases reports.
        'purchases' => [
            'name_ar' => 'المشتريات',
            'core' => [
                'view' => 'عرض فواتير المشتريات',
                'create' => 'إضافة فاتورة مشتريات',
                'update' => 'تعديل فاتورة مشتريات',
                'delete' => 'حذف فاتورة مشتريات',
            ],
            'extra' => [
                'print' => 'طباعة فواتير المشتريات',
                'export' => 'تصدير فواتير المشتريات',
                // مردودات المشتريات لا تُرى بمنح الفواتير: مردود يحرّك المخزون في الاتجاه المعاكس.
                // Purchase returns are not implied by invoice access: a return moves stock backwards.
                'returns_view' => 'عرض مردودات المشتريات',
                'returns_create' => 'إضافة مردود مشتريات',
                'returns_update' => 'تعديل مردود مشتريات',
                'returns_delete' => 'حذف مردود مشتريات',
                'returns_print' => 'طباعة مردودات المشتريات',
                'returns_export' => 'تصدير مردودات المشتريات',
                // تقارير المشتريات لوحة إحصائية مستقلة / purchases reports are their own dashboard
                'reports_view' => 'عرض تقارير المشتريات',
                'reports_print' => 'طباعة تقارير المشتريات',
                'reports_export' => 'تصدير تقارير المشتريات',
                // سجل الموردين وحساباتهم المالية: الإنشاء يولّد حساباً في دليل الحسابات،
                // وكشف الحساب يقرأ دفتر الأستاذ مباشرة.
                // The supplier registry and its ledgers: creating one numbers a chart account,
                // and the statement reads the general ledger directly.
                'suppliers_view' => 'عرض الموردين',
                'suppliers_create' => 'إضافة مورد جديد',
                'suppliers_update' => 'تعديل بيانات المورد',
                'suppliers_delete' => 'حذف المورد',
                'suppliers_print' => 'طباعة بيانات الموردين',
                'suppliers_export' => 'تصدير بيانات الموردين',
                'suppliers_statement' => 'عرض كشف حساب المورد',
            ],
        ],
        'tailoring' => [
            'name_ar' => 'كروت التفصيل',
            'core' => [
                'view' => 'عرض كروت التفصيل',
                'create' => 'إضافة كرت تفصيل',
                'update' => 'تعديل كرت تفصيل',
                'delete' => 'حذف كرت تفصيل',
            ],
            'extra' => [
                'print' => 'طباعة كروت التفصيل',
                'deliver' => 'تسليم الخدمة وتفريغ الكرت',
                // خانات المال في كرت التفصيل صلاحية مستقلة: رؤية الفاتورة لا تعني رؤية ما دُفع فيها.
                // Money fields on a cutting bill are their own grant: viewing the bill is not
                // viewing what was paid on it. The server nulls the amounts when it is missing,
                // so the gate lives on the controller and not on the widget alone.
                'payments_view' => 'عرض تفاصيل دفع فاتورة التفصيل',
                'export' => 'تصدير بيانات التفصيل',
                // متابعة السير تُعرض في بطاقة مستقلة عن الاستعراض، فلها صلاحية مستقلة.
                // Progress tracking is its own card, so it carries its own grant.
                'progress' => 'متابعة سير فاتورة التفصيل',
                // حسابات الصناديق النقدية في قوائم اختيار الحسابات المالية لا تظهر إلا
                // لهذه الصلاحية — إلا صندوق المستخدم المرتبط بالفاتورة نفسه فهو يظهر
                // تلقائياً بلا صلاحية، والحسابات البنكية ليست لها علاقة بهذا القيد.
                // Cash-box accounts in the financial-account pickers show only with this
                // grant — except the user's own box linked to the bill, which shows
                // automatically; bank accounts are out of this rule's scope.
                'cash_boxes_view' => 'اظهار حسابات الصناديق النقدية',
            ],
        ],
        // ── خط الإنتاج / production line ────────────────────────
        // ثماني وحدات مستقلة داخل قسم التفصيل. الفعل الأساسي في هذا الكتالوج هو
        // view فقط؛ الأفعال المخصّصة (approve/deliver/receive/transfer/finish)
        // تُسجّل في extra اتباعاً لقاعدة CORE_ACTIONS الأربعة.
        // Eight independent stations inside tailoring. `core` here is only `view`;
        // the custom verbs live in `extra`, following the four-verb CORE_ACTIONS rule.
        'prod_approval' => [
            'name_ar' => 'اعتماد طلبات التفصيل',
            'core' => [
                'view' => 'عرض طلبات الاعتماد',
            ],
            'extra' => [
                'approve' => 'اعتماد قطع الطلب',
                'unapprove' => 'إلغاء اعتماد قطع الطلب',
                'print' => 'طباعة كشف الاعتماد',
                'export' => 'تصدير كشف الاعتماد',
            ],
        ],
        'prod_to_cut' => [
            'name_ar' => 'التسليم إلى القص',
            'core' => [
                'view' => 'عرض الطلبات الجاهزة للقص',
            ],
            'extra' => [
                'deliver' => 'تسليم القطع إلى القصاص',
                'undo' => 'التراجع عن تسليم القص',
                'print' => 'طباعة سند التسليم إلى القص',
                'export' => 'تصدير بيانات التسليم إلى القص',
            ],
        ],
        'prod_from_cut' => [
            'name_ar' => 'الاستلام من القص',
            'core' => [
                'view' => 'عرض القطع عند القصاصين',
            ],
            'extra' => [
                'receive' => 'استلام القطع من القصاص',
                'undo' => 'التراجع عن استلام القص',
                'print' => 'طباعة سند الاستلام من القص',
                'export' => 'تصدير بيانات الاستلام من القص',
            ],
        ],
        'prod_cut_transfer' => [
            'name_ar' => 'التحويل بين القصاصين',
            'core' => [
                'view' => 'عرض القطع القابلة للتحويل بين القصاصين',
            ],
            'extra' => [
                'transfer' => 'تحويل القطع إلى قصاص آخر',
                'print' => 'طباعة إشعار التحويل بين القصاصين',
            ],
        ],
        'prod_to_tailor' => [
            'name_ar' => 'التسليم إلى الخياطين',
            'core' => [
                'view' => 'عرض القطع الجاهزة للخياطة',
            ],
            'extra' => [
                'deliver' => 'تسليم القطع إلى الخياط',
                'undo' => 'التراجع عن تسليم الخياط',
                'print' => 'طباعة سند التسليم إلى الخياط',
                'export' => 'تصدير بيانات التسليم إلى الخياط',
            ],
        ],
        'prod_from_tailor' => [
            'name_ar' => 'الاستلام من الخياط',
            'core' => [
                'view' => 'عرض القطع عند الخياطين',
            ],
            'extra' => [
                'receive' => 'استلام القطع من الخياط',
                'undo' => 'التراجع عن استلام الخياط',
                'print' => 'طباعة سند الاستلام من الخياط',
                'export' => 'تصدير بيانات الاستلام من الخياط',
            ],
        ],
        'prod_tailor_transfer' => [
            'name_ar' => 'التحويل بين الخياطين',
            'core' => [
                'view' => 'عرض القطع القابلة للتحويل بين الخياطين',
            ],
            'extra' => [
                'transfer' => 'تحويل القطع إلى خياط آخر',
                'print' => 'طباعة إشعار التحويل بين الخياطين',
            ],
        ],
        'prod_ready' => [
            'name_ar' => 'التجهيز النهائي',
            'core' => [
                'view' => 'عرض القطع الجاهزة للتجهيز النهائي',
            ],
            'extra' => [
                'finish' => 'إنهاء تجهيز القطع',
                'undo' => 'التراجع عن التجهيز النهائي',
                'print' => 'طباعة كشف الجاهز للتسليم',
                'export' => 'تصدير بيانات التجهيز النهائي',
            ],
        ],
        'customers' => [
            'name_ar' => 'العملاء',
            'core' => [
                'view' => 'عرض قائمة العملاء',
                'create' => 'إضافة عميل جديد',
                'update' => 'تعديل بيانات العميل',
                'delete' => 'حذف العميل',
            ],
            'extra' => [
                'statement' => 'عرض كشف حساب العميل',
                'print' => 'طباعة كشف حساب العميل',
                'export' => 'تصدير بيانات العملاء',
                'edit_limit' => 'تعديل سقف رصيد العميل',
                // مقاسات العملاء عملية مستقلة داخل القسم: من يرى سجل العميل لا يرى بالضرورة
                // أرشيف مقاساته، وطباعة الأرشيف وتصديره إجراءان يحتاجان منحاً صريحاً.
                // Customer measurements are their own operation inside the module: seeing the
                // customer record is not seeing the measurement archive, and printing or
                // exporting that archive are two separately-granted actions.
                'measurements_view' => 'عرض مقاسات العملاء',
                'measurements_print' => 'طباعة مقاسات العملاء',
                'measurements_export' => 'تصدير مقاسات العملاء',
            ],
        ],
        'employees' => [
            'name_ar' => 'الموظفين',
            'core' => [
                'view' => 'عرض قائمة الموظفين',
                'create' => 'إضافة موظف جديد',
                'update' => 'تعديل بيانات الموظف',
                'delete' => 'حذف الموظف',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو إيقاف الموظف',
                // كشف حساب مالي وكشف عمل صار لكل سجل عامل صلاحيتان مستقلّتان: القدرة على
                // إظهار أرصدة الموظف ليست هي القدرة على إظهار سجل عمله/حضوره.
                // Financial balance and work/attendance are separate grants per worker registry.
                'statement_financial' => 'كشف حساب مالي للموظف',
                'statement_work' => 'كشف عمل للموظف',
            ],
        ],
        'cutters' => [
            'name_ar' => 'القصاصين',
            'core' => [
                'view' => 'عرض قائمة القصاصين',
                'create' => 'إضافة قصاص',
                'update' => 'تعديل بيانات القصاص',
                'delete' => 'حذف القصاص',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو إيقاف القصاص',
                'statement_financial' => 'كشف حساب مالي للقصاص',
                'statement_work' => 'كشف عمل للقصاص',
                // تقرير العمل الجماعي لكل القصاصين يُعرض وحده في بطاقة مستقلة، فله صلاحية
                // مستقلة عن كشف الفرد: القدرة على رؤية أرشيف مجموعة لا تعني القدرة على
                // رؤية سجل فرد. / Group work report is its own grant: seeing the pooled
                // archive is distinct from seeing one worker's individual work statement.
                'group_work_report' => 'تقرير عمل مجموعة القصاصين',
            ],
        ],
        'tailors' => [
            'name_ar' => 'الخياطين',
            'core' => [
                'view' => 'عرض قائمة الخياطين',
                'create' => 'إضافة خيّاط',
                'update' => 'تعديل بيانات الخيّاط',
                'delete' => 'حذف الخيّاط',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو إيقاف الخيّاط',
                'statement_financial' => 'كشف حساب مالي للخياط',
                'statement_work' => 'كشف عمل للخياط',
                'group_work_report' => 'تقرير عمل مجموعة الخياطين',
            ],
        ],
        'accounts' => [
            'name_ar' => 'الحسابات',
            'core' => [
                'view' => 'عرض دليل الحسابات',
                'create' => 'إضافة حساب جديد',
                'update' => 'تعديل بيانات الحساب',
                'delete' => 'حذف الحساب',
            ],
            'extra' => [
                'structure_manage' => 'إدارة هيكل الشجرة المحاسبية',
                // كل كشف مالي له صلاحية مستقلة بدل `accounts.statement` العام: كشف الحساب
                // المالي، وميزان مجموعة الحسابات الجزئية، والكشف التحليلي المفصّل.
                // Each financial statement now carries its own code instead of one broad grant.
                'statement_financial' => 'كشف حساب مالي',
                'statement_group' => 'كشف حساب مجموعة حسابات جزئية',
                'statement_detail' => 'كشف حساب تحليلي مفصل',
                'print' => 'طباعة الكشوف المالية',
                'export' => 'تصدير الكشوف المالية',
            ],
        ],
        'currencies' => [
            'name_ar' => 'العملات',
            'core' => [
                'view' => 'عرض دليل العملات',
                'create' => 'إضافة عملة جديدة',
                'update' => 'تعديل بيانات العملة',
                'delete' => 'حذف العملة',
            ],
            'extra' => [
                'prices_view' => 'عرض أسعار صرف العملات',
                'prices_manage' => 'إضافة وتعديل أسعار الصرف',
            ],
        ],
        // ── القيود والسندات: ست وحدات مستقلة، لأن رؤية القيد المرحل لا تعني رؤية المؤقت،
        //    والقبض لا يعني الصرف. القيد المرحل والمؤقت منفصلان عمداً (ترحيل = منح صريح).
        // ── Entries & vouchers: six independent modules — seeing a posted entry is not seeing a
        //    temporary one, and receipts are not payments. Posting is its own explicit grant.
        'journal' => [
            'name_ar' => 'القيود المرحلة',
            'core' => [
                'view' => 'عرض القيود المرحلة',
                'create' => 'إضافة قيد يومية مرحل',
                'update' => 'تعديل قيد يومية مرحل',
                'delete' => 'حذف قيد يومية مرحل',
            ],
            'extra' => [
                'closing_create' => 'إضافة وتعديل قيد إقفال',
                'print' => 'طباعة القيود المرحلة',
                'export' => 'تصدير القيود المرحلة',
            ],
        ],
        'journal_temp' => [
            'name_ar' => 'القيود المؤقتة',
            'core' => [
                'view' => 'عرض القيود المؤقتة',
                'create' => 'إضافة قيد مؤقت',
                'update' => 'تعديل قيد مؤقت',
                'delete' => 'حذف قيد مؤقت',
            ],
            'extra' => [
                'post' => 'ترحيل القيد المؤقت',
                'print' => 'طباعة القيود المؤقتة',
                'export' => 'تصدير القيود المؤقتة',
            ],
        ],
        'receipts' => [
            'name_ar' => 'سندات القبض',
            'core' => [
                'view' => 'عرض سندات القبض',
                'create' => 'إضافة سند قبض',
                'update' => 'تعديل سند قبض',
                'delete' => 'حذف سند قبض',
            ],
            'extra' => [
                'print' => 'طباعة سندات القبض',
                'export' => 'تصدير سندات القبض',
            ],
        ],
        'payments' => [
            'name_ar' => 'سندات الصرف',
            'core' => [
                'view' => 'عرض سندات الصرف',
                'create' => 'إضافة سند صرف',
                'update' => 'تعديل سند صرف',
                'delete' => 'حذف سند صرف',
            ],
            'extra' => [
                'print' => 'طباعة سندات الصرف',
                'export' => 'تصدير سندات الصرف',
            ],
        ],
        'fin_search' => [
            'name_ar' => 'البحث عن عملية مالية',
            'core' => ['view' => 'البحث في القيود والسندات'],
            'extra' => [],
        ],
        'wages' => [
            'name_ar' => 'احتساب الأجور',
            'core' => ['view' => 'عرض كشوف أجور القصاصين والخياطين'],
            'extra' => ['calculate' => 'احتساب الأجور'],
        ],
        'invoices' => [
            'name_ar' => 'الفواتير',
            'core' => ['view' => 'عرض قائمة الفواتير'],
            'extra' => [
                'print' => 'طباعة الفواتير',
                'export' => 'تصدير الفواتير',
            ],
        ],
        // المخازن: قسم كامل بأوامر حركة وأصناف ومرجعيات وتقارير وكشف جرد.
        // كل نوع مستند له أفعاله الأربعة، والأصناف والمرجعيات والأمناء كلٌّ بصلاحيته، لأن
        // رؤية أمر لا تعني إنشاءه، ورؤية الصنف لا تعني تعديله.
        // Stores: a full module with movement orders, items, reference data, reports and a
        // counting sheet. Every document kind carries its own four actions, items/reference
        // data/keepers are separately granted — seeing a document is not creating one.
        'stores' => [
            'name_ar' => 'المخازن',
            'core' => [
                'view' => 'عرض المخازن',
                'create' => 'إضافة مخزن',
                'update' => 'تعديل بيانات المخزن',
                'delete' => 'حذف المخزن',
            ],
            'extra' => [
                'keepers_view' => 'عرض أمناء المخازن',
                'keepers_manage' => 'إدارة أمناء المخازن',
                'receiving_view' => 'عرض أوامر التوريد',
                'receiving_create' => 'إنشاء أمر توريد',
                'receiving_update' => 'تعديل أمر توريد',
                'receiving_delete' => 'حذف أمر توريد',
                'issuing_view' => 'عرض أوامر الصرف',
                'issuing_create' => 'إنشاء أمر صرف',
                'issuing_update' => 'تعديل أمر صرف',
                'issuing_delete' => 'حذف أمر صرف',
                'transfer_view' => 'عرض أوامر التحويل',
                'transfer_create' => 'إنشاء أمر تحويل',
                'transfer_update' => 'تعديل أمر تحويل',
                'transfer_delete' => 'حذف أمر تحويل',
                'items_view' => 'عرض الأصناف',
                'items_create' => 'إضافة صنف',
                'items_update' => 'تعديل صنف',
                'items_delete' => 'حذف صنف',
                'catalogs_view' => 'عرض المجموعات والوحدات والفئات',
                'catalogs_manage' => 'إدارة المجموعات والوحدات والفئات',
                'reports_view' => 'عرض تقارير المخازن والأرصدة',
                'reports_print' => 'طباعة تقارير المخازن',
                'reports_export' => 'تصدير تقارير المخازن',
                // الجرد كشف عدّ حي بلا حفظ: عرضه منحنى، وطباعته وتصديره منحان صريحان.
                // The stocktake is a live counting sheet: viewing it is one grant, printing
                // and exporting are two explicit ones.
                'stocktake_view' => 'عرض كشف الجرد',
                'stocktake_print' => 'طباعة كشف الجرد',
                'stocktake_export' => 'تصدير كشف الجرد',
            ],
        ],
        'reports' => [
            'name_ar' => 'التقارير',
            'core' => ['view' => 'عرض التقارير'],
            'extra' => ['export' => 'تصدير التقارير'],
        ],
        'management' => [
            'name_ar' => 'الإدارة',
            'core' => ['view' => 'عرض قسم الإدارة'],
            'extra' => [],
        ],
        'users' => [
            'name_ar' => 'إدارة المستخدمين',
            'core' => [
                'view' => 'عرض قائمة المستخدمين',
                'create' => 'إضافة مستخدم جديد',
                'update' => 'تعديل بيانات المستخدم',
                'delete' => 'حذف المستخدم',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو تعطيل المستخدم',
                'permissions_manage' => 'منح وسحب صلاحيات المستخدمين',
                'activity_view' => 'عرض سجل نشاط المستخدمين',
                'admin_flag_manage' => 'تعيين أو إلغاء صفة مدير النظام',
            ],
        ],
        'sessions' => [
            'name_ar' => 'الجلسات النشطة',
            'core' => ['view' => 'عرض الجلسات النشطة'],
            'extra' => ['terminate' => 'إنهاء جلسة مستخدم'],
        ],
        'settings' => [
            'name_ar' => 'إعدادات الأمان',
            'core' => [
                'view' => 'عرض إعدادات الأمان',
                'update' => 'تعديل إعدادات الأمان',
            ],
            'extra' => ['security_manage' => 'إدارة الإعدادات الحساسة للمستخدمين والجلسات'],
        ],
        // إعدادات التطبيق: هوية الشركة والمظهر وإعدادات اتصال API
        // Application configuration: company identity, theming, and API connection settings.
        'app_config' => [
            'name_ar' => 'إعدادات التطبيق',
            'core' => [
                'view' => 'عرض إعدادات التطبيق',
                'update' => 'تعديل معلومات الشركة والمظهر',
            ],
            'extra' => ['api_manage' => 'إدارة إعدادات اتصال API'],
        ],
        // الخدمات والمزودون: بوابة تقديم الرسائل والواتساب وإدارة المزودين والمهام الزمنية
        // Services & providers: message/whatsapp delivery, provider registries and scheduler jobs.
        'services' => [
            'name_ar' => 'الخدمات والمزودين',
            'core' => [
                'view' => 'عرض قسم الخدمات والمزودين',
                'update' => 'تعديل بيانات الخدمات (SERVICES)',
            ],
            'extra' => [
                'settings_manage' => 'إدارة إعدادات الخدمات (جدول CORP)',
            ],
        ],
        'providers' => [
            'name_ar' => 'إدارة المزودين',
            'core' => [
                'view' => 'عرض قائمة المزودين',
                'create' => 'إضافة مزود جديد',
                'update' => 'تعديل بيانات المزود',
                'delete' => 'حذف المزود',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو تعطيل المزود',
                'subscriptions_manage' => 'إدارة اشتراكات المزودين (PROVIDERS_DTL)',
                'reveal_credentials' => 'إظهار بيانات اعتماد المزود الحساسة',
            ],
        ],
        'sms' => [
            'name_ar' => 'خدمة الرسائل النصية',
            'core' => [
                'view' => 'عرض سجل الرسائل النصية',
                'create' => 'إضافة/إرسال رسالة نصية',
                'update' => 'تعديل رسالة نصية',
                'delete' => 'حذف رسالة نصية',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو تعطيل الرسالة النصية',
            ],
        ],
        'whatsapp' => [
            'name_ar' => 'خدمة رسائل واتساب',
            'core' => [
                'view' => 'عرض سجل رسائل واتساب',
                'create' => 'إضافة/إرسال رسالة واتساب',
                'update' => 'تعديل رسالة واتساب',
                'delete' => 'حذف رسالة واتساب',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو تعطيل رسالة واتساب',
            ],
        ],
        'jobs' => [
            'name_ar' => 'إدارة المهام الزمنية',
            'core' => [
                'view' => 'عرض المهام وسجل التشغيل',
                'create' => 'إضافة مهمة زمنية',
                'update' => 'تعديل المهلة أو الأمر',
                'delete' => 'حذف المهمة',
            ],
            'extra' => [
                'toggle' => 'تفعيل أو تعطيل المهمة',
            ],
        ],
        // الدعم والاستفسارات: لا فعل `create` هنا — الإرسال خدمة ذاتية من شاشة معلومات
        // البرنامج، وتعريفه صلاحيةً كان سيحجب النموذج عن الزائر قبل الدخول أصلاً.
        // Support & inquiries: no `create` action — submitting is a self-service call from
        // the app-info screen, and making it a grant would hide the form from pre-login guests.
        'support' => [
            'name_ar' => 'الدعم والاستفسارات',
            'core' => [
                'view' => 'عرض استفسارات الدعم',
                'update' => 'الرد على الاستفسار وإغلاقه',
            ],
            'extra' => [],
        ],
        // الكشوفات الشخصية: كشف مالي وكشف عمل خاصان بالموظف الحالي دون اختيار حساب
        // Personal statements: financial and work statements scoped to the logged-in employee only.
        'my_statement' => [
            'name_ar' => 'الكشوفات الشخصية',
            'core' => [
                'view' => 'عرض الكشوفات الشخصية',
            ],
            'extra' => [
                'financial' => 'كشف حسابي المالي الخاص بي',
                'work' => 'كشف عملي الخاص بي',
            ],
        ],
        'system' => [
            'name_ar' => 'النظام',
            'core' => [],
            'extra' => [self::WILDCARD_ACTION => 'جميع صلاحيات النظام (منح شامل)'],
        ],
    ];

    /**
     * أسماء الوحدات بالعربية مفهرسة برمز الوحدة — لعرضها في واجهة اختيار الصلاحيات
     * Module display names keyed by module code (for the permission picker UI)
     *
     * @return array<string, string>
     */
    public static function moduleNames(): array
    {
        $names = [];

        foreach (self::MODULES as $moduleCode => $module) {
            $names[$moduleCode] = $module['name_ar'];
        }

        return $names;
    }

    /**
     * الكتالوج كاملاً بصفوف جاهزة للإدراج في APP_PERMISSIONS
     * The full catalog as rows ready for insertion into APP_PERMISSIONS
     *
     * الترتيب داخل كل وحدة: صف المنح العام ثم الأساسية ثم الإضافية،
     * و SORT_ORDER = رقم الوحدة × 100 + رقم الفعل  ليظهر الأساسية قبل الإضافي
     * في أي عرض لا يطبّق منطق ترتيب خاصاً به.
     * Inside each module: the module wildcard first, then core actions, then extras.
     *
     * @return array<int, array{
     *     permission_code: string, module_code: string, action_code: string,
     *     name_ar: string, name_en: string, is_core: int, sort_order: int,
     *     description_ar: string
     * }>
     */
    public static function rows(): array
    {
        $rows = [];
        $moduleIndex = 0;

        foreach (self::MODULES as $moduleCode => $module) {
            $moduleIndex++;
            $base = $moduleIndex * 100;

            // صلاحيات وحدة "system" هي نفسها صلاحيات المنح العام فلا نكرّرها
            // The system module holds only its wildcard — do not duplicate it
            if ($moduleCode !== 'system') {
                $rows[] = self::row(
                    permissionCode: $moduleCode.'.'.self::WILDCARD_ACTION,
                    moduleCode: $moduleCode,
                    actionCode: self::WILDCARD_ACTION,
                    nameAr: 'كل صلاحيات «'.$module['name_ar'].'»',
                    nameEn: 'All '.$moduleCode.' permissions',
                    isCore: 0,
                    sortOrder: $base,
                    descriptionAr: 'منح شامل لكل صلاحيات وحدة '.$module['name_ar'].' الحالية والمستقبلية'
                );
            }

            $actionIndex = 0;

            foreach (['core' => 1, 'extra' => 0] as $group => $isCore) {
                foreach ($module[$group] as $actionCode => $nameAr) {
                    $actionIndex++;

                    $rows[] = self::row(
                        permissionCode: $moduleCode.'.'.$actionCode,
                        moduleCode: $moduleCode,
                        actionCode: $actionCode,
                        nameAr: $nameAr,
                        nameEn: self::humanize($moduleCode, $actionCode),
                        isCore: $isCore,
                        sortOrder: $base + $actionIndex,
                        descriptionAr: $nameAr.' — وحدة '.$module['name_ar']
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * بناء صف واحد ببنية موحّدة
     * Build a single catalog row with a uniform shape
     *
     * @return array<string, mixed>
     */
    private static function row(
        string $permissionCode,
        string $moduleCode,
        string $actionCode,
        string $nameAr,
        string $nameEn,
        int $isCore,
        int $sortOrder,
        string $descriptionAr
    ): array {
        return [
            'permission_code' => $permissionCode,
            'module_code' => $moduleCode,
            'action_code' => $actionCode,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'is_core' => $isCore,
            'sort_order' => $sortOrder,
            'description_ar' => $descriptionAr,
        ];
    }

    /**
     * تحويل رمز الفعل إلى عنوان إنجليزي مقروء
     * Turn an action code into a readable English label
     */
    private static function humanize(string $moduleCode, string $actionCode): string
    {
        $labels = [
            'view' => 'View',
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            'print' => 'Print',
            'export' => 'Export',
            'statement' => 'Statement',
            'statement_financial' => 'Financial statement',
            'statement_group' => 'Group trial-balance statement',
            'statement_detail' => 'Detailed analytical statement',
            'statement_work' => 'Work statement',
            'group_work_report' => 'Group work report',
            'toggle' => 'Activate / Deactivate',
            'deliver' => 'Deliver',
            'payments_view' => 'View payment details',
            'progress' => 'Track progress',
            'edit_limit' => 'Edit credit limit',
            // أفعال مركّبة لقسم المقاسات: بلا تسمية هنا يظهر الاسم الخام `Measurements_view`.
            // Compound actions of the measurements feature; without a label here the English
            // name would fall back to the raw token.
            'measurements_view' => 'View customer measurements',
            'measurements_print' => 'Print customer measurements',
            'measurements_export' => 'Export customer measurements',
            'structure_manage' => 'Manage account structure',
            'prices_view' => 'View exchange rates',
            'prices_manage' => 'Manage exchange rates',
            'permissions_manage' => 'Manage permissions',
            'activity_view' => 'View activity log',
            'admin_flag_manage' => 'Manage admin flag',
            'terminate' => 'Terminate session',
            'security_manage' => 'Manage security settings',
            'api_manage' => 'Manage API connection settings',
            'settings_manage' => 'Manage service settings',
            'subscriptions_manage' => 'Manage subscriptions',
            'reveal_credentials' => 'Reveal credentials',
            'financial' => 'Personal financial statement',
            'work' => 'Personal work statement',
            // أفعال قسم المخازن المركّبة — بلا تسمية هنا يظهر الاسم الخام `Receiving_view`.
            // Compound stores actions; without a label the English name would be the raw token.
            'keepers_view' => 'View store keepers',
            'keepers_manage' => 'Manage store keepers',
            'receiving_view' => 'View receive orders',
            'receiving_create' => 'Create receive order',
            'receiving_update' => 'Update receive order',
            'receiving_delete' => 'Delete receive order',
            'issuing_view' => 'View issue orders',
            'issuing_create' => 'Create issue order',
            'issuing_update' => 'Update issue order',
            'issuing_delete' => 'Delete issue order',
            'transfer_view' => 'View transfer orders',
            'transfer_create' => 'Create transfer order',
            'transfer_update' => 'Update transfer order',
            'transfer_delete' => 'Delete transfer order',
            'items_view' => 'View items',
            'items_create' => 'Create item',
            'items_update' => 'Update item',
            'items_delete' => 'Delete item',
            'catalogs_view' => 'View groups, units and categories',
            'catalogs_manage' => 'Manage groups, units and categories',
            'reports_view' => 'View store reports and balances',
            'reports_print' => 'Print store reports',
            'reports_export' => 'Export store reports',
            'stocktake_view' => 'View stocktake sheet',
            'stocktake_print' => 'Print stocktake sheet',
            'stocktake_export' => 'Export stocktake sheet',
            // أفعال المبيعات المركّبة (مردودات + تقارير) — بلا تسمية يظهر الاسم الخام.
            // Compound sales actions (returns + reports); without a label the raw token shows.
            'returns_view' => 'View sales returns',
            'returns_create' => 'Create sales return',
            'returns_update' => 'Update sales return',
            'returns_delete' => 'Delete sales return',
            'returns_print' => 'Print sales returns',
            'returns_export' => 'Export sales returns',
            'reports_view' => 'View sales reports',
            'reports_print' => 'Print sales reports',
            'reports_export' => 'Export sales reports',
            // أفعال القيود والأجور / journal & wages actions
            'closing_create' => 'Create closing entry',
            'post' => 'Post temporary entry',
            'calculate' => 'Calculate wages',
        ];

        return ($labels[$actionCode] ?? ucfirst($actionCode)).' — '.ucfirst($moduleCode);
    }

    /**
     * كل أكواد الصلاحيات الصالحة (بما فيها صيغ المنح العام)
     * Every valid permission code, wildcards included
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        $codes = [];

        foreach (self::rows() as $row) {
            $codes[] = $row['permission_code'];
        }

        return $codes;
    }

    /**
     * الكتالوج مجمّعاً حسب الوحدة — تُستهلكه واجهة اختيار الصلاحيات مباشرة
     * Catalog grouped by module — consumed directly by the permission picker
     *
     * @return array<int, array{module_code: string, name_ar: string, permissions: array<int, array<string, mixed>>}>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::rows() as $row) {
            $grouped[$row['module_code']] ??= [
                'module_code' => $row['module_code'],
                'name_ar' => self::MODULES[$row['module_code']]['name_ar'],
                'permissions' => [],
            ];

            $grouped[$row['module_code']]['permissions'][] = $row;
        }

        return array_values($grouped);
    }

    /**
     * هل الكود معرّف في الكتالوج؟
     * Is this code defined in the catalog?
     */
    public static function knows(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /**
     * العنوان العربي لصلاحية — لرسائل الرفض 403 المسموعة للمستخدم
     * Arabic label for a permission — used in user-facing 403 messages.
     * الأكواد غير المعروفة تُعرض برمزها كما هو حتى لا تنكشف تسميات داخلية عشوائية.
     * Unknown codes fall back to the raw code so nothing leaks an invented label.
     */
    public static function arabicName(string $code): string
    {
        foreach (self::rows() as $row) {
            if ($row['permission_code'] === $code) {
                return $row['name_ar'];
            }
        }

        return $code;
    }

    /**
     * هل الرمز المطابق صالح كصلاحية صريحة؟ (الأسماء المركّبة مرفوضة)
     * Validate an explicit permission code: lowercase, one dot, no partial wildcards.
     */
    public static function isValidCode(string $code): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]*\.(\*|[a-z][a-z0-9_]*)$/', $code) !== 1) {
            return false;
        }

        [$module] = explode('.', $code);

        return isset(self::MODULES[$module]);
    }

    /**
     * رمز المنح العام لوحدة معينة، أو null للوحدة غير المعروفة
     * Wildcard code for a module, or null when the module is unknown
     */
    public static function wildcardFor(string $moduleCode): ?string
    {
        if (! isset(self::MODULES[$moduleCode])) {
            return null;
        }

        return $moduleCode.'.'.self::WILDCARD_ACTION;
    }

    /**
     * عدد الصلاحيات في الكتالوج — يُستخدم في التحقق بعد البذر
     * Catalog size — used by the post-seed verification step
     */
    public static function count(): int
    {
        return count(self::rows());
    }
}
