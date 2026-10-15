<?php
declare(strict_types=1);

function message_preview(string $text, int $limit = 65): string
{
    $matched = preg_match_all('/./us', $text, $characters);
    if ($matched === false || $matched <= $limit) {
        return $text;
    }
    return implode('', array_slice($characters[0], 0, $limit)) . '…';
}

function message_pair_is_allowed(int $firstUserId, int $secondUserId): bool
{
    if ($firstUserId < 1 || $secondUserId < 1 || $firstUserId === $secondUserId) {
        return false;
    }
    $statement = database_connection()->prepare(
        "SELECT EXISTS (
            SELECT 1
            FROM students s
            JOIN users student_user ON student_user.id=s.user_id AND student_user.is_active
            JOIN user_roles student_role ON student_role.user_id=s.user_id
            JOIN roles sr ON sr.id=student_role.role_id AND sr.code='student'
            JOIN employees e ON e.user_id=:teacher_id AND e.is_active
            JOIN users teacher_user ON teacher_user.id=e.user_id AND teacher_user.is_active
            JOIN user_roles teacher_role ON teacher_role.user_id=e.user_id
            JOIN roles tr ON tr.id=teacher_role.role_id AND tr.code='teacher'
            JOIN teaching_assignments ta ON ta.employee_id=e.id
            JOIN class_subjects cs ON cs.id=ta.class_subject_id AND cs.is_active
            WHERE s.user_id=:student_id AND s.class_id=cs.class_id
         ) OR EXISTS (
            SELECT 1
            FROM students s
            JOIN users student_user ON student_user.id=s.user_id AND student_user.is_active
            JOIN user_roles student_role ON student_role.user_id=s.user_id
            JOIN roles sr ON sr.id=student_role.role_id AND sr.code='student'
            JOIN employees e ON e.user_id=:teacher_id_reverse AND e.is_active
            JOIN users teacher_user ON teacher_user.id=e.user_id AND teacher_user.is_active
            JOIN user_roles teacher_role ON teacher_role.user_id=e.user_id
            JOIN roles tr ON tr.id=teacher_role.role_id AND tr.code='teacher'
            JOIN teaching_assignments ta ON ta.employee_id=e.id
            JOIN class_subjects cs ON cs.id=ta.class_subject_id AND cs.is_active
            WHERE s.user_id=:student_id_reverse AND s.class_id=cs.class_id
         )"
    );
    $statement->execute([
        'teacher_id' => $firstUserId, 'student_id' => $secondUserId,
        'teacher_id_reverse' => $secondUserId, 'student_id_reverse' => $firstUserId,
    ]);
    return (bool) $statement->fetchColumn();
}

function list_message_contacts(int $userId): array
{
    $statement = database_connection()->prepare(
        "SELECT DISTINCT u.id, u.full_name, CASE WHEN r.code='teacher' THEN 'أستاذ' ELSE 'طالب' END AS role_name
         FROM users u
         JOIN user_roles ur ON ur.user_id=u.id
         JOIN roles r ON r.id=ur.role_id AND r.code IN ('student','teacher')
         WHERE u.id<>:user_id AND u.is_active
           AND (
             (r.code='teacher' AND EXISTS (
                SELECT 1 FROM employees e
                JOIN teaching_assignments ta ON ta.employee_id=e.id
                JOIN class_subjects cs ON cs.id=ta.class_subject_id AND cs.is_active
                JOIN students s ON s.class_id=cs.class_id AND s.user_id=:student_id
                WHERE e.user_id=u.id AND e.is_active
             )) OR
             (r.code='student' AND EXISTS (
                SELECT 1 FROM students s JOIN employees me ON me.user_id=:teacher_id
                JOIN teaching_assignments ta ON ta.employee_id=me.id AND me.is_active
                JOIN class_subjects cs ON cs.id=ta.class_subject_id AND cs.class_id=s.class_id AND cs.is_active
                WHERE s.user_id=u.id
             ))
           )
         ORDER BY role_name, u.full_name"
    );
    $statement->execute(['user_id' => $userId, 'student_id' => $userId, 'teacher_id' => $userId]);
    return $statement->fetchAll();
}

