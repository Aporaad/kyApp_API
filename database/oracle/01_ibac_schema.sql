-- ============================================================================
-- IBAC — نموذج البيانات (المرحلة 1) / IBAC schema (Phase 1)
-- قاعدة البيانات: Oracle 11g · المخطط: ACCOUNTS · الترميز: WE8MSWIN1252
-- Database: Oracle 11g · schema ACCOUNTS · charset WE8MSWIN1252 (Arabic = CP1256 bytes)
--
-- ينفَّذ بSQLPlus أو عبر موصل Oracle:  @01_ibac_schema.sql
-- Run with SQL*Plus or an Oracle client. Execute ONE statement at a time when using a
-- driver that does not understand "/" separators (the Oracle MCP server used here works
-- statement-by-statement and simply omits the trailing "/").
--
-- ملاحظة / Note: لا تُنفَّذ أحرف عربية داخل هذا الملف عبر موصل أوراكل؛ فترميز العميل
-- AL32UTF8 يحوّل بايتات CP1256 إلى علامة استبدال. النصوص العربية تُزرع عبر
-- 02_ibac_seed.php و 03_ibac_bootstrap.php باستخدام ArabicEncoding::toOracleSearchChars().
-- Arabic text is NOT seeded from this file: an AL32UTF8 client turns raw CP1256 bytes into
-- replacement characters (0xBF). Arabic values are seeded by the PHP scripts instead.
--
-- نقطة الاسترجاع قبل التنفيذ / Rollback snapshot taken first:
--   CREATE TABLE ACCOUNTS.APP_USERS_BAK_IBAC AS SELECT * FROM ACCOUNTS.APP_USERS;
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) APP_USERS — تعديل الجدول الموجود مسبقاً (النظام القديم يكتب فيه أيضاً)
--    APP_USERS is a pre-existing legacy table: widen the password column, add the admin
--    flag, and add a sequence WITHOUT a trigger (a trigger here could break the ERP).
-- ---------------------------------------------------------------------------
ALTER TABLE ACCOUNTS.APP_USERS MODIFY (USER_PASSWORD VARCHAR2(100));

ALTER TABLE ACCOUNTS.APP_USERS ADD (
  IS_ADMIN NUMBER(1) DEFAULT 0 NOT NULL
);

CREATE SEQUENCE ACCOUNTS.SEQ_APP_USERS_ID
  START WITH 100 INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE;

-- ---------------------------------------------------------------------------
-- 2) APP_PERMISSIONS — كتالوج الصلاحيات / Permission catalog
-- ---------------------------------------------------------------------------
CREATE TABLE ACCOUNTS.APP_PERMISSIONS (
  PERMISSION_ID     NUMBER(6)      NOT NULL,
  PERMISSION_CODE   VARCHAR2(60)   NOT NULL,
  MODULE_CODE       VARCHAR2(30)   NOT NULL,
  ACTION_CODE       VARCHAR2(30)   NOT NULL,
  NAME_AR           VARCHAR2(150)  NOT NULL,
  NAME_EN           VARCHAR2(150),
  IS_CORE           NUMBER(1)      DEFAULT 0 NOT NULL,
  SORT_ORDER        NUMBER(4)      DEFAULT 0,
  IS_ACTIVE         NUMBER(1)      DEFAULT 1 NOT NULL,
  DESCRIPTION_AR    VARCHAR2(500),
  CREATED_ON        DATE           DEFAULT SYSDATE,
  CREATED_BY        NUMBER(5),
  LAST_UPDATED_ON   DATE,
  LAST_UPDATED_BY   NUMBER(5),
  CONSTRAINT APP_PERM_PK       PRIMARY KEY (PERMISSION_ID),
  CONSTRAINT APP_PERM_CODE_UQ  UNIQUE (PERMISSION_CODE),
  CONSTRAINT APP_PERM_CORE_CK  CHECK (IS_CORE IN (0, 1)),
  CONSTRAINT APP_PERM_ACT_CK   CHECK (IS_ACTIVE IN (0, 1))
);

CREATE INDEX ACCOUNTS.APP_PERM_MOD_IX ON ACCOUNTS.APP_PERMISSIONS (MODULE_CODE, SORT_ORDER);

CREATE SEQUENCE ACCOUNTS.SEQ_APP_PERMISSIONS
  START WITH 1 INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE;

