<?php
declare(strict_types=1);

function content_text(mixed $value, string $field, int $max, bool $required = false): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value)) throw new ApiException(422, 'validation_failed', "الحقل {$field} يجب أن يكون نصًا.");
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($length === false || ($required && $value === '') || $length > $max) {
        throw new ApiException(422, 'validation_failed', "الحقل {$field} مطلوب وبحد أقصى {$max} محرفًا.");
    }
    return $value;
}

function content_positive_id(mixed $value, string $field): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new ApiException(422, 'validation_failed', "الحقل {$field} يجب أن يكون رقمًا موجبًا.");
    return $id;
}

function content_form_bool(mixed $value, string $field, bool $default = true): bool
{
    if ($value === null || $value === '') return $default;
    if ($value === true || $value === 'true' || $value === '1' || $value === 1) return true;
    if ($value === false || $value === 'false' || $value === '0' || $value === 0) return false;
    throw new ApiException(422, 'validation_failed', "الحقل {$field} يجب أن يكون true أو false.");
}

function content_employee_id(PDO $db, int $userId): int
{
    $statement = $db->prepare('SELECT e.id FROM employees e JOIN users u ON u.id = e.user_id WHERE e.user_id = :user_id AND e.is_active = TRUE AND u.is_active = TRUE FOR SHARE OF e');
    $statement->execute(['user_id' => $userId]);
    $id = $statement->fetchColumn();
    if ($id === false) throw new ApiException(403, 'employee_profile_required', 'يلزم ملف موظف نشط لتنفيذ هذه العملية.');
    return (int) $id;
}

