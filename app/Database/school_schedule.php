<?php
declare(strict_types=1);

function school_schedule_days(): array
{
    return [
        0 => 'الأحد',
        1 => 'الاثنين',
        2 => 'الثلاثاء',
        3 => 'الأربعاء',
        4 => 'الخميس',
        5 => 'الجمعة',
        6 => 'السبت',
    ];
}

function school_schedule_weekly_grid(array $entries): array
{
    $dayNames = school_schedule_days();
    $days = [];
    $slots = [];

    foreach ($entries as $entry) {
        if (($entry['recurrence_type'] ?? null) !== 'WEEKLY') {
            continue;
        }
        $day = filter_var($entry['day_of_week'] ?? null, FILTER_VALIDATE_INT);
        if ($day === false || $day < 0 || $day > 6) {
            continue;
        }

        $startsAt = substr((string) ($entry['starts_at'] ?? ''), 0, 5);
        $endsAt = substr((string) ($entry['ends_at'] ?? ''), 0, 5);
        $slotKey = $startsAt . '-' . $endsAt;
        $days[$day] = $dayNames[$day];
        $slots[$slotKey] ??= [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'entries_by_day' => [],
        ];
        $slots[$slotKey]['entries_by_day'][$day][] = $entry;
    }

    krsort($days, SORT_NUMERIC);
    uasort($slots, static fn(array $left, array $right): int => [$left['starts_at'], $left['ends_at']] <=> [$right['starts_at'], $right['ends_at']]);

    return ['days' => $days, 'slots' => array_values($slots)];
}

function school_schedule_validate_date(string $value, string $field): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || str_starts_with($value, '0000-')) {
        throw new InvalidArgumentException("أدخل تاريخًا صحيحًا في حقل {$field}.");
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException("أدخل تاريخًا صحيحًا في حقل {$field}.");
    }
    return $value;
}

function school_schedule_validate_time(string $value, string $field): string
{
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
        throw new InvalidArgumentException("أدخل وقتًا صحيحًا في حقل {$field}.");
    }
    return $value;
}

function school_schedule_dates_overlap(array $left, array $right): bool
{
    $leftType = $left['recurrence_type'];
    $rightType = $right['recurrence_type'];
    if ($leftType === 'WEEKLY' && $rightType === 'WEEKLY') {
        return (int) $left['day_of_week'] === (int) $right['day_of_week'];
    }
    if ($leftType === 'ONCE' && $rightType === 'ONCE') {
        return $left['occurrence_date'] === $right['occurrence_date'];
    }
    $weekly = $leftType === 'WEEKLY' ? $left : $right;
    $once = $leftType === 'ONCE' ? $left : $right;
    $date = new DateTimeImmutable((string) $once['occurrence_date']);
    return (int) $weekly['day_of_week'] === (int) $date->format('w');
}

function school_schedule_entries_conflict(array $left, array $right): bool
{
    $leftClass = $left['class_id'] ?? null;
    $rightClass = $right['class_id'] ?? null;
    $classesOverlap = $leftClass === null || $rightClass === null || (int) $leftClass === (int) $rightClass;
    $leftTeacher = $left['teacher_id'] ?? null;
    $rightTeacher = $right['teacher_id'] ?? null;
    $teachersOverlap = $leftTeacher !== null && $rightTeacher !== null
        && (int) $leftTeacher === (int) $rightTeacher;
    if (!$classesOverlap && !$teachersOverlap) {
        return false;
    }

    $leftStart = substr((string) $left['starts_at'], 0, 5);
    $leftEnd = substr((string) $left['ends_at'], 0, 5);
    $rightStart = substr((string) $right['starts_at'], 0, 5);
    $rightEnd = substr((string) $right['ends_at'], 0, 5);
    $timesOverlap = $leftStart < $rightEnd && $rightStart < $leftEnd;
    return $timesOverlap && school_schedule_dates_overlap($left, $right)
        && ($classesOverlap || $teachersOverlap);
}

