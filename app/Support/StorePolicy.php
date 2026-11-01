<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * سياسة المخازن — القيم المرجعية التي تحكم قسم المخازن بالكامل
 * Stores policy — the reference values that govern the whole stores module.
 *
 * كل الأرقام هنا مستخرجة من مخطط ACCOUNTS الحيّ لا من تخمين، وأي تغيير في الـ VIEWs
 * القديمة (ITEM_MOVE_D / ITEM_MOVEMENT_D) يفرض مراجعة هذا الملف أولاً.
 * Every constant below was read from the live ACCOUNTS schema, not guessed. Any change in
 * the legacy movement views must be reconciled here first.
 */
final class StorePolicy
{
    /**
     * المخازن التي تستثنيها `ITEM_MOVEMENT_D` من الأرصدة (مخازن مؤرشفة/قديمة).
     * Stores excluded by ITEM_MOVEMENT_D from balances (archived/legacy warehouses).
     *
     * ── لماذا ثابت لا استعلام؟ لأن الرقم مكتوب حرفياً داخل تعريف الـ VIEW:
     *    `WHERE store_no NOT IN (11, 12, 2)`.
     * ── لا يُقبل إنشاء أو تعديل مستند على أحد هذه المخازن: سيُحفظ ولا يظهر في أي رصيد،
     *    فيظن المستخدم أن الكمية دخلت وهي مفقودة.
     * Orders on these stores are refused: they would be stored but never appear in any
     * balance, so the user would believe the quantity landed while it silently vanished.
     *
     * @var array<int, int>
     */
    public const NON_STOCK_STORES = [2, 11, 12];

    public const KIND_RECEIVE = 'receive';

    public const KIND_ISSUE = 'issue';

    public const KIND_TRANSFER = 'transfer';

    /**
     * أقصى مدى زمني لتقرير واحد بالأيام — التقارير بلا مدى تفتح مسحاً كاملاً للـ VIEW.
     * Maximum span of a single report in days; an unbounded report scans the whole view.
     */
    public const MAX_REPORT_DAYS = 731;

    /**
     * تعريف كل نوع مستند: جدولاه، ونوع الحركة، وتسلسل الترقيم.
     * Each document kind: its two tables, its movement type, and its numbering sequence.
     *
     * `typeno` هو `TRANS_TYPE.TYPE_NO` الذي تربطه الـ VIEW بالمستند:
     *   12 = أمر توريد مخزني · 13 = أمر صرف مخزني · 4/5 = تحويل وارد/منصرف.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function documents(): array
    {
        return [
            self::KIND_RECEIVE => [
                'master' => 'ACCOUNTS.INVENTORY_RECEIVE',
                'details' => 'ACCOUNTS.INVENTORY_RECEIVE_DETAILES',
                'table' => 'INVENTORY_RECEIVE',
                'details_table' => 'INVENTORY_RECEIVE_DETAILES',
                'typeno' => 12,
                'tr_type_id' => 1,
                'sequence' => 'SEQ_STORES_RCV',
                'party' => 'PROV_NAME',
                'bill_type' => 2,
                'inout' => 1,
            ],
            self::KIND_ISSUE => [
                'master' => 'ACCOUNTS.INVENTORY_SARF',
                'details' => 'ACCOUNTS.INVENTORY_SARF_DETAILES',
                'table' => 'INVENTORY_SARF',
                'details_table' => 'INVENTORY_SARF_DETAILES',
                'typeno' => 13,
                'tr_type_id' => 16,
                'sequence' => 'SEQ_STORES_ISS',
                'party' => 'CUST_NAME',
                'bill_type' => 2,
                'inout' => -1,
            ],
            self::KIND_TRANSFER => [
                'master' => 'ACCOUNTS.INVENTORY_TRANSM',
                'details' => 'ACCOUNTS.INVENTORY_TRANSD',
                'table' => 'INVENTORY_TRANSM',
                'details_table' => 'INVENTORY_TRANSD',
                'typeno' => 5,
                'tr_type_id' => 18,
                'sequence' => 'SEQ_STORES_TRNM',
                'party' => 'DETAILES',
                'bill_type' => 1,
                'inout' => -1,
            ],
        ];
    }

    /**
     * تعريف نوع مستند واحد، أو null للنوع غير المعروف
     * One document definition, or null for an unknown kind.
     *
     * @return array<string, mixed>|null
     */
    public static function document(string $kind): ?array
    {
        return self::documents()[$kind] ?? null;
    }

