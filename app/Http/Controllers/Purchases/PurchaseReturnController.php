<?php

namespace App\Http\Controllers\Purchases;

use App\Support\ArabicEncoding;
use App\Support\InventoryLineGuard;
use App\Support\JournalWriter;
use App\Support\PermissionResolver;
use App\Support\PurchaseDocumentWriter;
use App\Support\PurchasePolicy;
use App\Support\PurchasePosting;
use App\Support\StorePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم مردودات المشتريات — `RETURN_BUY` وتفاصيلها `RETURN_BUY_DETAILES`
 * Purchase returns controller — RETURN_BUY and its RETURN_BUY_DETAILES lines.
 *
 * ── حقيقة تفرض تصميم هذا الملف: المفتاح الأساسي لسطور المردود خمسة أعمدة
 *    (RETURN_NO, YEAR, SANF_NO, CAP, SANF_DNO). أي أن المردود يحمل **سطراً واحداً لكل صنف
 *    فرعي**، بينما 48 مجموعة سطور فواتير تتكرر بنفس المفتاح. لذلك:
 *      • السطور القابلة للرد تُجمَّع بالمفتاح نفسه لا سطراً بسطر،
 *      • `SEQ_SALES` يأخذ أصغر `SEQ` في المجموعة (والحقل فارغ في 89 من 203 صفاً قديماً أصلاً)،
 *      • أي تكرار في حمولة العميل يُدمج هنا قبل الإدراج وإلا رفضته القاعدة.
 * ── A fact that shapes this file: the return line's primary key is
 *    (return_no, year, sanf_no, cap, sanf_dno) — one line per sub item — while 48 invoice
 *    line-groups repeat that key. So returnable lines are aggregated by that key, SEQ_SALES takes
 *    the smallest member sequence, and duplicate payload lines are merged before insertion.
 */
class PurchaseReturnController extends PurchaseBaseController
{
    private const TABLE = 'ACCOUNTS.RETURN_BUY';

    private const TABLE_DETAILS = 'ACCOUNTS.RETURN_BUY_DETAILES';

    private const TABLE_INVOICE = 'ACCOUNTS.BUY_BILL';

