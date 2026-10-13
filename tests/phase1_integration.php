<?php
declare(strict_types=1);

ob_start();
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

$audit = $pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE target_type='user' AND target_id=:id");
$audit->execute(['id' => $teacherId]);
$assert((int) $audit->fetchColumn() >= 3, 'account creation, role change, and update are audited');

printf("%d assertions, %d failures\n", $assertions, count($failures));
exit($failures ? 1 : 0);