-- أسماء التسلسلات تُذكر بلا بادئة مخطط: يوجد VIEW باسم ACCOUNTS داخل المخطط،
-- وكتابة ACCOUNTS.SEQ_X تُقرأ كعمود من العرض وتفشل بالخطأ PLS-00302.
-- Sequence names inside PL/SQL must stay UNQUALIFIED: the schema contains a VIEW named
-- ACCOUNTS, so ACCOUNTS.SEQ_X resolves as view.column and fails with PLS-00302.
CREATE OR REPLACE TRIGGER ACCOUNTS.TRG_APP_PERMISSIONS
BEFORE INSERT ON ACCOUNTS.APP_PERMISSIONS FOR EACH ROW
BEGIN
  IF :NEW.PERMISSION_ID IS NULL THEN
    SELECT SEQ_APP_PERMISSIONS.NEXTVAL INTO :NEW.PERMISSION_ID FROM DUAL;
  END IF;
END;
/

-- ---------------------------------------------------------------------------
-- 3) APP_USERS_PERMISSIONS — منح مباشر للمستخدم (نموذج IBAC)
--    Direct user -> permission grants (the IBAC core; no roles)
-- ---------------------------------------------------------------------------
CREATE TABLE ACCOUNTS.APP_USERS_PERMISSIONS (
  ID              NUMBER(9)   NOT NULL,
  USER_ID         NUMBER(5)   NOT NULL,
  PERMISSION_ID   NUMBER(6)   NOT NULL,
  GRANTED_BY      NUMBER(5),
  GRANTED_ON      DATE        DEFAULT SYSDATE,
  EXPIRES_ON      DATE,
  IS_ACTIVE       NUMBER(1)   DEFAULT 1 NOT NULL,
  NOTE            VARCHAR2(200),
  CONSTRAINT APP_UQ_PK         PRIMARY KEY (ID),
  CONSTRAINT APP_USER_PERM_UQ  UNIQUE (USER_ID, PERMISSION_ID),
  CONSTRAINT APP_UQ_USER_FK    FOREIGN KEY (USER_ID)
    REFERENCES ACCOUNTS.APP_USERS (USER_ID) ON DELETE CASCADE,
  CONSTRAINT APP_UQ_PERM_FK    FOREIGN KEY (PERMISSION_ID)
    REFERENCES ACCOUNTS.APP_PERMISSIONS (PERMISSION_ID),
  CONSTRAINT APP_UQ_ACTIVE_CK  CHECK (IS_ACTIVE IN (0, 1))
);

CREATE INDEX ACCOUNTS.APP_UQ_USER_IX ON ACCOUNTS.APP_USERS_PERMISSIONS (USER_ID, IS_ACTIVE);
CREATE INDEX ACCOUNTS.APP_UQ_PERM_IX ON ACCOUNTS.APP_USERS_PERMISSIONS (PERMISSION_ID);

CREATE SEQUENCE ACCOUNTS.SEQ_APP_USERS_PERM
  START WITH 1 INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER ACCOUNTS.TRG_APP_USERS_PERM
BEFORE INSERT ON ACCOUNTS.APP_USERS_PERMISSIONS FOR EACH ROW
BEGIN
  IF :NEW.ID IS NULL THEN
    SELECT SEQ_APP_USERS_PERM.NEXTVAL INTO :NEW.ID FROM DUAL;
  END IF;
END;
/

-- ---------------------------------------------------------------------------
-- 4) APP_SETTINGS — إعدادات الأمان والجلسات (قابلة للتعديل من الواجهة)
--    Security/session settings editable from the "إعدادات الأمان" screen
-- ---------------------------------------------------------------------------
CREATE TABLE ACCOUNTS.APP_SETTINGS (
  SETTING_ID      NUMBER(6)     NOT NULL,
  SETTING_KEY     VARCHAR2(60)  NOT NULL,
  SETTING_VALUE   VARCHAR2(500),
  VALUE_TYPE      VARCHAR2(20)  DEFAULT 'int' NOT NULL,
  NAME_AR         VARCHAR2(150) NOT NULL,
  DESCRIPTION_AR  VARCHAR2(500),
  MIN_VALUE       NUMBER(10),
  MAX_VALUE       NUMBER(10),
  IS_LOCKABLE     NUMBER(1)     DEFAULT 0 NOT NULL,
  SORT_ORDER      NUMBER(4)     DEFAULT 0,
  UPDATED_BY      NUMBER(5),
  UPDATED_ON      DATE,
  CONSTRAINT APP_SET_PK      PRIMARY KEY (SETTING_ID),
  CONSTRAINT APP_SET_KEY_UQ  UNIQUE (SETTING_KEY),
  CONSTRAINT APP_SET_TYPE_CK CHECK (VALUE_TYPE IN ('int', 'bool', 'string', 'json'))
);

