<?php
declare(strict_types=1);

function list_students_with_access(int $page = 1, int $perPage = 50): array
{
    $page = max(1, $page);
    $perPage = max(1, min($perPage, 100));
    $pdo = database_connection();
    $total = (int) $pdo->query(
        "SELECT COUNT(*) FROM students s JOIN user_roles ur ON ur.user_id = s.user_id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'student'"
    )->fetchColumn();
    $pageCount = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pageCount);

    $statement = $pdo->prepare(
        "SELECT s.id AS student_id, u.full_name, u.email,
                CASE WHEN u.is_active THEN 1 ELSE 0 END AS account_active,
                access.id AS access_id, access.granted_at, access.grant_reason
         FROM students s
         JOIN users u ON u.id = s.user_id
         JOIN user_roles ur ON ur.user_id = s.user_id
         JOIN roles r ON r.id = ur.role_id AND r.code = 'student'
         LEFT JOIN manual_content_access access ON access.student_id = s.id AND access.revoked_at IS NULL
         ORDER BY u.full_name, s.id
         LIMIT :limit OFFSET :offset"
    );
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $statement->execute();

    return [
        'students' => $statement->fetchAll(),
        'total' => $total,
        'page' => $page,
        'page_count' => $pageCount,
    ];
}

function student_has_manual_content_access(int $userId): bool
{
    if ($userId < 1) {
        return false;
    }
    $statement = database_connection()->prepare(
        'SELECT CASE WHEN EXISTS (
            SELECT 1
            FROM students s
            JOIN users u ON u.id = s.user_id AND u.is_active
            JOIN manual_content_access access ON access.student_id = s.id AND access.revoked_at IS NULL
            WHERE s.user_id = :user_id
        ) THEN 1 ELSE 0 END'
    );
    $statement->execute(['user_id' => $userId]);
    return (bool) $statement->fetchColumn();
}

function change_student_content_access(int $studentId, int $actorId, string $change, string $reason): bool
{
    if ($studentId < 1 || !in_array($change, ['grant', 'revoke'], true)) {
        throw new InvalidArgumentException('طلب إدارة الوصول غير صحيح.');
    }
    $reason = trim($reason);
    $reasonLength = preg_match_all('/./us', $reason);
    if ($reasonLength === false || $reasonLength > 500) {
        throw new InvalidArgumentException('يجب ألا يتجاوز السبب 500 حرف.');
    }

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $studentStatement = $pdo->prepare(
            'SELECT s.id, CASE WHEN u.is_active THEN 1 ELSE 0 END AS account_active
             FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = :student_id
             FOR UPDATE OF s, u'
        );
        $studentStatement->execute(['student_id' => $studentId]);
        $student = $studentStatement->fetch();
        if (!$student) {
            throw new InvalidArgumentException('حساب الطالب غير موجود.');
        }

        $current = $pdo->prepare(
            'SELECT id FROM manual_content_access
             WHERE student_id = :student_id AND revoked_at IS NULL
             FOR UPDATE'
        );
        $current->execute(['student_id' => $studentId]);
        $currentAccessId = $current->fetchColumn();

        if ($change === 'grant') {
            if ((int) $student['account_active'] !== 1) {
                throw new InvalidArgumentException('لا يمكن منح الوصول لحساب معطل.');
            }
            if ($currentAccessId !== false) {
                $pdo->commit();
                return false;
            }
            $insert = $pdo->prepare(
                'INSERT INTO manual_content_access (student_id, granted_by, grant_reason)
                 VALUES (:student_id, :actor_id, :reason) RETURNING id'
            );
            $insert->execute(['student_id' => $studentId, 'actor_id' => $actorId, 'reason' => $reason !== '' ? $reason : null]);
            $accessId = (int) $insert->fetchColumn();
            record_audit_event($pdo, $actorId, 'student.content_access.granted', 'student', $studentId, [
                'access_record_id' => $accessId,
                'reason' => $reason !== '' ? $reason : null,
            ]);
        } else {
            if ($currentAccessId === false) {
                $pdo->commit();
                return false;
            }
            $accessId = (int) $currentAccessId;
            $revoke = $pdo->prepare(
                'UPDATE manual_content_access
                 SET revoked_by = :actor_id, revoked_at = CURRENT_TIMESTAMP, revocation_reason = :reason
                 WHERE id = :access_id'
            );
            $revoke->execute(['actor_id' => $actorId, 'reason' => $reason !== '' ? $reason : null, 'access_id' => $accessId]);
            record_audit_event($pdo, $actorId, 'student.content_access.revoked', 'student', $studentId, [
                'access_record_id' => $accessId,
                'reason' => $reason !== '' ? $reason : null,
            ]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
