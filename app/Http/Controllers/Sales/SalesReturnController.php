<?php

namespace App\Http\Controllers\Sales;

use App\Support\ArabicEncoding;
use App\Support\SalesDocumentWriter;
use App\Support\SalesPolicy;
use App\Support\SalesPosting;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم مردودات المبيعات — قراءة فقط من ACCOUNTS.RETURN_SALES + تفاصيلها
 * Sales returns controller — read-only over ACCOUNTS.RETURN_SALES and RETURN_SALES_DETAILES.
 *
 * المردود مستند مستقل لهويته المركّبة (RETURN_NO, YEAR)، ويرتبط بفاتورته الأصلية عبر
 * (BILL_NO, BILL_YEAR). لا حالة سداد هنا: الجدول لا يحمل AMOUNT_PAID، والقيمة هي صافي المردود.
 * A return is its own document keyed by (RETURN_NO, YEAR), linked to its origin bill through
 * (BILL_NO, BILL_YEAR). There is no payment status here — the table carries no AMOUNT_PAID.
 */
class SalesReturnController extends SalesBaseController
{
    private const TABLE = 'ACCOUNTS.RETURN_SALES';

    private const TABLE_DETAILS = 'ACCOUNTS.RETURN_SALES_DETAILES';

    private const TABLE_SANFS = 'ACCOUNTS.SANFS';

    private const TABLE_CURRENCY = 'ACCOUNTS.CURRENCY';

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $query = DB::connection('oracle')->table(self::TABLE);

