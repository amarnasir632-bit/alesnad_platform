<?php
declare(strict_types=1);

function quiz_config_value(string $name, array $allowed = []): string
{
    $value = env_value($name);
    if ($value === null || trim($value) === '') throw new ApiException(503, 'quiz_not_configured', "إعداد الاختبارات {$name} غير محدد.");
    $value = strtoupper(trim($value));
    if ($allowed !== [] && !in_array($value, $allowed, true)) throw new ApiException(503, 'quiz_not_configured', "إعداد الاختبارات {$name} غير صالح.");
    return $value;
}

function quiz_positive_setting(string $name): int
{
    $value = quiz_config_value($name);
    if (!ctype_digit($value) || (int) $value < 1 || (string) (int) $value !== $value) throw new ApiException(503, 'quiz_not_configured', "إعداد الاختبارات {$name} يجب أن يكون عددًا صحيحًا موجبًا.");
    return (int) $value;
}

function quiz_incomplete_policy(): string
{
    return quiz_config_value('QUIZ_INCOMPLETE_ATTEMPT_POLICY', ['AUTO_SUBMIT', 'CANCEL_COUNTED', 'CANCEL_FREE']);
}

function quiz_result_visibility(): string
{
    return quiz_config_value('QUIZ_RESULT_VISIBILITY', ['IMMEDIATE', 'AFTER_WINDOW', 'AFTER_MANUAL_GRADING']);
}

function quiz_decimal(mixed $value, string $field, bool $required = true): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value) && !is_int($value) && !is_float($value)) throw new ApiException(422, 'validation_failed', "الحقل {$field} يجب أن يكون رقمًا.");
    $raw = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/D', $raw)) throw new ApiException(422, 'validation_failed', "الحقل {$field} رقم موجب حتى منزلتين عشريتين.");
    $number = (float) $raw;
    if ($required && $number <= 0) throw new ApiException(422, 'validation_failed', "الحقل {$field} يجب أن يكون أكبر من صفر.");
    return number_format($number, 2, '.', '');
}

function quiz_decimal_cents(string $value): int
{
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
}

function quiz_format_cents(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function quiz_timestamp(mixed $value, string $field): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) throw new ApiException(422, 'validation_failed', "الحقل {$field} يجب أن يكون تاريخًا نصيًا.");
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        throw new ApiException(422, 'validation_failed', "التاريخ في {$field} غير صالح.");
    }
    return $date->format(DateTimeInterface::ATOM);
}

function quiz_employee_id(PDO $db, array $actor, int $classSubjectId): int
{
    $context = $db->prepare('SELECT cs.id FROM class_subjects cs JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id JOIN subjects s ON s.id = cs.subject_id WHERE cs.id = :id AND cs.is_active = TRUE AND c.is_active = TRUE AND st.is_active = TRUE AND s.is_active = TRUE');
    $context->execute(['id' => $classSubjectId]);
    if ($context->fetchColumn() === false) throw new ApiException(422, 'invalid_reference', 'مادة الاختبار غير موجودة أو غير نشطة.');
    $employee = content_employee_id($db, (int) $actor['id']);
    if (in_array('admin', $actor['roles'] ?? [], true)) return $employee;
    if (!in_array('teacher', $actor['roles'] ?? [], true)) throw new ApiException(403, 'forbidden', 'إدارة الاختبارات متاحة للأستاذ ومدير النظام.');
    $assignment = $db->prepare('SELECT 1 FROM teaching_assignments WHERE employee_id = :employee_id AND class_subject_id = :class_subject_id');
    $assignment->execute(['employee_id' => $employee, 'class_subject_id' => $classSubjectId]);
    if ($assignment->fetchColumn() === false) throw new ApiException(403, 'teaching_assignment_required', 'يمكنك إدارة اختبارات المواد المسندة إليك فقط.');
    return $employee;
}

function quiz_student_id(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT st.id, st.class_id FROM students st JOIN users u ON u.id = st.user_id WHERE st.user_id = :user_id AND u.is_active = TRUE');
    $statement->execute(['user_id' => $userId]);
    $student = $statement->fetch();
    if (!$student) throw new ApiException(403, 'student_profile_required', 'يلزم ملف طالب نشط لأداء الاختبار.');
    return ['id' => (int) $student['id'], 'class_id' => $student['class_id'] === null ? null : (int) $student['class_id']];
}

function quiz_normalize_items(PDO $db, int $classSubjectId, mixed $items): array
{
    if (!is_array($items) || $items === []) throw new ApiException(422, 'quiz_questions_required', 'أضف سؤالًا واحدًا على الأقل للاختبار.');
    if (count($items) > 32768) throw new ApiException(422, 'validation_failed', 'عدد الأسئلة يتجاوز الحد الذي يدعمه ترتيب قاعدة البيانات.');
    $normalized = [];
    $seen = [];
    $find = $db->prepare('SELECT id, class_subject_id, status, question_type FROM questions WHERE id = :id FOR SHARE');
    foreach (array_values($items) as $index => $item) {
        if (is_object($item)) $item = get_object_vars($item);
        if (!is_array($item)) throw new ApiException(422, 'validation_failed', 'كل عنصر سؤال يجب أن يكون كائنًا.');
        $questionId = content_positive_id($item['question_id'] ?? null, 'question_id');
        if (isset($seen[$questionId])) throw new ApiException(422, 'duplicate_question', 'لا يمكن تكرار السؤال داخل الاختبار.');
        $mark = quiz_decimal($item['question_mark'] ?? null, 'question_mark');
        $find->execute(['id' => $questionId]);
        $question = $find->fetch();
        if (!$question || (int) $question['class_subject_id'] !== $classSubjectId) throw new ApiException(422, 'invalid_reference', 'السؤال لا يتبع مادة الاختبار.');
        if ($question['status'] !== 'APPROVED') throw new ApiException(422, 'question_not_approved', 'لا يمكن إضافة سؤال غير معتمد إلى اختبار.');
        content_validate_question_options($db, $question);
        $normalized[] = ['question_id' => $questionId, 'question_mark' => $mark, 'sort_order' => $index];
        $seen[$questionId] = true;
    }
    return $normalized;
}

