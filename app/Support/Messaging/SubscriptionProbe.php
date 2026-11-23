<?php

namespace App\Support\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * فحص اتصال اشتراك — يقرأ الإعداد من الصف ولا يقبل عنواناً من الطلب، ولا يرسل رسالة أبداً
 * Subscription connectivity probe — reads its target from the stored row (never from the request)
 * and never sends a message.
 *
 * حاكمان مقصودان:
 *  1) **لا عنوان من المستخدم**: الـURL يُقرأ من `PROVIDERS_DTL` فقط، فلا يتحول الخادم إلى وسيط
 *     لطلبات داخلية (SSRF) عبر حقل في الطلب.
 *  2) **لا إرسال**: لواتساب نستدعي نقطة الحالة فقط. وللـSMS **لا** نستدعي قالب الإرسال
 *     (`...#MESSAGE#...#PHONE_NO#`) لأن ذلك يُرسل رسالة حقيقية ويكلّف مالاً؛ نكتفي بالوصول إلى
 *     جذر المضيف ونتحقق أن القالب يحوي المتغيّرين المطلوبين.
 * Two deliberate guards: the target comes only from the stored row (no SSRF via request input), and
 * nothing is ever sent — WhatsApp uses the status endpoint, and SMS checks host reachability plus the
 * template placeholders instead of calling a URL that would deliver a real, billable message.
 */
final class SubscriptionProbe
{
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 8;

    /**
     * فحص اشتراك واحد
     * Probe one subscription.
     *
     * @param  array<string, mixed>  $row  صف PROVIDERS_DTL بمفاتيح UPPER / a PROVIDERS_DTL row with UPPER keys
     * @param  string|null  $serviceType  نوع الخدمة من SERVICES / the service type from SERVICES
     * @return array{service_type: string|null, reachable: bool, http_status: int|null, latency_ms: int, verdict: string, detail_ar: string, checked_at: string}
     */
    public function probe(array $row, ?string $serviceType): array
    {
        $type = strtoupper(trim((string) $serviceType));
        $url = trim((string) ($row['URL'] ?? ''));

        $checkedAt = now()->format('Y-m-d H:i:s');

        if ($url === '' || ! $this->isHttpUrl($url)) {
            return $this->result($type, false, null, 0, 'misconfigured', 'الرابط غير موجود أو غير صالح في الاشتراك.', $checkedAt);
        }

        return match ($type) {
            'WHATSAPP' => $this->probeWhatsApp($row, $url, $checkedAt),
            'URL' => $this->probeSmsTemplate($url, $checkedAt),
            'FIREBASE' => $this->probeHost($row, $url, $type, $checkedAt),
            default => $this->result($type, false, null, 0, 'misconfigured', 'نوع خدمة غير معروف؛ لا يمكن فحصه.', $checkedAt),
        };
    }

