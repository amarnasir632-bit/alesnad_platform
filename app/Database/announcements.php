<?php
declare(strict_types=1);

/**
 * دالة جلب الإعلانات المنشورة للمستخدم بحسب صفه ومواده
 */
function list_published_announcements(?int $studentClassId = null, ?int $limit = 10): array
{
    $pdo = database_connection();
    $sql = "
        SELECT a.id, a.author_id, a.title, a.content, a.badge_label, a.priority,
               a.cover_style, a.cover_image_url, a.class_subject_id, a.class_id,
               a.created_at, u.full_name AS author_name,
               cs.id AS subject_id, s.name AS subject_name, c.name AS class_name
        FROM announcements a
        JOIN users u ON u.id = a.author_id
        LEFT JOIN class_subjects cs ON cs.id = a.class_subject_id
        LEFT JOIN subjects s ON s.id = cs.subject_id
        LEFT JOIN classes c ON c.id = cs.class_id OR c.id = a.class_id
        WHERE a.is_published = TRUE
          AND (
            (a.class_subject_id IS NULL AND a.class_id IS NULL)
            " . ($studentClassId !== null ? " OR c.id = :student_class_id OR cs.class_id = :student_class_id" : "") . "
          )
        ORDER BY CASE WHEN a.priority = 'URGENT' THEN 1 WHEN a.priority = 'IMPORTANT' THEN 2 ELSE 3 END,
                 a.created_at DESC
        LIMIT :limit
    ";
    $stmt = $pdo->prepare($sql);
    if ($studentClassId !== null) {
        $stmt->bindValue(':student_class_id', $studentClassId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit ?: 10, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * دالة جلب كافة الإعلانات لمدير النظام
 */
function list_admin_announcements(): array
{
    $pdo = database_connection();
    $stmt = $pdo->query("
        SELECT a.id, a.author_id, a.title, a.content, a.badge_label, a.priority,
               a.cover_style, a.cover_image_url, a.class_subject_id, a.class_id,
               a.is_published, a.created_at, u.full_name AS author_name,
               r.code AS author_role, s.name AS subject_name, c.name AS class_name,
               st.name AS stage_name
        FROM announcements a
        JOIN users u ON u.id = a.author_id
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        LEFT JOIN class_subjects cs ON cs.id = a.class_subject_id
        LEFT JOIN subjects s ON s.id = cs.subject_id
        LEFT JOIN classes c ON c.id = cs.class_id OR c.id = a.class_id
        LEFT JOIN stages st ON st.id = c.stage_id
        ORDER BY a.created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * دالة جلب الإعلانات الخاصة بالأستاذ والمواد المسندة له
 */
function list_teacher_announcements(int $teacherUserId): array
{
    $pdo = database_connection();
    $stmt = $pdo->prepare("
        SELECT a.id, a.author_id, a.title, a.content, a.badge_label, a.priority,
               a.cover_style, a.cover_image_url, a.class_subject_id, a.class_id,
               a.is_published, a.created_at, u.full_name AS author_name,
               s.name AS subject_name, c.name AS class_name, st.name AS stage_name
        FROM announcements a
        JOIN users u ON u.id = a.author_id
        LEFT JOIN class_subjects cs ON cs.id = a.class_subject_id
        LEFT JOIN subjects s ON s.id = cs.subject_id
        LEFT JOIN classes c ON c.id = cs.class_id OR c.id = a.class_id
        LEFT JOIN stages st ON st.id = c.stage_id
        WHERE a.author_id = :teacher_id
           OR (a.class_subject_id IS NULL AND a.class_id IS NULL)
        ORDER BY a.created_at DESC
    ");
    $stmt->execute(['teacher_id' => $teacherUserId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * دالة إنشاء إعلان جديد مع فحص الصلاحيات
 */
function create_announcement_record(
    int $authorId,
    string $title,
    string $content,
    string $badgeLabel,
    string $priority,
    string $coverStyle,
    ?int $classSubjectId,
    bool $isAdmin
): int {
    $title = trim($title);
    $content = trim($content);
    $badgeLabel = trim($badgeLabel);
    if ($title === '' || mb_strlen($title) > 255) {
        throw new InvalidArgumentException('أدخل عنواناً صالحاً للإعلان (بحد أقصى 255 حرفاً).');
    }
    if ($content === '') {
        throw new InvalidArgumentException('أدخل تفاصيل ومحتوى الإعلان.');
    }
    if (!in_array($priority, ['NORMAL', 'IMPORTANT', 'URGENT'], true)) {
        $priority = 'NORMAL';
    }
    if (!in_array($coverStyle, ['gradient_blue', 'gradient_emerald', 'gradient_amber', 'gradient_purple', 'gradient_rose'], true)) {
        $coverStyle = 'gradient_blue';
    }
    if ($badgeLabel === '') {
        $badgeLabel = 'تنبيه أكاديمي';
    }

    $pdo = database_connection();

    // التحقق الصارم للأستاذ: يجب أن تكون المادة مسندة له حصراً
    if (!$isAdmin) {
        if ($classSubjectId === null || $classSubjectId < 1) {
            throw new InvalidArgumentException('يجب على الأستاذ اختيار مادة من المواد المسندة إليه حصراً لنشر الإعلان.');
        }
        $checkStmt = $pdo->prepare("
            SELECT 1
            FROM employees e
            JOIN teaching_assignments ta ON ta.employee_id = e.id
            WHERE e.user_id = :uid AND e.is_active = TRUE AND ta.class_subject_id = :csid
        ");
        $checkStmt->execute(['uid' => $authorId, 'csid' => $classSubjectId]);
        if (!$checkStmt->fetchColumn()) {
            throw new InvalidArgumentException('ليس لديك صلاحية لنشر إعلان لهذه المادة؛ المادة غير مسندة إليك.');
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO announcements (author_id, title, content, badge_label, priority, cover_style, class_subject_id, is_published)
        VALUES (:author_id, :title, :content, :badge_label, :priority, :cover_style, :class_subject_id, TRUE)
        RETURNING id
    ");
    $stmt->execute([
        'author_id' => $authorId,
        'title' => $title,
        'content' => $content,
        'badge_label' => $badgeLabel,
        'priority' => $priority,
        'cover_style' => $coverStyle,
        'class_subject_id' => $classSubjectId,
    ]);
    return (int) $stmt->fetchColumn();
}

/**
 * دالة تبديل حالة تفعيل الإعلان
 */
function toggle_announcement_status(int $announcementId, int $userId, bool $isAdmin): void
{
    $pdo = database_connection();
    $sql = "UPDATE announcements SET is_published = NOT is_published, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
    if (!$isAdmin) {
        $sql .= " AND author_id = :user_id";
    }
    $params = ['id' => $announcementId];
    if (!$isAdmin) {
        $params['user_id'] = $userId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * دالة حذف إعلان
 */
function delete_announcement_record(int $announcementId, int $userId, bool $isAdmin): void
{
    $pdo = database_connection();
    $sql = "DELETE FROM announcements WHERE id = :id";
    if (!$isAdmin) {
        $sql .= " AND author_id = :user_id";
    }
    $params = ['id' => $announcementId];
    if (!$isAdmin) {
        $params['user_id'] = $userId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
