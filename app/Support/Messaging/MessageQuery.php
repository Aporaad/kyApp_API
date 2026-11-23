<?php

namespace App\Support\Messaging;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * استعلام سجل الرسائل — الفلترة والفرز والتقسيم كلها في أوراكل
 * The message-log query — filtering, sorting, and paging all happen inside Oracle.
 *
 * لماذا لا ندمج في PHP كما كان؟ النسخة القديمة كانت تجلب **كل** صفوف الطابور والأرشيف ثم تقصّ
 * النتيجة في الذاكرة، فلا فلترة خادمية ولا عدّادات، وأداؤها يتدهور مع كل صف جديد. هنا يُبنى
 * `UNION ALL` واحد يُطبَّق عليه الشرط والترتيب ثم `ROWNUM` (أوراكل 11g بلا `OFFSET/FETCH`).
 * Why not merge in PHP as before? The old code fetched *every* queue and archive row and then sliced
 * in memory — no server-side filtering, no counts, and a cost that grows with every row. Here one
 * `UNION ALL` carries the filter, the sort, then `ROWNUM` (11g has no OFFSET/FETCH).
 *
 * ملاحظة ترتيب: `CREATED_AT` يُعاد كنص `YYYY-MM-DD HH24:MI:SS` فيبقى ترتيبه الزمني صحيحاً
 * لغوياً — نفس الحيلة المستخدمة في بقية المشروع، وتُجنّبنا فروق المناطق الزمنية للتواريخ.
 * Sort note: `CREATED_AT` is emitted as `YYYY-MM-DD HH24:MI:SS` text, whose lexicographic order is
 * chronological — the same trick used elsewhere in this project.
 */
final class MessageQuery
{
    /** خريطة بيضاء لأعمدة الفرز — لا يُلصق أي اسم من الطلب */
    private const SORT_COLUMNS = [
        'created_at' => 'CREATED_AT',
        'id' => 'ID',
        'mobile' => 'MOBILE_NO',
        'attempts' => 'ATTEMPTS',
        'status' => 'STATUS_EFF',
    ];

    public function __construct(private readonly ChannelSpec $spec) {}

    /**
     * صفحة واحدة من السجل + الإجمالي + عدّادات الحالات
     * One page of the log plus the total and the per-status counts.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, counts: array<string, int>, page: int, limit: int}
     */
    public function paginate(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = ($page - 1) * $limit;

        $union = $this->union();
        $where = $this->where($filters, withStatus: true);
        $order = $this->orderBy($filters);

        $sql = "SELECT * FROM (
                    SELECT X.*, ROWNUM AS RN FROM (
                        SELECT * FROM ({$union}) U {$where} ORDER BY {$order}
                    ) X WHERE ROWNUM <= :max_row
                ) WHERE RN > :min_row";

        $rows = DB::connection('oracle')->select($sql, $this->bindings($filters, withStatus: true) + [
            'max_row' => $offset + $limit,
            'min_row' => $offset,
        ]);

        $total = (int) ($this->scalar(
            "SELECT COUNT(*) AS CNT FROM ({$union}) U {$where}",
            $this->bindings($filters, withStatus: true),
            'CNT'
        ) ?? 0);

        return [
            'rows' => array_map(fn (array $r): array => $this->present($r, fullText: false), array_map(
                static fn ($r): array => (array) $r,
                $rows
            )),
            'total' => $total,
            'counts' => $this->counts($filters),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * عدّادات الحالات تحت نفس الفلاتر (بلا فلتر الحالة نفسه، وإلا لكان كل عدّاد = صفراً أو الإجمالي)
     * Per-status counts under the same filters — without the status filter itself, otherwise every
     * counter would equal either zero or the total.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function counts(array $filters): array
    {
        $union = $this->union();
        $where = $this->where($filters, withStatus: false);

        $rows = DB::connection('oracle')->select(
            "SELECT STATUS_EFF, COUNT(*) AS CNT FROM ({$union}) U {$where} GROUP BY STATUS_EFF",
            $this->bindings($filters, withStatus: false)
        );

        $counts = array_fill_keys(MessageStatus::all(), 0);
        $all = 0;
        foreach ($rows as $row) {
            $arr = array_change_key_case((array) $row, CASE_UPPER);
            $key = strtolower((string) ($arr['STATUS_EFF'] ?? ''));
            $cnt = (int) ($arr['CNT'] ?? 0);
            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + $cnt;
            }
            $all += $cnt;
        }

        return ['all' => $all] + $counts;
    }

