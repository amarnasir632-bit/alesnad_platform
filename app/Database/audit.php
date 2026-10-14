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
