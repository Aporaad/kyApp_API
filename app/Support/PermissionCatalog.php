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
        'invoices' => [
            'name_ar' => 'الفواتير',
            'core' => ['view' => 'عرض قائمة الفواتير'],
            'extra' => [
                'print' => 'طباعة الفواتير',
                'export' => 'تصدير الفواتير',
            ],
        ],
        'stores' => [
            'name_ar' => 'المخازن',
            'core' => [
                'view' => 'عرض المخازن',
                'create' => 'إضافة مخزن',
                'update' => 'تعديل بيانات المخزن',
                'delete' => 'حذف المخزن',
            ],
            'extra' => [],
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
            'edit_limit' => 'Edit credit limit',
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