    /**
     * واتساب: حالة الجلسة عبر البوابة — نفس نداء `SESSION_READY` في المحرّك
     * WhatsApp: session state through the gateway — the same call the engine's SESSION_READY makes.
     *
     * @param  array<string, mixed>  $row
     * @return array{service_type: string|null, reachable: bool, http_status: int|null, latency_ms: int, verdict: string, detail_ar: string, checked_at: string}
     */
    private function probeWhatsApp(array $row, string $url, string $checkedAt): array
    {
        $sessionId = trim((string) ($row['PASSWORD'] ?? ''));
        $apiKey = trim((string) ($row['TOKEN'] ?? ''));

        if ($sessionId === '' || $apiKey === '') {
            return $this->result('WHATSAPP', false, null, 0, 'misconfigured', 'معرّف الجلسة أو مفتاح الـAPI غير مضبوط في الاشتراك.', $checkedAt);
        }

        // الفحص عبر **دالة المحرّك نفسها** لا عبر HTTP من الخادم: هي التي تقرّر فعلاً قبل كل إرسال،
        // وتعمل من خادم أوراكل الذي يرى البوابة على 127.0.0.1؛ ومسار /api/sessions/{id}/status في
        // إصدار البوابة الحالي يعيد `{"statuses":[]}` فيبدو الفحص فاشلاً وهو ليس كذلك.
        // Probe through the engine's own function, not an HTTP call from the API host: it is what
        // actually gates every send, it runs on the Oracle host that can reach 127.0.0.1, and this
        // gateway build answers the status path with `{"statuses":[]}` — a false failure.
        $started = microtime(true);

        try {
            $raw = (string) $this->scalar(
                'SELECT SendWhatsappAPI.checkApiStatus(:url, :sid, :key) AS RES FROM DUAL',
                ['url' => $url, 'sid' => $sessionId, 'key' => $apiKey]
            );
        } catch (Throwable $e) {
            return $this->result('WHATSAPP', false, null, $this->elapsed($started), 'unreachable', 'تعذّر استدعاء فحص الجلسة: '.$e->getMessage(), $checkedAt);
        }

        $ms = $this->elapsed($started);
        $decoded = $this->jsonAfterPrefix($raw, 'OK:');
        $state = strtolower((string) ($decoded['status'] ?? $decoded['state'] ?? ''));
        $phone = trim((string) ($decoded['phone'] ?? ''));

        if (str_starts_with($raw, 'OK:') && $state === 'ready') {
            return $this->result(
                'WHATSAPP',
                true,
                200,
                $ms,
                'ready',
                'الجلسة جاهزة والإرسال ممكن'.($phone !== '' ? ' — الرقم '.$phone : '').'.',
                $checkedAt
            );
        }

        if (str_contains($raw, 'HTTP_401') || str_contains($raw, 'HTTP_403')) {
            return $this->result('WHATSAPP', true, 401, $ms, 'unauthorized', 'المفتاح منتهٍ أو غير صحيح / API key rejected.', $checkedAt);
        }

        if (str_contains($raw, 'HTTP_404')) {
            return $this->result('WHATSAPP', true, 404, $ms, 'not_found', 'معرّف الجلسة غير موجود على البوابة.', $checkedAt);
        }

        if (str_starts_with($raw, 'ERROR:NET')) {
            return $this->result('WHATSAPP', false, null, $ms, 'unreachable', 'البوابة لا تستجيب: '.mb_substr($raw, 0, 160), $checkedAt);
        }

        if ($state !== '') {
            return $this->result('WHATSAPP', true, 200, $ms, 'session_down', 'الجلسة غير جاهزة ('.$state.') — أعد ربط واتساب من البوابة.', $checkedAt);
        }

        return $this->result('WHATSAPP', true, null, $ms, 'session_down', 'رد غير مفهوم من البوابة: '.mb_substr($raw, 0, 160), $checkedAt);
    }

