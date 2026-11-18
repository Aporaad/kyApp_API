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
 * متحكم القيود المؤقتة — ENTRY_MAIN2 (الرأس) + ENTRY_MAIN3 (السطور)
 * Temporary entries controller — ENTRY_MAIN2 (header) + ENTRY_MAIN3 (lines).
 *
 * ── المؤقت لا يمس الأرصدة إطلاقاً: لا يدخل ENTRY_SUB حتى يُرحَّل. الترحيل ينقله بنفس رقمه إلى
 *    ENTRY_MAIN (النوع 1) و ENTRY_SUB في معاملة واحدة ثم يحذفه من الجدولين المؤقتين.
 * ── A temporary entry never touches balances: it stays out of ENTRY_SUB until posted. Posting
 *    moves it, with the same number, into ENTRY_MAIN (type 1) + ENTRY_SUB in one transaction and
 *    then removes it from the temporary pair.
 */
class TempEntryController extends FinanceBaseController
{
    private const MODULE = 'journal_temp';

    private const ENTITY = 'temp_entry';

    /** قائمة القيود المؤقتة / the temporary list */
    public function index(Request $request): JsonResponse
    {
        [$page, $limit, $offset] = $this->paging($request);

        try {
            [$where, $bindings] = $this->filters($request);

            $countRow = (array) DB::connection('oracle')->selectOne(
                "SELECT COUNT(*) AS C FROM ACCOUNTS.ENTRY_MAIN2 m WHERE {$where}",
                $bindings
            );
            $total = (int) (reset($countRow) ?: 0);

            $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $order = match ((string) ($request->input('sort') ?? 'date')) {
                'no' => "m.ENTRY_NO {$dir}",
                'amount' => "NVL(a.AMT, 0) {$dir}, m.ENTRY_NO DESC",
                default => "m.ENTRY_DATE {$dir}, m.ENTRY_NO {$dir}",
            };

            $rows = DB::connection('oracle')->select(
                "SELECT * FROM (
                    SELECT q.*, ROWNUM AS RN FROM (
                        SELECT m.ENTRY_NO, RTRIM(m.DETAILS) AS DETAILS, TO_CHAR(m.ENTRY_DATE,'YYYY-MM-DD') AS ENTRY_DATE_ISO,
                               m.PERIODIC_ENTRY, NVL(a.AMT, 0) AS AMT, NVL(a.N, 0) AS N
                          FROM ACCOUNTS.ENTRY_MAIN2 m
                          LEFT JOIN (SELECT ENTRY_NO, SUM(NVL(ENTRY_MNT1, 0)) AS AMT, COUNT(*) AS N
                                       FROM ACCOUNTS.ENTRY_MAIN3 GROUP BY ENTRY_NO) a ON a.ENTRY_NO = m.ENTRY_NO
                         WHERE {$where}
                         ORDER BY {$order}
                    ) q WHERE ROWNUM <= ?
                 ) WHERE RN > ?",
                array_merge($bindings, [$offset + $limit, $offset])
            );

            $items = array_map(fn ($r): array => $this->summary((array) $r), $rows);

            return $this->ok('تم جلب القيود المؤقتة / Temporary entries retrieved', $items, 200, $this->meta($total, $page, $limit));
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب القيود المؤقتة / Failed to retrieve temporary entries', $e);
        }
    }

    /** قيد مؤقت واحد / one temporary entry */
    public function show(Request $request, int $entryNo): JsonResponse
    {
        try {
            $header = EntryLedger::tempHeader($entryNo);

            if ($header === null) {
                return $this->fail('القيد المؤقت غير موجود / Temporary entry not found', 404);
            }

            $lines = EntryLedger::readTempLines($entryNo);
            $totals = EntryLedger::totals($lines);
            $summary = $this->summary(array_merge($header, ['AMT' => $totals['debit'], 'N' => count($lines)]));

            $this->logActivity($request, self::MODULE, 'viewed', self::ENTITY, (string) $entryNo, 'عرض قيد مؤقت');

            return $this->ok('تم جلب القيد المؤقت / Temporary entry retrieved', array_merge($summary, [
                'lines' => $lines,
                'totals' => $totals,
                'amount_words' => ArabicTafkeet::forCurrency($totals['debit'], FinancePolicy::LOCAL_CURRENCY),
                'audit' => ['created_by_name' => null, 'created_on' => null, 'updated_by_name' => null, 'updated_on' => null],
            ]));
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب القيد المؤقت / Failed to retrieve the temporary entry', $e);
        }
    }

    /** إنشاء قيد مؤقت / create a temporary entry */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $entryNo = $this->withNumberRetry(fn (): int => DB::connection('oracle')->transaction(function () use ($payload): int {
                $entryNo = FinancePolicy::nextJournalNo((int) substr($payload['date'], 0, 4));
                EntryLedger::insertTemp($entryNo, $payload['details'], $payload['date'], $payload['periodic'], $payload['lines']);

                return $entryNo;
            }));

            $this->logActivity($request, self::MODULE, 'created', self::ENTITY, (string) $entryNo, 'إنشاء قيد مؤقت '.$entryNo);

            return $this->ok('تم حفظ القيد المؤقت / Temporary entry saved', ['entry_no' => $entryNo], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حفظ القيد المؤقت / Failed to save the temporary entry', $e);
        }
    }

    /** تعديل قيد مؤقت / update a temporary entry */
    public function update(Request $request, int $entryNo): JsonResponse
    {
        if (EntryLedger::tempHeader($entryNo) === null) {
            return $this->fail('القيد المؤقت غير موجود / Temporary entry not found', 404);
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            DB::connection('oracle')->transaction(function () use ($payload, $entryNo): void {
                EntryLedger::updateTempHeader($entryNo, $payload['details'], $payload['date'], $payload['periodic']);
                EntryLedger::deleteTempLines($entryNo);
                EntryLedger::insertTempLines($entryNo, $payload['lines']);
            });

            $this->logActivity($request, self::MODULE, 'updated', self::ENTITY, (string) $entryNo, 'تعديل قيد مؤقت '.$entryNo);

            return $this->ok('تم تعديل القيد المؤقت / Temporary entry updated', ['entry_no' => $entryNo]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل القيد المؤقت / Failed to update the temporary entry', $e);
        }
    }

    /** حذف قيد مؤقت / delete a temporary entry */
    public function destroy(Request $request, int $entryNo): JsonResponse
    {
        $header = EntryLedger::tempHeader($entryNo);

        if ($header === null) {
            return $this->fail('القيد المؤقت غير موجود / Temporary entry not found', 404);
        }

        try {
            DB::connection('oracle')->transaction(fn () => EntryLedger::deleteTemp($entryNo));

            $details = ArabicEncoding::normalizeStatementText($header['DETAILS'] ?? '');
            $this->logActivity($request, self::MODULE, 'deleted', self::ENTITY, (string) $entryNo,
                "حذف قيد مؤقت {$entryNo} بتاريخ {$header['ENTRY_DATE_ISO']} — {$details}");

            return $this->ok('تم حذف القيد المؤقت / Temporary entry deleted', ['entry_no' => $entryNo]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف القيد المؤقت / Failed to delete the temporary entry', $e);
        }
    }

    /**
     * ترحيل قيد مؤقت: إعادة التحقق من التوازن والحسابات، ثم النقل بنفس الرقم في معاملة واحدة
     * Post a temporary entry: re-validate balance and accounts, then move it under the same
     * number in one transaction.
     */
    public function post(Request $request, int $entryNo): JsonResponse
    {
        $header = EntryLedger::tempHeader($entryNo);

        if ($header === null) {
            return $this->fail('القيد المؤقت غير موجود / Temporary entry not found', 404);
        }

        $input = array_map(static fn (array $l): array => [
            'acc_no' => $l['acc_no'],
            'debit' => $l['debit'],
            'credit' => $l['credit'],
            'seq_price' => $l['seq_price'],
            'sub_details' => $l['sub_details'],
            'cust_no' => $l['cust_no'],
        ], EntryLedger::readTempLines($entryNo));

        $guard = EntryLedger::normalizeLines($input);

        if (! $guard['ok']) {
            $this->logActivity($request, self::MODULE, 'post_failed', self::ENTITY, (string) $entryNo, 'فشل ترحيل قيد مؤقت: '.$guard['message'], true);

            return $this->fail($guard['message'], 422, $guard['errors']);
        }

        if (EntryLedger::header($entryNo) !== null) {
            return $this->fail('يوجد قيد مرحل بنفس الرقم / A posted entry already uses this number', 409);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($entryNo, $header, $guard, $request): void {
                EntryLedger::insertHeader($entryNo, (string) ($header['DETAILS'] ?? ''), (string) $header['ENTRY_DATE_ISO'], FinancePolicy::TYPE_JOURNAL);
                EntryLedger::insertLines($entryNo, $guard['lines']);
                EntryAudit::created($entryNo, $request);
                EntryLedger::deleteTemp($entryNo);
            });

            $this->logActivity($request, self::MODULE, 'posted', self::ENTITY, (string) $entryNo, 'ترحيل قيد مؤقت '.$entryNo);

            return $this->ok('تم ترحيل القيد / Entry posted', ['entry_no' => $entryNo]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في ترحيل القيد / Failed to post the entry', $e);
        }
    }

    /** تسجيل طباعة أو تصدير قيد مؤقت / log a print or export */
    public function activity(Request $request, int $entryNo): JsonResponse
    {
        $action = (string) $request->input('action');

        if (! in_array($action, ['print', 'export'], true)) {
            return $this->fail('قيمة action غير مدعومة / Unsupported action value');
        }

        if (! $this->can($request, "journal_temp.{$action}")) {
            return $this->forbidden($action === 'print' ? 'طباعة القيود المؤقتة' : 'تصدير القيود المؤقتة');
        }

        $this->logActivity($request, self::MODULE, $action === 'print' ? 'printed' : 'exported', self::ENTITY, (string) $entryNo,
            ($action === 'print' ? 'طباعة قيد مؤقت ' : 'تصدير قيد مؤقت ').$entryNo);

        return $this->ok('تم التسجيل / Logged');
    }

    // ── helpers ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function payload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'details' => 'required|string|max:200',
            'periodic' => 'nullable|boolean',
            'lines' => 'required|array|min:2|max:'.FinancePolicy::MAX_LINES,
            'lines.*.acc_no' => 'required|integer|min:1',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.sub_details' => 'nullable|string|max:200',
            'lines.*.cust_no' => 'nullable|integer|min:1',
            'lines.*.seq_price' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات القيد غير صالحة / The entry payload is invalid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $guard = EntryLedger::normalizeLines((array) $data['lines']);

        if (! $guard['ok']) {
            return $this->fail($guard['message'], 422, $guard['errors']);
        }

        return [
            'date' => (string) $data['date'],
            'details' => trim((string) $data['details']),
            'periodic' => (bool) ($data['periodic'] ?? false),
            'lines' => $guard['lines'],
        ];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function filters(Request $request): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (($from = $this->dateInput($request, 'from_date')) !== null) {
            $where[] = "m.ENTRY_DATE >= TO_DATE(?, 'YYYY-MM-DD')";
            $bindings[] = $from;
        }

        if (($to = $this->dateInput($request, 'to_date')) !== null) {
            $where[] = "m.ENTRY_DATE < TO_DATE(?, 'YYYY-MM-DD') + 1";
            $bindings[] = $to;
        }

        if (($acc = (int) $request->input('acc_no')) > 0) {
            $where[] = 'm.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN3 WHERE ACC_NO = ?)';
            $bindings[] = $acc;
        }

        if (($search = $this->text($request, 'search')) !== '') {
            if (ctype_digit($search)) {
                $where[] = 'm.ENTRY_NO = ?';
                $bindings[] = (int) $search;
            } else {
                [$like, $likeBindings] = $this->likeBoth('m.DETAILS', $search);
                $where[] = $like;
                array_push($bindings, ...$likeBindings);
            }
        }

        $min = $request->input('min_amount');
        $max = $request->input('max_amount');
        if ((is_numeric($min) && (float) $min > 0) || is_numeric($max)) {
            $where[] = 'm.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN3 GROUP BY ENTRY_NO HAVING SUM(NVL(ENTRY_MNT1, 0)) BETWEEN ? AND ?)';
            array_push($bindings, is_numeric($min) ? (float) $min : 0, is_numeric($max) ? (float) $max : 1e15);
        }

        if ((string) $request->input('periodic') === '1') {
            $where[] = 'NVL(m.PERIODIC_ENTRY, 0) = 1';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /** @param  array<string, mixed>  $r */
    private function summary(array $r): array
    {
        return [
            'entry_no' => $this->int($r, 'ENTRY_NO'),
            'status' => 'temp',
            'date' => $this->str($r, 'ENTRY_DATE_ISO'),
            'details' => $this->str($r, 'DETAILS'),
            'type_no' => FinancePolicy::TYPE_JOURNAL,
            'type_name' => 'قيد يومية مؤقت',
            'source_name' => 'حسابات',
            'total' => round((float) ($this->num($r, 'AMT') ?? 0), 2),
            'lines_count' => (int) ($this->int($r, 'N') ?? 0),
            'is_closing' => false,
            'periodic' => (int) ($this->int($r, 'PERIODIC_ENTRY') ?? 0) === 1,
            'editable' => true,
            'read_only_reason' => null,
            'voucher' => null,
            'created_by_name' => null,
        ];
    }
}
