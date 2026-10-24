-- ============================================================================
-- 06_ibac_statement_split.sql — ترحيل أكواد الكشوف العامة إلى الدقيقة
-- Migrate the broad statement codes into their precise replacements (full replacement).
--
-- قاعدة البيانات / Target: Oracle 11g · المخطط ACCOUNTS
--
-- السبب / Why:
--   استُبدلت الأكواد العامة الأربعة بأكواد دقيقة في PermissionCatalog:
--     accounts.statement    → statement_financial · statement_group · statement_detail
--     employees.statement   → statement_financial · statement_work
--     cutters.statement     → statement_financial · statement_work
--     tailors.statement     → statement_financial · statement_work
--   الترحيل لا يقلّل صلاحية أحد: من يملك الكود العام يحصل تلقائياً على كل الأكواد
--   الدقيقة المقابلة، ثم يُزال الكود العام ومنحه (استبدال كامل بلا بقايا).
--   Migration never reduces anyone's access: a holder of a broad code receives EVERY precise
--   replacement automatically, then the retired code and its grants are removed.
--
-- الترتيب / Order of operations (IMPORTANT):
--   1) شغّل البذر أولاً حتى تُنشأ الصفوف التسعة الجديدة (وهي عربية المصدر):
--        php artisan seed:ibac     (database/oracle/02_ibac_seed.php — idempotent upsert)
--   2) ثم نفّذ هذا الملف لترحيل المنح وإلغاء الأكواد العامة.
--   This file carries NO Arabic literals: an AL32UTF8 driver corrupts CP1256 bytes, and every
--   value it writes (USER_ID, PERMISSION_ID, flags) is numeric/ASCII anyway. Arabic names for the
--   new rows come from the PHP seed, not from here.
--
-- نقطة استرجاع / Rollback snapshot before running:
--   CREATE TABLE ACCOUNTS.APP_USERS_PERMISSIONS_BAK_06 AS SELECT * FROM ACCOUNTS.APP_USERS_PERMISSIONS;
--   CREATE TABLE ACCOUNTS.APP_PERMISSIONS_BAK_06         AS SELECT * FROM ACCOUNTS.APP_PERMISSIONS;
-- ============================================================================

DECLARE
  TYPE code_list IS TABLE OF VARCHAR2(60) INDEX BY PLS_INTEGER;
  TYPE perm_map  IS TABLE OF code_list    INDEX BY VARCHAR2(60);

  l_map     perm_map;
  l_targets code_list;

  CURSOR c_generics IS
    SELECT PERMISSION_ID, PERMISSION_CODE
      FROM ACCOUNTS.APP_PERMISSIONS
     WHERE PERMISSION_CODE IN (
       'accounts.statement', 'employees.statement',
       'cutters.statement',  'tailors.statement');
  v_gen c_generics%ROWTYPE;

  CURSOR c_grants (p_permission_id NUMBER) IS
    SELECT USER_ID, GRANTED_BY, GRANTED_ON, EXPIRES_ON, IS_ACTIVE, NOTE
      FROM ACCOUNTS.APP_USERS_PERMISSIONS
     WHERE PERMISSION_ID = p_permission_id;
  v_g c_grants%ROWTYPE;

  l_new_id NUMBER;
  l_dup    NUMBER;
BEGIN
  -- خريطة الأكواد العامة → الدقيقة / generic → precise map
  l_map('accounts.statement')(1) := 'accounts.statement_financial';
  l_map('accounts.statement')(2) := 'accounts.statement_group';
  l_map('accounts.statement')(3) := 'accounts.statement_detail';

  l_map('employees.statement')(1) := 'employees.statement_financial';
  l_map('employees.statement')(2) := 'employees.statement_work';

  l_map('cutters.statement')(1) := 'cutters.statement_financial';
  l_map('cutters.statement')(2) := 'cutters.statement_work';

  l_map('tailors.statement')(1) := 'tailors.statement_financial';
  l_map('tailors.statement')(2) := 'tailors.statement_work';

  -- 1) ترحيل المنح: لكل حامل للكود العام، امنحه كل كود دقيق (تجاهل المكرر)
  --    Per grant of a generic code, grant every precise replacement (skip duplicates).
  FOR v_gen IN c_generics LOOP
    IF NOT l_map.EXISTS(v_gen.PERMISSION_CODE) THEN
      CONTINUE;
    END IF;

    l_targets := l_map(v_gen.PERMISSION_CODE);

    FOR i IN 1 .. l_targets.COUNT LOOP
      BEGIN
        SELECT PERMISSION_ID
          INTO l_new_id
          FROM ACCOUNTS.APP_PERMISSIONS
         WHERE PERMISSION_CODE = l_targets(i);
      EXCEPTION
        WHEN NO_DATA_FOUND THEN
          RAISE_APPLICATION_ERROR(
            -20011, 'B_seed_first: missing precise permission ' || l_targets(i) ||
            '. Run 02_ibac_seed.php before this script.');
      END;

      FOR v_g IN c_grants(v_gen.PERMISSION_ID) LOOP
        SELECT COUNT(*)
          INTO l_dup
          FROM ACCOUNTS.APP_USERS_PERMISSIONS
         WHERE USER_ID = v_g.USER_ID
           AND PERMISSION_ID = l_new_id;

        IF l_dup = 0 THEN
          INSERT INTO ACCOUNTS.APP_USERS_PERMISSIONS
            (USER_ID, PERMISSION_ID, GRANTED_BY, GRANTED_ON, EXPIRES_ON, IS_ACTIVE, NOTE)
          VALUES
            (v_g.USER_ID, l_new_id, v_g.GRANTED_BY, v_g.GRANTED_ON,
             v_g.EXPIRES_ON, v_g.IS_ACTIVE, 'migrated-from-' || v_gen.PERMISSION_CODE);
        END IF;
      END LOOP;
    END LOOP;
  END LOOP;

  -- 2) تنظيف منح الأكواد العامة ثم حذف صفوفها (استبدال كامل)
  --    Drop the generics' grants, then remove the generic rows themselves.
  DELETE FROM ACCOUNTS.APP_USERS_PERMISSIONS
   WHERE PERMISSION_ID IN (
     SELECT PERMISSION_ID FROM ACCOUNTS.APP_PERMISSIONS
      WHERE PERMISSION_CODE IN (
        'accounts.statement', 'employees.statement',
        'cutters.statement',  'tailors.statement'));

  DELETE FROM ACCOUNTS.APP_PERMISSIONS
   WHERE PERMISSION_CODE IN (
     'accounts.statement', 'employees.statement',
     'cutters.statement',  'tailors.statement');

  COMMIT;
END;
/

-- ملاحظة: لا تُشغّل هذا الملف مرتين فوق قاعدة مُرحّلة بالفعل إلا بعد إعادة البذر؛ ففي المرة
-- الثانية لا يوجد منح عام لترحيله، وحذف الأكواد العامة يكون تم أصلاً — فلا أثر (idempotent-ish
-- فقط إن سبَقه البذر). أما الترحيل الحقيقي فيُقرأ من منح العام قبل حذفها.
-- Running twice is harmless once the generics are gone (no grants to move, nothing left to delete),
-- provided the seed created the precise rows first.
