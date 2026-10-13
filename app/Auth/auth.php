<?php
declare(strict_types=1);

function authenticate_user(string $email, string $password): bool
{
    if (strlen($password) > 72) {
        $emailKey = login_email_key($email);
        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            record_login_failure($email);
            audit_log('auth.login.failed', 'user', null, ['email_key' => $emailKey, 'reason' => 'invalid_credentials']);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return false;
    }

    if (login_is_locked($email)) {
        audit_log('auth.login.blocked', 'user', null, ['email_key' => login_email_key($email)]);
        return false;
    }

    $pdo = database_connection();
    $statement = $pdo->prepare(
        'SELECT id, full_name, email, password_hash,
                CASE WHEN is_active THEN 1 ELSE 0 END AS active_flag
         FROM users
         WHERE lower(email) = lower(:email)'
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if (!$user || (int) $user['active_flag'] !== 1 || !password_verify($password, $user['password_hash'])) {
        $pdo->beginTransaction();
        try {
            record_login_failure($email);
            audit_log('auth.login.failed', 'user', $user ? (int) $user['id'] : null, [
                'email_key' => login_email_key($email),
                'reason' => 'invalid_credentials',
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return false;
    }

    $pdo->beginTransaction();
    try {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }
        $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $user['id']]);
        clear_login_failures($email);
        $roleStatement = $pdo->prepare(
            'SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :user_id ORDER BY r.code'
        );
        $roleStatement->execute(['user_id' => $user['id']]);
        $roles = $roleStatement->fetchAll(PDO::FETCH_COLUMN);
        audit_log('auth.login.succeeded', 'user', (int) $user['id']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'roles' => $roles,
    ];
    unset($_SESSION['csrf_token']);
    csrf_token();
    return true;
}

function register_student(string $fullName, string $email, string $phone, string $password): void
{
    validate_password_or_throw($password);
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $userStatement = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash)
             VALUES (:full_name, :email, :phone, :password_hash)
             RETURNING id, full_name, email'
        );
        $userStatement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $user = $userStatement->fetch();

        $studentRole = $pdo->prepare('SELECT id FROM roles WHERE code = :code');
        $studentRole->execute(['code' => 'student']);
        $roleId = $studentRole->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException('Missing seeded student role. Run database migrations first.');
        }

        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)')
            ->execute(['user_id' => $user['id'], 'role_id' => $roleId]);
        $pdo->prepare('INSERT INTO students (user_id) VALUES (:user_id)')
            ->execute(['user_id' => $user['id']]);
        audit_log('auth.registered', 'user', (int) $user['id'], ['role' => 'student'], (int) $user['id']);

        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'roles' => ['student'],
        ];
        unset($_SESSION['csrf_token']);
        csrf_token();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}
