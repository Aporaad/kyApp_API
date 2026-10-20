-- ============================================================================
-- IBAC — التراجع الكامل (مرحلة 1) / Full rollback for the IBAC schema
-- يَحذف الكائنات الجديدة ولا يمسّ بيانات الأعمال. IS_ADMIN يبقى إلا لو طُلب عكس ذلك.
-- Drops only the new IBAC objects; business data is untouched. IS_ADMIN stays unless the
-- user explicitly asks for a complete removal (statement at the end of this file).
--
-- قبل التنفيذ / before running, restore the pre-change users table if it was altered:
--   UPDATE ACCOUNTS.APP_USERS u SET (IS_ADMIN) =
--     (SELECT b.IS_ADMIN FROM ACCOUNTS.APP_USERS_BAK_IBAC b WHERE b.USER_ID = u.USER_ID)
--    WHERE EXISTS (SELECT 1 FROM ACCOUNTS.APP_USERS_BAK_IBAC b WHERE b.USER_ID = u.USER_ID);
-- ============================================================================

-- 1) سجل النشاط / activity log
DROP TABLE ACCOUNTS.APP_USER_ACTIVITY PURGE;
DROP SEQUENCE ACCOUNTS.SEQ_APP_ACTIVITY;

-- 2) إعدادات الأمان / security settings
DROP TABLE ACCOUNTS.APP_SETTINGS PURGE;
DROP SEQUENCE ACCOUNTS.SEQ_APP_SETTINGS;

-- 3) منح المستخدمين ثم الكتالوج (ترتيب الالتزامات معكوس)
--    user grants first, then the catalog — reverse dependency order
DROP TABLE ACCOUNTS.APP_USERS_PERMISSIONS PURGE;
DROP SEQUENCE ACCOUNTS.SEQ_APP_USERS_PERM;

DROP TABLE ACCOUNTS.APP_PERMISSIONS PURGE;
DROP SEQUENCE ACCOUNTS.SEQ_APP_PERMISSIONS;

-- 4) تسلسل معرّفات المستخدمين الجديد (لا يمس أي بيانات)
--    new user-id sequence only — no data is removed
DROP SEQUENCE ACCOUNTS.SEQ_APP_USERS_ID;

-- 5) استرجاع عمودي APP_USERS إن لزم / optional APP_USERS restore
--    ALTER TABLE ACCOUNTS.APP_USERS DROP (IS_ADMIN);
--    ALTER TABLE ACCOUNTS.APP_USERS MODIFY (USER_PASSWORD VARCHAR2(30));
--    -- تحذير: لا يُنفَّذ توسيع/تضييق العمود إلا بعد التأكد أن أطوال كلمات المرور الحالية
--    -- ما زالت داخل الحد الجديد، وإلا فشل الأمر بـ ORA-02292/ORA-14047.
--    -- Warning: narrowing USER_PASSWORD fails if any stored value is longer than 30 bytes.

-- 6) نقطة الاسترجاع / rollback snapshot
--    DROP TABLE ACCOUNTS.APP_USERS_BAK_IBAC PURGE;
