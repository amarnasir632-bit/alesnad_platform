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
               FROM messages WHERE ((sender_id=:sender AND receiver_id=:receiver) OR (sender_id=:receiver2 AND receiver_id=:sender2))
                    AND deleted_at IS NULL ORDER BY sent_at DESC, id DESC LIMIT 100) recent
         ORDER BY sent_at, id'
    );
    $statement->execute(['sender' => $userId, 'receiver' => $peerId, 'receiver2' => $userId, 'sender2' => $peerId]);
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
    database_connection()->prepare(
        'INSERT INTO messages (sender_id, receiver_id, message_body) VALUES (:sender_id, :receiver_id, :message_body)'
    )->execute(['sender_id' => $senderId, 'receiver_id' => $receiverId, 'message_body' => $body]);
}
