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




---

## 2026-10-21 — SQL/فحص المخطط لتقارير الحسابات (Account Reports)

**نموذج AI:** Qoder Agent · **التاريخ:** الأربعاء 21 أكتوبر 2026 · **الجلسة:** تنفيذ خطة `Account_Financial_Reports_1686e067.md`.

**تنويه:** لا توجد أي كتابة/DDL داخل `ACCOUNTS` خلال هذه المهمة — كل ما يلي هو **استعلامات فحص للقراءة فقط** تم تنفيذها عبر `oracledb` MCP / `php artisan tinker` للتأكد من صحة المسارات قبل كتابة الكود.

### 1) التأكيد على عدم وجود `ACC_TRXN`
```sql
SELECT COUNT(*) AS C
  FROM ALL_TABLES
 WHERE OWNER = 'ACCOUNTS'
   AND TABLE_NAME = 'ACC_TRXN';
-- النتيجة: 0 — الجدول غير موجود؛ يجب استخدام ENTRY_MAIN + ENTRY_SUB + ENTRY_TYPE
```

### 2) التحقّق من بنية `ENTRY_SUB` والربط بـ `CHART_ACC`
```sql
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH
  FROM ALL_TAB_COLUMNS
 WHERE OWNER = 'ACCOUNTS'
   AND TABLE_NAME = 'ENTRY_SUB'
 ORDER BY COLUMN_ID;
-- الأعمدة ذات الصلة: ENTRY_NO, DET_NO, ACC_NO, ENTRY_MNT, CUR_NO, DETAILS
```

### 3) معرفة العملة المحلية مقابل الأجنبية (للتقرير 2)
```sql
SELECT CUR_NO, CUR_NAME, CUR_SIGN
  FROM ACCOUNTS.CURRENCY
 ORDER BY CUR_NO;
-- CUR_NO = 1  =>  "ريال يمني" / YR  (هي العملة المحلية)
```

### 4) اختبار تصنيف الرصيد المحلي/الأجنبي
```sql
SELECT s.ACC_NO,
       s.CUR_NO,
       SUM(CASE WHEN s.ENTRY_MNT > 0 AND s.CUR_NO = 1 THEN s.ENTRY_MNT ELSE 0 END) AS LOCAL_DEBIT,
       SUM(CASE WHEN s.ENTRY_MNT < 0 AND s.CUR_NO = 1 THEN -s.ENTRY_MNT ELSE 0 END) AS LOCAL_CREDIT,
       SUM(CASE WHEN s.ENTRY_MNT > 0 AND s.CUR_NO <> 1 THEN s.ENTRY_MNT ELSE 0 END) AS FOREIGN_DEBIT,
       SUM(CASE WHEN s.ENTRY_MNT < 0 AND s.CUR_NO <> 1 THEN -s.ENTRY_MNT ELSE 0 END) AS FOREIGN_CREDIT
  FROM ACCOUNTS.ENTRY_SUB s
 WHERE s.ACC_NO IN (SELECT ACC_NO FROM ACCOUNTS.CHART_ACC WHERE ACC_SUB = :accSub)
 GROUP BY s.ACC_NO, s.CUR_NO;
```

### 5) حساب آخر حركة لنافذة 15 يوماً الافتراضية
```sql
SELECT MAX(m.ENTRY_DATE) AS LAST_TRXN_DATE
  FROM ACCOUNTS.ENTRY_MAIN m
  JOIN ACCOUNTS.ENTRY_SUB s ON s.ENTRY_NO = m.ENTRY_NO
 WHERE s.ACC_NO = :accNo;
-- ثم في PHP: $from = subDays(lastTrxnDate, 15); $to = now();
```

### 6) فحص ترميز Windows-1256 للحقول العربيّة
```sql
SELECT RAWTOHEX(UTL_RAW.CAST_TO_RAW(TYPE_NAME)) AS HEX_BYTES,
       TYPE_NAME
  FROM ACCOUNTS.ENTRY_TYPE
 WHERE ROWNUM <= 5;
--Bytes 0xC0–0xFF => عربية CP-1256؛ تصل كـ Latin-1 mojibake وتُصحَّح في التطبيق.
```

