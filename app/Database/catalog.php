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

function catalog_actor_scope(array $actor, string $classColumn = 'c.id', string $classSubjectColumn = 'cs.id'): array
{
    $roles = $actor['roles'] ?? [];
    if (in_array('admin', $roles, true) || in_array('content_reviewer', $roles, true)) return ['', []];
    if (in_array('teacher', $roles, true)) {
        return [
            ' AND EXISTS (
                SELECT 1 FROM employees scope_employee
                JOIN teaching_assignments scope_assignment ON scope_assignment.employee_id = scope_employee.id
                WHERE scope_employee.user_id = :scope_teacher_id AND scope_employee.is_active
                    AND scope_assignment.class_subject_id = ' . $classSubjectColumn . '
            )',
            ['scope_teacher_id' => (int) $actor['id']],
        ];
    }
    if (in_array('student', $roles, true)) {
        $classId = student_class_id((int) $actor['id']);
        return $classId === null
            ? [' AND FALSE', []]
            : [' AND ' . $classColumn . ' = :scope_student_class_id', ['scope_student_class_id' => $classId]];
    }
    return [' AND FALSE', []];
}

function catalog_actor_can_access_class_subject(int $classSubjectId, array $actor): bool
{
    [$scope, $params] = catalog_actor_scope($actor);
    $statement = database_connection()->prepare(
        'SELECT EXISTS (
            SELECT 1 FROM class_subjects cs
            JOIN classes c ON c.id = cs.class_id AND c.is_active
            WHERE cs.id = :class_subject_id AND cs.is_active' . $scope . '
        )'
    );
    $statement->execute(['class_subject_id' => $classSubjectId] + $params);
    return database_bool($statement->fetchColumn());
}

function catalog_query_pattern(string $query): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
}

function catalog_search_teachers(string $query, array $actor, int $limit = 12): array
{
    $query = trim($query);
    $limit = max(1, min($limit, 30));
    [$scope, $params] = catalog_actor_scope($actor);
    $where = "e.is_active AND u.is_active
        AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = u.id AND r.code = 'teacher')" . $scope;
    if ($query !== '') {
        $where .= " AND (u.full_name ILIKE :name_query ESCAPE '\\'
                    OR e.job_title ILIKE :title_query ESCAPE '\\'
                    OR EXISTS (
                        SELECT 1 FROM teaching_assignments qta
                        JOIN class_subjects qcs ON qcs.id = qta.class_subject_id
                        JOIN subjects qs ON qs.id = qcs.subject_id
                        WHERE qta.employee_id = e.id AND qs.name ILIKE :subject_query ESCAPE '\\'
                    ))";
        $pattern = catalog_query_pattern($query);
        $params += ['name_query' => $pattern, 'title_query' => $pattern, 'subject_query' => $pattern];
    }
    $sql = 'SELECT e.id AS employee_id, u.full_name, e.job_title, e.profile_image_key,
                   string_agg(DISTINCT st.name || \' · \' || c.name || \' · \' || s.name, \'، \' ORDER BY st.name || \' · \' || c.name || \' · \' || s.name) AS subjects
            FROM employees e
            JOIN users u ON u.id = e.user_id
            LEFT JOIN teaching_assignments ta ON ta.employee_id = e.id
            LEFT JOIN class_subjects cs ON cs.id = ta.class_subject_id AND cs.is_active
            LEFT JOIN classes c ON c.id = cs.class_id AND c.is_active
            LEFT JOIN stages st ON st.id = c.stage_id AND st.is_active
            LEFT JOIN subjects s ON s.id = cs.subject_id AND s.is_active
            WHERE ' . $where . '
            GROUP BY e.id, u.full_name, e.job_title, e.profile_image_key
            ORDER BY u.full_name, e.id
            LIMIT :result_limit';
    $statement = database_connection()->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':result_limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function catalog_find_teacher_for_actor(int $employeeId, array $actor): ?array
{
    [$scope, $params] = catalog_actor_scope($actor);
    $statement = database_connection()->prepare(
        "SELECT e.id AS employee_id, u.full_name, e.job_title, e.profile_image_key,
                string_agg(DISTINCT st.name || ' · ' || c.name || ' · ' || s.name,
                    '، ' ORDER BY st.name || ' · ' || c.name || ' · ' || s.name) AS subjects
         FROM employees e
         JOIN users u ON u.id = e.user_id
         LEFT JOIN teaching_assignments ta ON ta.employee_id = e.id
         LEFT JOIN class_subjects cs ON cs.id = ta.class_subject_id AND cs.is_active
         LEFT JOIN classes c ON c.id = cs.class_id AND c.is_active
         LEFT JOIN stages st ON st.id = c.stage_id AND st.is_active
         LEFT JOIN subjects s ON s.id = cs.subject_id AND s.is_active
         WHERE e.id = :employee_id AND e.is_active AND u.is_active
           AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                       WHERE ur.user_id = u.id AND r.code = 'teacher')" . $scope . '
         GROUP BY e.id, u.full_name, e.job_title, e.profile_image_key'
    );
    $statement->execute(['employee_id' => $employeeId] + $params);
    $teacher = $statement->fetch();
    return $teacher ?: null;
}

