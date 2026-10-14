<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
load_environment(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/app/Support/logger.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';
require_once dirname(__DIR__) . '/app/Security/audit.php';
require_once dirname(__DIR__) . '/app/Services/QuizService.php';

try {
    $count = quiz_classify_expired_attempts();
    fwrite(STDOUT, sprintf("Classified expired quiz attempts: %d\n", $count));
} catch (Throwable $error) {
    app_log_error('expired_quiz_attempt_processing_failed', $error);
    fwrite(STDERR, "Expired quiz attempt processing failed. Check protected application logs for details.\n");
    exit(1);
}