### 7) حساب الرصيد الافتتاحي (قبل `:from`)
```sql
SELECT NVL(SUM(CASE WHEN s.ENTRY_MNT > 0 THEN s.ENTRY_MNT ELSE 0 END), 0)
     - NVL(SUM(CASE WHEN s.ENTRY_MNT < 0 THEN -s.ENTRY_MNT ELSE 0 END), 0)
       AS OPENING_BALANCE
  FROM ACCOUNTS.ENTRY_MAIN m
  JOIN ACCOUNTS.ENTRY_SUB s ON s.ENTRY_NO = m.ENTRY_NO
 WHERE s.ACC_NO = :accNo
   AND m.ENTRY_DATE < TO_DATE(:fromDate, 'YYYY-MM-DD');
```

### 8) استعلام الرصيد الجاري لكل سطر (window function)
```sql
SELECT m.ENTRY_NO, s.DET_NO, m.ENTRY_DATE, t.TYPE_NAME, s.DETAILS,
       CASE WHEN s.ENTRY_MNT > 0 THEN  s.ENTRY_MNT ELSE 0 END AS DEBIT,
       CASE WHEN s.ENTRY_MNT < 0 THEN -s.ENTRY_MNT ELSE 0 END AS CREDIT,
       :openingBalance + SUM(s.ENTRY_MNT) OVER (
           ORDER BY m.ENTRY_DATE, m.ENTRY_NO, s.DET_NO
           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
       ) AS RUNNING_BALANCE
  FROM ACCOUNTS.ENTRY_MAIN m
  JOIN ACCOUNTS.ENTRY_SUB  s ON s.ENTRY_NO = m.ENTRY_NO
  LEFT JOIN ACCOUNTS.ENTRY_TYPE t ON t.TYPE_NO = s.TYPE_NO
 WHERE s.ACC_NO = :accNo
   AND m.ENTRY_DATE BETWEEN TO_DATE(:fromDate,'YYYY-MM-DD')
                        AND TO_DATE(:toDate,'YYYY-MM-DD')
 ORDER BY m.ENTRY_DATE, m.ENTRY_NO, s.DET_NO;
```

### 9) لا تغييرات على `APP_USERS_PERMISSIONS`
الصلاحية `accounts.statement` (وكذلك `accounts.print` / `accounts.export`) **كانت مُهيّأة مسبقاً** في `APP_PERMISSIONS` — لم يضف هذا العمل أي codes جديدة، وأي مستخدم يملكها يستطيع الوصول للمسارين الجديدين مباشرة.

### 10) لا DDL
- لم تُنشَأ جداول، لم تُضَف أعمدة، لم تُنشَأ sequences أو triggers.
- جميع التعديلات على طبقة الاستعلام داخل Laravel (`AccountController`) وطبقة العرض داخل Flutter.


---

## 2026-10-21 — SQL فحص انهيار كشف الحساب (قراءة فقط، بلا كتابة)

**نموذج AI:** Qoder Agent · **الأداة:** oracledb MCP — تشخيص انهيار `showDatePicker` (سنة 93422).

### 1) تواريخ حساب 11010001 (سليمة)
```sql
SELECT * FROM (SELECT em.ENTRY_NO,
       TO_CHAR(em.ENTRY_DATE, 'DD/MM/YYYY') AS ENTRY_DATE_FMT,
       TO_CHAR(em.ENTRY_DATE, 'YYYY-MM-DD') AS RAW_DATE,
       DUMP(em.ENTRY_DATE) AS DATE_DUMP
  FROM ENTRY_MAIN em JOIN ENTRY_SUB es ON es.ENTRY_NO = em.ENTRY_NO
 WHERE es.ACC_NO = 11010001
 ORDER BY em.ENTRY_DATE DESC) WHERE ROWNUM <= 5;
-- نتيجتان: 15/04/2025 و 27/03/2025 (DUMP: Typ=12 Len=7 سليم)
```

