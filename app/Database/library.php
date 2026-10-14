<?php
declare(strict_types=1);

function legacy_library_storage_root(): string
{
    $configured = (string) env_value('PRIVATE_STORAGE_PATH', 'storage/private');
    $root = str_starts_with($configured, DIRECTORY_SEPARATOR)
        ? $configured
        : PROJECT_ROOT . DIRECTORY_SEPARATOR . $configured;
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    if ($root === '' || !is_dir($root) || !is_writable($root)) {
        throw new RuntimeException('مساحة التخزين الخاصة غير جاهزة.');
    }
    $realRoot = realpath($root);
    $publicRoot = realpath(PROJECT_ROOT . DIRECTORY_SEPARATOR . 'public');
    if ($realRoot === false || ($publicRoot !== false && str_starts_with($realRoot . DIRECTORY_SEPARATOR, $publicRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('يجب أن تكون مساحة الملفات الخاصة خارج مجلد الويب.');
    }
    return $realRoot;
}

function store_private_pdf(array $upload): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('اختر ملف PDF صالحًا لرفعه.');
    }
    $maximumBytes = required_positive_int('UPLOAD_MAX_BYTES');
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > $maximumBytes) {
        throw new InvalidArgumentException('حجم الملف يتجاوز الحد المسموح أو أن الملف فارغ.');
    }
    $tmp = (string) $upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $handle = fopen($tmp, 'rb');
    $signature = $handle ? fread($handle, 5) : false;
    if ($handle) { fclose($handle); }
    if ($mime !== 'application/pdf' || $signature !== '%PDF-') {
        throw new InvalidArgumentException('يسمح برفع ملفات PDF فقط.');
    }
    $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? 'document.pdf')));
    $originalName = preg_replace('/[^\pL\pN._() -]+/u', '_', $originalName) ?: 'document.pdf';
    $root = legacy_library_storage_root();
    $directory = $root . DIRECTORY_SEPARATOR . 'library';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('تعذر إعداد مجلد الملفات.');
    }
    $key = 'library/' . bin2hex(random_bytes(24)) . '.pdf';
    $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('تعذر حفظ الملف المرفوع.');
    }
    chmod($destination, 0600);
    return ['storage_key' => $key, 'filename' => mb_substr($originalName, 0, 255), 'mime' => 'application/pdf', 'size' => $size];
}

function list_library_items(bool $reviewQueue = false): array
{
    $pdo = database_connection();
    if ($reviewQueue) {
        return $pdo->query(
            "SELECT 'resource' AS item_type, r.id, r.title, r.status, r.resource_type, r.original_filename,
                    r.file_size_bytes, r.external_url, r.requires_subscription, r.created_at,
                    cs.class_id, c.name AS class_name, s.name AS subject_name, u.full_name AS uploader_name, u.id AS uploader_user_id,
                    latest.feedback AS latest_feedback
             FROM learning_resources r JOIN class_subjects cs ON cs.id=r.class_subject_id
             JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id
             JOIN employees e ON e.id=r.uploaded_by JOIN users u ON u.id=e.user_id
             LEFT JOIN LATERAL (SELECT feedback FROM content_reviews WHERE resource_id=r.id ORDER BY reviewed_at DESC,id DESC LIMIT 1) latest ON TRUE
             WHERE r.status IN ('PENDING_REVIEW','REJECTED','DRAFT')
             UNION ALL
             SELECT 'exam' AS item_type, x.id, x.title, x.status, 'PDF' AS resource_type, x.original_filename,
                    x.file_size_bytes, NULL AS external_url, x.requires_subscription, x.created_at,
                    cs.class_id, c.name AS class_name, s.name AS subject_name, u.full_name AS uploader_name, u.id AS uploader_user_id,
                    latest.feedback AS latest_feedback
             FROM past_exams x JOIN class_subjects cs ON cs.id=x.class_subject_id
             JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id
             JOIN employees e ON e.id=x.uploaded_by JOIN users u ON u.id=e.user_id
             LEFT JOIN LATERAL (SELECT feedback FROM content_reviews WHERE past_exam_id=x.id ORDER BY reviewed_at DESC,id DESC LIMIT 1) latest ON TRUE
             WHERE x.status IN ('PENDING_REVIEW','REJECTED','DRAFT')
             ORDER BY created_at DESC"
        )->fetchAll();
    }
    $statement = $pdo->query(
        "SELECT 'resource' AS item_type, r.id, r.title, r.status, r.resource_type, r.original_filename,
                r.file_size_bytes, r.external_url, r.requires_subscription, r.created_at,
                cs.id AS class_subject_id, cs.class_id, c.name AS class_name, s.name AS subject_name
         FROM learning_resources r JOIN class_subjects cs ON cs.id=r.class_subject_id
         JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id
         WHERE r.status='APPROVED'
         UNION ALL
         SELECT 'exam' AS item_type, x.id, x.title, x.status, 'PDF' AS resource_type, x.original_filename,
                x.file_size_bytes, NULL AS external_url, x.requires_subscription, x.created_at,
                cs.id AS class_subject_id, cs.class_id, c.name AS class_name, s.name AS subject_name
         FROM past_exams x JOIN class_subjects cs ON cs.id=x.class_subject_id
         JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id
         WHERE x.status='APPROVED'
         ORDER BY created_at DESC"
    );
    return $statement->fetchAll();
}

