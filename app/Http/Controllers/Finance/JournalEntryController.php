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
 * متحكم القيود المرحلة — ENTRY_MAIN + ENTRY_SUB
 * Posted journal entries controller — ENTRY_MAIN + ENTRY_SUB.
 *
 * ── القائمة تعرض كل الأنواع (يومية، سندات، تفصيل، مبيعات، مخازن…)، لكن الكتابة محصورة في
 *    النوعين اليدويين 1 (يومية) و 100 (إقفال). قيد السند يُعدَّل من سنده، وقيد نظام آخر للعرض.
 * ── The list shows every type, but writes are limited to the two manual types 1 (journal)
 *    and 100 (closing). A voucher's entry is edited through its voucher; another system's
 *    entry is view-only.
 */
class JournalEntryController extends FinanceBaseController
{
    private const MODULE = 'journal';

    private const ENTITY = 'entry';

    /** قائمة القيود المرحلة بالبحث والفلاتر والفرز والتصفح / the posted list */
    public function index(Request $request): JsonResponse
    {
        [$page, $limit, $offset] = $this->paging($request);

        try {
            [$where, $bindings] = $this->filters($request);

            $countRow = (array) DB::connection('oracle')->selectOne(
                "SELECT COUNT(*) AS C FROM ACCOUNTS.ENTRY_MAIN m WHERE {$where}",
                $bindings
            );
            $total = (int) (reset($countRow) ?: 0);

            [$order, $join] = $this->sort($request);

            $rows = DB::connection('oracle')->select(
                "SELECT * FROM (
                    SELECT q.*, ROWNUM AS RN FROM (
                        SELECT m.ENTRY_NO, m.DETAILS, TO_CHAR(m.ENTRY_DATE,'YYYY-MM-DD') AS ENTRY_DATE_ISO,
                               m.ENTRY_TYPE_NO, t.ENTRY_TYPE_NAME, t.SOURCE_NAME
                          FROM ACCOUNTS.ENTRY_MAIN m
                          LEFT JOIN ACCOUNTS.ENTRY_TYPE t ON t.ENTRY_TYPE_NO = m.ENTRY_TYPE_NO
                          {$join}
                         WHERE {$where}
                         ORDER BY {$order}
                    ) q WHERE ROWNUM <= ?
                 ) WHERE RN > ?",
                array_merge($bindings, [$offset + $limit, $offset])
            );

            return $this->ok(
                'تم جلب القيود المرحلة / Posted entries retrieved',
                $this->summaries(array_map(static fn ($r): array => (array) $r, $rows)),
                200,
                $this->meta($total, $page, $limit)
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب القيود / Failed to retrieve entries', $e);
        }
    }

    /** قيد واحد: الرأس + السطور + المجاميع + التفقيط + التدقيق / one posted entry */
    public function show(Request $request, int $entryNo): JsonResponse
    {
        try {
            $header = EntryLedger::header($entryNo);

            if ($header === null) {
                return $this->fail('القيد غير موجود / Entry not found', 404);
            }

            $summary = $this->summaries([$header])[0];
            $lines = EntryLedger::readLines($entryNo);
            $totals = EntryLedger::totals($lines);

            $payload = array_merge($summary, [
                'lines' => $lines,
                'totals' => $totals,
                'amount_words' => ArabicTafkeet::forCurrency($totals['debit'], FinancePolicy::LOCAL_CURRENCY),
                'audit' => EntryAudit::of($entryNo),
            ]);

            $this->logActivity($request, self::MODULE, 'viewed', self::ENTITY, (string) $entryNo, 'عرض قيد مرحل');

            return $this->ok('تم جلب القيد / Entry retrieved', $payload);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب القيد / Failed to retrieve the entry', $e);
        }
    }

