# سجل أوامر قاعدة البيانات (Database Commands)

## [2026-09-08 01:07:30]
- **النموذج المنفذ / AI Model:** Gemini 3.8 Flash (Medium)
- **الغرض / Purpose:** فحص بنية جدول NEW_USERS وقيم حقل IS_ACTIVATED والمستخدمين المسجلين.
- **كود SQL / SQL Command:**
```sql
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE FROM USER_TAB_COLUMNS WHERE TABLE_NAME = 'NEW_USERS';
SELECT USER_ID, USER_NO, USER_NAME_AR, USER_NAME_EN, USER_PASSWORD, IS_ACTIVATED, USER_TYPE FROM NEW_USERS WHERE ROWNUM <= 10;
```

## [2026-09-08 01:12:10]
- **النموذج المنفذ / AI Model:** Gemini 3.8 Flash (Medium)
- **الغرض / Purpose:** تنظيف توكنات الاختبار التجريبية من جدول PERSONAL_ACCESS_TOKENS.
- **كود SQL / SQL Command:**
```sql
DELETE FROM PERSONAL_ACCESS_TOKENS WHERE TOKENABLE_TYPE = 'TestUser';
COMMIT;
```

## [2026-09-09 00:24:21]
- **النموذج المنفذ / AI Model:** Gemini 3.7 Flash (Medium)
- **الغرض / Purpose:** استعلام كشف حساب العامل من جدول قيود اليومية وحساب الرصيد الافتتاحي وتحديد أحدث حركة.
- **كود SQL / SQL Command:**
```sql
-- 1. تحديد تاريخ أحدث حركة لحساب العامل
SELECT MAX(TRUNC(ENTRY_DATE)) as LATEST_DATE FROM JOURNAL_ENTRY_REC WHERE ACC_NO = :acc_no;

-- 2. استعلام حركات كشف الحساب خلال الفترة المحددة
SELECT ENTRY_NO, SUB_ENTRY_NO, TO_CHAR(ENTRY_DATE, 'DD/MM/YYYY') as ENTRY_DATE, ENTRY_TYPE_NAME, DETAILS, BALANCE_S, ENTRY_MNT, DEBIT, CREDIT, ACC_NO FROM JOURNAL_ENTRY_REC WHERE ACC_NO = :acc_no AND TRUNC(ENTRY_DATE) >= TO_DATE(:from_date, 'DD/MM/YYYY') AND TRUNC(ENTRY_DATE) <= TO_DATE(:to_date, 'DD/MM/YYYY') ORDER BY ENTRY_DATE ASC, ENTRY_NO ASC;

-- 3. احتساب الرصيد الافتتاحي لما قبل الفترة
SELECT COALESCE(SUM(NVL(DEBIT, 0)), 0) as TOTAL_DEBIT, COALESCE(SUM(NVL(CREDIT, 0)), 0) as TOTAL_CREDIT FROM JOURNAL_ENTRY_REC WHERE ACC_NO = :acc_no AND TRUNC(ENTRY_DATE) < TO_DATE(:from_date, 'DD/MM/YYYY');
```

## [2026-09-13 20:00:24]
- **النموذج المنفذ / AI Model:** Gemini 3.8 Flash (Medium)
- **الغرض / Purpose:** إنشاء مشغلات (Triggers) لتوليد المفاتيح الأساسية تلقائياً من المتتاليات (Sequences) في جداول Laravel على Oracle 11g لحل خطأ ORA-01400 أثناء تسجيل الدخول.
- **كود SQL / SQL Command:**
```sql
-- 1. مشغل جدول التوكنات لحل خطأ تسجيل الدخول
CREATE OR REPLACE TRIGGER PERSONAL_ACCESS_TOKENS_ID_TRG
BEFORE INSERT ON PERSONAL_ACCESS_TOKENS
FOR EACH ROW
BEGIN
    IF :NEW.ID IS NULL THEN
        SELECT PERSONAL_ACCESS_TOKENS_ID_SEQ.NEXTVAL INTO :NEW.ID FROM DUAL;
    END IF;
END;

-- 2. مشغل جدول الترحيلات
CREATE OR REPLACE TRIGGER MIGRATIONS_ID_TRG
BEFORE INSERT ON MIGRATIONS
FOR EACH ROW
BEGIN
    IF :NEW.ID IS NULL THEN
        SELECT MIGRATIONS_ID_SEQ.NEXTVAL INTO :NEW.ID FROM DUAL;
    END IF;
END;

-- 3. مشغل جدول الوظائف في الطابور
CREATE OR REPLACE TRIGGER JOBS_ID_TRG
BEFORE INSERT ON JOBS
FOR EACH ROW
BEGIN
    IF :NEW.ID IS NULL THEN
        SELECT JOBS_ID_SEQ.NEXTVAL INTO :NEW.ID FROM DUAL;
    END IF;
END;

-- 4. مشغل جدول الوظائف الفاشلة
CREATE OR REPLACE TRIGGER FAILED_JOBS_ID_TRG
BEFORE INSERT ON FAILED_JOBS
FOR EACH ROW
BEGIN
    IF :NEW.ID IS NULL THEN
        SELECT FAILED_JOBS_ID_SEQ.NEXTVAL INTO :NEW.ID FROM DUAL;
    END IF;
END;
```



