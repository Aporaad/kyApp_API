<?php

namespace App\Http\Controllers\Finance;

use App\Support\ArabicEncoding;
use App\Support\Finance\ArabicTafkeet;
use App\Support\Finance\EntryAudit;
use App\Support\Finance\EntryLedger;
use App\Support\Finance\FinancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم سندات القبض والصرف — أربعة جداول (CASH_RECEIVE, BANK_RECEIVE, CASH_SARF, BANK_SARF)
 * Receipt and payment vouchers controller — four tables behind one surface.
 *
 * ── الاتجاه (receipt / payment) يأتي من تعريف المسار، والمصدر (cash / bank) من الطلب.
 * ── «المتعدد» ليس جدولاً: هو سند لقيده أكثر من سطرين في ENTRY_SUB (مثبت: 363 سند صرف
 *    متعدد في 2026)، فيحمل رأسه أول حساب مقابل و AMOUNT = المجموع، ولكل سطر بيانه.
 * ── قيد السند: القبض = مدين الصندوق/البنك بالمجموع ثم دائن كل حساب مقابل؛ الصرف = العكس.
 * ── The direction comes from the route, the source from the request. "Multi" is not a table:
 *    it is a voucher whose entry has more than two lines. Receipt = debit the box with the
 *    total then credit each counter account; payment = the mirror image.
 */
