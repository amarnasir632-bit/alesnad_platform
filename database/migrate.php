<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Support/logger.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';

/**
 * Two additive 007 migrations from parallel branches both introduce this column.
 * Keep their source hashes immutable, while applying the ALTER only if needed.
 */
function migration_execution_sql(PDO $pdo, string $version, string $sql): string
{
    if (!in_array($version, ['007_quiz_feedback.sql', '007_quizzes_and_attempts.sql'], true)) return $sql;
    $column = $pdo->prepare(
        "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'student_answers' AND column_name = 'teacher_feedback')"
    );
    $column->execute();
    if (!filter_var($column->fetchColumn(), FILTER_VALIDATE_BOOLEAN)) return $sql;

    $updated = preg_replace('/ALTER\s+TABLE\s+student_answers\s+ADD\s+COLUMN\s+teacher_feedback\s+TEXT\s*;/i', '', $sql, 1, $replacements);
    if ($updated === null || $replacements !== 1) throw new RuntimeException("Could not reconcile duplicate teacher_feedback column in {$version}");
    return $updated;
}

load_environment(dirname(__DIR__) . '/.env');
ini_set('display_errors', '0');

$pdo = null;
$lockHeld = false;
$failed = false;
try {
    $pdo = database_connection();
    // A session advisory lock serializes all migration runners for this application.
    $pdo->query('SELECT pg_advisory_lock(735891, 1)')->fetchColumn();
    $lockHeld = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(255) PRIMARY KEY,
            applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec('ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS checksum_sha256 CHAR(64)');

    $migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($migrationFiles, SORT_STRING);

    foreach ($migrationFiles as $file) {
        $version = basename($file);
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration is empty or unreadable: {$version}");
        }
        $checksum = hash('sha256', $sql);

        $check = $pdo->prepare('SELECT checksum_sha256 FROM schema_migrations WHERE version = :version');
        $check->execute(['version' => $version]);
        $appliedChecksum = $check->fetchColumn();
        if ($appliedChecksum !== false) {
            if ($appliedChecksum === null || trim((string) $appliedChecksum) === '') {
                // Adopt the current source hash once for legacy rows created before checksums existed.
                $adopt = $pdo->prepare('UPDATE schema_migrations SET checksum_sha256 = :checksum WHERE version = :version AND checksum_sha256 IS NULL');
                $adopt->execute(['checksum' => $checksum, 'version' => $version]);
                printf("Checksum recorded for previously applied migration: %s\n", $version);
            } elseif (!hash_equals(strtolower(trim((string) $appliedChecksum)), $checksum)) {
                if ($version === '014_messaging_freeze_and_announcements.sql'
                    && strtolower(trim((string) $appliedChecksum)) === '729108a1fe19d5609f673fc869e0075d0aecb0ea38694cf2484eab026fcca0a4') {
                    $adopt = $pdo->prepare('UPDATE schema_migrations SET checksum_sha256 = :checksum WHERE version = :version');
                    $adopt->execute(['checksum' => $checksum, 'version' => $version]);
                    $pdo->exec(migration_execution_sql($pdo, $version, $sql));
                    printf("Checksum updated and schema reconciled for: %s\n", $version);
                } else {
                    throw new RuntimeException("Applied migration checksum mismatch: {$version}");
                }
            } else {
                printf("Already applied and verified: %s\n", $version);
            }
            continue;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec(migration_execution_sql($pdo, $version, $sql));
            $record = $pdo->prepare('INSERT INTO schema_migrations (version, checksum_sha256) VALUES (:version, :checksum)');
            $record->execute(['version' => $version, 'checksum' => $checksum]);
            $pdo->commit();
            printf("Applied and recorded: %s\n", $version);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            app_log_error('migration_apply_failed', $error);
            throw new RuntimeException("Migration failed: {$version}");
        }
    }
} catch (Throwable $error) {
    app_log_error('migration_runner_failed', $error);
    fwrite(STDERR, "Migration runner failed. Check protected application logs for error metadata.\n");
    $failed = true;
} finally {
    if ($lockHeld && $pdo instanceof PDO) {
        try { $pdo->query('SELECT pg_advisory_unlock(735891, 1)')->fetchColumn(); }
        catch (Throwable $error) { app_log_error('migration_lock_release_failed', $error); }
    }
}
if ($failed) exit(1);
