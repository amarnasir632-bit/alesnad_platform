<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/env.php';
require dirname(__DIR__) . '/app/Database/connection.php';
load_environment(dirname(__DIR__) . '/.env');

$baseUrl = rtrim(env_value('TEST_BASE_URL', 'http://127.0.0.1:8000') ?? '', '/');
$databaseName = env_value('DB_DATABASE', '') ?? '';
if (!str_starts_with($databaseName, 'alesnad_test_')) {
    fwrite(STDERR, "Refusing endpoint integration tests: DB_DATABASE must begin with alesnad_test_.\n");
    exit(2);
}
$pdo = database_connection();
$suffix = bin2hex(random_bytes(6));
$password = 'ci-test-password-42';
$fixtureUsers = [];
$fixtureEmployeeIds = [];
$fixtureStudentId = null;
$fixtureSubjectId = null;
$fixtureClassSubjectId = null;
$fixturePlanId = null;
$apiPlanCode = null;
$fixtureSubscriptionId = null;
$fixtureDirectFile = null;
$assertions = 0;
$failures = [];

$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $label;
    printf("%s %s\n", $condition ? 'PASS' : 'FAIL', $label);
};

$httpRequest = static function (array &$client, string $method, string $path, ?array $body = null) use ($baseUrl): array {
    $headers = ['Accept: application/json'];
    if (($client['cookie'] ?? '') !== '') $headers[] = 'Cookie: ' . $client['cookie'];
    if (($client['csrf'] ?? '') !== '') $headers[] = 'X-CSRF-TOKEN: ' . $client['csrf'];
    $content = '';
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 12,
    ]]);
    $responseBody = file_get_contents($baseUrl . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s([1-5][0-9]{2})\s/', $responseHeaders[0], $match)) $status = (int) $match[1];
    foreach ($responseHeaders as $header) {
        if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) $client['cookie'] = trim($match[1]);
    }
    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
    if (is_array($decoded) && is_string($decoded['data']['csrf_token'] ?? null)) $client['csrf'] = $decoded['data']['csrf_token'];
    return [$status, is_array($decoded) ? $decoded : $responseBody];
};