class VoucherController extends FinanceBaseController
{
    /** قائمة السندات: الكل/النقدية/البنكية/المتعدد / the voucher list per tab */
    public function index(Request $request): JsonResponse
    {
        $direction = $this->direction($request);
        [$page, $limit, $offset] = $this->paging($request);
        $kind = (string) ($request->input('kind') ?? 'all');
        $sources = match ($kind) {
            'cash' => ['cash'],
            'bank' => ['bank'],
            default => ['cash', 'bank'],
        };

        try {
            $bindings = [];
            $parts = [];

            foreach ($sources as $source) {
                $parts[] = $this->sourceSelect($direction, $source, $request, $kind === 'multi', $bindings);
            }

            $union = implode(' UNION ALL ', $parts);
            $multi = 'WITH MULTI AS (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_SUB GROUP BY ENTRY_NO HAVING COUNT(*) > 2) ';
            $prefix = $kind === 'multi' ? $multi : '';

            $countRow = (array) DB::connection('oracle')->selectOne("{$prefix}SELECT COUNT(*) AS C FROM ({$union})", $bindings);
            $total = (int) (reset($countRow) ?: 0);

            $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $order = match ((string) ($request->input('sort') ?? 'date')) {
                'no' => "V_NO {$dir}, V_YEAR {$dir}",
                'amount' => "AMOUNT {$dir}, V_DATE DESC",
                'name' => "ACC_NAME {$dir}, V_DATE DESC",
                default => "V_DATE {$dir}, V_NO {$dir}",
            };

            $rows = DB::connection('oracle')->select(
                "{$prefix}SELECT * FROM (
                    SELECT q.*, ROWNUM AS RN FROM (SELECT * FROM ({$union}) ORDER BY {$order}) q WHERE ROWNUM <= ?
                 ) WHERE RN > ?",
                array_merge($bindings, [$offset + $limit, $offset])
            );

            $rows = array_map(static fn ($r): array => (array) $r, $rows);
            $stats = $this->entryStats(array_map(fn (array $r): int => (int) $this->int($r, 'ENTRY_NO'), $rows));
            $items = array_map(fn (array $r): array => $this->summary($direction, $r, $stats), $rows);

            return $this->ok('تم جلب السندات / Vouchers retrieved', $items, 200, $this->meta($total, $page, $limit));
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب السندات / Failed to retrieve vouchers', $e);
        }
    }

    /** سند واحد بقيده وسطوره وتدقيقه / one voucher with its entry, lines and audit */
    public function show(Request $request, string $source, int $no, int $year): JsonResponse
    {
        $direction = $this->direction($request);

        if (! FinancePolicy::isVoucher($direction, $source)) {
            return $this->fail('نوع السند غير معروف / Unknown voucher source', 404);
        }

        try {
            $detail = $this->detail($direction, $source, $no, $year);

            if ($detail === null) {
                return $this->fail('السند غير موجود / Voucher not found', 404);
            }

            $def = FinancePolicy::voucher($direction, $source);
            $this->logActivity($request, $this->module($direction), 'viewed', $def['entity'], "{$no}/{$year}", 'عرض سند');

            return $this->ok('تم جلب السند / Voucher retrieved', $detail);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب السند / Failed to retrieve the voucher', $e);
        }
    }

    /** إنشاء سند بقيده في معاملة واحدة / create a voucher and its entry in one transaction */
    public function store(Request $request): JsonResponse
    {
        $direction = $this->direction($request);
        $source = (string) $request->input('source');

        if (! FinancePolicy::isVoucher($direction, $source)) {
            return $this->fail('مصدر السند يجب أن يكون نقدياً أو بنكياً / The source must be cash or bank', 422, ['source' => 'in:cash,bank']);
        }

        $payload = $this->payload($request, $direction, $source);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $def = FinancePolicy::voucher($direction, $source);

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(function () use ($payload, $direction, $source, $def, $request): array {
                $year = (int) substr($payload['date'], 0, 4);
                $entryNo = FinancePolicy::nextVoucherEntryNo($direction, $source, $year);
                $no = FinancePolicy::nextVoucherNo($direction, $source, $year);

                // القيد أولاً: ENTRY_NO مفتاح أجنبي غير فارغ في جدول السند
                // Entry first: ENTRY_NO is a NOT NULL foreign key on the voucher row.
                EntryLedger::insertHeader($entryNo, $payload['details'], $payload['date'], $def['type']);
                EntryLedger::insertLines($entryNo, $payload['entry_lines']);
                EntryAudit::created($entryNo, $request);

                $columns = $this->columns($direction, $source, $payload, $entryNo);
                [$creatorId, $creatorName] = EntryAudit::who($request, $def['creator_len']);
                $columns['CREATED_BY'] = $creatorId;
                $columns['CREATED_BY_NAME'] = $creatorName;
                $this->insertVoucher($def, $source, $no, $year, $columns);

                return ['no' => $no, 'year' => $year, 'entry_no' => $entryNo, 'source' => $source];
            }));

            $this->logActivity($request, $this->module($direction), 'created', $def['entity'], "{$result['no']}/{$result['year']}",
                $this->label($direction, $source).' — إنشاء سند '.$result['no'].' بمبلغ '.$payload['total']);

            return $this->ok('تم حفظ السند / Voucher saved', $result, 201);
        } catch (\Throwable $e) {
            $this->logActivity($request, $this->module($direction), 'create_failed', $def['entity'], '-', 'فشل إنشاء سند', true);

            return $this->errorResponse('خطأ في حفظ السند / Failed to save the voucher', $e);
        }
    }

    /** تعديل سند — يحتفظ برقمه ورقم قيده ويستبدل سطور القيد / update a voucher in place */
    public function update(Request $request, string $source, int $no, int $year): JsonResponse
    {
        $direction = $this->direction($request);
        $guard = $this->editableGuard($direction, $source, $no, $year);

        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $payload = $this->payload($request, $direction, $source);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if ((int) substr($payload['date'], 0, 4) !== $year) {
            return $this->fail('لا يمكن نقل السند إلى سنة أخرى / A voucher cannot move to another year', 422, ['date' => 'year']);
        }

        $def = FinancePolicy::voucher($direction, $source);
        $entryNo = (int) $this->int($guard, 'ENTRY_NO');

        try {
            DB::connection('oracle')->transaction(function () use ($payload, $direction, $source, $def, $entryNo, $no, $year, $request): void {
                EntryLedger::updateHeader($entryNo, $payload['details'], $payload['date']);
                EntryLedger::deleteLines($entryNo);
                EntryLedger::insertLines($entryNo, $payload['entry_lines']);
                EntryAudit::updated($entryNo, $request);

                $columns = $this->columns($direction, $source, $payload, $entryNo);
                [$userId, $userName] = EntryAudit::who($request, $def['creator_len']);
                $columns['LAST_UPDATED_BY'] = $userId;
                $columns['LAST_UPDATED_BY_NAME'] = $userName;
                $this->updateVoucher($def, $source, $no, $year, $columns);
            });

            $this->logActivity($request, $this->module($direction), 'updated', $def['entity'], "{$no}/{$year}",
                $this->label($direction, $source).' — تعديل سند '.$no.' بمبلغ '.$payload['total']);

            return $this->ok('تم تعديل السند / Voucher updated', ['no' => $no, 'year' => $year, 'entry_no' => $entryNo, 'source' => $source]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل السند / Failed to update the voucher', $e);
        }
    }

    /** حذف سند مع قيده بالكامل / delete a voucher together with its whole entry */
    public function destroy(Request $request, string $source, int $no, int $year): JsonResponse
    {
        $direction = $this->direction($request);
        $guard = $this->editableGuard($direction, $source, $no, $year);

        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $def = FinancePolicy::voucher($direction, $source);
        $entryNo = (int) $this->int($guard, 'ENTRY_NO');

        try {
            DB::connection('oracle')->transaction(function () use ($def, $no, $year, $entryNo): void {
                DB::connection('oracle')->delete(
                    "DELETE FROM {$def['table']} WHERE {$def['no']} = :no AND YEAR = :year",
                    ['no' => $no, 'year' => $year]
                );
                EntryLedger::deleteEntry($entryNo);
            });

            $name = ArabicEncoding::normalizeStatementText($guard['ACC_NAME'] ?? '');
            $this->logActivity($request, $this->module($direction), 'deleted', $def['entity'], "{$no}/{$year}",
                $this->label($direction, $source)." — حذف سند {$no}/{$year} بمبلغ ".$this->num($guard, 'AMOUNT')." — {$name} — قيد {$entryNo}");

            return $this->ok('تم حذف السند وقيده / Voucher and entry deleted', ['no' => $no, 'year' => $year]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف السند / Failed to delete the voucher', $e);
        }
    }

    /** تسجيل طباعة/تصدير سند بقالب السند أو القيد / log a print/export with its template */
    public function activity(Request $request, string $source, int $no, int $year): JsonResponse
    {
        $direction = $this->direction($request);
        $action = (string) $request->input('action');
        $template = (string) ($request->input('template') ?? 'voucher') === 'entry' ? 'entry' : 'voucher';

        if (! FinancePolicy::isVoucher($direction, $source) || ! in_array($action, ['print', 'export'], true)) {
            return $this->fail('قيمة action غير مدعومة / Unsupported action value');
        }

        $module = $this->module($direction);

        if (! $this->can($request, "{$module}.{$action}")) {
            return $this->forbidden($action === 'print' ? 'طباعة السندات' : 'تصدير السندات');
        }

        $def = FinancePolicy::voucher($direction, $source);
        $verb = $action === 'print' ? 'طباعة' : 'تصدير';
        $tpl = $template === 'entry' ? 'بقالب القيد' : 'بقالب السند';
        $this->logActivity($request, $module, $action === 'print' ? 'printed' : 'exported', $def['entity'], "{$no}/{$year}",
            "{$verb} ".$this->label($direction, $source)." {$no}/{$year} {$tpl}");

        return $this->ok('تم التسجيل / Logged');
    }

    // ── القراءة / reads ─────────────────────────────────────────────

    /**
     * التفاصيل الكاملة لسند — الرأس، الصندوق، السطور المقابلة بعملة السند، والقيد
     * The full voucher detail — header, box, counter lines in the voucher currency, and entry.
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $direction, string $source, int $no, int $year): ?array
    {
        $row = $this->row($direction, $source, $no, $year);

        if ($row === null) {
            return null;
        }

        $entryNo = (int) $this->int($row, 'ENTRY_NO');
        $lines = EntryLedger::readLines($entryNo);
        $stats = [$entryNo => ['lines' => count($lines), 'debit' => EntryLedger::totals($lines)['debit']]];
        $summary = $this->summary($direction, array_merge($row, ['SRC' => $source, 'V_NO' => $no, 'V_YEAR' => $year]), $stats);
        $curNo = (int) ($this->int($row, 'CUR_NO') ?? FinancePolicy::LOCAL_CURRENCY);

        // سطر الصندوق: مدين في القبض ودائن في الصرف، وحسابه 121/122
        // The box line: a debit on receipts and a credit on payments, on a 121/122 account.
        $boxIndex = null;
        foreach ($lines as $i => $line) {
            $isBox = FinancePolicy::boxKind((int) $line['acc_no']) !== null;
            $sideOk = $direction === 'receipt' ? $line['debit'] > 0 : $line['credit'] > 0;

            if ($isBox && $sideOk) {
                $boxIndex = $i;
                break;
            }
        }

        $box = $boxIndex === null ? null : $lines[$boxIndex];
        $price = $box !== null && $box['price'] > 0 ? (float) $box['price'] : 1.0;
        $voucherLines = [];

        foreach ($lines as $i => $line) {
            if ($i === $boxIndex) {
                continue;
            }

            $raw = $line['debit'] > 0 ? $line['debit'] : $line['credit'];
            // سطر محلي في سند أجنبي يُعاد إلى عملة السند بقسمة السعر
            // A local line on a foreign voucher is brought back to the voucher currency.
            $amount = (int) $line['cur_no'] === $curNo ? $raw : round($raw / $price, 2);

            $voucherLines[] = [
                'acc_no' => $line['acc_no'],
                'acc_name' => $line['acc_name'],
                'amount' => $amount,
                'sub_details' => $line['sub_details'],
                'cust_no' => $line['cust_no'],
                'cust_name' => $line['cust_name'],
            ];
        }

        $structureOk = $box !== null && $voucherLines !== [];
        $words = $this->str($row, $source === 'cash' ? 'TYPE' : 'TAFKEET');

        return array_merge($summary, [
            'editable' => $summary['editable'] && $structureOk,
            'read_only_reason' => $summary['read_only_reason'] ?? ($structureOk ? null : 'structure'),
            'amount_words' => $words ?? ArabicTafkeet::forCurrency((float) $this->num($row, 'AMOUNT'), $curNo),
            'cur_price' => $price,
            'box' => $box === null ? null : ['acc_no' => $box['acc_no'], 'acc_name' => $box['acc_name'], 'cur_no' => $box['cur_no']],
            'voucher_lines' => $voucherLines,
            'tel_no' => $this->str($row, 'TEL_NO'),
            'cheq_date' => $this->str($row, 'CHEQ_DATE_ISO'),
            'cheq_type' => $this->int($row, 'CHEQ_TYPE'),
            'name_in_cheq' => $this->str($row, 'NAME_IN_CHEQ'),
            'entry' => [
                'entry_no' => $entryNo,
                'lines' => $lines,
                'totals' => EntryLedger::totals($lines),
            ],
            'audit' => [
                'created_by_name' => $this->str($row, 'CREATED_BY_NAME'),
                'created_on' => $this->str($row, 'CREATED_ON_TXT'),
                'updated_by_name' => $this->str($row, 'LAST_UPDATED_BY_NAME'),
                'updated_on' => $this->str($row, 'UPDATED_ON_TXT'),
            ],
        ]);
    }

    /** الصف الخام للسند / the raw voucher row @return array<string, mixed>|null */
    private function row(string $direction, string $source, int $no, int $year): ?array
    {
        $def = FinancePolicy::voucher($direction, $source);
        $cheq = $source === 'bank' ? ", TO_CHAR(t.CHEQ_DATE,'YYYY-MM-DD') AS CHEQ_DATE_ISO" : '';

        $row = DB::connection('oracle')->selectOne(
            "SELECT t.*, TO_CHAR(t.{$def['date']},'YYYY-MM-DD') AS V_DATE_ISO,
                    TO_CHAR(t.CREATED_ON,'YYYY-MM-DD HH24:MI') AS CREATED_ON_TXT,
                    TO_CHAR(t.LAST_UPDATED_ON,'YYYY-MM-DD HH24:MI') AS UPDATED_ON_TXT{$cheq}
               FROM {$def['table']} t
              WHERE t.{$def['no']} = :no AND t.YEAR = :year",
            ['no' => $no, 'year' => $year]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * استعلام مصدر واحد بأعمدة موحّدة وفلاتر مربوطة — يُدمج مع الآخر بـ UNION ALL
     * One source's select with unified columns and bound filters — merged with UNION ALL.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function sourceSelect(string $direction, string $source, Request $request, bool $multiOnly, array &$bindings): string
    {
        $def = FinancePolicy::voucher($direction, $source);
        $t = 't';
        $no = "{$t}.{$def['no']}";
        $date = "{$t}.{$def['date']}";
        $isReceipt = $direction === 'receipt';

        $extra = match ("{$direction}.{$source}") {
            'receipt.cash' => "{$t}.BILL_NO, {$t}.INVOICE_NO, {$t}.MANUAL_C_NO AS MANUAL_NO, {$t}.ACC_NO1 AS BOX_ACC, NULL AS BANK_NAME, NULL AS CHEQ_NO, TO_CHAR({$t}.TEL_NO) AS TEL_NO",
            'payment.cash' => "NULL AS BILL_NO, NULL AS INVOICE_NO, {$t}.MANUAL_C_NO AS MANUAL_NO, NULL AS BOX_ACC, NULL AS BANK_NAME, NULL AS CHEQ_NO, NULL AS TEL_NO",
            'receipt.bank' => "{$t}.BILL_NO, NULL AS INVOICE_NO, NULL AS MANUAL_NO, {$t}.TRANSFER_ACC_NO AS BOX_ACC, {$t}.BANK_NAME, {$t}.CHEQ_NO, NULL AS TEL_NO",
            default => "NULL AS BILL_NO, NULL AS INVOICE_NO, NULL AS MANUAL_NO, NULL AS BOX_ACC, {$t}.BANK_NAME, {$t}.CHEQ_NO, NULL AS TEL_NO",
        };

        $where = ['1 = 1'];

        if (($from = $this->dateInput($request, 'from_date')) !== null) {
            $where[] = "{$date} >= TO_DATE(?, 'YYYY-MM-DD')";
            $bindings[] = $from;
        }

        if (($to = $this->dateInput($request, 'to_date')) !== null) {
            $where[] = "{$date} < TO_DATE(?, 'YYYY-MM-DD') + 1";
            $bindings[] = $to;
        }

        if (($year = (int) $request->input('year')) > 0) {
            $where[] = "{$t}.YEAR = ?";
            $bindings[] = $year;
        }

        if (($search = $this->text($request, 'search')) !== '') {
            if (ctype_digit($search)) {
                $manual = $source === 'cash' ? " OR {$t}.MANUAL_C_NO = ?" : '';
                $where[] = "({$no} = ? OR {$t}.ENTRY_NO = ?{$manual})";
                array_push($bindings, (int) $search, (int) $search);
                if ($manual !== '') {
                    $bindings[] = (int) $search;
                }
            } else {
                [$likeName, $b1] = $this->likeBoth("{$t}.ACC_NAME", $search);
                [$likeDetails, $b2] = $this->likeBoth("{$t}.DETAILES", $search);
                $where[] = "({$likeName} OR {$likeDetails})";
                array_push($bindings, ...$b1, ...$b2);
            }
        }

        foreach (['acc_no' => 'ACC_NO', 'cust_no' => 'CUST_NO', 'cur_no' => 'CUR_NO'] as $key => $column) {
            if (($value = (int) $request->input($key)) > 0) {
                $where[] = "{$t}.{$column} = ?";
                $bindings[] = $value;
            }
        }

        if (is_numeric($min = $request->input('min_amount'))) {
            $where[] = "{$t}.AMOUNT >= ?";
            $bindings[] = (float) $min;
        }

        if (is_numeric($max = $request->input('max_amount'))) {
            $where[] = "{$t}.AMOUNT <= ?";
            $bindings[] = (float) $max;
        }

        if ((string) $request->input('mine') === '1' && ($me = $request->user()?->getAuthIdentifier()) !== null) {
            $where[] = "({$t}.CREATED_BY = ? OR {$t}.LAST_UPDATED_BY = ?)";
            array_push($bindings, (int) $me, (int) $me);
        }

        if ($isReceipt && (string) $request->input('linked') !== '') {
            $where[] = (string) $request->input('linked') === '1' ? "{$t}.BILL_NO IS NOT NULL" : "{$t}.BILL_NO IS NULL";
        }

        if ($multiOnly) {
            $where[] = "{$t}.ENTRY_NO IN (SELECT ENTRY_NO FROM MULTI)";
        }

        return "SELECT '{$source}' AS SRC, {$no} AS V_NO, {$t}.YEAR AS V_YEAR, {$date} AS V_DATE,
                       TO_CHAR({$date},'YYYY-MM-DD') AS V_DATE_ISO, {$t}.ACC_NO, {$t}.ACC_NAME, {$t}.AMOUNT,
                       {$t}.DETAILES, {$t}.ENTRY_NO, {$t}.CUR_NO, {$t}.CUST_NO, {$t}.CREATED_BY_NAME, {$extra}
                  FROM {$def['table']} {$t}
                 WHERE ".implode(' AND ', $where);
    }

    /**
     * ملخص سند للقائمة والعرض / a voucher summary for the list and the view
     *
     * @param  array<string, mixed>  $r
     * @param  array<int, array{lines: int, debit: float}>  $stats
     * @return array<string, mixed>
     */
    private function summary(string $direction, array $r, array $stats): array
    {
        $entryNo = (int) $this->int($r, 'ENTRY_NO');
        $linesCount = $stats[$entryNo]['lines'] ?? 0;
        $billNo = $this->int($r, 'BILL_NO');
        $invoiceNo = $this->int($r, 'INVOICE_NO');
        $linked = $billNo !== null || $invoiceNo !== null;
        $curNo = (int) ($this->int($r, 'CUR_NO') ?? FinancePolicy::LOCAL_CURRENCY);
        $source = (string) ($r['SRC'] ?? 'cash');

        return [
            'direction' => $direction,
            'source' => $source,
            'no' => $this->int($r, 'V_NO') ?? $this->int($r, $source === 'cash' ? 'C_NO' : 'B_NO'),
            'year' => $this->int($r, 'V_YEAR') ?? $this->int($r, 'YEAR'),
            'date' => $this->str($r, 'V_DATE_ISO'),
            'acc_no' => $this->int($r, 'ACC_NO'),
            'acc_name' => $this->str($r, 'ACC_NAME'),
            'amount' => round((float) ($this->num($r, 'AMOUNT') ?? 0), 2),
            'cur_no' => $curNo,
            'cur_name' => EntryLedger::currencyNames()[$curNo] ?? null,
            'details' => $this->str($r, 'DETAILES'),
            'entry_no' => $entryNo,
            'cust_no' => $this->int($r, 'CUST_NO'),
            'manual_no' => $this->int($r, 'MANUAL_NO') ?? $this->int($r, 'MANUAL_C_NO'),
            'bill_no' => $billNo,
            'invoice_no' => $invoiceNo,
            'box_acc_no' => $this->int($r, 'BOX_ACC') ?? $this->int($r, 'ACC_NO1') ?? $this->int($r, 'TRANSFER_ACC_NO'),
            'bank_name' => $this->str($r, 'BANK_NAME'),
            'cheq_no' => $this->str($r, 'CHEQ_NO'),
            'lines_count' => $linesCount,
            'is_multi' => $linesCount > 2,
            'editable' => ! $linked,
            'read_only_reason' => $linked ? 'linked' : null,
            'created_by_name' => $this->str($r, 'CREATED_BY_NAME'),
        ];
    }

    // ── الكتابة / writes ────────────────────────────────────────────

    /**
     * حارس الكتابة: السند موجود وغير مرتبط بفاتورة/كرت تفصيل
     * Write guard: the voucher exists and is not tied to an invoice / cutting card.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function editableGuard(string $direction, string $source, int $no, int $year): array|JsonResponse
    {
        if (! FinancePolicy::isVoucher($direction, $source)) {
            return $this->fail('نوع السند غير معروف / Unknown voucher source', 404);
        }

        $row = $this->row($direction, $source, $no, $year);

        if ($row === null) {
            return $this->fail('السند غير موجود / Voucher not found', 404);
        }

        if ($this->int($row, 'BILL_NO') !== null || $this->int($row, 'INVOICE_NO') !== null) {
            return $this->fail('السند صادر من فاتورة أو كرت تفصيل — يُدار من مصدره / This voucher belongs to an invoice or cutting card', 422, ['voucher' => 'linked']);
        }

        return $row;
    }

    /**
     * التحقق من حمولة السند وبناء سطور قيده بالإشارات والعملات الصحيحة
     * Validate the voucher payload and build its entry lines with the right signs and currencies.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function payload(Request $request, string $direction, string $source): array|JsonResponse
    {
        $def = FinancePolicy::voucher($direction, $source);
        $bank = $source === 'bank';

        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'details' => 'required|string|max:'.$def['details_len'],
            'box_acc_no' => 'required|integer|min:1',
            'manual_no' => 'nullable|integer|min:1',
            'tel_no' => 'nullable|digits_between:1,9',
            'seq_price' => 'nullable|integer|min:1',
            'lines' => 'required|array|min:1|max:'.(FinancePolicy::MAX_LINES - 1),
            'lines.*.acc_no' => 'required|integer|min:1',
            'lines.*.amount' => 'required|numeric|gt:0',
            'lines.*.sub_details' => 'nullable|string|max:200',
            'lines.*.cust_no' => 'nullable|integer|min:1',
            'cheq_no' => ($bank ? 'required' : 'nullable').'|string|max:20',
            'bank_name' => 'nullable|string|max:100',
            'cheq_date' => ['nullable', 'date_format:Y-m-d'],
            'cheq_type' => 'nullable|integer|in:'.implode(',', array_keys(FinancePolicy::CHEQUE_TYPES)),
            'name_in_cheq' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات السند غير صالحة / The voucher payload is invalid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $boxAcc = (int) $data['box_acc_no'];

        if (FinancePolicy::boxKind($boxAcc) !== $source) {
            return $this->fail($bank ? 'اختر حساب بنك (122…) / Pick a bank account' : 'اختر صندوقاً نقدياً (121…) / Pick a cash box', 422, ['box_acc_no' => 'kind']);
        }

        $lines = array_values((array) $data['lines']);
        $accounts = EntryLedger::accounts(array_merge([$boxAcc], array_map(static fn ($l): int => (int) $l['acc_no'], $lines)));

        foreach (array_merge([$boxAcc], array_map(static fn ($l): int => (int) $l['acc_no'], $lines)) as $i => $accNo) {
            if (! isset($accounts[$accNo])) {
                return $this->fail("الحساب {$accNo} غير موجود / Unknown account {$accNo}", 422, ['acc_no' => (string) $accNo]);
            }
            if ($accounts[$accNo]['stopped']) {
                return $this->fail("الحساب {$accNo} موقوف / Account {$accNo} is stopped", 422, ['acc_no' => (string) $accNo]);
            }
        }

        $headAcc = (int) $lines[0]['acc_no'];
        if (strlen((string) $headAcc) > $def['acc_digits']) {
            return $this->fail("رقم الحساب {$headAcc} أطول من سعة هذا النوع من السندات / The account number exceeds this voucher's column", 422, ['lines.0.acc_no' => 'digits']);
        }

        // عملة السند هي عملة الصندوق/البنك، والسعر لغير المحلية من CUR_PRICE
        // The voucher currency is the box's; a non-local one takes its rate from CUR_PRICE.
        $curNo = FinancePolicy::currencyOf($boxAcc);
        $price = 1.0;
        $seqPrice = null;

        if ($curNo !== FinancePolicy::LOCAL_CURRENCY) {
            $rate = isset($data['seq_price']) ? EntryLedger::priceOf((int) $data['seq_price']) : EntryLedger::latestPrice($curNo);

            if ($rate === null || (int) $rate['cur_no'] !== $curNo || $rate['price'] <= 0) {
                return $this->fail('لا يوجد سعر صرف صالح لعملة السند / No valid exchange rate for the voucher currency', 422, ['seq_price' => 'missing']);
            }

            $price = (float) $rate['price'];
            $seqPrice = (int) $rate['seq'];
        }

        $details = trim((string) $data['details']);
        $total = 0.0;
        $counter = [];

        foreach ($lines as $i => $line) {
            $accNo = (int) $line['acc_no'];
            $amount = round((float) $line['amount'], 2);
            $lineCur = FinancePolicy::currencyOf($accNo);

            if ($lineCur === $curNo) {
                $entryAmount = $amount;
                $lineSeq = $seqPrice;
            } elseif ($lineCur === FinancePolicy::LOCAL_CURRENCY) {
                // حساب محلي في سند أجنبي: يُقيَّد بالمعادل المحلي بلا سعر (كما في البيانات الحية)
                // A local account on a foreign voucher posts the local equivalent, rate-less.
                $entryAmount = round($amount * $price, 2);
                $lineSeq = null;
            } else {
                return $this->fail('عملة الحساب في السطر '.($i + 1).' تختلف عن عملة السند / Line currency differs from the voucher currency', 422, ["lines.{$i}.acc_no" => 'currency']);
            }

            $total += $amount;
            $sub = trim((string) ($line['sub_details'] ?? ''));
            $counter[] = [
                'acc_no' => $accNo,
                'amount' => $entryAmount,
                'seq_price' => $lineSeq,
                'sub_details' => $sub !== '' ? $sub : $details,
                'cust_no' => isset($line['cust_no']) ? (int) $line['cust_no'] : null,
            ];
        }

        $total = round($total, 2);
        $boxLine = ['acc_no' => $boxAcc, 'amount' => $total, 'seq_price' => $seqPrice, 'sub_details' => $details, 'cust_no' => null];

        // القبض: الصندوق مدين أولاً ثم المقابل دائن. الصرف: المقابل مدين أولاً ثم الصندوق دائن.
        // Receipt: box debit first, counters credited. Payment: counters debited, box credited.
        $entryLines = $direction === 'receipt'
            ? array_merge([$boxLine], array_map(static fn (array $l): array => array_merge($l, ['amount' => -$l['amount']]), $counter))
            : array_merge($counter, [array_merge($boxLine, ['amount' => -$total])]);

        $custNo = $counter[0]['cust_no'];
        $accName = $accounts[$headAcc]['acc_name'];

        if ($custNo !== null) {
            $cust = DB::connection('oracle')->selectOne('SELECT CUST_NAME FROM ACCOUNTS.CUST WHERE CUST_NO = :c', ['c' => $custNo]);
            if ($cust !== null) {
                $accName = ArabicEncoding::normalizeStatementText(((array) $cust)['CUST_NAME'] ?? ((array) $cust)['cust_name'] ?? $accName);
            }
        }

        return [
            'date' => (string) $data['date'],
            'details' => $details,
            'total' => $total,
            'cur_no' => $curNo,
            'price' => $price,
            'seq_price' => $seqPrice,
            'box_acc_no' => $boxAcc,
            'box_name' => $accounts[$boxAcc]['acc_name'],
            'head_acc_no' => $headAcc,
            'acc_name' => $accName,
            'cust_no' => $custNo,
            'manual_no' => isset($data['manual_no']) ? (int) $data['manual_no'] : null,
            'tel_no' => isset($data['tel_no']) ? (int) $data['tel_no'] : null,
            'cheq_no' => trim((string) ($data['cheq_no'] ?? '')),
            'bank_name' => trim((string) ($data['bank_name'] ?? '')) ?: $accounts[$boxAcc]['acc_name'],
            'cheq_date' => $data['cheq_date'] ?? $data['date'],
            'cheq_type' => (int) ($data['cheq_type'] ?? 1),
            'name_in_cheq' => trim((string) ($data['name_in_cheq'] ?? '')) ?: $accName,
            'words' => ArabicTafkeet::forCurrency($total, $curNo),
            'entry_lines' => $entryLines,
        ];
    }

    /**
     * أعمدة صف السند (غير المفتاح) لكل جدول — التواريخ تُعلَّم بـ ['date' => …]
     * The voucher row's non-key columns per table — dates are tagged ['date' => …].
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function columns(string $direction, string $source, array $p, int $entryNo): array
    {
        $def = FinancePolicy::voucher($direction, $source);
        $arabic = static fn (string $text, int $limit): ?string => EntryLedger::arabic(EntryLedger::clip($text, $limit) ?: '-');

        $columns = [
            'ACC_NO' => $p['head_acc_no'],
            'ACC_NAME' => $arabic((string) $p['acc_name'], $def['name_len']),
            'AMOUNT' => $p['total'],
            $def['date'] => ['date' => $p['date']],
            'DETAILES' => $arabic((string) $p['details'], $def['details_len']),
            $def['words'] => $arabic((string) $p['words'], $def['words_len']),
            'ENTRY_NO' => $entryNo,
            'CUR_NO' => $p['cur_no'],
            'CUST_NO' => $p['cust_no'],
        ];

        return match ("{$direction}.{$source}") {
            'receipt.cash' => $columns + [
                'MANUAL_C_NO' => $p['manual_no'],
                'TEL_NO' => $p['tel_no'],
                'CUR_PRICE' => $p['price'],
                'ACC_NO1' => $p['box_acc_no'],
            ],
            'payment.cash' => $columns + ['MANUAL_C_NO' => $p['manual_no']],
            'receipt.bank' => $columns + [
                'CHEQ_NO' => EntryLedger::clip($p['cheq_no'], 20) ?: '0',
                'BANK_NAME' => $arabic((string) $p['bank_name'], 100),
                'CHEQ_DATE' => ['date' => $p['cheq_date']],
                'CHEQ_TYPE' => $p['cheq_type'],
                'TRANSFER_ACC_NO' => $p['box_acc_no'],
                'EQUIVALENT_AMOUNT' => $p['total'],
                'CUR_PRICE_TRANSFER' => $p['price'],
                'SEQ_PRICE_TRANSFER' => $p['seq_price'],
            ],
            default => $columns + [
                'CHEQ_NO' => EntryLedger::clip($p['cheq_no'], 20) ?: '0',
                'BANK_NAME' => $arabic((string) $p['bank_name'], 100),
                'CHEQ_DATE' => ['date' => $p['cheq_date']],
                'CHEQ_TYPE' => $p['cheq_type'],
                'NAME_IN_CHEQ' => $arabic((string) $p['name_in_cheq'], 100),
            ],
        };
    }

    /** @param  array<string, mixed>  $columns */
    private function insertVoucher(array $def, string $source, int $no, int $year, array $columns): void
    {
        $names = [$def['no'], 'YEAR', 'CREATED_ON'];
        $values = [':v_no', ':v_year', 'SYSDATE'];
        $bindings = ['v_no' => $no, 'v_year' => $year];

        if ($source === 'cash') {
            $names[] = 'CREATED_TIME';
            $values[] = "TO_CHAR(SYSDATE,'HH24:MI')";
        }

        foreach ($columns as $column => $value) {
            $names[] = $column;
            $key = 'c_'.strtolower($column);
            $values[] = is_array($value) ? "TO_DATE(:{$key},'YYYY-MM-DD')" : ":{$key}";
            $bindings[$key] = is_array($value) ? $value['date'] : $value;
        }

        DB::connection('oracle')->insert(
            "INSERT INTO {$def['table']} (".implode(', ', $names).') VALUES ('.implode(', ', $values).')',
            $bindings
        );
    }

    /** @param  array<string, mixed>  $columns */
    private function updateVoucher(array $def, string $source, int $no, int $year, array $columns): void
    {
        $sets = ['LAST_UPDATED_ON = SYSDATE'];
        $bindings = ['v_no' => $no, 'v_year' => $year];

        if ($source === 'cash') {
            $sets[] = "UPDATE_TIME = TO_CHAR(SYSDATE,'HH24:MI')";
        }

        foreach ($columns as $column => $value) {
            $key = 'c_'.strtolower($column);
            $sets[] = is_array($value) ? "{$column} = TO_DATE(:{$key},'YYYY-MM-DD')" : "{$column} = :{$key}";
            $bindings[$key] = is_array($value) ? $value['date'] : $value;
        }

        DB::connection('oracle')->update(
            "UPDATE {$def['table']} SET ".implode(', ', $sets)." WHERE {$def['no']} = :v_no AND YEAR = :v_year",
            $bindings
        );
    }

    // ── helpers ──────────────────────────────────────────────

    /** الاتجاه من تعريف المسار / the direction from the route definition */
    private function direction(Request $request): string
    {
        return (string) $request->route('direction') === 'payment' ? 'payment' : 'receipt';
    }

    /** وحدة الصلاحيات للاتجاه / the permission module of the direction */
    private function module(string $direction): string
    {
        return $direction === 'payment' ? 'payments' : 'receipts';
    }

    /** اسم السند للعرض / a display label */
    private function label(string $direction, string $source): string
    {
        return ($direction === 'payment' ? 'سند صرف' : 'سند قبض').($source === 'bank' ? ' بنكي' : ' نقدي');
    }
}
