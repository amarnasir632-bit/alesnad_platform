<?php
declare(strict_types=1);

function account_public_fields(array $row): array
{
    return [
        'id' => (int) $row['id'], 'full_name' => $row['full_name'], 'email' => $row['email'],
        'phone' => $row['phone'], 'is_active' => database_boolean($row['is_active']),
        'roles' => $row['roles'] ?? [], 'created_at' => $row['created_at'] ?? null,
    ];
}

function list_accounts(int $limit = 50, int $offset = 0): array
{
    $statement = database_connection()->prepare(
        'SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.created_at,
                COALESCE(array_agg(r.code ORDER BY r.code) FILTER (WHERE r.code IS NOT NULL), ARRAY[]::VARCHAR[]) AS roles
         FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
         GROUP BY u.id ORDER BY u.id DESC LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
    $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();
    return array_map('account_row_to_public', $statement->fetchAll());
}

function find_account(int $id): ?array
{
    $statement = database_connection()->prepare(
        'SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.created_at,
                COALESCE(array_agg(r.code ORDER BY r.code) FILTER (WHERE r.code IS NOT NULL), ARRAY[]::VARCHAR[]) AS roles
         FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
         WHERE u.id = :id GROUP BY u.id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row ? account_row_to_public($row) : null;
}

function account_row_to_public(array $row): array
{
    if (!is_array($row['roles'])) {
        $roles = trim((string) $row['roles'], '{}');
        $row['roles'] = $roles === '' ? [] : str_getcsv($roles, ',', '"', '\\');
    }
    return account_public_fields($row);
}

function create_managed_account(array $input, int $actorId): array
{
    if (!is_string($input['full_name'] ?? null) || !is_string($input['email'] ?? null) || !is_string($input['phone'] ?? '')) {
        throw new InvalidArgumentException('الاسم والبريد والهاتف يجب أن تكون نصوصًا.');
    }
    $name = trim($input['full_name']);
    $email = strtolower(trim($input['email']));
    $phone = trim((string) ($input['phone'] ?? ''));
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $roles = normalize_role_codes($input['roles'] ?? null);
    $nameLength = preg_match_all('/./us', $name);
    if ($nameLength === false || $nameLength < 2 || $nameLength > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('أدخل اسمًا وبريدًا إلكترونيًا صحيحين.');
    }
    if ($phone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $phone)) throw new InvalidArgumentException('رقم الهاتف غير صحيح.');
    validate_password_or_throw($password);

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        if (in_array('admin', $roles, true)) lock_admin_role($pdo);
        $insert = $pdo->prepare('INSERT INTO users (full_name,email,phone,password_hash) VALUES (:name,:email,:phone,:hash) RETURNING id');
        $insert->execute(['name' => $name, 'email' => $email, 'phone' => $phone ?: null, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $insert->fetchColumn();
        set_roles_in_transaction($pdo, $id, $roles, $actorId);
        ensure_account_profiles($pdo, $id, $roles);
        audit_log('account.created', 'user', $id, ['roles' => $roles]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return find_account($id) ?? throw new RuntimeException('Created account is unavailable.');
}

function update_managed_account(int $id, array $input): array
{
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id,is_active FROM users WHERE id=:id FOR UPDATE');
        $query->execute(['id' => $id]);
        $user = $query->fetch();
        if (!$user) throw new OutOfBoundsException('الحساب غير موجود.');
        $sets = [];
        $params = ['id' => $id];
        if (array_key_exists('full_name', $input)) {
            if (!is_string($input['full_name'])) throw new InvalidArgumentException('الاسم يجب أن يكون نصًا.');
            $name = trim((string) $input['full_name']);
            $length = preg_match_all('/./us', $name);
            if ($length === false || $length < 2 || $length > 150) throw new InvalidArgumentException('الاسم غير صحيح.');
            $sets[] = 'full_name=:name'; $params['name'] = $name;
        }
        if (array_key_exists('email', $input)) {
            if (!is_string($input['email'])) throw new InvalidArgumentException('البريد الإلكتروني يجب أن يكون نصًا.');
            $email = strtolower(trim($input['email']));
            if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('البريد الإلكتروني غير صحيح.');
            $sets[] = 'email=:email'; $params['email'] = $email;
        }
        if (array_key_exists('phone', $input)) {
            if (!is_string($input['phone'])) throw new InvalidArgumentException('رقم الهاتف يجب أن يكون نصًا.');
            $phone = trim((string) $input['phone']);
            if ($phone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $phone)) throw new InvalidArgumentException('رقم الهاتف غير صحيح.');
            $sets[] = 'phone=:phone'; $params['phone'] = $phone ?: null;
        }
        if (array_key_exists('password', $input)) {
            if (!is_string($input['password'])) throw new InvalidArgumentException('كلمة المرور غير صحيحة.');
            validate_password_or_throw($input['password']);
            $sets[] = 'password_hash=:hash'; $params['hash'] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('is_active', $input)) {
            if (!is_bool($input['is_active'])) throw new InvalidArgumentException('is_active يجب أن تكون قيمة منطقية.');
            if (!$input['is_active'] && database_boolean($user['is_active']) && is_admin_account($pdo, $id)) ensure_not_last_admin($pdo, $id);
            $sets[] = 'is_active=:active'; $params['active'] = $input['is_active'];
        }
        if (!$sets) throw new InvalidArgumentException('لا توجد حقول قابلة للتعديل.');
        $update = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id=:id');
        $update->execute($params);
        audit_log('account.updated', 'user', $id, ['fields' => array_keys($input)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return find_account($id) ?? throw new RuntimeException('Updated account is unavailable.');
}

function assign_account_roles(int $id, mixed $roleCodes, int $actorId): array
{
    $roles = normalize_role_codes($roleCodes);
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $userQuery = $pdo->prepare('SELECT id,is_active FROM users WHERE id=:id FOR UPDATE');
        $userQuery->execute(['id' => $id]);
        $user = $userQuery->fetch();
        if (!$user) throw new OutOfBoundsException('الحساب غير موجود.');
        lock_admin_role($pdo);
        $currentQuery = $pdo->prepare("SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=:id");
        $currentQuery->execute(['id' => $id]);
        $current = $currentQuery->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('admin', $current, true) && !in_array('admin', $roles, true) && database_boolean($user['is_active'])) ensure_not_last_admin($pdo, $id);
        $pdo->prepare('DELETE FROM user_roles WHERE user_id=:id')->execute(['id' => $id]);
        set_roles_in_transaction($pdo, $id, $roles, $actorId);
        ensure_account_profiles($pdo, $id, $roles);
        audit_log('account.roles_changed', 'user', $id, ['roles' => $roles]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return find_account($id) ?? throw new RuntimeException('Updated account is unavailable.');
}

function normalize_role_codes(mixed $roles): array
{
    if (!is_array($roles) || $roles === [] || count($roles) > 8) throw new InvalidArgumentException('أرسل دورًا واحدًا على الأقل.');
    foreach ($roles as $role) if (!is_string($role) || !preg_match('/^[a-z][a-z0-9_]{1,49}$/', $role)) throw new InvalidArgumentException('رمز الدور غير صحيح.');
    return array_values(array_unique($roles));
}

function set_roles_in_transaction(PDO $pdo, int $userId, array $roles, int $actorId): void
{
    $find = $pdo->prepare('SELECT id FROM roles WHERE code=:code');
    $insert = $pdo->prepare('INSERT INTO user_roles (user_id,role_id,assigned_by) VALUES (:user_id,:role_id,:actor_id)');
    foreach ($roles as $code) {
        $find->execute(['code' => $code]);
        $roleId = $find->fetchColumn();
        if ($roleId === false) throw new InvalidArgumentException('الدور غير معروف: ' . $code);
        $insert->execute(['user_id' => $userId, 'role_id' => $roleId, 'actor_id' => $actorId]);
    }
}

function ensure_account_profiles(PDO $pdo, int $id, array $roles): void
{
    if (in_array('student', $roles, true)) $pdo->prepare('INSERT INTO students(user_id) VALUES(:id) ON CONFLICT(user_id) DO NOTHING')->execute(['id' => $id]);
    $employeeRoles = ['teacher' => 'أستاذ', 'content_reviewer' => 'مراجع محتوى', 'admin' => 'مدير النظام'];
    foreach ($employeeRoles as $role => $title) {
        if (in_array($role, $roles, true)) $pdo->prepare('INSERT INTO employees(user_id,job_title) VALUES(:id,:title) ON CONFLICT(user_id) DO UPDATE SET job_title=EXCLUDED.job_title')->execute(['id' => $id, 'title' => $title]);
    }
}

function lock_admin_role(PDO $pdo): void
{
    $query = $pdo->prepare("SELECT id FROM roles WHERE code='admin' FOR UPDATE");
    $query->execute();
    if ($query->fetchColumn() === false) throw new RuntimeException('Admin role is not seeded.');
}

function ensure_not_last_admin(PDO $pdo, int $targetId): void
{
    lock_admin_role($pdo);
    $query = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.code='admin' AND u.is_active AND u.id<>:id");
    $query->execute(['id' => $targetId]);
    if ((int) $query->fetchColumn() < 1) throw new DomainException('لا يمكن تعطيل أو إزالة دور آخر مدير نظام نشط.');
}

function is_admin_account(PDO $pdo, int $id): bool
{
    $query = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id AND r.code='admin')");
    $query->execute(['id' => $id]);
    return database_boolean($query->fetchColumn());
}
