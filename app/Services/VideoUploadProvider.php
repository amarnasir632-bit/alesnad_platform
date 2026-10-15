<?php
declare(strict_types=1);

interface VideoUploadProvider
{
    public function code(): string;

    /** @return array{provider_upload_id:string,upload_url:string,upload_method:string} */
    public function createDirectUpload(string $title, string $correlationToken): array;

    /** @return array{asset_id:string,playback_id:string,status:string,duration_seconds:?float,file_size_bytes:?int} */
    public function completeDirectUpload(string $uploadId): array;

    public function playbackUrl(string $playbackId, int $durationSeconds = 0): string;
}

final class MuxVideoUploadProvider implements VideoUploadProvider
{
    private string $tokenId;
    private string $tokenSecret;
    private string $signingKeyId;
    private string $signingKey;

    public function __construct()
    {
        $this->tokenId = trim((string) env_value('MUX_TOKEN_ID', ''));
        $this->tokenSecret = trim((string) env_value('MUX_TOKEN_SECRET', ''));
        $this->signingKeyId = trim((string) env_value('MUX_SIGNING_KEY_ID', ''));
        $encodedKey = trim((string) env_value('MUX_SIGNING_KEY_PRIVATE', ''));
        $decodedKey = base64_decode($encodedKey, true);
        if ($this->tokenId === '' || $this->tokenSecret === '' || $this->signingKeyId === ''
            || !is_string($decodedKey) || $decodedKey === '') {
            throw new ApiException(503, 'video_upload_provider_not_configured', 'إعداد Mux لرفع الفيديو ومشاهدته غير مكتمل.');
        }
        $this->signingKey = $decodedKey;
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = 'Authorization: Basic ' . base64_encode($this->tokenId . ':' . $this->tokenSecret) . "\r\nAccept: application/json\r\n";
        if ($body !== null) $headers .= "Content-Type: application/json\r\n";
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('https://api.mux.com/video/v1' . $path, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($raw === false || !preg_match('/\s([0-9]{3})\s/', $statusLine, $match)) {
            throw new ApiException(503, 'video_provider_unavailable', 'تعذر الاتصال بمزود الفيديو.');
        }
        try { $response = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiException(503, 'video_provider_invalid_response', 'أعاد مزود الفيديو استجابة غير صالحة.'); }
        if ((int) $match[1] < 200 || (int) $match[1] >= 300 || !is_array($response)) {
            app_log_error('mux_video_api_error', new RuntimeException('Mux request failed with HTTP ' . $match[1]));
            throw new ApiException(502, 'video_provider_request_failed', 'رفض Mux الطلب. تحقق من إعداد الحساب والصلاحيات.');
        }
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function appOrigin(): string
    {
        $url = trim((string) env_value('APP_URL', ''));
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new ApiException(503, 'video_upload_provider_not_configured', 'يجب ضبط APP_URL بعنوان الموقع قبل تفعيل رفع Mux.');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) $origin .= ':' . (int) $parts['port'];
        return $origin;
    }

    public function createDirectUpload(string $title, string $correlationToken): array
    {
        $upload = $this->request('POST', '/uploads', [
            'timeout' => 14400,
            'cors_origin' => $this->appOrigin(),
            'new_asset_settings' => [
                'playback_policies' => ['signed'],
                'video_quality' => 'basic',
                'passthrough' => $correlationToken,
                'meta' => ['title' => $title],
            ],
        ]);
        if (!is_string($upload['id'] ?? null) || $upload['id'] === ''
            || !is_string($upload['url'] ?? null) || $upload['url'] === '') {
            throw new ApiException(502, 'video_provider_invalid_response', 'لم يصدر Mux رابط رفع الفيديو.');
        }
        return [
            'provider_upload_id' => $upload['id'],
            'upload_url' => $upload['url'],
            'upload_method' => 'PUT',
        ];
    }

    public function code(): string
    {
        return 'mux';
    }

    public function completeDirectUpload(string $uploadId): array
    {
        $upload = $this->request('GET', '/uploads/' . rawurlencode($uploadId));
        $status = $upload['status'] ?? '';
        if ($status !== 'asset_created') {
            if (in_array($status, ['errored', 'cancelled', 'timed_out'], true)) {
                throw new ApiException(422, 'video_upload_failed', 'تعذر إنشاء الفيديو في Mux. أعد رفع الملف.');
            }
            throw new ApiException(422, 'video_upload_incomplete', 'لم يكتمل رفع الفيديو إلى Mux بعد.');
        }
        $assetId = $upload['asset_id'] ?? null;
        if (!is_string($assetId) || $assetId === '') {
            throw new ApiException(422, 'video_upload_incomplete', 'لم يكتمل تجهيز الفيديو في Mux بعد.');
        }
        $asset = $this->request('GET', '/assets/' . rawurlencode($assetId));
        if (($asset['status'] ?? '') === 'errored') {
            throw new ApiException(422, 'video_upload_failed', 'تعذر تجهيز الفيديو في Mux. أعد رفع ملف صالح.');
        }
        $playbackId = null;
        foreach (($asset['playback_ids'] ?? []) as $playback) {
            if (is_array($playback) && ($playback['policy'] ?? null) === 'signed'
                && is_string($playback['id'] ?? null) && $playback['id'] !== '') {
                $playbackId = $playback['id'];
                break;
            }
        }
        if (!is_string($playbackId)) {
            throw new ApiException(422, 'video_upload_incomplete', 'لم يجهز Mux رابط مشاهدة الفيديو بعد.');
        }
        $duration = filter_var($asset['duration'] ?? null, FILTER_VALIDATE_FLOAT);
        return [
            'asset_id' => $assetId,
            'playback_id' => $playbackId,
            'status' => is_string($asset['status'] ?? null) ? $asset['status'] : 'preparing',
            'duration_seconds' => $duration === false ? null : (float) $duration,
            'file_size_bytes' => null,
        ];
    }

    private function signedToken(string $playbackId, int $durationSeconds): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $lifetime = max(3600, min(86400, $durationSeconds + 900));
        $payload = self::base64UrlEncode(json_encode([
            'sub' => $playbackId,
            'aud' => 'v',
            'exp' => time() + $lifetime,
            'kid' => $this->signingKeyId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $message = $header . '.' . $payload;
        $key = openssl_pkey_get_private($this->signingKey);
        if ($key === false || !openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ApiException(503, 'video_upload_provider_not_configured', 'تعذر توقيع رابط مشاهدة Mux.');
        }
        return $message . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function playbackUrl(string $playbackId, int $durationSeconds = 0): string
    {
        if ($playbackId === '') throw new ApiException(404, 'video_not_found', 'رابط مشاهدة الفيديو غير صالح.');
        $token = $this->signedToken($playbackId, $durationSeconds);
        return 'https://player.mux.com/' . rawurlencode($playbackId) . '?token=' . rawurlencode($token);
    }
}

final class CloudflareStreamVideoUploadProvider implements VideoUploadProvider
{
    private LiveMeetingProvider $streamProvider;

    public function __construct()
    {
        $this->streamProvider = live_meeting_provider();
    }

    public function code(): string
    {
        return 'cloudflare_stream';
    }

    public function createDirectUpload(string $title, string $correlationToken): array
    {
        $upload = $this->streamProvider->createDirectUpload($title, $correlationToken, 14400);
        return [
            'provider_upload_id' => $upload['uid'],
            'upload_url' => $upload['upload_url'],
            'upload_method' => 'POST',
        ];
    }

    public function completeDirectUpload(string $uploadId): array
    {
        $video = $this->streamProvider->getUploadedVideo($uploadId);
        if (($video['uid'] ?? null) !== $uploadId) {
            throw new ApiException(422, 'video_upload_incomplete', 'لم يكتمل رفع الفيديو إلى Cloudflare بعد.');
        }
        $state = $video['status']['state'] ?? '';
        if (in_array($state, ['pendingupload', 'inprogress', 'error'], true)) {
            throw new ApiException(422, 'video_upload_incomplete', 'لم يكتمل رفع الفيديو إلى Cloudflare بعد.');
        }
        $duration = filter_var($video['duration'] ?? null, FILTER_VALIDATE_FLOAT);
        $size = filter_var($video['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return [
            'asset_id' => $uploadId,
            'playback_id' => $uploadId,
            'status' => is_string($state) ? $state : '',
            'duration_seconds' => $duration === false ? null : (float) $duration,
            'file_size_bytes' => $size === false ? null : $size,
        ];
    }

    public function playbackUrl(string $playbackId, int $durationSeconds = 0): string
    {
        $playback = $this->streamProvider->videoPlaybackUrl($playbackId, max(3600, $durationSeconds + 900));
        return $playback['url'];
    }
}

function video_upload_provider(?string $providerCode = null): VideoUploadProvider
{
    $providerCode = strtolower(trim($providerCode ?? (string) env_value('VIDEO_UPLOAD_PROVIDER', 'cloudflare_stream')));
    return match ($providerCode) {
        'cloudflare_stream' => new CloudflareStreamVideoUploadProvider(),
        'mux' => new MuxVideoUploadProvider(),
        default => throw new ApiException(503, 'video_upload_provider_not_configured', 'مزود رفع الفيديو غير مدعوم أو غير مهيأ.'),
    };
}