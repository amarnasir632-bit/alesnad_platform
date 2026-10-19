<?php
declare(strict_types=1);

function list_staff_accounts(): array
{
    return database_connection()->query(
        "SELECT u.id, u.full_name, u.email,
                CASE WHEN u.is_active THEN 1 ELSE 0 END AS account_active,
                e.job_title, invite.expires_at AS invitation_expires_at,
                CASE WHEN invite.expires_at > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS invitation_valid,
                string_agg(r.code, ',' ORDER BY r.code) AS role_codes,
                string_agg(r.display_name, '، ' ORDER BY r.code) AS role_names
         FROM users u
         JOIN employees e ON e.user_id = u.id
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id AND r.code IN ('teacher', 'content_reviewer', 'admin', 'registrar')
         LEFT JOIN LATERAL (
             SELECT expires_at FROM staff_invitations
             WHERE user_id = u.id AND accepted_at IS NULL AND revoked_at IS NULL
             ORDER BY created_at DESC LIMIT 1
         ) invite ON TRUE
         GROUP BY u.id, e.job_title, invite.expires_at
         ORDER BY u.full_name, u.id"
    )->fetchAll();
}

function list_active_teaching_subjects(): array
{
    return database_connection()->query(
        'SELECT cs.id AS class_subject_id, st.name AS stage_name, c.name AS class_name, s.name AS subject_name
         FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.is_active
         JOIN stages st ON st.id=c.stage_id AND st.is_active
         JOIN subjects s ON s.id=cs.subject_id AND s.is_active
         WHERE cs.is_active ORDER BY st.sort_order, c.sort_order, s.name'
    )->fetchAll();
}

function list_teacher_subject_assignments(): array
{
    return database_connection()->query(
        "SELECT u.id AS teacher_user_id, u.full_name,
                string_agg(DISTINCT st.name || ' · ' || c.name || ' · ' || s.name, '، ' ORDER BY st.name || ' · ' || c.name || ' · ' || s.name) AS subjects
         FROM users u JOIN employees e ON e.user_id=u.id
         JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.code='teacher'
         LEFT JOIN teaching_assignments ta ON ta.employee_id=e.id
         LEFT JOIN class_subjects cs ON cs.id=ta.class_subject_id AND cs.is_active
         LEFT JOIN classes c ON c.id=cs.class_id LEFT JOIN stages st ON st.id=c.stage_id
         LEFT JOIN subjects s ON s.id=cs.subject_id
         GROUP BY u.id ORDER BY u.full_name"
    )->fetchAll();
}

