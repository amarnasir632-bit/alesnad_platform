<?php
declare(strict_types=1);

function subscription_database_error(PDOException $error): never
{
    if ($error->getCode() === '23505') throw new ApiException(409, 'subscription_conflict', 'يوجد رمز خطة مستخدم مسبقًا.');
    if ($error->getCode() === '23503') throw new ApiException(422, 'invalid_reference', 'الطالب أو الخطة غير موجودة.');
    if ($error->getCode() === '23514') throw new ApiException(422, 'subscription_validation_failed', 'رفضت قاعدة البيانات بيانات الخطة أو الاشتراك.');
    throw $error;
}

function api_list_subscription_plans(): never
{
    require_permission('subscriptions.read_own');
    json_response(200, ['data' => ['plans' => subscription_plan_list(false)]]);
}

function api_admin_subscription_plans(): never
{
    require_permission('subscriptions.manage');
    json_response(200, ['data' => ['plans' => subscription_plan_list(true)]]);
}

function api_admin_create_subscription_plan(array $input): never
{
    $actor = require_permission('subscriptions.manage');
    try { json_response(201, ['data' => ['plan' => subscription_create_plan($input, (int) $actor['id'])]]); }
    catch (PDOException $error) { subscription_database_error($error); }
}

function api_admin_update_subscription_plan(int $id, array $input): never
{
    $actor = require_permission('subscriptions.manage');
    try { json_response(200, ['data' => ['plan' => subscription_update_plan($id, $input, (int) $actor['id'])]]); }
    catch (PDOException $error) { subscription_database_error($error); }
}

function api_current_subscriptions(): never
{
    $actor = require_permission('subscriptions.read_own');
    json_response(200, ['data' => ['subscriptions' => subscription_list_own((int) $actor['id'])]]);
}

function api_admin_subscriptions(): never
{
    $actor = require_permission('subscriptions.manage');
    $filters = [];
    if (isset($_GET['student_id']) && $_GET['student_id'] !== '') $filters['student_id'] = subscription_positive_id($_GET['student_id'], 'student_id');
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $status = is_string($_GET['status']) ? strtoupper(trim($_GET['status'])) : '';
        if (!in_array($status, ['PENDING', 'ACTIVE', 'EXPIRED', 'CANCELLED', 'REFUNDED'], true)) throw new ApiException(422, 'validation_failed', 'status غير صالح.');
        $filters['status'] = $status;
    }
    $limit = filter_var($_GET['limit'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $offset = filter_var($_GET['offset'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($limit === false || $offset === false) throw new ApiException(422, 'validation_failed', 'limit يجب أن يكون من 1 إلى 100 وoffset عددًا غير سالب.');
    $filters['limit'] = $limit;
    $filters['offset'] = $offset;
    json_response(200, ['data' => ['subscriptions' => subscription_list_admin($filters, (int) $actor['id'])]]);
}

function api_admin_create_subscription(array $input): never
{
    $actor = require_permission('subscriptions.manage');
    try { json_response(201, ['data' => ['subscription' => subscription_create_manual($input, (int) $actor['id'])]]); }
    catch (PDOException $error) { subscription_database_error($error); }
}

function api_admin_update_subscription(int $id, array $input): never
{
    $actor = require_permission('subscriptions.manage');
    try { json_response(200, ['data' => ['subscription' => subscription_update_manual($id, $input, (int) $actor['id'])]]); }
    catch (PDOException $error) { subscription_database_error($error); }
}

function api_admin_expire_subscriptions(): never
{
    $actor = require_permission('subscriptions.manage');
    $count = subscription_expire_all((int) $actor['id']);
    audit_log('subscription.expiration_requested', 'subscription', null, ['expired_count' => $count], (int) $actor['id']);
    json_response(200, ['data' => ['expired_count' => $count]]);
}
