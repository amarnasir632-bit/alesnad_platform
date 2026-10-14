<?php
declare(strict_types=1);

function list_academic_structure(): array
{
    $pdo = database_connection();
    $stages = $pdo->query('SELECT id, code, name, sort_order, is_active FROM stages ORDER BY sort_order, id')->fetchAll();
    $classes = $pdo->query(
        'SELECT c.id, c.stage_id, c.code, c.name, c.sort_order, c.is_active,
                COUNT(cs.id) FILTER (WHERE cs.is_active AND s.is_active) AS subject_count
         FROM classes c
         LEFT JOIN class_subjects cs ON cs.class_id = c.id
         LEFT JOIN subjects s ON s.id = cs.subject_id
         GROUP BY c.id
         ORDER BY c.stage_id, c.sort_order, c.id'
    )->fetchAll();
    $assignments = $pdo->query(
        'SELECT cs.id, cs.class_id, cs.is_active, s.code AS subject_code, s.name AS subject_name, s.is_active AS subject_active
         FROM class_subjects cs
         JOIN subjects s ON s.id = cs.subject_id
         ORDER BY s.name, cs.id'
    )->fetchAll();

    $classesByStage = [];
    foreach ($classes as $class) {
        $class['subjects'] = [];
        $classesByStage[(int) $class['stage_id']][] = $class;
    }
    $classPositions = [];
    foreach ($classesByStage as $stageId => $stageClasses) {
        foreach ($stageClasses as $position => $class) {
            $classPositions[(int) $class['id']] = [$stageId, $position];
        }
    }
    foreach ($assignments as $assignment) {
        $classId = (int) $assignment['class_id'];
        if (!isset($classPositions[$classId])) {
            continue;
        }
        [$stageId, $position] = $classPositions[$classId];
        $classesByStage[$stageId][$position]['subjects'][] = $assignment;
    }

    foreach ($stages as &$stage) {
        $stage['classes'] = $classesByStage[(int) $stage['id']] ?? [];
    }
    unset($stage);
    return $stages;
}