    /**
     * هل للمخزن رصيد أصلاً؟ (كل ما عدا المستثنى)
     * Does this store carry a balance at all? (everything but the excluded list)
     */
    public static function isStockStore(?int $storeNo): bool
    {
        if ($storeNo === null) {
            return false;
        }

        return ! in_array($storeNo, self::NON_STOCK_STORES, true);
    }

    /**
     * وسوم المخازن المستثناة للعرض في الواجهة
     * The excluded stores as a client-readable payload.
     *
     * @return array<int, int>
     */
    public static function excludedStores(): array
    {
        return self::NON_STOCK_STORES;
    }

    /**
     * الرقم التالي من تسلسل أوراكل — لا `MAX+1` في PHP أبداً
     * Next number from an Oracle sequence — never a PHP-side MAX+1.
     *
     * القيد الفريد `(BILL_NO, YEAR)` يجعل `MAX+1` يتصادم تحت التزامن، والتسلسل لا يقفل جدولاً.
     * The unique key (BILL_NO, YEAR) makes MAX+1 collide under concurrency; a sequence does not.
     */
    public static function nextNumber(string $sequence): int
    {
        // أسماء التسلسلات تُكتب كاملة بالمخطط: هذا SQL لا PL/SQL، فلا يقع في مشكلة
        // تعارض الاسم مع VIEW اسمه ACCOUNTS (PLS-00302) المذكورة في سكربت المخطط.
        // Fully qualified here is safe because this is SQL, not PL/SQL.
        $row = (array) DB::connection('oracle')
            ->selectOne('SELECT '.$sequence.'.NEXTVAL AS N FROM DUAL');

        return (int) (reset($row) ?: 0);
    }

    /**
     * سنة المستند من ساعة أوراكل لا من ساعة التطبيق (توقيت التطبيق UTC وأوراكل +03:00)
     * The document year from the Oracle clock, not the app clock (app is UTC, Oracle +03:00).
     */
    public static function dbYear(): int
    {
        $row = (array) DB::connection('oracle')
            ->selectOne("SELECT TO_NUMBER(TO_CHAR(SYSDATE, 'YYYY')) AS Y FROM DUAL");

        return (int) (reset($row) ?: 0);
    }

    /**
     * تاريخ اليوم من ساعة أوراكل بصيغة YYYY-MM-DD
     * Today's date from the Oracle clock, as YYYY-MM-DD.
     */
    public static function dbToday(): string
    {
        $row = (array) DB::connection('oracle')
            ->selectOne("SELECT TO_CHAR(SYSDATE, 'YYYY-MM-DD') AS D FROM DUAL");

        return (string) (reset($row) ?: '');
    }

    /**
     * تصفية الأرصدة على المخازن التي لها رصيد فعلي
     * Restrict a balance query to the stores that actually carry stock.
     *
     * يُستدعى في كل استعلام أرصدة حتى لا يظهر مخزن أرشيفي بصفوف صفرية مضللة.
     * Called by every balance query so an archived store never shows misleading zero rows.
     */
    public static function excludeArchivedStores(Builder $query, string $column = 'INV_ID'): Builder
    {
        return $query->whereNotIn($column, self::NON_STOCK_STORES);
    }

    /**
     * أسماء أنواع الحركة العربية كما هي في TRANS_TYPE — تُغذّي منتَقي التقرير
     * Arabic movement type names straight from TRANS_TYPE — feeds the report picker.
     *
     * @return array<int, string>
     */
    public static function movementTypeLabels(): array
    {
        return [
            12 => 'أمر توريد مخزني',
            13 => 'أمر صرف مخزني',
            4 => 'توريد نقل مخزني',
            5 => 'صرف نقل مخزني',
            0 => 'مشتريات',
            2 => 'مبيعات',
            17 => 'منصرف للقص',
            14 => 'استلام انتاج تام',
            11 => 'تسويات مخزنية -دائنة',
            10 => 'تسويات مخزنية -مدينة',
            1 => 'مردودات مبيعات',
            3 => 'مردودات مشتريات',
            6 => 'جرد أول المدة',
        ];
    }
}
