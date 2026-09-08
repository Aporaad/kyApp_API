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

