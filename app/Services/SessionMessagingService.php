<?php
declare(strict_types=1);

function sm_env_positive(string $key): int
{
    $value = env_value($key);
    if ($value === null || !preg_match('/^[1-9][0-9]*$/D', $value) || (string) (int) $value !== $value) {
        throw new ApiException(503, 'messaging_not_configured', "إعداد {$key} غير محدد أو غير صالح.");
    }
    return (int) $value;
}

function sm_session(array $actor, int $sessionId): array
{
    $db = database_connection();
    $statement = $db->prepare('SELECT ls.*, cs.class_id, c.stage_id, cs.is_active AS class_subject_active, c.is_active AS class_active, st.is_active AS stage_active, s.is_active AS subject_active FROM live_sessions ls JOIN class_subjects cs ON cs.id = ls.class_subject_id JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id JOIN subjects s ON s.id = cs.subject_id WHERE ls.id = :id');
    $statement->execute(['id' => $sessionId]);
    $session = $statement->fetch();
    if (!$session) throw new ApiException(404, 'not_found', 'الحصة غير موجودة.');
    if (!database_boolean($session['class_subject_active']) || !database_boolean($session['class_active']) || !database_boolean($session['stage_active']) || !database_boolean($session['subject_active'])) throw new ApiException(409, 'session_academic_context_inactive', 'المادة أو الصف المرتبط بالحصة غير نشط.');
    if (in_array('admin', $actor['roles'] ?? [], true)) return $session;
    $employeeId = null;
    if (in_array('teacher', $actor['roles'] ?? [], true)) {
        $employee = $db->prepare('SELECT e.id FROM employees e JOIN teaching_assignments ta ON ta.employee_id = e.id AND ta.class_subject_id = :class_subject_id WHERE e.user_id = :user_id AND e.is_active = TRUE');
        $employee->execute(['user_id' => $actor['id'], 'class_subject_id' => (int) $session['class_subject_id']]);
        $employeeId = $employee->fetchColumn();
        if ($employeeId !== false && (int) $employeeId === (int) $session['teacher_id']) return $session;
    }
    if (in_array('student', $actor['roles'] ?? [], true)) {
        $student = $db->prepare('SELECT id, class_id FROM students WHERE user_id = :user_id');
        $student->execute(['user_id' => $actor['id']]);
        $profile = $student->fetch();
        if ($profile && $profile['class_id'] !== null && (int) $profile['class_id'] === (int) $session['class_id']) return $session;
    }
    throw new ApiException(403, 'session_access_denied', 'لا تملك صلاحية الوصول إلى هذه الحصة.');
}

function sm_employee_for_class(PDO $db, array $actor, int $classSubjectId, ?int $requestedEmployeeId = null): int
{
    if (in_array('admin', $actor['roles'] ?? [], true)) {
        $employeeId = $requestedEmployeeId;
        if ($employeeId === null) throw new ApiException(422, 'teacher_required', 'يجب تحديد الأستاذ المسند للحصة.');
    } else {
        $employee = $db->prepare('SELECT e.id FROM employees e WHERE e.user_id = :user_id AND e.is_active = TRUE');
        $employee->execute(['user_id' => $actor['id']]);
        $employeeId = $employee->fetchColumn();
        if ($employeeId === false) throw new ApiException(403, 'employee_profile_required', 'يلزم ملف موظف نشط لإدارة الحصة.');
    }
    $assignment = $db->prepare('SELECT 1 FROM teaching_assignments ta JOIN class_subjects cs ON cs.id = ta.class_subject_id AND cs.is_active = TRUE JOIN classes c ON c.id = cs.class_id AND c.is_active = TRUE JOIN stages st ON st.id = c.stage_id AND st.is_active = TRUE JOIN subjects s ON s.id = cs.subject_id AND s.is_active = TRUE WHERE ta.employee_id = :employee_id AND ta.class_subject_id = :class_subject_id');
    $assignment->execute(['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId]);
    if ($assignment->fetchColumn() === false && !in_array('admin', $actor['roles'] ?? [], true)) throw new ApiException(403, 'teaching_assignment_required', 'يمكنك إدارة حصص موادك المسندة فقط.');
    if (!in_array('teacher', $actor['roles'] ?? [], true) && !in_array('admin', $actor['roles'] ?? [], true)) throw new ApiException(403, 'forbidden', 'إدارة الحصص متاحة للأستاذ ومدير النظام.');
    return (int) $employeeId;
}

function sm_text(mixed $value, string $field, int $max, bool $required = true): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value)) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون نصًا.");
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($length === false || ($required && $value === '') || $length > $max) throw new ApiException(422, 'validation_failed', "{$field} مطلوب وبحد أقصى {$max} محرفًا.");
    return $value;
}