function catalog_teacher_lessons(int $employeeId, array $actor, int $limit = 20): array
{
    [$scope, $params] = catalog_actor_scope($actor);
    $statement = database_connection()->prepare(
        'SELECT l.id, l.title, l.position, cs.id AS class_subject_id, c.name AS class_name, s.name AS subject_name
         FROM lessons l
         JOIN class_subjects cs ON cs.id = l.class_subject_id
         JOIN classes c ON c.id = cs.class_id
         JOIN subjects s ON s.id = cs.subject_id
         WHERE l.is_published AND cs.is_active AND c.is_active AND s.is_active
           AND EXISTS (
               SELECT 1 FROM teaching_assignments ta
               WHERE ta.employee_id = :employee_id AND ta.class_subject_id = cs.id
           )' . $scope . '
         ORDER BY c.name, s.name, l.position, l.id
         LIMIT :result_limit'
    );
    foreach ($params as $name => $value) {
        $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $statement->bindValue(':employee_id', $employeeId, PDO::PARAM_INT);
    $statement->bindValue(':result_limit', max(1, min($limit, 50)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function catalog_search_lessons(string $query, array $actor, int $limit = 20): array
{
    $query = trim($query);
    $limit = max(1, min($limit, 50));
    [$scope, $params] = catalog_actor_scope($actor);
    $where = 'l.is_published AND cs.is_active AND c.is_active AND st.is_active AND s.is_active' . $scope;
    if ($query !== '') {
        $where .= " AND (l.title ILIKE :lesson_title ESCAPE '\\'
                    OR COALESCE(l.description, '') ILIKE :lesson_description ESCAPE '\\'
                    OR s.name ILIKE :lesson_subject ESCAPE '\\'
                    OR c.name ILIKE :lesson_class ESCAPE '\\')";
        $pattern = catalog_query_pattern($query);
        $params += [
            'lesson_title' => $pattern,
            'lesson_description' => $pattern,
            'lesson_subject' => $pattern,
            'lesson_class' => $pattern,
        ];
    }
    $sql = 'SELECT l.id, l.title, l.description, l.position, l.updated_at,
                   cs.id AS class_subject_id, c.id AS class_id, c.name AS class_name,
                   st.name AS stage_name, s.name AS subject_name,
                   COUNT(DISTINCT lr.id) FILTER (WHERE lr.status = \'APPROVED\' AND lr.resource_type IN (\'VIDEO\', \'AUDIO\')) AS media_count
            FROM lessons l
            JOIN class_subjects cs ON cs.id = l.class_subject_id
            JOIN classes c ON c.id = cs.class_id
            JOIN stages st ON st.id = c.stage_id
            JOIN subjects s ON s.id = cs.subject_id
            LEFT JOIN learning_resources lr ON lr.lesson_id = l.id
            WHERE ' . $where . '
            GROUP BY l.id, cs.id, c.id, c.name, st.name, s.name
            ORDER BY l.updated_at DESC, l.position, l.id
            LIMIT :result_limit';
    $statement = database_connection()->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':result_limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function catalog_list_upload_lessons(int $userId): array
{
    $sql = 'SELECT l.id, l.title, l.class_subject_id, cs.class_id, c.name AS class_name, s.name AS subject_name
            FROM lessons l
            JOIN class_subjects cs ON cs.id = l.class_subject_id AND cs.is_active
            JOIN classes c ON c.id = cs.class_id AND c.is_active
            JOIN subjects s ON s.id = cs.subject_id AND s.is_active
            WHERE l.is_published';
    $params = [];
    if (!user_has_permission('roles.manage')) {
        $sql .= ' AND EXISTS (
            SELECT 1 FROM employees e JOIN teaching_assignments ta ON ta.employee_id = e.id
            WHERE e.user_id = :user_id AND e.is_active AND ta.class_subject_id = cs.id
        )';
        $params['user_id'] = $userId;
    }
    $statement = database_connection()->prepare($sql . ' ORDER BY c.name, s.name, l.position, l.id');
    $statement->execute($params);
    return $statement->fetchAll();
}

function catalog_find_lesson_for_actor(int $lessonId, array $actor): ?array
{
    [$scope, $params] = catalog_actor_scope($actor);
    $statement = database_connection()->prepare(
        'SELECT l.id, l.title, l.description, l.body, l.position, cs.id AS class_subject_id,
                c.id AS class_id, c.name AS class_name, st.name AS stage_name, s.name AS subject_name
         FROM lessons l
         JOIN class_subjects cs ON cs.id = l.class_subject_id
         JOIN classes c ON c.id = cs.class_id
         JOIN stages st ON st.id = c.stage_id
         JOIN subjects s ON s.id = cs.subject_id
         WHERE l.id = :lesson_id AND l.is_published AND cs.is_active AND c.is_active AND st.is_active AND s.is_active' . $scope
    );
    $statement->execute(['lesson_id' => $lessonId] + $params);
    $lesson = $statement->fetch();
    if (!$lesson) return null;

    $teachers = database_connection()->prepare(
        'SELECT e.id AS employee_id, u.full_name, e.job_title, e.profile_image_key
         FROM teaching_assignments ta
         JOIN employees e ON e.id = ta.employee_id AND e.is_active
         JOIN users u ON u.id = e.user_id AND u.is_active
         WHERE ta.class_subject_id = :class_subject_id
         ORDER BY u.full_name, e.id'
    );
    $teachers->execute(['class_subject_id' => $lesson['class_subject_id']]);
    $lesson['teachers'] = $teachers->fetchAll();

    $resources = database_connection()->prepare(
        "SELECT lr.id, lr.title, lr.resource_type, lr.description, lr.mime_type, lr.original_filename,
                lr.video_provider, lr.video_asset_id, lr.video_playback_id, lr.video_duration_seconds,
                lr.view_count, lr.external_url, lr.published_at
         FROM learning_resources lr
         WHERE lr.lesson_id = :lesson_id AND lr.status = 'APPROVED' AND lr.published_at IS NOT NULL
           AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = lr.id AND cr.status = 'APPROVED')
         ORDER BY CASE lr.resource_type WHEN 'VIDEO' THEN 0 WHEN 'AUDIO' THEN 1 ELSE 2 END, lr.published_at DESC, lr.id DESC"
    );
    $resources->execute(['lesson_id' => $lessonId]);
    $lesson['resources'] = $resources->fetchAll();

    $recommendations = database_connection()->prepare(
        'SELECT id, title, youtube_url, created_at
         FROM lesson_youtube_recommendations
         WHERE lesson_id = :lesson_id
         ORDER BY created_at DESC, id DESC'
    );
    $recommendations->execute(['lesson_id' => $lessonId]);
    $lesson['youtube_recommendations'] = $recommendations->fetchAll();
    return $lesson;
}

function catalog_visible_media_for_actor(int $resourceId, array $actor): ?array
{
    [$scope, $params] = catalog_actor_scope($actor);
    $statement = database_connection()->prepare(
        "SELECT lr.id, lr.lesson_id, lr.class_subject_id, lr.resource_type, lr.storage_key, lr.original_filename, lr.mime_type,
                lr.video_provider, lr.video_playback_id, lr.video_duration_seconds
         FROM learning_resources lr
         JOIN class_subjects cs ON cs.id = lr.class_subject_id
         JOIN classes c ON c.id = cs.class_id
         WHERE lr.id = :resource_id AND lr.resource_type IN ('VIDEO', 'AUDIO')
           AND lr.lesson_id IS NOT NULL AND cs.is_active AND c.is_active
           AND lr.status = 'APPROVED' AND lr.published_at IS NOT NULL
           AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = lr.id AND cr.status = 'APPROVED')"
            . $scope
    );
    $statement->execute(['resource_id' => $resourceId] + $params);
    $resource = $statement->fetch();
    return $resource ?: null;
}

function catalog_popular_media(array $actor, int $limit = 5): array
{
    [$scope, $params] = catalog_actor_scope($actor);
    $statement = database_connection()->prepare(
        "SELECT lr.id AS resource_id, lr.lesson_id, lr.title AS resource_title, lr.resource_type, lr.view_count,
                l.title AS lesson_title, c.name AS class_name, s.name AS subject_name
         FROM learning_resources lr
         JOIN class_subjects cs ON cs.id = lr.class_subject_id
         JOIN classes c ON c.id = cs.class_id
         JOIN subjects s ON s.id = cs.subject_id
         LEFT JOIN lessons l ON l.id = lr.lesson_id AND l.is_published
         WHERE lr.resource_type IN ('VIDEO', 'AUDIO') AND lr.status = 'APPROVED' AND lr.published_at IS NOT NULL
           AND lr.lesson_id IS NOT NULL
           AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = lr.id AND cr.status = 'APPROVED')"
            . $scope . '
         ORDER BY lr.view_count DESC, lr.published_at DESC, lr.id DESC
         LIMIT :result_limit'
    );
    foreach ($params as $name => $value) {
        $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $statement->bindValue(':result_limit', max(1, min($limit, 20)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function catalog_latest_media(array $actor, int $limit = 6): array
{
    [$scope, $params] = catalog_actor_scope($actor);
    $statement = database_connection()->prepare(
        "SELECT lr.id AS resource_id, lr.lesson_id, lr.title AS resource_title, lr.resource_type, lr.published_at,
                l.title AS lesson_title, c.name AS class_name, s.name AS subject_name
         FROM learning_resources lr
         JOIN class_subjects cs ON cs.id = lr.class_subject_id
         JOIN classes c ON c.id = cs.class_id
         JOIN subjects s ON s.id = cs.subject_id
         LEFT JOIN lessons l ON l.id = lr.lesson_id AND l.is_published
         WHERE lr.resource_type IN ('VIDEO', 'AUDIO') AND lr.status = 'APPROVED' AND lr.published_at IS NOT NULL
           AND lr.lesson_id IS NOT NULL
           AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = lr.id AND cr.status = 'APPROVED')"
            . $scope . '
         ORDER BY lr.published_at DESC, lr.id DESC
         LIMIT :result_limit'
    );
    foreach ($params as $name => $value) {
        $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $statement->bindValue(':result_limit', max(1, min($limit, 20)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function catalog_record_media_view(int $resourceId, array $actor): bool
{
    $resource = catalog_visible_media_for_actor($resourceId, $actor);
    if ($resource === null) return false;
    $statement = database_connection()->prepare(
        "UPDATE learning_resources SET view_count = view_count + 1
         WHERE id = :id AND resource_type IN ('VIDEO', 'AUDIO') AND status = 'APPROVED' AND published_at IS NOT NULL
           AND EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.resource_id = learning_resources.id AND cr.status = 'APPROVED')"
    );
    $statement->execute(['id' => $resourceId]);
    return $statement->rowCount() === 1;
}

function catalog_youtube_video_id(string $url): ?string
{
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $videoId = null;
    if ($host === 'youtu.be') {
        $videoId = trim($path, '/');
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
        if ($path === '/watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = is_string($query['v'] ?? null) ? $query['v'] : null;
        } elseif (preg_match('#^/(?:embed|shorts|live)/([A-Za-z0-9_-]{6,20})/?$#D', $path, $match)) {
            $videoId = $match[1];
        }
    }
    return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{6,20}$/D', $videoId) ? $videoId : null;
}

function catalog_create_youtube_recommendation(array $actor, int $lessonId, string $title, string $url): void
{
    $title = trim($title);
    $url = trim($url);
    if ($title === '' || mb_strlen($title) > 200) {
        throw new InvalidArgumentException('أدخل عنوان فيديو يوتيوب لا يتجاوز 200 محرف.');
    }
    $videoId = catalog_youtube_video_id($url);
    if ($videoId === null) {
        throw new InvalidArgumentException('أدخل رابط فيديو صالحًا من YouTube.');
    }
    $lesson = database_connection()->prepare(
        'SELECT class_subject_id FROM lessons WHERE id = :lesson_id AND is_published'
    );
    $lesson->execute(['lesson_id' => $lessonId]);
    $classSubjectId = $lesson->fetchColumn();
    if ($classSubjectId === false) throw new InvalidArgumentException('الدرس غير موجود أو غير منشور.');
    $employeeId = content_employee_id(database_connection(), (int) $actor['id']);
    content_assert_upload_scope(database_connection(), $actor, $employeeId, (int) $classSubjectId);
    $statement = database_connection()->prepare(
        'INSERT INTO lesson_youtube_recommendations (lesson_id, title, youtube_url, created_by)
         VALUES (:lesson_id, :title, :url, :created_by)'
    );
    $statement->execute([
        'lesson_id' => $lessonId,
        'title' => $title,
        'url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
        'created_by' => $employeeId,
    ]);
    audit_log('lesson.youtube_recommendation.created', 'lesson', $lessonId, [], (int) $actor['id']);
}
