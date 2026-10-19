<?php
declare(strict_types=1);

function api_list_accounts(): never
{
    require_permission('roles.manage');
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    $offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
    json_response(200, ['data' => list_accounts($limit ?: 50, $offset ?: 0)]);
}

function api_list_roles(): never
{
    require_permission('roles.manage');
    $statement = database_connection()->prepare('SELECT code, display_name, description FROM roles ORDER BY code');
    $statement->execute();
    json_response(200, ['data' => ['roles' => $statement->fetchAll()]]);
}

function api_create_account(array $body): never
{
    $actor = require_permission('users.manage');
    if (!has_permission('roles.manage')) throw new ApiException(403, 'forbidden', 'تحتاج صلاحية إدارة الأدوار لإنشاء حساب بأدواره.');
    try {
        $account = create_managed_account($body, (int) $actor['id']);
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') throw new ApiException(409, 'account_conflict', 'البريد أو رقم الحساب مستخدم بالفعل.');
        throw $error;
    }
    json_response(201, ['data' => ['user' => $account]]);
}

function api_update_account(int $id, array $body): never
{
    require_permission('roles.manage');
    try {
        json_response(200, ['data' => ['user' => update_managed_account($id, $body)]]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') throw new ApiException(409, 'account_conflict', 'البريد الإلكتروني مستخدم بالفعل.');
        throw $error;
    } catch (OutOfBoundsException $error) {
        throw new ApiException(404, 'not_found', $error->getMessage());
    } catch (DomainException $error) {
        throw new ApiException(409, 'last_admin', $error->getMessage());
    }
}

function api_assign_account_roles(int $id, array $body): never
{
    $actor = require_permission('roles.manage');
    try {
        json_response(200, ['data' => ['user' => assign_account_roles($id, $body['roles'] ?? null, (int) $actor['id'])]]);
    } catch (OutOfBoundsException $error) {
        throw new ApiException(404, 'not_found', $error->getMessage());
    } catch (DomainException $error) {
        throw new ApiException(409, 'last_admin', $error->getMessage());
    }
}

function api_teacher_profile_photo(int $employeeId): never
{
    require_login();
    $statement = database_connection()->prepare(
        "SELECT e.profile_image_key, e.profile_image_mime
         FROM employees e
         JOIN users u ON u.id = e.user_id AND u.is_active
         WHERE e.id = :employee_id AND e.is_active
           AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                       WHERE ur.user_id = u.id AND r.code = 'teacher')"
    );
    $statement->execute(['employee_id' => $employeeId]);
    $profile = $statement->fetch();
    if (!$profile || !is_string($profile['profile_image_key'] ?? null)
        || !is_string($profile['profile_image_mime'] ?? null)) {
        throw new ApiException(404, 'not_found', 'صورة الأستاذ غير متاحة.');
    }
    $filename = match ($profile['profile_image_mime']) {
        'image/jpeg' => 'teacher-profile.jpg',
        'image/png' => 'teacher-profile.png',
        'image/webp' => 'teacher-profile.webp',
        default => throw new ApiException(404, 'not_found', 'صورة الأستاذ غير متاحة.'),
    };
    if (resolve_private_file($profile['profile_image_key']) === null) {
        app_log_error('teacher_profile_image_missing', new RuntimeException('Stored teacher profile image is missing.'));
        throw new ApiException(404, 'not_found', 'صورة الأستاذ غير متاحة.');
    }
    stream_private_file($profile['profile_image_key'], $profile['profile_image_mime'], $filename, true);
}
