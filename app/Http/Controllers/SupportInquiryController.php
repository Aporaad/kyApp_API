<?php

namespace App\Http\Controllers;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use App\Support\MessageQueueWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * متحكم استفسارات الدعم — الجانب العام (الإرسال من شاشة معلومات البرنامج) والجانب الإداري (القراءة والرد)
 * Support-inquiry controller — the public side (submitting from the app-info screen) and the
 * administrative side (listing and replying).
 *
 * قاعدتان تحكمان هذا الملف:
 * Two rules govern this file:
 *  1) المسار العام يكتب في القاعدة بلا جلسة، فكل ما يصل يخضع لحدود أطوال صارمة وللتحقق المسبق.
 *     The public route writes to the database without a session, so every payload is length-capped
 *     and validated before it touches a bind.
 *  2) العربية تُكتب بـ `toOracleSearchChars()` وتُقرأ بـ `fixMojibake()` — نفس مسار `SettingsStore`،
 *     وإلا ظهرت التذاكر مشوّهة كما كان يحدث قبل هذا المسار الموحّد.
 *     Arabic travels through `toOracleSearchChars()` on write and `fixMojibake()` on read, the same
 *     path `SettingsStore` uses; anything else stores mojibake.
 *  3) الرد المطلوب إرساله لا يُحفظ إلا مع إدراجه في طابور القناة المختارة داخل معاملة واحدة،
 *     والإدراج نفسه يمرّ عبر `MessageQueueWriter` بقاعدة معرّف موحّدة (أكبر تسلسل بين الطابور
 *     والأرشيف + 1) كي لا يتصادم معرّف الرد مع صف أرشيف موجود.
 *     A reply marked for delivery saves only together with its queue insert inside one
 *     transaction; the insert itself goes through `MessageQueueWriter` with the unified id rule
 *     (highest sequence across queue and archive, + 1) so a reply's id never collides with an
 *     existing archive row.
 *  4) الإدراج يحمل `SERVICE_ID/PROVIDER_ID` من الاشتراك النشط لخدمة القناة (`resolveDestination`)
 *     — مهمة الإرسال تطابق الصف بهذين المفتاحين، وبلا اشتراك نشط يُرفض الطلب 422 قبل أي حفظ.
 *     The insert carries SERVICE_ID/PROVIDER_ID from the channel's active subscription
 *     (`resolveDestination`) — the send job matches the row by these two keys, and with no active
 *     subscription the request is refused 422 before any save.
 */
class SupportInquiryController extends Controller
{
    /**
     * حدود التصفح — نفس قيم `UserAdminController` كي يقرأ العميلُ شكلاً واحداً
     * Pagination bounds matching UserAdminController so the client parses one shape.
     */
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;

    /**
     * الحالات المعلَنة — مطابقة لقيد `APP_SUP_STATUS_CK` في القاعدة
     * Declared statuses, matching the `APP_SUP_STATUS_CK` check constraint.
     */
    private const STATUSES = ['new', 'answered', 'closed'];