            $search = trim((string) ($request->input('search') ?? ''));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    if (is_numeric($search)) {
                        $n = (int) $search;
                        $q->where('RETURN_NO', $n)->orWhere('MANUAL_RETURN_NO', $n)->orWhere('BILL_NO', $n);
                    } else {
                        $oracle = ArabicEncoding::toOracleSearchChars($search);
                        $q->where('CUST_NAME', 'LIKE', '%'.$oracle.'%')
                            ->orWhere('CUST_NAME', 'LIKE', '%'.$search.'%');
                    }
                });
            }

            if (($from = $this->dateInput($request, 'from_date')) !== null) {
                $query->whereRaw('RET_DATE >= TO_DATE(?, \'YYYY-MM-DD\')', [$from]);
            }
            if (($to = $this->dateInput($request, 'to_date')) !== null) {
                $query->whereRaw('RET_DATE < TO_DATE(?, \'YYYY-MM-DD\') + 1', [$to]);
            }
            if (($year = $request->input('year')) !== null && $year !== '') {
                $query->where('YEAR', (int) $year);
            }
            if (($branch = $request->input('branch')) !== null && $branch !== '') {
                $query->where('BRANCH_NO', (int) $branch);
            }
            if (($billType = $request->input('bill_type')) !== null && $billType !== '') {
                $query->where('BILL_TYPE', (int) $billType);
            }
            if (($min = $request->input('min_amount')) !== null && $min !== '' && is_numeric($min)) {
                $query->where('NET', '>=', (float) $min);
            }
            if (($max = $request->input('max_amount')) !== null && $max !== '' && is_numeric($max)) {
                $query->where('NET', '<=', (float) $max);
            }

            $total = (clone $query)->count();

            $sort = $this->resolveSort($request);

            $rows = $query
                ->select([
                    'RETURN_NO', 'YEAR', 'MANUAL_RETURN_NO', 'BILL_NO', 'BILL_YEAR', 'BILL_TYPE',
                    'CUST_NO', 'CUST_NAME', 'RETURN_TOTAL', 'DISCOUNT', 'NET', 'STORE_NO',
                    'BRANCH_NO', 'CUR_NO',
                    DB::raw("TO_CHAR(RET_DATE,'YYYY-MM-DD') AS RET_DATE_ISO"),
                    // عدد سطور المردود لبطاقة القائمة — استعلام مرتبط محدود بالصفوف المعادة فقط.
                    // The return's line count for the list card — a correlated subquery over the
                    // fetched page only, never over the whole table.
                    DB::raw('(SELECT COUNT(*) FROM '.self::TABLE_DETAILS.' d '
                        .'WHERE d.RETURN_NO = RETURN_SALES.RETURN_NO '
                        .'AND d.YEAR = RETURN_SALES.YEAR) AS ITEMS_COUNT'),
                ])
                ->orderBy($sort['column'], $sort['dir'])
                ->orderByDesc('RETURN_NO')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $items = array_map(fn ($row): array => $this->toSummary((array) $row), $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مردودات المبيعات بنجاح / Sales returns retrieved',
                'data' => ArabicEncoding::cleanData($items),
                'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مردودات المبيعات / Failed to retrieve sales returns', $e);
        }
    }

    public function filterOptions(): JsonResponse
    {
        try {
            $years = array_values(array_filter(array_map(
                static fn ($r): ?int => isset($r->YEAR) ? (int) $r->YEAR : null,
                DB::connection('oracle')->table(self::TABLE)->distinct()->select('YEAR')->orderByDesc('YEAR')->get()->all()
            )));

            $branches = array_values(array_filter(array_map(
                static fn ($r): ?int => isset($r->BRANCH_NO) ? (int) $r->BRANCH_NO : null,
                DB::connection('oracle')->table(self::TABLE)->whereNotNull('BRANCH_NO')->distinct()->select('BRANCH_NO')->orderBy('BRANCH_NO')->get()->all()
            )));

            $billTypes = array_values(array_filter(array_map(
                static fn ($r): ?int => isset($r->BILL_TYPE) ? (int) $r->BILL_TYPE : null,
                DB::connection('oracle')->table(self::TABLE)->whereNotNull('BILL_TYPE')->distinct()->select('BILL_TYPE')->orderBy('BILL_TYPE')->get()->all()
            )));

            return response()->json([
                'success' => true,
                'message' => 'تم جلب خيارات الفلترة بنجاح / Filter options retrieved',
                'data' => ['years' => $years, 'branches' => $branches, 'bill_types' => $billTypes],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب خيارات الفلترة / Failed to retrieve filter options', $e);
        }
    }

    public function show(Request $request, int $returnNo, int $year): JsonResponse
    {
        try {
            $header = DB::connection('oracle')->table(self::TABLE)
                ->select([
                    'RETURN_NO', 'YEAR', 'MANUAL_RETURN_NO', 'BILL_NO', 'BILL_YEAR', 'BILL_TYPE',
                    'CUST_NO', 'CUST_NAME', 'RETURN_TOTAL', 'DISCOUNT', 'DIS_RATE', 'NET', 'STORE_NO',
                    'BRANCH_NO', 'CUR_NO', 'ACC_NO', 'DETAILES', 'CREATED_BY_NAME',
                    DB::raw("TO_CHAR(RET_DATE,'YYYY-MM-DD') AS RET_DATE_ISO"),
                ])
                ->where('RETURN_NO', $returnNo)
                ->where('YEAR', $year)
                ->first();

            if (! $header) {
                return response()->json(['success' => false, 'message' => 'مردود المبيعات غير موجود / Sales return not found'], 404);
            }

            $header = (array) $header;

            $currency = $this->str($header, 'CUR_NO') !== null
                ? DB::connection('oracle')->table(self::TABLE_CURRENCY)
                    ->select(['CUR_NAME'])->where('CUR_NO', (int) $this->num($header, 'CUR_NO'))->first()
                : null;

            $detailRows = DB::connection('oracle')->table(self::TABLE_DETAILS.' d')
                ->select([
                    'd.SEQ', 'd.SANF_NO', 'd.RET_QTY', 'd.CAP', 'd.PCS', 'd.SANF_PRICE',
                    'd.DISCOUNT_RATE', 'd.DISCOUNT1', 'd.BOUNS', 'd.TOTAL', 'd.CATEGORY', 'd.COLOR_NO',
                    DB::raw('(SELECT MAX(s.SANF_NAME) FROM '.self::TABLE_SANFS.' s WHERE s.SANF_NO = d.SANF_NO) AS SANF_NAME'),
                ])
                ->where('d.RETURN_NO', $returnNo)
                ->where('d.YEAR', $year)
                ->orderBy('d.SEQ')
                ->get();

            $details = array_map(fn ($row): array => $this->toDetail((array) $row), $detailRows->all());

            $payload = [
                'return_no' => $this->int($header, 'RETURN_NO'),
                'year' => $this->int($header, 'YEAR'),
                'manual_return_no' => $this->int($header, 'MANUAL_RETURN_NO'),
                'bill_no' => $this->int($header, 'BILL_NO'),
                'bill_year' => $this->int($header, 'BILL_YEAR'),
                'bill_type' => $this->int($header, 'BILL_TYPE'),
                'date' => $this->str($header, 'RET_DATE_ISO'),
                'cust_no' => $this->int($header, 'CUST_NO'),
                'cust_name' => $this->str($header, 'CUST_NAME'),
                'note' => $this->str($header, 'DETAILES'),
                'created_by_name' => $this->str($header, 'CREATED_BY_NAME'),
                'store_no' => $this->int($header, 'STORE_NO'),
                'branch_no' => $this->int($header, 'BRANCH_NO'),
                'cur_no' => $this->int($header, 'CUR_NO'),
                'cur_name' => $currency ? ($currency->CUR_NAME ?? $currency->cur_name ?? null) : null,
                'items_count' => count($details),
                'total' => $this->num($header, 'RETURN_TOTAL'),
                'discount' => $this->num($header, 'DISCOUNT'),
                'dis_rate' => $this->num($header, 'DIS_RATE'),
                'net' => $this->num($header, 'NET'),
                'details' => $details,
            ];

            $this->logActivity($request, 'sales.return_viewed', self::ENTITY_RETURN, "$returnNo/$year", 'عرض مردود مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مردود المبيعات بنجاح / Sales return retrieved',
                'data' => ArabicEncoding::cleanData($payload),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مردود المبيعات / Failed to retrieve sales return', $e);
        }
    }

    public function activity(Request $request, int $returnNo, int $year): JsonResponse
    {
        $action = (string) ($request->input('action') ?? '');
        $map = [
            'print' => ['sales.return_printed', 'طباعة مردود مبيعات'],
            'export' => ['sales.returns_exported', 'تصدير مردود مبيعات'],
        ];

        if (! isset($map[$action])) {
            return response()->json(['success' => false, 'message' => 'قيمة action غير مدعومة / Unsupported action value'], 422);
        }

        $this->logActivity($request, $map[$action][0], self::ENTITY_RETURN, "$returnNo/$year", $map[$action][1]);

        return response()->json(['success' => true, 'message' => 'تم التسجيل / Logged'], 200);
    }

    // ── الكتابة: إنشاء/تعديل/حذف (المرحلة ب) ──────────────────────────────────
    // ── Writes: create / update / delete (phase B) ────────────────────────────

    /**
     * مراجع نموذج المردود: الأنواع وحسابات المردودات والمخازن والعمولات
     * The return form's reference payload: bill types, returns accounts, stores, currencies.
     */
    public function reference(): JsonResponse
    {
        try {
            $stores = DB::connection('oracle')->select(
                'SELECT STORE_NO, STORE_NAME, INV_ACC_NO FROM ACCOUNTS.STORES ORDER BY STORE_NO'
            );

            $currencies = DB::connection('oracle')->select(
                'SELECT CUR_NO, CUR_NAME FROM ACCOUNTS.CURRENCY ORDER BY CUR_NO'
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مراجع المردود بنجاح / Return reference retrieved',
                'data' => ArabicEncoding::cleanData(array_merge(SalesPolicy::reference(), [
                    'stores' => array_map(static fn ($r): array => (array) $r, $stores),
                    'currencies' => array_map(static fn ($r): array => (array) $r, $currencies),
                ])),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مراجع المردود / Failed to retrieve the return reference', $e);
        }
    }

    /**
     * إنشاء مردود مبيعات — رقم + قيد عكسي (بالإيراد والتكلفة) + رأس + سطور
     * Create a sales return — number, reversing entry (revenue and cost), header, lines.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->writePayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(
                fn (): array => $this->createReturn($payload, $request)
            ));

            $this->logActivity($request, 'sales.return_created', self::ENTITY_RETURN, $result['return_no'].'/'.$result['year'], 'إنشاء مردود مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء مردود المبيعات بنجاح / Sales return created',
                'data' => $result,
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء مردود المبيعات / Failed to create the sales return', $e);
        }
    }

    /**
     * تعديل مردود: قيد جديد ثم تحديث الرأس ثم حذف القديم واستبدال السطور
     * Update a return: new entry, header update, drop the old entry, replace the lines.
     */
    public function update(Request $request, int $returnNo, int $year): JsonResponse
    {
        $payload = $this->writePayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $header = $this->returnHeader($returnNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'مردود المبيعات غير موجود / Sales return not found',
            ], 404);
        }

        $oldEntryNo = (int) ($this->int($header, 'ENTRY_NO') ?? 0);
        $payload['manual_no'] = (int) ($this->int($header, 'MANUAL_RETURN_NO') ?? 0);

        try {
            $result = DB::connection('oracle')->transaction(function () use ($payload, $request, $returnNo, $year, $oldEntryNo): array {
                $entryNo = SalesPosting::postReturn($this->postingDocument($payload));

                DB::connection('oracle')->update(
                    'UPDATE ACCOUNTS.RETURN_SALES
                        SET RET_DATE = TO_DATE(:ret_date, \'YYYY-MM-DD\'), RETURN_TOTAL = :return_total,
                            BILL_NO = :bill_no, BILL_YEAR = :bill_year, BILL_TYPE = :bill_type,
                            DETAILES = :details, STORE_NO = :store_no, CUST_NO = :cust_no,
                            CUST_NAME = :cust_name, ENTRY_NO = :entry_no, DIS_RATE = :dis_rate,
                            DISCOUNT = :discount, NET = :net, ACC_NO = :acc_no, CUR_NO = :cur_no,
                            CUR_PRICE = :cur_price, BRANCH_NO = :branch_no,
                            LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :updated_by,
                            LAST_UPDATED_BY_NAME = :updated_by_name
                      WHERE RETURN_NO = :return_no AND YEAR = :year',
                    SalesDocumentWriter::bindings([
                        'ret_date' => $payload['header']['date'],
                        'return_total' => $payload['totals']['total'],
                        'bill_no' => $payload['header']['bill_no'],
                        'bill_year' => $payload['header']['bill_year'],
                        'bill_type' => $payload['header']['bill_type'],
                        'details' => $this->returnNote($payload),
                        'store_no' => $payload['header']['store_no'],
                        'cust_no' => $payload['header']['cust_no'],
                        'cust_name' => $payload['header']['cust_name'],
                        'entry_no' => $entryNo,
                        'dis_rate' => $payload['totals']['rate'],
                        'discount' => $payload['totals']['discount'],
                        'net' => $payload['totals']['net'],
                        'acc_no' => $payload['header']['acc_no'],
                        'cur_no' => $payload['header']['cur_no'],
                        'cur_price' => $payload['header']['cur_price'],
                        'branch_no' => $payload['header']['branch_no'],
                        'updated_by' => $request->user()?->getAuthIdentifier(),
                        'updated_by_name' => SalesDocumentWriter::clip($request->user()?->USER_NAME_AR ?? null, 200),
                        'return_no' => $returnNo,
                        'year' => $year,
                    ])
                );

                if ($oldEntryNo > 0 && $oldEntryNo !== $entryNo) {
                    SalesPosting::deleteEntry($oldEntryNo);
                }

                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.RETURN_SALES_DETAILES WHERE RETURN_NO = :return_no AND YEAR = :year',
                    ['return_no' => $returnNo, 'year' => $year]
                );

                SalesDocumentWriter::insertReturnLines($returnNo, $year, $payload['lines']);

                return [
                    'return_no' => $returnNo,
                    'year' => $year,
                    'manual_return_no' => $payload['manual_no'],
                    'entry_no' => $entryNo,
                ];
            });

            $this->logActivity($request, 'sales.return_updated', self::ENTITY_RETURN, "$returnNo/$year", 'تعديل مردود مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل مردود المبيعات بنجاح / Sales return updated',
                'data' => $result,
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل مردود المبيعات / Failed to update the sales return', $e);
        }
    }

    /**
     * حذف مردود — حذف فيزيائي للسطور فالرأس فالقيد العكسي
     * Delete a return — physical delete of lines, header, then the reversing entry.
     */
    public function destroy(Request $request, int $returnNo, int $year): JsonResponse
    {
        $header = $this->returnHeader($returnNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'مردود المبيعات غير موجود / Sales return not found',
            ], 404);
        }

        $entryNo = (int) ($this->int($header, 'ENTRY_NO') ?? 0);

        try {
            DB::connection('oracle')->transaction(function () use ($returnNo, $year, $entryNo): void {
                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.RETURN_SALES_DETAILES WHERE RETURN_NO = :return_no AND YEAR = :year',
                    ['return_no' => $returnNo, 'year' => $year]
                );

                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.RETURN_SALES WHERE RETURN_NO = :return_no AND YEAR = :year',
                    ['return_no' => $returnNo, 'year' => $year]
                );

                SalesPosting::deleteEntry($entryNo);
            });

            $this->logActivity($request, 'sales.return_deleted', self::ENTITY_RETURN, "$returnNo/$year", 'حذف مردود مبيعات');

            return response()->json([
                'success' => true,
                'message' => 'تم حذف مردود المبيعات بنجاح / Sales return deleted',
                'data' => ['return_no' => $returnNo, 'year' => $year],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف مردود المبيعات / Failed to delete the sales return', $e);
        }
    }

    /**
     * رأس المردود الخام أو null
     * The raw return header or null.
     *
     * @return array<string, mixed>|null
     */
    private function returnHeader(int $returnNo, int $year): ?array
    {
        $row = DB::connection('oracle')->table(self::TABLE)
            ->where('RETURN_NO', $returnNo)
            ->where('YEAR', $year)
            ->first();

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * إنشاء المردود داخل المعاملة
     * Create the return inside the transaction.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createReturn(array $payload, Request $request): array
    {
        $h = $payload['header'];
        $year = (int) $h['year'];
        $returnNo = SalesPolicy::nextReturnNo($year);
        $manualNo = SalesPolicy::nextManualReturnNo($year, (int) $h['bill_type']);
        $payload['manual_no'] = $manualNo;
        $note = $this->returnNote($payload);

        $entryNo = SalesPosting::postReturn([
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'manual_no' => $manualNo,
            'cust_no' => $h['cust_no'],
            'net' => (float) $payload['totals']['net'],
            'cost' => (float) $payload['totals']['cost'],
            'returns_account' => (int) $h['acc_no'],
            'party_account' => (int) $h['acc_no1'],
            'note' => $note,
        ]);

        SalesDocumentWriter::insertReturn([
            'return_no' => $returnNo,
            'year' => $year,
            'date' => (string) $h['date'],
            'total' => (float) $payload['totals']['total'],
            'bill_no' => $h['bill_no'],
            'bill_year' => $h['bill_year'],
            'bill_type' => (int) $h['bill_type'],
            'details' => $note,
            'store_no' => (int) $h['store_no'],
            'cust_no' => $h['cust_no'],
            'cust_name' => $h['cust_name'],
            'entry_no' => $entryNo,
            'dis_rate' => (float) $payload['totals']['rate'],
            'discount' => (float) $payload['totals']['discount'],
            'net' => (float) $payload['totals']['net'],
            'acc_no' => (int) $h['acc_no'],
            'cur_no' => (int) $h['cur_no'],
            'cur_price' => (float) $h['cur_price'],
            'manual_no' => $manualNo,
            'branch_no' => (int) $h['branch_no'],
            'created_by' => $request->user()?->getAuthIdentifier(),
            'created_by_name' => SalesDocumentWriter::clip($request->user()?->USER_NAME_AR ?? null, 200),
        ]);

        SalesDocumentWriter::insertReturnLines($returnNo, $year, $payload['lines']);

        return [
            'return_no' => $returnNo,
            'year' => $year,
            'manual_return_no' => $manualNo,
            'entry_no' => $entryNo,
            'net' => (float) $payload['totals']['net'],
        ];
    }

    /**
     * بيانات قيد المردود العكسي
     * The reversing entry's data.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postingDocument(array $payload): array
    {
        $h = $payload['header'];

        return [
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'manual_no' => (int) $payload['manual_no'],
            'cust_no' => $h['cust_no'],
            'net' => (float) $payload['totals']['net'],
            'cost' => (float) $payload['totals']['cost'],
            'returns_account' => (int) $h['acc_no'],
            'party_account' => (int) $h['acc_no1'],
            'note' => $this->returnNote($payload),
        ];
    }

    /**
     * بيان المردود: نص المستخدم أو صياغة النظام القديم
     * The return narration: the user's text or the legacy wording.
     *
     * @param  array<string, mixed>  $payload
     */
    private function returnNote(array $payload): string
    {
        $h = $payload['header'];

        if (! empty($h['note'])) {
            return SalesDocumentWriter::clip((string) $h['note'], 100) ?? '';
        }

        $label = SalesPolicy::BILL_TYPE_LABELS[(int) $h['bill_type']] ?? '';
        $manual = $payload['manual_no'] ?? '';
        $from = empty($h['bill_no']) ? '' : " من فاتورة مبيعات رقم {$h['bill_no']}";

        return SalesDocumentWriter::clip("مردود مبيعات {$label} رقم {$manual}{$from}", 100) ?? '';
    }

    /**
     * التحقق من حمولة المردود وتجهيزها أو رد 422
     * Validate and prepare the return payload or a 422 response.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function writePayload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bill_type' => 'required|integer|in:1,2',
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'store_no' => 'required|integer|exists:oracle.STORES,STORE_NO',
            'branch_no' => 'nullable|integer',
            'bill_no' => 'nullable|integer|min:1',
            'bill_year' => 'nullable|integer|min:2000|max:2100',
            'cust_no' => 'nullable|integer',
            'cust_name' => 'nullable|string|max:80',
            'acc_no' => 'nullable|integer',
            'acc_no1' => 'nullable|integer',
            'cur_no' => 'nullable|integer',
            'cur_price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:100',
            'lines' => 'required|array|min:1|max:'.SalesPolicy::MAX_LINES,
            'lines.*.sanf_no' => 'required|integer|min:1',
            'lines.*.qty' => 'required|numeric|gt:0',
            'lines.*.price' => 'required|numeric|min:0',
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.seq_sales' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المردود غير صالحة / The return payload is invalid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $billType = (int) $data['bill_type'];
        $custNo = isset($data['cust_no']) ? (int) $data['cust_no'] : null;
        $customer = $this->customer($custNo);
        $source = $this->invoice((int) ($data['bill_no'] ?? 0), (int) ($data['bill_year'] ?? 0));

        // مردود بلا فاتورة مصدر: يُقبل، لكن الآجل يحتاج عميلاً لتحديد الحساب الدائن.
        // A return without a source invoice is accepted, but credit needs a customer for the credit side.
        if ($customer === null && $source !== null) {
            $custNo = $this->int($source, 'CUST_NO');
            $customer = $this->customer($custNo);
        }

        if ($billType === SalesPolicy::BILL_CREDIT && $customer === null) {
            return response()->json([
                'success' => false,
                'message' => 'مردود الفاتورة الآجلة يحتاج عميلاً / A credit return requires a customer',
                'errors' => ['cust_no' => 'required'],
            ], 422);
        }

        $accNo = (int) ($data['acc_no'] ?? 0) ?: SalesPolicy::returnAccount($billType);

        // حساب الطرف الدائن: عميل الآجل أو صندوق النقدية للنقدي
        // The credit-side party: the customer ledger on credit, the cash box on cash.
        $accNo1 = (int) ($data['acc_no1'] ?? 0);

        if ($accNo1 <= 0) {
            $accNo1 = $billType === SalesPolicy::BILL_CREDIT
                ? (int) ($customer['ACC_NO'] ?? 0)
                : SalesPolicy::DEFAULT_CASH_ACCOUNT;
        }

        if ($accNo1 <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'حساب الطرف الدائن مطلوب / A credit-side account is required',
                'errors' => ['acc_no1' => 'required'],
            ], 422);
        }

        $guard = SalesDocumentWriter::normalizeLines((array) $data['lines']);

        if (! $guard['ok']) {
            return response()->json([
                'success' => false,
                'message' => $guard['message'],
                'errors' => $guard['errors'],
            ], 422);
        }

        $totals = SalesDocumentWriter::totals($guard['lines'], (float) ($data['discount'] ?? 0));

        if ($totals['net'] <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'صافي المردود يجب أن يكون أكبر من صفر / The return net must be greater than zero',
                'errors' => ['net' => 'must be > 0'],
            ], 422);
        }

        $custName = $data['cust_name']
            ?? $customer['CUST_NAME']
            ?? ($billType === SalesPolicy::BILL_CASH ? 'مردودات مبيعات نقدية' : null);

        return [
            'header' => [
                'bill_type' => $billType,
                'date' => (string) $data['date'],
                'year' => (int) substr((string) $data['date'], 0, 4),
                'store_no' => (int) $data['store_no'],
                'branch_no' => (int) ($data['branch_no'] ?? 1),
                'bill_no' => isset($data['bill_no']) && (int) $data['bill_no'] > 0 ? (int) $data['bill_no'] : null,
                'bill_year' => isset($data['bill_year']) && (int) $data['bill_year'] > 0 ? (int) $data['bill_year'] : null,
                'cust_no' => $custNo,
                'cust_name' => SalesDocumentWriter::clip((string) $custName, 80),
                'acc_no' => $accNo,
                'acc_no1' => $accNo1,
                'cur_no' => (int) ($data['cur_no'] ?? 1),
                'cur_price' => (float) ($data['cur_price'] ?? 1),
                'note' => SalesDocumentWriter::clip($data['note'] ?? null, 100),
            ],
            'lines' => $guard['lines'],
            'totals' => [
                'total' => $totals['total'],
                'discount' => $totals['discount'],
                'rate' => $totals['total'] > 0 ? round($totals['discount'] / $totals['total'] * 100, 2) : 0.0,
                'net' => $totals['net'],
                'count' => $totals['count'],
                'cost' => $totals['cost'],
            ],
        ];
    }

    /**
     * بطاقة العميل أو null
     *
     * @return array<string, mixed>|null
     */
    private function customer(?int $custNo): ?array
    {
        if ($custNo === null || $custNo <= 0) {
            return null;
        }

        $row = DB::connection('oracle')->selectOne(
            'SELECT CUST_NO, CUST_NAME, ACC_NO, TEL1, ADDR FROM ACCOUNTS.CUST WHERE CUST_NO = :c',
            ['c' => $custNo]
        );

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * رأس الفاتورة المصدر (لأخذ العميل/النوع منها) أو null
     *
     * @return array<string, mixed>|null
     */
    private function invoice(int $billNo, int $year): ?array
    {
        if ($billNo <= 0) {
            return null;
        }

        $query = DB::connection('oracle')->table('ACCOUNTS.SALES_BILL')
            ->where('BILL_NO', $billNo)
            ->where('CUTTING_OR_SLAES', SalesPolicy::CUTTING_OR_SALES);

        if ($year > 0) {
            $query->where('YEAR', $year);
        }

        $row = $query->first();

        return $row === null ? null : array_change_key_case((array) $row, CASE_UPPER);
    }

    /**
     * إعادة المحاولة عند تصادم الترقيم (ORA-00001)
     * Retry on a numbering collision (ORA-00001).
     *
     * @param  callable(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function withNumberRetry(callable $operation): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (QueryException $e) {
                $unique = (int) ($e->errorInfo[1] ?? 0) === 1 || str_contains($e->getMessage(), 'ORA-00001');

                if (! $unique || $attempt >= SalesPolicy::NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────

    /** @return array{column: string, dir: string} */
    private function resolveSort(Request $request): array
    {
        $allowed = [
            'date' => 'RET_DATE', 'return_no' => 'RETURN_NO', 'manual_no' => 'MANUAL_RETURN_NO',
            'customer' => 'CUST_NAME', 'net' => 'NET', 'total' => 'RETURN_TOTAL',
        ];
        $key = (string) ($request->input('sort') ?? 'date');
        $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return ['column' => $allowed[$key] ?? 'RET_DATE', 'dir' => $dir];
    }

    private function dateInput(Request $request, string $key): ?string
    {
        $value = trim((string) ($request->input($key) ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toSummary(array $row): array
    {
        return [
            'return_no' => $this->int($row, 'RETURN_NO'),
            'year' => $this->int($row, 'YEAR'),
            'manual_return_no' => $this->int($row, 'MANUAL_RETURN_NO'),
            'bill_no' => $this->int($row, 'BILL_NO'),
            'bill_year' => $this->int($row, 'BILL_YEAR'),
            'bill_type' => $this->int($row, 'BILL_TYPE'),
            'date' => $this->str($row, 'RET_DATE_ISO'),
            'cust_no' => $this->int($row, 'CUST_NO'),
            'cust_name' => $this->str($row, 'CUST_NAME'),
            'total' => $this->num($row, 'RETURN_TOTAL'),
            'discount' => $this->num($row, 'DISCOUNT'),
            'net' => $this->num($row, 'NET'),
            'branch_no' => $this->int($row, 'BRANCH_NO'),
            'store_no' => $this->int($row, 'STORE_NO'),
            'items_count' => $this->int($row, 'ITEMS_COUNT'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toDetail(array $row): array
    {
        return [
            'seq' => $this->int($row, 'SEQ'),
            'sanf_no' => $this->int($row, 'SANF_NO'),
            'sanf_name' => $this->str($row, 'SANF_NAME'),
            'qty' => $this->num($row, 'RET_QTY'),
            'cap' => $this->num($row, 'CAP'),
            'pcs' => $this->num($row, 'PCS'),
            'price' => $this->num($row, 'SANF_PRICE'),
            'discount_rate' => $this->num($row, 'DISCOUNT_RATE'),
            'discount' => $this->num($row, 'DISCOUNT1'),
            'bouns' => $this->num($row, 'BOUNS'),
            'total' => $this->num($row, 'TOTAL'),
            'category' => $this->str($row, 'CATEGORY'),
        ];
    }
}