function sm_timestamp(mixed $value, string $field): string
{
    if (!is_string($value)) throw new ApiException(422, 'validation_failed', "{$field} يجب أن يكون وقتًا نصيًا.");
    try { return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM); }
    catch (Throwable) { throw new ApiException(422, 'validation_failed', "{$field} غير صالح."); }
}

function sm_create_session(array $actor, array $input): array
{
    $classSubjectId = filter_var($input['class_subject_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($classSubjectId === false) throw new ApiException(422, 'validation_failed', 'class_subject_id غير صالح.');
    $title = sm_text($input['title'] ?? null, 'title', 150);
    $description = sm_text($input['description'] ?? null, 'description', 5000, false);
    $scheduledAt = sm_timestamp($input['scheduled_at'] ?? null, 'scheduled_at');
    $duration = filter_var($input['duration_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 32767]]);
    if ($duration === false) throw new ApiException(422, 'validation_failed', 'duration_minutes يجب أن يكون عددًا صحيحًا موجبًا.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $teacherId = in_array('admin', $actor['roles'] ?? [], true)
            ? filter_var($input['teacher_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        if ($teacherId === false) throw new ApiException(422, 'validation_failed', 'teacher_id غير صالح.');
        $lessonId = filter_var($input['lesson_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($lessonId === false) $lessonId = null;
        $employeeId = sm_employee_for_class($db, $actor, (int) $classSubjectId, $teacherId);
        $stmt = $db->prepare("INSERT INTO live_sessions (class_subject_id, lesson_id, teacher_id, title, description, scheduled_at, duration_minutes, status) VALUES (:class_subject_id, :lesson_id, :teacher_id, :title, :description, :scheduled_at, :duration_minutes, 'SCHEDULED') RETURNING id, class_subject_id, lesson_id, teacher_id, title, description, scheduled_at, duration_minutes, status, created_at");
        $stmt->execute(['class_subject_id' => $classSubjectId, 'lesson_id' => $lessonId, 'teacher_id' => $employeeId, 'title' => $title, 'description' => $description, 'scheduled_at' => $scheduledAt, 'duration_minutes' => $duration]);
        $row = $stmt->fetch();
        audit_log('session.created', 'live_session', (int) $row['id'], ['class_subject_id' => (int) $classSubjectId], (int) $actor['id']);
        $db->commit();
        return $row;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function sm_update_session(array $actor, int $sessionId, array $input): array
{
    $allowed = ['title', 'description', 'scheduled_at', 'duration_minutes', 'status', 'meeting_url', 'lesson_id'];
    foreach ($input as $key => $_) if ($key !== '_csrf' && !in_array($key, $allowed, true)) throw new ApiException(422, 'validation_failed', "الحقل {$key} غير قابل للتعديل.");
    unset($input['_csrf']);
    if ($input === []) throw new ApiException(422, 'validation_failed', 'أرسل حقلاً واحدًا على الأقل.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM live_sessions WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $sessionId]);
        $old = $stmt->fetch();
        if (!$old) throw new ApiException(404, 'not_found', 'الحصة غير موجودة.');
        if (in_array('admin', $actor['roles'] ?? [], true)) $teacherId = (int) $old['teacher_id'];
        else {
        $currentEmployeeId = sm_employee_for_class($db, $actor, (int) $old['class_subject_id']);
        if ($currentEmployeeId !== (int) $old['teacher_id']) throw new ApiException(403, 'session_teacher_required', 'يمكن للأستاذ تعديل حصصه فقط.');
            $teacherId = (int) $old['teacher_id'];
        }
        $changes = [];
        if (array_key_exists('title', $input)) $changes['title'] = sm_text($input['title'], 'title', 150);
        if (array_key_exists('description', $input)) $changes['description'] = sm_text($input['description'], 'description', 5000, false);
        if (array_key_exists('scheduled_at', $input)) $changes['scheduled_at'] = sm_timestamp($input['scheduled_at'], 'scheduled_at');
        if (array_key_exists('duration_minutes', $input)) {
            $duration = filter_var($input['duration_minutes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 32767]]);
            if ($duration === false) throw new ApiException(422, 'validation_failed', 'duration_minutes غير صالح.');
            $changes['duration_minutes'] = $duration;
        }
        if (array_key_exists('status', $input)) {
            $status = is_string($input['status']) ? strtoupper(trim($input['status'])) : '';
            if (!in_array($status, ['LIVE', 'COMPLETED', 'CANCELLED'], true)) throw new ApiException(422, 'validation_failed', 'حالة الحصة غير صالحة.');
            $changes['status'] = $status;
        }
        if (array_key_exists('meeting_url', $input)) {
            $url = sm_text($input['meeting_url'], 'meeting_url', 2048, false);
            if ($url !== null && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https'], true))) throw new ApiException(422, 'validation_failed', 'meeting_url يجب أن يكون رابط HTTPS صالحًا.');
            $changes['meeting_url'] = $url;
        }
        $set = [];
        foreach ($changes as $field => $_) $set[] = $field . ' = :' . $field;
        $set[] = 'updated_at = CURRENT_TIMESTAMP';
        $update = $db->prepare('UPDATE live_sessions SET ' . implode(', ', $set) . ' WHERE id = :id RETURNING id, class_subject_id, teacher_id, title, description, scheduled_at, duration_minutes, status, updated_at');
        $update->execute($changes + ['id' => $sessionId]);
        $row = $update->fetch();
        audit_log('session.updated', 'live_session', $sessionId, ['fields' => array_keys($changes)], (int) $actor['id']);
        $db->commit();
        return $row;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function sm_list_sessions(array $actor): array
{
    $db = database_connection();
    if (in_array('admin', $actor['roles'] ?? [], true)) {
        $stmt = $db->prepare('SELECT ls.id, ls.class_subject_id, ls.teacher_id, ls.title, ls.description, ls.scheduled_at, ls.duration_minutes, ls.status, cs.class_id, s.name AS subject_name FROM live_sessions ls JOIN class_subjects cs ON cs.id = ls.class_subject_id JOIN subjects s ON s.id = cs.subject_id ORDER BY ls.scheduled_at DESC LIMIT 200');
        $stmt->execute();
    } elseif (in_array('teacher', $actor['roles'] ?? [], true)) {
        $stmt = $db->prepare('SELECT ls.id, ls.class_subject_id, ls.teacher_id, ls.title, ls.description, ls.scheduled_at, ls.duration_minutes, ls.status, cs.class_id, s.name AS subject_name FROM live_sessions ls JOIN class_subjects cs ON cs.id = ls.class_subject_id JOIN subjects s ON s.id = cs.subject_id JOIN employees e ON e.id = ls.teacher_id JOIN teaching_assignments ta ON ta.employee_id = e.id AND ta.class_subject_id = cs.id WHERE e.user_id = :user_id ORDER BY ls.scheduled_at DESC LIMIT 200');
        $stmt->execute(['user_id' => $actor['id']]);
    } else {
        if (!has_manual_content_access((int) $actor['id'])) return [];
        $stmt = $db->prepare('SELECT ls.id, ls.class_subject_id, ls.teacher_id, ls.title, ls.description, ls.scheduled_at, ls.duration_minutes, ls.status, cs.class_id, s.name AS subject_name FROM live_sessions ls JOIN class_subjects cs ON cs.id = ls.class_subject_id JOIN subjects s ON s.id = cs.subject_id JOIN students st ON st.class_id = cs.class_id JOIN classes c ON c.id = st.class_id AND c.is_active = TRUE JOIN teaching_assignments ta ON ta.employee_id = ls.teacher_id AND ta.class_subject_id = cs.id WHERE st.user_id = :user_id ORDER BY ls.scheduled_at DESC LIMIT 200');
        $stmt->execute(['user_id' => $actor['id']]);
    }
    return $stmt->fetchAll();
}

function sm_join_session(array $actor, int $sessionId): array
{
    $session = sm_session($actor, $sessionId);
    if ($session['status'] === 'CANCELLED') throw new ApiException(409, 'session_cancelled', 'الحصة ملغاة.');
    $now = new DateTimeImmutable('now');
    $start = new DateTimeImmutable($session['scheduled_at']);
    if ($now < $start) throw new ApiException(409, 'session_not_started', 'لم يحن موعد الحصة.');
    if ($session['duration_minutes'] === null || $now > $start->modify('+' . (int) $session['duration_minutes'] . ' minutes')) throw new ApiException(409, 'session_ended', 'انتهى وقت الحصة.');
    if (in_array('student', $actor['roles'] ?? [], true) && !has_manual_content_access((int) $actor['id'])) throw new ApiException(403, 'content_access_required', 'يجب أن يمنحك المدير أو المراجع صلاحية المحتوى أولًا.');
    $joinUrl = is_string($session['meeting_url']) && trim($session['meeting_url']) !== ''
        ? $session['meeting_url']
        : live_meeting_provider()->joinUrl($session, $actor);
    audit_log('session.joined', 'live_session', $sessionId, [], (int) $actor['id']);
    return ['session_id' => $sessionId, 'meeting_url' => $joinUrl, 'scheduled_at' => $session['scheduled_at'], 'duration_minutes' => $session['duration_minutes']];
}

function sm_provision_stream(array $actor, int $sessionId): array
{
    $session = sm_session($actor, $sessionId);
    if (!in_array('admin', $actor['roles'] ?? [], true)) {
        $employeeId = sm_employee_for_class(database_connection(), $actor, (int) $session['class_subject_id']);
        if ($employeeId !== (int) $session['teacher_id']) throw new ApiException(403, 'session_teacher_required', 'يمكن للأستاذ المسند أو المدير إعداد بث الحصة فقط.');
    }
    if (!empty($session['provider_session_ref'])) throw new ApiException(409, 'stream_already_provisioned', 'أُعد مدخل بث لهذه الحصة من قبل.');
    if ($session['status'] === 'CANCELLED' || $session['status'] === 'COMPLETED') throw new ApiException(409, 'session_not_provisionable', 'لا يمكن إعداد بث لحصة ملغاة أو مكتملة.');

    // Do not hold a database transaction while calling Cloudflare.
    $meeting = live_meeting_provider()->createMeeting($session);
    $stmt = database_connection()->prepare("UPDATE live_sessions SET provider_code = :provider_code, provider_session_ref = :provider_ref, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND provider_session_ref IS NULL RETURNING id");
    $stmt->execute(['provider_code' => $meeting['provider_code'], 'provider_ref' => $meeting['provider_session_ref'], 'id' => $sessionId]);
    if ($stmt->fetchColumn() === false) throw new ApiException(409, 'stream_already_provisioned', 'أُعد مدخل بث لهذه الحصة بالتزامن.');
    audit_log('session.stream.provisioned', 'live_session', $sessionId, ['provider' => $meeting['provider_code']], (int) $actor['id']);
    // The RTMPS stream key is only returned once and is never stored by this application.
    return [
        'session_id' => $sessionId,
        'provider_code' => $meeting['provider_code'],
        'ingest_url' => $meeting['ingest_url'],
        'stream_key' => $meeting['stream_key'],
        'whip_url' => $meeting['whip_url'] ?? null,
    ];
}

function sm_sync_stream_recordings(array $actor, int $sessionId): array
{
    $session = sm_session($actor, $sessionId);
    if (!in_array('admin', $actor['roles'] ?? [], true)) {
        $employeeId = sm_employee_for_class(database_connection(), $actor, (int) $session['class_subject_id']);
        if ($employeeId !== (int) $session['teacher_id']) throw new ApiException(403, 'session_teacher_required', 'يمكن للأستاذ المسند أو المدير مزامنة تسجيلات الحصة فقط.');
    }
    if (($session['provider_code'] ?? null) !== 'cloudflare_stream' || empty($session['provider_session_ref'])) throw new ApiException(409, 'stream_not_provisioned', 'لم يُجهز بث Cloudflare لهذه الحصة.');
    $videos = live_meeting_provider()->listReadyRecordings($session);
    $db = database_connection();
    $insert = $db->prepare("INSERT INTO session_recordings (session_id, title, storage_key, provider_recording_ref, duration_seconds, file_size_bytes, status) VALUES (:session_id, :title, NULL, :provider_ref, :duration, :size, 'READY') ON CONFLICT (session_id, provider_recording_ref) WHERE provider_recording_ref IS NOT NULL DO NOTHING");
    $added = 0;
    foreach ($videos as $video) {
        $ref = (string) $video['uid'];
        $title = $video['meta']['name'] ?? ('تسجيل ' . substr($ref, 0, 12));
        if (!is_string($title) || trim($title) === '') $title = 'تسجيل ' . substr($ref, 0, 12);
        $insert->execute([
            'session_id' => $sessionId,
            'title' => mb_substr(trim($title), 0, 180),
            'provider_ref' => $ref,
            'duration' => isset($video['duration']) && is_numeric($video['duration']) ? max(0, (int) $video['duration']) : null,
            'size' => isset($video['size']) && is_numeric($video['size']) ? max(0, (int) $video['size']) : null,
        ]);
        $added += $insert->rowCount();
    }
    audit_log('session.recordings.synced', 'live_session', $sessionId, ['ready_count' => count($videos), 'added_count' => $added], (int) $actor['id']);
    return ['session_id' => $sessionId, 'ready_count' => count($videos), 'added_count' => $added];
}

function sm_add_recording(array $actor, int $sessionId, array $file, array $input): array
{
    $session = sm_session($actor, $sessionId);
    if (!in_array('admin', $actor['roles'] ?? [], true)) sm_employee_for_class(database_connection(), $actor, (int) $session['class_subject_id']);
    $title = sm_text($input['title'] ?? null, 'title', 180);
    $providerRef = sm_text($input['provider_recording_ref'] ?? null, 'provider_recording_ref', 255, false);
    $metadata = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $metadata = store_uploaded_file($file, 'VIDEO');
    if ($metadata === null && $providerRef === null) throw new ApiException(422, 'recording_source_required', 'ارفع ملف التسجيل أو أدخل مرجع تسجيل المزود.');
    $duration = $input['duration_seconds'] ?? null;
    if ($duration !== null && $duration !== '') {
        $duration = filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        if ($duration === false) { if ($metadata) discard_private_file($metadata['storage_key']); throw new ApiException(422, 'validation_failed', 'duration_seconds غير صالح.'); }
    } else $duration = null;
    $db = database_connection();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO session_recordings (session_id, title, storage_key, provider_recording_ref, duration_seconds, file_size_bytes, status, available_from) VALUES (:session_id, :title, :storage_key, :provider_ref, :duration, :size, 'READY', :available_from) RETURNING id, session_id, title, provider_recording_ref, duration_seconds, status, available_from, created_at");
        $stmt->execute(['session_id' => $sessionId, 'title' => $title, 'storage_key' => $metadata['storage_key'] ?? null, 'provider_ref' => $providerRef, 'duration' => $duration, 'size' => $metadata['size'] ?? null, 'available_from' => empty($input['available_from']) ? null : sm_timestamp($input['available_from'], 'available_from')]);
        $row = $stmt->fetch();
        audit_log('session.recording.added', 'session_recording', (int) $row['id'], ['session_id' => $sessionId], (int) $actor['id']);
        $db->commit();
        return $row;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); if ($metadata) discard_private_file($metadata['storage_key']); throw $error; }
}

function sm_list_recordings(array $actor, int $sessionId): array
{
    $session = sm_session($actor, $sessionId);
    if (in_array('student', $actor['roles'] ?? [], true)) {
        if ($session['status'] !== 'COMPLETED' && new DateTimeImmutable($session['scheduled_at'] . ' +' . (int) ($session['duration_minutes'] ?? 0) . ' minutes') > new DateTimeImmutable('now')) return [];
        if (!has_manual_content_access((int) $actor['id'])) throw new ApiException(403, 'content_access_required', 'يجب أن يمنحك المدير أو المراجع صلاحية المحتوى أولًا.');
    }
    $stmt = database_connection()->prepare('SELECT id, session_id, title, duration_seconds, file_size_bytes, status, available_from, created_at FROM session_recordings WHERE session_id = :session_id AND status = \'READY\' AND (available_from IS NULL OR available_from <= CURRENT_TIMESTAMP) ORDER BY created_at');
    $stmt->execute(['session_id' => $sessionId]);
    return $stmt->fetchAll();
}

function sm_recording_download(array $actor, int $recordingId): array
{
    $stmt = database_connection()->prepare('SELECT sr.*, ls.status AS session_status, ls.scheduled_at, ls.duration_minutes, ls.class_subject_id FROM session_recordings sr JOIN live_sessions ls ON ls.id = sr.session_id WHERE sr.id = :id AND sr.status = \'READY\' AND (sr.available_from IS NULL OR sr.available_from <= CURRENT_TIMESTAMP)');
    $stmt->execute(['id' => $recordingId]);
    $recording = $stmt->fetch();
    if (!$recording) throw new ApiException(404, 'not_found', 'التسجيل غير متاح.');
    $session = sm_session($actor, (int) $recording['session_id']);
    $end = (new DateTimeImmutable($session['scheduled_at']))->modify('+' . (int) ($session['duration_minutes'] ?? 0) . ' minutes');
    if ($session['status'] !== 'COMPLETED' && $end > new DateTimeImmutable('now')) throw new ApiException(403, 'recording_not_available', 'يتاح التسجيل بعد انتهاء الحصة.');
    if (in_array('student', $actor['roles'] ?? [], true) && !has_manual_content_access((int) $actor['id'])) throw new ApiException(403, 'content_access_required', 'يجب أن يمنحك المدير أو المراجع صلاحية المحتوى أولًا.');
    if ($recording['storage_key'] === null) {
        $provider = live_meeting_provider();
        $source = $provider->recordingSource($recording, $actor);
        return ['provider_source' => $source];
    }
    return $recording;
}

function sm_message_peer_allowed(PDO $db, int $userId, int $peerId): bool
{
    $stmt = $db->prepare("SELECT EXISTS (
      SELECT 1 FROM students st JOIN users su ON su.id = st.user_id AND su.is_active = TRUE
      JOIN class_subjects cs ON cs.class_id = st.class_id AND cs.is_active = TRUE
      JOIN teaching_assignments ta ON ta.class_subject_id = cs.id
      JOIN employees e ON e.id = ta.employee_id AND e.is_active = TRUE
      JOIN users tu ON tu.id = e.user_id AND tu.is_active = TRUE
      JOIN user_roles ur ON ur.user_id = tu.id JOIN roles r ON r.id = ur.role_id AND r.code = 'teacher'
      WHERE ((st.user_id = :student_user_id AND tu.id = :teacher_user_id) OR (st.user_id = :student_peer_id AND tu.id = :teacher_peer_id))
    )");
    $stmt->execute(['student_user_id' => $userId, 'teacher_user_id' => $peerId, 'student_peer_id' => $peerId, 'teacher_peer_id' => $userId]);
    return database_boolean($stmt->fetchColumn());
}

function sm_send_message(array $actor, array $input): array
{
    $peer = filter_var($input['receiver_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($peer === false || $peer === (int) $actor['id']) throw new ApiException(422, 'validation_failed', 'receiver_id غير صالح.');
    $body = sm_text($input['message_body'] ?? null, 'message_body', 10000);
    $limit = sm_env_positive('MESSAGING_RATE_LIMIT_COUNT');
    $window = sm_env_positive('MESSAGING_RATE_LIMIT_WINDOW_SECONDS');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT pg_advisory_xact_lock(CAST(:user_id AS BIGINT))');
        $lock->execute(['user_id' => (int) $actor['id']]);
        if (!sm_message_peer_allowed($db, (int) $actor['id'], (int) $peer)) throw new ApiException(403, 'message_peer_not_linked', 'الرسائل متاحة بين الطالب والأستاذ المرتبطين بمواد مسندة فقط.');
        $count = $db->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = :sender_id AND sent_at >= CURRENT_TIMESTAMP - make_interval(secs => CAST(:window AS INTEGER))');
        $count->execute(['sender_id' => (int) $actor['id'], 'window' => $window]);
        if ((int) $count->fetchColumn() >= $limit) throw new ApiException(429, 'message_rate_limited', 'تجاوزت معدل إرسال الرسائل المسموح.');
        $stmt = $db->prepare('INSERT INTO messages (sender_id, receiver_id, message_body) VALUES (:sender_id, :receiver_id, :message_body) RETURNING id, sender_id, receiver_id, message_body, sent_at, read_at');
        $stmt->execute(['sender_id' => (int) $actor['id'], 'receiver_id' => (int) $peer, 'message_body' => $body]);
        $message = $stmt->fetch();
        audit_log('message.sent', 'message', (int) $message['id'], ['receiver_id' => (int) $peer], (int) $actor['id']);
        $db->commit();
        return $message;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function sm_list_messages(array $actor, int $peerId, int $limit = 50, int $beforeId = PHP_INT_MAX): array
{
    if ($peerId === (int) $actor['id'] || !sm_message_peer_allowed(database_connection(), (int) $actor['id'], $peerId)) throw new ApiException(403, 'message_peer_not_linked', 'لا توجد علاقة تعليمية تسمح بهذه المحادثة.');
    $stmt = database_connection()->prepare('SELECT id, sender_id, receiver_id, message_body, sent_at, read_at FROM messages WHERE ((sender_id = :sender_id AND receiver_id = :receiver_id AND sender_deleted_at IS NULL) OR (sender_id = :reverse_sender_id AND receiver_id = :reverse_receiver_id AND receiver_deleted_at IS NULL)) AND deleted_at IS NULL AND id < :before_id ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue('sender_id', (int) $actor['id'], PDO::PARAM_INT);
    $stmt->bindValue('receiver_id', $peerId, PDO::PARAM_INT);
    $stmt->bindValue('reverse_sender_id', $peerId, PDO::PARAM_INT);
    $stmt->bindValue('reverse_receiver_id', (int) $actor['id'], PDO::PARAM_INT);
    $stmt->bindValue('before_id', $beforeId, PDO::PARAM_INT);
    $stmt->bindValue('limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll());
}

function sm_mark_message_read(array $actor, int $messageId): array
{
    $stmt = database_connection()->prepare('UPDATE messages SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = :id AND receiver_id = :user_id AND receiver_deleted_at IS NULL RETURNING id, read_at');
    $stmt->execute(['id' => $messageId, 'user_id' => (int) $actor['id']]);
    $row = $stmt->fetch();
    if (!$row) throw new ApiException(404, 'not_found', 'الرسالة غير موجودة.');
    return $row;
}

function sm_delete_message(array $actor, int $messageId): void
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE messages SET sender_deleted_at = CASE WHEN sender_id = :sender_user_id THEN CURRENT_TIMESTAMP ELSE sender_deleted_at END, receiver_deleted_at = CASE WHEN receiver_id = :receiver_user_id THEN CURRENT_TIMESTAMP ELSE receiver_deleted_at END WHERE id = :id AND (sender_id = :filter_sender_id OR receiver_id = :filter_receiver_id) AND deleted_at IS NULL RETURNING id');
        $userId = (int) $actor['id'];
        $stmt->execute(['id' => $messageId, 'sender_user_id' => $userId, 'receiver_user_id' => $userId, 'filter_sender_id' => $userId, 'filter_receiver_id' => $userId]);
        if (!$stmt->fetchColumn()) throw new ApiException(404, 'not_found', 'الرسالة غير موجودة.');
        audit_log('message.deleted', 'message', $messageId, [], $userId);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function sm_request_voice_participation(array $actor, int $sessionId): array
{
    $session = sm_session($actor, $sessionId);
    if (!in_array('student', $actor['roles'] ?? [], true)) {
        throw new ApiException(403, 'student_role_required', 'طلب التحدث مخصص للطلاب.');
    }
    if (!has_manual_content_access((int) $actor['id'])) {
        throw new ApiException(403, 'content_access_required', 'يلزم صلاحية محتوى سارية للمشاركة.');
    }
    $db = database_connection();
    $studentStmt = $db->prepare('SELECT id FROM students WHERE user_id = :user_id');
    $studentStmt->execute(['user_id' => (int) $actor['id']]);
    $studentId = $studentStmt->fetchColumn();
    if ($studentId === false) {
        throw new ApiException(403, 'student_profile_required', 'ملف الطالب غير موجود.');
    }
    $stmt = $db->prepare("
        INSERT INTO session_voice_requests (session_id, student_id, status)
        VALUES (:session_id, :student_id, 'PENDING')
        ON CONFLICT (session_id, student_id)
        DO UPDATE SET status = 'PENDING', updated_at = CURRENT_TIMESTAMP
        RETURNING id, session_id, student_id, status, created_at, updated_at
    ");
    $stmt->execute(['session_id' => $sessionId, 'student_id' => (int) $studentId]);
    audit_log('session.voice.requested', 'live_session', $sessionId, ['student_id' => (int) $studentId], (int) $actor['id']);
    return $stmt->fetch();
}

function sm_list_voice_requests(array $actor, int $sessionId): array
{
    $session = sm_session($actor, $sessionId);
    if (!in_array('admin', $actor['roles'] ?? [], true)) {
        $employeeId = sm_employee_for_class(database_connection(), $actor, (int) $session['class_subject_id']);
        if ($employeeId !== (int) $session['teacher_id']) {
            throw new ApiException(403, 'session_teacher_required', 'يمكن للأستاذ المسند أو المدير مراجعة طلبات التحدث.');
        }
    }
    $stmt = database_connection()->prepare("
        SELECT svr.id, svr.session_id, svr.student_id, svr.status, svr.created_at, svr.updated_at,
               u.full_name AS student_name, u.email AS student_email
        FROM session_voice_requests svr
        JOIN students st ON st.id = svr.student_id
        JOIN users u ON u.id = st.user_id
        WHERE svr.session_id = :session_id
        ORDER BY svr.updated_at DESC
    ");
    $stmt->execute(['session_id' => $sessionId]);
    return $stmt->fetchAll();
}

function sm_update_voice_request(array $actor, int $sessionId, int $requestId, array $input): array
{
    $session = sm_session($actor, $sessionId);
    if (!in_array('admin', $actor['roles'] ?? [], true)) {
        $employeeId = sm_employee_for_class(database_connection(), $actor, (int) $session['class_subject_id']);
        if ($employeeId !== (int) $session['teacher_id']) {
            throw new ApiException(403, 'session_teacher_required', 'يمكن للأستاذ المسند أو المدير تعديل حالة طلب التحدث.');
        }
    }
    $status = strtoupper(trim((string) ($input['status'] ?? '')));
    if (!in_array($status, ['APPROVED', 'REJECTED', 'REVOKED', 'PENDING'], true)) {
        throw new ApiException(422, 'validation_failed', 'حالة الطلب غير صالحة.');
    }
    $stmt = database_connection()->prepare("
        UPDATE session_voice_requests
        SET status = :status, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id AND session_id = :session_id
        RETURNING id, session_id, student_id, status, created_at, updated_at
    ");
    $stmt->execute(['status' => $status, 'id' => $requestId, 'session_id' => $sessionId]);
    $row = $stmt->fetch();
    if (!$row) throw new ApiException(404, 'not_found', 'طلب التحدث غير موجود.');
    audit_log('session.voice.moderated', 'live_session', $sessionId, ['request_id' => $requestId, 'status' => $status], (int) $actor['id']);
    return $row;
}

function sm_my_voice_status(array $actor, int $sessionId): array
{
    $session = sm_session($actor, $sessionId);
    $stmt = database_connection()->prepare("
        SELECT svr.id, svr.session_id, svr.student_id, svr.status, svr.created_at, svr.updated_at
        FROM session_voice_requests svr
        JOIN students st ON st.id = svr.student_id
        WHERE svr.session_id = :session_id AND st.user_id = :user_id
    ");
    $stmt->execute(['session_id' => $sessionId, 'user_id' => (int) $actor['id']]);
    $row = $stmt->fetch();
    return $row ?: ['session_id' => $sessionId, 'status' => 'NONE'];
}