function list_message_conversations(int $userId): array
{
    $statement = database_connection()->prepare(
        "WITH participant_messages AS (
            SELECT CASE WHEN sender_id=:current_user THEN receiver_id ELSE sender_id END AS peer_id,
                   id, sender_id, receiver_id, message_body, sent_at, read_at
            FROM messages
            WHERE (sender_id=:sender_user OR receiver_id=:receiver_user) AND deleted_at IS NULL
         ), latest AS (
            SELECT DISTINCT ON (peer_id) peer_id, message_body, sent_at, sender_id
            FROM participant_messages ORDER BY peer_id, sent_at DESC, id DESC
         ), unread AS (
            SELECT peer_id, COUNT(*) AS unread_count FROM participant_messages
            WHERE receiver_id=:unread_user AND read_at IS NULL GROUP BY peer_id
         )
         SELECT u.id AS peer_id, u.full_name, latest.message_body, latest.sent_at, latest.sender_id,
                COALESCE(unread.unread_count,0) AS unread_count
         FROM latest JOIN users u ON u.id=latest.peer_id
         LEFT JOIN unread ON unread.peer_id=latest.peer_id
         ORDER BY latest.sent_at DESC"
    );
    $statement->execute([
        'current_user' => $userId,
        'sender_user' => $userId,
        'receiver_user' => $userId,
        'unread_user' => $userId,
    ]);
    return $statement->fetchAll();
}

function list_message_thread(int $userId, int $peerId): array
{
    if (!message_pair_is_allowed($userId, $peerId)) {
        throw new InvalidArgumentException('المراسلة متاحة فقط بين الطالب والأستاذ المسند إلى صفه.');
    }
    $statement = database_connection()->prepare(
        'SELECT id, sender_id, receiver_id, message_body, sent_at, read_at
         FROM (SELECT id, sender_id, receiver_id, message_body, sent_at, read_at
               FROM messages WHERE ((sender_id = :u AND receiver_id = :p) OR (sender_id = :p AND receiver_id = :u))
                    AND deleted_at IS NULL ORDER BY sent_at DESC, id DESC LIMIT 100) recent
         ORDER BY sent_at, id'
    );
    $statement->execute(['u' => $userId, 'p' => $peerId]);
    return $statement->fetchAll();
}

function mark_message_thread_read(int $userId, int $peerId): void
{
    if (!message_pair_is_allowed($userId, $peerId)) {
        throw new InvalidArgumentException('المحادثة غير متاحة.');
    }
    database_connection()->prepare(
        'UPDATE messages SET read_at=CURRENT_TIMESTAMP WHERE sender_id=:peer AND receiver_id=:user_id AND read_at IS NULL AND deleted_at IS NULL'
    )->execute(['peer' => $peerId, 'user_id' => $userId]);
}

