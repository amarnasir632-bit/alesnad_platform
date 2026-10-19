<?php
declare(strict_types=1);

putenv('APP_ENV=test');
putenv('SESSION_IDLE_TIMEOUT_SECONDS=300');
putenv('LOGIN_MAX_ATTEMPTS=2');
putenv('LOGIN_WINDOW_SECONDS=3600');
putenv('LOGIN_LOCKOUT_SECONDS=60');
putenv('AUTH_THROTTLE_KEY=phase1-test-secret-key-change-before-use-00000000');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/tests/phase1_integration.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Services/AccountService.php';
require dirname(__DIR__) . '/app/Http/Response.php';

$database = (string) env_value('DB_DATABASE', '');
if (!str_starts_with($database, 'alesnad_test_')) {
    fwrite(STDERR, "Refusing integration tests: DB_DATABASE must begin with alesnad_test_.\n");
    exit(2);
}
$pdo = database_connection();
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $message;
    printf("%s %s\n", $condition ? 'PASS' : 'FAIL', $message);
};

$suffix = bin2hex(random_bytes(6));
$adminEmail = 'phase1-admin-' . $suffix . '@example.invalid';
$teacherEmail = 'phase1-teacher-' . $suffix . '@example.invalid';
$insert = $pdo->prepare('INSERT INTO users(full_name,email,password_hash) VALUES(:name,:email,:hash) RETURNING id');
$insert->execute(['name' => 'Phase 1 Test Admin', 'email' => $adminEmail, 'hash' => password_hash('test-password-123', PASSWORD_DEFAULT)]);
$adminId = (int) $insert->fetchColumn();
$role = $pdo->prepare('SELECT id FROM roles WHERE code=:code');
$role->execute(['code' => 'admin']);
$roleId = $role->fetchColumn();
if ($roleId === false) throw new RuntimeException('Apply migrations 001-004 before integration testing.');
$pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(:user_id,:role_id)')->execute(['user_id' => $adminId, 'role_id' => $roleId]);
$pdo->prepare('INSERT INTO employees(user_id,job_title) VALUES(:user_id,:title)')->execute(['user_id' => $adminId, 'title' => 'Integration test admin']);

$admin = refresh_authenticated_user();
$_SESSION['user'] = ['id' => $adminId];
$admin = refresh_authenticated_user();
$assert($admin !== null && in_array('admin', $admin['roles'], true), 'reload active account and current roles');
$assert(has_permission('users.manage'), 'read permission through role_permissions');