function content_assert_upload_scope(PDO $db, array $actor, int $employeeId, int $classSubjectId): void
{
    $statement = $db->prepare(
        'SELECT cs.id FROM class_subjects cs
         JOIN classes c ON c.id = cs.class_id AND c.is_active = TRUE
         JOIN stages st ON st.id = c.stage_id AND st.is_active = TRUE
         JOIN subjects s ON s.id = cs.subject_id AND s.is_active = TRUE
         WHERE cs.id = :id AND cs.is_active = TRUE FOR SHARE OF cs'
    );
    $statement->execute(['id' => $classSubjectId]);
    if ($statement->fetchColumn() === false) throw new ApiException(422, 'invalid_reference', 'ربط الصف والمادة غير موجود أو غير نشط.');

    if (in_array('teacher', $actor['roles'] ?? [], true)) {
        $assignment = $db->prepare('SELECT 1 FROM teaching_assignments WHERE employee_id = :employee_id AND class_subject_id = :class_subject_id');
        $assignment->execute(['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId]);
        if ($assignment->fetchColumn() === false) throw new ApiException(403, 'teaching_assignment_required', 'لا يمكنك رفع محتوى إلا لمادة مسندة إليك.');
        return;
    }
    if (!in_array('admin', $actor['roles'] ?? [], true)) {
        throw new ApiException(403, 'forbidden', 'رفع المحتوى متاح للأستاذ المسند أو مدير النظام.');
    }
}

function content_validate_lesson(PDO $db, ?int $lessonId, int $classSubjectId): void
{
    if ($lessonId === null) return;
    $statement = $db->prepare('SELECT 1 FROM lessons WHERE id = :lesson_id AND class_subject_id = :class_subject_id');
    $statement->execute(['lesson_id' => $lessonId, 'class_subject_id' => $classSubjectId]);
    if ($statement->fetchColumn() === false) throw new ApiException(422, 'invalid_reference', 'الدرس لا يتبع الصف والمادة المحددين.');
}

function content_public_record(array $record): array
{
    unset($record['storage_key'], $record['external_url']);
    return $record;
}

function content_has_video_asset(array $record): bool
{
    return !empty($record['video_asset_id']) || !empty($record['cloudflare_video_uid']);
}

function content_video_playback_url(array $record): string
{
    $providerCode = is_string($record['video_provider'] ?? null)
        ? $record['video_provider']
        : (!empty($record['cloudflare_video_uid']) ? 'cloudflare_stream' : '');
    $playbackId = is_string($record['video_playback_id'] ?? null)
        ? $record['video_playback_id']
        : (string) ($record['cloudflare_video_uid'] ?? '');
    if ($providerCode === '' || $playbackId === '') {
        throw new ApiException(404, 'video_not_found', 'رابط مشاهدة الفيديو غير صالح.');
    }
    $duration = filter_var($record['video_duration_seconds'] ?? null, FILTER_VALIDATE_FLOAT);
    return video_upload_provider($providerCode)->playbackUrl($playbackId, $duration === false ? 0 : (int) ceil((float) $duration));
}

function content_create_resource(array $actor, array $input, array $file): array
{
    $type = is_string($input['resource_type'] ?? null) ? strtoupper(trim($input['resource_type'])) : '';
    if (!in_array($type, ['PDF', 'VIDEO', 'AUDIO', 'DOCUMENT'], true)) throw new ApiException(422, 'validation_failed', 'resource_type غير مدعوم للرفع.');
    $classSubjectId = content_positive_id($input['class_subject_id'] ?? null, 'class_subject_id');
    $lessonId = ($input['lesson_id'] ?? '') === '' ? null : content_positive_id($input['lesson_id'], 'lesson_id');
    $data = [
        'class_subject_id' => $classSubjectId,
        'lesson_id' => $lessonId,
        'title' => content_text($input['title'] ?? null, 'title', 200, true),
        'resource_type' => $type,
        'description' => content_text($input['description'] ?? null, 'description', 5000),
        'source_reference' => content_text($input['source_reference'] ?? null, 'source_reference'),
        'source_owner' => content_text($input['source_owner'] ?? null, 'source_owner', 200),
        'copyright_license' => content_text($input['copyright_license'] ?? null, 'copyright_license', 200),
        'uploaded_by' => null,
    ];
    $db = database_connection();
    $stored = store_uploaded_file($file, $type);
    $data['storage_key'] = $stored['storage_key'];
    $data['original_filename'] = $stored['original_filename'];
    $data['mime_type'] = $stored['mime_type'];
    $data['file_size_bytes'] = $stored['size'];
    try {
        $db->beginTransaction();
        $employeeId = content_employee_id($db, (int) $actor['id']);
        content_assert_upload_scope($db, $actor, $employeeId, $classSubjectId);
        content_validate_lesson($db, $lessonId, $classSubjectId);
        $db->exec('DELETE FROM cloudflare_video_uploads WHERE expires_at < CURRENT_TIMESTAMP AND resource_id IS NULL');
        $data['uploaded_by'] = $employeeId;
        $statement = $db->prepare(
            'INSERT INTO learning_resources
             (class_subject_id, lesson_id, title, resource_type, description, storage_key, original_filename, mime_type, file_size_bytes,
              source_reference, source_owner, copyright_license, requires_subscription, status, uploaded_by)
             VALUES (:class_subject_id, :lesson_id, :title, :resource_type, :description, :storage_key, :original_filename, :mime_type, :file_size_bytes,
                     :source_reference, :source_owner, :copyright_license, TRUE, \'DRAFT\', :uploaded_by)
             RETURNING id, class_subject_id, lesson_id, title, resource_type, description, original_filename, mime_type, file_size_bytes,
                       source_reference, source_owner, copyright_license, requires_subscription, status, uploaded_by, created_at'
        );
        $statement->execute($data);
        $record = $statement->fetch();
        audit_log('content.uploaded', 'learning_resource', (int) $record['id'], ['resource_type' => $type, 'file_size_bytes' => $stored['size']], (int) $actor['id']);
        $db->commit();
        return $record;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        discard_private_file($stored['storage_key']);
        throw $error;
    }
}

function content_create_video_upload(array $actor, array $input): array
{
    $classSubjectId = content_positive_id($input['class_subject_id'] ?? null, 'class_subject_id');
    $lessonId = ($input['lesson_id'] ?? '') === '' ? null : content_positive_id($input['lesson_id'], 'lesson_id');
    $title = content_text($input['title'] ?? null, 'title', 200, true);
    $description = content_text($input['description'] ?? null, 'description', 5000);
    $sourceReference = content_text($input['source_reference'] ?? null, 'source_reference');
    $sourceOwner = content_text($input['source_owner'] ?? null, 'source_owner', 200);
    $license = content_text($input['copyright_license'] ?? null, 'copyright_license', 200);
    $db = database_connection();
    $provider = video_upload_provider();
    $db->beginTransaction();
    try {
        $employeeId = content_employee_id($db, (int) $actor['id']);
        content_assert_upload_scope($db, $actor, $employeeId, $classSubjectId);
        content_validate_lesson($db, $lessonId, $classSubjectId);
        $token = bin2hex(random_bytes(24));
        $upload = $provider->createDirectUpload($title, $token);
        $statement = $db->prepare(
            'DELETE FROM video_upload_sessions WHERE expires_at < CURRENT_TIMESTAMP AND resource_id IS NULL'
        );
        $statement->execute();
        $statement = $db->prepare(
            'INSERT INTO video_upload_sessions
             (upload_token, provider_code, provider_upload_id, user_id, employee_id, class_subject_id, lesson_id, title,
              description, source_reference, source_owner, copyright_license, expires_at)
             VALUES (:token, :provider_code, :provider_upload_id, :user_id, :employee_id, :class_subject_id, :lesson_id, :title,
                     :description, :source_reference, :source_owner, :license, CURRENT_TIMESTAMP + INTERVAL \'4 hours\')'
        );
        $statement->execute([
            'token' => $token, 'provider_code' => $provider->code(), 'provider_upload_id' => $upload['provider_upload_id'],
            'user_id' => (int) $actor['id'], 'employee_id' => $employeeId,
            'class_subject_id' => $classSubjectId, 'lesson_id' => $lessonId, 'title' => $title,
            'description' => $description, 'source_reference' => $sourceReference, 'source_owner' => $sourceOwner,
            'license' => $license,
        ]);
        $db->commit();
        return [
            'upload_token' => $token,
            'provider_code' => $provider->code(),
            'upload_url' => $upload['upload_url'],
            'upload_method' => $upload['upload_method'],
            'expires_in_seconds' => 14400,
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function content_complete_video_upload(array $actor, string $token): array
{
    if (!preg_match('/^[a-f0-9]{48}$/D', $token)) throw new ApiException(422, 'invalid_upload_token', 'رمز رفع الفيديو غير صالح.');
    $db = database_connection();
    $query = $db->prepare('SELECT * FROM video_upload_sessions WHERE upload_token = :token AND user_id = :user_id AND expires_at > CURRENT_TIMESTAMP');
    $query->execute(['token' => $token, 'user_id' => (int) $actor['id']]);
    $upload = $query->fetch();
    if (!$upload) throw new ApiException(404, 'video_upload_not_found', 'جلسة رفع الفيديو غير موجودة أو انتهت.');
    if ($upload['resource_id'] !== null) return ['id' => (int) $upload['resource_id'], 'status' => 'DRAFT'];

    $video = video_upload_provider((string) $upload['provider_code'])->completeDirectUpload((string) $upload['provider_upload_id']);

    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT * FROM video_upload_sessions WHERE upload_token = :token AND user_id = :user_id FOR UPDATE');
        $lock->execute(['token' => $token, 'user_id' => (int) $actor['id']]);
        $upload = $lock->fetch();
        if (!$upload) throw new ApiException(404, 'video_upload_not_found', 'جلسة رفع الفيديو غير موجودة.');
        if ($upload['resource_id'] !== null) {
            $db->commit();
            return ['id' => (int) $upload['resource_id'], 'status' => 'DRAFT'];
        }
        $employeeId = content_employee_id($db, (int) $actor['id']);
        content_assert_upload_scope($db, $actor, $employeeId, (int) $upload['class_subject_id']);
        $insert = $db->prepare(
            'INSERT INTO learning_resources
             (class_subject_id, lesson_id, title, resource_type, description, cloudflare_video_uid, video_provider,
              video_asset_id, video_playback_id, video_duration_seconds, original_filename, mime_type, file_size_bytes,
              source_reference, source_owner, copyright_license, requires_subscription, status, uploaded_by)
             VALUES (:class_subject_id, :lesson_id, :title, \'VIDEO\', :description, :cloudflare_uid, :provider_code,
                     :asset_id, :playback_id, :duration, :filename, :mime_type, :size,
                     :source_reference, :source_owner, :license, TRUE, \'DRAFT\', :employee_id)
             RETURNING id, class_subject_id, lesson_id, title, resource_type, description, original_filename, mime_type,
                       file_size_bytes, video_provider, video_asset_id, video_playback_id, video_duration_seconds,
                       source_reference, source_owner, copyright_license, requires_subscription, status, uploaded_by, created_at'
        );
        $insert->execute([
            'class_subject_id' => (int) $upload['class_subject_id'], 'lesson_id' => $upload['lesson_id'],
            'title' => $upload['title'], 'description' => $upload['description'],
            'cloudflare_uid' => $upload['provider_code'] === 'cloudflare_stream' ? $video['asset_id'] : null,
            'provider_code' => $upload['provider_code'], 'asset_id' => $video['asset_id'],
            'playback_id' => $video['playback_id'], 'duration' => $video['duration_seconds'],
            'filename' => $upload['title'] . '.mp4',
            'mime_type' => $upload['provider_code'] === 'cloudflare_stream' ? 'video/cloudflare-stream' : 'video/mux',
            'size' => $video['file_size_bytes'],
            'source_reference' => $upload['source_reference'], 'source_owner' => $upload['source_owner'],
            'license' => $upload['copyright_license'], 'employee_id' => $employeeId,
        ]);
        $record = $insert->fetch();
        $save = $db->prepare('UPDATE video_upload_sessions SET resource_id = :resource_id WHERE upload_token = :token');
        $save->execute(['resource_id' => $record['id'], 'token' => $token]);
        audit_log('content.uploaded', 'learning_resource', (int) $record['id'], ['resource_type' => 'VIDEO', 'provider' => $upload['provider_code']], (int) $actor['id']);
        $db->commit();
        return $record;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function content_create_past_exam(array $actor, array $input, array $file): array
{
    $classSubjectId = content_positive_id($input['class_subject_id'] ?? null, 'class_subject_id');
    $year = content_text($input['academic_year'] ?? null, 'academic_year', 20);
    $data = [
        'class_subject_id' => $classSubjectId,
        'title' => content_text($input['title'] ?? null, 'title', 150, true),
        'academic_year' => $year,
        'source_reference' => content_text($input['source_reference'] ?? null, 'source_reference'),
        'source_owner' => content_text($input['source_owner'] ?? null, 'source_owner', 200),
        'copyright_license' => content_text($input['copyright_license'] ?? null, 'copyright_license', 200),
    ];
    $db = database_connection();
    $stored = store_uploaded_file($file, 'EXAM');
    $data['storage_key'] = $stored['storage_key'];
    $data['original_filename'] = $stored['original_filename'];
    $data['mime_type'] = $stored['mime_type'];
    $data['file_size_bytes'] = $stored['size'];
    try {
        $db->beginTransaction();
        $employeeId = content_employee_id($db, (int) $actor['id']);
        content_assert_upload_scope($db, $actor, $employeeId, $classSubjectId);
        $statement = $db->prepare(
            'INSERT INTO past_exams
             (class_subject_id, title, academic_year, storage_key, original_filename, mime_type, file_size_bytes,
              source_reference, source_owner, copyright_license, requires_subscription, status, uploaded_by)
             VALUES (:class_subject_id, :title, :academic_year, :storage_key, :original_filename, :mime_type, :file_size_bytes,
                     :source_reference, :source_owner, :copyright_license, TRUE, \'DRAFT\', :uploaded_by)
             RETURNING id, class_subject_id, title, academic_year, original_filename, mime_type, file_size_bytes,
                       source_reference, source_owner, copyright_license, requires_subscription, status, uploaded_by, created_at'
        );
        $statement->execute($data + ['uploaded_by' => $employeeId]);
        $record = $statement->fetch();
        audit_log('content.uploaded', 'past_exam', (int) $record['id'], ['file_size_bytes' => $stored['size']], (int) $actor['id']);
        $db->commit();
        return $record;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        discard_private_file($stored['storage_key']);
        throw $error;
    }
}

function content_normalize_question_options(mixed $options): array
{
    if (!is_array($options)) throw new ApiException(422, 'validation_failed', 'options يجب أن تكون قائمة.');
    if (count($options) > 32768) throw new ApiException(422, 'validation_failed', 'عدد الخيارات يتجاوز الحد الذي يدعمه المخطط.');
    $normalized = [];
    foreach (array_values($options) as $index => $option) {
        if (is_object($option)) $option = get_object_vars($option);
        if (!is_array($option)) throw new ApiException(422, 'validation_failed', 'كل خيار يجب أن يكون كائنًا.');
        $text = content_text($option['option_text'] ?? null, 'option_text', PHP_INT_MAX, true);
        $correct = $option['is_correct'] ?? false;
        if (!is_bool($correct)) throw new ApiException(422, 'validation_failed', 'is_correct يجب أن يكون true أو false.');
        $normalized[] = ['option_text' => $text, 'is_correct' => $correct, 'sort_order' => $index];
    }
    return $normalized;
}

function content_replace_question_options(PDO $db, int $questionId, array $options): void
{
    $delete = $db->prepare('DELETE FROM question_options WHERE question_id = :id');
    $delete->execute(['id' => $questionId]);
    $insert = $db->prepare('INSERT INTO question_options (question_id, option_text, is_correct, sort_order) VALUES (:question_id, :option_text, :is_correct, :sort_order)');
    foreach ($options as $option) $insert->execute(['question_id' => $questionId] + $option);
}

function content_validate_question_option_set(string $questionType, array $options): void
{
    $optionCount = count($options);
    $correctCount = count(array_filter($options, static fn(array $option): bool => database_boolean($option['is_correct'] ?? false)));
    if ($questionType === 'ESSAY') {
        if ($optionCount !== 0) throw new ApiException(422, 'question_options_invalid', 'السؤال المقالي لا يقبل خيارات موضوعية.');
        return;
    }
    if ($correctCount !== 1) throw new ApiException(422, 'correct_option_required', 'يجب تحديد إجابة صحيحة واحدة للسؤال الموضوعي.');
    if ($questionType === 'MCQ' && $optionCount < 2) throw new ApiException(422, 'question_options_invalid', 'سؤال الاختيار من متعدد يحتاج خيارين على الأقل.');
    if ($questionType === 'TRUE_FALSE' && $optionCount !== 2) throw new ApiException(422, 'question_options_invalid', 'سؤال الصح والخطأ يحتاج خيارين فقط.');
}

function content_validate_question_options(PDO $db, array $question): void
{
    $statement = $db->prepare('SELECT is_correct FROM question_options WHERE question_id = :id ORDER BY sort_order, id');
    $statement->execute(['id' => $question['id']]);
    content_validate_question_option_set((string) $question['question_type'], $statement->fetchAll());
}

function content_create_question(array $actor, array $input): array
{
    $type = is_string($input['question_type'] ?? null) ? strtoupper(trim($input['question_type'])) : '';
    if (!in_array($type, ['MCQ', 'TRUE_FALSE', 'ESSAY'], true)) throw new ApiException(422, 'validation_failed', 'نوع السؤال غير مدعوم.');
    $difficulty = is_string($input['difficulty'] ?? null) ? strtoupper(trim($input['difficulty'])) : null;
    if ($difficulty !== null && !in_array($difficulty, ['EASY', 'MEDIUM', 'HARD'], true)) throw new ApiException(422, 'validation_failed', 'مستوى الصعوبة غير صالح.');
    $classSubjectId = content_positive_id($input['class_subject_id'] ?? null, 'class_subject_id');
    $lessonId = ($input['lesson_id'] ?? '') === '' ? null : content_positive_id($input['lesson_id'], 'lesson_id');
    $options = array_key_exists('options', $input) ? content_normalize_question_options($input['options']) : null;
    $data = [
        'class_subject_id' => $classSubjectId,
        'lesson_id' => $lessonId,
        'question_text' => content_text($input['question_text'] ?? null, 'question_text', PHP_INT_MAX, true),
        'question_type' => $type,
        'difficulty' => $difficulty,
        'explanation' => content_text($input['explanation'] ?? null, 'explanation', PHP_INT_MAX),
        'source_reference' => content_text($input['source_reference'] ?? null, 'source_reference'),
        'source_owner' => content_text($input['source_owner'] ?? null, 'source_owner', 200),
        'copyright_license' => content_text($input['copyright_license'] ?? null, 'copyright_license', 200),
    ];
    $db = database_connection();
    $db->beginTransaction();
    try {
        $employeeId = content_employee_id($db, (int) $actor['id']);
        content_assert_upload_scope($db, $actor, $employeeId, $classSubjectId);
        content_validate_lesson($db, $lessonId, $classSubjectId);
        $statement = $db->prepare(
            'INSERT INTO questions (class_subject_id, lesson_id, question_text, question_type, difficulty, explanation,
                                    source_reference, source_owner, copyright_license, status, created_by)
             VALUES (:class_subject_id, :lesson_id, :question_text, :question_type, COALESCE(:difficulty, \'MEDIUM\'), :explanation,
                     :source_reference, :source_owner, :copyright_license, \'DRAFT\', :created_by)
             RETURNING id, class_subject_id, lesson_id, question_text, question_type, difficulty, explanation,
                       source_reference, source_owner, copyright_license, status, created_by, created_at'
        );
        $statement->execute($data + ['created_by' => $employeeId]);
        $question = $statement->fetch();
        if ($options !== null) content_replace_question_options($db, (int) $question['id'], $options);
        audit_log('content.question.created', 'question', (int) $question['id'], ['question_type' => $type], (int) $actor['id']);
        $db->commit();
        return $question;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function content_fetch_for_owner(PDO $db, string $kind, int $id, bool $lock = false): ?array
{
    $table = match ($kind) {
        'resource' => 'learning_resources',
        'past_exam' => 'past_exams',
        'question' => 'questions',
        default => throw new LogicException('Unknown content kind.'),
    };
    $statement = $db->prepare('SELECT * FROM ' . $table . ' WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row ?: null;
}

function content_assert_owner_or_admin(array $row, array $actor): void
{
    $db = database_connection();
    $statement = $db->prepare('SELECT user_id FROM employees WHERE id = :employee_id');
    $statement->execute(['employee_id' => $row['uploaded_by'] ?? $row['created_by']]);
    $uploaderUserId = $statement->fetchColumn();
    if ((int) $uploaderUserId === (int) $actor['id']) return;
    if (in_array('admin', $actor['roles'] ?? [], true)) return;
    throw new ApiException(403, 'content_owner_required', 'يمكن لصاحب المحتوى أو مدير النظام فقط تعديله أو إرساله.');
}

function content_editable_fields(string $kind, array $input): array
{
    $allowed = match ($kind) {
        'resource' => ['title' => 200, 'description' => 5000, 'class_subject_id' => 'id', 'lesson_id' => 'nullable_id', 'source_reference' => null, 'source_owner' => 200, 'copyright_license' => 200],
        'past_exam' => ['title' => 150, 'academic_year' => 20, 'class_subject_id' => 'id', 'source_reference' => null, 'source_owner' => 200, 'copyright_license' => 200],
        'question' => ['question_text' => null, 'question_type' => 'question_type', 'difficulty' => 'difficulty', 'explanation' => null, 'class_subject_id' => 'id', 'lesson_id' => 'nullable_id', 'source_reference' => null, 'source_owner' => 200, 'copyright_license' => 200],
        default => throw new LogicException('Unknown content kind.'),
    };
    $data = [];
    foreach ($input as $field => $value) {
        if ($field === '_csrf') continue;
        if (!array_key_exists($field, $allowed)) throw new ApiException(422, 'validation_failed', "الحقل {$field} غير قابل للتعديل.");
        $rule = $allowed[$field];
        $data[$field] = match ($rule) {
            'id' => content_positive_id($value, $field),
            'nullable_id' => ($value === '' || $value === null) ? null : content_positive_id($value, $field),
            'question_type' => in_array(strtoupper((string) $value), ['MCQ', 'TRUE_FALSE', 'ESSAY'], true) ? strtoupper((string) $value) : throw new ApiException(422, 'validation_failed', 'نوع السؤال غير صالح.'),
            'difficulty' => in_array(strtoupper((string) $value), ['EASY', 'MEDIUM', 'HARD'], true) ? strtoupper((string) $value) : throw new ApiException(422, 'validation_failed', 'مستوى الصعوبة غير صالح.'),
            null => content_text($value, $field, PHP_INT_MAX),
            default => content_text($value, $field, $rule, in_array($field, ['title'], true)),
        };
    }
    return $data;
}

function content_update_asset(array $actor, string $kind, int $id, array $input, ?array $file = null): array
{
    $db = database_connection();
    $existing = content_fetch_for_owner($db, $kind, $id);
    if ($existing === null) throw new ApiException(404, 'not_found', 'المحتوى غير موجود.');
    content_assert_owner_or_admin($existing, $actor);
    if (!in_array($existing['status'], ['DRAFT', 'REJECTED'], true)) throw new ApiException(409, 'content_state_conflict', 'لا يمكن تعديل محتوى قيد المراجعة أو منشور.');
    $options = $kind === 'question' && array_key_exists('options', $input) ? content_normalize_question_options($input['options']) : null;
    if ($kind === 'question') unset($input['options']);
    $data = content_editable_fields($kind, $input);
    if ($data === [] && $file === null && $options === null) throw new ApiException(422, 'validation_failed', 'أرسل بيانات للتعديل أو ملفًا بديلًا.');
    $newFile = null;
    if ($file !== null) {
        if ($kind === 'question') throw new ApiException(422, 'file_not_supported', 'الأسئلة لا تقبل ملفًا في هذا المسار.');
        $category = $kind === 'resource' ? $existing['resource_type'] : 'EXAM';
        $newFile = store_uploaded_file($file, $category);
        $data['storage_key'] = $newFile['storage_key'];
        $data['original_filename'] = $newFile['original_filename'];
        $data['mime_type'] = $newFile['mime_type'];
        $data['file_size_bytes'] = $newFile['size'];
    }
    $oldKey = (string) ($existing['storage_key'] ?? '');
    $table = match ($kind) { 'resource' => 'learning_resources', 'past_exam' => 'past_exams', 'question' => 'questions' };
    try {
        $db->beginTransaction();
        $locked = content_fetch_for_owner($db, $kind, $id, true);
        if ($locked === null) throw new ApiException(404, 'not_found', 'المحتوى غير موجود.');
        content_assert_owner_or_admin($locked, $actor);
        if (!in_array($locked['status'], ['DRAFT', 'REJECTED'], true)) throw new ApiException(409, 'content_state_conflict', 'تغيرت حالة المحتوى ولا يمكن تعديله.');
        if (isset($data['class_subject_id'])) {
            $employeeId = content_employee_id($db, (int) $actor['id']);
            content_assert_upload_scope($db, $actor, $employeeId, (int) $data['class_subject_id']);
        }
        if ($kind === 'resource' && array_key_exists('lesson_id', $data)) {
            $mappingId = (int) ($data['class_subject_id'] ?? $locked['class_subject_id']);
            content_validate_lesson($db, $data['lesson_id'], $mappingId);
        }
        if ($kind === 'question' && array_key_exists('lesson_id', $data)) {
            $mappingId = (int) ($data['class_subject_id'] ?? $locked['class_subject_id']);
            content_validate_lesson($db, $data['lesson_id'], $mappingId);
        }
        if ($data === [] && $options !== null) {
            content_replace_question_options($db, $id, $options);
            if ($locked['status'] === 'REJECTED') {
                $reset = $db->prepare("UPDATE questions SET status = 'DRAFT', published_at = NULL WHERE id = :id");
                $reset->execute(['id' => $id]);
                $locked['status'] = 'DRAFT';
                $locked['published_at'] = null;
            }
            audit_log('content.question.edited', 'question', $id, ['fields' => ['options']], (int) $actor['id']);
            $db->commit();
            return content_public_record($locked);
        }
        $data['id'] = $id;
        $set = array_map(static fn(string $field): string => $field . ' = :' . $field, array_filter(array_keys($data), static fn(string $field): bool => $field !== 'id'));
        if ($locked['status'] === 'REJECTED') {
            $set[] = "status = 'DRAFT'";
            $set[] = 'published_at = NULL';
        }
        $statement = $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = :id RETURNING *');
        $statement->execute($data);
        $updated = $statement->fetch();
        if ($kind === 'question' && $options !== null) content_replace_question_options($db, $id, $options);
        $targetType = match ($kind) { 'resource' => 'learning_resource', 'past_exam' => 'past_exam', 'question' => 'question' };
        audit_log('content.edited', $targetType, $id, ['fields' => array_values(array_diff(array_keys($data), ['id'])), 'options_changed' => $options !== null, 'file_replaced' => $newFile !== null], (int) $actor['id']);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($newFile !== null) discard_private_file($newFile['storage_key']);
        throw $error;
    }
    if ($newFile !== null && preg_match('/^[a-f0-9]{64}$/D', $oldKey)) {
        try { discard_private_file($oldKey); } catch (Throwable $error) { app_log_error('old_private_file_cleanup_failed', $error); }
    }
    return content_public_record($updated);
}

function content_submit_for_review(array $actor, string $kind, int $id): array
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $row = content_fetch_for_owner($db, $kind, $id, true);
        if ($row === null) throw new ApiException(404, 'not_found', 'المحتوى غير موجود.');
        content_assert_owner_or_admin($row, $actor);
        if ($row['status'] !== 'DRAFT') throw new ApiException(409, 'content_state_conflict', 'يجب أن يكون المحتوى مسودة قبل إرساله للمراجعة.');
        foreach (['source_reference', 'source_owner', 'copyright_license'] as $field) {
            if (!is_string($row[$field] ?? null) || trim($row[$field]) === '') throw new ApiException(422, 'provenance_required', 'أدخل المصدر ومالك المحتوى والترخيص قبل الإرسال للمراجعة.');
        }
        if ($kind === 'question') content_validate_question_options($db, $row);
        if ($kind !== 'question' && !content_has_video_asset($row) && resolve_private_file((string) ($row['storage_key'] ?? '')) === null) throw new ApiException(422, 'file_unavailable', 'ملف المحتوى غير متاح داخل مساحة التخزين الخاصة.');
        $employeeId = content_employee_id($db, (int) $actor['id']);
        content_assert_upload_scope($db, $actor, $employeeId, (int) $row['class_subject_id']);
        $table = match ($kind) { 'resource' => 'learning_resources', 'past_exam' => 'past_exams', 'question' => 'questions' };
        $statement = $db->prepare("UPDATE {$table} SET status = 'PENDING_REVIEW', published_at = NULL WHERE id = :id RETURNING id, status");
        $statement->execute(['id' => $id]);
        $updated = $statement->fetch();
        audit_log('content.submitted', match ($kind) { 'resource' => 'learning_resource', 'past_exam' => 'past_exam', 'question' => 'question' }, $id, [], (int) $actor['id']);
        $db->commit();
        return $updated;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function content_review(array $actor, string $kind, int $id, string $outcome, ?string $feedback): array
{
    if (!in_array($outcome, ['APPROVED', 'REJECTED'], true)) throw new ApiException(422, 'validation_failed', 'النتيجة يجب أن تكون APPROVED أو REJECTED.');
    if ($outcome === 'APPROVED' && !has_permission('content.publish')) throw new ApiException(403, 'forbidden', 'لا تملك صلاحية نشر المحتوى المعتمد.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $row = content_fetch_for_owner($db, $kind, $id, true);
        if ($row === null) throw new ApiException(404, 'not_found', 'المحتوى غير موجود.');
        if ($row['status'] !== 'PENDING_REVIEW') throw new ApiException(409, 'content_state_conflict', 'لا يمكن مراجعة محتوى غير معلق للمراجعة.');
        if ($kind !== 'question' && !content_has_video_asset($row) && resolve_private_file((string) ($row['storage_key'] ?? '')) === null) throw new ApiException(409, 'file_unavailable', 'ملف المحتوى غير متاح للمراجعة.');
        $reviewerId = content_employee_id($db, (int) $actor['id']);
        if ($reviewerId === (int) ($row['uploaded_by'] ?? $row['created_by'])) throw new ApiException(403, 'self_review_forbidden', 'لا يجوز للمراجع مراجعة المحتوى الذي رفعه.');
        $targetColumn = match ($kind) { 'resource' => 'resource_id', 'past_exam' => 'past_exam_id', 'question' => 'question_id' };
        $targetType = match ($kind) { 'resource' => 'learning_resource', 'past_exam' => 'past_exam', 'question' => 'question' };
        $review = $db->prepare("INSERT INTO content_reviews ({$targetColumn}, reviewer_id, status, feedback) VALUES (:target_id, :reviewer_id, :status, :feedback) RETURNING id, status, feedback, reviewed_at");
        $review->execute(['target_id' => $id, 'reviewer_id' => $reviewerId, 'status' => $outcome, 'feedback' => $feedback]);
        $reviewRow = $review->fetch();
        $table = match ($kind) { 'resource' => 'learning_resources', 'past_exam' => 'past_exams', 'question' => 'questions' };
        $update = $db->prepare("UPDATE {$table} SET status = :status, published_at = CASE WHEN :published_status = 'APPROVED' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = :id RETURNING id, status, published_at");
        $update->execute(['status' => $outcome, 'published_status' => $outcome, 'id' => $id]);
        $item = $update->fetch();
        audit_log('content.review.' . strtolower($outcome), $targetType, $id, ['review_id' => (int) $reviewRow['id']], (int) $actor['id']);
        if ($outcome === 'APPROVED') audit_log('content.published', $targetType, $id, ['review_id' => (int) $reviewRow['id']], (int) $actor['id']);
        $db->commit();
        return ['review' => $reviewRow, 'item' => $item];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function content_search_past_exams(array $filters): array
{
    if (isset($filters['lesson_id'])) return [];
    $where = ["pe.status = 'APPROVED'", 'pe.published_at IS NOT NULL', 'st.is_active = TRUE', 'c.is_active = TRUE', 'cs.is_active = TRUE', 's.is_active = TRUE', "EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.past_exam_id = pe.id AND cr.status = 'APPROVED')"];
    $params = [];
    foreach (['stage_id' => 'st.id', 'class_id' => 'c.id', 'class_subject_id' => 'cs.id', 'subject_id' => 's.id'] as $key => $column) {
        if (isset($filters[$key])) { $where[] = $column . ' = :' . $key; $params[$key] = $filters[$key]; }
    }
    if (isset($filters['q']) && $filters['q'] !== '') {
        $where[] = '(pe.title ILIKE :q1 OR COALESCE(pe.academic_year, \'\') ILIKE :q2 OR s.name ILIKE :q3)';
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
        foreach (['q1', 'q2', 'q3'] as $placeholder) $params[$placeholder] = $pattern;
    }
    $sql = 'SELECT pe.id, pe.title, pe.academic_year, pe.class_subject_id, pe.original_filename, pe.mime_type, pe.file_size_bytes,
                   pe.requires_subscription, pe.published_at, st.id AS stage_id, st.name AS stage_name,
                   c.id AS class_id, c.name AS class_name, s.id AS subject_id, s.name AS subject_name
            FROM past_exams pe JOIN class_subjects cs ON cs.id = pe.class_subject_id
            JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id
            JOIN subjects s ON s.id = cs.subject_id WHERE ' . implode(' AND ', $where) .
            ' ORDER BY pe.academic_year DESC NULLS LAST, pe.published_at DESC, pe.id DESC LIMIT :limit OFFSET :offset';
    $statement = database_connection()->prepare($sql);
    foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value);
    $statement->bindValue(':limit', max(1, min(100, $filters['limit'] ?? 25)), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $filters['offset'] ?? 0), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function content_download_record(string $kind, int $id, ?int $variantId = null): ?array
{
    if ($kind === 'resource') {
        if ($variantId === null) {
            $sql = "SELECT lr.id, lr.storage_key, lr.cloudflare_video_uid, lr.video_provider, lr.video_asset_id, lr.video_playback_id,
                           lr.video_duration_seconds, lr.original_filename, lr.mime_type, lr.file_size_bytes, lr.requires_subscription
                    FROM learning_resources lr WHERE lr.id = :id AND lr.status = 'APPROVED' AND lr.published_at IS NOT NULL
                    AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = lr.id AND cr.status = 'APPROVED')";
            $params = ['id' => $id];
        } else {
            $sql = "SELECT lr.id, rv.storage_key, rv.label AS original_filename, rv.mime_type, rv.file_size_bytes, lr.requires_subscription
                    FROM learning_resources lr JOIN resource_variants rv ON rv.resource_id = lr.id
                    WHERE lr.id = :id AND rv.id = :variant_id AND rv.is_downloadable = TRUE
                    AND lr.status = 'APPROVED' AND lr.published_at IS NOT NULL
                    AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = lr.id AND cr.status = 'APPROVED')";
            $params = ['id' => $id, 'variant_id' => $variantId];
        }
    } elseif ($kind === 'past_exam' && $variantId === null) {
        $sql = "SELECT pe.id, pe.storage_key, pe.original_filename, pe.mime_type, pe.file_size_bytes, pe.requires_subscription
                FROM past_exams pe WHERE pe.id = :id AND pe.status = 'APPROVED' AND pe.published_at IS NOT NULL
                AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.past_exam_id = pe.id AND cr.status = 'APPROVED')";
        $params = ['id' => $id];
    } else {
        return null;
    }
    $statement = database_connection()->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();
    return $row ?: null;
}

function content_has_active_subscription(int $userId): bool
{
    return has_active_subscription($userId);
}

function content_audit_download(array $actor, string $kind, int $id, ?int $variantId, bool $partial): void
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        audit_log('content.download.started', $kind === 'resource' ? 'learning_resource' : 'past_exam', $id, ['variant_id' => $variantId, 'partial' => $partial], (int) $actor['id']);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
