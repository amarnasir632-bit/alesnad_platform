<?php
declare(strict_types=1);

function list_class_subjects(int $limit = 12, ?int $classId = null, ?int $teacherUserId = null): array
{
    $limit = max(1, min($limit, 50));
    $scope = '';
    $params = [];
    if ($classId !== null) {
        $scope = ' AND cs.class_id = :class_id';
        $params['class_id'] = $classId;
    } elseif ($teacherUserId !== null) {
        $scope = ' AND EXISTS (
                SELECT 1 FROM employees e
                JOIN teaching_assignments ta ON ta.employee_id = e.id
                WHERE e.user_id = :teacher_user_id AND e.is_active
                    AND ta.class_subject_id = cs.id
            )';
        $params['teacher_user_id'] = $teacherUserId;
    }
    $sql = 'SELECT cs.id AS class_subject_id, s.name AS subject_name,
                   c.name AS class_name, st.name AS stage_name,
                   COUNT(DISTINCT l.id) AS lesson_count
            FROM class_subjects cs
            JOIN subjects s ON s.id = cs.subject_id AND s.is_active
            JOIN classes c ON c.id = cs.class_id AND c.is_active
            JOIN stages st ON st.id = c.stage_id AND st.is_active
            LEFT JOIN lessons l ON l.class_subject_id = cs.id AND l.is_published
            WHERE cs.is_active' . $scope . '
            GROUP BY cs.id, s.name, c.name, st.name, st.sort_order, c.sort_order
            ORDER BY st.sort_order, c.sort_order, s.name
            LIMIT :limit';
    $statement = database_connection()->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, PDO::PARAM_INT);
    }
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function student_class_id(int $userId): ?int
{
    $statement = database_connection()->prepare(
        'SELECT class_id FROM students WHERE user_id = :user_id'
    );
    $statement->execute(['user_id' => $userId]);
    $classId = $statement->fetchColumn();
    return $classId === false || $classId === null ? null : (int) $classId;
}

function list_student_class_subjects(int $userId, int $limit = 50): array
{
    $classId = student_class_id($userId);
    return $classId === null ? [] : list_class_subjects($limit, $classId);
}

function list_teacher_class_subjects(int $userId, int $limit = 50): array
{
    return list_class_subjects($limit, null, $userId);
}

function teacher_has_class_subject(int $userId, int $classSubjectId): bool
{
    $statement = database_connection()->prepare(
        'SELECT EXISTS (
            SELECT 1 FROM employees e
            JOIN teaching_assignments ta ON ta.employee_id = e.id
            WHERE e.user_id = :user_id AND e.is_active AND ta.class_subject_id = :class_subject_id
        )'
    );
    $statement->execute(['user_id' => $userId, 'class_subject_id' => $classSubjectId]);
    return database_bool($statement->fetchColumn());
}

function find_class_subject(int $id): ?array
{
    $statement = database_connection()->prepare(
        'SELECT cs.id AS class_subject_id, cs.class_id, s.name AS subject_name, s.description,
                c.name AS class_name, st.name AS stage_name
         FROM class_subjects cs
         JOIN subjects s ON s.id = cs.subject_id AND s.is_active
         JOIN classes c ON c.id = cs.class_id AND c.is_active
         JOIN stages st ON st.id = c.stage_id AND st.is_active
         WHERE cs.id = :id AND cs.is_active'
    );
    $statement->execute(['id' => $id]);
    $subject = $statement->fetch();
    return $subject ?: null;
}

function list_published_lessons(int $classSubjectId): array
{
    $statement = database_connection()->prepare(
        'SELECT id, title, description, body, position
         FROM lessons
         WHERE class_subject_id = :class_subject_id AND is_published
         ORDER BY position, id'
    );
    $statement->execute(['class_subject_id' => $classSubjectId]);
    return $statement->fetchAll();
}
