<?php

namespace App\Http\Controllers;

use App\Support\ArabicEncoding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CurrencyController
 * إدارة العملات وأسعار الصرف
 *
 * جداول قاعدة البيانات:
 * - CURRENCY  : العملات (CUR_NO, CUR_NAME)
 * - CUR_CODE  : رموز العملات (CUR_NO, CODE, MAIN_CUR_NAME, SUB_CUR_NAME)
 * - CUR_PRICE : أسعار الصرف (CUR_NO, PRICE, DAY_DATE, SEQ)
 */
class CurrencyController extends Controller
{
    /**
     * GET /currencies
     * جلب جميع العملات مع رموزها وآخر سعر صرف
     */
    public function index()
    {
        try {
            $rows = DB::connection('oracle')->select(
                "SELECT c.CUR_NO, c.CUR_NAME,
                        cc.CODE, cc.MAIN_CUR_NAME, cc.SUB_CUR_NAME
                 FROM CURRENCY c
                 LEFT JOIN CUR_CODE cc ON c.CUR_NO = cc.CUR_NO
                 ORDER BY c.CUR_NO"
            );

            $data = [];
            foreach ($rows as $row) {
                $r = (array) $row;
                $curNo = $r['CUR_NO'] ?? $r['cur_no'] ?? null;
                $curName = ArabicEncoding::fixMojibake($r['CUR_NAME'] ?? $r['cur_name'] ?? '');
                $code = $r['CODE'] ?? $r['code'] ?? '';
                $mainCurName = ArabicEncoding::fixMojibake($r['MAIN_CUR_NAME'] ?? $r['main_cur_name'] ?? '');
                $subCurName = ArabicEncoding::fixMojibake($r['SUB_CUR_NAME'] ?? $r['sub_cur_name'] ?? '');

                // جلب آخر سعرين للصرف متوافق مع Oracle 11g
                $prices = DB::connection('oracle')->select(
                    "SELECT PRICE, TO_CHAR(DAY_DATE, 'YYYY-MM-DD') as DAY_DATE
                     FROM (
                         SELECT PRICE, DAY_DATE
                         FROM CUR_PRICE
                         WHERE CUR_NO = :cur_no
                         ORDER BY SEQ DESC
                     )
                     WHERE ROWNUM <= 2",
                    ['cur_no' => $curNo]
                );

                $currentPrice = 0.0;
                $previousPrice = 0.0;
                $lastDate = null;

                if (!empty($prices)) {
                    $p0 = (array) $prices[0];
                    $currentPrice = (float) ($p0['PRICE'] ?? $p0['price'] ?? 0);
                    $lastDate = $p0['DAY_DATE'] ?? $p0['day_date'] ?? null;

                    if (count($prices) > 1) {
                        $p1 = (array) $prices[1];
                        $previousPrice = (float) ($p1['PRICE'] ?? $p1['price'] ?? 0);
                    }
                }

                $changePercent = $previousPrice > 0
                    ? round((($currentPrice - $previousPrice) / $previousPrice) * 100, 2)
                    : 0;

                $data[] = [
                    'cur_no'         => (int) $curNo,
                    'cur_name'       => $curName,
                    'code'           => $code,
                    'main_cur_name'  => $mainCurName,
                    'sub_cur_name'   => $subCurName,
                    'current_price'  => $currentPrice,
                    'previous_price' => $previousPrice,
                    'change_percent' => $changePercent,
                    'last_date'      => $lastDate,
                ];
            }

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /currencies
     * إضافة عملة جديدة
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'cur_name'      => 'required|string|max:30',
                'code'          => 'required|string|max:30',
                'main_cur_name' => 'required|string|max:30',
                'sub_cur_name'  => 'required|string|max:30',
            ]);

            $maxRow = DB::connection('oracle')->selectOne("SELECT MAX(CUR_NO) as MAX_NO FROM CURRENCY");
            $maxRowArr = (array) $maxRow;
            $maxNo = $maxRowArr['MAX_NO'] ?? $maxRowArr['max_no'] ?? 0;
            $nextNo = $maxNo ? ((int)$maxNo + 1) : 1;

            $curName = ArabicEncoding::encodeForOracle($request->input('cur_name'));
            $mainName = ArabicEncoding::encodeForOracle($request->input('main_cur_name'));
            $subName = ArabicEncoding::encodeForOracle($request->input('sub_cur_name'));

            DB::connection('oracle')->insert(
                "INSERT INTO CURRENCY (CUR_NO, CUR_NAME) VALUES (:no, :name)",
                ['no' => $nextNo, 'name' => $curName]
            );

