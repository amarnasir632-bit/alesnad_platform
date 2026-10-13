<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';
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
if (strlen($password) < 16 || !hash_equals($password, $confirmation)) {
    fwrite(STDERR, "كلمة المرور قصيرة أو غير متطابقة.\n");
    exit(1);
}

$pdo = database_connection();
$existingAdmin = $pdo->query("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'admin' LIMIT 1")->fetchColumn();
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
    $adminRoleId = $pdo->query("SELECT id FROM roles WHERE code = 'admin'")->fetchColumn();
    if ($adminRoleId === false) {
        throw new RuntimeException('Admin role is missing. Apply database migrations first.');
    }
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)')
        ->execute(['user_id' => $userId, 'role_id' => $adminRoleId]);
    $pdo->commit();
    fwrite(STDOUT, "تم إنشاء حساب مدير النظام. احتفظ ببياناته خارج المستودع.\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "فشل إنشاء حساب مدير النظام. تحقق من البريد ومن تطبيق الترحيلات.\n");
    error_log('Admin bootstrap failed: ' . $error->getMessage());
    exit(1);
}
