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
    if (database_boolean($record['requires_subscription'])
        && in_array('student', $actor['roles'] ?? [], true)
        && !has_active_subscription((int) $actor['id'])) {
        throw new ApiException(403, 'active_subscription_required', 'يلزم اشتراك نشط لتنزيل هذا المحتوى.');
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

function api_search_past_exams(array $filters): array
{
    return content_search_past_exams($filters);
}
