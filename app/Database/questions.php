<?php
declare(strict_types=1);

function list_question_bank(?int $classSubjectId = null, bool $includeUnpublished = false, ?int $creatorUserId = null): array
{
    $where = ['q.status = :status'];
    $params = ['status' => 'APPROVED'];
    if ($includeUnpublished) {
        $where = ['q.status <> :archived'];
        $params = ['archived' => 'ARCHIVED'];
    }
    if ($classSubjectId !== null && $classSubjectId > 0) {
        $where[] = 'q.class_subject_id = :class_subject_id';
        $params['class_subject_id'] = $classSubjectId;
    }
    if ($creatorUserId !== null) {
        $where[] = '(q.status = :published_status OR q.created_by = (SELECT id FROM employees WHERE user_id = :creator_user_id))';
        $params['published_status'] = 'APPROVED';
        $params['creator_user_id'] = $creatorUserId;
    }
    $statement = database_connection()->prepare(
        'SELECT q.id, q.question_text, q.question_type, q.difficulty, q.explanation, q.status,
                q.class_subject_id, s.name AS subject_name, c.name AS class_name,
                st.name AS stage_name, l.title AS lesson_title, author.user_id AS author_user_id,
                latest_review.feedback AS latest_feedback,
                COALESCE(json_agg(json_build_object(\'id\', qo.id, \'text\', qo.option_text, \'correct\', qo.is_correct)
                    ORDER BY qo.sort_order) FILTER (WHERE qo.id IS NOT NULL), \'[]\'::json) AS options
         FROM questions q
         JOIN class_subjects cs ON cs.id = q.class_subject_id
         JOIN subjects s ON s.id = cs.subject_id
         JOIN classes c ON c.id = cs.class_id
         JOIN stages st ON st.id = c.stage_id
         LEFT JOIN lessons l ON l.id = q.lesson_id
         JOIN employees author ON author.id = q.created_by
         LEFT JOIN LATERAL (SELECT feedback FROM content_reviews WHERE question_id=q.id ORDER BY reviewed_at DESC, id DESC LIMIT 1) latest_review ON TRUE
         LEFT JOIN question_options qo ON qo.question_id = q.id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY q.id, s.name, c.name, st.name, l.title, author.user_id, latest_review.feedback
         ORDER BY q.created_at DESC, q.id DESC
         LIMIT 200'
    );
    $statement->execute($params);
    return $statement->fetchAll();
}

function normalize_question_payload(
    string $text,
    string $type,
    string $difficulty,
    string $explanation,
    array $options,
    ?int $correctOption,
    string $status
): array {
    $text = trim($text);
    $textLength = preg_match_all('/./us', $text);
    if ($textLength === false || $textLength < 5 || $textLength > 10000) {
        throw new InvalidArgumentException('نص السؤال مطلوب (من 5 إلى 10000 حرف).');
    }
    if (!in_array($type, ['MCQ', 'TRUE_FALSE', 'ESSAY'], true)
        || !in_array($difficulty, ['EASY', 'MEDIUM', 'HARD'], true)
        || !in_array($status, ['DRAFT', 'PENDING_REVIEW'], true)) {
        throw new InvalidArgumentException('بيانات السؤال غير صالحة.');
    }
    $explanation = trim($explanation);
    if (strlen($explanation) > 10000) {
        throw new InvalidArgumentException('التفسير طويل جدًا.');
    }
    $cleanOptions = [];
    if ($type === 'MCQ') {
        foreach ($options as $index => $option) {
            if (!is_string($option)) {
                continue;
            }
            $option = trim($option);
            if ($option !== '') {
                $optionLength = preg_match_all('/./us', $option);
                if ($optionLength === false || $optionLength > 2000) {
                    throw new InvalidArgumentException('الخيار الواحد لا يتجاوز 2000 حرف.');
                }
                $cleanOptions[(int) $index] = $option;
            }
        }
        if (count($cleanOptions) < 2 || count($cleanOptions) > 8 || $correctOption === null || !isset($cleanOptions[$correctOption])) {
            throw new InvalidArgumentException('أدخل خيارين على الأقل وحدد إجابة صحيحة واحدة.');
        }
    } elseif ($type === 'TRUE_FALSE') {
        $cleanOptions = ['صحيح', 'خطأ'];
        if (!in_array($correctOption, [0, 1], true)) {
            throw new InvalidArgumentException('حدد إجابة سؤال الصح والخطأ.');
        }
    } else {
        $correctOption = null;
    }
    return [
        'text' => $text,
        'type' => $type,
        'difficulty' => $difficulty,
        'explanation' => $explanation,
        'options' => $cleanOptions,
        'correct_option' => $correctOption,
        'status' => $status,
    ];
}

function create_question(
    int $classSubjectId,
    ?int $lessonId,
    string $text,
    string $type,
    string $difficulty,
    string $explanation,
    array $options,
    ?int $correctOption,
    string $status,
    int $actorId
): int {
    if (!user_has_permission('roles.manage') && !in_array('teacher', current_user()['roles'] ?? [], true)) {
        throw new InvalidArgumentException('إنشاء الأسئلة متاح للأستاذ المسند إليه أو للمدير.');
    }
    $payload = normalize_question_payload($text, $type, $difficulty, $explanation, $options, $correctOption, $status);

    $pdo = database_connection();
    $scope = $pdo->prepare(
        'SELECT cs.id, e.id AS employee_id
         FROM class_subjects cs
         JOIN classes c ON c.id = cs.class_id AND c.is_active
         JOIN stages st ON st.id = c.stage_id AND st.is_active
         JOIN subjects s ON s.id = cs.subject_id AND s.is_active
         JOIN employees e ON e.user_id = :actor_id AND e.is_active
         WHERE cs.id = :class_subject_id AND cs.is_active'
    );
    $scope->execute(['actor_id' => $actorId, 'class_subject_id' => $classSubjectId]);
    $scopeRow = $scope->fetch();
    if (!$scopeRow) {
        throw new InvalidArgumentException('المادة غير موجودة أو غير نشطة.');
    }
    if (!user_has_permission('roles.manage')) {
        $assignment = $pdo->prepare('SELECT 1 FROM teaching_assignments WHERE employee_id = :employee_id AND class_subject_id = :class_subject_id');
        $assignment->execute(['employee_id' => $scopeRow['employee_id'], 'class_subject_id' => $classSubjectId]);
        if (!$assignment->fetchColumn()) {
            throw new InvalidArgumentException('هذه المادة غير مسندة إليك.');
        }
    }
    if ($lessonId !== null) {
        $lesson = $pdo->prepare('SELECT 1 FROM lessons WHERE id = :lesson_id AND class_subject_id = :class_subject_id');
        $lesson->execute(['lesson_id' => $lessonId, 'class_subject_id' => $classSubjectId]);
        if (!$lesson->fetchColumn()) {
            throw new InvalidArgumentException('الدرس لا يتبع المادة المختارة.');
        }
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO questions (class_subject_id, lesson_id, question_text, question_type, difficulty,
                                   explanation, status, created_by)
             VALUES (:class_subject_id, :lesson_id, :question_text, :question_type, :difficulty,
                     :explanation, :status, :employee_id) RETURNING id'
        );
        $insert->execute([
        'class_subject_id' => $classSubjectId,
            'lesson_id' => $lessonId,
            'question_text' => $payload['text'],
            'question_type' => $payload['type'],
            'difficulty' => $payload['difficulty'],
            'explanation' => $payload['explanation'] !== '' ? $payload['explanation'] : null,
            'status' => $payload['status'],
            'employee_id' => $scopeRow['employee_id'],
        ]);
        $questionId = (int) $insert->fetchColumn();
        if ($payload['options']) {
            $insertOption = $pdo->prepare(
                'INSERT INTO question_options (question_id, option_text, is_correct, sort_order)
                 VALUES (:question_id, :option_text, :is_correct, :sort_order)'
            );
            $sortOrder = 0;
            foreach ($payload['options'] as $index => $option) {
                $insertOption->execute([
                    'question_id' => $questionId,
                    'option_text' => $option,
                    'is_correct' => $payload['correct_option'] === $index ? 'true' : 'false',
                    'sort_order' => $sortOrder++,
                ]);
            }
        }
        record_audit_event($pdo, $actorId, 'question.created', 'question', $questionId, [
            'class_subject_id' => $classSubjectId,
            'question_type' => $payload['type'],
            'status' => $payload['status'],
        ]);
        $pdo->commit();
        return $questionId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function update_question(
    int $questionId,
    string $text,
    string $difficulty,
    string $explanation,
    array $options,
    ?int $correctOption,
    string $status,
    int $actorId
): void {
    if (!user_has_permission('roles.manage') && !in_array('teacher', current_user()['roles'] ?? [], true)) {
        throw new InvalidArgumentException('تعديل الأسئلة متاح لمؤلف السؤال أو للمدير.');
    }
    $pdo = database_connection();
    $payload = null;
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            'SELECT q.id, q.class_subject_id, q.created_by, q.lesson_id, q.question_type,
                    e.id AS actor_employee_id
             FROM questions q JOIN employees e ON e.user_id=:actor_id AND e.is_active
             WHERE q.id=:question_id AND q.status IN (\'DRAFT\', \'REJECTED\')
             FOR UPDATE OF q'
        );
        $query->execute(['actor_id' => $actorId, 'question_id' => $questionId]);
        $question = $query->fetch();
        if (!$question) {
            throw new InvalidArgumentException('السؤال غير موجود أو لا يمكن تعديله في حالته الحالية.');
        }
        if (!user_has_permission('roles.manage') && (int) $question['created_by'] !== (int) $question['actor_employee_id']) {
            throw new InvalidArgumentException('لا يمكنك تعديل سؤال أنشأه مستخدم آخر.');
        }
        if (!user_has_permission('roles.manage')) {
            $assignment = $pdo->prepare('SELECT 1 FROM teaching_assignments WHERE employee_id=:employee_id AND class_subject_id=:class_subject_id');
            $assignment->execute(['employee_id' => $question['actor_employee_id'], 'class_subject_id' => $question['class_subject_id']]);
            if (!$assignment->fetchColumn()) {
                throw new InvalidArgumentException('لم تعد هذه المادة مسندة إليك.');
            }
        }
        $payload = normalize_question_payload(
            $text,
            $question['question_type'],
            $difficulty,
            $explanation,
            $options,
            $correctOption,
            $status
        );
        $pdo->prepare(
            'UPDATE questions SET question_text=:question_text, difficulty=:difficulty,
                explanation=:explanation, status=:status WHERE id=:id'
        )->execute([
            'question_text' => $payload['text'],
            'difficulty' => $payload['difficulty'],
            'explanation' => $payload['explanation'] !== '' ? $payload['explanation'] : null,
            'status' => $payload['status'],
            'id' => $questionId,
        ]);
        $pdo->prepare('DELETE FROM question_options WHERE question_id=:id')->execute(['id' => $questionId]);
        if ($payload['options']) {
            $insertOption = $pdo->prepare(
                'INSERT INTO question_options (question_id, option_text, is_correct, sort_order)
                 VALUES (:question_id, :option_text, :is_correct, :sort_order)'
            );
            $sortOrder = 0;
            foreach ($payload['options'] as $index => $option) {
                $insertOption->execute([
                    'question_id' => $questionId,
                    'option_text' => $option,
                    'is_correct' => $payload['correct_option'] === $index ? 'true' : 'false',
                    'sort_order' => $sortOrder++,
                ]);
            }
        }
        record_audit_event($pdo, $actorId, 'question.updated', 'question', $questionId, [
            'status' => $payload['status'],
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function review_question(int $questionId, int $actorId, string $decision, string $feedback): void
{
    $reviewStatus = match ($decision) {
        'approve' => 'APPROVED',
        'reject' => 'REJECTED',
        'revise' => 'NEEDS_MODIFICATION',
        default => throw new InvalidArgumentException('قرار المراجعة غير صالح.'),
    };
    $feedback = trim($feedback);
    if (strlen($feedback) > 3000) {
        throw new InvalidArgumentException('الملاحظة طويلة جدًا.');
    }
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $question = $pdo->prepare('SELECT id, status FROM questions WHERE id = :id FOR UPDATE');
        $question->execute(['id' => $questionId]);
        $row = $question->fetch();
        if (!$row || !in_array($row['status'], ['PENDING_REVIEW', 'REJECTED'], true)) {
            throw new InvalidArgumentException('السؤال غير موجود ضمن العناصر القابلة للمراجعة.');
        }
        $employee = $pdo->prepare('SELECT id FROM employees WHERE user_id = :user_id AND is_active');
        $employee->execute(['user_id' => $actorId]);
        $reviewerId = $employee->fetchColumn();
        if ($reviewerId === false) {
            throw new RuntimeException('حساب المراجع غير مرتبط بملف موظف.');
        }
        $targetStatus = match ($decision) {
            'approve' => 'APPROVED',
            'reject' => 'REJECTED',
            'revise' => 'DRAFT',
        };
        $pdo->prepare('UPDATE questions SET status = :status WHERE id = :id')->execute(['status' => $targetStatus, 'id' => $questionId]);
        $pdo->prepare(
            'INSERT INTO content_reviews (question_id, reviewer_id, status, feedback)
             VALUES (:question_id, :reviewer_id, :status, :feedback)'
        )->execute([
            'question_id' => $questionId,
            'reviewer_id' => $reviewerId,
            'status' => $reviewStatus,
            'feedback' => $feedback !== '' ? $feedback : null,
        ]);
        record_audit_event($pdo, $actorId, 'question.reviewed', 'question', $questionId, ['decision' => $decision]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function list_question_subjects(?int $actorId = null): array
{
    $sql = 'SELECT cs.id AS class_subject_id, st.name AS stage_name, c.name AS class_name, s.name AS subject_name
            FROM class_subjects cs
            JOIN classes c ON c.id = cs.class_id AND c.is_active
            JOIN stages st ON st.id = c.stage_id AND st.is_active
            JOIN subjects s ON s.id = cs.subject_id AND s.is_active
            WHERE cs.is_active';
    $params = [];
    if ($actorId !== null && !user_has_permission('roles.manage')) {
        $sql .= ' AND EXISTS (SELECT 1 FROM employees e JOIN teaching_assignments ta ON ta.employee_id=e.id
                            WHERE e.user_id=:actor_id AND e.is_active AND ta.class_subject_id=cs.id)';
        $params['actor_id'] = $actorId;
    }
    $sql .= ' ORDER BY st.sort_order, c.sort_order, s.name';
    $statement = database_connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function list_question_lessons(int $classSubjectId): array
{
    $statement = database_connection()->prepare('SELECT id, title FROM lessons WHERE class_subject_id=:id ORDER BY position, id');
    $statement->execute(['id' => $classSubjectId]);
    return $statement->fetchAll();
}
