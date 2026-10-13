<?php
declare(strict_types=1);

function audit_log(
    string $action,
    ?string $targetType = null,
    ?int $targetId = null,
    array $metadata = [],
    ?int $actorId = null
): void {
    $actorId ??= isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ip = null;
    }
    $statement = database_connection()->prepare(
        'INSERT INTO audit_events (actor_user_id, action_code, target_type, target_id, ip_address, metadata)
         VALUES (:actor_user_id, :action_code, :target_type, :target_id, CAST(:ip_address AS INET), CAST(:metadata AS JSONB))'
    );
    $statement->execute([
        'actor_user_id' => $actorId,
        'action_code' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'ip_address' => $ip,
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}
