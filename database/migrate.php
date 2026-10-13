<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Support/logger.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';

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
                throw new RuntimeException("Applied migration checksum mismatch: {$version}");
            } else {
                printf("Already applied and verified: %s\n", $version);
            }
            continue;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
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