    /**
     * قراءة JSON بعد بادئة (`OK:` أو `ERROR:HTTP_x:`) — ردود طبقة أوراكل نصية
     * Read the JSON that follows a prefix — the Oracle layer answers with text.
     *
     * @return array<string, mixed>
     */
    private function jsonAfterPrefix(string $raw, string $prefix): array
    {
        if (! str_starts_with($raw, $prefix)) {
            return [];
        }

        $json = trim(substr($raw, strlen($prefix)));
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * قيمة عمود واحد من استعلام (yajra يعيد المفاتيح صغيرة)
     * One scalar from a query (yajra returns lowercase keys).
     *
     * @param  array<string, mixed>  $bindings
     */
    private function scalar(string $sql, array $bindings): mixed
    {
        $row = (array) DB::connection('oracle')->selectOne($sql, $bindings);
        $upper = array_change_key_case($row, CASE_UPPER);

        return $upper['RES'] ?? null;
    }

    /**
     * SMS بقالب URL: الوصول إلى الجذر + اكتمال المتغيّرات — بلا إرسال
     * SMS URL template: host reachability plus placeholder completeness — without sending.
     *
     * @return array{service_type: string|null, reachable: bool, http_status: int|null, latency_ms: int, verdict: string, detail_ar: string, checked_at: string}
     */
    private function probeSmsTemplate(string $url, string $checkedAt): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $port = $parts['port'] ?? null;

        if ($host === '') {
            return $this->result('URL', false, null, 0, 'misconfigured', 'تعذّر قراءة المضيف من رابط الاشتراك.', $checkedAt);
        }

        $root = $scheme.'://'.$host.($port !== null ? ':'.$port : '').'/';
        $started = microtime(true);

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withoutRedirecting()
                ->get($root);
            $status = $response->status();
        } catch (ConnectionException $e) {
            return $this->result('URL', false, null, $this->elapsed($started), 'unreachable', 'تعذّر الوصول إلى مزوّد الرسائل: '.$e->getMessage(), $checkedAt);
        } catch (Throwable $e) {
            return $this->result('URL', false, null, $this->elapsed($started), 'unreachable', 'خطأ غير متوقع في الفحص: '.$e->getMessage().' (لم تُرسل أي رسالة)', $checkedAt);
        }

        $ms = $this->elapsed($started);

        $hasPhone = str_contains($url, '#PHONE_NO#');
        $hasBody = str_contains($url, '#MESSAGE#');

        if (! $hasPhone || ! $hasBody) {
            $missing = [];
            if (! $hasPhone) {
                $missing[] = '#PHONE_NO#';
            }
            if (! $hasBody) {
                $missing[] = '#MESSAGE#';
            }

            return $this->result('URL', true, $status, $ms, 'template_incomplete', 'القالب ينقصه: '.implode(' و', $missing).' — الرسائل لن تُرسل.', $checkedAt);
        }

        return $this->result('URL', true, $status, $ms, 'ready', 'المزوّد يستجيب والقالب مكتمل المتغيّرات.', $checkedAt);
    }

    /**
     * FIREBASE: الوصول إلى المضيف فقط
     * FIREBASE: host reachability only.
     *
     * @return array{service_type: string|null, reachable: bool, http_status: int|null, latency_ms: int, verdict: string, detail_ar: string, checked_at: string}
     */
    private function probeHost(array $row, string $url, string $type, string $checkedAt): array
    {
        // FIREBASE لا يُفحص شبكياً من هنا: الإرسال ينفّذه خادم أوراكل، ومحاولة الوصول إلى
        // fcm.googleapis.com من خادم الـAPI تعطي فشلاً مضلّلاً في الشبكات المقيّدة. نفحص الإعداد.
        // FIREBASE is not probed over the network from here: delivery runs on the Oracle host, and
        // reaching fcm.googleapis.com from the API host yields a misleading failure.
        $token = trim((string) ($row['TOKEN'] ?? ''));

        if ($token === '') {
            return $this->result($type, false, null, 0, 'misconfigured', 'مفتاح الخدمة (TOKEN) غير مضبوط في الاشتراك.', $checkedAt);
        }

        return $this->result(
            $type,
            true,
            null,
            0,
            'ready',
            'الإعداد مكتمل. الإشعارات تُرسل من خادم أوراكل عبر مفتاح الخدمة، ولا تُفحص شبكياً من هنا.',
            $checkedAt
        );
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /**
     * @return array{service_type: string|null, reachable: bool, http_status: int|null, latency_ms: int, verdict: string, detail_ar: string, checked_at: string}
     */
    private function result(string $type, bool $reachable, ?int $httpStatus, int $latencyMs, string $verdict, string $detailAr, string $checkedAt): array
    {
        return [
            'service_type' => $type !== '' ? $type : null,
            'reachable' => $reachable,
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'verdict' => $verdict,
            'detail_ar' => $detailAr,
            'checked_at' => $checkedAt,
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