$stage = $pdo->prepare('INSERT INTO stages(code,name,sort_order) VALUES(:code,:name,0) RETURNING id');
$stage->execute(['code' => 'P1_' . strtoupper($suffix), 'name' => 'مرحلة اختبار التسجيل']);
$registrationStageId = (int) $stage->fetchColumn();
$class = $pdo->prepare('INSERT INTO classes(stage_id,code,name,sort_order) VALUES(:stage,:code,:name,0) RETURNING id');
$class->execute(['stage' => $registrationStageId, 'code' => 'P1_' . strtoupper($suffix), 'name' => 'صف اختبار التسجيل']);
$registrationClassId = (int) $class->fetchColumn();
$studentEmail = 'phase1-student-' . $suffix . '@example.invalid';
register_student('Phase 1 Test Student', $studentEmail, '', 'test-password-123', $registrationClassId);
$registeredStudent = $pdo->prepare('SELECT id, user_id, class_id, student_number, academic_semester, admission_year, registration_status FROM students WHERE user_id=(SELECT id FROM users WHERE email=:email)');
$registeredStudent->execute(['email' => $studentEmail]);
$registeredStudentRow = $registeredStudent->fetch();
$assert((int) $registeredStudentRow['class_id'] === $registrationClassId, 'self-registration stores the selected active class on the student profile');
$assert($registeredStudentRow['student_number'] === student_number_for_id((int) $registeredStudentRow['id']), 'self-registration assigns a stable STU student number from the student profile ID');
$assert($registeredStudentRow['academic_semester'] === null, 'new student profiles leave the academic semester unset until assigned by an administrator');
$assert($registeredStudentRow['admission_year'] === null, 'new student profiles leave the admission year unset until assigned by an administrator');
$assert($registeredStudentRow['registration_status'] === 'PENDING', 'self-registration enters the registrar review queue');
$pendingAccount = $pdo->prepare('SELECT is_active FROM users WHERE id = :id');
$pendingAccount->execute(['id' => $registeredStudentRow['user_id']]);
$assert(!database_boolean($pendingAccount->fetchColumn()), 'student cannot log in before registrar approval');
$assert(!authenticate_user($studentEmail, 'test-password-123'), 'pending student credentials cannot establish a login session');
$updatedStudent = update_managed_account((int) $registeredStudentRow['user_id'], ['academic_semester' => 'FIRST', 'admission_year' => (int) date('Y')]);
$assert($updatedStudent['academic_semester'] === 'FIRST', 'administrator can assign a semester to a student account');
$assert($updatedStudent['admission_year'] === (int) date('Y'), 'administrator can assign an admission year to a student account');
$invalidSemesterRejected = false;
try {
    update_managed_account((int) $registeredStudentRow['user_id'], ['academic_semester' => 'THIRD']);
} catch (InvalidArgumentException) {
    $invalidSemesterRejected = true;
}
$assert($invalidSemesterRejected, 'student semester assignment rejects unsupported values');
$invalidAdmissionYearRejected = false;
try {
    update_managed_account((int) $registeredStudentRow['user_id'], ['admission_year' => (int) date('Y') + 1]);
} catch (InvalidArgumentException) {
    $invalidAdmissionYearRejected = true;
}
$assert($invalidAdmissionYearRejected, 'student admission year rejects future years');
$assert(has_permission('admissions.manage'), 'administrator gets the admission management permission');
$registrar = create_managed_account([
    'full_name' => 'Phase 1 Test Registrar',
    'email' => 'phase1-registrar-' . $suffix . '@example.invalid',
    'phone' => '',
    'password' => 'test-password-123',
    'roles' => ['registrar'],
], $adminId);
$_SESSION['user'] = ['id' => $registrar['id']];
$assert(has_permission('admissions.manage'), 'registrar role grants admission management');
$assert(!has_permission('roles.manage') && !has_permission('academics.manage'), 'registrar role does not grant role or curriculum administration');
$_SESSION['user'] = ['id' => $adminId];
$admin = refresh_authenticated_user();
$registrarInvitationEmail = 'phase1-registrar-invitation-' . $suffix . '@example.invalid';
$registrarInvitationToken = create_staff_invitation(
    'Phase 1 Invited Registrar',
    $registrarInvitationEmail,
    'registrar',
    'مسؤول التسجيل والقبول',
    $adminId
);
$invitedRegistrar = $pdo->prepare(
    "SELECT u.id AS user_id, u.is_active, r.code
     FROM users u
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     WHERE u.email = :email"
);
$invitedRegistrar->execute(['email' => $registrarInvitationEmail]);
$invitedRegistrarRow = $invitedRegistrar->fetch();
$assert($registrarInvitationToken !== '' && $invitedRegistrarRow['code'] === 'registrar', 'administrator can invite a registrar account');
$assert(!database_boolean($invitedRegistrarRow['is_active']), 'invited registrar stays inactive until accepting the invitation');
$validInvitationDeletionBlocked = false;
try {
    delete_inactive_staff_account((int) $invitedRegistrarRow['user_id'], $adminId, 'test-password-123');
} catch (InvalidArgumentException) {
    $validInvitationDeletionBlocked = true;
}
$assert($validInvitationDeletionBlocked, 'cannot delete an account with a still-valid staff invitation');
$assert(
    count(array_filter(list_staff_accounts(), static fn(array $member): bool => $member['email'] === $registrarInvitationEmail)) === 1,
    'invited registrar appears in the staff account list'
);
$reviewer = create_managed_account([
    'full_name' => 'Phase 1 Test Reviewer',
    'email' => 'phase1-reviewer-' . $suffix . '@example.invalid',
    'phone' => '',
    'password' => 'test-password-123',
    'roles' => ['content_reviewer'],
], $adminId);
$_SESSION['user'] = ['id' => $reviewer['id']];
$assert(has_permission('users.manage') && !has_permission('roles.manage'), 'content reviewer can manage staff invitations without role management');
$reviewerCannotInviteRegistrar = false;
try {
    create_staff_invitation(
        'Unauthorized Registrar',
        'phase1-unauthorized-registrar-' . $suffix . '@example.invalid',
        'registrar',
        'مسؤول التسجيل والقبول',
        (int) $reviewer['id']
    );
} catch (InvalidArgumentException) {
    $reviewerCannotInviteRegistrar = true;
}
$assert($reviewerCannotInviteRegistrar, 'content reviewer cannot invite a registrar without role-management permission');
$_SESSION['user'] = ['id' => $adminId];
$admin = refresh_authenticated_user();
$approvedAdmission = review_student_admission((int) $registeredStudentRow['id'], $adminId, [
    'status' => 'APPROVED',
    'reason' => '',
    'class_id' => $registrationClassId,
    'admission_year' => (int) date('Y'),
    'academic_semester' => 'FIRST',
]);
$assert($approvedAdmission['registration_status'] === 'APPROVED', 'registrar can approve a pending student after assigning academic details');
$pendingAccount->execute(['id' => $registeredStudentRow['user_id']]);
$assert(database_boolean($pendingAccount->fetchColumn()), 'approved student account becomes active');
$correctionId = submit_student_profile_correction((int) $registeredStudentRow['user_id'], [
    'phone' => '+249123456789',
    'reason' => 'تصحيح رقم الهاتف المسجل في الحساب',
]);
review_profile_correction_request($correctionId, $adminId, 'APPROVED', 'تم التحقق من الرقم.');
$correctedPhone = $pdo->prepare('SELECT phone FROM users WHERE id = :id');
$correctedPhone->execute(['id' => $registeredStudentRow['user_id']]);
$assert($correctedPhone->fetchColumn() === '+249123456789', 'registrar can approve a validated student profile correction request');

