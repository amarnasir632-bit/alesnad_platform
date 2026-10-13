<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_common.php';
load_environment(dirname(__DIR__) . '/.env');

umask(0077);
ini_set('display_errors', '0');
$pgpassFile = null;
$failed = false;

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--backup=')) $options['backup'] = substr($argument, 9);
        elseif (str_starts_with($argument, '--confirm-target=')) $options['confirm-target'] = substr($argument, 17);
        else throw new InvalidArgumentException('Unknown restore option.');
    }
    $sourceDatabase = env_value('DB_DATABASE', 'alesnad_platform') ?? 'alesnad_platform';
    $targetDatabase = env_value('RESTORE_TARGET_DATABASE', $sourceDatabase) ?? $sourceDatabase;
    if (!preg_match('/^[A-Za-z0-9_.-]{1,63}$/D', $targetDatabase)) throw new InvalidArgumentException('Restore target database name is invalid.');
    if (($options['backup'] ?? '') === '' || ($options['confirm-target'] ?? '') !== $targetDatabase) {
        throw new InvalidArgumentException('Restore requires --backup=<verified-key> and an exact --confirm-target=<RESTORE_TARGET_DATABASE>.');
    }
    if (basename($options['backup']) !== $options['backup'] || !preg_match('/^db-[A-Za-z0-9TZ-]+-[a-f0-9]{16}\.dump$/D', $options['backup'])) {
        throw new InvalidArgumentException('Backup key is invalid.');
    }

    [$backupDirectory, $pdo] = backup_environment();
    $path = $backupDirectory . '/' . $options['backup'];
    $directoryReal = realpath($backupDirectory);
    $pathReal = realpath($path);
    if ($directoryReal === false || $pathReal === false || dirname($pathReal) !== $directoryReal || !is_file($pathReal) || is_link($path)) {
        throw new InvalidArgumentException('Backup archive is not present in the private backup directory.');
    }

    $record = $pdo->prepare("SELECT id, checksum_sha256, status FROM backup_records WHERE storage_key = :storage_key");
    $record->execute(['storage_key' => $options['backup']]);
    $backup = $record->fetch();
    if (!$backup || $backup['status'] !== 'VERIFIED' || !is_string($backup['checksum_sha256']) || !preg_match('/^[a-f0-9]{64}$/i', trim($backup['checksum_sha256']))) {
        throw new InvalidArgumentException('Backup record is not verified.');
    }
    $actualChecksum = hash_file('sha256', $pathReal);
    if ($actualChecksum === false || !hash_equals(strtolower(trim($backup['checksum_sha256'])), $actualChecksum)) {
        throw new RuntimeException('Backup checksum does not match its record.');
    }

    $pgpassFile = backup_pgpass_file($targetDatabase);
    backup_run_pg_tool(['pg_restore', '--list', $pathReal], $pgpassFile);
    fwrite(STDOUT, "Restoring verified backup to database '{$targetDatabase}'. Existing objects may be replaced.\n");
    backup_run_pg_tool(array_merge(['pg_restore'], backup_pg_connection_arguments($targetDatabase), [
        '--clean', '--if-exists', '--exit-on-error', '--single-transaction', '--no-owner', '--no-acl', $pathReal,
    ]), $pgpassFile);

    $restoredPdo = backup_target_database_connection($targetDatabase);
    $restoreRecord = $restoredPdo->prepare("INSERT INTO backup_records (storage_key, checksum_sha256, size_bytes, status, verified_at, notes) VALUES (:storage_key, :checksum, :size, 'RESTORED', CURRENT_TIMESTAMP, 'Restore completed') ON CONFLICT (storage_key) DO UPDATE SET checksum_sha256 = EXCLUDED.checksum_sha256, size_bytes = EXCLUDED.size_bytes, status = 'RESTORED', verified_at = CURRENT_TIMESTAMP, notes = EXCLUDED.notes");
    $restoreRecord->execute(['storage_key' => $options['backup'], 'checksum' => $actualChecksum, 'size' => filesize($pathReal)]);
    fwrite(STDOUT, "Restore completed and recorded.\n");
} catch (Throwable $error) {
    app_log_error('database_restore_failed', $error);
    fwrite(STDERR, "Database restore failed. Check protected application logs for error metadata.\n");
    $failed = true;
} finally {
    if (is_string($pgpassFile) && is_file($pgpassFile)) @unlink($pgpassFile);
}
if ($failed) exit(1);
