<?php
declare(strict_types=1);

function api_list_sessions(): never
{
    $actor = refresh_authenticated_user();
    if ($actor === null) throw new ApiException(401, 'unauthenticated', 'سجل الدخول أولًا.');
    if (in_array('student', $actor['roles'] ?? [], true)) require_permission('sessions.join');
    else require_permission('sessions.manage');
    json_response(200, ['data' => ['sessions' => sm_list_sessions($actor)]]);
}

function api_create_session(array $input): never
{
    $actor = require_permission('sessions.manage');
    try { json_response(201, ['data' => ['session' => sm_create_session($actor, $input)]]); }
    catch (PDOException $error) {
        if ($error->getCode() === '23514') throw new ApiException(403, 'teaching_assignment_required', 'الأستاذ غير مسند لهذه المادة.');
        if ($error->getCode() === '23503') throw new ApiException(422, 'invalid_reference', 'مرجع المادة أو الأستاذ غير موجود.');
        throw $error;
    }
}

function api_update_session(int $id, array $input): never
{
    $actor = require_permission('sessions.manage');
    json_response(200, ['data' => ['session' => sm_update_session($actor, $id, $input)]]);
}

function api_join_session(int $id): never
{
    $actor = refresh_authenticated_user();
    if ($actor === null) throw new ApiException(401, 'unauthenticated', 'سجل الدخول أولًا.');
    if (in_array('student', $actor['roles'] ?? [], true)) require_permission('sessions.join');
    else require_permission('sessions.manage');
    json_response(200, ['data' => ['join' => sm_join_session($actor, $id)]]);
}

function api_provision_session_stream(int $id): never
{
    $actor = require_permission('sessions.manage');
    json_response(201, ['data' => ['stream' => sm_provision_stream($actor, $id)]]);
}

function api_sync_session_recordings(int $id): never
{
    $actor = require_permission('sessions.manage');
    json_response(200, ['data' => ['sync' => sm_sync_stream_recordings($actor, $id)]]);
}

function api_add_session_recording(int $id, array $file, array $input): never
{
    $actor = require_permission('sessions.manage');
    json_response(201, ['data' => ['recording' => sm_add_recording($actor, $id, $file, $input)]]);
}

function api_list_session_recordings(int $id): never
{
    $actor = refresh_authenticated_user();
    if ($actor === null) throw new ApiException(401, 'unauthenticated', 'سجل الدخول أولًا.');
    if (in_array('student', $actor['roles'] ?? [], true)) require_permission('sessions.join');
    else require_permission('sessions.manage');
    json_response(200, ['data' => ['recordings' => sm_list_recordings($actor, $id)]]);
}

function api_download_session_recording(int $id): never
{
    $actor = refresh_authenticated_user();
    if ($actor === null) throw new ApiException(401, 'unauthenticated', 'سجل الدخول أولًا.');
    if (in_array('student', $actor['roles'] ?? [], true)) require_permission('sessions.join');
    else require_permission('sessions.manage');
    $recording = sm_recording_download($actor, $id);
    if (isset($recording['provider_source'])) {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $recording['provider_source']['stream_url'], true, 302);
        exit;
    }
    audit_log('session.recording.downloaded', 'session_recording', $id, [], (int) $actor['id']);
    $path = resolve_private_file((string) $recording['storage_key']);
    if ($path === null) throw new ApiException(404, 'not_found', 'ملف التسجيل غير متاح.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    stream_private_file((string) $recording['storage_key'], is_string($mime) ? $mime : 'application/octet-stream', (string) $recording['title']);
}

function api_list_messages(): never
{
    $actor = require_permission('messages.use');
    $peerId = filter_var($_GET['peer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($peerId === false) throw new ApiException(422, 'validation_failed', 'peer_id مطلوب ويجب أن يكون عددًا موجبًا.');
    $before = filter_var($_GET['before_id'] ?? (string) PHP_INT_MAX, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($before === false) throw new ApiException(422, 'validation_failed', 'before_id غير صالح.');
    json_response(200, ['data' => ['messages' => sm_list_messages($actor, (int) $peerId, 50, (int) $before)]]);
}

function api_send_message(array $input): never
{
    $actor = require_permission('messages.use');
    json_response(201, ['data' => ['message' => sm_send_message($actor, $input)]]);
}

function api_read_message(int $id): never
{
    $actor = require_permission('messages.use');
    json_response(200, ['data' => ['message' => sm_mark_message_read($actor, $id)]]);
}

function api_delete_message(int $id): never
{
    $actor = require_permission('messages.use');
    sm_delete_message($actor, $id);
    json_response(200, ['data' => ['deleted' => true]]);
}