### 2) الصفوف الفاسدة عبر كل ENTRY_MAIN
```sql
SELECT * FROM (SELECT em.ENTRY_NO,
       TO_CHAR(em.ENTRY_DATE, 'YYYY-MM-DD') AS RAW_DATE, em.ENTRY_DATE
  FROM ENTRY_MAIN em
 WHERE em.ENTRY_DATE > TO_DATE('9999-12-31','YYYY-MM-DD')
    OR em.ENTRY_DATE < TO_DATE('1900-01-01','YYYY-MM-DD')
 ORDER BY em.ENTRY_DATE DESC) WHERE ROWNUM <= 10;
-- وُجدت صفوف بسنة 0025 (مثل ENTRY_NO 4250019965 بتاريخ 0025-06-18)
-- القرار: لا تُصلَح البيانات يدوياً؛ طبقة الحماية الجديدة في التطبيق تتجاهلها.
```

### ملاحظات
- لا `FETCH FIRST n ROWS ONLY` على Oracle 11g (ORA-00933) — استُخدم `WHERE ROWNUM <= n`.
- لم تُنفَّذ أي كتابة/DDL — كلا الاستعلامين SELECT للقراءة فقط.



---

## [2026-10-21 11:07] — مفتاحا الترويسة الإنجليزية في `APP_SETTINGS` (company_name_en / company_address_en)

**المنفِّذ / Executor:** Qoder Agent
**الطريق / Channel:** موصل `oracledb MCP` — تنفيذ مباشر على مخطط `ACCOUNTS` (لا `migrations`؛ القاعدة تراثية).
**الغرض / Purpose:** جعل العمود الأيسر من ترويسة التقارير الثلاثية (اسم الشركة + العنوان بالإنجليزية) قابلاً للتحكم من الإعدادات بدل نص ثابت في الكود.

### 1) الاستعلامات التشخيصية (قراءة فقط) / read-only probes

```sql
-- (أ) بنية الجدول — لا عمود مجموعة؛ التجميع يتم في PHP
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE, COLUMN_ID
  FROM ALL_TAB_COLUMNS
 WHERE OWNER = 'ACCOUNTS' AND TABLE_NAME = 'APP_SETTINGS'
 ORDER BY COLUMN_ID;
-- SETTING_ID NUMBER(N) · SETTING_KEY VARCHAR2(60) · SETTING_VALUE VARCHAR2(500)
-- VALUE_TYPE VARCHAR2(20) · NAME_AR VARCHAR2(150) · DESCRIPTION_AR VARCHAR2(500)
-- MIN_VALUE/MAX_VALUE NUMBER · IS_LOCKABLE NUMBER(N) · SORT_ORDER NUMBER
-- UPDATED_BY NUMBER · UPDATED_ON DATE

-- (ب) صفوف العلامة التجارية القائمة (11..14) وأسمائها العربية المخزنة بايتات CP-1256
SELECT SETTING_ID, SETTING_KEY, SETTING_VALUE, VALUE_TYPE, NAME_AR, SORT_ORDER, IS_LOCKABLE
  FROM ACCOUNTS.APP_SETTINGS
 WHERE SETTING_KEY LIKE 'company%' ORDER BY SORT_ORDER;
-- 11 company_name · 12 company_address · 13 company_website · 14 company_phone

-- (ج) مصدر المعرّف التلقائي + آخر قيمة من المتتالية
SELECT TRIGGER_NAME, TRIGGER_TYPE, TRIGGERING_EVENT, STATUS FROM ALL_TRIGGERS
 WHERE TABLE_NAME = 'APP_SETTINGS' AND TABLE_OWNER = 'ACCOUNTS';
-- TRG_APP_SETTINGS · BEFORE EACH ROW · INSERT · ENABLED  (:NEW.SETTING_ID := SEQ_APP_SETTINGS.NEXTVAL)
SELECT MAX(SETTING_ID) FROM ACCOUNTS.APP_SETTINGS;                  -- 22
SELECT LAST_NUMBER FROM ALL_SEQUENCES
 WHERE SEQUENCE_OWNER = 'ACCOUNTS' AND SEQUENCE_NAME = 'SEQ_APP_SETTINGS';  -- 23  ⇒ آمن بلا تصادم

-- (د) اختبار ترميز: العميل لا ينقل العربية الحرفية (كلها 0xFD) لكنه ينقل بايتات CP-1256 كما هي
SELECT DUMP('اسم الشركة بالإنجليزي', 1016) FROM DUAL;   -- fd,fd,fd…  ⇒ لا تُرسل العربية حرفياً
SELECT DUMP('ÇÓã', 1016) FROM DUAL;                     -- c7,d3,e3   ⇒ الصيغة المموّهة تُرسَل سليمة
```