function send_individual_message(int $senderId, int $receiverId, string $body): void
{
    $body = trim($body);
    $length = preg_match_all('/./us', $body);
    if ($length === false || $length < 1 || $length > 5000) {
        throw new InvalidArgumentException('اكتب رسالة من حرف واحد إلى 5000 حرف.');
    }
    if (!message_pair_is_allowed($senderId, $receiverId)) {
        throw new InvalidArgumentException('المراسلة متاحة فقط بين الطالب والأستاذ المسند إلى صفه.');
    }

    // التحقق الصارم من حالة تجميد المحادثة بقرار الإدارة
    $req = get_message_request($senderId, $receiverId);
    if (!$req) {
        $req = get_message_request($receiverId, $senderId);
    }
    if ($req && !empty($req['is_frozen'])) {
        throw new InvalidArgumentException('تم تجميد هذه المحادثة مؤقتاً بقرار من إدارة المنصة.');
    }

    // التحقق من حالة طلب المراسلة إن كان المرسل طالباً
    if ($req && $req['status'] === 'REJECTED') {
        $reason = $req['rejection_reason'] ?: 'لم يُحدد سبب معين.';
        throw new InvalidArgumentException('اعتذر الأستاذ عن قبول المراسلة حالياً. سبب الاعتذار: ' . $reason);
    }
    if (!$req) {
        // التحقق إن كان المرسل طالباً والمستقبل أستاذاً لإنشاء طلب مراسلة
        $stmt = database_connection()->prepare("SELECT 1 FROM students WHERE user_id = :uid");
        $stmt->execute(['uid' => $senderId]);
        if ($stmt->fetchColumn()) {
            ensure_message_request($senderId, $receiverId);
        }
    }

    // إذا كان المرسل هو الأستاذ، فإن رده يُعد قبولاً ضمنياً للمحادثة
    $reqAsTeacher = get_message_request($receiverId, $senderId);
    if ($reqAsTeacher && in_array($reqAsTeacher['status'], ['PENDING', 'IGNORED'], true)) {
        update_message_request_status($senderId, $receiverId, 'ACCEPTED');
    }

    database_connection()->prepare(
        'INSERT INTO messages (sender_id, receiver_id, message_body) VALUES (:sender_id, :receiver_id, :message_body)'
    )->execute(['sender_id' => $senderId, 'receiver_id' => $receiverId, 'message_body' => $body]);
}