function create_academic_stage(string $code, string $name, int $sortOrder, int $actorId): void
{
    $code = normalize_academic_code($code, 'رمز المرحلة');
    $name = validate_academic_name($name, 'اسم المرحلة', 100);
    validate_sort_order($sortOrder);

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('INSERT INTO stages (code, name, sort_order) VALUES (:code, :name, :sort_order) RETURNING id');
        $statement->execute(['code' => $code, 'name' => $name, 'sort_order' => $sortOrder]);
        record_audit_event($pdo, $actorId, 'academics.stage.created', 'stage', (int) $statement->fetchColumn(), ['code' => $code, 'name' => $name]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function create_academic_class(int $stageId, string $code, string $name, int $sortOrder, int $actorId): void
{
    $code = normalize_academic_code($code, 'رمز الصف');
    $name = validate_academic_name($name, 'اسم الصف', 100);
    validate_sort_order($sortOrder);

    $pdo = database_connection();
    $stageCheck = $pdo->prepare('SELECT 1 FROM stages WHERE id = :id AND is_active');
    $stageCheck->execute(['id' => $stageId]);
    if (!$stageCheck->fetchColumn()) {
        throw new InvalidArgumentException('المرحلة غير موجودة أو غير نشطة.');
    }
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('INSERT INTO classes (stage_id, code, name, sort_order) VALUES (:stage_id, :code, :name, :sort_order) RETURNING id');
        $statement->execute(['stage_id' => $stageId, 'code' => $code, 'name' => $name, 'sort_order' => $sortOrder]);
        record_audit_event($pdo, $actorId, 'academics.class.created', 'class', (int) $statement->fetchColumn(), ['stage_id' => $stageId, 'code' => $code, 'name' => $name]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function create_subject_assignment(int $classId, string $name, string $code, int $actorId): void
{
    $name = validate_academic_name($name, 'اسم المادة', 120);
    $code = trim($code);
    if ($code !== '' && !preg_match('/^[A-Z0-9_]{2,50}$/', $code)) {
        throw new InvalidArgumentException('رمز المادة يجب أن يتكون من حرفين أو أكثر من الأحرف الإنجليزية الكبيرة أو الأرقام أو الشرطة السفلية.');
    }

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $classCheck = $pdo->prepare(
            'SELECT 1 FROM classes c JOIN stages st ON st.id = c.stage_id
             WHERE c.id = :id AND c.is_active AND st.is_active'
        );
        $classCheck->execute(['id' => $classId]);
        if (!$classCheck->fetchColumn()) {
            throw new InvalidArgumentException('الصف غير موجود أو غير نشط.');
        }
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(lower(:subject_name)))')
            ->execute(['subject_name' => $name]);
        if ($code === '') {
            $lookup = $pdo->prepare('SELECT id, name, code, is_active FROM subjects WHERE lower(name) = lower(:subject_name) LIMIT 1');
            $lookup->execute(['subject_name' => $name]);
        } else {
            $lookup = $pdo->prepare(
                'SELECT id, name, code, is_active FROM subjects
                 WHERE lower(name) = lower(:subject_name) OR code = :subject_code
                 ORDER BY CASE WHEN lower(name) = lower(:sort_name) THEN 0 ELSE 1 END
                 LIMIT 1'
            );
            $lookup->execute(['subject_name' => $name, 'subject_code' => $code, 'sort_name' => $name]);
        }
        $subject = $lookup->fetch();
        if ($subject && strcasecmp($subject['name'], $name) !== 0) {
            throw new InvalidArgumentException('رمز المادة مستخدم لمادة أخرى. اختر رمزًا مختلفًا.');
        }
        if (!$subject) {
            $insertSubject = $pdo->prepare('INSERT INTO subjects (name, code) VALUES (:name, :code) RETURNING id');
            $insertSubject->execute(['name' => $name, 'code' => $code !== '' ? $code : null]);
            $subjectId = (int) $insertSubject->fetchColumn();
        } else {
            $subjectId = (int) $subject['id'];
            if ($code !== '' && $subject['code'] !== null && $subject['code'] !== $code) {
                throw new InvalidArgumentException('هذه المادة مسجلة برمز مختلف. استخدم الرمز المسجل أو اترك الحقل فارغًا.');
            }
            if ($code !== '' && $subject['code'] === null) {
                $pdo->prepare('UPDATE subjects SET code = :code WHERE id = :id')->execute(['code' => $code, 'id' => $subjectId]);
            }
            if (!(bool) $subject['is_active']) {
                $pdo->prepare('UPDATE subjects SET is_active = TRUE WHERE id = :id')->execute(['id' => $subjectId]);
            }
        }

        $link = $pdo->prepare(
            'INSERT INTO class_subjects (class_id, subject_id)
             VALUES (:class_id, :subject_id)
             ON CONFLICT (class_id, subject_id) DO UPDATE SET is_active = TRUE
             RETURNING id'
        );
        $link->execute(['class_id' => $classId, 'subject_id' => $subjectId]);
        $assignmentId = (int) $link->fetchColumn();
        record_audit_event($pdo, $actorId, 'academics.subject.assigned', 'class_subject', $assignmentId, ['class_id' => $classId, 'subject_id' => $subjectId, 'subject_name' => $name]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function normalize_academic_code(string $code, string $label): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9_]{2,50}$/', $code)) {
        throw new InvalidArgumentException($label . ' يجب أن يتكون من حرفين أو أكثر من الأحرف الإنجليزية أو الأرقام أو الشرطة السفلية.');
    }
    return $code;
}

function validate_academic_name(string $name, string $label, int $maxLength): string
{
    $name = trim($name);
    $length = preg_match_all('/./us', $name);
    if ($length === false || $length < 2 || $length > $maxLength) {
        throw new InvalidArgumentException('أدخل ' . $label . ' من حرفين إلى ' . $maxLength . ' حرفًا.');
    }
    return $name;
}

function validate_sort_order(int $sortOrder): void
{
    if ($sortOrder < 0 || $sortOrder > 32767) {
        throw new InvalidArgumentException('ترتيب العرض يجب أن يكون بين 0 و32767.');
    }
}
