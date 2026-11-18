<?php

namespace App\Http\Controllers\Finance;

use App\Support\Finance\EntryLedger;
use App\Support\Finance\FinancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مراجع قسم القيود والسندات: الأنواع، الصناديق والبنوك، العملات وأسعارها، والبحث عن حساب/عميل
 * Entries & vouchers references: entry types, cash boxes and banks, currencies and their rates,
 * plus account and customer lookups for the forms.
 */
class FinanceReferenceController extends FinanceBaseController
{
    /** قاموس النماذج والفلاتر / the forms' and filters' reference payload */
    public function reference(): JsonResponse
    {
        try {
            $types = array_map(function ($row): array {
                $r = (array) $row;

                return [
                    'no' => $this->int($r, 'ENTRY_TYPE_NO'),
                    'name' => $this->str($r, 'ENTRY_TYPE_NAME'),
                    'source' => $this->str($r, 'SOURCE_NAME'),
                    'editable' => FinancePolicy::isEditableType($this->int($r, 'ENTRY_TYPE_NO')),
                ];
            }, DB::connection('oracle')->select(
                'SELECT ENTRY_TYPE_NO, ENTRY_TYPE_NAME, SOURCE_NAME FROM ACCOUNTS.ENTRY_TYPE ORDER BY ENTRY_TYPE_NO'
            ));

            $boxes = array_map(function ($row): array {
                $r = (array) $row;
                $accNo = (int) $this->int($r, 'ACC_NO');

                return [
                    'acc_no' => $accNo,
                    'acc_name' => $this->str($r, 'ACC_NAME'),
                    'kind' => FinancePolicy::boxKind($accNo),
                    'cur_no' => FinancePolicy::currencyOf($accNo),
                ];
            }, DB::connection('oracle')->select(
                'SELECT ACC_NO, ACC_NAME FROM ACCOUNTS.CHART_ACC
                  WHERE ACC_NO BETWEEN :lo AND :hi AND NVL(STOPPING_ACC, 0) <> 1
                  ORDER BY ACC_NO',
                ['lo' => FinancePolicy::CASH_RANGE[0], 'hi' => FinancePolicy::BANK_RANGE[1]]
            ));

            // الصندوق والبنك الافتراضيان من CASH_ACC (النوع 1 نقدية، 2 بنك)
            // The default cash box and bank from CASH_ACC (type 1 cash, 2 bank).
            $defaults = [];
            foreach (DB::connection('oracle')->select('SELECT TYPE_NO, ACC_NO FROM ACCOUNTS.CASH_ACC WHERE TYPE_NO IN (1, 2)') as $row) {
                $r = (array) $row;
                $defaults[(int) $this->int($r, 'TYPE_NO') === 1 ? 'cash' : 'bank'] = $this->int($r, 'ACC_NO');
            }

            $currencies = [];
            foreach (EntryLedger::currencyNames() as $curNo => $name) {
                $price = $curNo === FinancePolicy::LOCAL_CURRENCY ? null : EntryLedger::latestPrice($curNo);
                $currencies[] = [
                    'cur_no' => $curNo,
                    'cur_name' => $name,
                    'price' => $price['price'] ?? 1.0,
                    'seq_price' => $price['seq'] ?? null,
                ];
            }

            $today = (array) DB::connection('oracle')->selectOne("SELECT TO_CHAR(SYSDATE,'YYYY-MM-DD') AS D FROM DUAL");

            return $this->ok('تم جلب المراجع / References retrieved', [
                'entry_types' => $types,
                'boxes' => $boxes,
                'default_boxes' => $defaults,
                'currencies' => $currencies,
                'cheque_types' => array_map(
                    static fn (int $v, string $l): array => ['value' => $v, 'label' => $l],
                    array_keys(FinancePolicy::CHEQUE_TYPES),
                    FinancePolicy::CHEQUE_TYPES
                ),
                'editable_types' => FinancePolicy::EDITABLE_TYPES,
                'max_lines' => FinancePolicy::MAX_LINES,
                'local_currency' => FinancePolicy::LOCAL_CURRENCY,
                'today' => (string) (reset($today) ?: date('Y-m-d')),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب المراجع / Failed to retrieve references', $e);
        }
    }

    /** البحث عن حساب برقمه أو اسمه / account lookup by number or name */
    public function accounts(Request $request): JsonResponse
    {
        $search = $this->text($request, 'q');
        $limit = max(1, min((int) ($request->input('limit') ?? 30), 50));

        try {
            $where = 'NVL(STOPPING_ACC, 0) <> 1';
            $bindings = [];

            if ($search !== '') {
                if (ctype_digit($search)) {
                    $where .= ' AND (ACC_NO = ? OR TO_CHAR(ACC_NO) LIKE ?)';
                    $bindings = [(int) $search, $search.'%'];
                } else {
                    [$like, $likeBindings] = $this->likeBoth('ACC_NAME', $search);
                    $where .= ' AND '.$like;
                    $bindings = $likeBindings;
                }
            }

            $rows = DB::connection('oracle')->select(
                "SELECT * FROM (SELECT ACC_NO, ACC_NAME FROM ACCOUNTS.CHART_ACC WHERE {$where} ORDER BY ACC_NO) WHERE ROWNUM <= {$limit}",
                $bindings
            );

            $names = EntryLedger::currencyNames();
            $items = array_map(function ($row) use ($names): array {
                $r = (array) $row;
                $accNo = (int) $this->int($r, 'ACC_NO');
                $curNo = FinancePolicy::currencyOf($accNo);

                return [
                    'acc_no' => $accNo,
                    'acc_name' => $this->str($r, 'ACC_NAME'),
                    'cur_no' => $curNo,
                    'cur_name' => $names[$curNo] ?? null,
                    'box_kind' => FinancePolicy::boxKind($accNo),
                ];
            }, $rows);

            return $this->ok('تم جلب الحسابات / Accounts retrieved', $items);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب الحسابات / Failed to retrieve accounts', $e);
        }
    }

    /** البحث عن عميل بالاسم أو الرقم أو الهاتف / customer lookup by name, number or phone */
    public function customers(Request $request): JsonResponse
    {
        $search = $this->text($request, 'q');
        $limit = max(1, min((int) ($request->input('limit') ?? 30), 50));

        try {
            $where = '1 = 1';
            $bindings = [];

            if ($search !== '') {
                if (ctype_digit($search)) {
                    $where = '(CUST_NO = ? OR TEL1 LIKE ? OR TEL2 LIKE ?)';
                    $bindings = [(int) $search, '%'.$search.'%', '%'.$search.'%'];
                } else {
                    [$where, $bindings] = $this->likeBoth('CUST_NAME', $search);
                }
            }

            $rows = DB::connection('oracle')->select(
                "SELECT * FROM (SELECT CUST_NO, CUST_NAME, ACC_NO, TEL1 FROM ACCOUNTS.CUST WHERE {$where} ORDER BY CUST_NO DESC) WHERE ROWNUM <= {$limit}",
                $bindings
            );

            $items = array_map(fn ($row): array => [
                'cust_no' => $this->int((array) $row, 'CUST_NO'),
                'cust_name' => $this->str((array) $row, 'CUST_NAME'),
                'acc_no' => $this->int((array) $row, 'ACC_NO'),
                'tel' => $this->str((array) $row, 'TEL1'),
            ], $rows);

            return $this->ok('تم جلب العملاء / Customers retrieved', $items);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب العملاء / Failed to retrieve customers', $e);
        }
    }

    /**
     * تسجيل طباعة/تصدير قائمة كاملة — الوحدة من الطلب والصلاحية الخاصة بها تُفحَص هنا
     * Log a whole-list print/export — the module comes from the request and its own grant is
     * checked here (the route only guarantees one of the eight print/export codes).
     */
    public function listActivity(Request $request): JsonResponse
    {
        $module = (string) $request->input('module');
        $action = (string) $request->input('action');
        $modules = ['journal' => 'القيود المرحلة', 'journal_temp' => 'القيود المؤقتة', 'receipts' => 'سندات القبض', 'payments' => 'سندات الصرف'];

        if (! isset($modules[$module]) || ! in_array($action, ['print', 'export'], true)) {
            return $this->fail('قيمة module أو action غير مدعومة / Unsupported module or action');
        }

        if (! $this->can($request, "{$module}.{$action}")) {
            return $this->forbidden(($action === 'print' ? 'طباعة ' : 'تصدير ').$modules[$module]);
        }

        $count = (int) $request->input('count');
        $this->logActivity($request, $module, $action === 'print' ? 'list_printed' : 'list_exported', 'list', $module,
            ($action === 'print' ? 'طباعة قائمة ' : 'تصدير قائمة ').$modules[$module]." ({$count} سجل)");

        return $this->ok('تم التسجيل / Logged');
    }
}
