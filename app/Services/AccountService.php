<?php
declare(strict_types=1);

function account_public_fields(array $row): array
{
    return [
        'id' => (int) $row['id'], 'full_name' => $row['full_name'], 'email' => $row['email'],
        'phone' => $row['phone'], 'is_active' => database_boolean($row['is_active']),
        'roles' => $row['roles'] ?? [], 'class_id' => isset($row['class_id']) ? (int) $row['class_id'] : null,
        'class_name' => $row['class_name'] ?? null, 'created_at' => $row['created_at'] ?? null,
    ];
}

function list_accounts(int $limit = 50, int $offset = 0): array
{
    $statement = database_connection()->prepare(
        'SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.created_at, st.class_id, c.name AS class_name,
                COALESCE(array_agg(r.code ORDER BY r.code) FILTER (WHERE r.code IS NOT NULL), ARRAY[]::VARCHAR[]) AS roles
         FROM users u LEFT JOIN students st ON st.user_id = u.id LEFT JOIN classes c ON c.id = st.class_id
         LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
         GROUP BY u.id, st.class_id, c.name ORDER BY u.id DESC LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
    $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();
    return array_map('account_row_to_public', $statement->fetchAll());
}

function find_account(int $id): ?array
{
    $statement = database_connection()->prepare(
        'SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.created_at, st.class_id, c.name AS class_name,
                COALESCE(array_agg(r.code ORDER BY r.code) FILTER (WHERE r.code IS NOT NULL), ARRAY[]::VARCHAR[]) AS roles
         FROM users u LEFT JOIN students st ON st.user_id = u.id LEFT JOIN classes c ON c.id = st.class_id
         LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
         WHERE u.id = :id GROUP BY u.id, st.class_id, c.name'
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
        $studentClassChange = false;
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
        if (array_key_exists('class_id', $input)) {
            $student = $pdo->prepare('SELECT id FROM students WHERE user_id = :id FOR UPDATE');
            $student->execute(['id' => $id]);
            $studentId = $student->fetchColumn();
            if ($studentId === false) throw new InvalidArgumentException('تعيين الصف متاح لحساب الطالب فقط.');
            $classId = $input['class_id'] === null ? null : filter_var($input['class_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($classId === false) throw new InvalidArgumentException('الصف غير صالح.');
            if ($classId !== null) {
                $class = $pdo->prepare('SELECT 1 FROM classes c JOIN stages s ON s.id = c.stage_id WHERE c.id = :id AND c.is_active = TRUE AND s.is_active = TRUE');
                $class->execute(['id' => $classId]);
                if ($class->fetchColumn() === false) throw new InvalidArgumentException('الصف غير نشط أو غير موجود.');
            }
            $studentClassChange = true;
            $params['class_id'] = $classId;
        }
        if (!$sets && !$studentClassChange) throw new InvalidArgumentException('لا توجد حقول قابلة للتعديل.');
        if ($sets) {
            $update = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id=:id');
            $update->execute($params);
        }
        if ($studentClassChange) {
            $pdo->prepare('UPDATE students SET class_id = :class_id WHERE user_id = :id')->execute(['class_id' => $params['class_id'], 'id' => $id]);
        }
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

function get_user_account_details(int $userId): array
{
    $pdo = database_connection();
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, is_active, created_at, last_login_at FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new OutOfBoundsException('المستخدم غير موجود.');
    }

    $rolesStmt = $pdo->prepare('SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :id ORDER BY r.code');
    $rolesStmt->execute(['id' => $userId]);
    $user['roles'] = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);

    $studentStmt = $pdo->prepare(
        'SELECT st.id, st.student_number, st.class_id, st.parent_phone, st.date_of_birth,
                c.name AS class_name, s.name AS stage_name
         FROM students st
         LEFT JOIN classes c ON c.id = st.class_id
         LEFT JOIN stages s ON s.id = c.stage_id
         WHERE st.user_id = :id'
    );
    $studentStmt->execute(['id' => $userId]);
    $student = $studentStmt->fetch();

    if ($student) {
        if (empty($student['student_number'])) {
            $defaultNumber = 'STD-' . str_pad((string) $student['id'], 5, '0', STR_PAD_LEFT);
            try {
                $updateNum = $pdo->prepare("UPDATE students SET student_number = :num WHERE id = :id AND (student_number IS NULL OR student_number = '')");
                $updateNum->execute(['num' => $defaultNumber, 'id' => $student['id']]);
                $student['student_number'] = $defaultNumber;
            } catch (Throwable) {
                $student['student_number'] = $defaultNumber;
            }
        }
    }

    $employeeStmt = $pdo->prepare('SELECT id, staff_number, job_title, profile_image_key, profile_image_mime FROM employees WHERE user_id = :id');
    $employeeStmt->execute(['id' => $userId]);
    $employee = $employeeStmt->fetch();

    $teachingSubjects = [];
    if ($employee) {
        $teachingStmt = $pdo->prepare(
            'SELECT cs.id AS class_subject_id, sub.name AS subject_name, c.name AS class_name, stg.name AS stage_name
             FROM teaching_assignments ta
             JOIN class_subjects cs ON cs.id = ta.class_subject_id
             JOIN subjects sub ON sub.id = cs.subject_id
             JOIN classes c ON c.id = cs.class_id
             JOIN stages stg ON stg.id = c.stage_id
             WHERE ta.employee_id = :employee_id
             ORDER BY stg.name, c.name, sub.name'
        );
        $teachingStmt->execute(['employee_id' => $employee['id']]);
        $teachingSubjects = $teachingStmt->fetchAll();
    }

    $hasContentAccess = student_has_manual_content_access($userId);

    return [
        'user' => $user,
        'student' => $student ?: null,
        'employee' => $employee ?: null,
        'teaching_subjects' => $teachingSubjects,
        'has_content_access' => $hasContentAccess,
    ];
}

function update_user_self_profile(int $userId, array $data): void
{
    $name = trim((string) ($data['full_name'] ?? ''));
    $nameLength = preg_match_all('/./us', $name);
    if ($nameLength === false || $nameLength < 2 || $nameLength > 150) {
        throw new InvalidArgumentException('الاسم الكامل يجب أن يكون بين محرفين و 150 محرفًا.');
    }

    function update_teacher_profile_image(int $userId, array $file): void
    {
        if (!user_has_role('teacher')) {
            throw new ApiException(403, 'teacher_profile_required', 'تحديث صورة الملف متاح لحساب الأستاذ فقط.');
        }
        $pdo = database_connection();
        $lookup = $pdo->prepare('SELECT id, profile_image_key FROM employees WHERE user_id = :user_id AND is_active');
        $lookup->execute(['user_id' => $userId]);
        $employee = $lookup->fetch();
        if (!$employee) throw new ApiException(403, 'employee_profile_required', 'يلزم ملف أستاذ نشط لتحديث الصورة.');

        $stored = store_uploaded_file($file, 'IMAGE');
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE employees SET profile_image_key = :image_key, profile_image_mime = :mime
                 WHERE id = :employee_id AND is_active'
            );
            $update->execute([
                'image_key' => $stored['storage_key'],
                'mime' => $stored['mime_type'],
                'employee_id' => (int) $employee['id'],
            ]);
            if ($update->rowCount() !== 1) throw new ApiException(409, 'employee_profile_changed', 'تعذر تحديث ملف الأستاذ.');
            audit_log('teacher.profile_image.updated', 'employee', (int) $employee['id'], [], $userId);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            discard_private_file($stored['storage_key']);
            throw $error;
        }

        if (is_string($employee['profile_image_key']) && $employee['profile_image_key'] !== '') {
            discard_private_file($employee['profile_image_key']);
        }
    }

    $phone = trim((string) ($data['phone'] ?? ''));
    if ($phone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $phone)) {
        throw new InvalidArgumentException('رقم الهاتف غير صحيح (يجب أن يحتوي على أرقام فقط بين 7 و 32 خانة).');
    }

    $parentPhone = trim((string) ($data['parent_phone'] ?? ''));
    if ($parentPhone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $parentPhone)) {
        throw new InvalidArgumentException('رقم هاتف ولي الأمر غير صحيح.');
    }

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $userStmt = $pdo->prepare('UPDATE users SET full_name = :name, phone = :phone WHERE id = :id');
        $userStmt->execute([
            'name' => $name,
            'phone' => $phone !== '' ? $phone : null,
            'id' => $userId,
        ]);

        $studentStmt = $pdo->prepare('UPDATE students SET parent_phone = :parent_phone WHERE user_id = :id');
        $studentStmt->execute([
            'parent_phone' => $parentPhone !== '' ? $parentPhone : null,
            'id' => $userId,
        ]);

        audit_log('profile.updated', 'user', $userId, ['name' => $name], $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['full_name'] = $name;
    }
}

function change_user_password(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): void
{
    if ($currentPassword === '') {
        throw new InvalidArgumentException('يرجى إدخال كلمة المرور الحالية.');
    }
    if ($newPassword === '') {
        throw new InvalidArgumentException('يرجى إدخال كلمة المرور الجديدة.');
    }
    if ($newPassword !== $confirmPassword) {
        throw new InvalidArgumentException('كلمة المرور الجديدة غير متطابقة مع التأكيد.');
    }
    if ($newPassword === $currentPassword) {
        throw new InvalidArgumentException('كلمة المرور الجديدة يجب أن تكون مختلفة عن كلمة المرور الحالية.');
    }

    validate_password_or_throw($newPassword);

    $pdo = database_connection();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($currentPassword, (string) $user['password_hash'])) {
        throw new InvalidArgumentException('كلمة المرور الحالية غير صحيحة.');
    }

    $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $update->execute([
        'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);

    audit_log('password.changed', 'user', $userId, [], $userId);
}
