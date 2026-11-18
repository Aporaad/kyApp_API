<?php

namespace App\Http\Controllers\Finance;

use App\Support\Finance\FinancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * البحث عن عملية مالية عبر كل القيود (مرحلة ومؤقتة) وكل السندات (قبض وصرف، نقدي وبنكي)
 * Financial-transaction search across every entry (posted + temporary) and every voucher.
 *
 * ── الحماية من المسح الكامل: النص الحر يشترط فترة زمنية، والرقم يُطابَق مطابقة تامة؛
 *    والنتائج محدودة بـ 50 لكل نوع ثم تُدمج وتُرتَّب بالتاريخ.
 * ── Full-scan protection: free text requires a date range, numbers match exactly, and each
 *    kind is capped at 50 rows before the merge sorted by date.
 *
 * ── كل نوع يظهر فقط لمن يملك صلاحية عرضه — البحث لا يكشف ما لا يراه المستخدم في قوائمه.
 * ── Each kind appears only for holders of its view grant — search never reveals more than the lists.
 */
class FinancialSearchController extends FinanceBaseController
{
    private const CAP = 50;

    private const FIELDS = ['all', 'no', 'account', 'customer', 'phone', 'details', 'amount'];

    public function search(Request $request): JsonResponse
    {
        $q = $this->text($request, 'q');
        $field = in_array((string) $request->input('field'), self::FIELDS, true) ? (string) $request->input('field') : 'all';
        $from = $this->dateInput($request, 'from_date');
        $to = $this->dateInput($request, 'to_date');
        $numeric = $q !== '' && is_numeric($q);

        if ($q === '') {
            return $this->fail('أدخل قيمة للبحث / Enter a search value', 422, ['q' => 'required']);
        }

        if (! $numeric && ($from === null || $to === null)) {
            return $this->fail('البحث النصي يحتاج فترة زمنية (من - إلى) / Text search needs a date range', 422, ['from_date' => 'required']);
        }

        $requested = array_values(array_intersect(
            (array) ($request->input('scopes') ?? ['entries', 'temp', 'receipts', 'payments']),
            ['entries', 'temp', 'receipts', 'payments']
        ));

        $grants = ['entries' => 'journal.view', 'temp' => 'journal_temp.view', 'receipts' => 'receipts.view', 'payments' => 'payments.view'];
        $scopes = array_values(array_filter($requested, fn (string $s): bool => $this->can($request, $grants[$s])));

        try {
            $results = [];
            $ctx = ['q' => $q, 'field' => $field, 'from' => $from, 'to' => $to, 'numeric' => $numeric];

            if (in_array('entries', $scopes, true)) {
                $results = array_merge($results, $this->entries($ctx));
            }
            if (in_array('temp', $scopes, true)) {
                $results = array_merge($results, $this->temps($ctx));
            }
            foreach (['receipts' => 'receipt', 'payments' => 'payment'] as $scope => $direction) {
                if (in_array($scope, $scopes, true)) {
                    foreach (['cash', 'bank'] as $source) {
                        $results = array_merge($results, $this->vouchers($ctx, $direction, $source));
                    }
                }
            }

            usort($results, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
            $results = array_slice($results, 0, self::CAP * 2);

            $this->logActivity($request, 'fin_search', 'search', 'search', mb_substr($q, 0, 50), "بحث عن عملية مالية ({$field}): {$q}");

            return $this->ok('تم البحث / Search completed', $results, 200, ['total' => count($results), 'scopes' => $scopes]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في البحث / Search failed', $e);
        }
    }

    /** @param  array<string, mixed>  $c @return array<int, array<string, mixed>> */
    private function entries(array $c): array
    {
        $or = [];
        $b = [];
        $want = fn (string $f): bool => $c['field'] === $f || $c['field'] === 'all';

        if ($c['numeric'] && $want('no')) {
            $or[] = 'm.ENTRY_NO = ?';
            $b[] = (int) $c['q'];
        }
        if ($want('details') && ! $c['numeric']) {
            [$like, $lb] = $this->likeBoth('m.DETAILS', $c['q']);
            $or[] = $like;
            array_push($b, ...$lb);
        }
        if ($want('account')) {
            [$accSql, $ab] = $this->accountFilter($c);
            $or[] = "m.ENTRY_NO IN (SELECT s.ENTRY_NO FROM ACCOUNTS.ENTRY_SUB s WHERE s.ACC_NO IN ({$accSql}))";
            array_push($b, ...$ab);
        }
        if ($want('customer') || $want('phone')) {
            [$custSql, $cb] = $this->customerFilter($c);
            if ($custSql !== null) {
                $or[] = "m.ENTRY_NO IN (SELECT s.ENTRY_NO FROM ACCOUNTS.ENTRY_SUB s WHERE s.GIVING IN ({$custSql}))";
                array_push($b, ...$cb);
            }
        }
        if ($c['numeric'] && $want('amount')) {
            $or[] = 'm.ENTRY_NO IN (SELECT s.ENTRY_NO FROM ACCOUNTS.ENTRY_SUB s WHERE ABS(s.ENTRY_MNT) = ?)';
            $b[] = (float) $c['q'];
        }

        if ($or === []) {
            return [];
        }

        [$range, $rb] = $this->range('m.ENTRY_DATE', $c);
        $rows = DB::connection('oracle')->select(
            "SELECT * FROM (
                SELECT m.ENTRY_NO, m.DETAILS, TO_CHAR(m.ENTRY_DATE,'YYYY-MM-DD') AS D, m.ENTRY_TYPE_NO, t.ENTRY_TYPE_NAME
                  FROM ACCOUNTS.ENTRY_MAIN m LEFT JOIN ACCOUNTS.ENTRY_TYPE t ON t.ENTRY_TYPE_NO = m.ENTRY_TYPE_NO
                 WHERE (".implode(' OR ', $or).") {$range}
                 ORDER BY m.ENTRY_DATE DESC, m.ENTRY_NO DESC
             ) WHERE ROWNUM <= ".self::CAP,
            array_merge($b, $rb)
        );

        $rows = array_map(static fn ($r): array => (array) $r, $rows);
        $stats = $this->entryStats(array_map(fn (array $r): int => (int) $this->int($r, 'ENTRY_NO'), $rows));
        $links = $this->voucherLinks(array_map(fn (array $r): int => (int) $this->int($r, 'ENTRY_NO'), $rows));

        return array_map(function (array $r) use ($stats, $links): array {
            $no = (int) $this->int($r, 'ENTRY_NO');

            return [
                'kind' => 'entry',
                'source' => null,
                'no' => $no,
                'year' => null,
                'entry_no' => $no,
                'date' => $this->str($r, 'D'),
                'amount' => $stats[$no]['debit'] ?? 0.0,
                'title' => $this->str($r, 'ENTRY_TYPE_NAME'),
                'details' => $this->str($r, 'DETAILS'),
                'type_no' => $this->int($r, 'ENTRY_TYPE_NO'),
                'voucher' => $links[$no] ?? null,
            ];
        }, $rows);
    }

    /** @param  array<string, mixed>  $c @return array<int, array<string, mixed>> */
    private function temps(array $c): array
    {
        $or = [];
        $b = [];
        $want = fn (string $f): bool => $c['field'] === $f || $c['field'] === 'all';

        if ($c['numeric'] && $want('no')) {
            $or[] = 'm.ENTRY_NO = ?';
            $b[] = (int) $c['q'];
        }
        if ($want('details') && ! $c['numeric']) {
            [$like, $lb] = $this->likeBoth('m.DETAILS', $c['q']);
            $or[] = $like;
            array_push($b, ...$lb);
        }
        if ($want('account')) {
            if ($c['numeric']) {
                $or[] = 'm.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN3 WHERE ACC_NO = ?)';
                $b[] = (int) $c['q'];
            } else {
                [$like, $lb] = $this->likeBoth('ACC', $c['q']);
                $or[] = "m.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN3 WHERE {$like})";
                array_push($b, ...$lb);
            }
        }
        if ($c['numeric'] && $want('amount')) {
            $or[] = 'm.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN3 WHERE ENTRY_MNT1 = ? OR ENTRY_MNT2 = ?)';
            array_push($b, (float) $c['q'], (float) $c['q']);
        }

        if ($or === []) {
            return [];
        }

        [$range, $rb] = $this->range('m.ENTRY_DATE', $c);
        $rows = DB::connection('oracle')->select(
            "SELECT * FROM (
                SELECT m.ENTRY_NO, RTRIM(m.DETAILS) AS DETAILS, TO_CHAR(m.ENTRY_DATE,'YYYY-MM-DD') AS D,
                       (SELECT SUM(NVL(ENTRY_MNT1,0)) FROM ACCOUNTS.ENTRY_MAIN3 x WHERE x.ENTRY_NO = m.ENTRY_NO) AS AMT
                  FROM ACCOUNTS.ENTRY_MAIN2 m
                 WHERE (".implode(' OR ', $or).") {$range}
                 ORDER BY m.ENTRY_DATE DESC
             ) WHERE ROWNUM <= ".self::CAP,
            array_merge($b, $rb)
        );

        return array_map(fn ($row): array => [
            'kind' => 'temp',
            'source' => null,
            'no' => $this->int((array) $row, 'ENTRY_NO'),
            'year' => null,
            'entry_no' => $this->int((array) $row, 'ENTRY_NO'),
            'date' => $this->str((array) $row, 'D'),
            'amount' => round((float) ($this->num((array) $row, 'AMT') ?? 0), 2),
            'title' => 'قيد مؤقت',
            'details' => $this->str((array) $row, 'DETAILS'),
            'type_no' => FinancePolicy::TYPE_JOURNAL,
            'voucher' => null,
        ], $rows);
    }

