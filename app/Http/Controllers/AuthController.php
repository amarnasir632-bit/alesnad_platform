<?php
declare(strict_types=1);

function api_csrf(): never
{
    json_response(200, ['data' => ['csrf_token' => csrf_token()]]);
}

function api_login(array $body): never
{
    if (!is_string($body['email'] ?? null)) throw new ApiException(422, 'validation_failed', 'البريد أو كلمة المرور غير صحيحة.');
    $email = strtolower(trim($body['email']));
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') throw new ApiException(422, 'validation_failed', 'البريد أو كلمة المرور غير صحيحة.');
    if (login_is_locked($email)) {
        audit_log('auth.login.blocked', 'user', null, ['email_key' => login_email_key($email)]);
        throw new ApiException(429, 'login_rate_limited', 'تعذر تسجيل الدخول مؤقتًا. حاول لاحقًا.');
    }
    if (!authenticate_user($email, $password)) throw new ApiException(401, 'invalid_credentials', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.');
    json_response(200, ['data' => ['user' => current_user(), 'csrf_token' => csrf_token()]]);
}

function api_register_student(array $body): never
{
    if (!is_string($body['full_name'] ?? null) || !is_string($body['email'] ?? null) || !is_string($body['phone'] ?? '') || !array_key_exists('class_id', $body)) {
        throw new ApiException(422, 'validation_failed', 'الاسم والبريد والهاتف والصف مطلوبة بصيغ صحيحة.');
    }
    $name = trim($body['full_name']);
    $email = strtolower(trim($body['email']));
    $phone = trim((string) ($body['phone'] ?? ''));
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    $classId = academic_id($body['class_id'], 'class_id');
    $length = preg_match_all('/./us', $name);
    if ($length === false || $length < 2 || $length > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException(422, 'validation_failed', 'الاسم أو البريد الإلكتروني غير صحيح.');
    }
    if (($error = password_validation_error($password)) !== null) throw new ApiException(422, 'validation_failed', $error);
    try {
        register_student($name, $email, $phone, $password, $classId);
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') throw new ApiException(409, 'email_exists', 'البريد الإلكتروني مسجل بالفعل.');
        throw $error;
    }
    json_response(201, ['data' => ['registration_received' => true, 'status' => 'PENDING']]);
}

function api_logout(): never
{
    $user = refresh_authenticated_user();
    if ($user !== null) audit_log('auth.logout', 'user', (int) $user['id']);
    logout_user();
    session_start();
    session_regenerate_id(true);
    json_response(200, ['data' => ['logged_out' => true, 'csrf_token' => csrf_token()]]);
}

function api_current_user(): never
{
    $user = refresh_authenticated_user();
    if ($user === null) throw new ApiException(401, 'unauthenticated', 'سجّل الدخول للمتابعة.');
    json_response(200, ['data' => ['user' => $user]]);
}

function api_request_password_reset(array $body): never
{
    $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiException(422, 'validation_failed', 'أدخل بريدًا إلكترونيًا صحيحًا.');
    try {
        request_password_reset($email);
    } catch (Throwable $error) {
        app_log_error('password_reset_email_failed', $error);
        // Keep the public response identical for known and unknown accounts.
    }
    json_response(200, ['data' => ['message' => 'إذا كان البريد مرتبطًا بحساب، فستصلك رسالة برابط استعادة صالح لمدة محدودة.']]);
}

function api_complete_password_reset(array $body): never
{
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    try {
        consume_password_reset($token, $password);
    } catch (InvalidArgumentException $error) {
        throw new ApiException(422, 'password_reset_invalid', $error->getMessage());
    }
    json_response(200, ['data' => ['password_reset' => true]]);
}

function api_complete_admission(array $body): never
{
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    try {
        complete_student_admission($token, $body);
    } catch (InvalidArgumentException $error) {
        throw new ApiException(422, 'admission_details_invalid', $error->getMessage());
    }
    json_response(200, ['data' => ['registration_received' => true, 'status' => 'PENDING']]);
}

function api_request_profile_correction(array $body): never
{
    $actor = require_login();
    try {
        $requestId = submit_student_profile_correction((int) $actor['id'], $body);
    } catch (InvalidArgumentException $error) {
        throw new ApiException(422, 'profile_correction_invalid', $error->getMessage());
    }
    json_response(201, ['data' => ['request_id' => $requestId, 'status' => 'PENDING']]);
}

function api_review_student_admission(int $studentId, array $body): never
{
    $actor = require_permission('admissions.manage');
    try {
        json_response(200, ['data' => ['admission' => review_student_admission($studentId, (int) $actor['id'], $body)]]);
    } catch (OutOfBoundsException $error) {
        throw new ApiException(404, 'admission_not_found', $error->getMessage());
    }
}

function api_review_profile_correction(int $requestId, array $body): never
{
    $actor = require_permission('admissions.manage');
    try {
        review_profile_correction_request(
            $requestId,
            (int) $actor['id'],
            is_string($body['status'] ?? null) ? strtoupper($body['status']) : '',
            is_string($body['resolution_reason'] ?? '') ? $body['resolution_reason'] : ''
        );
    } catch (OutOfBoundsException $error) {
        throw new ApiException(404, 'correction_not_found', $error->getMessage());
    }
    json_response(200, ['data' => ['reviewed' => true]]);
}

function api_export_student_admissions(): never
{
    require_permission('admissions.manage');
    $filters = admission_filters($_GET);
    $rows = list_student_admissions($filters, 200, 0);
    audit_log('admission.exported', 'student', null, ['count' => count($rows), 'filters' => array_keys($filters)]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="student-admissions.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) throw new RuntimeException('Unable to open CSV output stream.');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['student_number', 'full_name', 'email', 'phone', 'stage', 'class', 'status', 'applied_at']);
    $csvValue = static function (?string $value): string {
        $value ??= '';
        return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) ? "'" . $value : $value;
    };
    foreach ($rows as $row) {
        fputcsv($output, [
            $csvValue($row['student_number']),
            $csvValue($row['full_name']),
            $csvValue($row['email']),
            $csvValue($row['phone']),
            $csvValue($row['stage_name']),
            $csvValue($row['class_name']),
            $csvValue($row['registration_status']),
            $csvValue($row['applied_at']),
        ]);
    }
    fclose($output);
    exit;
}

function api_reviewed_admission_request(int $studentId): never
{
    require_permission('admissions.manage');
    $admission = find_student_admission($studentId);
    if (!$admission) throw new ApiException(404, 'admission_not_found', 'طلب التسجيل غير موجود.');
    $events = database_connection()->prepare(
        'SELECT previous_status, new_status, reason, actor_user_id, created_at
         FROM student_admission_events WHERE student_id = :id ORDER BY created_at DESC, id DESC'
    );
    $events->execute(['id' => $studentId]);
    $corrections = database_connection()->prepare(
        "SELECT cr.id, cr.requested_changes, cr.reason, cr.status, cr.resolution_reason,
                cr.requested_at, cr.reviewed_at, cr.reviewed_by, u.full_name AS requester_name
         FROM student_profile_correction_requests cr
         JOIN users u ON u.id = cr.user_id
         WHERE u.id = :user_id ORDER BY cr.requested_at DESC, cr.id DESC LIMIT 25"
    );
    $corrections->execute(['user_id' => $admission['user_id']]);
    $correctionRows = $corrections->fetchAll();
    foreach ($correctionRows as &$row) {
        if (!is_array($row['requested_changes'])) $row['requested_changes'] = json_decode((string) $row['requested_changes'], true, 16, JSON_THROW_ON_ERROR);
    }
    json_response(200, ['data' => [
        'admission' => $admission,
        'history' => $events->fetchAll(),
        'corrections' => $correctionRows,
    ]]);
}