CREATE SEQUENCE ACCOUNTS.SEQ_APP_SETTINGS
  START WITH 1 INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER ACCOUNTS.TRG_APP_SETTINGS
BEFORE INSERT ON ACCOUNTS.APP_SETTINGS FOR EACH ROW
BEGIN
  IF :NEW.SETTING_ID IS NULL THEN
    SELECT SEQ_APP_SETTINGS.NEXTVAL INTO :NEW.SETTING_ID FROM DUAL;
  END IF;
END;
/

-- ---------------------------------------------------------------------------
-- 5) APP_USER_ACTIVITY — سجل نشاط المستخدمين (تدقيق خفيف لا يُفشل الطلب)
--    User activity/audit trail — logging failures must never break a request
-- ---------------------------------------------------------------------------
CREATE TABLE ACCOUNTS.APP_USER_ACTIVITY (
  ACTIVITY_ID     NUMBER(11)    NOT NULL,
  USER_ID         NUMBER(5),
  TARGET_USER_ID  NUMBER(5),
  ACTION_CODE     VARCHAR2(60)  NOT NULL,
  MODULE_CODE     VARCHAR2(30),
  ENTITY_TYPE     VARCHAR2(40),
  ENTITY_ID       VARCHAR2(60),
  DESCRIPTION_AR  VARCHAR2(1000),
  STATUS          VARCHAR2(10)  DEFAULT 'success' NOT NULL,
  IP_ADDRESS      VARCHAR2(45),
  USER_AGENT      VARCHAR2(200),
  PLATFORM        VARCHAR2(20),
  CREATED_ON      DATE          DEFAULT SYSDATE,
  CONSTRAINT APP_ACT_PK        PRIMARY KEY (ACTIVITY_ID),
  CONSTRAINT APP_ACT_STATUS_CK CHECK (STATUS IN ('success', 'failed'))
);

CREATE INDEX ACCOUNTS.APP_ACT_USER_IX ON ACCOUNTS.APP_USER_ACTIVITY (USER_ID, CREATED_ON);
CREATE INDEX ACCOUNTS.APP_ACT_TGT_IX  ON ACCOUNTS.APP_USER_ACTIVITY (TARGET_USER_ID, CREATED_ON);
CREATE INDEX ACCOUNTS.APP_ACT_MOD_IX  ON ACCOUNTS.APP_USER_ACTIVITY (MODULE_CODE, CREATED_ON);

CREATE SEQUENCE ACCOUNTS.SEQ_APP_ACTIVITY
  START WITH 1 INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE;

CREATE OR REPLACE TRIGGER ACCOUNTS.TRG_APP_ACTIVITY
BEFORE INSERT ON ACCOUNTS.APP_USER_ACTIVITY FOR EACH ROW
BEGIN
  IF :NEW.ACTIVITY_ID IS NULL THEN
    SELECT SEQ_APP_ACTIVITY.NEXTVAL INTO :NEW.ACTIVITY_ID FROM DUAL;
  END IF;
END;
/

-- ملاحظة / note: لا قيد FK على USER_ID في APP_USER_ACTIVITY لأن فشل الدخول باسم مجهول
-- يجب أن يُسجَّل بصف USER_ID = NULL، وحذف مستخدم لا يجب أن يمحو سجل تدقيقه.
-- No FK on USER_ID: failed logins with an unknown username must log with NULL, and
-- deleting a user must not erase their audit history.

-- التعليقات التوضيحية على الجداول والأعمدة (عربية + إنجليزية) تُنفَّذ عبر:
-- Table/column COMMENTs (bilingual) are applied by 03_ibac_bootstrap.php.

-- ---------------------------------------------------------------------------
-- 6) تحقق سريع / quick verification
-- ---------------------------------------------------------------------------
SELECT TABLE_NAME FROM ALL_TABLES
 WHERE OWNER = 'ACCOUNTS' AND TABLE_NAME LIKE 'APP\_%' ESCAPE '\' ORDER BY TABLE_NAME;

SELECT OBJECT_NAME, OBJECT_TYPE, STATUS FROM ALL_OBJECTS
 WHERE OWNER = 'ACCOUNTS' AND (OBJECT_NAME LIKE 'TRG_APP%' OR OBJECT_NAME LIKE 'SEQ_APP%')
 ORDER BY OBJECT_TYPE, OBJECT_NAME;

SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH FROM ALL_TAB_COLUMNS
 WHERE OWNER = 'ACCOUNTS' AND TABLE_NAME = 'APP_USERS'
   AND COLUMN_NAME IN ('IS_ADMIN', 'USER_PASSWORD');
