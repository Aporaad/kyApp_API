-- ============================================================================
-- قسم المخازن — تسلسلات الترقيم (المرحلة 0) / Stores module — numbering sequences
-- قاعدة البيانات: Oracle 11g · المخطط: ACCOUNTS · الترميز: WE8MSWIN1252
-- Database: Oracle 11g · schema ACCOUNTS · charset WE8MSWIN1252
--
-- ⚠️ هذا الملف **لا يُنشئ جدولاً واحداً** ولا يعدّل أي جدول قائم.
--    قرار المستخدم (D13): الكائنات الجديدة الوحيدة في هذا القسم هي تسلسلات الترقيم.
-- ⚠️ This file creates NO table and alters NO existing table. The only new objects of
--    the stores module are the numbering sequences below (user decision D13).
--
-- لماذا تسلسلات؟ لأن جداول المستندات القديمة لا تملك توليداً مركزياً للأرقام، وقيد
-- التفرّد `INVENTORY_RECEIVE_BRW_U1 (BILL_NO, YEAR)` يجعل `MAX+1` من PHP عرضة للتصادم
-- تحت التزامن. التسلسل يعطي رقماً فريداً بلا قفل جدول.
-- Why sequences: the legacy document tables have no central numbering and their unique
-- keys (BILL_NO, YEAR) make a PHP-side MAX+1 collide under concurrency.
--
-- نقطة البداية = MAX+1 المقاس فعلياً على القاعدة الحيّة (2026-11-01):
--   INVENTORY_RECEIVE.BILL_NO   = 535          → SEQ_STORES_RCV     = 536
--   INVENTORY_SARF.BILL_NO      = 301          → SEQ_STORES_ISS     = 302
--   INVENTORY_TRANSM.TR_INV_M_ID= 265,471      → SEQ_STORES_TRNM    = 265472
--   INVENTORY_TRANSD.TR_INV_D_ID= 2,641,710,001→ SEQ_STORES_TRND    = 2641710002
--   SANFS.SANF_NO               = 2,183        → SEQ_STORES_ITEM    = 2184
--   SANFS_D.SANF_DNO            = 1,010,100,010,013 → SEQ_STORES_ITEM_D
--   PARTIAL_SANFS.SEQ_NO        = 2,187        → SEQ_STORES_PSANF   = 2188
--   STORES.STORE_NO             = 12           → SEQ_STORES_STORE   = 13
--   GROUP1.GROUP_NO             = 9            → SEQ_STORES_GROUP   = 10
--   TYPES.TYPE_NO               = 27           → SEQ_STORES_TYPE    = 28
--   UNITS.UNIT_NO               = 11           → SEQ_STORES_UNIT    = 12
--   CATEGORY.SEQ                = 18           → SEQ_STORES_CAT     = 19
--   STORE_EMP (فارغ/empty)                     → SEQ_STORES_KEEPER  = 1
--
-- ملاحظة إلزامية: أسماء التسلسلات داخل PL/SQL تبقى **بلا بادئة مخطط**، لأن المخطط يحوي
-- VIEW اسمه ACCOUNTS فتُقرأ `ACCOUNTS.SEQ_X` كعمود من العرض وتفشل بـ PLS-00302.
-- Mandatory: sequence names inside PL/SQL stay UNQUALIFIED (a VIEW named ACCOUNTS exists).
--
-- التنفيذ: مرة واحدة. السكربت idempotent — يتخطى كل تسلسل موجود مسبقاً.
-- Run once. The script is idempotent: every existing sequence is skipped.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) مستندات المخازن / Inventory documents
-- ---------------------------------------------------------------------------
DECLARE
  PROCEDURE create_seq(p_name VARCHAR2, p_start NUMBER) IS
    v_exists NUMBER;
  BEGIN
    SELECT COUNT(*) INTO v_exists FROM USER_SEQUENCES WHERE SEQUENCE_NAME = p_name;
    IF v_exists = 0 THEN
      EXECUTE IMMEDIATE 'CREATE SEQUENCE ' || p_name
        || ' START WITH ' || p_start
        || ' INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE';
    END IF;
  END;
