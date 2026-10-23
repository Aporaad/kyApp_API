<?php

namespace App\Http\Controllers\Services;

use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * متحكّم إدارة المهام الزمنية عبر `DBMS_SCHEDULER` (أوراكل)
 * Scheduler-job management via Oracle DBMS_SCHEDULER.
 *
 * كل العملية تمرّ عبر `yajra-oci8` بحزم PL/SQL ومُربوطات (bind) — لا واجهات سطر أوامر ولا عامل
 * طابور جديد. الأكواد محروسة عبر المسارات (`jobs.view/create/update/delete/toggle`)، والتسمية
 * تُتحقّق منها بنمط معرّف أوراكل قبل تمريرها كمُربوط إلى `DBMS_SCHEDULER`.
 * Everything runs through yajra-oci8 as PL/SQL with bind variables — no CLI, no new queue worker.
 * Route middleware guards each code; job names are validated against an Oracle identifier pattern
 * before being passed as a bind into DBMS_SCHEDULER.
 */
class JobController extends ServiceBaseController
{
    /** نمط معرّف مهمة أوراكل المسموح / allowed Oracle job identifier pattern */
    private const NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,127}$/';

    /**
     * قائمة المهام المجدولة (افتراضياً مهام الرسائل) + سجل التشغيل عند الطلب
     * Scheduler job list (message jobs by default); run details are separate
     */
    public function index(Request $request): JsonResponse
    {
        $all = filter_var($request->input('all'), FILTER_VALIDATE_BOOLEAN);

        try {
            $query = DB::connection('oracle')->table('USER_SCHEDULER_JOBS')
                ->select([
                    'JOB_NAME', 'ENABLED', 'REPEAT_INTERVAL', 'JOB_TYPE',
                    'JOB_ACTION', 'STATE', 'LAST_START_DATE', 'NEXT_RUN_DATE',
                ]);

            if (! $all) {
                // الحصر الافتراضي في مهام الرسائل الثلاث المعروفة
                // Default scope to the three known messaging jobs.
                $query->whereIn('JOB_NAME', ['SEND_MSG', 'W_SEND_MSG', 'W_SEND_MSG_F']);
            }

            $jobs = $query->orderBy('JOB_NAME')->get();

            $data = array_map(static fn ($r): array => (array) $r, $jobs->all());

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة المهام', $e);
        }
    }

    /**
     * سجل تشغيل مهمة (قراءة فقط) مع ترقيم صفحي
     * Read-only run history for a job, paginated
     */
    public function history(Request $request, string $name): JsonResponse
    {
        if (! $this->validName($name)) {
            return $this->badName($name);
        }

        [$page, $limit, $offset] = $this->pagination($request);

        try {
            $query = DB::connection('oracle')->table('USER_SCHEDULER_JOB_RUN_DETAILS')
                ->select(['LOG_DATE', 'RUN_DURATION', 'SUCCEEDED', 'STATUS', 'ERROR#', 'ADDITIONAL_INFO'])
                ->where('JOB_NAME', strtoupper($name));

            $total = $query->count();
            $rows = $query->orderByDesc('LOG_DATE')->offset($offset)->limit($limit)->get();

            return response()->json([
                'success' => true,
                'data' => array_map(static fn ($r): array => (array) $r, $rows->all()),
                'meta' => $this->meta($total, $page, $limit),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في جلب سجل تشغيل المهمة', $e);
        }
    }

    /**
     * إنشاء مهمة مجدولة جديدة
     * Create a new scheduler job
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'job_name' => ['required', 'string', 'max:128'],
            'job_action' => ['required', 'string'],
            'repeat_interval' => ['required', 'string', 'max:256'],
            'enabled' => ['nullable', 'boolean'],
        ], [
            'job_name.required' => 'اسم المهمة مطلوب / Job name is required.',
            'job_action.required' => 'أمر المهمة مطلوب / Job action is required.',
            'repeat_interval.required' => 'المهلة مطلوبة / Repeat interval is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المهمة غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = strtoupper(trim((string) $request->input('job_name')));
        if (! $this->validName($name)) {
            return $this->badName($name);
        }

        try {
            // `enabled` قيمة منطقية PL/SQL تُدمج حرفياً (TRUE/FALSE) — لا يمكن ربطها كمُربوط OCI8،
            // والقيمة مشتقّة من boolean ولا تأتي من نص المستخدم.
            // `enabled` is a PL/SQL boolean: it must be an inline TRUE/FALSE literal (not an OCI8 bind),
            // derived from the request boolean rather than user text.
            $enabledLiteral = $request->boolean('enabled', true) ? 'TRUE' : 'FALSE';

            DB::connection('oracle')->statement(
                "BEGIN DBMS_SCHEDULER.CREATE_JOB(
                    job_name => :name,
                    job_type => 'PLSQL_BLOCK',
                    job_action => :action,
                    repeat_interval => :interval,
                    enabled => {$enabledLiteral},
                    comments => 'Created via KY app');
                 END;",
                [
                    'name' => $name,
                    'action' => (string) $request->input('job_action'),
                    'interval' => (string) $request->input('repeat_interval'),
                ]
            );

            $this->log($request, 'jobs.create', "إنشاء مهمة {$name}");

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء المهمة / Job created.',
                'job_name' => $name,
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في إنشاء المهمة', $e);
        }
    }

    /**
     * تعديل المهلة أو أمر المهمة
     * Update the job's repeat interval or action
     */
    public function update(Request $request, string $name): JsonResponse
    {
        $name = strtoupper($name);
        if (! $this->validName($name)) {
            return $this->badName($name);
        }

        $validator = Validator::make($request->all(), [
            'repeat_interval' => ['nullable', 'string', 'max:256'],
            'job_action' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات المهمة غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if ($request->filled('repeat_interval')) {
                DB::connection('oracle')->statement(
                    'BEGIN DBMS_SCHEDULER.SET_ATTRIBUTE(:name, \'REPEAT_INTERVAL\', :value); END;',
                    ['name' => $name, 'value' => (string) $request->input('repeat_interval')]
                );
            }

            if ($request->filled('job_action')) {
                DB::connection('oracle')->statement(
                    'BEGIN DBMS_SCHEDULER.SET_ATTRIBUTE(:name, \'JOB_ACTION\', :value); END;',
                    ['name' => $name, 'value' => (string) $request->input('job_action')]
                );
            }

            $this->log($request, 'jobs.update', "تعديل مهمة {$name}");

            return response()->json(['success' => true, 'message' => 'تم تعديل المهمة / Job updated.']);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تعديل المهمة', $e);
        }
    }

    /**
     * تفعيل/تعطيل المهمة
     * Enable/disable the job
     */
    public function toggle(Request $request, string $name): JsonResponse
    {
        $name = strtoupper($name);
        if (! $this->validName($name)) {
            return $this->badName($name);
        }

        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير صالحة / Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $enable = $request->boolean('enabled');
            $proc = $enable ? 'ENABLE' : 'DISABLE';

            DB::connection('oracle')->statement(
                "BEGIN DBMS_SCHEDULER.{$proc}(:name); END;",
                ['name' => $name]
            );

            $this->log($request, 'jobs.toggle', ($enable ? 'تفعيل' : 'تعطيل')." مهمة {$name}");

            return response()->json([
                'success' => true,
                'message' => $enable ? 'تم تفعيل المهمة / Job enabled.' : 'تم تعطيل المهمة / Job disabled.',
                'enabled' => $enable,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('خطأ في تبديل حالة المهمة', $e);
        }
    }

    /**
     * حذف مهمة (تُرفض إن كانت قيد التشغيل)
     * Drop a job (refused while running)
     */
    public function destroy(Request $request, string $name): JsonResponse
    {
        $name = strtoupper($name);
        if (! $this->validName($name)) {
            return $this->badName($name);
        }

        try {
            // FORCE => false: يرفض الحذف إذا كانت المهمة قيد التشغيل
            // FORCE => false: refuses to drop a currently-running job.
            DB::connection('oracle')->statement(
                'BEGIN DBMS_SCHEDULER.DROP_JOB(job_name => :name, defer => false, force => false); END;',
                ['name' => $name]
            );

            $this->log($request, 'jobs.delete', "حذف مهمة {$name}");

            return response()->json(['success' => true, 'message' => 'تم حذف المهمة / Job deleted.']);
        } catch (\Throwable $e) {
            // رسالة مسموعة ثنائية اللغة عند فشل الحذف (قد تكون قيد التشغيل)
            // Bilingual, actionable message (commonly "job is running").
            return response()->json([
                'success' => false,
                'message' => 'تعذّر حذف المهمة — قد تكون قيد التشغيل / Could not drop the job — it may be running.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 409);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function validName(string $name): bool
    {
        return preg_match(self::NAME_REGEX, $name) === 1;
    }

    private function badName(string $name): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'اسم المهمة غير صالح — يُقبل معرّف أوراكل فقط / Invalid job name (Oracle identifier only).',
        ], 422);
    }

    private function log(Request $request, string $code, string $desc): void
    {
        app(ActivityLogger::class)->success($code, [
            'user_id' => $request->user()?->getAttribute('USER_ID'),
            'module_code' => 'jobs',
            'entity_type' => 'SCHEDULER_JOB',
            'description_ar' => $desc,
        ], $request);
    }
}