function school_schedule_list_classes(): array
{
    return database_connection()->query(
        'SELECT c.id, c.name, st.name AS stage_name
         FROM classes c
         JOIN stages st ON st.id = c.stage_id
         WHERE c.is_active = TRUE AND st.is_active = TRUE
         ORDER BY st.sort_order, c.sort_order, c.id'
    )->fetchAll();
}

function school_schedule_list_assignments(): array
{
    return database_connection()->query(
        "SELECT cs.id AS class_subject_id, cs.class_id, st.name AS stage_name,
                c.name AS class_name, s.name AS subject_name, e.id AS teacher_id,
                u.full_name AS teacher_name
         FROM class_subjects cs
         JOIN classes c ON c.id = cs.class_id AND c.is_active = TRUE
         JOIN stages st ON st.id = c.stage_id AND st.is_active = TRUE
         JOIN subjects s ON s.id = cs.subject_id AND s.is_active = TRUE
         JOIN teaching_assignments ta ON ta.class_subject_id = cs.id
         JOIN employees e ON e.id = ta.employee_id AND e.is_active = TRUE
         JOIN users u ON u.id = e.user_id AND u.is_active = TRUE
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id AND r.code = 'teacher'
         WHERE cs.is_active = TRUE
         ORDER BY st.sort_order, c.sort_order, s.name, u.full_name"
    )->fetchAll();
}