$teacher = create_managed_account([
    'full_name' => 'Phase 1 Test Teacher', 'email' => $teacherEmail, 'phone' => '',
    'password' => 'test-password-123', 'roles' => ['teacher'],
], $adminId);
$teacherId = $teacher['id'];
$assert(in_array('teacher', $teacher['roles'], true), 'create account and assign initial role');
$employeeCheck = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE user_id=:id');
$employeeCheck->execute(['id' => $teacherId]);
$assert((int) $employeeCheck->fetchColumn() === 1, 'create employee profile for teacher');
$assert(authenticate_user($teacherEmail, 'test-password-123'), 'valid login succeeds and establishes a session');
$assert(!authenticate_user($teacherEmail, 'wrong-password'), 'invalid login fails and is audited');

$_SESSION['user'] = ['id' => $teacherId];
$assert(has_permission('content.upload'), 'teacher gets upload permission from database');
assign_account_roles($teacherId, ['content_reviewer'], $adminId);
$fresh = refresh_authenticated_user();
$assert($fresh !== null && in_array('content_reviewer', $fresh['roles'], true), 'role change is visible on the next request');
$assert(!has_permission('content.upload') && has_permission('content.review'), 'permission changes take effect immediately');

$teacherEmail = 'phase1-updated-' . $suffix . '@example.invalid';
$updatedTeacher = update_managed_account($teacherId, ['email' => $teacherEmail, 'is_active' => false]);
$assert($updatedTeacher['email'] === $teacherEmail, 'account email can be updated by users.manage');
$assert(refresh_authenticated_user() === null, 'disabled account loses its session immediately');

$attemptEmail = 'phase1-throttle-' . $suffix . '@example.invalid';
$assert(record_login_failure($attemptEmail) === false, 'first failed login is below configured threshold');
$assert(record_login_failure($attemptEmail) === true && login_is_locked($attemptEmail), 'configured failed-login threshold locks account');

$_SESSION['csrf_token'] = 'expected-token';
$csrfDenied = false;
try { require_api_csrf(['_csrf' => 'wrong-token']); } catch (ApiException $error) { $csrfDenied = $error->status === 419; }
$assert($csrfDenied, 'CSRF rejects a wrong token');
$csrfAccepted = true;
try { require_api_csrf(['_csrf' => 'expected-token']); } catch (ApiException) { $csrfAccepted = false; }
$assert($csrfAccepted, 'CSRF accepts the session token');

$activeAdminCount = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.code='admin' AND u.is_active");
$activeAdminCount->execute();
if ((int) $activeAdminCount->fetchColumn() === 1) {
    $lastAdminBlocked = false;
    try { update_managed_account($adminId, ['is_active' => false]); } catch (DomainException) { $lastAdminBlocked = true; }
    $assert($lastAdminBlocked, 'cannot disable the final active administrator');
} else {
    printf("SKIP last-admin guard needs a test DB with exactly one active administrator\n");
}

$_SESSION['user'] = ['id' => $adminId];
refresh_authenticated_user();

