<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_common.php';
load_environment(dirname(__DIR__) . '/.env');

umask(0077);
ini_set('display_errors', '0');
$pgpassFile = null;
$temporaryPath = null;
$recordId = null;
$pdo = null;
$failed = false;

try {
    [$backupDirectory, $pdo] = backup_environment();
    $key = 'db-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8)) . '.dump';
    $finalPath = $backupDirectory . '/' . $key;
    $temporaryPath = $backupDirectory . '/.' . $key . '.tmp';

    $insert = $pdo->prepare("INSERT INTO backup_records (storage_key, status, notes) VALUES (:storage_key, 'CREATED', 'pg_dump custom-format backup') RETURNING id");
    $insert->execute(['storage_key' => $key]);
    $recordId = (int) $insert->fetchColumn();

    $pgpassFile = backup_pgpass_file();
    backup_run_pg_tool(array_merge(['pg_dump'], backup_pg_connection_arguments(), [
        '--format=custom', '--no-owner', '--no-acl', '--file=' . $temporaryPath,
    ]), $pgpassFile);

    if (!is_file($temporaryPath) || filesize($temporaryPath) === false || filesize($temporaryPath) < 1) {
        throw new RuntimeException('pg_dump did not produce a non-empty archive.');
    }
    chmod($temporaryPath, 0600);
    if (!rename($temporaryPath, $finalPath)) throw new RuntimeException('Could not finalize backup archive.');
    $temporaryPath = null;
    $checksum = hash_file('sha256', $finalPath);
    $size = filesize($finalPath);
    if ($checksum === false || $size === false) throw new RuntimeException('Could not verify backup archive.');

    $update = $pdo->prepare("UPDATE backup_records SET checksum_sha256 = :checksum, size_bytes = :size, status = 'VERIFIED', verified_at = CURRENT_TIMESTAMP, notes = NULL WHERE id = :id");
    $update->execute(['checksum' => $checksum, 'size' => $size, 'id' => $recordId]);
    printf("Backup verified: %s (%d bytes)\n", $key, $size);
} catch (Throwable $error) {
    if ($recordId !== null && $pdo instanceof PDO) backup_record_failed($pdo, $recordId, $error);
    app_log_error('database_backup_failed', $error);
    fwrite(STDERR, "Database backup failed. Check protected application logs for error metadata.\n");
    $failed = true;
} finally {
    if (is_string($temporaryPath) && is_file($temporaryPath)) @unlink($temporaryPath);
    if (is_string($pgpassFile) && is_file($pgpassFile)) @unlink($pgpassFile);
}
if ($failed) exit(1);
