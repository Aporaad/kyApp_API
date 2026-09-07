<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكم للاستعلام الديناميكي عن بيانات الجداول مع تطبيق معايير الأمان العالية
 * Controller for dynamically querying table data with high security standards
 */
class TableQueryController extends Controller
{
    /**
     * الحد الأقصى لعدد الصفوف المسموح بطلبها لحماية أداء السيرفر
     * Maximum allowed rows limit to prevent server overload
     */
    private const MAX_ROW_LIMIT = 1000;

    /**
     * الحد الافتراضي لعدد الصفوف في حال عدم تحديده
     * Default row limit if not specified in the request
     */
    private const DEFAULT_ROW_LIMIT = 50;

    /**
     * استعلام البيانات من جدول محدد بعدد صفوف معين
     * Fetch records from a specified table with a given row count
     */
    public function selectFromTable(Request $request): JsonResponse
    {
        // استخراج المتغيرات مع دعم الأسماء المتعددة الشائعة لتسهيل الاستخدام
        // Extract parameters supporting common aliases for flexibility
        $rawTableName = $request->input('table_name')
            ?? $request->input('table')
            ?? $request->input('tableName')
            ?? $request->input('tbl');

        $rawLimit = $request->input('limit')
            ?? $request->input('rows')
            ?? $request->input('row_count')
            ?? $request->input('rowCount')
            ?? $request->input('count')
            ?? self::DEFAULT_ROW_LIMIT;

        // التحقق الأمني من المدخلات لمنع هجمات الحقن
        // Security validation to prevent injection attacks
        $validator = Validator::make([
            'table_name' => $rawTableName,
            'limit' => $rawLimit,
        ], [
            'table_name' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/', 'max:30'],
            'limit' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ROW_LIMIT],
        ], [
            'table_name.required' => 'اسم الجدول مطلوب / Table name is required.',
            'table_name.regex' => 'اسم الجدول يحتوي على محارف غير صالحة / Invalid table name format.',
            'table_name.max' => 'اسم الجدول يتجاوز الطول المسموح به في أوراكل / Table name exceeds max length.',
            'limit.integer' => 'عدد الصفوف يجب أن يكون رقماً صحيحاً / Limit must be an integer.',
            'limit.min' => 'عدد الصفوف يجب أن يكون 1 على الأقل / Limit must be at least 1.',
            'limit.max' => 'الحد الأقصى لعدد الصفوف هو '.self::MAX_ROW_LIMIT.' / Maximum limit is '.self::MAX_ROW_LIMIT.'.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الطلب غير صالحة / Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // تحويل اسم الجدول إلى أحرف كبيرة لمطابقة جداول أوراكل
        // Convert table name to uppercase to match Oracle naming convention
        $tableName = strtoupper(trim((string) $rawTableName));
        $limit = (int) $rawLimit;

        try {
            // التحقق من وجود الجدول داخل مخطط المستخدم في أوراكل قبل تنفيذ الاستعلام
            // Verify if the table exists in the connected Oracle user schema
            $tableExists = DB::connection('oracle')
                ->table('USER_TABLES')
                ->where('TABLE_NAME', $tableName)
                ->exists();

            if (! $tableExists) {
                return response()->json([
                    'success' => false,
                    'message' => "الجدول [{$tableName}] غير موجود في قاعدة البيانات / Table [{$tableName}] does not exist in the database schema.",
                ], 404);
            }

            // تنفيذ الاستعلام وجلب السجلات بعدد الصفوف المحدد
            // Execute the query and fetch the specified number of rows
            $data = DB::connection('oracle')
                ->table($tableName)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'table' => $tableName,
                'count' => $data->count(),
                'limit' => $limit,
                'data' => $data,
            ], 200);

        } catch (\Throwable $e) {
            // معالجة الأخطاء غير المتوقعة بأمان دون كشف تفاصيل داخلية حساسة
            // Safely handle unexpected errors without exposing sensitive internals
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء استعلام البيانات من قاعدة البيانات / Error querying data from database.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