try {
    $classQuery = $pdo->prepare("SELECT c.id FROM classes c JOIN stages s ON s.id=c.stage_id WHERE c.code='GRADE_6' ORDER BY c.id LIMIT 1");
    $classQuery->execute();
    $classId = (int) $classQuery->fetchColumn();
    if ($classId < 1) throw new RuntimeException('Migration seed GRADE_6 is required for endpoint authorization tests.');

    $usersInsert = $pdo->prepare('INSERT INTO users(full_name,email,password_hash) VALUES(:name,:email,:password) RETURNING id');
    $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE code=:code');
    $roleInsert = $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(:user,:role)');
    foreach (['admin', 'teacher', 'student', 'content_reviewer'] as $roleCode) {
        $email = 'phase8-' . $roleCode . '-' . $suffix . '@example.invalid';
        $usersInsert->execute(['name' => 'Phase 8 ' . $roleCode, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $usersInsert->fetchColumn();
        $roleQuery->execute(['code' => $roleCode]);
        $roleId = (int) $roleQuery->fetchColumn();
        $roleInsert->execute(['user' => $userId, 'role' => $roleId]);
        $fixtureUsers[$roleCode] = ['id' => $userId, 'email' => $email];
    }
    foreach (['teacher', 'content_reviewer'] as $employeeRole) {
        $employee = $pdo->prepare('INSERT INTO employees(user_id,job_title) VALUES(:user,:title) RETURNING id');
        $employee->execute(['user' => $fixtureUsers[$employeeRole]['id'], 'title' => 'CI ' . $employeeRole]);
        $fixtureEmployeeIds[$employeeRole] = (int) $employee->fetchColumn();
    }
    $pdo->prepare('INSERT INTO students(user_id,class_id) VALUES(:user,:class) RETURNING id')->execute(['user' => $fixtureUsers['student']['id'], 'class' => $classId]);
    $studentLookup = $pdo->prepare('SELECT id FROM students WHERE user_id=:user');
    $studentLookup->execute(['user' => $fixtureUsers['student']['id']]);
    $fixtureStudentId = (int) $studentLookup->fetchColumn();
    $subject = $pdo->prepare('INSERT INTO subjects(code,name) VALUES(:code,:name) RETURNING id');
    $subject->execute(['code' => 'PHASE8_' . strtoupper($suffix), 'name' => 'Phase 8 ' . $suffix]);
    $fixtureSubjectId = (int) $subject->fetchColumn();
    $mapping = $pdo->prepare('INSERT INTO class_subjects(class_id,subject_id) VALUES(:class,:subject) RETURNING id');
    $mapping->execute(['class' => $classId, 'subject' => $fixtureSubjectId]);
    $fixtureClassSubjectId = (int) $mapping->fetchColumn();
    $pdo->prepare('INSERT INTO teaching_assignments(employee_id,class_subject_id) VALUES(:employee,:mapping)')
        ->execute(['employee' => $fixtureEmployeeIds['teacher'], 'mapping' => $fixtureClassSubjectId]);

    $plan = $pdo->prepare("INSERT INTO subscription_plans(code,name,duration_days,is_active) VALUES(:code,:name,30,TRUE) RETURNING id");
    $plan->execute(['code' => 'PHASE8_' . strtoupper($suffix), 'name' => 'Phase 8 test plan']);
    $fixturePlanId = (int) $plan->fetchColumn();
    $sub = $pdo->prepare("INSERT INTO subscriptions(student_id,plan_id,starts_at,ends_at,amount_paid,currency,status) VALUES(:student,:plan,CURRENT_TIMESTAMP - INTERVAL '1 minute',CURRENT_TIMESTAMP + INTERVAL '1 day',0,'USD','PENDING') RETURNING id");
    $sub->execute(['student' => $fixtureStudentId, 'plan' => $fixturePlanId]);
    $fixtureSubscriptionId = (int) $sub->fetchColumn();

    $clients = [];
    foreach ($fixtureUsers as $role => $user) {
        $client = ['cookie' => '', 'csrf' => ''];
        [$csrfStatus, $csrfBody] = $httpRequest($client, 'GET', '/api/v1/csrf');
        $csrf = $csrfBody['data']['csrf_token'] ?? '';
        $client['csrf'] = is_string($csrf) ? $csrf : '';
        [$loginStatus, $loginBody] = $httpRequest($client, 'POST', '/api/v1/auth/login', ['email' => $user['email'], 'password' => $password]);
        $assert($csrfStatus === 200 && $loginStatus === 200, "fixture login succeeds for {$role}");
        $clients[$role] = $client;
    }

    // Every entry exercises the real HTTP router and controller authorization guard.
    // The authorized request may reach validation or a not-found branch; it must not be rejected by auth/CSRF.
    $apiPlanCode = 'API_' . strtoupper($suffix);
    $cases = [
        ['GET', '/api/v1/admin/users', 'admin', 'student'],
        ['GET', '/api/v1/admin/roles', 'admin', 'student'],
        ['POST', '/api/v1/admin/users', 'admin', 'student', []],
        ['PATCH', '/api/v1/admin/users/999999', 'admin', 'student', []],
        ['PUT', '/api/v1/admin/users/999999/roles', 'admin', 'student', ['roles' => ['teacher']]],
        ['GET', '/api/v1/academics/stages?include_inactive=true', 'admin', 'student'],
        ['GET', '/api/v1/academics/classes?include_inactive=true', 'admin', 'student'],
        ['GET', '/api/v1/academics/subjects?include_inactive=true', 'admin', 'student'],
        ['GET', '/api/v1/academics/lessons', 'student', 'content_reviewer'],
        ['GET', '/api/v1/academics/my-teaching-assignments', 'teacher', 'student'],
        ['GET', '/api/v1/content/search', 'student', 'content_reviewer'],
        ['POST', '/api/v1/admin/academics/stages', 'admin', 'student', []],
        ['POST', '/api/v1/admin/academics/classes', 'admin', 'student', []],
        ['POST', '/api/v1/admin/academics/subjects', 'admin', 'student', []],
        ['POST', '/api/v1/admin/academics/class-subjects', 'admin', 'student', []],
        ['POST', '/api/v1/admin/academics/lessons', 'admin', 'student', ['is_published' => true]],
        ['PATCH', '/api/v1/admin/academics/stages/999999', 'admin', 'student', []],
        ['PATCH', '/api/v1/admin/academics/classes/999999', 'admin', 'student', []],
        ['PATCH', '/api/v1/admin/academics/subjects/999999', 'admin', 'student', []],
        ['PATCH', '/api/v1/admin/academics/class-subjects/999999', 'admin', 'student', []],
        ['PATCH', '/api/v1/admin/academics/lessons/999999', 'admin', 'student', ['is_published' => true]],
        ['POST', '/api/v1/admin/teaching-assignments', 'admin', 'student', ['employee_id' => 999999, 'class_subject_id' => 999999]],
        ['DELETE', '/api/v1/admin/teaching-assignments/999999/999999', 'admin', 'student'],
        ['GET', '/api/v1/teacher/questions', 'teacher', 'student'],
        ['POST', '/api/v1/teacher/quizzes', 'teacher', 'student', []],
        ['PATCH', '/api/v1/teacher/quizzes/999999', 'teacher', 'student', []],
        ['GET', '/api/v1/teacher/quizzes/999999/results', 'teacher', 'student'],
        ['GET', '/api/v1/quizzes', 'student', 'teacher'],
        ['GET', '/api/v1/quizzes/999999', 'student', 'teacher'],
        ['POST', '/api/v1/quizzes/999999/attempts', 'student', 'teacher', []],
        ['PUT', '/api/v1/attempts/999999/answers/999999', 'student', 'teacher', []],
        ['POST', '/api/v1/attempts/999999/submit', 'student', 'teacher', []],
        ['GET', '/api/v1/attempts/999999/result', 'student', 'content_reviewer'],
        ['GET', '/api/v1/attempts/999999/result', 'teacher', 'content_reviewer'],
        ['POST', '/api/v1/teacher/attempts/999999/questions/999999/grade', 'teacher', 'student', []],
        ['POST', '/api/v1/content/resources', 'teacher', 'student', []],
        ['POST', '/api/v1/content/past-exams', 'teacher', 'student', []],
        ['POST', '/api/v1/content/questions', 'teacher', 'student', []],
        ['PATCH', '/api/v1/content/resources/999999', 'teacher', 'student', []],
        ['PATCH', '/api/v1/content/past-exams/999999', 'teacher', 'student', []],
        ['PATCH', '/api/v1/content/questions/999999', 'teacher', 'student', []],
        ['POST', '/api/v1/content/resources/999999/replace-file', 'teacher', 'student', []],
        ['POST', '/api/v1/content/past-exams/999999/replace-file', 'teacher', 'student', []],
        ['POST', '/api/v1/content/resources/999999/submit', 'teacher', 'student', []],
        ['POST', '/api/v1/content/past-exams/999999/submit', 'teacher', 'student', []],
        ['POST', '/api/v1/content/questions/999999/submit', 'teacher', 'student', []],
        ['POST', '/api/v1/admin/content/resources/999999/review', 'content_reviewer', 'teacher', ['status' => 'APPROVED', 'feedback' => 'test']],
        ['POST', '/api/v1/admin/content/past-exams/999999/review', 'content_reviewer', 'teacher', ['status' => 'APPROVED', 'feedback' => 'test']],
        ['POST', '/api/v1/admin/content/questions/999999/review', 'content_reviewer', 'teacher', ['status' => 'APPROVED', 'feedback' => 'test']],
        ['GET', '/api/v1/content/resources/999999/download', 'student', 'content_reviewer'],
        ['GET', '/api/v1/content/resources/999999/variants/999999/download', 'student', 'content_reviewer'],
        ['GET', '/api/v1/content/past-exams/999999/download', 'student', 'content_reviewer'],
        ['GET', '/api/v1/sessions', 'student', 'content_reviewer'],
        ['POST', '/api/v1/teacher/sessions', 'teacher', 'student', []],
        ['PATCH', '/api/v1/teacher/sessions/999999', 'teacher', 'student', []],
        ['POST', '/api/v1/sessions/999999/join', 'student', 'content_reviewer', []],
        ['GET', '/api/v1/sessions/999999/recordings', 'student', 'content_reviewer'],
        ['POST', '/api/v1/teacher/sessions/999999/recordings', 'teacher', 'student', []],
        ['GET', '/api/v1/session-recordings/999999/download', 'student', 'content_reviewer'],
        ['GET', '/api/v1/messages?peer_id=' . $fixtureUsers['teacher']['id'], 'student', 'content_reviewer'],
        ['POST', '/api/v1/messages', 'student', 'content_reviewer', ['peer_id' => $fixtureUsers['teacher']['id'], 'message_body' => 'phase 8 authorization test']],
        ['PATCH', '/api/v1/messages/999999/read', 'student', 'content_reviewer'],
        ['DELETE', '/api/v1/messages/999999', 'student', 'content_reviewer'],
        ['GET', '/api/v1/subscription-plans', 'student', 'teacher'],
        ['GET', '/api/v1/subscriptions/me', 'student', 'teacher'],
        ['GET', '/api/v1/admin/subscription-plans', 'admin', 'student'],
        ['POST', '/api/v1/admin/subscription-plans', 'admin', 'student', ['code' => $apiPlanCode, 'name' => 'API phase 8 test plan', 'duration_days' => 30]],
        ['PATCH', '/api/v1/admin/subscription-plans/' . $fixturePlanId, 'admin', 'student', ['description' => 'authorization test']],
        ['GET', '/api/v1/admin/subscriptions', 'admin', 'student'],
        ['POST', '/api/v1/admin/subscriptions', 'admin', 'student', ['student_id' => $fixtureStudentId, 'plan_id' => $fixturePlanId, 'starts_at' => gmdate('Y-m-d\TH:i:s\Z'), 'amount_paid' => '0.00', 'currency' => 'USD', 'status' => 'PENDING']],
        ['PATCH', '/api/v1/admin/subscriptions/' . $fixtureSubscriptionId, 'admin', 'student', ['status' => 'ACTIVE']],
        ['POST', '/api/v1/admin/subscriptions/expire', 'admin', 'student', []],
    ];

    foreach ($cases as $case) {
        [$method, $path, $allowedRole, $deniedRole] = $case;
        $body = $case[4] ?? null;
        $authorizedClient = $clients[$allowedRole];
        [$authorizedStatus] = $httpRequest($authorizedClient, $method, $path, $body);
        $assert($authorizedStatus >= 200 && $authorizedStatus < 500 && !in_array($authorizedStatus, [401, 403, 419], true),
            "{$method} {$path}: allowed {$allowedRole} reaches endpoint (HTTP {$authorizedStatus})");
        $deniedClient = $clients[$deniedRole];
        [$deniedStatus] = $httpRequest($deniedClient, $method, $path, $body);
        $assert($deniedStatus === 403, "{$method} {$path}: denied {$deniedRole} receives 403 (HTTP {$deniedStatus})");
    }

    $authClient = $clients['student'];
    [$authMe] = $httpRequest($authClient, 'GET', '/api/v1/auth/me');
    $assert($authMe === 200, 'GET /api/v1/auth/me accepts an authenticated account');
    $anonymous = ['cookie' => '', 'csrf' => ''];
    [$anonymousMe] = $httpRequest($anonymous, 'GET', '/api/v1/auth/me');
    $assert($anonymousMe === 401, 'GET /api/v1/auth/me rejects an anonymous request');
    [$healthStatus] = $httpRequest($anonymous, 'GET', '/health');
    $assert($healthStatus === 200, 'GET /health returns healthy for a reachable PostgreSQL service');

    $fixtureDirectFile = bin2hex(random_bytes(32));
    $privateRoot = dirname(__DIR__) . '/storage/private';
    if (!is_dir($privateRoot)) mkdir($privateRoot, 0700, true);
    file_put_contents($privateRoot . '/' . $fixtureDirectFile, 'private-test-content', LOCK_EX);
    chmod($privateRoot . '/' . $fixtureDirectFile, 0600);
    $directClient = ['cookie' => '', 'csrf' => ''];
    [$directStatus] = $httpRequest($directClient, 'GET', '/storage/private/' . $fixtureDirectFile);
    $assert($directStatus === 404, 'private file cannot be fetched by direct public URL');

    printf("%d assertions, %d failures; %d protected endpoint entries\n", $assertions, count($failures), count($cases));
} catch (Throwable $error) {
    $failures[] = 'unexpected test exception: ' . $error->getMessage();
    fwrite(STDERR, "ERROR " . $error->getMessage() . "\n");
} finally {
    if ($fixtureDirectFile !== null) @unlink(dirname(__DIR__) . '/storage/private/' . $fixtureDirectFile);
    if ($fixtureUsers !== []) {
        $ids = array_column($fixtureUsers, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM messages WHERE sender_id IN ({$placeholders}) OR receiver_id IN ({$placeholders})")->execute(array_merge($ids, $ids));
        $pdo->prepare("DELETE FROM audit_events WHERE actor_user_id IN ({$placeholders})")->execute($ids);
        if ($fixtureSubscriptionId !== null) $pdo->prepare('DELETE FROM subscriptions WHERE id=?')->execute([$fixtureSubscriptionId]);
        if ($fixturePlanId !== null && $fixtureStudentId !== null) $pdo->prepare('DELETE FROM subscriptions WHERE plan_id=? AND student_id=?')->execute([$fixturePlanId, $fixtureStudentId]);
        if ($fixturePlanId !== null) $pdo->prepare('DELETE FROM subscription_plans WHERE id=?')->execute([$fixturePlanId]);
        if ($apiPlanCode !== null) $pdo->prepare('DELETE FROM subscription_plans WHERE code=?')->execute([$apiPlanCode]);
        foreach ($fixtureEmployeeIds as $employeeId) $pdo->prepare('DELETE FROM teaching_assignments WHERE employee_id=?')->execute([$employeeId]);
        if ($fixtureStudentId !== null) $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$fixtureStudentId]);
        foreach ($fixtureEmployeeIds as $employeeId) $pdo->prepare('DELETE FROM employees WHERE id=?')->execute([$employeeId]);
        if ($fixtureClassSubjectId !== null) $pdo->prepare('DELETE FROM class_subjects WHERE id=?')->execute([$fixtureClassSubjectId]);
        if ($fixtureSubjectId !== null) $pdo->prepare('DELETE FROM subjects WHERE id=?')->execute([$fixtureSubjectId]);
        $pdo->prepare("DELETE FROM audit_events WHERE actor_user_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM user_roles WHERE user_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")->execute($ids);
    }
}

exit($failures ? 1 : 0);
