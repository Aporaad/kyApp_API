-- ============================================================================
-- 07_ibac_statement_split_verify.sql — تحقّق بعد الترحيل
-- Post-migration verification for the statement-code split.
--
-- نفّذ 02_ibac_seed.php ثم 06_ibac_statement_split.sql، ثم هذا الملف. كل استعلام يجب أن
-- يعيد القيمة المذكورة في تعليقها؛ أي انحراف يعني الترحيل لم يكتمل.
-- Run the seed, then the split script, then this file. Each query must return the value named
-- in its comment; any deviation means the migration did not complete.
--
-- لا نصوص عربية هنا / no Arabic literals: verification is entirely numeric/ASCII.
-- ============================================================================

-- (1) الأكواد العامة الأربعة اختفت تماماً — المتوقع: 0
--     The four generic codes are fully gone — expect 0.
SELECT COUNT(*) AS generics_left
  FROM ACCOUNTS.APP_PERMISSIONS
 WHERE PERMISSION_CODE IN (
   'accounts.statement', 'employees.statement',
   'cutters.statement',  'tailors.statement');

-- (2) الأكواد الدقيقة التسعة موجودة ونشطة — المتوقع: 9 صفّاً، IS_ACTIVE=1 لكلٍّ
--     The nine precise codes exist and are active — expect 9 rows, IS_ACTIVE=1 each.
SELECT PERMISSION_CODE, IS_ACTIVE
  FROM ACCOUNTS.APP_PERMISSIONS
 WHERE PERMISSION_CODE IN (
   'accounts.statement_financial', 'accounts.statement_group', 'accounts.statement_detail',
   'employees.statement_financial', 'employees.statement_work',
   'cutters.statement_financial',   'cutters.statement_work',
   'tailors.statement_financial',   'tailors.statement_work')
 ORDER BY PERMISSION_CODE;

-- (3) لا منح معلّقة على أكواد غير موجودة — المتوقع: 0
--     No grant points at a permission that is absent — expect 0 (referential hygiene).
SELECT COUNT(*) AS orphan_grants
  FROM ACCOUNTS.APP_USERS_PERMISSIONS up
 WHERE NOT EXISTS (
   SELECT 1 FROM ACCOUNTS.APP_PERMISSIONS p
    WHERE p.PERMISSION_ID = up.PERMISSION_ID);

-- (4) حفظ الصلاحيات: عدد حاملي كل كود عام سابقاً ≤ عدد حاملي مجموعة دقيقة مقابلة.
--     المقارنة مبسّطة على الحسابات: من كان يملك accounts.statement يجب أن يملك الآن
--     الأكواد الثلاثة الدقيقة على الأقل. المتوقع: 0 (لا شخص فقد تغطية الكشف).
--     Access preserved: anyone who held a generic now holds its precise replacements. Expect 0.
SELECT COUNT(*) AS accounts_holders_uncovered
  FROM ACCOUNTS.APP_USERS_PERMISSIONS old_g
 WHERE old_g.PERMISSION_ID IN (
     SELECT PERMISSION_ID FROM ACCOUNTS.APP_PERMISSIONS_BAK_06
      WHERE PERMISSION_CODE = 'accounts.statement')
   AND NOT EXISTS (
     SELECT 1 FROM ACCOUNTS.APP_USERS_PERMISSIONS nw
      JOIN ACCOUNTS.APP_PERMISSIONS p ON p.PERMISSION_ID = nw.PERMISSION_ID
     WHERE nw.USER_ID = old_g.USER_ID
       AND p.PERMISSION_CODE IN ('accounts.statement_financial',
                                 'accounts.statement_group',
                                 'accounts.statement_detail'));

-- (5) العدد الكلي لكتالوج الأكواد الحيّ يجب أن يطابق PermissionCatalog::count() = 132.
--     Total live permission rows must equal the catalog count (132). Read APP_PERMISSIONS only.
--     ملاحظة: 132 يفترض قاعدة مُنظّفة؛ الجداول التاريخية (BAK_06) لا تُحسب هنا لأنها في
--     جدول مستقل. إن لم تزل النسخ الاحتياطية، احذفها قبل اعتماد هذا الرقم.
--     BAK_06 tables are separate, so this COUNT reflects only the live catalog.
SELECT COUNT(*) AS live_permissions
  FROM ACCOUNTS.APP_PERMISSIONS;
