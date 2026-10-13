<?php
declare(strict_types=1);

function academic_query_id(string $name): ?int
{
    $value = $_GET[$name] ?? null;
    if ($value === null || $value === '') return null;
    return academic_id($value, $name);
}

function academic_query_pagination(): array
{
    $limit = $_GET['limit'] ?? '25';
    $offset = $_GET['offset'] ?? '0';
    $limit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $offset = filter_var($offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($limit === false || $offset === false) throw new InvalidArgumentException('limit من 1 إلى 100 وoffset عدد غير سالب.');
    return ['limit' => $limit, 'offset' => $offset];
}

function academic_include_inactive(): bool
{
    $value = $_GET['include_inactive'] ?? 'false';
    if (!in_array($value, ['true', 'false'], true)) throw new InvalidArgumentException('include_inactive يجب أن يكون true أو false.');
    return $value === 'true';
}

function api_academic_stages(): never
{
    $include = academic_include_inactive();
    if ($include) require_permission('academics.manage');
    json_response(200, ['data' => ['stages' => academic_list_stages($include)]]);
}

function api_academic_classes(): never
{
    $include = academic_include_inactive();
    if ($include) require_permission('academics.manage');
    json_response(200, ['data' => ['classes' => academic_list_classes(academic_query_id('stage_id'), $include)]]);
}

function api_academic_subjects(): never
{
    $include = academic_include_inactive();
    if ($include) require_permission('academics.manage');
    json_response(200, ['data' => ['subjects' => academic_list_subjects(academic_query_id('class_id'), $include)]]);
}

function api_academic_lessons(): never
{
    $filters = [];
    foreach (['stage_id', 'class_id', 'subject_id', 'class_subject_id'] as $field) {
        $value = academic_query_id($field);
        if ($value !== null) $filters[$field] = $value;
    }
    $includeDrafts = has_permission('academics.manage');
    if (!$includeDrafts) require_permission('content.read');
    json_response(200, ['data' => ['lessons' => academic_list_lessons($filters, $includeDrafts)]]);
}

function api_search_academic_content(): never
{
    require_permission('content.read');
    $filters = academic_query_pagination();
    foreach (['stage_id', 'class_id', 'class_subject_id', 'subject_id', 'lesson_id'] as $field) {
        $value = academic_query_id($field);
        if ($value !== null) $filters[$field] = $value;
    }
    $query = $_GET['q'] ?? '';
    if (!is_string($query)) throw new InvalidArgumentException('q يجب أن يكون نصًا.');
    $queryLength = preg_match_all('/./us', $query);
    if ($queryLength === false || $queryLength > 120) throw new InvalidArgumentException('q يجب أن يكون نصًا صالحًا وألا يتجاوز 120 محرفًا.');
    $filters['q'] = trim($query);
    json_response(200, ['data' => [
        'resources' => academic_search_resources($filters),
        'past_exams' => api_search_past_exams($filters),
        'filters' => array_intersect_key($filters, array_flip(['stage_id', 'class_id', 'class_subject_id', 'subject_id', 'lesson_id', 'q', 'limit', 'offset'])),
    ]]);
}

function api_admin_academic_create(string $kind, array $body): never
{
    $actor = require_permission('academics.manage');
    if ($kind === 'lessons' && ($body['is_published'] ?? false) === true) require_permission('content.publish');
    try {
        $row = match ($kind) {
            'stages' => academic_create_stage($body, (int) $actor['id']),
            'classes' => academic_create_class($body, (int) $actor['id']),
            'subjects' => academic_create_subject($body, (int) $actor['id']),
            'class-subjects' => academic_create_class_subject($body, (int) $actor['id']),
            'lessons' => academic_create_lesson($body, (int) $actor['id']),
            default => throw new LogicException('Unknown academic resource.'),
        };
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') throw new ApiException(409, 'academic_conflict', 'يوجد سجل بنفس الرمز أو الاسم أو الربط.');
        if ($error->getCode() === '23503') throw new ApiException(422, 'invalid_reference', 'أحد المراجع الأكاديمية غير موجود.');
        throw $error;
    }
    json_response(201, ['data' => ['item' => $row]]);
}

function api_admin_academic_update(string $kind, int $id, array $body): never
{
    $actor = require_permission('academics.manage');
    if ($kind === 'lessons' && array_key_exists('is_published', $body)) require_permission('content.publish');
    $table = match ($kind) {
        'stages' => 'stages', 'classes' => 'classes', 'subjects' => 'subjects',
        'class-subjects' => 'class_subjects', 'lessons' => 'lessons',
        default => throw new LogicException('Unknown academic resource.'),
    };
    try {
        $row = academic_update($table, $id, $body, (int) $actor['id']);
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') throw new ApiException(409, 'academic_conflict', 'يوجد سجل بنفس الرمز أو الاسم أو موضع الدرس.');
        if ($error->getCode() === '23503') throw new ApiException(422, 'invalid_reference', 'أحد المراجع الأكاديمية غير موجود.');
        throw $error;
    }
    if ($row === null) throw new ApiException(404, 'not_found', 'السجل الأكاديمي غير موجود.');
    json_response(200, ['data' => ['item' => $row]]);
}

function api_admin_assign_teacher(array $body): never
{
    $actor = require_permission('teaching_assignments.manage');
    try {
        $employeeId = academic_id($body['employee_id'] ?? null, 'employee_id');
        $classSubjectId = academic_id($body['class_subject_id'] ?? null, 'class_subject_id');
        academic_assign_teacher($employeeId, $classSubjectId, (int) $actor['id']);
    } catch (OutOfBoundsException $error) {
        throw new ApiException(404, 'not_found', $error->getMessage());
    }
    json_response(200, ['data' => ['assigned' => true]]);
}

function api_admin_unassign_teacher(int $employeeId, int $classSubjectId): never
{
    $actor = require_permission('teaching_assignments.manage');
    if (!academic_unassign_teacher($employeeId, $classSubjectId, (int) $actor['id'])) {
        throw new ApiException(404, 'not_found', 'إسناد الأستاذ غير موجود.');
    }
    json_response(200, ['data' => ['unassigned' => true]]);
}

function api_my_teaching_assignments(): never
{
    $user = require_role('teacher');
    json_response(200, ['data' => ['assignments' => academic_teacher_assignments((int) $user['id'])]]);
}