    /** @param  array<string, mixed>  $c @return array<int, array<string, mixed>> */
    private function vouchers(array $c, string $direction, string $source): array
    {
        $def = FinancePolicy::voucher($direction, $source);
        $or = [];
        $b = [];
        $want = fn (string $f): bool => $c['field'] === $f || $c['field'] === 'all';

        if ($c['numeric'] && $want('no')) {
            $or[] = "t.{$def['no']} = ? OR t.ENTRY_NO = ?";
            array_push($b, (int) $c['q'], (int) $c['q']);
            if ($source === 'cash') {
                $or[] = 't.MANUAL_C_NO = ?';
                $b[] = (int) $c['q'];
            }
        }
        if ($want('details') && ! $c['numeric']) {
            [$like, $lb] = $this->likeBoth('t.DETAILES', $c['q']);
            $or[] = $like;
            array_push($b, ...$lb);
        }
        if ($want('account')) {
            if ($c['numeric']) {
                $or[] = 't.ACC_NO = ?';
                $b[] = (int) $c['q'];
            } else {
                [$like, $lb] = $this->likeBoth('t.ACC_NAME', $c['q']);
                $or[] = $like;
                array_push($b, ...$lb);
            }
        }
        if ($want('customer') || $want('phone')) {
            [$custSql, $cb] = $this->customerFilter($c);
            if ($custSql !== null) {
                $or[] = "t.CUST_NO IN ({$custSql})";
                array_push($b, ...$cb);
            }
            if ($c['numeric'] && $direction === 'receipt' && $source === 'cash' && strlen($c['q']) <= 9) {
                $or[] = 't.TEL_NO = ?';
                $b[] = (int) $c['q'];
            }
        }
        if ($c['numeric'] && $want('amount')) {
            $or[] = 't.AMOUNT = ?';
            $b[] = (float) $c['q'];
        }

        if ($or === []) {
            return [];
        }

        [$range, $rb] = $this->range("t.{$def['date']}", $c);
        $rows = DB::connection('oracle')->select(
            "SELECT * FROM (
                SELECT t.{$def['no']} AS V_NO, t.YEAR, t.ENTRY_NO, t.ACC_NAME, t.AMOUNT, t.DETAILES,
                       TO_CHAR(t.{$def['date']},'YYYY-MM-DD') AS D
                  FROM {$def['table']} t
                 WHERE (".implode(' OR ', $or).") {$range}
                 ORDER BY t.{$def['date']} DESC
             ) WHERE ROWNUM <= ".self::CAP,
            array_merge($b, $rb)
        );