function get_message_request(int $studentId, int $teacherId): ?array
{
    $stmt = database_connection()->prepare(
        'SELECT id, student_id, teacher_id, status, rejection_reason, is_frozen, frozen_at, frozen_by, created_at, updated_at
         FROM message_requests
         WHERE student_id = :student_id AND teacher_id = :teacher_id'
    );
    $stmt->execute(['student_id' => $studentId, 'teacher_id' => $teacherId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

function ensure_message_request(int $studentId, int $teacherId): array
{
    $req = get_message_request($studentId, $teacherId);
    if ($req) {
        return $req;
    }
    $stmt = database_connection()->prepare(
        'INSERT INTO message_requests (student_id, teacher_id, status)
         VALUES (:student_id, :teacher_id, \'PENDING\')
         ON CONFLICT (student_id, teacher_id) DO UPDATE SET updated_at = CURRENT_TIMESTAMP
         RETURNING id, student_id, teacher_id, status, rejection_reason, is_frozen, frozen_at, frozen_by, created_at, updated_at'
    );
    $stmt->execute(['student_id' => $studentId, 'teacher_id' => $teacherId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function update_message_request_status(int $teacherId, int $studentId, string $status, ?string $reason = null): void
{
    if (!in_array($status, ['ACCEPTED', 'IGNORED', 'REJECTED'], true)) {
        throw new InvalidArgumentException('حالة غير صالحة لطلب المراسلة.');
    }
    database_connection()->prepare(
        'INSERT INTO message_requests (student_id, teacher_id, status, rejection_reason, updated_at)
         VALUES (:student_id, :teacher_id, :status, :reason, CURRENT_TIMESTAMP)
         ON CONFLICT (student_id, teacher_id)
         DO UPDATE SET status = EXCLUDED.status, rejection_reason = EXCLUDED.rejection_reason, updated_at = CURRENT_TIMESTAMP'
    )->execute([
        'student_id' => $studentId,
        'teacher_id' => $teacherId,
        'status' => $status,
        'reason' => $status === 'REJECTED' ? trim((string)$reason) : null,
    ]);
}

/**
 * دالة جلب كافة الرسائل بين أي أستاذ وطالب لمدير النظام (وضع القراءة والرقابة فقط)
 */
function admin_list_message_thread(int $teacherId, int $studentId): array
{
    $statement = database_connection()->prepare(
        'SELECT id, sender_id, receiver_id, message_body, sent_at, read_at
         FROM (SELECT id, sender_id, receiver_id, message_body, sent_at, read_at
               FROM messages WHERE ((sender_id = :t AND receiver_id = :s) OR (sender_id = :s AND receiver_id = :t))
                    AND deleted_at IS NULL ORDER BY sent_at DESC, id DESC LIMIT 200) recent
         ORDER BY sent_at, id'
    );
    $statement->execute(['t' => $teacherId, 's' => $studentId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * دالة جلب الشجرة الهرمية لكافة المحادثات بين الأساتذة والطلاب لمدير النظام
 */
function admin_list_messaging_overview(): array
{
    $pdo = database_connection();
    
    // جلب كافة المدرسين النشطين
    $teachersStmt = $pdo->query("
        SELECT u.id, u.full_name, u.email, e.job_title
        FROM users u
        JOIN employees e ON e.user_id = u.id AND e.is_active = TRUE
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id AND r.code = 'teacher'
        ORDER BY u.full_name ASC
    ");
    $teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($teachers as $teacher) {
        $tId = (int) $teacher['id'];

        // جلب الطلاب المرتبطين بهذا المعلم سواء بوجود طلب مراسلة أو رسائل سابقة
        $studentsStmt = $pdo->prepare("
            SELECT DISTINCT s_user.id AS student_id, s_user.full_name AS student_name, s_user.email AS student_email,
                   c.name AS class_name, mr.status AS request_status, COALESCE(mr.is_frozen, FALSE) AS is_frozen,
                   mr.rejection_reason,
                   (
                       SELECT m.message_body FROM messages m
                       WHERE ((m.sender_id = s_user.id AND m.receiver_id = :t_id1) OR (m.sender_id = :t_id2 AND m.receiver_id = s_user.id))
                         AND m.deleted_at IS NULL
                       ORDER BY m.sent_at DESC, m.id DESC LIMIT 1
                   ) AS latest_message,
                   (
                       SELECT m.sent_at FROM messages m
                       WHERE ((m.sender_id = s_user.id AND m.receiver_id = :t_id3) OR (m.sender_id = :t_id4 AND m.receiver_id = s_user.id))
                         AND m.deleted_at IS NULL
                       ORDER BY m.sent_at DESC, m.id DESC LIMIT 1
                   ) AS latest_message_at,
                   (
                       SELECT COUNT(*) FROM messages m
                       WHERE ((m.sender_id = s_user.id AND m.receiver_id = :t_id5) OR (m.sender_id = :t_id6 AND m.receiver_id = s_user.id))
                         AND m.deleted_at IS NULL
                   ) AS messages_total
            FROM users s_user
            JOIN students stud ON stud.user_id = s_user.id
            JOIN classes c ON c.id = stud.class_id
            LEFT JOIN message_requests mr ON mr.student_id = s_user.id AND mr.teacher_id = :t_id7
            WHERE s_user.is_active = TRUE
              AND (
                mr.id IS NOT NULL
                OR EXISTS (
                    SELECT 1 FROM messages m
                    WHERE (m.sender_id = s_user.id AND m.receiver_id = :t_id8) OR (m.sender_id = :t_id9 AND m.receiver_id = s_user.id)
                )
                OR EXISTS (
                    SELECT 1 FROM teaching_assignments ta
                    JOIN employees emp ON emp.id = ta.employee_id
                    JOIN class_subjects cs ON cs.id = ta.class_subject_id
                    WHERE emp.user_id = :t_id10 AND cs.class_id = stud.class_id
                )
              )
            ORDER BY COALESCE(mr.is_frozen, FALSE) DESC, latest_message_at DESC NULLS LAST, s_user.full_name ASC
        ");

        $studentsStmt->execute([
            't_id1' => $tId, 't_id2' => $tId, 't_id3' => $tId, 't_id4' => $tId,
            't_id5' => $tId, 't_id6' => $tId, 't_id7' => $tId, 't_id8' => $tId,
            't_id9' => $tId, 't_id10' => $tId
        ]);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        $frozenCount = 0;
        $activeCount = 0;
        foreach ($students as $s) {
            if (!empty($s['is_frozen'])) {
                $frozenCount++;
            } elseif (!empty($s['messages_total']) && $s['messages_total'] > 0) {
                $activeCount++;
            }
        }

        $teacher['students'] = $students;
        $teacher['total_students'] = count($students);
        $teacher['active_conversations'] = $activeCount;
        $teacher['frozen_conversations'] = $frozenCount;
        $result[] = $teacher;
    }

    return $result;
}

/**
 * تجميد محادثة فردية بين طالب ومعلم
 */
function freeze_conversation(int $studentId, int $teacherId, int $adminUserId): void
{
    ensure_message_request($studentId, $teacherId);
    $stmt = database_connection()->prepare("
        UPDATE message_requests
        SET is_frozen = TRUE, frozen_at = CURRENT_TIMESTAMP, frozen_by = :admin_id, updated_at = CURRENT_TIMESTAMP
        WHERE student_id = :student_id AND teacher_id = :teacher_id
    ");
    $stmt->execute([
        'admin_id' => $adminUserId,
        'student_id' => $studentId,
        'teacher_id' => $teacherId,
    ]);
}

/**
 * فك تجميد محادثة فردية بين طالب ومعلم
 */
function unfreeze_conversation(int $studentId, int $teacherId): void
{
    ensure_message_request($studentId, $teacherId);
    $stmt = database_connection()->prepare("
        UPDATE message_requests
        SET is_frozen = FALSE, frozen_at = NULL, frozen_by = NULL, updated_at = CURRENT_TIMESTAMP
        WHERE student_id = :student_id AND teacher_id = :teacher_id
    ");
    $stmt->execute([
        'student_id' => $studentId,
        'teacher_id' => $teacherId,
    ]);
}

/**
 * تجميد كافة المحادثات الخاصة بمعلم بعينه
 */
function freeze_all_teacher_conversations(int $teacherId, int $adminUserId): void
{
    $pdo = database_connection();
    // جلب كافة طلاب هذا المعلم المسندين أو الذين راسلوه
    $stmt = $pdo->prepare("
        SELECT DISTINCT stud.user_id AS student_id
        FROM students stud
        WHERE EXISTS (
            SELECT 1 FROM teaching_assignments ta
            JOIN employees emp ON emp.id = ta.employee_id
            JOIN class_subjects cs ON cs.id = ta.class_subject_id
            WHERE emp.user_id = :tid1 AND cs.class_id = stud.class_id
        ) OR EXISTS (
            SELECT 1 FROM messages m
            WHERE (m.sender_id = stud.user_id AND m.receiver_id = :tid2)
               OR (m.sender_id = :tid3 AND m.receiver_id = stud.user_id)
        )
    ");
    $stmt->execute(['tid1' => $teacherId, 'tid2' => $teacherId, 'tid3' => $teacherId]);
    $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($studentIds as $sId) {
        freeze_conversation((int) $sId, $teacherId, $adminUserId);
    }
}

/**
 * فك تجميد كافة المحادثات الخاصة بمعلم بعينه
 */
function unfreeze_all_teacher_conversations(int $teacherId): void
{
    $stmt = database_connection()->prepare("
        UPDATE message_requests
        SET is_frozen = FALSE, frozen_at = NULL, frozen_by = NULL, updated_at = CURRENT_TIMESTAMP
        WHERE teacher_id = :teacher_id
    ");
    $stmt->execute(['teacher_id' => $teacherId]);
}

/**
 * تجميد كافة المحادثات في كامل المنصة
 */
function freeze_all_platform_conversations(int $adminUserId): void
{
    $pdo = database_connection();
    // تجميد كل ما هو موجود
    $pdo->prepare("
        UPDATE message_requests
        SET is_frozen = TRUE, frozen_at = CURRENT_TIMESTAMP, frozen_by = :admin_id, updated_at = CURRENT_TIMESTAMP
    ")->execute(['admin_id' => $adminUserId]);
}

/**
 * فك تجميد كافة المحادثات في كامل المنصة
 */
function unfreeze_all_platform_conversations(): void
{
    database_connection()->exec("
        UPDATE message_requests
        SET is_frozen = FALSE, frozen_at = NULL, frozen_by = NULL, updated_at = CURRENT_TIMESTAMP
    ");
}


