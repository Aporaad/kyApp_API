<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * محلل العميل — يجد العميل الموجود أو يُنشئ جديداً وفق قيود النظام القديم
 * The customer resolver — finds an existing customer or creates a new one under the
 * legacy schema's constraints.
 *
 * ── `CUST.CUST_NAME` عليه قيد فريد (CUST_BRW_U1): لا يُنشأ عميل ثانٍ بنفس الاسم أبداً.
 *    عند التطابق يُعاد استخدام الصف الموجود (قرار D6)، وعند الإنشاء يُعاد فحص التعارض.
 * ── CUST.CUST_NAME carries a unique constraint (CUST_BRW_U1): a second customer with the
 *    same name is never created. On a match the existing row is reused (decision D6), and
 *    the insert re-checks the collision.
 *
 * ── تعبئة الهاتف: `TEL1` أولاً ثم `TEL2` إذا كان مكاناً فارغاً، وإلا يبقى الرقم على
 *    الفاتورة وحدها (قاعدة §5.2).
 * ── Phone fill: TEL1 then TEL2 when a slot is empty; otherwise the number stays on the
 *    bill only (rule §5.2).
 *
 * ── ربط جاري العاملين (قرار D7): `SALES_BILL.ACC_NO` يصبح حساب العامل و`CUST_NO` يبقى
 *    للعميل. العامل يُقرأ من أحد الجداول الثلاثة كما يعرّفها WorkerController.
 * ── Worker link (decision D7): SALES_BILL.ACC_NO becomes the worker's ledger while
 *    CUST_NO stays the customer's. The worker is read from one of the three tables as
 *    WorkerController defines them.
 */
final class TailoringCustomerResolver
{
    /** أنواع العاملين الثلاثة واعمدتهم كما في WorkerController::TABLES */
    /** The three worker tables and their columns, as WorkerController::TABLES defines. */
    private const WORKER_TABLES = [
        'employee' => ['table' => 'ACCOUNTS.TAILOR_EMPLOYEES', 'pk' => 'EMP_NO', 'name' => 'EMP_NAME'],
        'cutter' => ['table' => 'ACCOUNTS.CUTTER', 'pk' => 'CUTTER_NO', 'name' => 'CUTTER_NAME'],
        'tailor' => ['table' => 'ACCOUNTS.TAILOR', 'pk' => 'TAILOR_NO', 'name' => 'TAILOR_NAME'],
    ];