    /**
     * رسالة واحدة بالنص الكامل — تُبحث في الطابور أولاً ثم الأرشيف
     * A single message with its full text — queue first, then archive.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $union = $this->union();

        foreach (['queue', 'archive'] as $source) {
            $row = DB::connection('oracle')->selectOne(
                "SELECT * FROM ({$union}) U WHERE U.SRC = :src AND U.ID = :id",
                ['src' => $source, 'id' => $id]
            );

            if ($row) {
                return $this->present((array) $row, fullText: true);
            }
        }

        return null;
    }

    /**
     * سقف المحاولات المعروض — أقصى `SEND_ATTEMPT_CNT` بين الاشتراكات النشطة للقناة
     * The displayed attempt ceiling — the max SEND_ATTEMPT_CNT among the channel's active subs.
     */
    public function attemptLimit(): int
    {
        $types = implode(', ', array_map(
            static fn (string $t): string => "'".$t."'",
            $this->spec->serviceTypes
        ));

        $value = $this->scalar(
            "SELECT MAX(d.SEND_ATTEMPT_CNT) AS LIMIT_N
               FROM ACCOUNTS.PROVIDERS_DTL d
               JOIN ACCOUNTS.SERVICES s ON s.SERVICE_ID = d.SERVICE_ID
              WHERE d.STATUS = 1 AND s.SERVICE_TYPE IN ({$types})",
            [],
            'LIMIT_N'
        );

        return $value !== null ? (int) $value : 3;
    }

    /**
     * سجل المحاولات (واتساب فقط) — `W_MSG_ATTEMPT_LOG`
     * The attempt trail (WhatsApp only).
     *
     * @return list<array<string, mixed>>
     */
    public function attempts(int $id, int $limit = 50): array
    {
        if (! $this->spec->hasWorkerState) {
            return [];
        }

        $limit = min(max($limit, 1), 200);

        $rows = DB::connection('oracle')->select(
            "SELECT * FROM (
                SELECT LOG_ID, MSG_ID, ATTEMPT_NO, TO_CHAR(AT,'YYYY-MM-DD HH24:MI:SS') AS AT,
                       STAGE, RESULT_CODE, HTTP_CODE, PROVIDER_MSG_ID, DURATION_MS, ERROR_TEXT
                  FROM ACCOUNTS.W_MSG_ATTEMPT_LOG
                 WHERE MSG_ID = :id
                 ORDER BY LOG_ID DESC
             ) WHERE ROWNUM <= {$limit}",
            ['id' => $id]
        );