function list_library_subjects(int $userId): array
{
    $sql = "SELECT cs.id AS class_subject_id,c.name AS class_name,s.name AS subject_name
            FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.is_active
            JOIN subjects s ON s.id=cs.subject_id AND s.is_active WHERE cs.is_active";
    $params = [];
    if (!user_has_permission('roles.manage')) {
        $sql .= ' AND EXISTS (SELECT 1 FROM employees e JOIN teaching_assignments ta ON ta.employee_id=e.id WHERE e.user_id=:teacher_id AND e.is_active AND ta.class_subject_id=cs.id)';
        $params['teacher_id'] = $userId;
    }
    $statement = database_connection()->prepare($sql . ' ORDER BY c.name,s.name');
    $statement->execute($params);
    return $statement->fetchAll();
}

function list_student_library_items(int $userId): array
{
    if (!student_has_manual_content_access($userId)) { return []; }
    $statement = database_connection()->prepare('SELECT class_id FROM students WHERE user_id=:user_id');
    $statement->execute(['user_id'=>$userId]);
    $classId = $statement->fetchColumn();
    if (!$classId) { return []; }
    return array_values(array_filter(list_library_items(false), static fn(array $item): bool => (int)$item['class_id'] === (int)$classId));
}

function create_library_item(int $classSubjectId, string $kind, string $title, string $description, array $upload, string $externalUrl, string $sourceOwner, string $license, int $actorId): void
{
    if (!in_array($kind, ['resource', 'exam'], true)) {
        throw new InvalidArgumentException('نوع المحتوى غير صالح.');
    }
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > ($kind === 'exam' ? 150 : 200)) {
        throw new InvalidArgumentException('أدخل عنوانًا ضمن الطول المسموح.');
    }
    $description = trim($description);
    $externalUrl = trim($externalUrl);
    if (mb_strlen($description) > 5000 || mb_strlen($sourceOwner) > 200 || mb_strlen($license) > 200) {
        throw new InvalidArgumentException('تجاوز أحد الحقول الحد المسموح.');
    }
    $isLink = $kind === 'resource' && $externalUrl !== '';
    if ($isLink) {
        if (!filter_var($externalUrl, FILTER_VALIDATE_URL) || parse_url($externalUrl, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('يجب أن يكون رابط المورد صالحًا وآمنًا (HTTPS).');
        }
    } else {
        if ($externalUrl !== '') { throw new InvalidArgumentException('الامتحان السابق يجب أن يكون ملف PDF.'); }
    }
    $file = null;
    $resourceType = $isLink ? 'LINK' : 'PDF';

    $pdo = database_connection();
    $scope = $pdo->prepare(
        'SELECT cs.id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.is_active
         JOIN subjects s ON s.id=cs.subject_id AND s.is_active WHERE cs.id=:id AND cs.is_active'
    );
    $scope->execute(['id' => $classSubjectId]);
    if (!$scope->fetchColumn()) {
        throw new InvalidArgumentException('اختر مادة نشطة من الهيكل الدراسي.');
    }
    $employee = $pdo->prepare('SELECT id FROM employees WHERE user_id=:user_id AND is_active');
    $employee->execute(['user_id' => $actorId]);
    $employeeId = $employee->fetchColumn();
    if (!$employeeId) {
        throw new InvalidArgumentException('حساب الموظف غير نشط.');
    }
    if (!user_has_permission('roles.manage')) {
        $assignment = $pdo->prepare('SELECT 1 FROM teaching_assignments WHERE employee_id=:employee AND class_subject_id=:subject');
        $assignment->execute(['employee' => $employeeId, 'subject' => $classSubjectId]);
        if (!$assignment->fetchColumn()) {
            throw new InvalidArgumentException('لا يمكنك إضافة محتوى إلى مادة غير مسندة إليك.');
        }
    }

    if (!$isLink) { $file = store_private_pdf($upload); }

    try {
        $pdo->beginTransaction();
        if ($kind === 'resource') {
            $statement = $pdo->prepare(
                "INSERT INTO learning_resources (class_subject_id,title,resource_type,description,storage_key,external_url,original_filename,mime_type,file_size_bytes,source_owner,copyright_license,status,uploaded_by)
                 VALUES (:subject,:title,:type,:description,:key,:url,:filename,:mime,:size,:owner,:license,'PENDING_REVIEW',:uploader) RETURNING id"
            );
            $params = [
                'subject'=>$classSubjectId,'title'=>$title,'type'=>$resourceType,
                'description'=>$description !== '' ? $description : null,'key'=>$file['storage_key'] ?? null,
                'url'=>$externalUrl !== '' ? $externalUrl : null,'filename'=>$file['filename'] ?? null,
                'mime'=>$file['mime'] ?? null,'size'=>$file['size'] ?? null,
                'owner'=>trim($sourceOwner) ?: null,'license'=>trim($license) ?: null,'uploader'=>$employeeId,
            ];
        } else {
            $statement = $pdo->prepare(
                "INSERT INTO past_exams (class_subject_id,title,storage_key,original_filename,mime_type,file_size_bytes,source_owner,copyright_license,status,uploaded_by)
                 VALUES (:subject,:title,:key,:filename,:mime,:size,:owner,:license,'PENDING_REVIEW',:uploader) RETURNING id"
            );
            $params = [
                'subject'=>$classSubjectId,'title'=>$title,'key'=>$file['storage_key'],'filename'=>$file['filename'],
                'mime'=>$file['mime'],'size'=>$file['size'],'owner'=>trim($sourceOwner) ?: null,
                'license'=>trim($license) ?: null,'uploader'=>$employeeId,
            ];
        }
        $statement->execute($params);
        $itemId = (int) $statement->fetchColumn();
        record_audit_event($pdo, $actorId, 'content.uploaded', $kind, $itemId, ['title'=>$title,'class_subject_id'=>$classSubjectId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($file) { @unlink(legacy_library_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file['storage_key'])); }
        throw $error;
    }
}

function review_library_item(string $kind, int $itemId, int $reviewerUserId, string $decision, string $feedback): void
{
    if (!in_array($kind, ['resource', 'exam'], true) || $itemId < 1 || !in_array($decision, ['APPROVED','REJECTED','NEEDS_MODIFICATION'], true)) {
        throw new InvalidArgumentException('قرار المراجعة غير صالح.');
    }
    $feedback = trim($feedback);
    if (mb_strlen($feedback) > 2000 || ($decision !== 'APPROVED' && $feedback === '')) {
        throw new InvalidArgumentException('اكتب ملاحظة عند الرفض أو طلب التعديل، بحد أقصى 2000 حرف.');
    }
    $pdo = database_connection();
    $employee = $pdo->prepare('SELECT id FROM employees WHERE user_id=:id AND is_active');
    $employee->execute(['id' => $reviewerUserId]);
    $reviewerId = $employee->fetchColumn();
    if (!$reviewerId) { throw new InvalidArgumentException('حساب المراجع غير نشط.'); }
    $table = $kind === 'resource' ? 'learning_resources' : 'past_exams';
    $column = $kind === 'resource' ? 'resource_id' : 'past_exam_id';
    $pdo->beginTransaction();
    try {
        $item = $pdo->prepare("SELECT id,status FROM {$table} WHERE id=:id FOR UPDATE");
        $item->execute(['id' => $itemId]);
        $row = $item->fetch();
        if (!$row || !in_array($row['status'], ['PENDING_REVIEW','DRAFT','REJECTED'], true)) {
            throw new InvalidArgumentException('المحتوى غير موجود أو حالته لا تسمح بالمراجعة.');
        }
        $newStatus = match ($decision) {
            'APPROVED' => 'APPROVED',
            'REJECTED' => 'REJECTED',
            default => 'DRAFT',
        };
        $published = $decision === 'APPROVED' ? ', published_at=CURRENT_TIMESTAMP' : ', published_at=NULL';
        $pdo->exec("UPDATE {$table} SET status='{$newStatus}'{$published} WHERE id=" . (int) $itemId);
        $insert = $pdo->prepare("INSERT INTO content_reviews ({$column},reviewer_id,status,feedback) VALUES (:id,:reviewer,:status,:feedback)");
        $insert->execute(['id' => $itemId,'reviewer' => $reviewerId,'status' => $decision,'feedback' => $feedback !== '' ? $feedback : null]);
        record_audit_event($pdo, $reviewerUserId, 'content.' . strtolower($decision), $kind, $itemId, ['feedback' => $feedback ?: null]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function get_authorized_library_download(string $kind, int $itemId, int $userId): array
{
    if (!in_array($kind, ['resource','exam'], true) || $itemId < 1) { throw new InvalidArgumentException('الملف غير موجود.'); }
    $table = $kind === 'resource' ? 'learning_resources' : 'past_exams';
    $column = $kind === 'resource' ? 'resource_id' : 'past_exam_id';
    $statement = database_connection()->prepare(
        "SELECT x.storage_key,x.original_filename,x.mime_type,x.status,x.class_subject_id,
                EXISTS (SELECT 1 FROM users requesting WHERE requesting.id=:requester_id AND requesting.is_active) AS requester_active,
                e.user_id AS owner_user_id, EXISTS (SELECT 1 FROM manual_content_access a JOIN students st ON st.id=a.student_id JOIN class_subjects acs ON acs.id=x.class_subject_id WHERE st.user_id=:access_user AND st.class_id=acs.class_id AND a.revoked_at IS NULL) AS has_access,
                EXISTS (SELECT 1 FROM employees me JOIN teaching_assignments ta ON ta.employee_id=me.id WHERE me.user_id=:teacher_user AND me.is_active AND ta.class_subject_id=x.class_subject_id) AS assigned_teacher,
                EXISTS (SELECT 1 FROM content_reviews cr WHERE cr.{$column}=x.id AND cr.reviewer_id=(SELECT id FROM employees WHERE user_id=:reviewer_user)) AS reviewed_by_me
         FROM {$table} x JOIN employees e ON e.id=x.uploaded_by WHERE x.id=:id"
    );
    $statement->execute(['requester_id'=>$userId,'access_user'=>$userId,'teacher_user'=>$userId,'reviewer_user'=>$userId,'id'=>$itemId]);
    $row = $statement->fetch();
    if (!$row || !$row['storage_key']) { throw new InvalidArgumentException('الملف غير موجود أو رابط خارجي.'); }
    if (!(bool)$row['requester_active']) { throw new RuntimeException('الحساب غير نشط.'); }
    $allowed = $row['status'] === 'APPROVED' && ((bool)$row['has_access'] || user_has_permission('roles.manage'));
    $allowed = $allowed || (int)$row['owner_user_id'] === $userId || (bool)$row['assigned_teacher'] || (bool)$row['reviewed_by_me'];
    $allowed = $allowed || (user_has_permission('content.review') && in_array($row['status'], ['PENDING_REVIEW','DRAFT','REJECTED'], true));
    if (!$allowed) { throw new RuntimeException('ليس لديك صلاحية تنزيل هذا الملف.'); }
    $path = legacy_library_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['storage_key']);
    $root = legacy_library_storage_root() . DIRECTORY_SEPARATOR;
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, $root) || !is_file($real) || !is_readable($real)) { throw new RuntimeException('تعذر العثور على الملف الخاص.'); }
    return ['path'=>$real,'filename'=>$row['original_filename'] ?: basename($real),'mime'=>$row['mime_type'] ?: 'application/pdf'];
}
