<?php
declare(strict_types=1);

function academic_id(mixed $value, string $field): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException("المعرّف {$field} يجب أن يكون عددًا صحيحًا موجبًا.");
    return $id;
}

function academic_text(mixed $value, string $field, int $max, bool $required = true): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value)) throw new InvalidArgumentException("الحقل {$field} يجب أن يكون نصًا.");
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if (($required && $value === '') || $length === false || $length > $max) {
        throw new InvalidArgumentException("الحقل {$field} مطلوب وبحد أقصى {$max} محرفًا.");
    }
    return $value;
}

function academic_bool(mixed $value, string $field): bool
{
    if (!is_bool($value)) throw new InvalidArgumentException("الحقل {$field} يجب أن يكون true أو false.");
    return $value;
}

function academic_sort(mixed $value): int
{
    if (!is_int($value) || $value < 0 || $value > 32767) throw new InvalidArgumentException('sort_order يجب أن يكون عددًا صحيحًا من 0 إلى 32767.');
    return $value;
}

function academic_list_stages(bool $includeInactive = false): array
{
    $sql = 'SELECT id, code, name, description, sort_order, is_active FROM stages';
    if (!$includeInactive) $sql .= ' WHERE is_active = TRUE';
    $sql .= ' ORDER BY sort_order, id';
    $statement = database_connection()->prepare($sql);
    $statement->execute();
    return $statement->fetchAll();
}