**القرار الترميزي / encoding decision:** التسميات العربية كُتبت بايتات Windows-1256 العربية
مستعرضة كأحرف لاتينية — نفس أسلوب الصفوف 11..22 — لتصل مطابقة حرفياً كما في التحقّق (د).
وليد التحقق المحلي قورن بـ `DUMP` قبل التنفيذ وبعده.

### 2) الكتابة / the writes

```sql
INSERT INTO ACCOUNTS.APP_SETTINGS
  (SETTING_KEY, SETTING_VALUE, VALUE_TYPE, NAME_AR, DESCRIPTION_AR,
   MIN_VALUE, MAX_VALUE, IS_LOCKABLE, SORT_ORDER, UPDATED_ON)
VALUES
  ('company_name_en', 'ALKABODI FOR MEN''s TAILORING', 'string',
   'ÇÓã ÇáÔÑßÉ ÈÇáÅäÌáíÒí',
   'ÇÓã ÇáÔÑßÉ ÇáãÚÑæÖ ÈÇáÅäÌáíÒíÉ Ýí ÊÑæíÓÉ ÇáÊÞÇÑíÑ',
   NULL, NULL, 0, 115, SYSDATE);

INSERT INTO ACCOUNTS.APP_SETTINGS
  (SETTING_KEY, SETTING_VALUE, VALUE_TYPE, NAME_AR, DESCRIPTION_AR,
   MIN_VALUE, MAX_VALUE, IS_LOCKABLE, SORT_ORDER, UPDATED_ON)
VALUES
  ('company_address_en', 'Airport Street - Next to Al-shayef House', 'string',
   'ÚäæÇä ÇáÔÑßÉ ÈÇáÅäÌáíÒí',
   'ÚäæÇä ÇáÔÑßÉ ÇáãÚÑæÖ ÈÇáÅäÌáíÒíÉ Ýí ÊÑæíÓÉ ÇáÊÞÇÑíÑ',
   NULL, NULL, 0, 125, SYSDATE);

COMMIT;
```

- `SETTING_ID` لم يُذكر صراحةً — يمنحه `TRG_APP_SETTINGS` ⇒ الناتج 23 ثم 24 تماماً كما توقّع `LAST_NUMBER`.
- `SORT_ORDER` = 115 و125 ليظهر كل مفتاح ملاصقاً لنظيره العربي في شاشة «إعدادات الأمان» (110 ← 115 ← 120 ← 125).
- `IS_LOCKABLE = 0` (قابل للتحرير من الواجهة) و`VALUE_TYPE = 'string'` وحدود NUMBER فارغة — نفس نمط `company_*`.

### 3) التحقّق بعد التنفيذ / post-apply verification

```sql
SELECT SETTING_ID, SETTING_KEY, SETTING_VALUE, NAME_AR, DESCRIPTION_AR, SORT_ORDER, VALUE_TYPE, IS_LOCKABLE
  FROM ACCOUNTS.APP_SETTINGS
 WHERE SETTING_KEY IN ('company_name_en', 'company_address_en') ORDER BY SORT_ORDER;
-- 23 company_name_en    ALKABODI FOR MEN's TAILORING        … 115 string 0
-- 24 company_address_en Airport Street - Next to Al-shayef House  125 string 0

SELECT DUMP(NAME_AR, 1016) FROM ACCOUNTS.APP_SETTINGS WHERE SETTING_KEY = 'company_name_en';
-- c7,d3,e3,20,c7,e1,d4,d1,df,c9,20,c8,c7,e1,c5,e4,cc,e1,ed,d2,ed  ⇒ مطابق للمحسوب مسبقاً

SELECT NVL(DBMS_TRANSACTION.LOCAL_TRANSACTION_ID,'NONE') FROM DUAL;   -- NONE ⇒ الأمر كُرِّس فعلاً
```

### 4) ما لم يُمَسّ / untouched

- لا `UPDATE` ولا `DELETE` على أي صف قائم؛ العدد من 22 إلى 24 صفاً.
- لا أعمدة جديدة ولا جدول جديد — `APP_SETTINGS` key-value كما هو.
- الملف المكافئ للتكرار لاحقاً: `kyApp_API/database/oracle/05_app_settings_company_en.sql`.
