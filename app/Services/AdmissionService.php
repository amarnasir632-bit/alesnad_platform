<?php
declare(strict_types=1);

function admission_class_id(mixed $value): int
{
    $classId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($classId === false) throw new InvalidArgumentException('الصف الدراسي غير صالح.');
    $statement = database_connection()->prepare(
        'SELECT 1 FROM classes c JOIN stages s ON s.id = c.stage_id
         WHERE c.id = :id AND c.is_active AND s.is_active'
    );
    $statement->execute(['id' => $classId]);
    if ($statement->fetchColumn() === false) throw new InvalidArgumentException('الصف الدراسي غير نشط أو غير موجود.');
    return (int) $classId;
}

function admission_filters(array $input): array
{
    $filters = [];
    $query = is_string($input['q'] ?? null) ? trim($input['q']) : '';
    if ($query !== '') {
        if (mb_strlen($query) > 150) throw new InvalidArgumentException('عبارة البحث طويلة أكثر من اللازم.');
        $filters['q'] = $query;
    }
    $status = is_string($input['status'] ?? null) ? strtoupper(trim($input['status'])) : '';
    if ($status !== '' && !in_array($status, ['PENDING', 'NEEDS_INFO', 'APPROVED', 'REJECTED', 'SUSPENDED'], true)) {
        throw new InvalidArgumentException('حالة التسجيل غير صالحة.');
    }
    if ($status !== '') $filters['status'] = $status;
    if (isset($input['class_id']) && $input['class_id'] !== '') {
        $filters['class_id'] = filter_var($input['class_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($filters['class_id'] === false) throw new InvalidArgumentException('الصف الدراسي غير صالح.');
    }
    foreach (['from', 'to'] as $dateField) {
        $date = $input[$dateField] ?? '';
        if ($date !== '' && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
            throw new InvalidArgumentException('تاريخ التصفية غير صالح.');
        }
        if ($date !== '') $filters[$dateField] = $date;
    }
    if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
        throw new InvalidArgumentException('بداية الفترة يجب أن تسبق نهايتها.');
    }
    return $filters;
}

function list_student_admissions(array $input = [], int $limit = 100, int $offset = 0): array
{
    $filters = admission_filters($input);
    $where = [];
    $params = [];
    if (isset($filters['q'])) {
        $pattern = '%' . $filters['q'] . '%';
        $where[] = '(u.full_name ILIKE :name_query OR u.email ILIKE :email_query OR COALESCE(st.student_number, \'\') ILIKE :student_query OR COALESCE(u.phone, \'\') ILIKE :phone_query)';
        $params['name_query'] = $pattern;
        $params['email_query'] = $pattern;
        $params['student_query'] = $pattern;
        $params['phone_query'] = $pattern;
    }
    if (isset($filters['status'])) {
        $where[] = 'st.registration_status = :status';
        $params['status'] = $filters['status'];
    }
    if (isset($filters['class_id'])) {
        $where[] = 'st.class_id = :class_id';
        $params['class_id'] = $filters['class_id'];
    }
    if (isset($filters['from'])) {
        $where[] = 'st.created_at >= CAST(:from_date AS DATE)';
        $params['from_date'] = $filters['from'];
    }
    if (isset($filters['to'])) {
        $where[] = 'st.created_at < CAST(:to_date AS DATE) + INTERVAL \'1 day\'';
        $params['to_date'] = $filters['to'];
    }
    $sql = 'SELECT st.id AS student_id, st.user_id, st.student_number, st.class_id,
                   st.admission_year, st.academic_semester, st.registration_status,
                   st.created_at AS applied_at, u.full_name, u.email, u.phone,
                   c.name AS class_name, s.name AS stage_name
            FROM students st
            JOIN users u ON u.id = st.user_id
            LEFT JOIN classes c ON c.id = st.class_id
            LEFT JOIN stages s ON s.id = c.stage_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY st.created_at DESC, st.id DESC LIMIT :limit OFFSET :offset';
    $statement = database_connection()->prepare($sql);
    foreach ($params as $name => $value) $statement->bindValue($name, $value);
    $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
    $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function review_student_admission(int $studentId, int $actorId, array $input): array
{
    $status = is_string($input['status'] ?? null) ? strtoupper(trim($input['status'])) : '';
    if (!in_array($status, ['PENDING', 'NEEDS_INFO', 'APPROVED', 'REJECTED', 'SUSPENDED'], true)) {
        throw new InvalidArgumentException('حالة التسجيل الجديدة غير صالحة.');
    }
    $reason = is_string($input['reason'] ?? '') ? trim((string) ($input['reason'] ?? '')) : '';
    if (in_array($status, ['NEEDS_INFO', 'REJECTED'], true)
        && (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000)) {
        throw new InvalidArgumentException('اكتب سببًا واضحًا من 5 إلى 1000 محرف.');
    }

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare(
            'SELECT st.id, st.user_id, st.registration_status, st.class_id, u.email, u.full_name
             FROM students st JOIN users u ON u.id = st.user_id
             WHERE st.id = :id FOR UPDATE OF st, u'
        );
        $select->execute(['id' => $studentId]);
        $student = $select->fetch();
        if (!$student) throw new OutOfBoundsException('طلب التسجيل غير موجود.');
        $previousStatus = $student['registration_status'];
        $allowedTransitions = [
            'PENDING' => ['PENDING', 'NEEDS_INFO', 'APPROVED', 'REJECTED'],
            'NEEDS_INFO' => ['PENDING', 'NEEDS_INFO', 'APPROVED', 'REJECTED'],
            'APPROVED' => ['APPROVED', 'SUSPENDED'],
            'SUSPENDED' => ['APPROVED', 'SUSPENDED'],
            'REJECTED' => ['REJECTED'],
        ];
        if (!in_array($status, $allowedTransitions[$previousStatus] ?? [], true)) {
            throw new InvalidArgumentException('لا يمكن نقل التسجيل من حالته الحالية إلى الحالة المطلوبة.');
        }

        $studentUpdates = [];
        $params = ['id' => $studentId];
        if (array_key_exists('class_id', $input)) {
            $classId = admission_class_id($input['class_id']);
            $studentUpdates[] = 'class_id = :class_id';
            $params['class_id'] = $classId;
        }
        if (array_key_exists('academic_semester', $input)) {
            $semester = $input['academic_semester'];
            if ($semester !== null && !in_array($semester, ['FIRST', 'SECOND'], true)) {
                throw new InvalidArgumentException('الفصل الدراسي غير صالح.');
            }
            $studentUpdates[] = 'academic_semester = :semester';
            $params['semester'] = $semester;
        }
        if (array_key_exists('admission_year', $input)) {
            $year = $input['admission_year'];
            if ($year !== null && (!is_int($year) || $year < 1900 || $year > (int) date('Y'))) {
                throw new InvalidArgumentException('سنة القبول غير صحيحة.');
            }
            $studentUpdates[] = 'admission_year = :admission_year';
            $params['admission_year'] = $year;
        }
        if ($status === 'APPROVED') {
            $approvedClassId = $params['class_id'] ?? ($student['class_id'] === null ? null : (int) $student['class_id']);
            if ($approvedClassId === null) {
                throw new InvalidArgumentException('يجب تحديد صف للطالب قبل اعتماد التسجيل.');
            }
            admission_class_id($approvedClassId);
        }
        $studentUpdates[] = 'registration_status = :status';
        $params['status'] = $status;
        $pdo->prepare('UPDATE students SET ' . implode(', ', $studentUpdates) . ' WHERE id = :id')->execute($params);
        $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :user_id')
            ->execute(['active' => $status === 'APPROVED', 'user_id' => $student['user_id']]);
        $pdo->prepare(
            'INSERT INTO student_admission_events (student_id, previous_status, new_status, reason, actor_user_id)
             VALUES (:student_id, :previous, :status, :reason, :actor_id)'
        )->execute([
            'student_id' => $studentId,
            'previous' => $previousStatus,
            'status' => $status,
            'reason' => $reason,
            'actor_id' => $actorId,
        ]);
        audit_log('admission.status_changed', 'student', $studentId, [
            'from' => $previousStatus,
            'to' => $status,
            'reason' => $reason,
        ], $actorId);

        $completionToken = null;
        if ($status === 'NEEDS_INFO') {
            $completionToken = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE student_admission_tokens SET used_at = CURRENT_TIMESTAMP WHERE student_id = :id AND used_at IS NULL')
                ->execute(['id' => $studentId]);
            $pdo->prepare(
                'INSERT INTO student_admission_tokens (student_id, token_hash, expires_at)
                 VALUES (:student_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL \'48 hours\')'
            )->execute(['student_id' => $studentId, 'token_hash' => hash('sha256', $completionToken)]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    if ($completionToken !== null) {
        try {
            $link = absolute_url('complete_admission', ['token' => $completionToken]);
            $safeLink = e($link);
            $safeReason = e($reason);
            $html = '<div dir="rtl" lang="ar"><h1>استكمال طلب التسجيل</h1><p>' . $safeReason . '</p><p>يرجى تحديث بياناتك خلال 48 ساعة:</p><p><a href="' . $safeLink . '">استكمال الطلب</a></p></div>';
            $text = "يرجى استكمال طلب التسجيل:\n{$reason}\nالرابط صالح لمدة 48 ساعة:\n{$link}";
            send_cloudflare_transactional_email($student['email'], 'استكمال طلب التسجيل | الإسناد الأكاديمي', $html, $text);
        } catch (Throwable $error) {
            $pdo->prepare('UPDATE student_admission_tokens SET used_at = CURRENT_TIMESTAMP WHERE token_hash = :token_hash AND used_at IS NULL')
                ->execute(['token_hash' => hash('sha256', $completionToken)]);
            throw new RuntimeException('تعذر إرسال رابط استكمال الطلب؛ تحقق من إعداد البريد ثم أعد المحاولة.', 0, $error);
        }
    }
    return find_student_admission($studentId) ?? throw new RuntimeException('Updated admission request is unavailable.');
}

function find_student_admission(int $studentId): ?array
{
    $statement = database_connection()->prepare(
        'SELECT st.id AS student_id, st.user_id, st.student_number, st.class_id,
                st.admission_year, st.academic_semester, st.registration_status,
                st.created_at AS applied_at, u.full_name, u.email, u.phone,
                c.name AS class_name, s.name AS stage_name
         FROM students st JOIN users u ON u.id = st.user_id
         LEFT JOIN classes c ON c.id = st.class_id
         LEFT JOIN stages s ON s.id = c.stage_id WHERE st.id = :id'
    );
    $statement->execute(['id' => $studentId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function admission_completion_is_valid(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $statement = database_connection()->prepare(
        "SELECT EXISTS (
            SELECT 1 FROM student_admission_tokens t
            JOIN students st ON st.id = t.student_id
            WHERE t.token_hash = :hash AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
              AND st.registration_status = 'NEEDS_INFO'
        )"
    );
    $statement->execute(['hash' => hash('sha256', $token)]);
    return database_bool($statement->fetchColumn());
}

function complete_student_admission(string $token, array $input): void
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new InvalidArgumentException('رابط الاستكمال غير صالح أو منتهي الصلاحية.');
    $name = is_string($input['full_name'] ?? null) ? trim($input['full_name']) : '';
    $phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
    $classId = admission_class_id($input['class_id'] ?? null);
    $length = preg_match_all('/./us', $name);
    if ($length === false || $length < 2 || $length > 150) throw new InvalidArgumentException('الاسم غير صالح.');
    if ($phone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $phone)) throw new InvalidArgumentException('رقم الهاتف غير صحيح.');

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            "SELECT t.id AS token_id, st.id AS student_id, st.user_id, st.registration_status
             FROM student_admission_tokens t JOIN students st ON st.id = t.student_id
             WHERE t.token_hash = :hash AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
               AND st.registration_status = 'NEEDS_INFO'
             FOR UPDATE OF t, st"
        );
        $query->execute(['hash' => hash('sha256', $token)]);
        $request = $query->fetch();
        if (!$request) throw new InvalidArgumentException('رابط الاستكمال غير صالح أو منتهي الصلاحية.');

        $pdo->prepare('UPDATE users SET full_name = :name, phone = :phone WHERE id = :id')
            ->execute(['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'id' => $request['user_id']]);
        $pdo->prepare("UPDATE students SET class_id = :class_id, registration_status = 'PENDING' WHERE id = :id")
            ->execute(['class_id' => $classId, 'id' => $request['student_id']]);
        $pdo->prepare('UPDATE student_admission_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $request['token_id']]);
        $pdo->prepare(
            "INSERT INTO student_admission_events (student_id, previous_status, new_status, reason, actor_user_id)
             VALUES (:student_id, 'NEEDS_INFO', 'PENDING', 'استكمل الطالب بيانات طلبه عبر رابط آمن.', :actor_id)"
        )->execute(['student_id' => $request['student_id'], 'actor_id' => $request['user_id']]);
        audit_log('admission.details_completed', 'student', (int) $request['student_id'], [], (int) $request['user_id']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function submit_student_profile_correction(int $userId, array $input): int
{
    $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) throw new InvalidArgumentException('اكتب سبب طلب التصحيح من 5 إلى 1000 محرف.');
    $changes = [];
    if (array_key_exists('full_name', $input)) {
        if (!is_string($input['full_name'])) throw new InvalidArgumentException('الاسم غير صالح.');
        $name = trim($input['full_name']);
        $length = preg_match_all('/./us', $name);
        if ($length === false || $length < 2 || $length > 150) throw new InvalidArgumentException('الاسم غير صالح.');
        $changes['full_name'] = $name;
    }
    if (array_key_exists('phone', $input)) {
        if (!is_string($input['phone'])) throw new InvalidArgumentException('رقم الهاتف غير صالح.');
        $phone = trim($input['phone']);
        if ($phone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $phone)) throw new InvalidArgumentException('رقم الهاتف غير صالح.');
        $changes['phone'] = $phone;
    }
    if (array_key_exists('parent_phone', $input)) {
        if (!is_string($input['parent_phone'])) throw new InvalidArgumentException('رقم هاتف ولي الأمر غير صالح.');
        $parentPhone = trim($input['parent_phone']);
        if ($parentPhone !== '' && !preg_match('/^[+0-9()\-\s]{7,32}$/', $parentPhone)) throw new InvalidArgumentException('رقم هاتف ولي الأمر غير صالح.');
        $changes['parent_phone'] = $parentPhone;
    }
    if (array_key_exists('class_id', $input)) {
        $classValue = $input['class_id'];
        $changes['class_id'] = $classValue === null || $classValue === '' ? null : admission_class_id($classValue);
    }
    if (!$changes) throw new InvalidArgumentException('أرسل بيانًا واحدًا على الأقل لتصحيحه.');

    $pdo = database_connection();
    $student = $pdo->prepare('SELECT id FROM students WHERE user_id = :id');
    $student->execute(['id' => $userId]);
    $studentId = $student->fetchColumn();
    if ($studentId === false) throw new InvalidArgumentException('طلبات تصحيح بيانات الطالب متاحة لحسابات الطلاب فقط.');
    $statement = $pdo->prepare(
        'INSERT INTO student_profile_correction_requests (user_id, requested_changes, reason)
         VALUES (:user_id, CAST(:changes AS JSONB), :reason) RETURNING id'
    );
    $statement->execute([
        'user_id' => $userId,
        'changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'reason' => $reason,
    ]);
    $requestId = (int) $statement->fetchColumn();
    audit_log('student.profile_correction.requested', 'student', (int) $studentId, ['request_id' => $requestId], $userId);
    return $requestId;
}

function list_profile_correction_requests(string $status = 'PENDING'): array
{
    if (!in_array($status, ['PENDING', 'APPROVED', 'REJECTED'], true)) throw new InvalidArgumentException('حالة طلب التصحيح غير صالحة.');
    $statement = database_connection()->prepare(
        'SELECT cr.id, cr.user_id, cr.requested_changes, cr.reason, cr.status,
                cr.resolution_reason, cr.requested_at, cr.reviewed_at,
                u.full_name, u.email, st.student_number, c.name AS class_name
         FROM student_profile_correction_requests cr
         JOIN users u ON u.id = cr.user_id
         JOIN students st ON st.user_id = u.id
         LEFT JOIN classes c ON c.id = st.class_id
         WHERE cr.status = :status ORDER BY cr.requested_at DESC, cr.id DESC LIMIT 200'
    );
    $statement->execute(['status' => $status]);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        if (!is_array($row['requested_changes'])) {
            $row['requested_changes'] = json_decode((string) $row['requested_changes'], true, 16, JSON_THROW_ON_ERROR);
        }
    }
    return $rows;
}

function review_profile_correction_request(int $requestId, int $actorId, string $decision, string $resolution): void
{
    if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) throw new InvalidArgumentException('قرار التصحيح غير صالح.');
    $resolution = trim($resolution);
    if ($decision === 'REJECTED' && (mb_strlen($resolution) < 5 || mb_strlen($resolution) > 1000)) {
        throw new InvalidArgumentException('اكتب سبب رفض واضحًا.');
    }
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            "SELECT cr.*, st.id AS student_id
             FROM student_profile_correction_requests cr
             JOIN students st ON st.user_id = cr.user_id
             WHERE cr.id = :id AND cr.status = 'PENDING'
             FOR UPDATE OF cr, st"
        );
        $query->execute(['id' => $requestId]);
        $request = $query->fetch();
        if (!$request) throw new OutOfBoundsException('طلب التصحيح غير موجود أو سبق البت فيه.');
        if ($decision === 'APPROVED' && isset($request['requested_changes'])) {
            $changesForValidation = is_array($request['requested_changes'])
                ? $request['requested_changes']
                : json_decode((string) $request['requested_changes'], true, 16, JSON_THROW_ON_ERROR);
            if (array_key_exists('class_id', $changesForValidation)) {
                if ($changesForValidation['class_id'] === null) throw new InvalidArgumentException('يجب اختيار صف دراسي صالح.');
                $validClass = $pdo->prepare('SELECT 1 FROM classes c JOIN stages s ON s.id = c.stage_id WHERE c.id = :id AND c.is_active AND s.is_active');
                $validClass->execute(['id' => (int) $changesForValidation['class_id']]);
                if ($validClass->fetchColumn() === false) throw new InvalidArgumentException('الصف المطلوب غير نشط أو غير موجود.');
            }
        }
        $changes = is_array($request['requested_changes'])
            ? $request['requested_changes']
            : json_decode((string) $request['requested_changes'], true, 16, JSON_THROW_ON_ERROR);
        if ($decision === 'APPROVED') {
            $userSets = [];
            $userParams = ['id' => $request['user_id']];
            if (array_key_exists('full_name', $changes)) { $userSets[] = 'full_name = :full_name'; $userParams['full_name'] = $changes['full_name']; }
            if (array_key_exists('phone', $changes)) { $userSets[] = 'phone = :phone'; $userParams['phone'] = $changes['phone'] !== '' ? $changes['phone'] : null; }
            if ($userSets) $pdo->prepare('UPDATE users SET ' . implode(', ', $userSets) . ' WHERE id = :id')->execute($userParams);

            $studentSets = [];
            $studentParams = ['id' => $request['student_id']];
            if (array_key_exists('parent_phone', $changes)) { $studentSets[] = 'parent_phone = :parent_phone'; $studentParams['parent_phone'] = $changes['parent_phone'] !== '' ? $changes['parent_phone'] : null; }
            if (array_key_exists('class_id', $changes)) { $studentSets[] = 'class_id = :class_id'; $studentParams['class_id'] = $changes['class_id']; }
            if ($studentSets) $pdo->prepare('UPDATE students SET ' . implode(', ', $studentSets) . ' WHERE id = :id')->execute($studentParams);
        }
        $pdo->prepare(
            'UPDATE student_profile_correction_requests
             SET status = :status, resolution_reason = :reason, reviewed_by = :actor, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute(['status' => $decision, 'reason' => $resolution, 'actor' => $actorId, 'id' => $requestId]);
        audit_log('student.profile_correction.reviewed', 'student', (int) $request['student_id'], [
            'request_id' => $requestId,
            'decision' => $decision,
            'fields' => array_keys($changes),
        ], $actorId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