    /**
     * استعلام القائمة: اسم المستخدم صاحب التذكرة + قصّ الصفوف بـ `ROW_NUMBER`
     * (Oracle 11g لا تعرف OFFSET/FETCH، وهو نفس أسلوب بقية القوائم)
     * Listing query: the ticket owner's name plus `ROW_NUMBER` slicing (11g has no OFFSET/FETCH).
     */
    private const LIST_SQL = <<<'SQL'
        SELECT *
          FROM (
                SELECT x.*, ROW_NUMBER() OVER (ORDER BY x.CREATED_ON DESC, x.TICKET_ID DESC) AS RN
                  FROM (
                        SELECT t.TICKET_ID, t.USER_ID, t.CONTACT_NAME, t.CONTACT_PHONE,
                               t.MESSAGE, t.STATUS, t.ADMIN_REPLY, t.REPLIED_BY,
                               t.APP_VERSION, t.PLATFORM,
                               TO_CHAR(t.REPLIED_ON, 'YYYY-MM-DD HH24:MI:SS') AS REPLIED_ON,
                               TO_CHAR(t.CREATED_ON, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_ON,
                               TO_CHAR(t.UPDATED_ON, 'YYYY-MM-DD HH24:MI:SS') AS UPDATED_ON,
                               u.USER_NAME_AR AS USER_NAME
                          FROM ACCOUNTS.APP_SUPPORT_TICKETS t
                          LEFT JOIN ACCOUNTS.APP_USERS u ON u.USER_ID = t.USER_ID
                         WHERE __WHERE__
                       ) x
               )
         WHERE RN > :rn_from AND RN <= :rn_to
         ORDER BY RN
        SQL;

    /**
     * عدّ نفس شروط القائمة لتوليد `meta.total`
     * Counts the same filtered set so `meta.total` matches the listing.
     */
    private const COUNT_SQL = <<<'SQL'
        SELECT COUNT(*) AS TOTAL
          FROM ACCOUNTS.APP_SUPPORT_TICKETS t
         WHERE __WHERE__
        SQL;

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * إرسال استفسار — عام، يعمل قبل الدخول وبعده
     * Submit an inquiry — public, works both before and after login.
     *
     * لا يوجد وسيط `auth:sanctum` على هذا المسار: التوكن يُقرأ اختيارياً فيُربَط `USER_ID` إن وُجد
     * توكن صالح، وإلا حُفظت التذكرة كزائر باسم وهاتف يكتبهما صاحبها.
     * No `auth:sanctum` middleware here: the token is read optionally, so `USER_ID` is bound when a
     * valid token is present; otherwise the ticket is stored as a guest with a typed name and phone.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $isGuest = $user === null;

        $phone = $this->normalizePhone($request->input('phone'));
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            // الاسم والهاتف لازمان للزائر فقط؛ صاحب الجلسة يُشتق اسمه من حسابه
            // Name and phone are required for a guest only; a signed-in user's name comes from the account.
            'name' => [$isGuest ? 'required' : 'nullable', 'string', 'min:2', 'max:100'],
            'phone' => [$isGuest ? 'required' : 'nullable', 'digits_between:7,15'],
            'message' => ['required', 'string', 'min:10', 'max:1000'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'platform' => ['nullable', 'string', 'max:30'],
        ], [
            'name.required' => 'الاسم مطلوب / Name is required.',
            'name.min' => 'الاسم أقصر من محرفين / Name is too short.',
            'name.max' => 'الاسم أطول من 100 محرف / Name is too long.',
            'phone.required' => 'رقم الهاتف مطلوب / Phone number is required.',
            'phone.digits_between' => 'رقم الهاتف يجب أن يكون بين 7 و15 رقماً / Phone must be 7 to 15 digits.',
            'message.required' => 'نص الاستفسار مطلوب / Message is required.',
            'message.min' => 'نص الاستفسار أقصر من 10 محارف / Message is too short.',
            'message.max' => 'نص الاستفسار أطول من 1000 محرف / Message is too long.',
            'app_version.max' => 'رقم إصدار التطبيق أطول من 20 محرفاً / App version is too long.',
            'platform.max' => 'اسم المنصة أطول من 30 محرفاً / Platform name is too long.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الاستفسار غير مكتملة / Incomplete inquiry data',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = trim((string) $request->input('name', ''));
        $userId = $user === null ? null : (int) $user->getAuthIdentifier();

        if ($name === '' && $user !== null) {
            $name = (string) ($user->getAttribute('USER_NAME_AR') ?? $user->getAttribute('USER_NAME') ?? '');
        }

        try {
            // المعرّف يُسحب من المتسلسلة صراحةً قبل الإدراج: محرّك PDO في أوراكل لا يعيد
            // `RETURNING` بسهولة، والمُشغِّل يترك القيمة كما هي حين تُمرَّر.
            // The id is pulled from the sequence before the insert: PDO over Oracle does not
            // return `RETURNING` conveniently, and the trigger keeps an explicit value untouched.
            $ticketId = $this->nextTicketId();

            DB::connection('oracle')->insert(
                'INSERT INTO ACCOUNTS.APP_SUPPORT_TICKETS
                    (TICKET_ID, USER_ID, CONTACT_NAME, CONTACT_PHONE, MESSAGE, STATUS,
                     APP_VERSION, PLATFORM, CREATED_ON, UPDATED_ON)
                 VALUES
                    (:ticket_id, :user_id, :contact_name, :contact_phone, :message, :status,
                     :app_version, :platform, SYSDATE, SYSDATE)',
                [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'contact_name' => $this->toOracle($this->limitText($name, 100)),
                    'contact_phone' => $this->limitText((string) $phone, 30),
                    'message' => $this->toOracle($this->limitText((string) $request->input('message'), 1000)),
                    'status' => 'new',
                    'app_version' => $this->limitText($request->input('app_version'), 20),
                    'platform' => $this->limitText($request->input('platform'), 30),
                ]
            );

            // التسجيل التدقيقي للمستخدم المُسجَّل فقط — الزائر لا USER_ID له أصلاً
            // Audit only the signed-in submitter; a guest has no USER_ID to log against.
            if ($userId !== null) {
                $this->activity->success('support.submit', [
                    'user_id' => $userId,
                    'module_code' => 'support',
                    'entity_type' => 'APP_SUPPORT_TICKETS',
                    'entity_id' => (string) $ticketId,
                    'description_ar' => 'إرسال استفسار دعم جديد رقم '.$ticketId,
                ], $request);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال استفسارك، وسيتم الرد عليك في أقرب وقت / Your inquiry was received.',
                'data' => [
                    'ticket_id' => $ticketId,
                    'status' => 'new',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حفظ الاستفسار', $e);
        }
    }

    /**
     * قائمة الاستفسارات للإدارة مع الفلترة والبحث والتصفح
     * Admin listing with status filter, search and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->input('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $page = max((int) $request->input('page', 1), 1);

        try {
            [$where, $bindings] = $this->conditions($request);

            $total = (int) $this->columnOf(
                (array) DB::connection('oracle')->selectOne(
                    str_replace('__WHERE__', $where, self::COUNT_SQL),
                    $bindings
                ),
                'TOTAL'
            );

            $rows = DB::connection('oracle')->select(
                str_replace('__WHERE__', $where, self::LIST_SQL),
                array_merge($bindings, ['rn_from' => ($page - 1) * $limit, 'rn_to' => $page * $limit])
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الاستفسارات / Inquiries retrieved.',
                'data' => array_map(fn ($row): array => $this->shape((array) $row), $rows),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في جلب قائمة الاستفسارات', $e);
        }
    }

    /**
     * الرد على استفسار أو إغلاقه — والرد اختيارياً يُرسَل لهاتف صاحب الاستفسار عبر طابور الرسائل
     * Answer an inquiry or close it — the reply may optionally be delivered to the inquirer's
     * phone through the message queue (`send_via` = sms | whatsapp).
     */
    public function reply(Request $request, int $ticketId): JsonResponse
    {
        $actor = $request->user();

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:answered,closed'],
            // الرد يلزم فقط عند اختيار «تم الرد» — الإغلاق قد يقع بلا نص
            // A reply text is required only for "answered"; closing may carry none.
            'reply' => ['nullable', 'string', 'max:1000', 'required_if:status,answered', 'required_with:send_via'],
            // قناة الإرسال اختيارية: وجودها يعني «أضِف نص الرد إلى طابور هذه القناة» ليصل
            // هاتف صاحب الاستفسار عبر مهمة الإرسال الزمنية
            // The send channel is optional: its presence means "enqueue the reply text on that
            // channel" so the inquirer's phone gets it via the scheduler job.
            'send_via' => ['nullable', 'string', 'in:sms,whatsapp'],
        ], [
            'status.required' => 'حالة التذكرة مطلوبة / Status is required.',
            'status.in' => 'الحالة يجب أن تكون «تم الرد» أو «مغلق» / Status must be answered or closed.',
            'reply.required_if' => 'نص الرد مطلوب عند اختيار «تم الرد» / A reply text is required when answering.',
            'reply.required_with' => 'نص الرد مطلوب عند اختيار قناة الإرسال / A reply text is required when a send channel is chosen.',
            'reply.max' => 'نص الرد أطول من 1000 محرف / Reply is too long.',
            'send_via.in' => 'قناة الإرسال يجب أن تكون «نصية» أو «واتساب» / Send channel must be sms or whatsapp.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الرد غير مقبولة / Invalid reply payload',
                'errors' => $validator->errors(),
            ], 422);
        }

        $status = (string) $request->input('status');
        $reply = trim((string) $request->input('reply', ''));
        $sendVia = $request->input('send_via');

        try {
            // صف التذكرة يُجلب مباشرةً بدل عدّ وجوده: الإرسال يحتاج الاسم والهاتف أصلاً،
            // فالجلب الواحد يجيب عن السؤالين (الوجود وبيانات الإرسال) معاً
            // The ticket row is fetched outright instead of counting it: delivery needs the name
            // and phone anyway, so one fetch answers both (existence + delivery data).
            $ticketRow = DB::connection('oracle')->selectOne(
                'SELECT t.CONTACT_NAME, t.CONTACT_PHONE, u.USER_NAME_AR AS USER_NAME
                   FROM ACCOUNTS.APP_SUPPORT_TICKETS t
                   LEFT JOIN ACCOUNTS.APP_USERS u ON u.USER_ID = t.USER_ID
                  WHERE t.TICKET_ID = :ticket_id',
                ['ticket_id' => $ticketId]
            );

            if ($ticketRow === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'الاستفسار غير موجود / Inquiry not found',
                ], 404);
            }

