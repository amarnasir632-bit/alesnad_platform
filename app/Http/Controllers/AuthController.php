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
    if (!is_string($body['full_name'] ?? null) || !is_string($body['email'] ?? null) || !is_string($body['phone'] ?? '')) {
        throw new ApiException(422, 'validation_failed', 'الاسم والبريد والهاتف يجب أن تكون نصوصًا.');
    }
    $name = trim($body['full_name']);
    $email = strtolower(trim($body['email']));
    $phone = trim((string) ($body['phone'] ?? ''));
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    $length = preg_match_all('/./us', $name);
    if ($length === false || $length < 2 || $length > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException(422, 'validation_failed', 'الاسم أو البريد الإلكتروني غير صحيح.');
    }
    if (($error = password_validation_error($password)) !== null) throw new ApiException(422, 'validation_failed', $error);
    try {
        register_student($name, $email, $phone, $password);
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') throw new ApiException(409, 'email_exists', 'البريد الإلكتروني مسجل بالفعل.');
        throw $error;
    }
    json_response(201, ['data' => ['user' => current_user(), 'csrf_token' => csrf_token()]]);
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
