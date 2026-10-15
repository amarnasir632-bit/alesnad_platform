<?php
declare(strict_types=1);

/** Cloudflare Stream adapter. Provider credentials are returned only during provisioning. */
interface LiveMeetingProvider
{
    /** @return array{provider_code:string,provider_session_ref:string,meeting_url:string,ingest_url:string,stream_key:string} */
    public function createMeeting(array $session): array;
    public function joinUrl(array $session, array $participant): string;
    /** @return list<array<string,mixed>> */
    public function listReadyRecordings(array $session): array;
    /** @return array{stream_url:string,expires_at:string,filename:string} */
    public function recordingSource(array $recording, array $participant): array;
    /** @return array{uid:string,upload_url:string} */
    public function createDirectUpload(string $title, string $creator, int $maxDurationSeconds): array;
    public function videoPlaybackUrl(string $uid, int $lifetimeSeconds = 3600): array;
    public function getUploadedVideo(string $uid): array;
}

final class CloudflareStreamProvider implements LiveMeetingProvider
{
    private string $accountId;
    private string $token;
    private string $customerCode;

    public function __construct()
    {
        $this->accountId = trim((string) env_value('CLOUDFLARE_ACCOUNT_ID', ''));
        $this->token = trim((string) env_value('CLOUDFLARE_API_TOKEN', ''));
        $this->customerCode = trim((string) env_value('CLOUDFLARE_STREAM_CUSTOMER_CODE', ''));
        if ($this->accountId === '' || $this->token === '' || $this->customerCode === '') {
            throw new ApiException(503, 'broadcast_provider_not_configured', 'إعداد Cloudflare Stream غير مكتمل.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{3,80}$/D', $this->accountId)
            || !preg_match('/^[A-Za-z0-9_-]{3,100}$/D', $this->customerCode)) {
            throw new ApiException(503, 'broadcast_provider_not_configured', 'إعداد Cloudflare Stream غير صالح.');
        }
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($this->accountId) . $path;
        $headers = "Authorization: Bearer {$this->token}\r\nAccept: application/json\r\n";
        if ($body !== null) $headers .= "Content-Type: application/json\r\n";
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($raw === false || !preg_match('/\s([0-9]{3})\s/', $statusLine, $match)) {
            throw new ApiException(503, 'broadcast_provider_unavailable', 'تعذر الاتصال بمزود البث.');
        }
        try { $response = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiException(503, 'broadcast_provider_invalid_response', 'أعاد مزود البث استجابة غير صالحة.'); }
        if ((int) $match[1] < 200 || (int) $match[1] >= 300 || !is_array($response) || ($response['success'] ?? false) !== true) {
            app_log_error('cloudflare_stream_api_error', new RuntimeException('Cloudflare Stream request failed with HTTP ' . $match[1]));
            throw new ApiException(502, 'broadcast_provider_request_failed', 'رفض مزود البث الطلب. تحقق من إعداد الحساب والصلاحيات.');
        }
        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function signedToken(string $uid, int $lifetimeSeconds = 3600): string
    {
        $lifetimeSeconds = max(60, min(86400, $lifetimeSeconds));
        $result = $this->request('POST', '/stream/' . rawurlencode($uid) . '/token', ['exp' => time() + $lifetimeSeconds]);
        $token = $result['token'] ?? null;
        if (!is_string($token) || $token === '') throw new ApiException(502, 'broadcast_provider_invalid_response', 'لم يصدر مزود البث رابط مشاهدة.');
        return $token;
    }

    public function createDirectUpload(string $title, string $creator, int $maxDurationSeconds): array
    {
        $expiry = gmdate(DateTimeInterface::ATOM, time() + 14400);
        $origin = parse_url((string) env_value('APP_URL', ''), PHP_URL_HOST);
        $result = $this->request('POST', '/stream/direct_upload', [
            'maxDurationSeconds' => $maxDurationSeconds,
            'requireSignedURLs' => true,
            'creator' => $creator,
            'expiry' => $expiry,
            'allowedOrigins' => is_string($origin) && $origin !== '' ? [$origin] : [],
            'meta' => ['name' => $title],
        ]);
        if (!is_string($result['uid'] ?? null) || $result['uid'] === ''
            || !is_string($result['uploadURL'] ?? null) || $result['uploadURL'] === '') {
            throw new ApiException(502, 'broadcast_provider_invalid_response', 'لم يصدر Cloudflare رابط رفع الفيديو.');
        }
        return ['uid' => $result['uid'], 'upload_url' => $result['uploadURL']];
    }

    public function videoPlaybackUrl(string $uid, int $lifetimeSeconds = 3600): array
    {
        $lifetimeSeconds = max(60, min(86400, $lifetimeSeconds));
        $signed = $this->signedToken($uid, $lifetimeSeconds);
        return [
            'url' => 'https://customer-' . $this->customerCode . '.cloudflarestream.com/' . rawurlencode($signed) . '/iframe',
            'expires_at' => gmdate(DateTimeInterface::ATOM, time() + $lifetimeSeconds),
        ];
    }

    public function getUploadedVideo(string $uid): array
    {
        return $this->request('GET', '/stream/' . rawurlencode($uid));
    }

    public function createMeeting(array $session): array
    {
        $input = $this->request('POST', '/stream/live_inputs', [
            'meta' => ['name' => (string) ($session['title'] ?? 'Academic session')],
            'recording' => [
                'mode' => 'automatic',
                'requireSignedURLs' => true,
                'hideLiveViewerCount' => true,
            ],
        ]);
        $uid = $input['uid'] ?? null;
        $rtmps = $input['rtmps'] ?? null;
        if (!is_string($uid) || $uid === '' || !is_array($rtmps)
            || !is_string($rtmps['url'] ?? null) || !is_string($rtmps['streamKey'] ?? null)) {
            throw new ApiException(502, 'broadcast_provider_invalid_response', 'لم تصل بيانات إعداد البث من Cloudflare.');
        }
        return [
            'provider_code' => 'cloudflare_stream',
            'provider_session_ref' => $uid,
            'meeting_url' => '',
            'ingest_url' => $rtmps['url'],
            'stream_key' => $rtmps['streamKey'],
        ];
    }

    public function joinUrl(array $session, array $participant): string
    {
        $uid = $session['provider_session_ref'] ?? null;
        if (!is_string($uid) || $uid === '') throw new ApiException(503, 'stream_not_provisioned', 'لم يُجهز مدخل البث لهذه الحصة بعد.');
        $signed = $this->signedToken($uid, max(3600, ((int) ($session['duration_minutes'] ?? 60) + 15) * 60));
        return 'https://customer-' . $this->customerCode . '.cloudflarestream.com/' . rawurlencode($signed) . '/iframe';
    }

    public function listReadyRecordings(array $session): array
    {
        $uid = $session['provider_session_ref'] ?? null;
        if (!is_string($uid) || $uid === '') throw new ApiException(503, 'stream_not_provisioned', 'لم يُجهز مدخل البث لهذه الحصة بعد.');
        $result = $this->request('GET', '/stream/live_inputs/' . rawurlencode($uid) . '/videos');
        $videos = $result;
        if (!is_array($videos)) throw new ApiException(502, 'broadcast_provider_invalid_response', 'قائمة التسجيلات من Cloudflare غير صالحة.');
        return array_values(array_filter($videos, static fn ($video): bool => is_array($video)
            && ($video['readyToStream'] ?? false) === true
            && is_string($video['uid'] ?? null)
            && ($video['status']['state'] ?? null) === 'ready'));
    }

    public function recordingSource(array $recording, array $participant): array
    {
        $uid = $recording['provider_recording_ref'] ?? null;
        if (!is_string($uid) || $uid === '') throw new ApiException(404, 'recording_not_found', 'مرجع التسجيل غير صالح.');
        $lifetime = 3600;
        $signed = $this->signedToken($uid, $lifetime);
        return [
            'stream_url' => 'https://customer-' . $this->customerCode . '.cloudflarestream.com/' . rawurlencode($signed) . '/iframe',
            'expires_at' => gmdate(DateTimeInterface::ATOM, time() + $lifetime),
            'filename' => (string) ($recording['title'] ?? 'session-recording'),
        ];
    }
}

function live_meeting_provider(): LiveMeetingProvider
{
    $provider = strtolower(trim((string) env_value('STREAM_PROVIDER', '')));
    if ($provider !== 'cloudflare_stream') {
        throw new ApiException(503, 'broadcast_provider_not_configured', 'لم يتم تفعيل Cloudflare Stream في إعدادات الخادم.');
    }
    return new CloudflareStreamProvider();
}
