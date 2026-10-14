<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(public int $status, public string $apiCode, string $message)
    {
        parent::__construct($message);
    }
}
function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}
function database_boolean(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}
require dirname(__DIR__) . '/app/Services/ContentService.php';
require dirname(__DIR__) . '/app/Services/QuizService.php';

$assert = static function (bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
};
$expectApiError = static function (callable $fn, int $status, string $code): bool {
    try { $fn(); } catch (ApiException $error) { return $error->status === $status && $error->apiCode === $code; }
    return false;
};

foreach (['QUIZ_MAX_ATTEMPTS', 'QUIZ_DURATION_MINUTES', 'QUIZ_RESULT_VISIBILITY', 'QUIZ_INCOMPLETE_ATTEMPT_POLICY'] as $name) putenv($name);
$assert($expectApiError(static fn() => quiz_positive_setting('QUIZ_MAX_ATTEMPTS'), 503, 'quiz_not_configured'), 'attempt limit is mandatory');
$assert($expectApiError(static fn() => quiz_result_visibility(), 503, 'quiz_not_configured'), 'result visibility is mandatory');
putenv('QUIZ_MAX_ATTEMPTS=3');
putenv('QUIZ_DURATION_MINUTES=45');
putenv('QUIZ_RESULT_VISIBILITY=AFTER_MANAGER_APPROVAL');
putenv('QUIZ_INCOMPLETE_ATTEMPT_POLICY=MANAGER_REVIEW');
$assert(quiz_positive_setting('QUIZ_MAX_ATTEMPTS') === 3, 'attempt limit reads configured value');
$assert(quiz_positive_setting('QUIZ_DURATION_MINUTES') === 45, 'duration reads configured value');
$assert(quiz_result_visibility() === 'AFTER_MANAGER_APPROVAL', 'manager approval result policy reads configured value');
$assert(quiz_incomplete_policy() === 'MANAGER_REVIEW', 'manager review incomplete policy reads configured value');
$assert($expectApiError(static fn() => (function (): string { putenv('QUIZ_RESULT_VISIBILITY=UNKNOWN'); return quiz_result_visibility(); })(), 503, 'quiz_not_configured'), 'unsupported result policy is rejected');
$normalized = content_normalize_question_options([(object) ['option_text' => 'صحيح', 'is_correct' => true], (object) ['option_text' => 'خطأ', 'is_correct' => false]]);
$assert(count($normalized) === 2 && $normalized[0]['is_correct'] === true, 'JSON object options normalize correctly');
putenv('QUIZ_RESULT_VISIBILITY=AFTER_WINDOW');
$assert($expectApiError(static fn() => content_validate_question_option_set('MCQ', [['is_correct' => true]]), 422, 'question_options_invalid'), 'MCQ requires at least two options');
$assert($expectApiError(static fn() => content_validate_question_option_set('MCQ', [['is_correct' => false], ['is_correct' => false]]), 422, 'correct_option_required'), 'objective question requires correct option');
$assert($expectApiError(static fn() => content_validate_question_option_set('TRUE_FALSE', [['is_correct' => true]]), 422, 'question_options_invalid'), 'true/false requires exactly two options');
content_validate_question_option_set('MCQ', [['is_correct' => true], ['is_correct' => false]]);
content_validate_question_option_set('TRUE_FALSE', [['is_correct' => true], ['is_correct' => false]]);
echo "PASS valid MCQ and true/false definitions\n";
