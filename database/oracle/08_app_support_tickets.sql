-- ═══════════════════════════════════════════════════════════════════════════
-- 08_app_support_tickets.sql
-- جدول تذاكر الدعم والاستفسارات القادمة من تطبيق الموبايل
-- Support / inquiry tickets submitted from the mobile app.
--
-- لماذا؟ شاشة «معلومات البرنامج والدعم» في العميل فيها نموذج استفسار يُرسَل قبل الدخول
-- وبعده. التذكرة تُخزَّن هنا ليقرأها فريق الإدارة ويردّ عليها من لوحة الإدارة، بدل أن
-- يضيع الاستفسار في محادثة واتساب بلا أثر.
-- Why: the client's "app info & support" screen carries an inquiry form that submits both
-- before and after login. Tickets land here so admins can read and answer them from the
-- management screen instead of losing the inquiry in a WhatsApp chat.
--
-- قاعدة البيانات: Oracle 11g · المخطط: ACCOUNTS · الترميز: WE8MSWIN1252
-- Database: Oracle 11g · schema ACCOUNTS · charset WE8MSWIN1252 (Arabic = CP1256 bytes)
--
-- ⚠️ لا نص عربي داخل هذا الملف: المحارف العربية تُكتب من PHP عبر
-- ArabicEncoding::toOracleSearchChars() وتُقرأ بـ fixMojibake()، لأن عميل AL32UTF8
-- يحوّل بايتات CP1256 إلى علامة الاستبدال 0xBF (انظر رأس 01_ibac_schema.sql).
-- No Arabic literal lives in this file: Arabic travels through
-- ArabicEncoding::toOracleSearchChars() on write and fixMojibake() on read.
--
-- يُنفَّذ عبر موصل oracledb MCP بياناً بياناً (الموصل لا يفهم فاصل "/")، ويُسجَّل نصّه
-- ونتيجته في db_commends.md.
-- Run statement-by-statement through the oracledb MCP (it does not understand "/"),
-- and log both the SQL and its result in db_commends.md.
-- ═══════════════════════════════════════════════════════════════════════════

-- ---------------------------------------------------------------------------
-- 1) APP_SUPPORT_TICKETS — التذاكر / the tickets table
--    USER_ID يُترك NULL للزائر قبل الدخول، ولا قيد FK عليه: جداول APP_USERS إرثية
--    يحذف منها النظام القديم، وحذف مستخدم لا يجوز أن يُفشل بسببه.
--    USER_ID stays NULL for a pre-login guest, and carries no FK: APP_USERS is legacy and
--    the old system deletes from it; removing a user must never fail because of a ticket.
-- ---------------------------------------------------------------------------
CREATE TABLE ACCOUNTS.APP_SUPPORT_TICKETS (
  TICKET_ID     NUMBER(12)     NOT NULL,
  USER_ID       NUMBER(5),
  CONTACT_NAME  VARCHAR2(100),
  CONTACT_PHONE VARCHAR2(30),
  MESSAGE       VARCHAR2(1000) NOT NULL,
  STATUS        VARCHAR2(20)   DEFAULT 'new' NOT NULL,
  ADMIN_REPLY   VARCHAR2(1000),
  REPLIED_BY    NUMBER(5),
  REPLIED_ON    DATE,
  APP_VERSION   VARCHAR2(20),
  PLATFORM      VARCHAR2(30),
  CREATED_ON    DATE           DEFAULT SYSDATE NOT NULL,
  UPDATED_ON    DATE,
  CONSTRAINT APP_SUP_PK        PRIMARY KEY (TICKET_ID),
  CONSTRAINT APP_SUP_STATUS_CK CHECK (STATUS IN ('new', 'answered', 'closed'))
);

-- قائمة الإدارة تُرشَّح بالحالة وتُرتَّب زمنياً، وشاشة «استفساراتي» (إن أُضيفت) تُرشَّح بالمستخدم.
-- The admin list filters by status and orders by date; a future "my inquiries" view filters by user.
CREATE INDEX ACCOUNTS.APP_SUP_STATUS_IX ON ACCOUNTS.APP_SUPPORT_TICKETS (STATUS, CREATED_ON);
CREATE INDEX ACCOUNTS.APP_SUP_USER_IX   ON ACCOUNTS.APP_SUPPORT_TICKETS (USER_ID, CREATED_ON);