function quiz_insert_items(PDO $db, int $quizId, array $items): string
{
    $insert = $db->prepare('INSERT INTO quiz_questions (quiz_id, question_id, question_mark, sort_order) VALUES (:quiz_id, :question_id, :question_mark, :sort_order)');
    $total = 0.0;
    foreach ($items as $item) {
        $insert->execute(['quiz_id' => $quizId] + $item);
        $total += (float) $item['question_mark'];
    }
    return number_format($total, 2, '.', '');
}

function quiz_create(array $actor, array $input): array
{
    $classSubjectId = content_positive_id($input['class_subject_id'] ?? null, 'class_subject_id');
    $title = content_text($input['title'] ?? null, 'title', 150, true);
    $description = content_text($input['description'] ?? null, 'description', 5000);
    $duration = array_key_exists('duration_minutes', $input) && $input['duration_minutes'] !== null
        ? content_positive_id($input['duration_minutes'], 'duration_minutes')
        : quiz_positive_setting('QUIZ_DURATION_MINUTES');
    if ($duration > 32767) throw new ApiException(422, 'validation_failed', 'مدة الاختبار تتجاوز الحد الذي يدعمه المخطط.');
    $startsAt = quiz_timestamp($input['starts_at'] ?? null, 'starts_at');
    $endsAt = quiz_timestamp($input['ends_at'] ?? null, 'ends_at');
    if ($startsAt !== null && $endsAt !== null && new DateTimeImmutable($endsAt) <= new DateTimeImmutable($startsAt)) throw new ApiException(422, 'validation_failed', 'نهاية الاختبار يجب أن تكون بعد بدايته.');
    if (quiz_result_visibility() === 'AFTER_WINDOW' && $endsAt === null) throw new ApiException(422, 'result_window_required', 'سياسة إظهار النتائج بعد إغلاق الاختبار تتطلب ends_at.');
    $passing = quiz_decimal($input['passing_marks'] ?? null, 'passing_marks', false);
    $requiresSubscription = content_form_bool($input['requires_subscription'] ?? null, 'requires_subscription', true);
    $db = database_connection();
    $db->beginTransaction();
    try {
        $employeeId = quiz_employee_id($db, $actor, $classSubjectId);
        $items = quiz_normalize_items($db, $classSubjectId, $input['questions'] ?? null);
        $totalCents = array_sum(array_map(static fn(array $item): int => quiz_decimal_cents($item['question_mark']), $items));
        if ($totalCents > 99999999) throw new ApiException(422, 'validation_failed', 'مجموع الدرجات يتجاوز الحد الذي يدعمه المخطط.');
        $total = quiz_format_cents($totalCents);
        if ($passing !== null && quiz_decimal_cents($passing) > $totalCents) throw new ApiException(422, 'validation_failed', 'درجة النجاح لا يمكن أن تتجاوز مجموع درجات الأسئلة.');
        $statement = $db->prepare(
            'INSERT INTO quizzes (class_subject_id, title, description, duration_minutes, total_marks, passing_marks,
                                  starts_at, ends_at, is_published, requires_subscription, created_by)
             VALUES (:class_subject_id, :title, :description, :duration_minutes, :total_marks, :passing_marks,
                     :starts_at, :ends_at, FALSE, :requires_subscription, :created_by)
             RETURNING id, class_subject_id, title, description, duration_minutes, total_marks, passing_marks,
                       starts_at, ends_at, is_published, requires_subscription, created_at'
        );
        $statement->execute([
            'class_subject_id' => $classSubjectId, 'title' => $title, 'description' => $description,
            'duration_minutes' => $duration, 'total_marks' => $total, 'passing_marks' => $passing,
            'starts_at' => $startsAt, 'ends_at' => $endsAt, 'requires_subscription' => $requiresSubscription,
            'created_by' => $employeeId,
        ]);
        $quiz = $statement->fetch();
        quiz_insert_items($db, (int) $quiz['id'], $items);
        audit_log('quiz.created', 'quiz', (int) $quiz['id'], ['question_count' => count($items)], (int) $actor['id']);
        $db->commit();
        return $quiz;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function quiz_fetch(PDO $db, int $quizId, bool $lock = false): ?array
{
    $statement = $db->prepare('SELECT qz.*, cs.class_id, cs.is_active AS class_subject_active, c.is_active AS class_active,
                                      st.is_active AS stage_active, s.is_active AS subject_active
                               FROM quizzes qz JOIN class_subjects cs ON cs.id = qz.class_subject_id
                               JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id
                               JOIN subjects s ON s.id = cs.subject_id WHERE qz.id = :id' . ($lock ? ' FOR UPDATE OF qz' : ''));
    $statement->execute(['id' => $quizId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function quiz_assert_teacher_scope(PDO $db, array $actor, array $quiz): int
{
    return quiz_employee_id($db, $actor, (int) $quiz['class_subject_id']);
}

function quiz_update(array $actor, int $quizId, array $input): array
{
    $allowed = ['title', 'description', 'duration_minutes', 'passing_marks', 'starts_at', 'ends_at', 'is_published', 'requires_subscription'];
    foreach ($input as $field => $_) if ($field !== '_csrf' && !in_array($field, $allowed, true)) throw new ApiException(422, 'validation_failed', "الحقل {$field} غير قابل للتعديل.");
    $db = database_connection();
    $db->beginTransaction();
    try {
        $quiz = quiz_fetch($db, $quizId, true);
        if (!$quiz) throw new ApiException(404, 'not_found', 'الاختبار غير موجود.');
        quiz_assert_teacher_scope($db, $actor, $quiz);
        $attempts = $db->prepare('SELECT EXISTS(SELECT 1 FROM quiz_attempts WHERE quiz_id = :id)');
        $attempts->execute(['id' => $quizId]);
        $hasAttempts = database_boolean($attempts->fetchColumn());
        if ($hasAttempts && array_values(array_diff(array_keys($input), ['_csrf'])) !== ['is_published']) throw new ApiException(409, 'quiz_has_attempts', 'لا يمكن تعديل بيانات اختبار بدأه طالب.');
        $data = [];
        if (array_diff(array_keys($input), ['_csrf']) === []) throw new ApiException(422, 'validation_failed', 'أرسل حقلًا واحدًا على الأقل للتعديل.');
        if (array_key_exists('title', $input)) $data['title'] = content_text($input['title'], 'title', 150, true);
        if (array_key_exists('description', $input)) $data['description'] = content_text($input['description'], 'description', 5000);
        if (array_key_exists('duration_minutes', $input)) {
            $duration = content_positive_id($input['duration_minutes'], 'duration_minutes');
            if ($duration > 32767) throw new ApiException(422, 'validation_failed', 'مدة الاختبار تتجاوز الحد الذي يدعمه المخطط.');
            $data['duration_minutes'] = $duration;
        }
        if (array_key_exists('passing_marks', $input)) $data['passing_marks'] = quiz_decimal($input['passing_marks'], 'passing_marks', false);
        foreach (['starts_at', 'ends_at'] as $field) if (array_key_exists($field, $input)) $data[$field] = quiz_timestamp($input[$field], $field);
        if (array_key_exists('requires_subscription', $input)) $data['requires_subscription'] = content_form_bool($input['requires_subscription'], 'requires_subscription');
        $newStart = $data['starts_at'] ?? $quiz['starts_at'];
        $newEnd = $data['ends_at'] ?? $quiz['ends_at'];
        if ($newStart !== null && $newEnd !== null && new DateTimeImmutable($newEnd) <= new DateTimeImmutable($newStart)) throw new ApiException(422, 'validation_failed', 'نهاية الاختبار يجب أن تكون بعد بدايته.');
        if (array_key_exists('is_published', $input)) {
            if (!is_bool($input['is_published'])) throw new ApiException(422, 'validation_failed', 'is_published يجب أن تكون قيمة منطقية.');
            if ($input['is_published']) {
                if (quiz_result_visibility() === 'AFTER_WINDOW' && $newEnd === null) throw new ApiException(422, 'result_window_required', 'سياسة إظهار النتائج بعد إغلاق الاختبار تتطلب ends_at.');
                if ($newEnd !== null && new DateTimeImmutable($newEnd) <= new DateTimeImmutable('now')) throw new ApiException(422, 'quiz_window_closed', 'لا يمكن نشر اختبار انتهت نافذته.');
                $count = $db->prepare("SELECT COUNT(*) FROM quiz_questions qq JOIN questions qu ON qu.id = qq.question_id WHERE qq.quiz_id = :id AND qu.status = 'APPROVED'");
                $count->execute(['id' => $quizId]);
                if ((int) $count->fetchColumn() < 1) throw new ApiException(422, 'quiz_questions_required', 'لا يمكن نشر اختبار بلا أسئلة معتمدة.');
            }
            $data['is_published'] = $input['is_published'];
        }
        if ($data !== []) {
            $assignments = [];
            foreach ($data as $field => $_) $assignments[] = $field . ' = :' . $field;
            $statement = $db->prepare('UPDATE quizzes SET ' . implode(', ', $assignments) . ' WHERE id = :id RETURNING id, class_subject_id, title, description, duration_minutes, total_marks, passing_marks, starts_at, ends_at, is_published, requires_subscription');
            $statement->execute($data + ['id' => $quizId]);
            $updated = $statement->fetch();
        } else {
            $updated = $quiz;
        }
        audit_log('quiz.updated', 'quiz', $quizId, ['fields' => array_keys($data)], (int) $actor['id']);
        $db->commit();
        return $updated;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function quiz_list_questions(array $actor, array $filters): array
{
    $db = database_connection();
    $where = ["q.status = 'APPROVED'"];
    $params = [];
    $columns = ['stage_id' => 'st.id', 'class_id' => 'c.id', 'subject_id' => 's.id', 'class_subject_id' => 'cs.id', 'lesson_id' => 'q.lesson_id'];
    foreach ($columns as $field => $column) {
        if (isset($filters[$field])) { $where[] = $column . ' = :' . $field; $params[$field] = $filters[$field]; }
    }
    foreach (['question_type' => ['MCQ', 'TRUE_FALSE', 'ESSAY'], 'difficulty' => ['EASY', 'MEDIUM', 'HARD']] as $field => $allowed) {
        if (isset($filters[$field])) {
            $value = strtoupper((string) $filters[$field]);
            if (!in_array($value, $allowed, true)) throw new ApiException(422, 'validation_failed', "مرشح {$field} غير صالح.");
            $where[] = "q.{$field} = :{$field}";
            $params[$field] = $value;
        }
    }
    if (!in_array('admin', $actor['roles'] ?? [], true)) {
        $employeeId = content_employee_id($db, (int) $actor['id']);
        $where[] = 'EXISTS (SELECT 1 FROM teaching_assignments ta WHERE ta.employee_id = :employee_id AND ta.class_subject_id = cs.id)';
        $params['employee_id'] = $employeeId;
    }
    $statement = $db->prepare('SELECT q.id, q.class_subject_id, q.lesson_id, q.question_text, q.question_type, q.difficulty,
                                      st.id AS stage_id, st.name AS stage_name, c.id AS class_id, c.name AS class_name,
                                      s.id AS subject_id, s.name AS subject_name
                               FROM questions q JOIN class_subjects cs ON cs.id = q.class_subject_id
                               JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id
                               JOIN subjects s ON s.id = cs.subject_id WHERE cs.is_active = TRUE AND c.is_active = TRUE AND st.is_active = TRUE AND s.is_active = TRUE AND ' . implode(' AND ', $where) .
                               ' ORDER BY st.sort_order, c.sort_order, s.name, q.lesson_id NULLS FIRST, q.difficulty, q.id LIMIT 100');
    $statement->execute($params);
    $questions = $statement->fetchAll();
    $options = $db->prepare('SELECT id, option_text, is_correct FROM question_options WHERE question_id = :id ORDER BY sort_order, id');
    foreach ($questions as &$question) {
        $options->execute(['id' => $question['id']]);
        $question['options'] = $options->fetchAll();
    }
    unset($question);
    return $questions;
}

function quiz_student_can_access(PDO $db, array $student, array $quiz, int $userId): void
{
    if (!database_boolean($quiz['class_subject_active']) || !database_boolean($quiz['class_active']) || !database_boolean($quiz['stage_active']) || !database_boolean($quiz['subject_active'])) throw new ApiException(404, 'not_found', 'الاختبار غير متاح.');
    if ($student['class_id'] === null || (int) $student['class_id'] !== (int) $quiz['class_id']) throw new ApiException(403, 'quiz_class_forbidden', 'الاختبار غير مخصص لصفك.');
    if (!has_manual_content_access($userId)) throw new ApiException(403, 'content_access_required', 'يجب أن يمنحك المدير أو المراجع صلاحية المحتوى أولًا.');
}

function quiz_student_list(int $userId): array
{
    $db = database_connection();
    if (!has_manual_content_access($userId)) return [];
    $student = quiz_student_id($db, $userId);
    if ($student['class_id'] === null) return [];
    $hasSubscription = has_manual_content_access($userId);
    $statement = $db->prepare(
        "SELECT qz.id, qz.class_subject_id, qz.title, qz.description, qz.duration_minutes, qz.total_marks, qz.passing_marks,
                qz.starts_at, qz.ends_at, qz.requires_subscription,
                :has_subscription AS has_active_subscription,
                (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = qz.id) AS question_count
         FROM quizzes qz JOIN class_subjects cs ON cs.id = qz.class_subject_id
         JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id JOIN subjects s ON s.id = cs.subject_id
         WHERE qz.is_published = TRUE AND cs.class_id = :class_id AND cs.is_active = TRUE AND c.is_active = TRUE AND st.is_active = TRUE AND s.is_active = TRUE
           AND (qz.ends_at IS NULL OR qz.ends_at > CURRENT_TIMESTAMP)
           AND (qz.requires_subscription = FALSE OR :can_access = TRUE)
         ORDER BY qz.starts_at NULLS FIRST, qz.id"
    );
    $statement->execute(['has_subscription' => $hasSubscription, 'can_access' => $hasSubscription, 'class_id' => $student['class_id']]);
    return $statement->fetchAll();
}

function quiz_student_detail(int $userId, int $quizId): array
{
    $db = database_connection();
    $student = quiz_student_id($db, $userId);
    $quiz = quiz_fetch($db, $quizId);
    if (!$quiz || !database_boolean($quiz['is_published'])) throw new ApiException(404, 'not_found', 'الاختبار غير متاح.');
    quiz_student_can_access($db, $student, $quiz, $userId);
    if ($quiz['starts_at'] !== null && new DateTimeImmutable($quiz['starts_at']) > new DateTimeImmutable('now')) throw new ApiException(409, 'quiz_not_open', 'لم يبدأ وقت الاختبار بعد.');
    if ($quiz['ends_at'] !== null && new DateTimeImmutable($quiz['ends_at']) <= new DateTimeImmutable('now')) throw new ApiException(409, 'quiz_closed', 'انتهت نافذة الاختبار.');
    $statement = $db->prepare("SELECT q.id, q.question_text, q.question_type, qq.question_mark, qq.sort_order FROM quiz_questions qq JOIN questions q ON q.id = qq.question_id WHERE qq.quiz_id = :quiz_id AND q.status = 'APPROVED' ORDER BY qq.sort_order, q.id");
    $statement->execute(['quiz_id' => $quizId]);
    $questions = $statement->fetchAll();
    $options = $db->prepare('SELECT id, option_text FROM question_options WHERE question_id = :question_id ORDER BY sort_order, id');
    foreach ($questions as &$question) {
        $options->execute(['question_id' => $question['id']]);
        $question['options'] = $options->fetchAll();
    }
    unset($question);
    unset($quiz['created_by'], $quiz['is_published'], $quiz['requires_subscription'], $quiz['class_id'], $quiz['class_subject_active'], $quiz['class_active'], $quiz['stage_active'], $quiz['subject_active']);
    $quiz['questions'] = $questions;
    return $quiz;
}

function quiz_is_expired(array $attempt): bool
{
    return $attempt['deadline_at'] !== null && new DateTimeImmutable($attempt['deadline_at']) <= new DateTimeImmutable('now');
}

function quiz_ensure_attempt_deadline(PDO $db, array &$attempt, array $quiz): void
{
    if (($attempt['deadline_at'] ?? null) !== null) return;
    $duration = $quiz['duration_minutes'] === null ? quiz_positive_setting('QUIZ_DURATION_MINUTES') : (int) $quiz['duration_minutes'];
    $started = new DateTimeImmutable($attempt['started_at']);
    $deadline = $started->modify('+' . $duration . ' minutes');
    if (($quiz['ends_at'] ?? null) !== null) {
        $windowEnd = new DateTimeImmutable($quiz['ends_at']);
        $deadline = $windowEnd <= $started ? $started->modify('+1 second') : min($deadline, $windowEnd);
    }
    $timestamp = $deadline->format(DateTimeInterface::ATOM);
    $update = $db->prepare('UPDATE quiz_attempts SET deadline_at = :deadline_at WHERE id = :id AND deadline_at IS NULL');
    $update->execute(['deadline_at' => $timestamp, 'id' => $attempt['id']]);
    $attempt['deadline_at'] = $timestamp;
}

function quiz_saved_answers(PDO $db, int $attemptId): array
{
    $statement = $db->prepare('SELECT question_id, selected_option_id, essay_answer, answered_at FROM student_answers WHERE attempt_id = :id ORDER BY question_id');
    $statement->execute(['id' => $attemptId]);
    return $statement->fetchAll();
}

function quiz_finalize_attempt(PDO $db, int $attemptId, string $mode, ?int $graderId = null): array
{
    if ($mode === 'CANCEL_COUNTED' || $mode === 'CANCEL_FREE') {
        $update = $db->prepare("UPDATE quiz_attempts SET status = 'CANCELLED', submitted_at = COALESCE(submitted_at, CURRENT_TIMESTAMP), score = NULL WHERE id = :id RETURNING id, status, attempt_number, started_at, submitted_at, deadline_at, score");
        $update->execute(['id' => $attemptId]);
        return $update->fetch();
    }
    $grade = $db->prepare(
        'UPDATE student_answers sa
         SET is_correct = qo.is_correct,
             awarded_mark = CASE WHEN qo.is_correct THEN qq.question_mark ELSE 0 END
         FROM quiz_questions qq, question_options qo
         WHERE qo.id = sa.selected_option_id AND sa.attempt_id = :attempt_id
           AND qq.quiz_id = (SELECT quiz_id FROM quiz_attempts WHERE id = :quiz_attempt_id)
           AND qq.question_id = sa.question_id'
    );
    $grade->execute(['attempt_id' => $attemptId, 'quiz_attempt_id' => $attemptId]);
    $pending = $db->prepare("SELECT EXISTS (SELECT 1 FROM student_answers sa JOIN questions q ON q.id = sa.question_id WHERE sa.attempt_id = :id AND q.question_type = 'ESSAY' AND sa.essay_answer IS NOT NULL AND sa.awarded_mark IS NULL)");
    $pending->execute(['id' => $attemptId]);
    $hasPendingEssay = database_boolean($pending->fetchColumn());
    $scoreStatement = $db->prepare('SELECT COALESCE(SUM(awarded_mark), 0) FROM student_answers WHERE attempt_id = :id');
    $scoreStatement->execute(['id' => $attemptId]);
    $score = $scoreStatement->fetchColumn();
    $status = $hasPendingEssay ? 'SUBMITTED' : 'GRADED';
    $update = $db->prepare('UPDATE quiz_attempts SET status = :status, submitted_at = COALESCE(submitted_at, CURRENT_TIMESTAMP), score = :score WHERE id = :id RETURNING id, status, attempt_number, started_at, submitted_at, deadline_at, score');
    $update->execute(['status' => $status, 'score' => $score, 'id' => $attemptId]);
    return $update->fetch();
}

function quiz_start_attempt(array $actor, int $quizId): array
{
    $maxAttempts = quiz_positive_setting('QUIZ_MAX_ATTEMPTS');
    if ($maxAttempts > 32767) throw new ApiException(503, 'quiz_not_configured', 'QUIZ_MAX_ATTEMPTS يتجاوز الحد الذي يدعمه المخطط.');
    $policy = quiz_incomplete_policy();
    $db = database_connection();
    $db->beginTransaction();
    try {
        $quiz = quiz_fetch($db, $quizId, true);
        if (!$quiz || !database_boolean($quiz['is_published'])) throw new ApiException(404, 'not_found', 'الاختبار غير متاح.');
        $student = quiz_student_id($db, (int) $actor['id']);
        quiz_student_can_access($db, $student, $quiz, (int) $actor['id']);
        if ($quiz['starts_at'] !== null && new DateTimeImmutable($quiz['starts_at']) > new DateTimeImmutable('now')) throw new ApiException(409, 'quiz_not_open', 'لم يبدأ وقت الاختبار بعد.');
        if ($quiz['ends_at'] !== null && new DateTimeImmutable($quiz['ends_at']) <= new DateTimeImmutable('now')) throw new ApiException(409, 'quiz_closed', 'انتهت نافذة الاختبار.');
        $questions = $db->prepare("SELECT COUNT(*) FROM quiz_questions qq JOIN questions q ON q.id = qq.question_id WHERE qq.quiz_id = :id AND q.status = 'APPROVED'");
        $questions->execute(['id' => $quizId]);
        if ((int) $questions->fetchColumn() < 1) throw new ApiException(409, 'quiz_questions_unavailable', 'لا يحتوي الاختبار على أسئلة معتمدة متاحة.');
        $active = $db->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'IN_PROGRESS' ORDER BY started_at DESC LIMIT 1 FOR UPDATE");
        $active->execute(['quiz_id' => $quizId, 'student_id' => $student['id']]);
        $attempt = $active->fetch();
        if ($attempt) {
            quiz_ensure_attempt_deadline($db, $attempt, $quiz);
            if (!quiz_is_expired($attempt)) {
                $savedAnswers = quiz_saved_answers($db, (int) $attempt['id']);
                $db->commit();
                return ['attempt' => $attempt, 'answers' => $savedAnswers, 'resumed' => true];
            }
        }
        if ($attempt) {
            $expired = quiz_finalize_attempt($db, (int) $attempt['id'], $policy);
            audit_log('quiz.attempt.incomplete_processed', 'quiz_attempt', (int) $attempt['id'], ['policy' => $policy, 'status' => $expired['status']], (int) $actor['id']);
        }
        $countSql = "SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id" . ($policy === 'CANCEL_FREE' ? " AND status <> 'CANCELLED'" : '');
        $count = $db->prepare($countSql);
        $count->execute(['quiz_id' => $quizId, 'student_id' => $student['id']]);
        if ((int) $count->fetchColumn() >= $maxAttempts) {
            $db->commit();
            throw new ApiException(409, 'attempt_limit_reached', 'استنفدت عدد المحاولات المتاحة.');
        }
        $duration = $quiz['duration_minutes'] === null ? quiz_positive_setting('QUIZ_DURATION_MINUTES') : (int) $quiz['duration_minutes'];
        $start = new DateTimeImmutable('now');
        $deadline = $start->modify('+' . $duration . ' minutes');
        if ($quiz['ends_at'] !== null && new DateTimeImmutable($quiz['ends_at']) < $deadline) $deadline = new DateTimeImmutable($quiz['ends_at']);
        $nextNumber = $db->prepare('SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id');
        $nextNumber->execute(['quiz_id' => $quizId, 'student_id' => $student['id']]);
        $attemptNumber = (int) $nextNumber->fetchColumn();
        if ($attemptNumber > 32767) {
            $db->commit();
            throw new ApiException(409, 'attempt_number_limit', 'وصل ترقيم المحاولات إلى الحد الذي يدعمه المخطط.');
        }
        $insert = $db->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, attempt_number, started_at, deadline_at, status) VALUES (:quiz_id, :student_id, :attempt_number, :started_at, :deadline_at, 'IN_PROGRESS') RETURNING id, quiz_id, attempt_number, started_at, deadline_at, status");
        $insert->execute(['quiz_id' => $quizId, 'student_id' => $student['id'], 'attempt_number' => $attemptNumber, 'started_at' => $start->format(DateTimeInterface::ATOM), 'deadline_at' => $deadline->format(DateTimeInterface::ATOM)]);
        $attempt = $insert->fetch();
        audit_log('quiz.attempt.started', 'quiz_attempt', (int) $attempt['id'], ['quiz_id' => $quizId, 'attempt_number' => (int) $attempt['attempt_number']], (int) $actor['id']);
        $db->commit();
        return ['attempt' => $attempt, 'answers' => [], 'resumed' => false];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function quiz_save_answer(array $actor, int $attemptId, int $questionId, array $input): array
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT qa.*, st.user_id FROM quiz_attempts qa JOIN students st ON st.id = qa.student_id WHERE qa.id = :id FOR UPDATE OF qa');
        $statement->execute(['id' => $attemptId]);
        $attempt = $statement->fetch();
        if (!$attempt) throw new ApiException(404, 'not_found', 'المحاولة غير موجودة.');
        if ((int) $attempt['user_id'] !== (int) $actor['id']) throw new ApiException(403, 'attempt_owner_required', 'يمكن للطالب تعديل إجاباته فقط.');
        if ($attempt['status'] !== 'IN_PROGRESS') throw new ApiException(409, 'attempt_not_active', 'المحاولة ليست مفتوحة للإجابة.');
        $quiz = quiz_attempt_quiz($db, $attemptId);
        if (!$quiz) throw new ApiException(404, 'not_found', 'الاختبار غير موجود.');
        quiz_ensure_attempt_deadline($db, $attempt, $quiz);
        if (quiz_is_expired($attempt)) {
            $policy = quiz_incomplete_policy();
            $finalized = quiz_finalize_attempt($db, $attemptId, $policy);
            audit_log('quiz.attempt.incomplete_processed', 'quiz_attempt', $attemptId, ['policy' => $policy, 'status' => $finalized['status']], (int) $actor['id']);
            $db->commit();
            throw new ApiException(409, 'attempt_time_expired', 'انتهى زمن المحاولة وأغلقها الخادم.');
        }
        $question = $db->prepare('SELECT q.question_type FROM questions q JOIN quiz_questions qq ON qq.question_id = q.id WHERE qq.quiz_id = :quiz_id AND q.id = :question_id AND q.status = \'APPROVED\'');
        $question->execute(['quiz_id' => $attempt['quiz_id'], 'question_id' => $questionId]);
        $type = $question->fetchColumn();
        if ($type === false) throw new ApiException(422, 'question_not_in_quiz', 'السؤال لا ينتمي إلى هذه المحاولة.');
        $optionId = null;
        $essay = null;
        if ($type === 'ESSAY') {
            $essay = content_text($input['essay_answer'] ?? null, 'essay_answer', 50000, true);
        } else {
            $optionId = content_positive_id($input['selected_option_id'] ?? null, 'selected_option_id');
            $validOption = $db->prepare('SELECT 1 FROM question_options WHERE id = :option_id AND question_id = :question_id');
            $validOption->execute(['option_id' => $optionId, 'question_id' => $questionId]);
            if ($validOption->fetchColumn() === false) throw new ApiException(422, 'invalid_question_option', 'الخيار المختار لا يتبع هذا السؤال.');
        }
        $save = $db->prepare(
            'INSERT INTO student_answers (attempt_id, question_id, selected_option_id, essay_answer, is_correct, awarded_mark)
             VALUES (:attempt_id, :question_id, :option_id, :essay_answer, NULL, NULL)
             ON CONFLICT (attempt_id, question_id) DO UPDATE SET selected_option_id = EXCLUDED.selected_option_id,
                 essay_answer = EXCLUDED.essay_answer, is_correct = NULL, awarded_mark = NULL, answered_at = CURRENT_TIMESTAMP
             RETURNING id, question_id, answered_at'
        );
        $save->execute(['attempt_id' => $attemptId, 'question_id' => $questionId, 'option_id' => $optionId, 'essay_answer' => $essay]);
        audit_log('quiz.answer.saved', 'quiz_attempt', $attemptId, ['question_id' => $questionId], (int) $actor['id']);
        $db->commit();
        return $save->fetch();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function quiz_submit_attempt(array $actor, int $attemptId): array
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT qa.*, st.user_id FROM quiz_attempts qa JOIN students st ON st.id = qa.student_id WHERE qa.id = :id FOR UPDATE OF qa');
        $statement->execute(['id' => $attemptId]);
        $attempt = $statement->fetch();
        if (!$attempt) throw new ApiException(404, 'not_found', 'المحاولة غير موجودة.');
        if ((int) $attempt['user_id'] !== (int) $actor['id']) throw new ApiException(403, 'attempt_owner_required', 'يمكن للطالب إرسال محاولته فقط.');
        if ($attempt['status'] !== 'IN_PROGRESS') throw new ApiException(409, 'attempt_not_active', 'المحاولة أرسلت أو أغلقت بالفعل.');
        $quiz = quiz_attempt_quiz($db, $attemptId);
        if (!$quiz) throw new ApiException(404, 'not_found', 'الاختبار غير موجود.');
        quiz_ensure_attempt_deadline($db, $attempt, $quiz);
        $policy = quiz_is_expired($attempt) ? quiz_incomplete_policy() : 'AUTO_SUBMIT';
        $result = quiz_finalize_attempt($db, $attemptId, $policy);
        audit_log($result['status'] === 'CANCELLED' ? 'quiz.attempt.incomplete_cancelled' : 'quiz.attempt.submitted', 'quiz_attempt', $attemptId, ['status' => $result['status']], (int) $actor['id']);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function quiz_attempt_quiz(PDO $db, int $attemptId): ?array
{
    $statement = $db->prepare('SELECT qz.*, cs.class_id FROM quiz_attempts qa JOIN quizzes qz ON qz.id = qa.quiz_id JOIN class_subjects cs ON cs.id = qz.class_subject_id WHERE qa.id = :id');
    $statement->execute(['id' => $attemptId]);
    $quiz = $statement->fetch();
    return $quiz ?: null;
}

function quiz_get_result(array $actor, int $attemptId): array
{
    $db = database_connection();
    $attemptQuery = $db->prepare('SELECT qa.*, st.user_id, st.id AS student_id FROM quiz_attempts qa JOIN students st ON st.id = qa.student_id WHERE qa.id = :id');
    $attemptQuery->execute(['id' => $attemptId]);
    $attempt = $attemptQuery->fetch();
    if (!$attempt) throw new ApiException(404, 'not_found', 'المحاولة غير موجودة.');
    $studentView = in_array('student', $actor['roles'] ?? [], true);
    if ($studentView) {
        if ((int) $attempt['user_id'] !== (int) $actor['id']) throw new ApiException(403, 'attempt_owner_required', 'يمكن للطالب عرض نتيجته فقط.');
    } else {
        require_permission('quizzes.manage');
        $quiz = quiz_attempt_quiz($db, $attemptId);
        quiz_assert_teacher_scope($db, $actor, $quiz);
    }
    if ($attempt['status'] === 'IN_PROGRESS') {
        $db->beginTransaction();
        try {
            $locked = $db->prepare('SELECT * FROM quiz_attempts WHERE id = :id FOR UPDATE');
            $locked->execute(['id' => $attemptId]);
            $fresh = $locked->fetch();
            $quiz = quiz_attempt_quiz($db, $attemptId);
            if (!$quiz) throw new ApiException(404, 'not_found', 'الاختبار غير موجود.');
            quiz_ensure_attempt_deadline($db, $fresh, $quiz);
            if ($fresh['status'] === 'IN_PROGRESS' && quiz_is_expired($fresh)) {
                $policy = quiz_incomplete_policy();
                $finalized = quiz_finalize_attempt($db, $attemptId, $policy);
                audit_log('quiz.attempt.incomplete_processed', 'quiz_attempt', $attemptId, ['policy' => $policy, 'status' => $finalized['status']], (int) $actor['id']);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        $attemptQuery->execute(['id' => $attemptId]);
        $attempt = $attemptQuery->fetch();
    }
    if ($attempt['status'] === 'IN_PROGRESS') throw new ApiException(409, 'attempt_in_progress', 'لا تتوفر النتيجة قبل إرسال المحاولة أو انتهاء وقتها.');
    if ($studentView) {
        $visibility = quiz_result_visibility();
        if ($visibility === 'AFTER_WINDOW') {
            $quiz = quiz_attempt_quiz($db, $attemptId);
            if ($quiz['ends_at'] === null || new DateTimeImmutable($quiz['ends_at']) > new DateTimeImmutable('now')) throw new ApiException(403, 'result_not_available', 'تظهر النتائج بعد إغلاق نافذة الاختبار.');
        }
        if ($visibility === 'AFTER_MANUAL_GRADING' && $attempt['status'] !== 'GRADED') throw new ApiException(403, 'result_not_available', 'تظهر النتيجة بعد اكتمال التصحيح اليدوي.');
        if ($attempt['status'] === 'CANCELLED') throw new ApiException(403, 'result_not_available', 'لا توجد نتيجة منشورة لهذه المحاولة.');
    }
    $answers = $db->prepare(
        'SELECT sa.question_id, q.question_text, q.question_type, qq.question_mark, sa.selected_option_id,
                so.option_text AS selected_option_text, sa.essay_answer, sa.is_correct, sa.awarded_mark, sa.teacher_feedback, sa.graded_at
         FROM student_answers sa JOIN questions q ON q.id = sa.question_id
         JOIN quiz_questions qq ON qq.quiz_id = (SELECT quiz_id FROM quiz_attempts WHERE id = sa.attempt_id) AND qq.question_id = sa.question_id
         LEFT JOIN question_options so ON so.id = sa.selected_option_id
         WHERE sa.attempt_id = :attempt_id ORDER BY qq.sort_order, q.id'
    );
    $answers->execute(['attempt_id' => $attemptId]);
    $quiz = quiz_attempt_quiz($db, $attemptId);
    $result = [
        'quiz' => ['id' => (int) $attempt['quiz_id'], 'title' => $quiz['title'], 'total_marks' => $quiz['total_marks'], 'passing_marks' => $quiz['passing_marks']],
        'attempt' => $attempt,
        'answers' => $answers->fetchAll(),
    ];
    unset($result['attempt']['user_id'], $result['attempt']['student_id']);
    return $result;
}

function quiz_teacher_results(array $actor, int $quizId): array
{
    $db = database_connection();
    $quiz = quiz_fetch($db, $quizId);
    if (!$quiz) throw new ApiException(404, 'not_found', 'الاختبار غير موجود.');
    quiz_assert_teacher_scope($db, $actor, $quiz);
    $statement = $db->prepare(
        'SELECT qa.id AS attempt_id, qa.student_id, u.full_name AS student_name, qa.attempt_number,
                qa.started_at, qa.submitted_at, qa.deadline_at, qa.status, qa.score, qz.total_marks
         FROM quiz_attempts qa JOIN students st ON st.id = qa.student_id JOIN users u ON u.id = st.user_id
         JOIN quizzes qz ON qz.id = qa.quiz_id WHERE qa.quiz_id = :quiz_id ORDER BY u.full_name, qa.attempt_number'
    );
    $statement->execute(['quiz_id' => $quizId]);
    return $statement->fetchAll();
}

function quiz_grade_essay(array $actor, int $attemptId, int $questionId, array $input): array
{
    $mark = quiz_decimal($input['awarded_mark'] ?? null, 'awarded_mark', false);
    if ($mark === null) throw new ApiException(422, 'validation_failed', 'awarded_mark مطلوب.');
    $feedback = content_text($input['teacher_feedback'] ?? null, 'teacher_feedback', 5000);
    $db = database_connection();
    $db->beginTransaction();
    try {
        $attemptQuery = $db->prepare('SELECT qa.*, qz.class_subject_id FROM quiz_attempts qa JOIN quizzes qz ON qz.id = qa.quiz_id WHERE qa.id = :id FOR UPDATE OF qa');
        $attemptQuery->execute(['id' => $attemptId]);
        $attempt = $attemptQuery->fetch();
        if (!$attempt) throw new ApiException(404, 'not_found', 'المحاولة غير موجودة.');
        quiz_employee_id($db, $actor, (int) $attempt['class_subject_id']);
        if (!in_array($attempt['status'], ['SUBMITTED', 'GRADED'], true)) throw new ApiException(409, 'attempt_not_submitted', 'لا يمكن تصحيح إجابة قبل إرسال المحاولة.');
        $answer = $db->prepare("SELECT sa.id, q.question_type, qq.question_mark FROM student_answers sa JOIN questions q ON q.id = sa.question_id JOIN quiz_questions qq ON qq.question_id = q.id AND qq.quiz_id = :quiz_id WHERE sa.attempt_id = :attempt_id AND sa.question_id = :question_id FOR UPDATE OF sa");
        $answer->execute(['quiz_id' => $attempt['quiz_id'], 'attempt_id' => $attemptId, 'question_id' => $questionId]);
        $row = $answer->fetch();
        if (!$row || $row['question_type'] !== 'ESSAY') throw new ApiException(422, 'essay_answer_required', 'الإجابة ليست إجابة مقالية قابلة للتصحيح اليدوي.');
        if (quiz_decimal_cents($mark) > quiz_decimal_cents((string) $row['question_mark'])) throw new ApiException(422, 'grade_exceeds_question_mark', 'الدرجة تتجاوز الدرجة المحددة للسؤال.');
        $employeeId = content_employee_id($db, (int) $actor['id']);
        $save = $db->prepare('UPDATE student_answers SET awarded_mark = :mark, teacher_feedback = :feedback, graded_by = :graded_by, graded_at = CURRENT_TIMESTAMP WHERE id = :id');
        $save->execute(['mark' => $mark, 'feedback' => $feedback, 'graded_by' => $employeeId, 'id' => $row['id']]);
        $pending = $db->prepare("SELECT EXISTS (SELECT 1 FROM student_answers sa JOIN questions q ON q.id = sa.question_id WHERE sa.attempt_id = :id AND q.question_type = 'ESSAY' AND sa.essay_answer IS NOT NULL AND sa.awarded_mark IS NULL)");
        $pending->execute(['id' => $attemptId]);
        if (!database_boolean($pending->fetchColumn())) {
            $score = $db->prepare('SELECT COALESCE(SUM(awarded_mark), 0) FROM student_answers WHERE attempt_id = :id');
            $score->execute(['id' => $attemptId]);
            $update = $db->prepare("UPDATE quiz_attempts SET status = 'GRADED', score = :score WHERE id = :id RETURNING status, score");
            $update->execute(['score' => $score->fetchColumn(), 'id' => $attemptId]);
        }
        audit_log('quiz.essay.graded', 'quiz_attempt', $attemptId, ['question_id' => $questionId, 'awarded_mark' => $mark], (int) $actor['id']);
        $db->commit();
        return ['graded' => true, 'question_id' => $questionId, 'awarded_mark' => $mark];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