            DB::connection('oracle')->insert(
                "INSERT INTO CUR_CODE (CUR_NO, CODE, MAIN_CUR_NAME, SUB_CUR_NAME)
                 VALUES (:no, :code, :main, :sub)",
                [
                    'no'   => $nextNo,
                    'code' => $request->input('code'),
                    'main' => $mainName,
                    'sub'  => $subName,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة العملة بنجاح',
                'data'    => ['cur_no' => $nextNo],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /currencies/{curNo}
     * تعديل بيانات عملة
     */
    public function update(Request $request, $curNo)
    {
        try {
            $existing = DB::connection('oracle')->selectOne("SELECT CUR_NO FROM CURRENCY WHERE CUR_NO = :no", ['no' => $curNo]);
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'العملة غير موجودة'], 404);
            }

            if ($request->has('cur_name')) {
                $curName = ArabicEncoding::encodeForOracle($request->input('cur_name'));
                DB::connection('oracle')->update(
                    "UPDATE CURRENCY SET CUR_NAME = :name WHERE CUR_NO = :no",
                    ['name' => $curName, 'no' => $curNo]
                );
            }

            $codeFields = [];
            $codeParams = ['no' => $curNo];
            if ($request->has('code')) {
                $codeFields[] = "CODE = :code";
                $codeParams['code'] = $request->input('code');
            }
            if ($request->has('main_cur_name')) {
                $codeFields[] = "MAIN_CUR_NAME = :main";
                $codeParams['main'] = ArabicEncoding::encodeForOracle($request->input('main_cur_name'));
            }
            if ($request->has('sub_cur_name')) {
                $codeFields[] = "SUB_CUR_NAME = :sub";
                $codeParams['sub']  = ArabicEncoding::encodeForOracle($request->input('sub_cur_name'));
            }

            if (!empty($codeFields)) {
                DB::connection('oracle')->update(
                    "UPDATE CUR_CODE SET " . implode(', ', $codeFields) . " WHERE CUR_NO = :no",
                    $codeParams
                );
            }

            return response()->json(['success' => true, 'message' => 'تم تعديل العملة بنجاح']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /currencies/{curNo}
     * حذف عملة (مع التحقق من عدم وجود بيانات مرتبطة)
     */
    public function destroy($curNo)
    {
        try {
            $existing = DB::connection('oracle')->selectOne("SELECT CUR_NO FROM CURRENCY WHERE CUR_NO = :no", ['no' => $curNo]);
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'العملة غير موجودة'], 404);
            }

            // حذف أسعار الصرف أولاً
            DB::connection('oracle')->delete("DELETE FROM CUR_PRICE WHERE CUR_NO = :no", ['no' => $curNo]);
            DB::connection('oracle')->delete("DELETE FROM CUR_CODE WHERE CUR_NO = :no",  ['no' => $curNo]);
            DB::connection('oracle')->delete("DELETE FROM CURRENCY WHERE CUR_NO = :no",  ['no' => $curNo]);

            return response()->json(['success' => true, 'message' => 'تم حذف العملة بنجاح']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /currencies/{curNo}/prices
     * جلب سجل أسعار الصرف لعملة محددة
     * Query params: page, limit
     */
    public function getPrices(Request $request, $curNo)
    {
        try {
            $page   = max(1, (int)$request->query('page', 1));
            $limit  = min(100, max(10, (int)$request->query('limit', 30)));
            $offset = ($page - 1) * $limit;
            $maxRow = $offset + $limit;

            $existing = DB::connection('oracle')->selectOne("SELECT CUR_NO, CUR_NAME FROM CURRENCY WHERE CUR_NO = :no", ['no' => $curNo]);
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'العملة غير موجودة'], 404);
            }
            $existingArr = (array) $existing;

            $countRow = DB::connection('oracle')->selectOne(
                "SELECT COUNT(*) as TOTAL FROM CUR_PRICE WHERE CUR_NO = :no",
                ['no' => $curNo]
            );
            $countArr = (array) $countRow;
            $total = (int) ($countArr['TOTAL'] ?? $countArr['total'] ?? 0);

            // استعلام الترقيم المتوافق مع Oracle 11g
            $sql = "
                SELECT * FROM (
                    SELECT a.*, ROWNUM rnum FROM (
                        SELECT SEQ, CUR_NO, PRICE, TO_CHAR(DAY_DATE,'YYYY-MM-DD') as DAY_DATE
                        FROM CUR_PRICE
                        WHERE CUR_NO = :no
                        ORDER BY SEQ DESC
                    ) a
                    WHERE ROWNUM <= :max_row
                )
                WHERE rnum > :offset
            ";

            $rows = DB::connection('oracle')->select($sql, [
                'no'      => $curNo,
                'max_row' => $maxRow,
                'offset'  => $offset,
            ]);

            $data = array_map(function($row) {
                $r = (array) $row;
                return [
                    'seq'      => (int) ($r['SEQ'] ?? $r['seq'] ?? 0),
                    'cur_no'   => (int) ($r['CUR_NO'] ?? $r['cur_no'] ?? 0),
                    'price'    => (float) ($r['PRICE'] ?? $r['price'] ?? 0),
                    'day_date' => $r['DAY_DATE'] ?? $r['day_date'] ?? null,
                ];
            }, $rows);

            return response()->json([
                'success'  => true,
                'currency' => [
                    'cur_no'   => (int) ($existingArr['CUR_NO'] ?? $existingArr['cur_no'] ?? $curNo),
                    'cur_name' => ArabicEncoding::fixMojibake($existingArr['CUR_NAME'] ?? $existingArr['cur_name'] ?? ''),
                ],
                'data'     => $data,
                'meta'     => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 0,
                    'has_more'    => ($page * $limit) < $total,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /currencies/{curNo}/prices
     * إضافة سعر صرف جديد لعملة
     */
    public function addPrice(Request $request, $curNo)
    {
        try {
            $request->validate([
                'price'    => 'required|numeric|min:0',
                'day_date' => 'required|date_format:Y-m-d',
            ]);

            $existing = DB::connection('oracle')->selectOne("SELECT CUR_NO FROM CURRENCY WHERE CUR_NO = :no", ['no' => $curNo]);
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'العملة غير موجودة'], 404);
            }

            $maxSeqRow = DB::connection('oracle')->selectOne("SELECT MAX(SEQ) as MAX_SEQ FROM CUR_PRICE");
            $maxSeqArr = (array) $maxSeqRow;
            $maxSeq = $maxSeqArr['MAX_SEQ'] ?? $maxSeqArr['max_seq'] ?? 0;
            $nextSeq = $maxSeq ? ((int)$maxSeq + 1) : 1;

            DB::connection('oracle')->insert(
                "INSERT INTO CUR_PRICE (CUR_NO, PRICE, DAY_DATE, SEQ)
                 VALUES (:cur_no, :price, TO_DATE(:day_date, 'YYYY-MM-DD'), :seq)",
                [
                    'cur_no'   => $curNo,
                    'price'    => $request->input('price'),
                    'day_date' => $request->input('day_date'),
                    'seq'      => $nextSeq,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة سعر الصرف بنجاح',
                'data'    => ['seq' => $nextSeq],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /currencies/{curNo}/prices/{seq}
     * تعديل سعر صرف محدد
     */
    public function updatePrice(Request $request, $curNo, $seq)
    {
        try {
            $request->validate([
                'price'    => 'required|numeric|min:0',
                'day_date' => 'required|date_format:Y-m-d',
            ]);

            $existing = DB::connection('oracle')->selectOne(
                "SELECT SEQ FROM CUR_PRICE WHERE CUR_NO = :no AND SEQ = :seq",
                ['no' => $curNo, 'seq' => $seq]
            );
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'سعر الصرف غير موجود'], 404);
            }

            DB::connection('oracle')->update(
                "UPDATE CUR_PRICE SET PRICE = :price, DAY_DATE = TO_DATE(:day_date, 'YYYY-MM-DD')
                 WHERE CUR_NO = :no AND SEQ = :seq",
                [
                    'price'    => $request->input('price'),
                    'day_date' => $request->input('day_date'),
                    'no'       => $curNo,
                    'seq'      => $seq,
                ]
            );

            return response()->json(['success' => true, 'message' => 'تم تعديل سعر الصرف بنجاح']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /currencies/{curNo}/prices/{seq}
     * حذف سعر صرف محدد
     */
    public function deletePrice($curNo, $seq)
    {
        try {
            $existing = DB::connection('oracle')->selectOne(
                "SELECT SEQ FROM CUR_PRICE WHERE CUR_NO = :no AND SEQ = :seq",
                ['no' => $curNo, 'seq' => $seq]
            );
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'سعر الصرف غير موجود'], 404);
            }

            DB::connection('oracle')->delete("DELETE FROM CUR_PRICE WHERE CUR_NO = :no AND SEQ = :seq", ['no' => $curNo, 'seq' => $seq]);
            return response()->json(['success' => true, 'message' => 'تم حذف سعر الصرف بنجاح']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