        return array_map(fn ($row): array => [
            'kind' => $direction,
            'source' => $source,
            'no' => $this->int((array) $row, 'V_NO'),
            'year' => $this->int((array) $row, 'YEAR'),
            'entry_no' => $this->int((array) $row, 'ENTRY_NO'),
            'date' => $this->str((array) $row, 'D'),
            'amount' => round((float) ($this->num((array) $row, 'AMOUNT') ?? 0), 2),
            'title' => $this->str((array) $row, 'ACC_NAME'),
            'details' => $this->str((array) $row, 'DETAILES'),
            'type_no' => $def['type'],
            'voucher' => null,
        ], $rows);
    }

    /** @param  array<string, mixed>  $c @return array{0: string, 1: array<int, mixed>} */
    private function accountFilter(array $c): array
    {
        if ($c['numeric']) {
            return ['?', [(int) $c['q']]];
        }

        [$like, $b] = $this->likeBoth('ACC_NAME', $c['q']);

        return ["SELECT ACC_NO FROM ACCOUNTS.CHART_ACC WHERE {$like}", $b];
    }

    /** @param  array<string, mixed>  $c @return array{0: ?string, 1: array<int, mixed>} */
    private function customerFilter(array $c): array
    {
        if ($c['numeric']) {
            if ($c['field'] === 'customer') {
                return ['?', [(int) $c['q']]];
            }

            // رقم هاتف قصير يطابق معظم العملاء — لا بحث بالهاتف دون 6 خانات
            // A short phone fragment matches most customers — no phone search under 6 digits.
            if (strlen((string) $c['q']) < 6) {
                return [null, []];
            }

            return ['SELECT CUST_NO FROM ACCOUNTS.CUST WHERE TEL1 LIKE ? OR TEL2 LIKE ?', ['%'.$c['q'].'%', '%'.$c['q'].'%']];
        }

        if ($c['field'] === 'phone') {
            return [null, []];
        }

        [$like, $b] = $this->likeBoth('CUST_NAME', $c['q']);

        return ["SELECT CUST_NO FROM ACCOUNTS.CUST WHERE {$like}", $b];
    }

    /** @param  array<string, mixed>  $c @return array{0: string, 1: array<int, string>} */
    private function range(string $column, array $c): array
    {
        $sql = '';
        $b = [];

        if ($c['from'] !== null) {
            $sql .= " AND {$column} >= TO_DATE(?, 'YYYY-MM-DD')";
            $b[] = $c['from'];
        }
        if ($c['to'] !== null) {
            $sql .= " AND {$column} < TO_DATE(?, 'YYYY-MM-DD') + 1";
            $b[] = $c['to'];
        }

        return [$sql, $b];
    }
}
