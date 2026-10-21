-- ═══════════════════════════════════════════════════════════════════════════
-- 05_app_settings_company_en.sql
-- صفّان جديدان في APP_SETTINGS لاسم الشركة وعنوانها بالإنجليزية
-- Two new APP_SETTINGS rows: the English company name and address.
--
-- لماذا؟ ترويسة التقارير الرسمية ثلاثية الأعمدة (يمين عربي / شعار في المنتصف /
-- يسار إنجليزي). العمود الأيسر كان يعتمد نصاً ثابتاً في الكود؛ الآن له مفتاحان
-- قابلان للكتابة من شاشة «إعدادات التطبيق» خلف الصلاحية app_config.update.
-- Why: the report letterhead is a three-column band (Arabic right, logo centre,
-- English left). The left column used a hardcoded string; it now has two writable
-- keys edited from the app-configuration screen behind the app_config.update grant.
--
-- طُبِّق هذا الملف يدوياً عبر موصل oracledb MCP في 2026-10-21، وهو مسجَّل في
-- db_commends.md بالوقت نفسه. المعرّفان (23 و24) يمنحهما المُشغِّل TRG_APP_SETTINGS
-- من SEQ_APP_SETTINGS تلقائياً، فلا يُذكر أيّ منهما صراحةً هنا.
-- Applied by hand through the oracledb MCP on 2026-10-21 (logged in db_commends.md).
-- SETTING_ID comes from TRG_APP_SETTINGS / SEQ_APP_SETTINGS, so it is never set here.
--
-- ⚠️ التسميات العربية مكتوبة كما تُخزَّن فعلاً: بايتات Windows-1256 العربية كما
-- تظهر بأحرف لاتينية (نفس أسلوب الصفوف السابقة 11..22)، لأن القاعدة
-- NLS_CHARACTERSET = WE8MSWIN1252 والطبقة-php تصلحها عند القراءة بـ fixMojibake.
-- لذلك يجب تنفيذ هذا الملف من عميل ينقل تلك الأحرف بترميز WE8MSWIN1252
-- (SQL*Plus مع NLS_LANG=ARABIC_SA.WE8MSWIN1252 أو موصل oracledb نفسه)، وإلا تحوّلت
-- إلى علامات استفهام. تحقّق بعدها بالـ DUMP المطابق في قسم التحقّق أدناه.
-- The Arabic labels are written exactly as they are stored: CP-1256 Arabic bytes
-- rendered as Latin-1 letters — the same convention as rows 11..22. Run this from a
-- client that transmits those characters as WE8MSWIN1252 (SQL*Plus with
-- NLS_LANG=ARABIC_SA.WE8MSWIN1252, or the oracledb MCP itself), otherwise they degrade
-- to question marks. PHP repairs the labels on read via ArabicEncoding::fixMojibake.
-- ═══════════════════════════════════════════════════════════════════════════

-- 1) اسم الشركة بالإنجليزية / English company name
INSERT INTO ACCOUNTS.APP_SETTINGS (
    SETTING_KEY, SETTING_VALUE, VALUE_TYPE, NAME_AR, DESCRIPTION_AR,
    MIN_VALUE, MAX_VALUE, IS_LOCKABLE, SORT_ORDER, UPDATED_ON
) VALUES (
    'company_name_en',
    'ALKABODI FOR MEN''s TAILORING',
    'string',
    'ÇÓã ÇáÔÑßÉ ÈÇáÅäÌáíÒí',
    'ÇÓã ÇáÔÑßÉ ÇáãÚÑæÖ ÈÇáÅäÌáíÒíÉ Ýí ÊÑæíÓÉ ÇáÊÞÇÑíÑ',
    NULL, NULL, 0, 115, SYSDATE
);

-- 2) عنوان الشركة بالإنجليزية / English company address
INSERT INTO ACCOUNTS.APP_SETTINGS (
    SETTING_KEY, SETTING_VALUE, VALUE_TYPE, NAME_AR, DESCRIPTION_AR,
    MIN_VALUE, MAX_VALUE, IS_LOCKABLE, SORT_ORDER, UPDATED_ON
) VALUES (
    'company_address_en',
    'Airport Street - Next to Al-shayef House',
    'string',
    'ÚäæÇä ÇáÔÑßÉ ÈÇáÅäÌáíÒí',
    'ÚäæÇä ÇáÔÑßÉ ÇáãÚÑæÖ ÈÇáÅäÌáíÒíÉ Ýí ÊÑæíÓÉ ÇáÊÞÇÑíÑ',
    NULL, NULL, 0, 125, SYSDATE
);

COMMIT;

-- ── تحقّق / verification ────────────────────────────────────────────────────
-- SELECT SETTING_ID, SETTING_KEY, SETTING_VALUE, SORT_ORDER, VALUE_TYPE, IS_LOCKABLE
--   FROM ACCOUNTS.APP_SETTINGS
--  WHERE SETTING_KEY IN ('company_name_en', 'company_address_en')
--  ORDER BY SORT_ORDER;
--   المتوقع: صَفّان بمعرّفين 23 و24 وSORT_ORDER 115 و125 وVALUE_TYPE = string.
--   Expected: two rows (ids 23, 24), SORT_ORDER 115/125, VALUE_TYPE 'string'.
--
-- والتحقّق من البايتات (يجب أن يطابق ما يلي حرفياً):
-- Byte-level check — both dumps must come back exactly as below:
--   DUMP(NAME_AR, 1016) company_name_en    = c7,d3,e3,20,c7,e1,d4,d1,df,c9,20,c8,c7,e1,c5,e4,cc,e1,ed,d2,ed
--   DUMP(NAME_AR, 1016) company_address_en = da,e4,e6,c7,e4,20,c7,e1,d4,d1,df,c9,20,c8,c7,e1,c5,e4,cc,e1,ed,d2,ed

-- ── تراجع / rollback ────────────────────────────────────────────────────────
-- DELETE FROM ACCOUNTS.APP_SETTINGS
--  WHERE SETTING_KEY IN ('company_name_en', 'company_address_en');
-- COMMIT;
-- لا تُحذف الأصفار قبل حذف المفتاحين من AppConfig::WRITABLE_KEYS في PHP، وإلا
-- فشل كل حفظ من شاشة التهيئة عند إرسالهما («الإعداد غير معروف»).
-- Do not delete these rows while the keys remain in AppConfig::WRITABLE_KEYS: every
-- branding save submits them and SettingsStore::set() would throw "Unknown setting".
