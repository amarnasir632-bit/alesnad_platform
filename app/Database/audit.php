<?php
declare(strict_types=1);

function record_audit_event(PDO $pdo, int $actorId, string $action, string $targetType, int $targetId, array $metadata = []): void
{
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        $ipAddress = null;
    }

    $statement = $pdo->prepare(
        'INSERT INTO audit_events (actor_user_id, action_code, target_type, target_id, ip_address, metadata)
         VALUES (:actor_user_id, :action_code, :target_type, :target_id, :ip_address, CAST(:metadata AS JSONB))'
    );
    $statement->execute([
        'actor_user_id' => $actorId,
        'action_code' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'ip_address' => $ipAddress,
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

function admin_dashboard_summary(): array
{
    $pdo = database_connection();
    $counts = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM students s
             JOIN users u ON u.id = s.user_id AND u.is_active
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.code = 'student') AS active_students,
            (SELECT COUNT(*) FROM employees e
             JOIN users u ON u.id = e.user_id AND u.is_active
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.code = 'teacher'
             WHERE e.is_active) AS active_teachers,
            (SELECT COUNT(*) FROM lessons WHERE is_published) AS published_lessons,
            (SELECT COUNT(*) FROM learning_resources
             WHERE status = 'APPROVED' AND resource_type IN ('VIDEO', 'AUDIO')) AS published_media"
    )->fetch();

    $activity = $pdo->query(
        'SELECT ae.action_code, ae.target_type, ae.occurred_at, u.full_name AS actor_name
         FROM audit_events ae
         LEFT JOIN users u ON u.id = ae.actor_user_id
         ORDER BY ae.occurred_at DESC, ae.id DESC
         LIMIT 8'
    )->fetchAll();

    $blockedLogins = $pdo->query(
        "SELECT COUNT(*) FROM audit_events
         WHERE action_code = 'auth.login.blocked'
           AND occurred_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours'"
    )->fetchColumn();

    $databaseAvailable = true;
    try {
        $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $error) {
        $databaseAvailable = false;
        app_log_error('admin_dashboard_health_check_failed', $error);
    }

    return [
        'active_students' => (int) $counts['active_students'],
        'active_teachers' => (int) $counts['active_teachers'],
        'published_lessons' => (int) $counts['published_lessons'],
        'published_media' => (int) $counts['published_media'],
        'blocked_logins_24h' => (int) $blockedLogins,
        'database_available' => $databaseAvailable,
        'activity' => $activity,
    ];
}
