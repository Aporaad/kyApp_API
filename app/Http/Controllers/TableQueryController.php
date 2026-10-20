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
     * جداول ممنوعة حتى على صلاحية النظام: كلها جداول اعتمادات أو جلسة أو بنية داخلية، وقيمتها
     * الكاملة قابلة للتسريب في استعلام واحد (كلمة مرور، توكن، محفظة كاش).
     * Tables denied even to the system wildcard: each is a credential, session, or internal
     * infrastructure table whose entire contents leak in a single query (password, token, cache).
     */
    private const DENIED_TABLES = [
        'APP_USERS',
        'PERSONAL_ACCESS_TOKENS',
        'APP_USERS_PERMISSIONS',
        'CACHE',
        'CACHE_LOCKS',
        'MIGRATIONS',
        'JOBS',
        'FAILED_JOBS',
        'SESSIONS',
        'PASSWORD_RESET_TOKENS',
        'USERS',
    ];

    /**
     * أعمدة تُحذف من أي صف قبل إرجاعه: البادئة/الطول لأن أسماء هذا المشروع مشتقة منها
     * Columns stripped from any row before it is returned: prefix/length because this project's
     * column names are all derived from these tokens.
     */
    private const DENIED_COLUMNS = [
        'PASSWORD',
        'DEVICE_TOKEN',
        'TOKEN',
        'SECRET',
        'KEY',
    ];

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

        // الرفض قبل أي وصول للقاعدة: اسم الجدول وحده كافٍ للقرار، فلا داعي لاستعلام وجوده
        // Deny before any database touch: the table name alone is enough to decide, so there is no
        // reason to run an existence query for a table we will not read.
        if (in_array($tableName, self::DENIED_TABLES, true)) {
            return response()->json([
                'success' => false,
                'message' => "الجدول [{$tableName}] محجوب عن هذا المسار لأنه يحمل بيانات اعتماد أو جلسات أو بنية داخلية / Table [{$tableName}] is denied: it holds credentials, session, or internal infrastructure data.",
                'errors' => ['table_name' => ['restricted_table']],
            ], 403);
        }

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

            // تنقيح الأعمدة الحساسة: الجدول غير ممنوع قد يبقى حاملاً عمود اعتماد (كلمة مرور مخزّنة
            // في جدول عمل، أو توكن في جدول جلسة مخصص). الحذف هنا لا على اسم الجدول كي لا يُفاجأ
            // المستخدم بصفّ ناقص في جدول يظنه بريئاً.
            // Sensitive-column scrubbing: an allowed table may still carry a credential column
            // (a stored password on a business table, a token on a session-ish one). We drop by
            // column name, not by table name, so a table the user believes is benign cannot
            // surprise them with a silently missing field later.
            [$rows, $redacted] = $this->redactSensitiveColumns($data);

            return response()->json([
                'success' => true,
                'table' => $tableName,
                'count' => $data->count(),
                'limit' => $limit,
                'redacted_columns' => $redacted,
                'data' => $rows,
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

    /**
     * إزالة الأعمدة الحساسة من صفوف الاستعلام مع إبقاء ترتيب الأعمدة الباقي كما هو
     * Strip sensitive columns from the fetched rows, keeping the remaining column order intact.
     *
     * @param  iterable<int, object>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     *                                                                           الصفوف المنقّحة + أسماء الأعمدة التي حُذفت (مرّة واحدة، لا لكل صف)
     *                                                                           The scrubbed rows + the distinct column names that were dropped (listed once, not per row)
     */
    private function redactSensitiveColumns(iterable $rows): array
    {
        $clean = [];
        $dropped = [];

        foreach ($rows as $row) {
            $asArray = (array) $row;
            $cleanRow = [];

            foreach ($asArray as $column => $value) {
                $upper = strtoupper((string) $column);

                $isSensitive = false;
                foreach (self::DENIED_COLUMNS as $needle) {
                    if (str_contains($upper, $needle)) {
                        $isSensitive = true;
                        $dropped[$upper] = true;

                        break;
                    }
                }

                if (! $isSensitive) {
                    $cleanRow[$column] = $value;
                }
            }

            $clean[] = $cleanRow;
        }

        return [$clean, array_keys($dropped)];
    }
}