    /**
     * إنشاء قيد مرحل مباشرة: يومية (النوع 1) أو إقفال (النوع 100) — بالترقيم والسطور والتدقيق
     * Create a posted entry directly: journal (type 1) or closing (type 100).
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $closing = $payload['kind'] === 'closing';

        if ($closing && ! $this->can($request, 'journal.closing_create')) {
            return $this->forbidden('إضافة وتعديل قيد إقفال');
        }

        if (! $closing && ! $this->can($request, 'journal.create')) {
            return $this->forbidden('إضافة قيد يومية مرحل');
        }

        $typeNo = $closing ? FinancePolicy::TYPE_CLOSING : FinancePolicy::TYPE_JOURNAL;

        try {
            $entryNo = $this->withNumberRetry(fn (): int => DB::connection('oracle')->transaction(function () use ($payload, $typeNo, $request): int {
                $entryNo = FinancePolicy::nextJournalNo((int) substr($payload['date'], 0, 4));

                EntryLedger::insertHeader($entryNo, $payload['details'], $payload['date'], $typeNo);
                EntryLedger::insertLines($entryNo, $payload['lines']);
                EntryAudit::created($entryNo, $request);

                return $entryNo;
            }));

            $this->logActivity($request, self::MODULE, $closing ? 'closing_created' : 'created', self::ENTITY, (string) $entryNo,
                ($closing ? 'إنشاء قيد إقفال' : 'إنشاء قيد يومية مرحل').' '.$entryNo);

            return $this->ok('تم حفظ القيد وترحيله / Entry posted', ['entry_no' => $entryNo, 'type_no' => $typeNo], 201);
        } catch (\Throwable $e) {
            $this->logActivity($request, self::MODULE, 'create_failed', self::ENTITY, '-', 'فشل إنشاء قيد مرحل', true);

            return $this->errorResponse('خطأ في حفظ القيد / Failed to save the entry', $e);
        }
    }

    /** تعديل قيد يدوي مرحل — يحتفظ برقمه ويستبدل سطوره / update a manual posted entry */
    public function update(Request $request, int $entryNo): JsonResponse
    {
        $guard = $this->editableGuard($request, $entryNo);

        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (substr($payload['date'], 0, 4) !== substr((string) $guard['ENTRY_DATE_ISO'], 0, 4)) {
            return $this->fail('لا يمكن نقل القيد إلى سنة مالية أخرى / An entry cannot move to another fiscal year', 422, ['date' => 'year']);
        }

        try {
            DB::connection('oracle')->transaction(function () use ($payload, $entryNo, $request): void {
                EntryLedger::updateHeader($entryNo, $payload['details'], $payload['date']);
                EntryLedger::deleteLines($entryNo);
                EntryLedger::insertLines($entryNo, $payload['lines']);
                EntryAudit::updated($entryNo, $request);
            });

            $this->logActivity($request, self::MODULE, 'updated', self::ENTITY, (string) $entryNo, 'تعديل قيد مرحل '.$entryNo);

            return $this->ok('تم تعديل القيد / Entry updated', ['entry_no' => $entryNo]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل القيد / Failed to update the entry', $e);
        }
    }

    /** حذف قيد يدوي مرحل مع لقطة في سجل النشاط / delete a manual posted entry */
    public function destroy(Request $request, int $entryNo): JsonResponse
    {
        $guard = $this->editableGuard($request, $entryNo);

        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        try {
            $total = EntryLedger::totals(EntryLedger::readLines($entryNo))['debit'];

            DB::connection('oracle')->transaction(fn () => EntryLedger::deleteEntry($entryNo));

            $details = ArabicEncoding::normalizeStatementText($guard['DETAILS'] ?? '');
            $this->logActivity($request, self::MODULE, 'deleted', self::ENTITY, (string) $entryNo,
                "حذف قيد مرحل {$entryNo} بتاريخ {$guard['ENTRY_DATE_ISO']} بمبلغ {$total} — {$details}");

            return $this->ok('تم حذف القيد / Entry deleted', ['entry_no' => $entryNo]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف القيد / Failed to delete the entry', $e);
        }
    }

    /** تسجيل طباعة أو تصدير قيد / log a print or export of one entry */
    public function activity(Request $request, int $entryNo): JsonResponse
    {
        $action = (string) $request->input('action');

        if (! in_array($action, ['print', 'export'], true)) {
            return $this->fail('قيمة action غير مدعومة / Unsupported action value');
        }

        if (! $this->can($request, "journal.{$action}")) {
            return $this->forbidden($action === 'print' ? 'طباعة القيود المرحلة' : 'تصدير القيود المرحلة');
        }

        $this->logActivity($request, self::MODULE, $action === 'print' ? 'printed' : 'exported', self::ENTITY, (string) $entryNo,
            ($action === 'print' ? 'طباعة قيد مرحل ' : 'تصدير قيد مرحل ').$entryNo);

        return $this->ok('تم التسجيل / Logged');
    }

    // ── helpers ──────────────────────────────────────────────

    /**
     * حارس الكتابة: القيد موجود، نوعه يدوي، غير مرتبط بسند، والصلاحية الخاصة بالإقفال
     * Write guard: the entry exists, is manual, has no voucher, and closing needs its own grant.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function editableGuard(Request $request, int $entryNo): array|JsonResponse
    {
        $header = EntryLedger::header($entryNo);

        if ($header === null) {
            return $this->fail('القيد غير موجود / Entry not found', 404);
        }

        $typeNo = (int) ($header['ENTRY_TYPE_NO'] ?? 0);

        if (($this->voucherLinks([$entryNo])[$entryNo] ?? null) !== null) {
            return $this->fail('هذا القيد تابع لسند — يُعدَّل أو يُحذف من السند نفسه / This entry belongs to a voucher', 422, ['entry' => 'voucher']);
        }

        if (! FinancePolicy::isEditableType($typeNo)) {
            $source = ArabicEncoding::normalizeStatementText($header['SOURCE_NAME'] ?? '');

            return $this->fail("قيد صادر من نظام آخر ({$source}) — للعرض فقط / This entry comes from another system and is view-only", 422, ['entry' => 'source']);
        }

        if ($typeNo === FinancePolicy::TYPE_CLOSING && ! $this->can($request, 'journal.closing_create')) {
            return $this->forbidden('إضافة وتعديل قيد إقفال');
        }

        return $header;
    }

    /**
     * التحقق من حمولة القيد وتجهيز سطوره أو رد 422
     * Validate the entry payload and prepare its lines, or a 422.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function payload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'details' => 'required|string|max:250',
            'kind' => 'nullable|in:journal,closing',
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
            'kind' => (string) ($data['kind'] ?? 'journal'),
            'lines' => $guard['lines'],
        ];
    }

    /**
     * الفلاتر: نوع/مصدر/مدة/حساب/بحث/مبلغ/منشئ/قابلية تعديل — شروط مربوطة فقط
     * Filters as bound predicates only.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function filters(Request $request): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        $types = array_values(array_filter(array_map('intval', (array) ($request->input('types') ?? [])), static fn (int $t): bool => $t > 0));
        if ($types !== []) {
            $where[] = 'm.ENTRY_TYPE_NO IN ('.$this->marks($types).')';
            array_push($bindings, ...$types);
        }

        if (($source = $this->text($request, 'source')) !== '') {
            $where[] = 'm.ENTRY_TYPE_NO IN (SELECT ENTRY_TYPE_NO FROM ACCOUNTS.ENTRY_TYPE WHERE SOURCE_NAME = ? OR SOURCE_NAME = ?)';
            array_push($bindings, (string) ArabicEncoding::toOracleSearchChars($source), $source);
        }

        if (($from = $this->dateInput($request, 'from_date')) !== null) {
            $where[] = "m.ENTRY_DATE >= TO_DATE(?, 'YYYY-MM-DD')";
            $bindings[] = $from;
        }

        if (($to = $this->dateInput($request, 'to_date')) !== null) {
            $where[] = "m.ENTRY_DATE < TO_DATE(?, 'YYYY-MM-DD') + 1";
            $bindings[] = $to;
        }

        if (($acc = (int) $request->input('acc_no')) > 0) {
            $where[] = 'm.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_SUB WHERE ACC_NO = ?)';
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
            $where[] = 'm.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_SUB GROUP BY ENTRY_NO
                        HAVING SUM(CASE WHEN ENTRY_MNT > 0 THEN ENTRY_MNT ELSE 0 END) BETWEEN ? AND ?)';
            array_push($bindings, is_numeric($min) ? (float) $min : 0, is_numeric($max) ? (float) $max : 1e15);
        }

        if ((string) $request->input('mine') === '1' && ($me = $request->user()?->getAuthIdentifier()) !== null) {
            $where[] = 'm.ENTRY_NO IN (SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_MAIN_PRIVILAGE WHERE CREATED_BY = ? OR LAST_UPDATED_BY = ?)';
            array_push($bindings, (int) $me, (int) $me);
        }

        if ((string) $request->input('editable') === '1') {
            $where[] = 'm.ENTRY_TYPE_NO IN ('.implode(',', FinancePolicy::EDITABLE_TYPES).')';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * الفرز بقائمة بيضاء — المبلغ يحتاج تجميع السطور فينضم تجميعها عند طلبه فقط
     * A whitelisted sort — the amount needs a line aggregate, joined only when requested.
     *
     * @return array{0: string, 1: string}
     */
    private function sort(Request $request): array
    {
        $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        return match ((string) ($request->input('sort') ?? 'date')) {
            'no' => ["m.ENTRY_NO {$dir}", ''],
            'amount' => [
                "NVL(a.AMT, 0) {$dir}, m.ENTRY_NO DESC",
                'LEFT JOIN (SELECT ENTRY_NO, SUM(CASE WHEN ENTRY_MNT > 0 THEN ENTRY_MNT ELSE 0 END) AS AMT
                              FROM ACCOUNTS.ENTRY_SUB GROUP BY ENTRY_NO) a ON a.ENTRY_NO = m.ENTRY_NO',
            ],
            default => ["m.ENTRY_DATE {$dir}, m.ENTRY_NO {$dir}", ''],
        };
    }

    /**
     * ملخصات صفوف الرؤوس: المجموع وعدد السطور والسند المرتبط وقابلية التعديل والمنشئ
     * Header-row summaries: total, line count, linked voucher, editability and creator.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function summaries(array $rows): array
    {
        $entryNos = array_map(fn (array $r): int => (int) $this->int($r, 'ENTRY_NO'), $rows);
        $stats = $this->entryStats($entryNos);
        $links = $this->voucherLinks($entryNos);
        $creators = [];

        if ($entryNos !== []) {
            foreach (DB::connection('oracle')->select(
                'SELECT ENTRY_NO, CREATED_BY_NAME FROM ACCOUNTS.ENTRY_MAIN_PRIVILAGE WHERE ENTRY_NO IN ('.$this->marks($entryNos).')',
                $entryNos
            ) as $row) {
                $creators[(int) $this->int((array) $row, 'ENTRY_NO')] = $this->str((array) $row, 'CREATED_BY_NAME');
            }
        }

        return array_map(function (array $r) use ($stats, $links, $creators): array {
            $no = (int) $this->int($r, 'ENTRY_NO');
            $typeNo = $this->int($r, 'ENTRY_TYPE_NO');
            $voucher = $links[$no] ?? null;
            $editable = $voucher === null && FinancePolicy::isEditableType($typeNo);

            return [
                'entry_no' => $no,
                'status' => 'posted',
                'date' => $this->str($r, 'ENTRY_DATE_ISO'),
                'details' => $this->str($r, 'DETAILS'),
                'type_no' => $typeNo,
                'type_name' => $this->str($r, 'ENTRY_TYPE_NAME'),
                'source_name' => $this->str($r, 'SOURCE_NAME'),
                'total' => $stats[$no]['debit'] ?? 0.0,
                'lines_count' => $stats[$no]['lines'] ?? 0,
                'is_closing' => $typeNo === FinancePolicy::TYPE_CLOSING,
                'editable' => $editable,
                'read_only_reason' => $editable ? null : ($voucher !== null ? 'voucher' : 'source'),
                'voucher' => $voucher,
                'created_by_name' => $creators[$no] ?? null,
            ];
        }, $rows);
    }
}