        return array_map(static function ($r): array {
            $row = ArabicEncoding::cleanData(array_change_key_case((array) $r, CASE_UPPER));

            return [
                'log_id' => (int) $row['LOG_ID'],
                'msg_id' => (int) $row['MSG_ID'],
                'attempt_no' => $row['ATTEMPT_NO'] !== null ? (int) $row['ATTEMPT_NO'] : null,
                'at' => $row['AT'],
                'stage' => $row['STAGE'],
                'result_code' => $row['RESULT_CODE'],
                'http_code' => $row['HTTP_CODE'] !== null ? (int) $row['HTTP_CODE'] : null,
                'provider_msg_id' => $row['PROVIDER_MSG_ID'],
                'duration_ms' => $row['DURATION_MS'] !== null ? (int) $row['DURATION_MS'] : null,
                'error_text' => $row['ERROR_TEXT'],
            ];
        }, $rows);
    }

    /**
     * تاريخ تغيّر الحالة (واتساب فقط) — `W_MSG_STATUS_HIST`
     * The status trail (WhatsApp only).
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $id, int $limit = 50): array
    {
        if (! $this->spec->hasWorkerState) {
            return [];
        }

        $limit = min(max($limit, 1), 200);

        $rows = DB::connection('oracle')->select(
            "SELECT * FROM (
                SELECT H_ID, MSG_ID, OLD_STATUS, NEW_STATUS,
                       TO_CHAR(AT,'YYYY-MM-DD HH24:MI:SS') AS AT, MODULE, ACTION_NAME, MACHINE
                  FROM ACCOUNTS.W_MSG_STATUS_HIST
                 WHERE MSG_ID = :id
                 ORDER BY H_ID DESC
             ) WHERE ROWNUM <= {$limit}",
            ['id' => $id]
        );

        return array_map(static function ($r): array {
            $row = array_change_key_case((array) $r, CASE_UPPER);

            return [
                'hist_id' => (int) $row['H_ID'],
                'msg_id' => (int) $row['MSG_ID'],
                'old_status' => $row['OLD_STATUS'],
                'new_status' => $row['NEW_STATUS'],
                'at' => $row['AT'],
                'module' => $row['MODULE'],
                'action_name' => $row['ACTION_NAME'],
                'machine' => $row['MACHINE'],
            ];
        }, $rows);
    }

    /**
     * اتحاد الطابور والأرشيف بأعمدة متطابقة (أعمدة الأرشيف الغائبة تُعاد NULL)
     * The queue ∪ archive union with identical columns (absent archive columns become NULL).
     */
    private function union(): string
    {
        return $this->queueSelect().' UNION ALL '.$this->archiveSelect();
    }

    private function queueSelect(): string
    {
        $t = $this->spec->queue;
        $status = MessageStatus::queueExpression('q');
        $joins = ' LEFT JOIN ACCOUNTS.PROVIDERS p ON p.PROVIDER_ID = q.PROVIDER_ID'
            .' LEFT JOIN ACCOUNTS.SERVICES s ON s.SERVICE_ID = q.SERVICE_ID';

        if ($this->spec->hasWorkerState) {
            return "SELECT 'queue' AS SRC, q.ID AS ID, q.MSG_TEXT AS MSG_TEXT,
                    SUBSTR(q.MSG_TEXT,1,400) AS MSG_TEXT_PREVIEW,
                    TO_CHAR(q.MOBILE_NO) AS MOBILE_NO, q.IS_SENT AS IS_SENT,
                    q.STATUS AS QUEUE_STATUS, {$status} AS STATUS_EFF,
                    NVL(q.ATTEMPTS, q.SEND_ATTEMPT) AS ATTEMPTS,
                    TO_CHAR(q.NEXT_ATTEMPT_AT,'YYYY-MM-DD HH24:MI:SS') AS NEXT_ATTEMPT_AT,
                    q.CLAIMED_BY AS CLAIMED_BY,
                    TO_CHAR(q.CLAIMED_AT,'YYYY-MM-DD HH24:MI:SS') AS CLAIMED_AT,
                    TO_CHAR(q.SENDING_AT,'YYYY-MM-DD HH24:MI:SS') AS SENDING_AT,
                    q.LAST_ERROR_CODE AS LAST_ERROR_CODE,
                    SUBSTR(q.ERROR_MSG,1,400) AS ERROR_MSG,
                    q.REQUEST_ID AS REQUEST_ID, q.PROVIDER_MSG_ID AS PROVIDER_MSG_ID,
                    NVL(q.SEND_LOCK,'N') AS SEND_LOCK,
                    q.REF_TYPE AS REF_TYPE, q.REF_ID AS REF_ID,
                    q.FILE_PATH AS FILE_PATH, q.FILE_NAME AS FILE_NAME,
                    q.FILE_TYPE AS FILE_TYPE, SUBSTR(q.CAPTION,1,600) AS CAPTION,
                    q.PROVIDER_ID AS PROVIDER_ID, q.SERVICE_ID AS SERVICE_ID,
                    p.PROVIDER_NAME AS PROVIDER_NAME, s.SERVICE_NAME AS SERVICE_NAME,
                    s.SERVICE_TYPE AS SERVICE_TYPE,
                    TO_CHAR(q.CREATED_AT,'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                    TO_CHAR(q.UPDATED_AT,'YYYY-MM-DD HH24:MI:SS') AS UPDATED_AT
               FROM {$t} q{$joins}";
        }

        // SMS: لا حالة تشغيلية ولا ملفات ولا عمود خطأ
        // SMS: no worker state, no files, no error column.
        return "SELECT 'queue' AS SRC, q.ID AS ID, q.MSG_TEXT AS MSG_TEXT,
                SUBSTR(q.MSG_TEXT,1,400) AS MSG_TEXT_PREVIEW,
                TO_CHAR(q.MOBILE_NO) AS MOBILE_NO, q.IS_SENT AS IS_SENT,
                NULL AS QUEUE_STATUS, '".MessageStatus::PENDING."' AS STATUS_EFF,
                q.SEND_ATTEMPT AS ATTEMPTS,
                NULL AS NEXT_ATTEMPT_AT, NULL AS CLAIMED_BY, NULL AS CLAIMED_AT,
                NULL AS SENDING_AT, NULL AS LAST_ERROR_CODE, NULL AS ERROR_MSG,
                NULL AS REQUEST_ID, NULL AS PROVIDER_MSG_ID, 'N' AS SEND_LOCK,
                NULL AS REF_TYPE, NULL AS REF_ID,
                NULL AS FILE_PATH, NULL AS FILE_NAME, NULL AS FILE_TYPE, NULL AS CAPTION,
                q.PROVIDER_ID AS PROVIDER_ID, q.SERVICE_ID AS SERVICE_ID,
                p.PROVIDER_NAME AS PROVIDER_NAME, s.SERVICE_NAME AS SERVICE_NAME,
                s.SERVICE_TYPE AS SERVICE_TYPE,
                TO_CHAR(q.CREATED_AT,'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                TO_CHAR(q.UPDATED_AT,'YYYY-MM-DD HH24:MI:SS') AS UPDATED_AT
           FROM {$t} q{$joins}";
    }

    private function archiveSelect(): string
    {
        $t = $this->spec->archive;
        $status = MessageStatus::archiveExpression('a');
        $joins = ' LEFT JOIN ACCOUNTS.PROVIDERS p ON p.PROVIDER_ID = a.PROVIDER_ID'
            .' LEFT JOIN ACCOUNTS.SERVICES s ON s.SERVICE_ID = a.SERVICE_ID';

        if ($this->spec->hasWorkerState) {
            return "SELECT 'archive' AS SRC, a.ID AS ID, a.MSG_TEXT AS MSG_TEXT,
                    SUBSTR(a.MSG_TEXT,1,400) AS MSG_TEXT_PREVIEW,
                    TO_CHAR(a.MOBILE_NO) AS MOBILE_NO, a.IS_SENT AS IS_SENT,
                    NULL AS QUEUE_STATUS, {$status} AS STATUS_EFF,
                    a.SEND_ATTEMPT AS ATTEMPTS,
                    NULL AS NEXT_ATTEMPT_AT, NULL AS CLAIMED_BY, NULL AS CLAIMED_AT,
                    NULL AS SENDING_AT, NULL AS LAST_ERROR_CODE,
                    SUBSTR(a.ERROR_MSG,1,400) AS ERROR_MSG,
                    NULL AS REQUEST_ID, NULL AS PROVIDER_MSG_ID, 'N' AS SEND_LOCK,
                    NULL AS REF_TYPE, NULL AS REF_ID,
                    a.FILE_PATH AS FILE_PATH, a.FILE_NAME AS FILE_NAME,
                    a.FILE_TYPE AS FILE_TYPE, SUBSTR(a.CAPTION,1,600) AS CAPTION,
                    a.PROVIDER_ID AS PROVIDER_ID, a.SERVICE_ID AS SERVICE_ID,
                    p.PROVIDER_NAME AS PROVIDER_NAME, s.SERVICE_NAME AS SERVICE_NAME,
                    s.SERVICE_TYPE AS SERVICE_TYPE,
                    TO_CHAR(a.CREATED_AT,'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                    TO_CHAR(a.UPDATED_AT,'YYYY-MM-DD HH24:MI:SS') AS UPDATED_AT
               FROM {$t} a{$joins}";
        }

        return "SELECT 'archive' AS SRC, a.ID AS ID, a.MSG_TEXT AS MSG_TEXT,
                SUBSTR(a.MSG_TEXT,1,400) AS MSG_TEXT_PREVIEW,
                TO_CHAR(a.MOBILE_NO) AS MOBILE_NO, a.IS_SENT AS IS_SENT,
                NULL AS QUEUE_STATUS, {$status} AS STATUS_EFF,
                a.SEND_ATTEMPT AS ATTEMPTS,
                NULL AS NEXT_ATTEMPT_AT, NULL AS CLAIMED_BY, NULL AS CLAIMED_AT,
                NULL AS SENDING_AT, NULL AS LAST_ERROR_CODE, NULL AS ERROR_MSG,
                NULL AS REQUEST_ID, NULL AS PROVIDER_MSG_ID, 'N' AS SEND_LOCK,
                NULL AS REF_TYPE, NULL AS REF_ID,
                NULL AS FILE_PATH, NULL AS FILE_NAME, NULL AS FILE_TYPE, NULL AS CAPTION,
                a.PROVIDER_ID AS PROVIDER_ID, a.SERVICE_ID AS SERVICE_ID,
                p.PROVIDER_NAME AS PROVIDER_NAME, s.SERVICE_NAME AS SERVICE_NAME,
                s.SERVICE_TYPE AS SERVICE_TYPE,
                TO_CHAR(a.CREATED_AT,'YYYY-MM-DD HH24:MI:SS') AS CREATED_AT,
                TO_CHAR(a.UPDATED_AT,'YYYY-MM-DD HH24:MI:SS') AS UPDATED_AT
           FROM {$t} a{$joins}";
    }

    /**
     * شرط الفلاتر — binds فقط، والفرز/الحالة من خريطتين بيضاوين
     * The filter clause — binds only; sort and status come from whitelists.
     *
     * @param  array<string, mixed>  $f
     */
    private function where(array $f, bool $withStatus): string
    {
        $parts = [];

        if ($withStatus && ! empty($f['status']) && $f['status'] !== 'all') {
            $parts[] = 'U.STATUS_EFF = :status';
        }

        if (! empty($f['source']) && $f['source'] !== 'all') {
            $parts[] = 'U.SRC = :source';
        }

        if (! empty($f['search'])) {
            $parts[] = '(U.CUSTOMER_NAME LIKE :search_enc OR U.CUSTOMER_NAME LIKE :search_raw
                         OR U.MOBILE_NO LIKE :search_raw OR U.MSG_TEXT LIKE :search_enc
                         OR U.MSG_TEXT LIKE :search_raw)';
        }

        if (! empty($f['from_date'])) {
            $parts[] = "TO_DATE(U.CREATED_AT,'YYYY-MM-DD HH24:MI:SS') >= TO_DATE(:from_date,'YYYY-MM-DD')";
        }

        if (! empty($f['to_date'])) {
            $parts[] = "TO_DATE(U.CREATED_AT,'YYYY-MM-DD HH24:MI:SS') < TO_DATE(:to_date,'YYYY-MM-DD') + 1";
        }

        if (! empty($f['provider_id'])) {
            $parts[] = 'U.PROVIDER_ID = :provider_id';
        }

        if (! empty($f['service_id'])) {
            $parts[] = 'U.SERVICE_ID = :service_id';
        }

        if (! empty($f['error_code'])) {
            $parts[] = '(U.LAST_ERROR_CODE LIKE :error_code OR U.ERROR_MSG LIKE :error_code)';
        }

        if (! empty($f['min_attempts'])) {
            $parts[] = 'NVL(U.ATTEMPTS,0) >= :min_attempts';
        }

        if ($this->spec->hasFileFields && array_key_exists('has_file', $f) && $f['has_file'] !== null) {
            $parts[] = $f['has_file']
                ? "U.FILE_TYPE IS NOT NULL AND UPPER(U.FILE_TYPE) NOT IN ('TEXT','TXT','NONE')"
                : "(U.FILE_TYPE IS NULL OR UPPER(U.FILE_TYPE) IN ('TEXT','TXT','NONE'))";
        }

        return $parts === [] ? '' : 'WHERE '.implode(' AND ', $parts);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function bindings(array $f, bool $withStatus): array
    {
        $b = [];

        if ($withStatus && ! empty($f['status']) && $f['status'] !== 'all') {
            $b['status'] = $f['status'];
        }
        if (! empty($f['source']) && $f['source'] !== 'all') {
            $b['source'] = $f['source'];
        }
        if (! empty($f['search'])) {
            $raw = trim((string) $f['search']);
            $b['search_raw'] = '%'.$raw.'%';
            $b['search_enc'] = '%'.(ArabicEncoding::toOracleSearchChars($raw) ?? $raw).'%';
        }
        if (! empty($f['from_date'])) {
            $b['from_date'] = $f['from_date'];
        }
        if (! empty($f['to_date'])) {
            $b['to_date'] = $f['to_date'];
        }
        if (! empty($f['provider_id'])) {
            $b['provider_id'] = (int) $f['provider_id'];
        }
        if (! empty($f['service_id'])) {
            $b['service_id'] = (int) $f['service_id'];
        }
        if (! empty($f['error_code'])) {
            $b['error_code'] = '%'.trim((string) $f['error_code']).'%';
        }
        if (! empty($f['min_attempts'])) {
            $b['min_attempts'] = (int) $f['min_attempts'];
        }

        return $b;
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function orderBy(array $f): string
    {
        $column = self::SORT_COLUMNS[$f['sort'] ?? 'created_at'] ?? 'CREATED_AT';
        $dir = strtolower((string) ($f['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        return $column.' '.$dir.', ID DESC';
    }

    /**
     * صف واحد بشكل عقد الـAPI (snake_case) بعد إصلاح العربية
     * One row in the API contract shape (snake_case) after the Arabic repair.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function present(array $raw, bool $fullText): array
    {
        $row = ArabicEncoding::cleanData(array_change_key_case($raw, CASE_UPPER));
        $status = MessageStatus::of($row);

        $text = $fullText
            ? (string) ($row['MSG_TEXT'] ?? '')
            : (string) ($row['MSG_TEXT_PREVIEW'] ?? $row['MSG_TEXT'] ?? '');

        return [
            'id' => (int) ($row['ID'] ?? 0),
            'source' => strtolower((string) ($row['SRC'] ?? 'queue')),
            'status' => $status,
            'status_label' => MessageStatus::labelAr($status),
            'queue_status' => $row['QUEUE_STATUS'] ?? null,
            'is_sent' => $row['IS_SENT'] ?? null,
            'mobile_no' => $row['MOBILE_NO'] ?? null,
            'customer_name' => $row['CUSTOMER_NAME'] ?? null,
            'message_text' => $text,
            'provider_id' => $row['PROVIDER_ID'] !== null ? (int) $row['PROVIDER_ID'] : null,
            'service_id' => $row['SERVICE_ID'] !== null ? (int) $row['SERVICE_ID'] : null,
            'provider_name' => $row['PROVIDER_NAME'] ?? null,
            'service_name' => $row['SERVICE_NAME'] ?? null,
            'service_type' => $row['SERVICE_TYPE'] ?? null,
            'attempts' => $row['ATTEMPTS'] !== null ? (int) $row['ATTEMPTS'] : null,
            'next_attempt_at' => $row['NEXT_ATTEMPT_AT'] ?? null,
            'last_error_code' => $row['LAST_ERROR_CODE'] ?? null,
            'error_msg' => $row['ERROR_MSG'] ?? null,
            'request_id' => $row['REQUEST_ID'] ?? null,
            'provider_msg_id' => $row['PROVIDER_MSG_ID'] ?? null,
            'send_lock' => $row['SEND_LOCK'] ?? 'N',
            'claimed_by' => $row['CLAIMED_BY'] ?? null,
            'claimed_at' => $row['CLAIMED_AT'] ?? null,
            'sending_at' => $row['SENDING_AT'] ?? null,
            'ref_type' => $row['REF_TYPE'] ?? null,
            'ref_id' => $row['REF_ID'] ?? null,
            'file_path' => $row['FILE_PATH'] ?? null,
            'file_name' => $row['FILE_NAME'] ?? null,
            'file_type' => $row['FILE_TYPE'] ?? null,
            'caption' => $row['CAPTION'] ?? null,
            'created_at' => $row['CREATED_AT'] ?? null,
            'updated_at' => $row['UPDATED_AT'] ?? null,
        ];
    }

    /**
     * قيمة عمود واحد من استعلام عدّادي (المفاتيح قد تعود صغيرة من yajra)
     * A single scalar from a count query (yajra may return lowercase keys).
     *
     * @param  array<string, mixed>  $bindings
     */
    private function scalar(string $sql, array $bindings, string $column): mixed
    {
        $row = (array) DB::connection('oracle')->selectOne($sql, $bindings);
        $upper = array_change_key_case($row, CASE_UPPER);

        return $upper[$column] ?? null;
    }
}
