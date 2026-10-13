<?php
declare(strict_types=1);

function refresh_authenticated_user(): ?array
{
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || !isset($sessionUser['id'])) {
        unset($_SESSION['user']);
        return null;
    }

    $statement = database_connection()->prepare(
        'SELECT id, full_name, email, is_active FROM users WHERE id = :id'
    );
    $statement->execute(['id' => (int) $sessionUser['id']]);
    $user = $statement->fetch();
    if (!$user || !database_boolean($user['is_active'])) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['last_activity'] = time();
        return null;
    }

    $roleStatement = database_connection()->prepare(
        'SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :user_id ORDER BY r.code'
    );
    $roleStatement->execute(['user_id' => $user['id']]);
    $user['roles'] = $roleStatement->fetchAll(PDO::FETCH_COLUMN);
    $user['id'] = (int) $user['id'];
    $_SESSION['user'] = $user;
    return $user;
}

function has_permission(string $permission): bool
{
    $user = refresh_authenticated_user();
    if ($user === null) {
        return false;
    }
    $statement = database_connection()->prepare(
        'SELECT EXISTS (
            SELECT 1
            FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = :user_id AND p.code = :permission
        )'
    );
    $statement->execute(['user_id' => $user['id'], 'permission' => $permission]);
    return database_boolean($statement->fetchColumn());
}

function require_permission(string $permission): array
{
    if (!has_permission($permission)) {
        json_response(403, ['error' => ['code' => 'forbidden', 'message' => 'لا تملك صلاحية تنفيذ هذا الإجراء.']]);
    }
    return refresh_authenticated_user() ?? [];
}

function require_role(string ...$roles): array
{
    $user = refresh_authenticated_user();
    if ($user === null || array_intersect($roles, $user['roles']) === []) {
        json_response(403, ['error' => ['code' => 'forbidden', 'message' => 'لا تملك صلاحية تنفيذ هذا الإجراء.']]);
    }
    return $user;
}