    /**
     * بطاقة العميل الموجود أو null — مع رفض الموقوف لدى المتحكم، ومعها رصيده من حركاته
     * في ENTRY_SUB (طلب المستخدم: عرض رصيد العميل في بطاقة الاختيار).
     * The existing customer's card, or null — stopped ones are refused by the controller —
     * carrying the balance from the customer's own ENTRY_SUB movements (user request).
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $custNo): ?array
    {
        if ($custNo <= 0) {
            return null;
        }

        // رصيد العميل يُحسب من حركاته وحده (ENTRY_MNT مقيَّد بالحساب ورقم العميل معاً)
        // — لا من CHART_ACC.BALANCE: آلاف العملاء يشتركون في حساب المراقبة 12310000
        // فرصيد الحساب الكامل ليس رصيد العميل الواحد (نفس تقييد كشف الحساب).
        // The customer balance is computed from their own movements (ENTRY_MNT scoped
        // by account AND customer number) — never CHART_ACC.BALANCE, which thousands
        // of customers share through the control account (same scoping as the statement).
        //
        // أحدث رقم هاتف على فواتير العميل — KEEP DENSE_RANK بدل inline view متداخل:
        // Oracle 11g لا يدعم الربط بمحرف خارجي داخل inline view متداخل (ORA-904)،
        // وهو ما كان يفشل البحث والإنشاء كله.
        // The customer's newest bill phone — KEEP DENSE_RANK instead of a nested
        // inline view: 11g forbids outer correlation inside one (ORA-904), which broke
        // search and creation alike.
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT c.CUST_NO, c.CUST_NAME, c.TEL1, c.TEL2, c.ADDR, c.ACC_NO,
                    c.STOPPING_CUST,
                    (SELECT NVL(SUM(es.ENTRY_MNT), 0)
                       FROM ACCOUNTS.ENTRY_SUB es
                      WHERE es.ACC_NO = c.ACC_NO
                        AND es.GIVING = c.CUST_NO) AS ACC_BALANCE,
                    (SELECT MAX(b.TEL_NO1)
                            KEEP (DENSE_RANK LAST ORDER BY b.BILL_NO)
                       FROM ACCOUNTS.SALES_BILL b
                      WHERE b.CUST_NO = c.CUST_NO
                        AND b.TEL_NO1 IS NOT NULL) AS BILL_TEL
               FROM ACCOUNTS.CUST c
              WHERE c.CUST_NO = :no',
            ['no' => $custNo]
        );

        return $row === [] ? null : self::map($row);
    }

    /**
     * البحث بالاسم المطابق تماماً — العمود فريد فالمطابقة صف واحد
     * Exact-name lookup — the column is unique, so a match is a single row.
     *
     * @return array<string, mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        // الصيغتان معاً: المخزون بايتات CP1256 والمُدخل UTF-8، فإحداهما تُطابق المخزّن.
        // Both forms: storage holds CP1256 bytes while input is UTF-8; one of them matches.
        $oracle = ArabicEncoding::toOracleSearchChars($name);

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT CUST_NO, CUST_NAME, TEL1, TEL2, ADDR, ACC_NO, STOPPING_CUST
               FROM ACCOUNTS.CUST
              WHERE CUST_NAME = :oracle OR CUST_NAME = :plain',
            [
                'oracle' => $oracle ?? $name,
                'plain' => $name,
            ]
        );

        return $row === [] ? null : self::map($row);
    }

    /**
     * إنشاء عميل جديد — الرقم MAX+1 والحساب الافتراضي 12310000 والهاتف في أول مكان فارغ
     * Create a new customer — MAX+1 number, default ledger 12310000, phone into the first
     * empty slot.
     *
     * @return array<string, mixed>
     */
    public static function create(string $name, ?string $phone1, ?string $phone2, ?string $address): array
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(CUST_NO), 0) AS N FROM ACCOUNTS.CUST'
        );

        $custNo = (int) (reset($row) ?: 0) + 1;

        // الهاتف يُعبّأ في TEL1 ثم TEL2 فقط عند وجود مكان فارغ، وإلا يبقى على الفاتورة.
        // The phone fills TEL1 then TEL2 only when a slot is free; otherwise the bill keeps it.
        $tel1 = $phone1;
        $tel2 = $phone2;

        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.CUST
                 (CUST_NO, CUST_NAME, TEL1, TEL2, ADDR, ACC_NO, STOPPING_CUST)
             VALUES (:no, :name, :tel1, :tel2, :addr, :acc_no, 0)',
            [
                'no' => $custNo,
                'name' => ArabicEncoding::toOracleSearchChars($name) ?? $name,
                'tel1' => $tel1 !== null && $tel1 !== '' ? $tel1 : null,
                'tel2' => $tel2 !== null && $tel2 !== '' ? $tel2 : null,
                'addr' => $address !== null && $address !== ''
                    ? (ArabicEncoding::toOracleSearchChars($address) ?? $address)
                    : null,
                'acc_no' => TailoringPolicy::CUSTOMER_CONTROL_ACCOUNT,
            ]
        );

        return [
            'cust_no' => $custNo,
            'cust_name' => $name,
            'tel1' => $tel1,
            'tel2' => $tel2,
            'addr' => $address,
            'acc_no' => TailoringPolicy::CUSTOMER_CONTROL_ACCOUNT,
            'stopping_cust' => 0,
        ];
    }

    /**
     * بطاقة العامل (موظف/قصّاص/خياط) بحسابه المالي — للربط بجاري العاملين
     * The worker card (employee/cutter/tailor) with its ledger — for the worker link.
     *
     * @return array<string, mixed>|null
     */
    public static function findWorker(string $workerType, int $workerNo): ?array
    {
        $def = self::WORKER_TABLES[$workerType] ?? null;

        if ($def === null || $workerNo <= 0) {
            return null;
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT '.$def['pk'].' AS WORKER_NO, '.$def['name'].' AS WORKER_NAME, ACC_NO
               FROM '.$def['table'].'
              WHERE '.$def['pk'].' = :no',
            ['no' => $workerNo]
        );

        if ($row === []) {
            return null;
        }

        $accNo = self::pickNum($row, 'ACC_NO');
        $name = self::pickText($row, 'WORKER_NAME');

        return [
            'worker_type' => $workerType,
            'worker_no' => (int) (self::pickNum($row, 'WORKER_NO') ?? $workerNo),
            'worker_name' => $name === null ? null : ArabicEncoding::fixMojibake($name),
            'acc_no' => $accNo === null ? null : (int) $accNo,
        ];
    }

    /**
     * ربط صف CUST بقيمه القياسية — القراءة بحالة غير حساسة لأن oci8 يقلب الحروف
     * Map a CUST row into its canonical shape — case-insensitive because oci8 flips case.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        $name = self::pickText($row, 'CUST_NAME');

        return [
            'cust_no' => (int) (self::pickNum($row, 'CUST_NO') ?? 0),
            'cust_name' => $name === null ? null : ArabicEncoding::fixMojibake($name),
            // الهاتف الأساسي يرجع لأحدث فاتورة للعميل عندما يكون TEL1 فارغاً في
            // CUST — آلاف العملاء لا تحمل بطاقاتهم رقماً (طلب المستخدم الحرفي).
            // The primary phone falls back to the newest bill's number when CUST.TEL1
            // is blank — thousands of customers carry no number on their card (literal).
            'tel1' => self::pickText($row, 'TEL1') ?? self::pickText($row, 'BILL_TEL'),
            'tel2' => self::pickText($row, 'TEL2'),
            'addr' => (function () use ($row): ?string {
                $addr = self::pickText($row, 'ADDR');

                return $addr === null ? null : ArabicEncoding::fixMojibake($addr);
            })(),
            'acc_no' => self::pickNum($row, 'ACC_NO') !== null ? (int) self::pickNum($row, 'ACC_NO') : null,
            'stopping_cust' => (int) (self::pickNum($row, 'STOPPING_CUST') ?? 0),
            // رصيد العميل من حركاته المقيّدة بحسابه ورقمه — قد يكون سالباً (مدين له)
            // The customer's balance from movements scoped by account and customer number.
            'acc_balance' => self::pickNum($row, 'ACC_BALANCE'),
        ];
    }

    /**
     * قراءة نص بحالة غير حساسة / case-insensitive text read
     *
     * @param  array<string, mixed>  $row
     */
    private static function pickText(array $row, string $key): ?string
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null) {
                return (string) $v;
            }
        }

        return null;
    }

    /**
     * قراءة رقم بحالة غير حساسة / case-insensitive numeric read
     *
     * @param  array<string, mixed>  $row
     */
    private static function pickNum(array $row, string $key): float|int|null
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null && is_numeric($v)) {
                $f = (float) $v;

                return floor($f) === $f ? (int) $f : $f;
            }
        }

        return null;
    }
}