BEGIN
  -- أمر توريد مخزني / inventory receive order
  create_seq('SEQ_STORES_RCV', 536);
  -- أمر صرف مخزني / inventory issue (sarf) order
  create_seq('SEQ_STORES_ISS', 302);
  -- أمر تحويل مخزني (الماستر) / inventory transfer master
  create_seq('SEQ_STORES_TRNM', 265472);
  -- سطور التحويل / inventory transfer detail line
  create_seq('SEQ_STORES_TRND', 2641710002);
END;
/

-- ---------------------------------------------------------------------------
-- 2) الأصناف ووحداتها / Items and their units
-- ---------------------------------------------------------------------------
DECLARE
  PROCEDURE create_seq(p_name VARCHAR2, p_start NUMBER) IS
    v_exists NUMBER;
  BEGIN
    SELECT COUNT(*) INTO v_exists FROM USER_SEQUENCES WHERE SEQUENCE_NAME = p_name;
    IF v_exists = 0 THEN
      EXECUTE IMMEDIATE 'CREATE SEQUENCE ' || p_name
        || ' START WITH ' || p_start
        || ' INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE';
    END IF;
  END;
BEGIN
  -- الصنف الرئيسي / main item (SANFS.SANF_NO)
  create_seq('SEQ_STORES_ITEM', 2184);
  -- الصنف الفرعي — باركود/لون/مقاس / sub item (SANFS_D.SANF_DNO)
  create_seq('SEQ_STORES_ITEM_D', 101010001014);
  -- وحدة الصنف وسعره / item unit + price (PARTIAL_SANFS.SEQ_NO)
  create_seq('SEQ_STORES_PSANF', 2188);
END;
/

-- ---------------------------------------------------------------------------
-- 3) المرجعيات: المخازن والمجموعات والأنواع والوحدات والفئات والأمناء
--    Reference data: stores, groups, types, units, categories, keepers
-- ---------------------------------------------------------------------------
DECLARE
  PROCEDURE create_seq(p_name VARCHAR2, p_start NUMBER) IS
    v_exists NUMBER;
  BEGIN
    SELECT COUNT(*) INTO v_exists FROM USER_SEQUENCES WHERE SEQUENCE_NAME = p_name;
    IF v_exists = 0 THEN
      EXECUTE IMMEDIATE 'CREATE SEQUENCE ' || p_name
        || ' START WITH ' || p_start
        || ' INCREMENT BY 1 MINVALUE 1 NOMAXVALUE NOCACHE NOCYCLE';
    END IF;
  END;
BEGIN
  create_seq('SEQ_STORES_STORE', 13);   -- STORES.STORE_NO
  create_seq('SEQ_STORES_GROUP', 10);   -- GROUP1.GROUP_NO
  create_seq('SEQ_STORES_TYPE', 28);    -- TYPES.TYPE_NO (المجموعة الفرعية)
  create_seq('SEQ_STORES_UNIT', 12);    -- UNITS.UNIT_NO
  create_seq('SEQ_STORES_CAT', 19);     -- CATEGORY.SEQ (الفئة)
  create_seq('SEQ_STORES_KEEPER', 1);   -- STORE_EMP.EMP_NO (أمين المخزن)
END;
/

-- ---------------------------------------------------------------------------
-- 4) التحقق / Verification — تُحفظ نتيجتها في db_commends.md
-- ---------------------------------------------------------------------------
-- SELECT SEQUENCE_NAME, LAST_NUMBER
--   FROM USER_SEQUENCES
--  WHERE SEQUENCE_NAME LIKE 'SEQ_STORES%'
--  ORDER BY SEQUENCE_NAME;

COMMIT;