function school_schedule_list_entries(array $actor): array
{
    $where = ['se.is_active = TRUE'];
    $params = [];
    $roles = $actor['roles'] ?? [];
    if (in_array('admin', $roles, true)) {
    } elseif (in_array('student', $roles, true)) {
        $where[] = '(se.class_id IS NULL OR se.class_id = (SELECT st.class_id FROM students st WHERE st.user_id = :user_id))';
        $params['user_id'] = (int) $actor['id'];
    } elseif (in_array('teacher', $roles, true)) {
        $where[] = '(se.class_id IS NULL OR se.class_id IN (
            SELECT cs.class_id
            FROM employees e
            JOIN teaching_assignments ta ON ta.employee_id = e.id
            JOIN class_subjects cs ON cs.id = ta.class_subject_id
            WHERE e.user_id = :user_id AND e.is_active = TRUE
        ))';
        $params['user_id'] = (int) $actor['id'];
    } else {
        return [];
    }

    $sql = 'SELECT se.id, se.entry_type, se.recurrence_type, se.class_id, se.class_subject_id,
                   se.teacher_id, se.title, se.day_of_week, se.occurrence_date,
                   se.starts_at, se.ends_at, c.name AS class_name, st.name AS stage_name,
                   s.name AS subject_name, u.full_name AS teacher_name
            FROM school_schedule_entries se
            LEFT JOIN classes c ON c.id = se.class_id
            LEFT JOIN stages st ON st.id = c.stage_id
            LEFT JOIN class_subjects cs ON cs.id = se.class_subject_id
            LEFT JOIN subjects s ON s.id = cs.subject_id
            LEFT JOIN employees e ON e.id = se.teacher_id
            LEFT JOIN users u ON u.id = e.user_id
            WHERE ' . implode(' AND ', $where) . '
              AND (se.recurrence_type = \'WEEKLY\' OR se.occurrence_date >= CURRENT_DATE)
            ORDER BY CASE WHEN se.recurrence_type = \'ONCE\' THEN se.occurrence_date END,
                     se.day_of_week, se.starts_at, c.name
            LIMIT 300';
    $statement = database_connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function school_schedule_list_holidays(?array $actor, int $limit = 30): array
{
    $where = ['h.is_published = TRUE', 'h.ends_on >= CURRENT_DATE'];
    $params = [];
    $roles = $actor['roles'] ?? [];
    if ($actor === null || !in_array('admin', $roles, true)) {
        if ($actor !== null && in_array('student', $roles, true)) {
            $where[] = '(h.class_id IS NULL OR h.class_id = (SELECT st.class_id FROM students st WHERE st.user_id = :user_id))';
            $params['user_id'] = (int) $actor['id'];
        } elseif ($actor !== null && in_array('teacher', $roles, true)) {
            $where[] = '(h.class_id IS NULL OR h.class_id IN (
                SELECT cs.class_id
                FROM employees e
                JOIN teaching_assignments ta ON ta.employee_id = e.id
                JOIN class_subjects cs ON cs.id = ta.class_subject_id
                WHERE e.user_id = :user_id AND e.is_active = TRUE
            ))';
            $params['user_id'] = (int) $actor['id'];
        } else {
            $where[] = 'h.class_id IS NULL';
        }
    }

    $statement = database_connection()->prepare(
        'SELECT h.id, h.class_id, h.title, h.description, h.starts_on, h.ends_on,
                c.name AS class_name, st.name AS stage_name
         FROM school_holidays h
         LEFT JOIN classes c ON c.id = h.class_id
         LEFT JOIN stages st ON st.id = c.stage_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY h.starts_on, h.id
         LIMIT :limit'
    );
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, PDO::PARAM_INT);
    }
    $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function school_schedule_list_manager_holidays(int $limit = 100): array
{
    $statement = database_connection()->prepare(
        'SELECT h.id, h.class_id, h.title, h.description, h.starts_on, h.ends_on,
                h.is_published, c.name AS class_name, st.name AS stage_name
         FROM school_holidays h
         LEFT JOIN classes c ON c.id = h.class_id
         LEFT JOIN stages st ON st.id = c.stage_id
         ORDER BY h.starts_on DESC, h.id DESC
         LIMIT :limit'
    );
    $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function school_schedule_create_entry(array $input, int $actorId): int
{
    $entryType = $input['entry_type'] ?? '';
    $recurrence = $input['recurrence_type'] ?? '';
    if (!in_array($entryType, ['LESSON', 'BREAK'], true)) {
        throw new InvalidArgumentException('اختر نوعًا صحيحًا للموعد.');
    }
    if (!in_array($recurrence, ['WEEKLY', 'ONCE'], true)) {
        throw new InvalidArgumentException('اختر تكرارًا صحيحًا للموعد.');
    }
    $startValue = $input['starts_at'] ?? '';
    $endValue = $input['ends_at'] ?? '';
    if (!is_string($startValue) || !is_string($endValue)) {
        throw new InvalidArgumentException('أدخل وقت بداية ونهاية صالحين.');
    }
    $startsAt = school_schedule_validate_time($startValue, 'بداية الموعد');
    $endsAt = school_schedule_validate_time($endValue, 'نهاية الموعد');
    if ($endsAt <= $startsAt) {
        throw new InvalidArgumentException('يجب أن يكون وقت النهاية بعد وقت البداية.');
    }

    $classId = null;
    $classSubjectId = null;
    $teacherId = null;
    $titleValue = $input['title'] ?? '';
    if (!is_string($titleValue)) {
        throw new InvalidArgumentException('أدخل اسمًا نصيًا للموعد.');
    }
    $title = trim($titleValue);
    if ($entryType === 'LESSON') {
        $classId = filter_var($input['class_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $classSubjectId = filter_var($input['class_subject_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $teacherId = filter_var($input['teacher_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($classId === false || $classSubjectId === false || $teacherId === false) {
            throw new InvalidArgumentException('اختر الصف والمادة والأستاذ.');
        }
        $title = '';
    } else {
        $classIdValue = $input['class_id'] ?? '';
        if ($classIdValue !== '') {
            $classId = filter_var($classIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($classId === false) {
                throw new InvalidArgumentException('اختر صفًا صحيحًا للفسحة.');
            }
        }
        $length = preg_match_all('/./us', $title);
        if ($title === '' || $length === false || $length > 180) {
            throw new InvalidArgumentException('أدخل اسم الفسحة (بحد أقصى 180 حرفًا).');
        }
    }

    $dayOfWeek = null;
    $date = null;
    if ($recurrence === 'WEEKLY') {
        $dayOfWeek = filter_var($input['day_of_week'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        if ($dayOfWeek === false) {
            throw new InvalidArgumentException('اختر يومًا صحيحًا من أيام الأسبوع.');
        }
    } else {
        $dateValue = $input['occurrence_date'] ?? '';
        if (!is_string($dateValue)) {
            throw new InvalidArgumentException('أدخل تاريخًا صحيحًا للحصة.');
        }
        $date = school_schedule_validate_date($dateValue, 'التاريخ');
    }

    $db = database_connection();
    $db->beginTransaction();
    try {
        $db->query('SELECT pg_advisory_xact_lock(735891, 16)')->fetchColumn();
        if ($entryType === 'LESSON') {
            $assignment = $db->prepare(
                "SELECT s.name
                 FROM class_subjects cs
                 JOIN subjects s ON s.id = cs.subject_id AND s.is_active = TRUE
                 JOIN classes c ON c.id = cs.class_id AND c.is_active = TRUE
                 JOIN teaching_assignments ta ON ta.class_subject_id = cs.id AND ta.employee_id = :teacher_id
                 JOIN employees e ON e.id = ta.employee_id AND e.is_active = TRUE
                 JOIN users u ON u.id = e.user_id AND u.is_active = TRUE
                 JOIN user_roles ur ON ur.user_id = u.id
                 JOIN roles r ON r.id = ur.role_id AND r.code = 'teacher'
                 WHERE cs.id = :class_subject_id AND cs.class_id = :class_id AND cs.is_active = TRUE"
            );
            $assignment->execute([
                'teacher_id' => $teacherId,
                'class_subject_id' => $classSubjectId,
                'class_id' => $classId,
            ]);
            $subjectName = $assignment->fetchColumn();
            if ($subjectName === false) {
                throw new InvalidArgumentException('الأستاذ المحدد غير مسند إلى هذه المادة والصف.');
            }
            $title = $subjectName;
        } elseif ($classId !== null) {
            $class = $db->prepare('SELECT 1 FROM classes WHERE id = :id AND is_active = TRUE');
            $class->execute(['id' => $classId]);
            if (!$class->fetchColumn()) {
                throw new InvalidArgumentException('الصف المحدد غير متاح.');
            }
        }

        $candidate = [
            'entry_type' => $entryType,
            'recurrence_type' => $recurrence,
            'class_id' => $classId,
            'teacher_id' => $teacherId,
            'day_of_week' => $dayOfWeek,
            'occurrence_date' => $date,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
        $existingRows = $db->query(
            'SELECT entry_type, recurrence_type, class_id, teacher_id, day_of_week,
                    occurrence_date::text AS occurrence_date, starts_at::text AS starts_at,
                    ends_at::text AS ends_at
             FROM school_schedule_entries
             WHERE is_active = TRUE'
        )->fetchAll();
        foreach ($existingRows as $existing) {
            if (school_schedule_entries_conflict($candidate, $existing)) {
                throw new InvalidArgumentException('يتعارض هذا الموعد مع موعد آخر للصف أو الأستاذ في الوقت نفسه.');
            }
        }

        $insert = $db->prepare(
            'INSERT INTO school_schedule_entries
                (entry_type, recurrence_type, class_id, class_subject_id, teacher_id,
                 title, day_of_week, occurrence_date, starts_at, ends_at, created_by)
             VALUES
                (:entry_type, :recurrence_type, :class_id, :class_subject_id, :teacher_id,
                 :title, :day_of_week, :occurrence_date, :starts_at, :ends_at, :created_by)
             RETURNING id'
        );
        $insert->execute([
            'entry_type' => $entryType,
            'recurrence_type' => $recurrence,
            'class_id' => $classId,
            'class_subject_id' => $classSubjectId,
            'teacher_id' => $teacherId,
            'title' => $title,
            'day_of_week' => $dayOfWeek,
            'occurrence_date' => $date,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_by' => $actorId,
        ]);
        $id = (int) $insert->fetchColumn();
        audit_log('school.schedule.created', 'school_schedule_entry', $id, [
            'entry_type' => $entryType,
            'recurrence_type' => $recurrence,
        ], $actorId);
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function school_schedule_create_holiday(array $input, int $actorId): int
{
    $titleValue = $input['title'] ?? '';
    $descriptionValue = $input['description'] ?? '';
    $startValue = $input['starts_on'] ?? '';
    $endValue = $input['ends_on'] ?? '';
    if (!is_string($titleValue) || !is_string($descriptionValue)
        || !is_string($startValue) || !is_string($endValue)) {
        throw new InvalidArgumentException('أدخل بيانات نصية وتواريخ صحيحة للإجازة.');
    }
    $title = trim($titleValue);
    $description = trim($descriptionValue);
    $titleLength = preg_match_all('/./us', $title);
    $descriptionLength = preg_match_all('/./us', $description);
    if ($title === '' || $titleLength === false || $titleLength > 180) {
        throw new InvalidArgumentException('أدخل اسم الإجازة (بحد أقصى 180 حرفًا).');
    }
    if ($descriptionLength === false || $descriptionLength > 2000) {
        throw new InvalidArgumentException('تفاصيل الإجازة تتجاوز الحد المسموح.');
    }
    $startsOn = school_schedule_validate_date($startValue, 'بداية الإجازة');
    $endsOn = school_schedule_validate_date($endValue, 'نهاية الإجازة');
    if ($endsOn < $startsOn) {
        throw new InvalidArgumentException('يجب أن يكون تاريخ نهاية الإجازة في يوم البداية أو بعده.');
    }

    $classValue = $input['class_id'] ?? '';
    $classId = null;
    if ($classValue !== '') {
        $classId = filter_var($classValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($classId === false) {
            throw new InvalidArgumentException('اختر صفًا صحيحًا للإجازة.');
        }
    }

    $db = database_connection();
    if ($classId !== null) {
        $class = $db->prepare('SELECT 1 FROM classes WHERE id = :id AND is_active = TRUE');
        $class->execute(['id' => $classId]);
        if (!$class->fetchColumn()) {
            throw new InvalidArgumentException('الصف المحدد غير متاح.');
        }
    }
    $db->beginTransaction();
    try {
        $insert = $db->prepare(
            'INSERT INTO school_holidays (class_id, title, description, starts_on, ends_on, created_by)
             VALUES (:class_id, :title, :description, :starts_on, :ends_on, :created_by)
             RETURNING id'
        );
        $insert->execute([
            'class_id' => $classId,
            'title' => $title,
            'description' => $description,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'created_by' => $actorId,
        ]);
        $id = (int) $insert->fetchColumn();
        audit_log('school.holiday.created', 'school_holiday', $id, [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'class_id' => $classId,
        ], $actorId);
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function school_schedule_delete_entry(int $id, int $actorId): void
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'DELETE FROM school_schedule_entries WHERE id = :id RETURNING entry_type'
        );
        $statement->execute(['id' => $id]);
        $entryType = $statement->fetchColumn();
        if ($entryType === false) {
            throw new InvalidArgumentException('هذا الموعد غير موجود أو حُذف مسبقًا.');
        }
        audit_log('school.schedule.deleted', 'school_schedule_entry', $id, ['entry_type' => $entryType], $actorId);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function school_schedule_delete_holiday(int $id, int $actorId): void
{
    $db = database_connection();
    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'DELETE FROM school_holidays WHERE id = :id RETURNING title'
        );
        $statement->execute(['id' => $id]);
        $title = $statement->fetchColumn();
        if ($title === false) {
            throw new InvalidArgumentException('هذه الإجازة غير موجودة أو حُذفت مسبقًا.');
        }
        audit_log('school.holiday.deleted', 'school_holiday', $id, ['title' => $title], $actorId);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
