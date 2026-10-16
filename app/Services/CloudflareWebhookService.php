<?php
declare(strict_types=1);

/**
 * Handles incoming webhooks from Cloudflare Stream for processing state changes.
 * Webhook requests are verified using the Webhook-Signature header (HMAC-SHA256).
 */
function handle_cloudflare_stream_webhook(?string $rawBody = null, ?string $signatureHeader = null): array
{
    $secret = trim((string) env_value('CLOUDFLARE_STREAM_WEBHOOK_SECRET', ''));
    if ($secret === '') {
        throw new ApiException(503, 'webhook_not_configured', 'إعداد التحقق من Webhook غير مهيأ.');
    }

    if ($rawBody === null) {
        $rawBody = (string) file_get_contents('php://input');
    }

    if ($signatureHeader === null) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $signatureHeader = $_SERVER['HTTP_WEBHOOK_SIGNATURE']
            ?? $headers['Webhook-Signature']
            ?? $headers['webhook-signature']
            ?? '';
    }

    if ($signatureHeader === '') {
        throw new ApiException(401, 'missing_signature', 'ترويسة التوقيع Webhook-Signature مفقودة.');
    }

    $parsed = [];
    foreach (explode(',', $signatureHeader) as $pair) {
        $parts = explode('=', trim($pair), 2);
        if (count($parts) === 2) {
            $parsed[trim($parts[0])] = trim($parts[1]);
        }
    }

    $timestamp = $parsed['time'] ?? null;
    $actualSignature = $parsed['sig1'] ?? null;

    if ($timestamp === null || $actualSignature === null || !ctype_digit($timestamp)) {
        throw new ApiException(400, 'invalid_signature_format', 'صيغة ترويسة التوقيع غير صالحة.');
    }

    // Replay attack protection (within 5 minutes)
    if (abs(time() - (int) $timestamp) > 300) {
        throw new ApiException(401, 'expired_signature', 'توقيع الطلب منتهي الصلاحية.');
    }

    $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    if (!hash_equals($expectedSignature, $actualSignature)) {
        throw new ApiException(401, 'invalid_signature', 'فشل التحقق من توقيع Webhook.');
    }

    try {
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiException(400, 'invalid_json', 'بيانات Webhook غير صالحة.');
    }

    $uid = $payload['uid'] ?? null;
    if (!is_string($uid) || $uid === '') {
        return ['status' => 'ignored', 'reason' => 'missing_uid'];
    }

    $state = $payload['status']['state'] ?? null;
    $normalizedState = match ($state) {
        'ready' => 'ready',
        'inprogress' => 'processing',
        'queued' => 'queued',
        'error' => 'error',
        default => is_string($state) ? $state : 'unknown',
    };

    $duration = filter_var($payload['duration'] ?? null, FILTER_VALIDATE_FLOAT);
    $size = filter_var($payload['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    $db = database_connection();
    $db->beginTransaction();
    try {
        // 1. Update learning resources if this video belongs to a learning resource
        $resStmt = $db->prepare("
            UPDATE learning_resources
            SET video_processing_status = :state,
                video_duration_seconds = COALESCE(:duration, video_duration_seconds),
                file_size_bytes = COALESCE(:size, file_size_bytes),
                updated_at = CURRENT_TIMESTAMP
            WHERE cloudflare_video_uid = :uid OR video_asset_id = :uid
            RETURNING id
        ");
        $resStmt->execute([
            'state' => $normalizedState,
            'duration' => $duration === false ? null : $duration,
            'size' => $size === false ? null : $size,
            'uid' => $uid,
        ]);
        $resourceIds = $resStmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Update session recordings if this video belongs to a recorded session
        $recordingStatus = match ($normalizedState) {
            'ready' => 'READY',
            'error' => 'FAILED',
            default => 'PROCESSING',
        };
        $recStmt = $db->prepare("
            UPDATE session_recordings
            SET status = :status,
                duration_seconds = COALESCE(:duration_int, duration_seconds),
                file_size_bytes = COALESCE(:size, file_size_bytes)
            WHERE provider_recording_ref = :uid
            RETURNING id
        ");
        $recStmt->execute([
            'status' => $recordingStatus,
            'duration_int' => $duration === false ? null : (int) ceil($duration),
            'size' => $size === false ? null : $size,
            'uid' => $uid,
        ]);
        $recordingIds = $recStmt->fetchAll(PDO::FETCH_COLUMN);

        $db->commit();

        return [
            'status' => 'processed',
            'uid' => $uid,
            'processing_state' => $normalizedState,
            'affected_resources' => count($resourceIds),
            'affected_recordings' => count($recordingIds),
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