CREATE SEQUENCE ACCOUNTS.SEQ_APP_SUPPORT_TICKETS
  START WITH 1 INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE;

-- أسماء التسلسلات تُذكر بلا بادئة مخطط: يوجد VIEW باسم ACCOUNTS داخل المخطط،
-- وكتابة ACCOUNTS.SEQ_X تُقرأ كعمود من العرض وتفشل بالخطأ PLS-00302.
-- Sequence names inside PL/SQL must stay UNQUALIFIED: the schema contains a VIEW named
-- ACCOUNTS, so ACCOUNTS.SEQ_X resolves as view.column and fails with PLS-00302.
CREATE OR REPLACE TRIGGER ACCOUNTS.TRG_APP_SUPPORT_TICKETS
BEFORE INSERT ON ACCOUNTS.APP_SUPPORT_TICKETS FOR EACH ROW
BEGIN
  IF :NEW.TICKET_ID IS NULL THEN
    SELECT SEQ_APP_SUPPORT_TICKETS.NEXTVAL INTO :NEW.TICKET_ID FROM DUAL;
  END IF;
END;
/

COMMIT;

-- ── تحقّق / verification ────────────────────────────────────────────────────
-- SELECT TABLE_NAME FROM ALL_TABLES
--  WHERE OWNER = 'ACCOUNTS' AND TABLE_NAME = 'APP_SUPPORT_TICKETS';       -- صف واحد
-- SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
--   FROM ALL_TAB_COLUMNS
--  WHERE OWNER = 'ACCOUNTS' AND TABLE_NAME = 'APP_SUPPORT_TICKETS'
--  ORDER BY COLUMN_ID;                                                    -- 13 عموداً
-- SELECT SEQUENCE_NAME, LAST_NUMBER FROM ALL_SEQUENCES
--  WHERE SEQUENCE_OWNER = 'ACCOUNTS' AND SEQUENCE_NAME = 'SEQ_APP_SUPPORT_TICKETS';
-- SELECT TRIGGER_NAME, STATUS FROM ALL_TRIGGERS
--  WHERE OWNER = 'ACCOUNTS' AND TRIGGER_NAME = 'TRG_APP_SUPPORT_TICKETS';  -- ENABLED
-- SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, STATUS FROM ALL_CONSTRAINTS
--  WHERE OWNER = 'ACCOUNTS' AND TABLE_NAME = 'APP_SUPPORT_TICKETS';        -- P + C
--
-- اختبار المُشغِّل (ثم تراجع):
-- INSERT INTO ACCOUNTS.APP_SUPPORT_TICKETS (MESSAGE) VALUES ('trigger check');
-- SELECT TICKET_ID, STATUS, CREATED_ON FROM ACCOUNTS.APP_SUPPORT_TICKETS
--  WHERE MESSAGE = 'trigger check';   -- TICKET_ID غير فارغ، STATUS = 'new'
-- DELETE FROM ACCOUNTS.APP_SUPPORT_TICKETS WHERE MESSAGE = 'trigger check';
-- COMMIT;

-- ── تراجع / rollback ────────────────────────────────────────────────────────
-- DROP TRIGGER ACCOUNTS.TRG_APP_SUPPORT_TICKETS;
-- DROP SEQUENCE ACCOUNTS.SEQ_APP_SUPPORT_TICKETS;
-- DROP INDEX ACCOUNTS.APP_SUP_USER_IX;
-- DROP INDEX ACCOUNTS.APP_SUP_STATUS_IX;
-- DROP TABLE ACCOUNTS.APP_SUPPORT_TICKETS;
-- COMMIT;
-- لا تُسقِط الجدول قبل حذف مساراته من routes/api.php ومتحكّم
-- SupportInquiryController، وإلا صارت كل قراءة/كتابة تفشل بـ ORA-00942.
-- Do not drop the table while its routes and SupportInquiryController still exist, or every
-- read/write fails with ORA-00942.