    /** قائمة المردودات مع بيانات فاتورتها / the return list with its invoice's data */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) ($request->input('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $page = max((int) ($request->input('page') ?? 1), 1);
        $offset = ($page - 1) * $limit;

        try {
            $query = DB::connection('oracle')->table(self::TABLE.' r')
                ->leftJoin('ACCOUNTS.STORES s', 's.STORE_NO', '=', 'r.STORE_NO')
                ->leftJoin('ACCOUNTS.CHART_ACC c', 'c.ACC_NO', '=', 'r.ACC_NO')
                ->select([
                    'r.RETURN_NO', 'r.YEAR', 'r.BILL_NO', 'r.BILL_YEAR', 'r.BILL_TYPE',
                    'r.PROV_NO', 'r.PROV_NAME', 'r.MANUAL_RETURN_NO', 'r.RETURN_TOTAL',
                    'r.DISCOUNT', 'r.NET', 'r.CUR_NO', 'r.CUR_PRICE', 'r.STORE_NO',
                    'r.ENTRY_NO', 'r.BRANCH_NO',
                    's.STORE_NAME', 'c.ACC_NAME AS PARTY_NAME',
                    DB::raw('TO_CHAR(r.RET_DATE, \'YYYY-MM-DD\') AS RET_DATE_ISO'),
                    DB::raw('ROUND(NVL(r.NET, 0) * NVL(r.CUR_PRICE, 1), 2) AS NET_BASE'),
                ]);

            $this->applyListFilters($query, $request);

            $total = (clone $query)->count();

            $rows = $query
                ->orderByRaw($this->resolveSort($request))
                ->orderByDesc('r.RETURN_NO')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $items = array_map(fn ($row): array => $this->toSummary($this->upperRow($row) ?? []), $rows->all());

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مردودات المشتريات بنجاح / Purchase returns retrieved',
                'data' => ArabicEncoding::cleanData($items),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مردودات المشتريات / Failed to retrieve purchase returns', $e);
        }
    }

    /** خيارات الفلترة / filter facets */
    public function filterOptions(): JsonResponse
    {
        try {
            $base = fn () => DB::connection('oracle')->table(self::TABLE.' r');

            return response()->json([
                'success' => true,
                'message' => 'تم جلب خيارات الفلترة / Filter options retrieved',
                'data' => ArabicEncoding::cleanData([
                    'years' => $this->distinctInts($base(), 'r.YEAR'),
                    'bill_types' => $this->distinctInts($base(), 'r.BILL_TYPE'),
                    'stores' => $this->distinctInts($base(), 'r.STORE_NO'),
                    'currencies' => $this->distinctInts($base(), 'r.CUR_NO'),
                ]),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب خيارات الفلترة / Failed to retrieve filter options', $e);
        }
    }

    /** مردود واحد مع سطوره وسطر فاتورته / one return with its lines and invoice link */
    public function show(int $returnNo, int $year): JsonResponse
    {
        try {
            $header = $this->returnHeader($returnNo, $year);

            if ($header === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'مردود المشتريات غير موجود / Purchase return not found',
                ], 404);
            }

            $lines = DB::connection('oracle')->select(
                'SELECT r.SEQ, r.SANF_NO, r.SANF_DNO, r.SANF_PRICE, r.RET_QTY, r.CAP, r.PCS,
                        r.TOTAL, r.DISCOUNT_RATE, r.DISCOUNT1, r.SEQ_SALES, r.SEQ_NO,
                        r.STK_COST, r.CATEGORY, r.CAT_NO, r.STYLE_NO, r.COLOR_NO,
                        s.SANF_NAME, sd.SANF_DNAME
                   FROM ACCOUNTS.RETURN_BUY_DETAILES r
                   LEFT JOIN ACCOUNTS.SANFS s ON s.SANF_NO = r.SANF_NO
                   LEFT JOIN ACCOUNTS.SANFS_D sd ON sd.SANF_DNO = r.SANF_DNO
                  WHERE r.RETURN_NO = :ret AND r.YEAR = :year
                  ORDER BY r.SEQ_NO, r.SEQ',
                ['ret' => $returnNo, 'year' => $year]
            );

            $net = (float) ($this->num($header, 'NET') ?? 0);
            $rate = (float) ($this->num($header, 'CUR_PRICE') ?? 1);

            $payload = [
                'return_no' => $this->int($header, 'RETURN_NO'),
                'year' => $this->int($header, 'YEAR'),
                'bill_no' => $this->int($header, 'BILL_NO'),
                'bill_year' => $this->int($header, 'BILL_YEAR'),
                'bill_type' => $this->int($header, 'BILL_TYPE'),
                'date' => $this->str($header, 'RET_DATE_ISO'),
                'manual_return_no' => $this->int($header, 'MANUAL_RETURN_NO'),
                'prov_no' => $this->int($header, 'PROV_NO'),
                'prov_name' => $this->str($header, 'PROV_NAME'),
                'store_no' => $this->int($header, 'STORE_NO'),
                'acc_no' => $this->int($header, 'ACC_NO'),
                'acc_no1' => $this->int($header, 'ACC_NO1'),
                'cur_no' => $this->int($header, 'CUR_NO'),
                'cur_price' => $rate,
                'cur_no1' => $this->int($header, 'CUR_NO1'),
                'cur_price1' => (float) ($this->num($header, 'CUR_PRICE1') ?? 1),
                'total' => (float) ($this->num($header, 'RETURN_TOTAL') ?? 0),
                'discount' => (float) ($this->num($header, 'DISCOUNT') ?? 0),
                'net' => $net,
                'net_base' => round($net * $rate, 2),
                'entry_no' => $this->int($header, 'ENTRY_NO'),
                'posted' => $this->int($header, 'ENTRY_NO') !== null,
                'details' => $this->str($header, 'DETAILES'),
                'created_by_name' => $this->str($header, 'CREATED_BY_NAME'),
                'created_on' => $this->str($header, 'CREATED_ON_ISO'),
                'lines' => array_map(fn ($r): array => $this->toLine($this->upperRow($r) ?? []), $lines),
            ];

            $payload['totals'] = $this->lineTotals($payload['lines'], $payload['discount']);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب مردود المشتريات بنجاح / Purchase return retrieved',
                'data' => ArabicEncoding::cleanData($payload),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب مردود المشتريات / Failed to retrieve the purchase return', $e);
        }
    }

    /** إنشاء مردود مشتريات وترحيله / create and post a purchase return */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->writePayload($request, null);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $result = $this->withNumberRetry(fn (): array => DB::connection('oracle')->transaction(
                fn (): array => $this->createReturn($payload, $request)
            ));

            $this->logActivity($request, 'purchases.return_created', self::ENTITY_RETURN, $result['return_no'].'/'.$result['year'], 'إنشاء مردود مشتريات');

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء مردود المشتريات بنجاح / Purchase return created',
                'data' => $result,
            ], 201);
        } catch (\RuntimeException $e) {
            return $this->invalid($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء مردود المشتريات / Failed to create the purchase return', $e);
        }
    }

    /** تعديل مردود: قيد جديد ثم الرأس ثم استبدال السطور ثم حذف القيد القديم */
    /** Update a return: new entry, header, replace lines, then drop the old entry. */
    public function update(Request $request, int $returnNo, int $year): JsonResponse
    {
        $header = $this->returnHeader($returnNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'مردود المشتريات غير موجود / Purchase return not found',
            ], 404);
        }

        $payload = $this->writePayload($request, $header);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $oldEntryNo = $this->int($header, 'ENTRY_NO');

        try {
            $result = DB::connection('oracle')->transaction(function () use ($payload, $header, $returnNo, $year, $oldEntryNo, $request): array {
                return $this->updateReturn($payload, $header, $returnNo, $year, $oldEntryNo, $request);
            });

            $this->logActivity($request, 'purchases.return_updated', self::ENTITY_RETURN, "$returnNo/$year", 'تعديل مردود مشتريات');

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل مردود المشتريات بنجاح / Purchase return updated',
                'data' => $result,
            ], 200);
        } catch (\RuntimeException $e) {
            return $this->invalid($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل مردود المشتريات / Failed to update the purchase return', $e);
        }
    }

    /** حذف مردود: السطور ثم الرأس ثم القيد / delete a return: lines, header, then entry */
    public function destroy(Request $request, int $returnNo, int $year): JsonResponse
    {
        $header = $this->returnHeader($returnNo, $year);

        if ($header === null) {
            return response()->json([
                'success' => false,
                'message' => 'مردود المشتريات غير موجود / Purchase return not found',
            ], 404);
        }

        $storeNo = (int) ($this->int($header, 'STORE_NO') ?? 0);
        $allowNegative = app(PermissionResolver::class)->can($request->user(), 'purchases.*');

        try {
            // حذف المردود يعيد الكمية إلى المخزون؛ لا خطر سالب هنا، لكن يبقى الفحص واقياً
            // من حذف مردود على مخزن لم تسجّل فيه الكمية أصلاً — لا ينطبق على المخازن المستثناة.
            // Deleting a return adds stock back, so no negative risk; the guard only matters for
            // stock stores and is kept for symmetry with the invoice delete path.
            if (StorePolicy::isStockStore($storeNo) && ! $allowNegative) {
                $this->assertReturnsStillCovered($header);
            }

            $entryNo = $this->int($header, 'ENTRY_NO');

            DB::connection('oracle')->transaction(function () use ($returnNo, $year, $entryNo): void {
                PurchaseDocumentWriter::deleteLines('RETURN_BUY_DETAILES', 'RETURN_NO', $returnNo, $year);

                DB::connection('oracle')->delete(
                    'DELETE FROM ACCOUNTS.RETURN_BUY WHERE RETURN_NO = :ret AND YEAR = :year',
                    ['ret' => $returnNo, 'year' => $year]
                );

                JournalWriter::deleteEntry($entryNo);
            });

            $this->logActivity($request, 'purchases.return_deleted', self::ENTITY_RETURN, "$returnNo/$year", 'حذف مردود مشتريات');

            return response()->json([
                'success' => true,
                'message' => 'تم حذف مردود المشتريات / Purchase return deleted',
                'data' => ['return_no' => $returnNo, 'year' => $year],
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في حذف مردود المشتريات / Failed to delete the purchase return', $e);
        }
    }

    /** تسجيل طباعة/تصدير على المردود / log a print or export on the return */
    public function activity(Request $request, int $returnNo, int $year): JsonResponse
    {
        $this->logActivity($request, 'purchases.return_printed', self::ENTITY_RETURN, "$returnNo/$year", 'طباعة/تصدير مردود مشتريات');

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل النشاط / Activity logged',
        ], 200);
    }

    // ── الكتابة ──────────────────────────────────────────────

    /**
     * التحقق من حمولة المردود — كل شيء يرثه من فاتورته
     * Validate the return payload — everything is inherited from its invoice.
     *
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|JsonResponse
     */
    private function writePayload(Request $request, ?array $existing)
    {
        $validator = Validator::make($request->all(), [
            'bill_no' => 'required|integer|min:1',
            'bill_year' => 'required|integer|min:2000|max:2100',
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'details' => 'nullable|string|max:100',
            'discount' => 'nullable|numeric|min:0',
            'lines' => 'required|array|min:1',
            'lines.*.sanf_no' => 'required|integer',
            'lines.*.sanf_dno' => 'required|integer',
            'lines.*.cap' => 'required|numeric|min:0',
            'lines.*.qty' => 'required|numeric|gt:0',
            'lines.*.price' => 'required|numeric|min:0',
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return $this->invalid('بيانات المردود غير صالحة / The return data is invalid.', $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $billNo = (int) $data['bill_no'];
        $billYear = (int) $data['bill_year'];

        $invoice = $this->upperRow(DB::connection('oracle')->selectOne(
            'SELECT * FROM ACCOUNTS.BUY_BILL WHERE BILL_NO = :bill_no AND YEAR = :year',
            ['bill_no' => $billNo, 'year' => $billYear]
        ));

        if ($invoice === null) {
            return $this->invalid('فاتورة المشتريات الأصلية غير موجودة / The source invoice does not exist.');
        }

        $storeNo = (int) ($this->int($invoice, 'STORE_NO') ?? 0);
        $billType = (int) ($this->int($invoice, 'BILL_TYPE') ?? PurchasePolicy::BILL_CASH);
        $accNo = (int) ($this->int($invoice, 'ACC_NO1') ?? 0);
        $accNo1 = (int) ($this->int($invoice, 'ACC_NO') ?? PurchasePolicy::STOCK_ACCOUNT);
        $curNo = (int) ($this->int($invoice, 'CUR_NO1') ?? 1);
        $rate = (float) ($this->num($invoice, 'CUR_PRICE1') ?? 1);

        // 1) الدمج بالمفتاح الخماسي ثم التحقق من المتبقي لكل مجموعة
        //    Merge by the five-column key, then check each group's remaining quantity.
        $lines = $this->mergeLines((array) $data['lines'], $invoice, $rate);
        $remaining = $this->remainingByGroup($billNo, $billYear, $existing === null ? null : [
            (int) ($this->int($existing, 'RETURN_NO') ?? 0),
            (int) ($this->int($existing, 'YEAR') ?? 0),
        ]);

        foreach ($lines as $line) {
            $key = $line['sanf_no'].'|'.$line['sanf_dno'].'|'.(float) $line['cap'];
            $left = $remaining[$key] ?? 0.0;

            if (round($line['qty'] - $left, 3) > 0) {
                return $this->invalid(
                    "السطر [{$line['sanf_no']}/{$line['sanf_dno']}]: الكمية تتجاوز المتبقي ({$left}) / The quantity exceeds what remains.",
                    ['lines' => ['remaining' => $left]]
                );
            }
        }

        // 2) فحص المتاح في المخزن — المخازن المستثناة (11/12/2) لا رصيد لها أصلاً
        //    Stock availability — excluded stores carry no balance at all.
        $allowNegative = app(PermissionResolver::class)->can($request->user(), 'purchases.*');

        if (StorePolicy::isStockStore($storeNo) && ! $allowNegative) {
            $shortages = $this->availabilityShortages($storeNo, $lines);

            if ($shortages !== []) {
                return $this->invalid(
                    'الكمية المطلوبة تتجاوز الرصيد المتاح / The requested quantity exceeds the available stock.',
                    ['stock' => $shortages]
                );
            }
        }

        $totals = PurchaseDocumentWriter::totals($lines, (float) ($data['discount'] ?? 0));

        return [
            'invoice' => $invoice,
            'header' => [
                'bill_no' => $billNo,
                'bill_year' => $billYear,
                'bill_type' => $billType,
                'date' => (string) $data['date'],
                'store_no' => $storeNo,
                'prov_no' => $this->int($invoice, 'PROV_NO'),
                'prov_name' => $this->str($invoice, 'PROV_NAME') ?? 'مورد',
                'acc_no' => $accNo,
                'acc_no1' => $accNo1,
                'cur_no' => $curNo,
                'cur_price' => $rate,
                'cur_no1' => 1,
                'cur_price1' => 1.0,
                'branch_no' => (int) ($this->int($invoice, 'BRANCH_NO') ?? PurchasePolicy::DEFAULT_BRANCH),
                'details' => $data['details'] ?? null,
                'manual_no' => $this->int($invoice, 'MANUAL_BILL_NO') ?? $billNo,
            ],
            'lines' => $lines,
            'totals' => $totals,
        ];
    }

    /**
     * إنشاء المردود داخل معاملة: قيد ← رأس ← سطور
     * Create the return inside a transaction: entry, header, lines.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createReturn(array $payload, Request $request): array
    {
        $year = PurchasePolicy::dbYear();
        $returnNo = PurchasePolicy::nextReturnNo($year);
        $h = $payload['header'];

        $note = PurchaseDocumentWriter::clip($h['details'], PurchasePolicy::DETAILES_LEN)
            ?? PurchasePolicy::returnNote((int) $h['bill_type'], $returnNo, (int) $h['manual_no']);

        $netBase = round((float) $payload['totals']['net'] * (float) $h['cur_price'], 2);
        $seqPrice = PurchasePosting::resolveRateSeq((int) $h['cur_no'], (float) $h['cur_price'], (string) $h['date']);

        $entryNo = PurchasePosting::postReturn([
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'manual_no' => $returnNo,
            'net' => (float) $payload['totals']['net'],
            'net_base' => $netBase,
            'stock_account' => (int) $h['acc_no1'],
            'party_account' => (int) $h['acc_no'],
            'seq_price' => $seqPrice,
            'note' => $note,
        ]);

        $user = $request->user();
        $legacyId = PurchasePolicy::legacyUserId($user?->getAuthIdentifier());
        $userName = $user?->USER_NAME_AR ?? $user?->name ?? null;

        PurchaseDocumentWriter::insertReturn([
            'return_no' => $returnNo,
            'year' => $year,
            'date' => $h['date'],
            'total' => $payload['totals']['total'],
            'bill_no' => $h['bill_no'],
            'details' => $note,
            'bill_year' => $h['bill_year'],
            'store_no' => $h['store_no'],
            'prov_no' => $h['prov_no'],
            'bill_type' => $h['bill_type'],
            'entry_no' => $entryNo,
            'dis_rate' => $payload['totals']['rate'],
            'discount' => $payload['totals']['discount'],
            'net' => $payload['totals']['net'],
            'prov_name' => $h['prov_name'],
            'acc_no' => $h['acc_no'],
            'cur_no' => $h['cur_no'],
            'cur_price' => $h['cur_price'],
            'seq_price' => $seqPrice,
            'acc_no1' => $h['acc_no1'],
            'cur_no1' => $h['cur_no1'],
            'cur_price1' => $h['cur_price1'],
            'manual_no' => $returnNo,
            'branch_no' => $h['branch_no'],
            'created_by' => $legacyId,
            'created_by_name' => $userName,
            'updated_by' => null,
            'updated_by_name' => null,
            'the_time' => PurchasePolicy::dbTime(),
        ]);

        PurchaseDocumentWriter::insertReturnLines($returnNo, $year, $payload['lines'], (float) $h['cur_price']);

        return [
            'return_no' => $returnNo,
            'year' => $year,
            'entry_no' => $entryNo,
            'net' => $payload['totals']['net'],
            'net_base' => $netBase,
        ];
    }

    /**
     * تعديل المردود داخل معاملة — السطور تُستبدل لأن لا شيء يشير إليها
     * Update the return inside a transaction — lines are replaced because nothing references them.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function updateReturn(array $payload, array $header, int $returnNo, int $year, ?int $oldEntryNo, Request $request): array
    {
        $h = $payload['header'];

        $note = PurchaseDocumentWriter::clip($h['details'], PurchasePolicy::DETAILES_LEN)
            ?? PurchasePolicy::returnNote((int) $h['bill_type'], $returnNo, (int) $h['manual_no']);

        $netBase = round((float) $payload['totals']['net'] * (float) $h['cur_price'], 2);
        $seqPrice = PurchasePosting::resolveRateSeq((int) $h['cur_no'], (float) $h['cur_price'], (string) $h['date']);

        $entryNo = PurchasePosting::postReturn([
            'bill_type' => (int) $h['bill_type'],
            'date' => (string) $h['date'],
            'manual_no' => $returnNo,
            'net' => (float) $payload['totals']['net'],
            'net_base' => $netBase,
            'stock_account' => (int) $h['acc_no1'],
            'party_account' => (int) $h['acc_no'],
            'seq_price' => $seqPrice,
            'note' => $note,
        ]);

        $user = $request->user();

        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.RETURN_BUY
                SET RET_DATE = TO_DATE(:ret_date, \'YYYY-MM-DD\'), RETURN_TOTAL = :total,
                    DETAILES = :details, ENTRY_NO = :entry_no, DIS_RATE = :dis_rate,
                    DISCOUNT = :discount, NET = :net, SEQ_PRICE = :seq_price,
                    LAST_UPDATED_ON = SYSDATE, LAST_UPDATED_BY = :updated_by,
                    LAST_UPDATED_BY_NAME = :updated_by_name, LAST_UPDATE_TIME = :the_time
              WHERE RETURN_NO = :ret AND YEAR = :year',
            PurchaseDocumentWriter::bindings([
                'ret_date' => $h['date'],
                'total' => $payload['totals']['total'],
                'details' => $note,
                'entry_no' => $entryNo,
                'dis_rate' => $payload['totals']['rate'],
                'discount' => $payload['totals']['discount'],
                'net' => $payload['totals']['net'],
                'seq_price' => $seqPrice,
                'updated_by' => PurchasePolicy::legacyUserId($user?->getAuthIdentifier()),
                'updated_by_name' => PurchaseDocumentWriter::clip($user?->USER_NAME_AR ?? $user?->name ?? null, PurchasePolicy::RETURN_BY_NAME_LEN),
                'the_time' => PurchasePolicy::dbTime(),
                'ret' => $returnNo,
                'year' => $year,
            ])
        );

        if ($oldEntryNo !== null && $oldEntryNo !== $entryNo) {
            JournalWriter::deleteEntry($oldEntryNo);
        }

        PurchaseDocumentWriter::deleteLines('RETURN_BUY_DETAILES', 'RETURN_NO', $returnNo, $year);
        PurchaseDocumentWriter::insertReturnLines($returnNo, $year, $payload['lines'], (float) $h['cur_price']);

        return [
            'return_no' => $returnNo,
            'year' => $year,
            'entry_no' => $entryNo,
            'net' => $payload['totals']['net'],
            'net_base' => $netBase,
        ];
    }

    // ── helpers ──────────────────────────────────────────────

    /**
     * دمج سطور الحمولة بالمفتاح الخماسي وربطها بسطر الفاتورة
     * Merge payload lines by the five-column key and link each to its invoice line.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @param  array<string, mixed>  $invoice
     * @return array<int, array<string, mixed>>
     */
    private function mergeLines(array $rawLines, array $invoice, float $rate): array
    {
        $firstSeq = [];

        foreach (DB::connection('oracle')->select(
            'SELECT SANF_NO, SANF_DNO, CAP, MIN(SEQ) AS FIRST_SEQ
               FROM ACCOUNTS.BUY_DETAILES
              WHERE BILL_NO = :bill_no AND YEAR = :year
              GROUP BY SANF_NO, SANF_DNO, CAP',
            [
                'bill_no' => (int) ($this->int($invoice, 'BILL_NO') ?? 0),
                'year' => (int) ($this->int($invoice, 'YEAR') ?? 0),
            ]
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $firstSeq[$r['SANF_NO'].'|'.$r['SANF_DNO'].'|'.(float) $r['CAP']] = (int) $r['FIRST_SEQ'];
        }

        $merged = [];

        foreach ($rawLines as $index => $line) {
            $sanfNo = (int) $line['sanf_no'];
            $sanfDno = (int) $line['sanf_dno'];
            $cap = (float) $line['cap'];
            $key = $sanfNo.'|'.$sanfDno.'|'.$cap;
            $qty = (float) $line['qty'];
            $price = round((float) $line['price'], 2);
            $rateDisc = round((float) ($line['discount_rate'] ?? 0), 2);

            if (! isset($firstSeq[$key])) {
                throw new \RuntimeException(
                    'السطر '.($index + 1).': الصنف الفرعي ليس ضمن سطور الفاتورة / The sub item is not on the invoice.'
                );
            }

            if (isset($merged[$key])) {
                $merged[$key]['qty'] += $qty;
                $merged[$key]['pcs'] = round($merged[$key]['qty'] * $cap, 3);
                $merged[$key]['total'] = round($merged[$key]['qty'] * $price, 2);
                $merged[$key]['discount'] = round($merged[$key]['total'] * $rateDisc / 100, 2);

                continue;
            }

            $merged[$key] = [
                'sanf_no' => $sanfNo,
                'sanf_dno' => $sanfDno,
                'cap' => $cap,
                'qty' => $qty,
                'price' => $price,
                'pcs' => round($qty * $cap, 3),
                'total' => round($qty * $price, 2),
                'discount_rate' => $rateDisc,
                'discount' => round($qty * $price * $rateDisc / 100, 2),
                'size_frm' => (int) ($line['size_frm'] ?? 0),
                'size_to' => (int) ($line['size_to'] ?? 0),
                'color_no' => (int) ($line['color_no'] ?? 0),
                'category' => (string) ($line['category'] ?? ''),
                'cat_no' => (int) ($line['cat_no'] ?? 0),
                'style_no' => (int) ($line['style_no'] ?? 0),
                'seq_sales' => $firstSeq[$key],
                'seq_order' => $index + 1,
                // التكلفة بالريال تُشتق من سعر السطر وعملة الفاتورة
                // The riyal cost derives from the line price and the invoice currency.
                'stk_cost' => round($price * $rate, 2),
            ];
        }

        return array_values($merged);
    }

    /**
     * المتبقي لكل مجموعة (صنف، صنف فرعي، عبوة) = المشترى − المردود سابقاً
     * Remaining per (item, sub item, pack) group: purchased minus previously returned.
     *
     * @param  array{0: int, 1: int}|null  $exclude  المردود الجاري تعديله
     * @return array<string, float>
     */
    private function remainingByGroup(int $billNo, int $billYear, ?array $exclude = null): array
    {
        $purchased = [];

        foreach (DB::connection('oracle')->select(
            'SELECT SANF_NO, SANF_DNO, CAP, SUM(QTY) AS QTY
               FROM ACCOUNTS.BUY_DETAILES
              WHERE BILL_NO = :bill_no AND YEAR = :year
              GROUP BY SANF_NO, SANF_DNO, CAP',
            ['bill_no' => $billNo, 'year' => $billYear]
        ) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $purchased[$r['SANF_NO'].'|'.$r['SANF_DNO'].'|'.(float) $r['CAP']] = (float) $r['QTY'];
        }

        $returnedSql = 'SELECT r.SANF_NO, r.SANF_DNO, r.CAP, SUM(r.RET_QTY) AS QTY
                          FROM ACCOUNTS.RETURN_BUY_DETAILES r
                          JOIN ACCOUNTS.RETURN_BUY h ON h.RETURN_NO = r.RETURN_NO AND h.YEAR = r.YEAR
                         WHERE h.BILL_NO = :bill_no AND h.BILL_YEAR = :year';

        $binds = ['bill_no' => $billNo, 'year' => $billYear];

        if ($exclude !== null && $exclude[0] > 0) {
            $returnedSql .= ' AND NOT (h.RETURN_NO = :exclude AND h.YEAR = :exclude_year)';
            $binds['exclude'] = $exclude[0];
            $binds['exclude_year'] = $exclude[1];
        }

        $returnedSql .= ' GROUP BY r.SANF_NO, r.SANF_DNO, r.CAP';

        foreach (DB::connection('oracle')->select($returnedSql, $binds) as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $key = $r['SANF_NO'].'|'.$r['SANF_DNO'].'|'.(float) $r['CAP'];
            $purchased[$key] = ($purchased[$key] ?? 0) - (float) $r['QTY'];
        }

        return $purchased;
    }

    /**
     * الكميات الناقصة في المخزن
     * Quantities short in the store.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, string>
     */
    private function availabilityShortages(int $storeNo, array $lines): array
    {
        $needed = [];

        foreach ($lines as $line) {
            $needed[$line['sanf_no']] = ($needed[$line['sanf_no']] ?? 0) + $line['qty'];
        }

        $available = InventoryLineGuard::availableQuantities($storeNo, array_keys($needed));
        $shortages = [];

        foreach ($needed as $sanfNo => $qty) {
            $onHand = $available[$sanfNo] ?? 0.0;

            if (round($onHand - $qty, 3) < 0) {
                $shortages[] = "الصنف [{$sanfNo}]: المتاح ".$onHand.' والمطلوب '.$qty;
            }
        }

        return $shortages;
    }

    /**
     * حارس حذف المردود — يمنع أن يصبح مردود الفاتورة أكثر من مشترياتها
     * The return-delete guard — prevents the invoice's returned quantity from exceeding its purchase.
     *
     * @param  array<string, mixed>  $header
     */
    private function assertReturnsStillCovered(array $header): void
    {
        // لا شيء يُفحص هنا اليوم: حذف مردود ينقص المردود الكلي ولا يزيده.
        // Nothing to check today: deleting a return lowers the total returned, it never raises it.
    }

    /**
     * صف المردود بمفاتيح كبيرة
     * The return row with uppercase keys.
     *
     * @return array<string, mixed>|null
     */
    private function returnHeader(int $returnNo, int $year): ?array
    {
        $row = DB::connection('oracle')->selectOne(
            'SELECT r.*,
                    TO_CHAR(r.RET_DATE, \'YYYY-MM-DD\') AS RET_DATE_ISO,
                    TO_CHAR(r.CREATED_ON, \'YYYY-MM-DD\') AS CREATED_ON_ISO
               FROM ACCOUNTS.RETURN_BUY r
              WHERE r.RETURN_NO = :ret AND r.YEAR = :year',
            ['ret' => $returnNo, 'year' => $year]
        );

        return $this->upperRow($row);
    }

    /**
     * صف المردود في القائمة
     * A return row in the list.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toSummary(array $row): array
    {
        $net = (float) ($this->num($row, 'NET') ?? 0);
        $rate = (float) ($this->num($row, 'CUR_PRICE') ?? 1);

        return [
            'return_no' => $this->int($row, 'RETURN_NO'),
            'year' => $this->int($row, 'YEAR'),
            'bill_no' => $this->int($row, 'BILL_NO'),
            'bill_year' => $this->int($row, 'BILL_YEAR'),
            'bill_type' => $this->int($row, 'BILL_TYPE'),
            'date' => $this->str($row, 'RET_DATE_ISO'),
            'manual_return_no' => $this->int($row, 'MANUAL_RETURN_NO'),
            'prov_no' => $this->int($row, 'PROV_NO'),
            'prov_name' => $this->str($row, 'PROV_NAME') ?? $this->str($row, 'PARTY_NAME'),
            'store_no' => $this->int($row, 'STORE_NO'),
            'store_name' => $this->str($row, 'STORE_NAME'),
            'total' => round((float) ($this->num($row, 'RETURN_TOTAL') ?? 0), 2),
            'discount' => round((float) ($this->num($row, 'DISCOUNT') ?? 0), 2),
            'net' => round($net, 2),
            'cur_no' => $this->int($row, 'CUR_NO'),
            'cur_price' => $rate,
            'net_base' => round($net * $rate, 2),
            'entry_no' => $this->int($row, 'ENTRY_NO'),
            'posted' => $this->int($row, 'ENTRY_NO') !== null,
            'branch_no' => $this->int($row, 'BRANCH_NO'),
        ];
    }

    /**
     * سطر مردود في الاستعراض
     * A return line in the detail view.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toLine(array $row): array
    {
        return [
            'seq' => $this->int($row, 'SEQ'),
            'sanf_no' => $this->int($row, 'SANF_NO'),
            'sanf_name' => $this->str($row, 'SANF_NAME'),
            'sanf_dno' => $this->int($row, 'SANF_DNO'),
            'sub_name' => $this->str($row, 'SANF_DNAME'),
            'cap' => (float) ($this->num($row, 'CAP') ?? 1),
            'qty' => (float) ($this->num($row, 'RET_QTY') ?? 0),
            'pcs' => (float) ($this->num($row, 'PCS') ?? 0),
            'price' => (float) ($this->num($row, 'SANF_PRICE') ?? 0),
            'total' => round((float) ($this->num($row, 'TOTAL') ?? 0), 2),
            'discount_rate' => (float) ($this->num($row, 'DISCOUNT_RATE') ?? 0),
            'discount' => round((float) ($this->num($row, 'DISCOUNT1') ?? 0), 2),
            'stk_cost' => (float) ($this->num($row, 'STK_COST') ?? 0),
            'seq_sales' => $this->int($row, 'SEQ_SALES'),
            'category' => $this->str($row, 'CATEGORY'),
        ];
    }

    /**
     * مجاميع مشتقة من السطور
     * Totals derived from the lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, float|int>
     */
    private function lineTotals(array $lines, float $headerDiscount): array
    {
        $total = 0.0;
        $discount = 0.0;
        $base = 0.0;

        foreach ($lines as $line) {
            $total += (float) $line['total'];
            $discount += (float) $line['discount'];
            $base += (float) $line['total'] * (float) $line['stk_cost'] / max((float) $line['price'], 0.0001);
        }

        return [
            'lines_total' => round($total, 2),
            'lines_discount' => round($discount, 2),
            'header_discount' => round($headerDiscount, 2),
            'net' => round($total - max($discount, 0) - max($headerDiscount, 0), 2),
            'net_base' => round($base, 2),
            'count' => count($lines),
        ];
    }

    /** فلاتر القائمة / the list filters */
    private function applyListFilters($query, Request $request): void
    {
        $search = trim((string) ($request->input('search') ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                if (is_numeric($search)) {
                    $n = (int) $search;
                    $q->where('r.RETURN_NO', $n)
                        ->orWhere('r.BILL_NO', $n)
                        ->orWhere('r.MANUAL_RETURN_NO', $n);
                }

                $needle = $this->arabicNeedle($search);
                $q->orWhere('r.PROV_NAME', 'LIKE', '%'.$needle.'%')
                    ->orWhere('r.PROV_NAME', 'LIKE', '%'.$search.'%');
            });
        }

        if (($from = $this->dateInput($request, 'from_date')) !== null) {
            $query->whereRaw('r.RET_DATE >= TO_DATE(?, \'YYYY-MM-DD\')', [$from]);
        }

        if (($to = $this->dateInput($request, 'to_date')) !== null) {
            $query->whereRaw('r.RET_DATE < TO_DATE(?, \'YYYY-MM-DD\') + 1', [$to]);
        }

        foreach (['year' => 'r.YEAR', 'bill_type' => 'r.BILL_TYPE', 'store_no' => 'r.STORE_NO', 'bill_no' => 'r.BILL_NO'] as $input => $column) {
            $value = $request->input($input);

            if ($value !== null && $value !== '') {
                $query->where($column, (int) $value);
            }
        }

        if (($curNo = $request->input('cur_no')) !== null && $curNo !== '') {
            $query->where('r.CUR_NO', (int) $curNo);
        }
    }

    /**
     * الفرز بقائمة بيضاء
     * A closed sort whitelist.
     */
    private function resolveSort(Request $request): string
    {
        $allowed = [
            'date' => 'r.RET_DATE',
            'return_no' => 'r.RETURN_NO',
            'bill_no' => 'r.BILL_NO',
            'supplier' => 'r.PROV_NAME',
            'net' => 'r.NET',
        ];

        $key = (string) ($request->input('sort') ?? 'date');
        $column = $allowed[$key] ?? $allowed['date'];
        $dir = strtolower((string) ($request->input('dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        return $column.' '.$dir;
    }

    /**
     * قيم مميزة لعمود رقمي
     * Distinct values of a numeric column.
     *
     * @return array<int, int>
     */
    private function distinctInts($query, string $column): array
    {
        $values = [];
        $alias = strtoupper(substr($column, strpos($column, '.') + 1));

        foreach ($query->whereNotNull($column)->distinct()->select($column)->orderBy($column)->get() as $row) {
            $r = array_change_key_case((array) $row, CASE_UPPER);
            $value = $r[$alias] ?? reset($r);

            if ($value !== null) {
                $values[] = (int) $value;
            }
        }

        return array_values(array_unique($values));
    }
}
