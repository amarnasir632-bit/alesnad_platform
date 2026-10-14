<?php
declare(strict_types=1);

function quiz_database_error(PDOException $error): never
{
    if ($error->getCode() === '23514') throw new ApiException(409, 'quiz_state_conflict', 'رفضت قاعدة البيانات محتوى السؤال أو المحاولة.');
    if ($error->getCode() === '23503') throw new ApiException(422, 'invalid_reference', 'أحد مراجع الاختبار أو السؤال غير موجود.');
    if ($error->getCode() === '23505') throw new ApiException(409, 'quiz_conflict', 'توجد محاولة أو إجابة مسجلة بالفعل.');
    throw $error;
}

function quiz_query_filters(): array
{
    $filters = [];
    foreach (['stage_id', 'class_id', 'subject_id', 'class_subject_id', 'lesson_id'] as $field) {
        if (isset($_GET[$field]) && $_GET[$field] !== '') $filters[$field] = content_positive_id($_GET[$field], $field);
    }
    foreach (['question_type', 'difficulty'] as $field) {
        if (isset($_GET[$field]) && $_GET[$field] !== '') {
            if (!is_string($_GET[$field])) throw new ApiException(422, 'validation_failed', "مرشح {$field} غير صالح.");
            $filters[$field] = strtoupper(trim($_GET[$field]));
        }
    }
    return $filters;
}

function api_teacher_questions(): never
{
    $actor = require_permission('quizzes.manage');
    try {
        json_response(200, ['data' => ['questions' => quiz_list_questions($actor, quiz_query_filters())]]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_teacher_create_quiz(array $input): never
{
    $actor = require_permission('quizzes.manage');
    try {
        json_response(201, ['data' => ['quiz' => quiz_create($actor, $input)]]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_teacher_update_quiz(int $quizId, array $input): never
{
    $actor = require_permission('quizzes.manage');
    try {
        json_response(200, ['data' => ['quiz' => quiz_update($actor, $quizId, $input)]]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_student_quizzes(): never
{
    $actor = require_permission('quizzes.attempt');
    require_role('student');
    json_response(200, ['data' => ['quizzes' => quiz_student_list((int) $actor['id'])]]);
}

function api_student_quiz(int $quizId): never
{
    $actor = require_permission('quizzes.attempt');
    require_role('student');
    json_response(200, ['data' => ['quiz' => quiz_student_detail((int) $actor['id'], $quizId)]]);
}

function api_student_start_attempt(int $quizId): never
{
    $actor = require_permission('quizzes.attempt');
    require_role('student');
    try {
        json_response(201, ['data' => quiz_start_attempt($actor, $quizId)]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_student_save_answer(int $attemptId, int $questionId, array $input): never
{
    $actor = require_permission('quizzes.attempt');
    require_role('student');
    try {
        json_response(200, ['data' => ['answer' => quiz_save_answer($actor, $attemptId, $questionId, $input)]]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_student_submit_attempt(int $attemptId): never
{
    $actor = require_permission('quizzes.attempt');
    require_role('student');
    try {
        json_response(200, ['data' => ['attempt' => quiz_submit_attempt($actor, $attemptId)]]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_attempt_result(int $attemptId): never
{
    $actor = refresh_authenticated_user();
    if ($actor === null) throw new ApiException(401, 'unauthenticated', 'سجل الدخول أولًا.');
    if (in_array('student', $actor['roles'] ?? [], true)) require_permission('quizzes.attempt');
    else require_permission('quizzes.manage');
    try {
        json_response(200, ['data' => quiz_get_result($actor, $attemptId)]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_teacher_quiz_results(int $quizId): never
{
    $actor = require_permission('quizzes.manage');
    json_response(200, ['data' => ['attempts' => quiz_teacher_results($actor, $quizId)]]);
}

function api_teacher_grade_essay(int $attemptId, int $questionId, array $input): never
{
    $actor = require_permission('quizzes.manage');
    try {
        json_response(200, ['data' => quiz_grade_essay($actor, $attemptId, $questionId, $input)]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}

function api_admin_review_quiz_attempt(int $attemptId, array $input): never
{
    $actor = require_permission('quizzes.manage');
    try {
        json_response(200, ['data' => quiz_manager_review_attempt($actor, $attemptId, (string) ($input['decision'] ?? ''))]);
    } catch (PDOException $error) {
        quiz_database_error($error);
    }
}