function assign_teacher_subject(int $teacherUserId, int $classSubjectId, int $actorId): void
{
    if (!user_has_permission('roles.manage')) {
        throw new InvalidArgumentException('إسناد المواد للأساتذة متاح للمدير فقط.');
    }
    $pdo = database_connection();
    $target = $pdo->prepare(
        "SELECT e.id FROM employees e JOIN users u ON u.id=e.user_id AND u.is_active
         JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.code='teacher'
         WHERE u.id=:user_id AND e.is_active"
    );
    $target->execute(['user_id' => $teacherUserId]);
    $employeeId = $target->fetchColumn();
    $subject = $pdo->prepare(
        'SELECT 1 FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.is_active
         JOIN stages st ON st.id=c.stage_id AND st.is_active JOIN subjects s ON s.id=cs.subject_id AND s.is_active
         WHERE cs.id=:id AND cs.is_active'
    );
    $subject->execute(['id' => $classSubjectId]);
    if ($employeeId === false || !$subject->fetchColumn()) {
        throw new InvalidArgumentException('اختر أستاذًا ومادة نشطين.');
    }
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO teaching_assignments (employee_id, class_subject_id)
             VALUES (:employee_id, :class_subject_id) ON CONFLICT DO NOTHING'
        );
        $statement->execute(['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId]);
        if ($statement->rowCount() > 0) {
            record_audit_event($pdo, $actorId, 'teacher.subject.assigned', 'user', $teacherUserId, ['class_subject_id' => $classSubjectId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function create_staff_invitation(string $fullName, string $email, string $roleCode, string $jobTitle, int $actorId): string
{
    $fullName = trim($fullName);
    $nameLength = preg_match_all('/./us', $fullName);
    if ($nameLength === false || $nameLength < 2 || $nameLength > 150) {
        throw new InvalidArgumentException('أدخل الاسم كاملًا (من حرفين إلى 150 حرفًا).');
    }
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        throw new InvalidArgumentException('أدخل بريدًا إلكترونيًا صحيحًا.');
    }
    $jobTitle = trim($jobTitle);
    $jobTitleLength = preg_match_all('/./us', $jobTitle);
    if ($jobTitleLength === false || $jobTitleLength < 2 || $jobTitleLength > 100) {
        throw new InvalidArgumentException('أدخل المسمى الوظيفي (من حرفين إلى 100 حرف).');
    }
    if (!in_array($roleCode, ['teacher', 'content_reviewer', 'admin', 'registrar'], true)) {
        throw new InvalidArgumentException('الدور المطلوب غير مسموح.');
    }
    if (!user_has_permission('users.manage')) {
        throw new InvalidArgumentException('ليس لديك صلاحية دعوة أعضاء الفريق.');
    }
    if ($roleCode === 'registrar' && !user_has_permission('roles.manage')) {
        throw new InvalidArgumentException('دعوة مسؤول التسجيل والقبول متاحة لمدير النظام فقط.');
    }

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $lookup = $pdo->prepare(
            'SELECT u.id, u.is_active, r.code AS role_code, invitation.id AS invitation_id, invitation.accepted_at
             FROM users u
             JOIN employees e ON e.user_id = u.id
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.code IN (\'teacher\', \'content_reviewer\', \'admin\', \'registrar\')
             LEFT JOIN staff_invitations invitation ON invitation.user_id = u.id AND invitation.accepted_at IS NULL AND invitation.revoked_at IS NULL
             WHERE lower(u.email) = :email
             ORDER BY invitation.created_at DESC NULLS LAST
             LIMIT 1
             FOR UPDATE OF u'
        );
        $lookup->execute(['email' => $email]);
        $existing = $lookup->fetch();
        $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE code = :role_code');
        $roleQuery->execute(['role_code' => $roleCode]);
        $roleId = $roleQuery->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException('الدور المطلوب غير موجود في قاعدة البيانات.');
        }

        if ($existing) {
            if ($existing['role_code'] !== $roleCode) {
                throw new InvalidArgumentException('البريد مسجل بالفعل بدور آخر.');
            }
            if ($existing['invitation_id'] === null) {
                throw new InvalidArgumentException('الحساب مقبول مسبقًا. استخدم إجراء إعادة تعيين كلمة المرور.');
            }
            $userId = (int) $existing['id'];
            $pdo->prepare('UPDATE staff_invitations SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND accepted_at IS NULL AND revoked_at IS NULL')
                ->execute(['user_id' => $userId]);
            $pdo->prepare('UPDATE users SET is_active = FALSE, full_name = :full_name WHERE id = :user_id')
                ->execute(['full_name' => $fullName, 'user_id' => $userId]);
            $pdo->prepare('UPDATE employees SET job_title = :job_title WHERE user_id = :user_id')
                ->execute(['job_title' => $jobTitle, 'user_id' => $userId]);
        } else {
            $randomPassword = bin2hex(random_bytes(32));
            $createUser = $pdo->prepare(
                'INSERT INTO users (full_name, email, password_hash, is_active)
                 VALUES (:full_name, :email, :password_hash, FALSE) RETURNING id'
            );
            $createUser->execute([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => password_hash($randomPassword, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $createUser->fetchColumn();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:user_id, :role_id, :actor_id)')
                ->execute(['user_id' => $userId, 'role_id' => $roleId, 'actor_id' => $actorId]);
            $pdo->prepare('INSERT INTO employees (user_id, job_title) VALUES (:user_id, :job_title)')
                ->execute(['user_id' => $userId, 'job_title' => $jobTitle]);
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $invitation = $pdo->prepare(
            'INSERT INTO staff_invitations (user_id, token_hash, created_by, expires_at)
             VALUES (:user_id, :token_hash, :actor_id, CURRENT_TIMESTAMP + INTERVAL \'48 hours\') RETURNING id'
        );
        $invitation->execute(['user_id' => $userId, 'token_hash' => $tokenHash, 'actor_id' => $actorId]);
        $invitationId = (int) $invitation->fetchColumn();
        record_audit_event($pdo, $actorId, 'staff.invitation.created', 'user', $userId, [
            'role' => $roleCode,
            'invitation_id' => $invitationId,
        ]);
        $pdo->commit();
        return $token;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function find_staff_invitation(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $statement = database_connection()->prepare(
        'SELECT invitation.id AS invitation_id, invitation.user_id, u.full_name, u.email, e.job_title
         FROM staff_invitations invitation
         JOIN users u ON u.id = invitation.user_id
         JOIN employees e ON e.user_id = u.id
         WHERE invitation.token_hash = :token_hash
           AND invitation.expires_at > CURRENT_TIMESTAMP
           AND invitation.accepted_at IS NULL
           AND invitation.revoked_at IS NULL'
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    return $statement->fetch() ?: null;
}

function accept_staff_invitation(int $invitationId, string $password): int
{
    if ($invitationId < 1) throw new InvalidArgumentException('رابط الدعوة غير صالح.');
    validate_password_or_throw($password);
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT invitation.user_id
             FROM staff_invitations invitation
             JOIN users u ON u.id = invitation.user_id
             WHERE invitation.id = :id
               AND invitation.expires_at > CURRENT_TIMESTAMP
               AND invitation.accepted_at IS NULL
               AND invitation.revoked_at IS NULL
             FOR UPDATE OF invitation, u'
        );
        $statement->execute(['id' => $invitationId]);
        $userId = $statement->fetchColumn();
        if ($userId === false) {
            throw new InvalidArgumentException('رابط الدعوة منتهي أو مستخدم أو غير صالح. اطلب دعوة جديدة من الإدارة.');
        }
        $pdo->prepare('UPDATE users SET password_hash = :password_hash, is_active = TRUE WHERE id = :user_id')
            ->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'user_id' => $userId]);
        $pdo->prepare('UPDATE staff_invitations SET accepted_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $invitationId]);
        record_audit_event($pdo, (int) $userId, 'staff.invitation.accepted', 'user', (int) $userId, ['invitation_id' => $invitationId]);
        $pdo->commit();
        return (int) $userId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function set_staff_account_active(int $targetUserId, int $actorId, bool $active): bool
{
    if ($targetUserId < 1 || $targetUserId === $actorId) {
        throw new InvalidArgumentException('لا يمكن تغيير حالة حسابك من هذه الصفحة.');
    }
    $canManageRoles = user_has_permission('roles.manage');
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $target = $pdo->prepare('SELECT id, CASE WHEN is_active THEN 1 ELSE 0 END AS account_active FROM users WHERE id = :id FOR UPDATE');
        $target->execute(['id' => $targetUserId]);
        $user = $target->fetch();
        if (!$user) {
            throw new InvalidArgumentException('الحساب غير موجود.');
        }
        $rolesQuery = $pdo->prepare(
            "SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.code IN ('teacher', 'content_reviewer', 'admin', 'registrar')"
        );
        $rolesQuery->execute(['user_id' => $targetUserId]);
        $roles = $rolesQuery->fetchAll(PDO::FETCH_COLUMN);
        if (!$roles) {
            throw new InvalidArgumentException('يمكن لهذه الصفحة إدارة حسابات الفريق فقط.');
        }
        if ($active) {
            $pendingInvitation = $pdo->prepare(
                'SELECT 1 FROM staff_invitations
                 WHERE user_id = :user_id AND accepted_at IS NULL AND revoked_at IS NULL
                 LIMIT 1'
            );
            $pendingInvitation->execute(['user_id' => $targetUserId]);
            if ($pendingInvitation->fetchColumn()) {
                throw new InvalidArgumentException('أكمل دعوة الحساب أولًا؛ لا يمكن تفعيل حساب لم يحدد كلمة مروره بعد.');
            }
        }
        if (!$canManageRoles && array_diff($roles, ['teacher'])) {
            throw new InvalidArgumentException('يمكن لمراجع المحتوى إدارة حسابات الأساتذة فقط.');
        }
        if (((int) $user['account_active'] === 1) === $active) {
            $pdo->commit();
            return false;
        }
        if (!$active && in_array('admin', $roles, true)) {
            $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext('active_admins_guard'))")->execute();
            $adminCount = (int) $pdo->query(
                "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.code='admin' AND u.is_active"
            )->fetchColumn();
            if ($adminCount <= 1) {
                throw new InvalidArgumentException('لا يمكن تعطيل آخر مدير نشط في النظام.');
            }
        }
        $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id')
            ->execute(['active' => $active ? 'true' : 'false', 'id' => $targetUserId]);
        if (!$active) {
            $pdo->prepare('UPDATE staff_invitations SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = :id AND accepted_at IS NULL AND revoked_at IS NULL')
                ->execute(['id' => $targetUserId]);
        }
        record_audit_event($pdo, $actorId, $active ? 'staff.account.enabled' : 'staff.account.disabled', 'user', $targetUserId, ['roles' => $roles]);
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function delete_inactive_staff_account(int $targetUserId, int $actorId, string $actorPassword): void
{
    if ($targetUserId < 1 || $targetUserId === $actorId) {
        throw new InvalidArgumentException('لا يمكن حذف حسابك أو اختيار حساب غير صالح.');
    }
    if (!user_has_permission('roles.manage')) {
        throw new InvalidArgumentException('حذف حسابات الفريق متاح لمدير النظام فقط.');
    }

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $actorQuery = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active FOR UPDATE');
        $actorQuery->execute(['id' => $actorId]);
        $actorHash = $actorQuery->fetchColumn();
        if (!is_string($actorHash) || !password_verify($actorPassword, $actorHash)) {
            throw new InvalidArgumentException('كلمة مرور مدير النظام غير صحيحة.');
        }

        $targetQuery = $pdo->prepare('SELECT is_active FROM users WHERE id = :id FOR UPDATE');
        $targetQuery->execute(['id' => $targetUserId]);
        $target = $targetQuery->fetch();
        if (!$target) {
            throw new InvalidArgumentException('الحساب غير موجود.');
        }
        if (database_boolean($target['is_active'])) {
            throw new InvalidArgumentException('لا يمكن حذف حساب نشط. عطّل الحساب أولًا ثم أعد المحاولة.');
        }

        $rolesQuery = $pdo->prepare(
            "SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.code IN ('teacher', 'content_reviewer', 'admin', 'registrar')"
        );
        $rolesQuery->execute(['user_id' => $targetUserId]);
        $roles = $rolesQuery->fetchAll(PDO::FETCH_COLUMN);
        if (!$roles) {
            throw new InvalidArgumentException('يمكن لهذه الصفحة حذف حسابات فريق العمل فقط.');
        }

        $pendingInvitation = $pdo->prepare(
            'SELECT 1 FROM staff_invitations
             WHERE user_id = :user_id AND accepted_at IS NULL AND revoked_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $pendingInvitation->execute(['user_id' => $targetUserId]);
        if ($pendingInvitation->fetchColumn()) {
            throw new InvalidArgumentException('لا يمكن حذف حساب لديه دعوة صالحة. أعد المحاولة بعد انتهاء الدعوة أو ألغها بتجديد الدعوة.');
        }

        $employeeQuery = $pdo->prepare('SELECT id FROM employees WHERE user_id = :user_id FOR UPDATE');
        $employeeQuery->execute(['user_id' => $targetUserId]);
        $employeeId = $employeeQuery->fetchColumn();
        if ($employeeId === false) {
            throw new InvalidArgumentException('ملف الموظف المرتبط بالحساب غير موجود.');
        }

        record_audit_event($pdo, $actorId, 'staff.account.deleted', 'user', $targetUserId, ['roles' => $roles]);
        $pdo->prepare('DELETE FROM employees WHERE id = :id')->execute(['id' => $employeeId]);
        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id AND is_active = FALSE');
        $deleteUser->execute(['id' => $targetUserId]);
        if ($deleteUser->rowCount() !== 1) {
            throw new InvalidArgumentException('تعذر حذف الحساب؛ تحقق من حالته ثم أعد المحاولة.');
        }
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error->getCode() === '23503') {
            throw new InvalidArgumentException('لا يمكن حذف الحساب لوجود بيانات مرتبطة به. احتفظ بالحساب معطلًا حتى لا تُفقد سجلات المنصة.');
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
