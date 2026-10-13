<?php
declare(strict_types=1);

putenv('APP_ENV=test');
putenv('SESSION_IDLE_TIMEOUT_SECONDS=300');
putenv('LOGIN_MAX_ATTEMPTS=2');
putenv('LOGIN_WINDOW_SECONDS=3600');
putenv('LOGIN_LOCKOUT_SECONDS=60');
putenv('AUTH_THROTTLE_KEY=phase2-test-secret-key-change-before-use-00000000');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/tests/phase2_academic_integration.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__) . '/app/bootstrap.php';
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
$adminId = null;
$teacherId = null;
$stageId = $classId = $subjectId = $classSubjectId = $lessonId = $resourceId = $employeeId = null;
try {
    $user = $pdo->prepare('INSERT INTO users(full_name,email,password_hash) VALUES(:name,:email,:hash) RETURNING id');
    $user->execute(['name' => 'Phase 2 Academic Admin', 'email' => 'phase2-admin-' . $suffix . '@example.invalid', 'hash' => password_hash('test-password-123', PASSWORD_DEFAULT)]);
    $adminId = (int) $user->fetchColumn();
    $adminRole = $pdo->prepare("SELECT id FROM roles WHERE code = 'admin'");
    $adminRole->execute();
    $adminRoleId = (int) $adminRole->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(:user_id,:role_id)')->execute(['user_id' => $adminId, 'role_id' => $adminRoleId]);
    $pdo->prepare('INSERT INTO employees(user_id,job_title) VALUES(:user_id,:title)')->execute(['user_id' => $adminId, 'title' => 'Test admin']);
    $_SESSION['user'] = ['id' => $adminId];
    $assert(has_permission('academics.manage'), 'admin receives academic management permission');
    $assert(has_permission('teaching_assignments.manage'), 'admin receives teacher assignment permission');

    $user->execute(['name' => 'Phase 2 Academic Teacher', 'email' => 'phase2-teacher-' . $suffix . '@example.invalid', 'hash' => password_hash('test-password-123', PASSWORD_DEFAULT)]);
    $teacherId = (int) $user->fetchColumn();
    $teacherRole = $pdo->prepare("SELECT id FROM roles WHERE code = 'teacher'");
    $teacherRole->execute();
    $teacherRoleId = (int) $teacherRole->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(:user_id,:role_id)')->execute(['user_id' => $teacherId, 'role_id' => $teacherRoleId]);
    $employee = $pdo->prepare('INSERT INTO employees(user_id,job_title) VALUES(:user_id,:title) RETURNING id');
    $employee->execute(['user_id' => $teacherId, 'title' => 'Teacher']);
    $employeeId = (int) $employee->fetchColumn();

    $stage = academic_create_stage(['code' => 'TEST_' . strtoupper($suffix), 'name' => 'مرحلة اختبار ' . $suffix, 'sort_order' => 10], $adminId);
    $stageId = (int) $stage['id'];
    $class = academic_create_class(['stage_id' => $stageId, 'code' => 'TEST_CLASS', 'name' => 'صف اختبار', 'sort_order' => 1], $adminId);
    $classId = (int) $class['id'];
    $subject = academic_create_subject(['code' => 'TEST_' . strtoupper($suffix), 'name' => 'مادة اختبار ' . $suffix], $adminId);
    $subjectId = (int) $subject['id'];
    $mapping = academic_create_class_subject(['class_id' => $classId, 'subject_id' => $subjectId], $adminId);
    $classSubjectId = (int) $mapping['id'];
    $lesson = academic_create_lesson(['class_subject_id' => $classSubjectId, 'title' => 'درس قابل للبحث', 'position' => 1], $adminId);
    $lessonId = (int) $lesson['id'];
    $assert($stageId > 0 && $classId > 0 && $subjectId > 0 && $classSubjectId > 0 && $lessonId > 0, 'create stage, class, subject, mapping, and lesson');
    $assert(count(array_filter(academic_list_classes($stageId), static fn(array $row): bool => (int) $row['id'] === $classId)) === 1, 'filter classes by stage');
    $assert(count(array_filter(academic_list_subjects($classId), static fn(array $row): bool => (int) $row['id'] === $subjectId)) === 1, 'filter subjects by class');
    $assert(count(array_filter(academic_list_lessons(['stage_id' => $stageId, 'class_id' => $classId, 'subject_id' => $subjectId]), static fn(array $row): bool => (int) $row['id'] === $lessonId)) === 0, 'unpublished lesson remains hidden from the content catalog');
    academic_update('lessons', $lessonId, ['is_published' => true], $adminId);
    $publishAudit = $pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE action_code='content.published' AND target_type='lesson' AND target_id=:id");
    $publishAudit->execute(['id' => $lessonId]);
    $assert((int) $publishAudit->fetchColumn() === 1, 'lesson publication is audited transactionally');

    $resource = $pdo->prepare("INSERT INTO learning_resources(class_subject_id,lesson_id,title,resource_type,storage_key,requires_subscription,status,uploaded_by,source_reference,source_owner,copyright_license) VALUES(:mapping,:lesson,:title,'PDF',:storage,TRUE,'DRAFT',:employee,'test source','test owner','test license') RETURNING id");
    $resource->execute(['mapping' => $classSubjectId, 'lesson' => $lessonId, 'title' => 'ملف دراسي قابل للبحث', 'storage' => 'test/' . $suffix . '.pdf', 'employee' => $employeeId]);
    $resourceId = (int) $resource->fetchColumn();
    $pdo->prepare("UPDATE learning_resources SET status='PENDING_REVIEW' WHERE id=:id")->execute(['id' => $resourceId]);
    $reviewerLookup = $pdo->prepare('SELECT id FROM employees WHERE user_id=:user_id');
    $reviewerLookup->execute(['user_id' => $adminId]);
    $reviewerId = (int) $reviewerLookup->fetchColumn();
    $pdo->prepare("INSERT INTO content_reviews(resource_id,reviewer_id,status,feedback) VALUES(:resource,:reviewer,'APPROVED','approved test fixture')")
        ->execute(['resource' => $resourceId, 'reviewer' => $reviewerId]);
    $pdo->prepare("UPDATE learning_resources SET status='APPROVED',published_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id' => $resourceId]);
    $results = academic_search_resources(['stage_id' => $stageId, 'class_id' => $classId, 'subject_id' => $subjectId, 'lesson_id' => $lessonId, 'q' => 'قابل للبحث']);
    $assert(count($results) === 1 && !array_key_exists('storage_key', $results[0]), 'search approved content by stage, class, subject, lesson, and text without exposing storage keys');

    academic_assign_teacher($employeeId, $classSubjectId, $adminId);
    academic_assign_teacher($employeeId, $classSubjectId, $adminId);
    $assignments = academic_teacher_assignments($teacherId);
    $assert(count($assignments) === 1 && (int) $assignments[0]['class_subject_id'] === $classSubjectId, 'assignments are idempotent and visible only through current teacher identity');
    $_SESSION['user'] = ['id' => $teacherId];
    $assert(!has_permission('academics.manage') && !has_permission('teaching_assignments.manage'), 'teacher cannot manage academic structure or assignments');
    $_SESSION['user'] = ['id' => $adminId];
    $removed = academic_unassign_teacher($employeeId, $classSubjectId, $adminId);
    $assert($removed && academic_teacher_assignments($teacherId) === [], 'authorized admin can unassign the teacher');

    $updated = academic_update('stages', $stageId, ['is_active' => false], $adminId);
    $assert($updated !== null && !database_boolean($updated['is_active']), 'academic records can be deactivated without hard deletion');
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    fwrite(STDERR, "ERROR " . $error->getMessage() . "\n");
} finally {
    if ($resourceId !== null) $pdo->prepare('DELETE FROM learning_resources WHERE id=:id')->execute(['id' => $resourceId]);
    if ($classSubjectId !== null && $employeeId !== null) $pdo->prepare('DELETE FROM teaching_assignments WHERE employee_id=:employee_id AND class_subject_id=:class_subject_id')->execute(['employee_id' => $employeeId, 'class_subject_id' => $classSubjectId]);
    if ($lessonId !== null) $pdo->prepare('DELETE FROM lessons WHERE id=:id')->execute(['id' => $lessonId]);
    if ($classSubjectId !== null) $pdo->prepare('DELETE FROM class_subjects WHERE id=:id')->execute(['id' => $classSubjectId]);
    if ($classId !== null) $pdo->prepare('DELETE FROM classes WHERE id=:id')->execute(['id' => $classId]);
    if ($subjectId !== null) $pdo->prepare('DELETE FROM subjects WHERE id=:id')->execute(['id' => $subjectId]);
    if ($stageId !== null) $pdo->prepare('DELETE FROM stages WHERE id=:id')->execute(['id' => $stageId]);
    foreach ([$adminId, $teacherId] as $userId) {
        if ($userId !== null) {
            $pdo->prepare('DELETE FROM audit_events WHERE actor_user_id=:id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM employees WHERE user_id=:id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM user_roles WHERE user_id=:id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id' => $userId]);
        }
    }
}

printf("%d assertions, %d failures\n", $assertions, count($failures));
exit($failures ? 1 : 0);
