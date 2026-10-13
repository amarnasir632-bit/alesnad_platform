<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Support/logger.php';
require_once dirname(__DIR__) . '/app/Support/config.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';
require_once dirname(__DIR__) . '/app/Security/audit.php';
require_once dirname(__DIR__) . '/app/Security/passwords.php';
require_once dirname(__DIR__) . '/app/Security/passwords.php';
load_environment(dirname(__DIR__) . '/.env');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the command line.\n");
    exit(1);
}

if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Run this command in an interactive terminal so the password can be entered safely.\n");
    exit(1);
}

function ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    return trim((string) fgets(STDIN));
}

function ask_secret(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    exec('stty -echo');
    try {
        $secret = rtrim((string) fgets(STDIN), "\r\n");
    } finally {
        exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $secret;
}

$name = ask('اسم مدير النظام: ');
$email = strtolower(ask('البريد الإلكتروني: '));
$password = ask_secret('كلمة مرور قوية (16 حرفًا على الأقل): ');
$confirmation = ask_secret('أعد كتابة كلمة المرور: ');

if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "الاسم أو البريد الإلكتروني غير صحيح.\n");
    exit(1);
}
if (password_validation_error($password) !== null || !hash_equals($password, $confirmation)) {
    fwrite(STDERR, "كلمة المرور غير مطابقة لسياسة النظام أو أن التأكيد غير مطابق.\n");
    exit(1);
}

$pdo = database_connection();
$adminCheck = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = :code LIMIT 1");
$adminCheck->execute(['code' => 'admin']);
$existingAdmin = $adminCheck->fetchColumn();
if ($existingAdmin) {
    fwrite(STDERR, "يوجد حساب مدير نظام بالفعل. لم يُنشأ حساب جديد.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $insertUser = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash)
         VALUES (:full_name, :email, :password_hash)
         RETURNING id'
    );
    $insertUser->execute([
        'full_name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = $insertUser->fetchColumn();

    $pdo->prepare('INSERT INTO employees (user_id, job_title) VALUES (:user_id, :job_title)')
        ->execute(['user_id' => $userId, 'job_title' => 'مدير النظام']);
    $adminRole = $pdo->prepare('SELECT id FROM roles WHERE code = :code');
    $adminRole->execute(['code' => 'admin']);
    $adminRoleId = $adminRole->fetchColumn();
    if ($adminRoleId === false) {
        throw new RuntimeException('Admin role is missing. Apply database migrations first.');
    }
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)')
        ->execute(['user_id' => $userId, 'role_id' => $adminRoleId]);
    audit_log('account.created', 'user', (int) $userId, ['roles' => ['admin']], (int) $userId);
    $pdo->commit();
    fwrite(STDOUT, "تم إنشاء حساب مدير النظام. احتفظ ببياناته خارج المستودع.\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "فشل إنشاء حساب مدير النظام. تحقق من البريد ومن تطبيق الترحيلات.\n");
    app_log_error('admin_bootstrap_failed', $error);
    exit(1);
}