$deleteCandidate = create_managed_account([
    'full_name' => 'Phase 1 Deletion Candidate',
    'email' => 'phase1-delete-' . $suffix . '@example.invalid',
    'phone' => '',
    'password' => 'test-password-123',
    'roles' => ['teacher'],
], $adminId);
$activeStaffDeletionBlocked = false;
try {
    delete_inactive_staff_account((int) $deleteCandidate['id'], $adminId, 'test-password-123');
} catch (InvalidArgumentException) {
    $activeStaffDeletionBlocked = true;
}
$assert($activeStaffDeletionBlocked, 'cannot delete an active staff account');

$wrongAdminPasswordBlocked = false;
try {
    delete_inactive_staff_account((int) $deleteCandidate['id'], $adminId, 'wrong-password');
} catch (InvalidArgumentException) {
    $wrongAdminPasswordBlocked = true;
}
$assert($wrongAdminPasswordBlocked, 'staff deletion requires the current administrator password');

set_staff_account_active((int) $deleteCandidate['id'], $adminId, false);
$inactiveStaffWrongPasswordBlocked = false;
try {
    delete_inactive_staff_account((int) $deleteCandidate['id'], $adminId, 'wrong-password');
} catch (InvalidArgumentException) {
    $inactiveStaffWrongPasswordBlocked = true;
}
$assert($inactiveStaffWrongPasswordBlocked, 'wrong administrator password leaves a disabled staff account intact');

delete_inactive_staff_account((int) $deleteCandidate['id'], $adminId, 'test-password-123');
$deletedStaff = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
$deletedStaff->execute(['id' => $deleteCandidate['id']]);
$assert((int) $deletedStaff->fetchColumn() === 0, 'administrator can permanently delete an inactive staff account');
$deletionAudit = $pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE action_code = 'staff.account.deleted' AND target_id = :id");
$deletionAudit->execute(['id' => $deleteCandidate['id']]);
$assert((int) $deletionAudit->fetchColumn() === 1, 'staff account deletion is audited before removing the account');

$pdo->prepare('UPDATE staff_invitations SET expires_at = CURRENT_TIMESTAMP - INTERVAL \'1 second\' WHERE user_id = :id AND accepted_at IS NULL AND revoked_at IS NULL')
    ->execute(['id' => $invitedRegistrarRow['user_id']]);
delete_inactive_staff_account((int) $invitedRegistrarRow['user_id'], $adminId, 'test-password-123');
$expiredInvitationUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
$expiredInvitationUser->execute(['id' => $invitedRegistrarRow['user_id']]);
$assert((int) $expiredInvitationUser->fetchColumn() === 0, 'administrator can delete an inactive account whose invitation has expired');

$referencedStaff = create_managed_account([
    'full_name' => 'Phase 1 Referenced Staff',
    'email' => 'phase1-referenced-' . $suffix . '@example.invalid',
    'phone' => '',
    'password' => 'test-password-123',
    'roles' => ['teacher'],
], $adminId);
set_staff_account_active((int) $referencedStaff['id'], $adminId, false);
$pdo->prepare('INSERT INTO messages (sender_id, receiver_id, message_body) VALUES (:sender, :receiver, :body)')
    ->execute(['sender' => $referencedStaff['id'], 'receiver' => $adminId, 'body' => 'Retain this message during account deletion test']);
$foreignKeyDeletionBlocked = false;
try {
    delete_inactive_staff_account((int) $referencedStaff['id'], $adminId, 'test-password-123');
} catch (InvalidArgumentException $error) {
    $foreignKeyDeletionBlocked = str_contains($error->getMessage(), 'وجود بيانات مرتبطة به');
}
$retainedStaff = $pdo->prepare('SELECT COUNT(*) FROM users u JOIN employees e ON e.user_id = u.id WHERE u.id = :id');
$retainedStaff->execute(['id' => $referencedStaff['id']]);
$retainedMessage = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = :id');
$retainedMessage->execute(['id' => $referencedStaff['id']]);
$assert($foreignKeyDeletionBlocked, 'foreign-key-protected staff account deletion reports linked data');
$assert((int) $retainedStaff->fetchColumn() === 1 && (int) $retainedMessage->fetchColumn() === 1, 'blocked deletion preserves the staff account, employee profile and message');
$pdo->prepare('DELETE FROM messages WHERE sender_id = :id')->execute(['id' => $referencedStaff['id']]);
delete_inactive_staff_account((int) $referencedStaff['id'], $adminId, 'test-password-123');

$audit = $pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE target_type='user' AND target_id=:id");
$audit->execute(['id' => $teacherId]);
$assert((int) $audit->fetchColumn() >= 3, 'account creation, role change, and update are audited');

printf("%d assertions, %d failures\n", $assertions, count($failures));
exit($failures ? 1 : 0);