            $ticket = $this->lowercaseKeys((array) $ticketRow);

            $queuedId = null;
            $queueChannel = null;
            $phone = null;
            $destination = null;

            if ($sendVia !== null) {
                // الهاتف إلى أرقام فقط — العمود يُخزَّن مطبَّعاً، لكن الصفوف القديمة لا تُترك للحظّ
                // Phone reduced to digits — the column is stored normalised, yet old rows are not
                // left to chance.
                $phone = preg_replace('/\D/', '', (string) ($ticket['contact_phone'] ?? ''));

                // الإرسال بلا رقم صالح رفضٌ مبكر لا حفظ جزئي: ردٌّ ظنّ الموظف أنه سينتقل كان سيبقى
                // حبراً على الورق. الرفض يُبقي التذكرة كما هي فيُعاد المحاولة بوعي.
                // Sending with no usable phone is an early refusal, not a partial save: a reply the
                // employee believed delivered would have stayed ink on paper. The refusal keeps the
                // ticket untouched so the retry is a conscious choice.
                if ($phone === '' || strlen($phone) < 6 || strlen($phone) > 15) {
                    return response()->json([
                        'success' => false,
                        'message' => 'لا يوجد رقم هاتف صالح مرفق بالاستفسار لإرسال الرد — احفظ الرد دون اختيار قناة أو أكمل رقم الهاتف أولاً'
                            .' / This inquiry carries no usable phone number for delivery — save the reply without a send channel or fix the phone first.',
                    ], 422);
                }

                // وجهة الإرسال الصحيحة: اشتراك نشط في PROVIDERS_DTL لخدمة القناة — مهمة
                // `SEND_MSG` تطابق الصف بهذين المفتاحين، وبلا اشتراك يعلق الصف في الطابور بلا
                // إرسال أبداً. الرفض مبكر بلا أي حفظ، كرفض الهاتف تماماً.
                // The correct send destination: an active PROVIDERS_DTL subscription for the
                // channel's service — SEND_MSG matches the row by these two keys, and with no
                // subscription the row would hang in the queue forever. Early refusal, no save,
                // exactly like the phone guard.
                $destination = MessageQueueWriter::resolveDestination($sendVia);

                if ($destination === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'لا يوجد اشتراك إرسال نشط لهذه القناة — فعّل اشتراك القناة من شاشة المزودين أو احفظ الرد دون اختيار قناة'
                            .' / No active send subscription exists for this channel — activate the channel subscription or save the reply without a send channel.',
                    ], 422);
                }
            }

            // اسم العميل في الطابور: الاسم الذي كتبه الزائر، وإلا اسم حساب صاحب التذكرة —
            // كلاهما يُنسخ خاماً من عمود أوراكل (بايتات CP1256 صحيحة أصلاً) فلا تحويل مضاعف
            // The queue's customer name: the guest's typed name, else the owner's account name —
            // both copied raw from Oracle columns (already valid CP1256), never re-encoded.
            $customerName = trim((string) ($ticket['contact_name'] ?? '')) !== ''
                ? (string) $ticket['contact_name']
                : trim((string) ($ticket['user_name'] ?? ''));

            $actorId = $actor === null ? null : (int) $actor->getAuthIdentifier();

            // CREATED_BY نص اسم الفاعل كما يكتبه قسم الرسائل — لا معرّف رقمي هنا
            // CREATED_BY is the actor's name string, as the messaging section writes it — no numeric id.
            $actorName = null;
            if ($actor !== null) {
                $name = $actor->getAttribute('USER_NAME_EN') ?? $actor->getAttribute('USER_NAME_AR');
                $actorName = $name !== null ? (string) $name : null;
            }

            // التحديث والإدراج في الطابور داخل معاملة واحدة: فشل الإدراج يلغي حفظ الرد كله،
            // فلا تبقى تذكرة «تم الرد» نصها لم يذهب إلى أي مكان
            // The update and the queue insert share one transaction: a failed insert cancels the
            // whole save, leaving no "answered" ticket whose text went nowhere.
            DB::connection('oracle')->transaction(
                function () use ($ticketId, $status, $reply, $sendVia, $phone, $customerName, $destination, $actorId, $actorName, &$queuedId, &$queueChannel): void {
                    DB::connection('oracle')->update(
                        'UPDATE ACCOUNTS.APP_SUPPORT_TICKETS
                            SET STATUS = :status,
                                ADMIN_REPLY = :admin_reply,
                                REPLIED_BY = :replied_by,
                                REPLIED_ON = SYSDATE,
                                UPDATED_ON = SYSDATE
                          WHERE TICKET_ID = :ticket_id',
                        [
                            'status' => $status,
                            'admin_reply' => $reply === '' ? null : $this->toOracle($this->limitText($reply, 1000)),
                            'replied_by' => $actorId,
                            'ticket_id' => $ticketId,
                        ]
                    );

                    // الإرسال مرتبط بوجود نص فعلي لا بالحالة: قناة مع نص تعني إدراجاً في الطابور
                    // Sending is tied to an actual text, not to the status: a channel plus text enqueues.
                    if ($sendVia !== null && $reply !== '') {
                        $queuedId = MessageQueueWriter::enqueue(
                            $sendVia,
                            $reply,
                            (int) $phone,
                            $customerName === '' ? null : $customerName,
                            $actorName,
                            $destination
                        );
                        $queueChannel = $sendVia;
                    }
                }
            );

            $channelLabel = $queueChannel === MessageQueueWriter::CHANNEL_SMS
                ? 'الرسائل النصية'
                : 'رسائل واتساب';

            // وصف التدقيق يذكر الإضافة إلى الطابور حين وقعت — أثر الرد والإرسال في سطر واحد
            // The audit description names the enqueue when it happened — reply and delivery in one line.
            $description = ($status === 'closed' ? 'إغلاق' : 'الرد على').' استفسار دعم رقم '.$ticketId;

            if ($queuedId !== null) {
                $description .= ' وإضافة الرد إلى طابور '.$channelLabel.' برقم رسالة '.$queuedId;
            }

            $this->activity->success('support.reply', [
                'user_id' => $actorId,
                'module_code' => 'support',
                'entity_type' => 'APP_SUPPORT_TICKETS',
                'entity_id' => (string) $ticketId,
                'description_ar' => $description,
            ], $request);

            return response()->json([
                'success' => true,
                'message' => $queuedId !== null
                    ? 'تم حفظ الرد وإضافته إلى طابور '.$channelLabel.' (رقم الرسالة '.$queuedId.') — سيرسل من مهمة الإرسال الزمنية'
                        .' / Reply saved and queued for '.($queueChannel === MessageQueueWriter::CHANNEL_SMS ? 'SMS' : 'WhatsApp').' delivery (message #'.$queuedId.').'
                    : ($status === 'closed'
                        ? 'تم إغلاق الاستفسار / Inquiry closed.'
                        : 'تم حفظ الرد / Reply saved.'),
                'data' => [
                    'ticket_id' => $ticketId,
                    'status' => $status,
                    // حقولا الإرسال يظهران فقط عند إدراج فعلي في الطابور، وإلا null
                    // The send fields appear only on an actual enqueue; null otherwise.
                    'send_via' => $queueChannel,
                    'queue_id' => $queuedId,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('خطأ في حفظ الرد أو إضافته إلى طابور الإرسال', $e);
        }
    }

    /**
     * شروط القائمة: الحالة والبحث — استعلامات مربوطة حصراً
     * Listing conditions (status, search) built exclusively from bound values
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(Request $request): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        $status = (string) $request->input('status', 'all');

        if (in_array($status, self::STATUSES, true)) {
            $bindings['status'] = $status;
            $where[] = 't.STATUS = :status';
        }

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            // البحث يغطي الاسم والهاتف والنص، بالشكل العربي المخزَّن وبالشكل الخام معاً
            // Search covers name, phone and text, in both the stored Arabic bytes and raw form.
            $bindings['search_ar'] = '%'.ArabicEncoding::toOracleSearchChars($search).'%';
            $bindings['search_raw'] = '%'.$search.'%';

            $where[] = '(t.CONTACT_NAME LIKE :search_ar
                      OR t.CONTACT_NAME LIKE :search_raw
                      OR t.MESSAGE LIKE :search_ar
                      OR t.MESSAGE LIKE :search_raw
                      OR t.CONTACT_PHONE LIKE :search_raw)';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * توحيد شكل صف التذكرة قبل الإرسال — العربية تُصلَح هنا والفراغ يصير `null`
     * Normalise a ticket row before it leaves the API; Arabic is repaired here and blanks become null.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $row = $this->lowercaseKeys($row);

        return [
            'ticket_id' => (int) ($row['ticket_id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'user_name' => $this->fix($row['user_name'] ?? null),
            'contact_name' => $this->fix($row['contact_name'] ?? null),
            'contact_phone' => $row['contact_phone'] ?? null,
            'message' => $this->fix($row['message'] ?? null),
            'status' => $row['status'] ?? 'new',
            'admin_reply' => $this->fix($row['admin_reply'] ?? null),
            'replied_by' => isset($row['replied_by']) ? (int) $row['replied_by'] : null,
            'replied_on' => $row['replied_on'] ?? null,
            'app_version' => $row['app_version'] ?? null,
            'platform' => $row['platform'] ?? null,
            'created_on' => $row['created_on'] ?? null,
            'updated_on' => $row['updated_on'] ?? null,
        ];
    }

    /**
     * الرقم التالي للتذكرة من المتسلسلة مباشرة (محرّك PDO لا يعيد RETURNING بسهولة)
     * The next ticket id straight from the sequence (PDO over Oracle lacks easy RETURNING).
     */
    private function nextTicketId(): int
    {
        return (int) $this->columnOf(
            (array) DB::connection('oracle')->selectOne('SELECT SEQ_APP_SUPPORT_TICKETS.NEXTVAL AS ID FROM DUAL'),
            'ID'
        );
    }

    /**
     * أرقام عربية-هندية ومسافات وشرطات تُطبَّع قبل التحقق كي لا يُرفض رقم صحيح الشكل
     * Arabic-Indic digits, spaces and dashes are normalised before validation so a well-formed
     * number typed on an Arabic keyboard is not rejected.
     */
    private function normalizePhone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        $stripped = preg_replace('/[^0-9]/', '', $digits);

        return $stripped === null || $stripped === '' ? null : $stripped;
    }

    /**
     * قصّ دفاعي لحدود الأعمدة — التحقق يسبق الإدراج، لكن الحدّ لا يُترك للحظّ
     * Defensive truncation to column widths — validation runs first, but the boundary is not left
     * to chance (an over-long value would raise ORA-12899 and lose the whole ticket).
     */
    private function limitText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    /**
     * العربية إلى بايتات أوراكل — `null` تبقى `null`
     * Arabic to Oracle bytes; null stays null.
     */
    private function toOracle(?string $value): ?string
    {
        return $value === null ? null : ArabicEncoding::toOracleSearchChars($value);
    }

    /**
     * إصلاح العربية القادمة من أوراكل
     * Repair Arabic coming back from Oracle.
     */
    private function fix(mixed $value): ?string
    {
        return $value === null ? null : ArabicEncoding::fixMojibake((string) $value);
    }

    /**
     * أوراكل يعيد أسماء الأعمدة بحالات مختلفة؛ التوحيد يجعل القراءة واحدة
     * Oracle returns column names in varying cases; lowercasing makes the read uniform.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function lowercaseKeys(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[strtolower((string) $key)] = $value;
        }

        return $out;
    }

    /**
     * قراءة عمود من صف خام بحالة غير مستقرة
     * Read a column from a raw row, tolerating unstable casing.
     *
     * @param  array<string, mixed>  $row
     */
    private function columnOf(array $row, string $column): mixed
    {
        return $row[$column] ?? $this->lowercaseKeys($row)[strtolower($column)] ?? null;
    }

    /**
     * رد خطأ موحّد — التفاصيل في وضع التصحيح فقط
     * Unified error reply; the detail is exposed only in debug mode.
     */
    private function errorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message.' / Database error.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