function academic_list_classes(?int $stageId, bool $includeInactive = false): array
{
    $conditions = [];
    $params = [];
    if (!$includeInactive) $conditions[] = 'c.is_active = TRUE AND s.is_active = TRUE';
    if ($stageId !== null) { $conditions[] = 'c.stage_id = :stage_id'; $params['stage_id'] = $stageId; }
    $sql = 'SELECT c.id, c.stage_id, s.name AS stage_name, c.code, c.name, c.sort_order, c.is_active
            FROM classes c JOIN stages s ON s.id = c.stage_id';
    if ($conditions !== []) $sql .= ' WHERE ' . implode(' AND ', $conditions);
    $sql .= ' ORDER BY s.sort_order, c.sort_order, c.id';
    $statement = database_connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function academic_list_subjects(?int $classId, bool $includeInactive = false): array
{
    $sql = 'SELECT s.id, s.code, s.name, s.description, s.is_active,
                   cs.id AS class_subject_id, cs.class_id
            FROM subjects s LEFT JOIN class_subjects cs ON cs.subject_id = s.id';
    $conditions = [];
    $params = [];
    if (!$includeInactive) $conditions[] = 's.is_active = TRUE AND (cs.id IS NULL OR cs.is_active = TRUE)';
    if ($classId !== null) { $conditions[] = 'cs.class_id = :class_id'; $params['class_id'] = $classId; }
    if ($conditions !== []) $sql .= ' WHERE ' . implode(' AND ', $conditions);
    $sql .= ' ORDER BY s.name, cs.class_id';
    $statement = database_connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function academic_create_stage(array $input, int $actorId): array
{
    $data = [
        'code' => strtoupper(academic_text($input['code'] ?? null, 'code', 50)),
        'name' => academic_text($input['name'] ?? null, 'name', 100),
        'description' => academic_text($input['description'] ?? null, 'description', 5000, false),
        'sort_order' => academic_sort($input['sort_order'] ?? 0),
    ];
    return academic_insert('stages', $data, 'academic.stage.created', $actorId);
}

function academic_create_class(array $input, int $actorId): array
{
    $data = [
        'stage_id' => academic_id($input['stage_id'] ?? null, 'stage_id'),
        'code' => strtoupper(academic_text($input['code'] ?? null, 'code', 50)),
        'name' => academic_text($input['name'] ?? null, 'name', 100),
        'sort_order' => academic_sort($input['sort_order'] ?? 0),
    ];
    return academic_insert('classes', $data, 'academic.class.created', $actorId);
}

function academic_create_subject(array $input, int $actorId): array
{
    $code = academic_text($input['code'] ?? null, 'code', 50, false);
    $data = [
        'code' => $code === null ? null : strtoupper($code),
        'name' => academic_text($input['name'] ?? null, 'name', 120),
        'description' => academic_text($input['description'] ?? null, 'description', 5000, false),
    ];
    return academic_insert('subjects', $data, 'academic.subject.created', $actorId);
}

function academic_create_class_subject(array $input, int $actorId): array
{
    $data = [
        'class_id' => academic_id($input['class_id'] ?? null, 'class_id'),
        'subject_id' => academic_id($input['subject_id'] ?? null, 'subject_id'),
    ];
    return academic_insert('class_subjects', $data, 'academic.class_subject.created', $actorId);
}

function academic_create_lesson(array $input, int $actorId): array
{
    $data = [
        'class_subject_id' => academic_id($input['class_subject_id'] ?? null, 'class_subject_id'),
        'title' => academic_text($input['title'] ?? null, 'title', 200),
        'description' => academic_text($input['description'] ?? null, 'description', 5000, false),
        'body' => academic_text($input['body'] ?? null, 'body', 100000, false),
        'position' => academic_positive_int($input['position'] ?? 1, 'position'),
        'created_by_user_id' => $actorId,
    ];
    if (array_key_exists('is_published', $input)) $data['is_published'] = academic_bool($input['is_published'], 'is_published');
    return academic_insert('lessons', $data, 'academic.lesson.created', $actorId);
}

function academic_positive_int(mixed $value, string $field): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($number === false) throw new InvalidArgumentException("الحقل {$field} يجب أن يكون عددًا صحيحًا موجبًا.");
    return $number;
}

function academic_insert(string $table, array $data, string $event, int $actorId): array
{
    $allowed = ['stages', 'classes', 'subjects', 'class_subjects', 'lessons'];
    if (!in_array($table, $allowed, true)) throw new LogicException('Unsupported academic table.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        if ($table === 'lessons' && array_key_exists('created_by_user_id', $data)) {
            $employee = $db->prepare('SELECT id FROM employees WHERE user_id = :user_id AND is_active = TRUE FOR SHARE');
            $employee->execute(['user_id' => $data['created_by_user_id']]);
            $employeeId = $employee->fetchColumn();
            unset($data['created_by_user_id']);
            $data['created_by'] = $employeeId === false ? null : (int) $employeeId;
        }
        $columns = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ') RETURNING *';
        $statement = $db->prepare($sql);
        $statement->execute($data);
        $row = $statement->fetch();
        audit_log($event, $table, (int) $row['id'], ['name' => $row['name'] ?? $row['title'] ?? $row['code'] ?? null], $actorId);
        if ($table === 'lessons' && database_boolean($row['is_published'] ?? false)) {
            audit_log('content.published', 'lesson', (int) $row['id'], [], $actorId);
        }
        $db->commit();
        return $row;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function academic_update(string $table, int $id, array $input, int $actorId): ?array
{
    $fields = [
        'stages' => ['name' => 100, 'description' => 5000, 'sort_order' => 'sort', 'is_active' => 'bool'],
        'classes' => ['name' => 100, 'code' => 50, 'sort_order' => 'sort', 'is_active' => 'bool'],
        'subjects' => ['name' => 120, 'code' => 50, 'description' => 5000, 'is_active' => 'bool'],
        'class_subjects' => ['is_active' => 'bool'],
        'lessons' => ['class_subject_id' => 'positive', 'title' => 200, 'description' => 5000, 'body' => 100000, 'position' => 'positive', 'is_published' => 'bool'],
    ];
    if (!isset($fields[$table])) throw new LogicException('Unsupported academic table.');
    $data = [];
    foreach ($input as $key => $value) {
        if (!array_key_exists($key, $fields[$table])) throw new InvalidArgumentException("الحقل {$key} غير قابل للتعديل.");
        $rule = $fields[$table][$key];
        $data[$key] = match ($rule) {
            'sort' => academic_sort($value),
            'bool' => academic_bool($value, $key),
            'positive' => academic_positive_int($value, $key),
            default => academic_text($value, $key, $rule, in_array($key, ['name', 'code', 'title'], true)),
        };
        if ($key === 'code' && is_string($data[$key])) $data[$key] = strtoupper($data[$key]);
    }
    if ($data === []) throw new InvalidArgumentException('يجب إرسال حقل واحد على الأقل للتعديل.');
    $db = database_connection();
    $db->beginTransaction();
    try {
        $set = [];
        foreach (array_keys($data) as $key) $set[] = $key . ' = :' . $key;
        if ($table === 'subjects' || $table === 'lessons') $set[] = 'updated_at = CURRENT_TIMESTAMP';
        $data['id'] = $id;
        $statement = $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = :id RETURNING *');
        database_execute($statement, $data);
        $row = $statement->fetch();
        if (!$row) { $db->rollBack(); return null; }
        $singular = ['stages' => 'stage', 'classes' => 'class', 'subjects' => 'subject', 'class_subjects' => 'class_subject', 'lessons' => 'lesson'][$table];
        audit_log('academic.' . $singular . '.updated', $table, $id, ['fields' => array_keys($input)], $actorId);
        if ($table === 'lessons' && array_key_exists('is_published', $data)) {
            audit_log($data['is_published'] ? 'content.published' : 'content.unpublished', 'lesson', $id, [], $actorId);
        }
        $db->commit();
        return $row;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function academic_assign_teacher(int $employeeId, int $classSubjectId, int $actorId): void
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $teacher = $db->prepare("SELECT e.id FROM employees e JOIN users u ON u.id = e.user_id AND u.is_active = TRUE JOIN user_roles ur ON ur.user_id = e.user_id JOIN roles r ON r.id = ur.role_id WHERE e.id = :employee_id AND e.is_active = TRUE AND r.code = 'teacher' FOR UPDATE OF e");
        $teacher->execute(['employee_id' => $employeeId]);
        if ($teacher->fetchColumn() === false) throw new OutOfBoundsException('الأستاذ غير موجود أو حسابه غير نشط.');
        $mapping = $db->prepare('SELECT id FROM class_subjects WHERE id = :id AND is_active = TRUE FOR UPDATE');
        $mapping->execute(['id' => $classSubjectId]);
        if ($mapping->fetchColumn() === false) throw new OutOfBoundsException('ربط الصف والمادة غير موجود أو غير نشط.');
        $insert = $db->prepare('INSERT INTO teaching_assignments (employee_id, class_subject_id) VALUES (:employee_id, :class_subject_id) ON CONFLICT DO NOTHING');
        $insert->execute(['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId]);
        if ($insert->rowCount() > 0) audit_log('academic.teacher.assigned', 'teaching_assignment', null, ['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId], $actorId);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function academic_unassign_teacher(int $employeeId, int $classSubjectId, int $actorId): bool
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $statement = $db->prepare('DELETE FROM teaching_assignments WHERE employee_id = :employee_id AND class_subject_id = :class_subject_id');
        $statement->execute(['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId]);
        $deleted = $statement->rowCount() > 0;
        if ($deleted) audit_log('academic.teacher.unassigned', 'teaching_assignment', null, ['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId], $actorId);
        $db->commit();
        return $deleted;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function academic_teacher_assignments(int $userId): array
{
    $statement = database_connection()->prepare(
        'SELECT ta.employee_id, ta.class_subject_id, c.id AS class_id, c.name AS class_name,
                st.id AS stage_id, st.name AS stage_name, s.id AS subject_id, s.name AS subject_name
         FROM employees e JOIN teaching_assignments ta ON ta.employee_id = e.id
         JOIN class_subjects cs ON cs.id = ta.class_subject_id
         JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id
         JOIN subjects s ON s.id = cs.subject_id
         WHERE e.user_id = :user_id AND e.is_active AND cs.is_active AND c.is_active AND st.is_active AND s.is_active
         ORDER BY st.sort_order, c.sort_order, s.name'
    );
    $statement->execute(['user_id' => $userId]);
    return $statement->fetchAll();
}

function academic_search_resources(array $filters): array
{
    $limit = max(1, min(100, $filters['limit'] ?? 25));
    $offset = max(0, $filters['offset'] ?? 0);
    $where = ["lr.status = 'APPROVED'", 'lr.published_at IS NOT NULL', 'st.is_active = TRUE', 'c.is_active = TRUE', 'cs.is_active = TRUE', 's.is_active = TRUE', "EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = lr.id AND cr.status = 'APPROVED')"];
    $params = [];
    foreach (['stage_id' => 'st.id', 'class_id' => 'c.id', 'class_subject_id' => 'cs.id', 'subject_id' => 's.id', 'lesson_id' => 'l.id'] as $key => $column) {
        if (isset($filters[$key])) { $where[] = $column . ' = :' . $key; $params[$key] = $filters[$key]; }
    }
    if (isset($filters['q']) && $filters['q'] !== '') {
        $where[] = '(lr.title ILIKE :q1 OR COALESCE(lr.description, \'\') ILIKE :q2 OR COALESCE(l.title, \'\') ILIKE :q3 OR s.name ILIKE :q4)';
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
        foreach (['q1', 'q2', 'q3', 'q4'] as $placeholder) $params[$placeholder] = $pattern;
    }
    $sql = 'SELECT lr.id, lr.title, lr.description, lr.resource_type, lr.lesson_id,
                   st.id AS stage_id, st.name AS stage_name, c.id AS class_id, c.name AS class_name,
                   s.id AS subject_id, s.name AS subject_name, cs.id AS class_subject_id,
                   CASE WHEN l.is_published THEN l.title ELSE NULL END AS lesson_title,
                   lr.requires_subscription, lr.published_at
            FROM learning_resources lr
            JOIN class_subjects cs ON cs.id = lr.class_subject_id
            JOIN subjects s ON s.id = cs.subject_id
            JOIN classes c ON c.id = cs.class_id
            JOIN stages st ON st.id = c.stage_id
            LEFT JOIN lessons l ON l.id = lr.lesson_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY lr.published_at DESC NULLS LAST, lr.id DESC LIMIT :limit OFFSET :offset';
    $statement = database_connection()->prepare($sql);
    foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function academic_list_lessons(array $filters, bool $includeUnpublished = false): array
{
    $where = ['cs.is_active = TRUE', 'c.is_active = TRUE', 's.is_active = TRUE', 'st.is_active = TRUE'];
    if (!$includeUnpublished) $where[] = 'l.is_published = TRUE';
    $params = [];
    foreach (['stage_id' => 'st.id', 'class_id' => 'c.id', 'subject_id' => 's.id', 'class_subject_id' => 'cs.id'] as $key => $column) {
        if (isset($filters[$key])) { $where[] = $column . ' = :' . $key; $params[$key] = $filters[$key]; }
    }
    $sql = 'SELECT l.id, l.class_subject_id, l.title, l.description, l.position, l.is_published,
                   cs.class_id, c.name AS class_name, st.id AS stage_id, st.name AS stage_name,
                   s.id AS subject_id, s.name AS subject_name
            FROM lessons l JOIN class_subjects cs ON cs.id = l.class_subject_id
            JOIN classes c ON c.id = cs.class_id JOIN stages st ON st.id = c.stage_id
            JOIN subjects s ON s.id = cs.subject_id WHERE ' . implode(' AND ', $where) .
            ' ORDER BY st.sort_order, c.sort_order, s.name, l.position, l.id';
    $statement = database_connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}
