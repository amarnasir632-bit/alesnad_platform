<?php
declare(strict_types=1);

function content_file_from_request(): ?array
{
    $file = $_FILES['file'] ?? null;
    if ($file === null) return null;
    if (!is_array($file)) throw new ApiException(422, 'invalid_upload', 'الملف المرسل غير صالح.');
    if (($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
    return $file;
}

function content_database_error(PDOException $error): never
{
    if ($error->getCode() === '23505') throw new ApiException(409, 'content_conflict', 'يوجد محتوى مسجل بهذه البيانات بالفعل.');
    if ($error->getCode() === '23503') throw new ApiException(422, 'invalid_reference', 'أحد مراجع المحتوى غير موجود.');
    if ($error->getCode() === '23514') throw new ApiException(409, 'content_state_conflict', 'رفضت قاعدة البيانات تغيير حالة المحتوى أو بيانات المراجعة.');
    throw $error;
}

function api_upload_resource(array $input): never
{
    $actor = require_permission('content.upload');
    $file = content_file_from_request();
    if ($file === null) throw new ApiException(422, 'file_required', 'أرفق ملفًا.');
    try {
        $record = content_create_resource($actor, $input, $file);
    } catch (PDOException $error) {
        content_database_error($error);
    }
    json_response(201, ['data' => ['resource' => $record]]);
}

function api_create_video_upload(array $input): never
{
    $actor = require_permission('content.upload');
    json_response(201, ['data' => ['upload' => content_create_video_upload($actor, $input)]]);
}

function api_complete_video_upload(array $input): never
{
    $actor = require_permission('content.upload');
    $token = $input['upload_token'] ?? null;
    if (!is_string($token)) throw new ApiException(422, 'invalid_upload_token', 'رمز رفع الفيديو مطلوب.');
    json_response(201, ['data' => ['resource' => content_complete_video_upload($actor, $token)]]);
}

function api_video_upload_status(string $token): never
{
    $actor = require_permission('content.upload');
    json_response(200, ['data' => ['upload_status' => content_video_upload_status($actor, $token)]]);
}

function api_upload_past_exam(array $input): never
{
    $actor = require_permission('content.upload');
    $file = content_file_from_request();
    if ($file === null) throw new ApiException(422, 'file_required', 'أرفق ملف الامتحان.');
    try {
        $record = content_create_past_exam($actor, $input, $file);
    } catch (PDOException $error) {
        content_database_error($error);
    }
    json_response(201, ['data' => ['past_exam' => $record]]);
}

function api_create_question(array $input): never
{
    $actor = require_permission('questions.manage');
    try {
        $record = content_create_question($actor, $input);
    } catch (PDOException $error) {
        content_database_error($error);
    }
    json_response(201, ['data' => ['question' => $record]]);
}

function api_update_content(string $kind, int $id, array $input, bool $replaceFile = false): never
{
    $actor = require_permission($kind === 'question' ? 'questions.manage' : 'content.upload');
    try {
        $record = content_update_asset($actor, $kind, $id, $input, $replaceFile ? content_file_from_request() : null);
    } catch (PDOException $error) {
        content_database_error($error);
    }
    $key = match ($kind) { 'resource' => 'resource', 'past_exam' => 'past_exam', 'question' => 'question' };
    json_response(200, ['data' => [$key => $record]]);
}

function api_submit_content(string $kind, int $id): never
{
    $actor = require_permission($kind === 'question' ? 'questions.manage' : 'content.upload');
    try {
        $record = content_submit_for_review($actor, $kind, $id);
    } catch (PDOException $error) {
        content_database_error($error);
    }
    json_response(200, ['data' => ['content' => $record]]);
}

function api_review_content(string $kind, int $id, array $input): never
{
    $actor = require_permission('content.review');
    $outcome = is_string($input['status'] ?? null) ? strtoupper(trim($input['status'])) : '';
    $feedback = content_text($input['feedback'] ?? null, 'feedback', 5000);
    try {
        $result = content_review($actor, $kind, $id, $outcome, $feedback);
    } catch (PDOException $error) {
        content_database_error($error);
    }
    json_response(200, ['data' => $result]);
}

function api_download_content(string $kind, int $id, ?int $variantId = null): never
{
    $permission = $kind === 'resource' ? 'content.read' : 'exams.read';
    $actor = require_permission($permission);
    $record = content_download_record($kind, $id, $variantId);
    if ($record === null) throw new ApiException(404, 'not_found', 'المحتوى غير متاح للتنزيل.');
    if (in_array('student', $actor['roles'] ?? [], true)
        && !has_manual_content_access((int) $actor['id'])) {
        throw new ApiException(403, 'content_access_required', 'يجب أن يمنحك المدير أو المراجع صلاحية المحتوى أولًا.');
    }
    if (!catalog_actor_can_access_class_subject((int) $record['class_subject_id'], $actor)) {
        throw new ApiException(404, 'not_found', 'المحتوى غير متاح للتنزيل.');
    }
    if (content_has_video_asset($record)) {
        content_audit_download($actor, $kind, $id, $variantId, false);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . content_video_playback_url($record), true, 302);
        exit;
    }
    $path = resolve_private_file((string) $record['storage_key']);
    if ($path === null) throw new ApiException(404, 'not_found', 'الملف غير متاح.');
    $size = filesize($path);
    if ($size === false) throw new ApiException(404, 'not_found', 'الملف غير متاح.');
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
    try {
        $range = parse_byte_range(is_string($rangeHeader) ? $rangeHeader : null, $size);
    } catch (ApiException $error) {
        if ($error->status === 416) header('Content-Range: bytes */' . $size);
        throw $error;
    }
    content_audit_download($actor, $kind, $id, $variantId, $range !== null);
    stream_private_file((string) $record['storage_key'], (string) $record['mime_type'], (string) $record['original_filename']);
}

function api_play_learning_media(int $resourceId): never
{
    $actor = require_permission('content.read');
    if (in_array('student', $actor['roles'] ?? [], true)
        && !has_manual_content_access((int) $actor['id'])) {
        throw new ApiException(403, 'content_access_required', 'يجب أن يمنحك المدير أو المراجع صلاحية المحتوى أولًا.');
    }
    $resource = catalog_visible_media_for_actor($resourceId, $actor);
    if ($resource === null) throw new ApiException(404, 'not_found', 'المحتوى غير متاح.');

    if ($resource['resource_type'] === 'VIDEO') {
        $playbackUrl = content_video_playback_url($resource);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $playbackUrl, true, 302);
        exit;
    }
    if (!is_string($resource['storage_key'] ?? null) || $resource['storage_key'] === ''
        || !is_string($resource['mime_type'] ?? null) || !str_starts_with($resource['mime_type'], 'audio/')) {
        throw new ApiException(404, 'media_not_found', 'الملف الصوتي غير متاح.');
    }
    stream_private_file((string) $resource['storage_key'], (string) $resource['mime_type'], (string) $resource['original_filename'], true);
}

function api_record_learning_media_view(int $resourceId): never
{
    $actor = require_permission('content.read');
    if (in_array('student', $actor['roles'] ?? [], true)
        && !has_manual_content_access((int) $actor['id'])) {
        throw new ApiException(403, 'content_access_required', 'يجب أن يمنحك المدير أو المراجع صلاحية المحتوى أولًا.');
    }
    if (!isset($_SESSION['learning_media_views']) || !is_array($_SESSION['learning_media_views'])) {
        $_SESSION['learning_media_views'] = [];
    }
    $views = &$_SESSION['learning_media_views'];
    if (isset($views[$resourceId])) {
        json_response(200, ['data' => ['counted' => false]]);
    }
    if (!catalog_record_media_view($resourceId, $actor)) {
        throw new ApiException(404, 'not_found', 'المحتوى غير متاح.');
    }
    if (count($views) >= 256) array_shift($views);
    $views[$resourceId] = true;
    json_response(200, ['data' => ['counted' => true]]);
}

function api_search_past_exams(array $filters): array
{
    return content_search_past_exams($filters);
}
