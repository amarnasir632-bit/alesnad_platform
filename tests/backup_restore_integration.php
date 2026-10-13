<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/env.php';
require dirname(__DIR__) . '/app/Database/connection.php';
load_environment(dirname(__DIR__) . '/.env');

$sourceName = env_value('DB_DATABASE', '') ?? '';
if (!str_starts_with($sourceName, 'alesnad_test_')) {
    fwrite(STDERR, "Refusing backup/restore integration test: DB_DATABASE must begin with alesnad_test_.\n");
    exit(2);
}
$pdo = database_connection();
$targetName = 'alesnad_test_restore_' . bin2hex(random_bytes(5));
$backupKey = null;
$archivePath = null;
$targetCreated = false;
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $label;
    printf("%s %s\n", $condition ? 'PASS' : 'FAIL', $label);
};

$run = static function (array $command, array $extraEnv = []): array {
    $env = array_merge(getenv() ?: [], $extraEnv);
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
    if (!is_resource($process)) throw new RuntimeException('Could not start integration-test command.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
};

try {
    [$backupExit, $backupOutput] = $run([PHP_BINARY, dirname(__DIR__) . '/database/backup.php']);
    $assert($backupExit === 0 && preg_match('/^Backup verified: ([A-Za-z0-9TZ-]+-[a-f0-9]{16}\.dump) \(/m', $backupOutput, $match) === 1,
        'pg_dump creates a verified private backup');
    if ($backupExit !== 0 || !isset($match[1])) throw new RuntimeException('Backup command failed in the test environment.');
    $backupKey = $match[1];
    $archivePath = dirname(__DIR__) . '/storage/backups/' . $backupKey;
    $record = $pdo->prepare("SELECT checksum_sha256, size_bytes, status FROM backup_records WHERE storage_key=:key");
    $record->execute(['key' => $backupKey]);
    $metadata = $record->fetch();
    $assert($metadata !== false && $metadata['status'] === 'VERIFIED' && hash_equals(trim($metadata['checksum_sha256']), hash_file('sha256', $archivePath)),
        'backup checksum and status match the stored archive');

    $pdo->exec('CREATE DATABASE "' . $targetName . '"');
    $targetCreated = true;
    [$restoreExit, $restoreOutput] = $run(
        [PHP_BINARY, dirname(__DIR__) . '/database/restore.php', '--backup=' . $backupKey, '--confirm-target=' . $targetName],
        ['RESTORE_TARGET_DATABASE' => $targetName]
    );
    $assert($restoreExit === 0 && str_contains($restoreOutput, 'Restore completed and recorded.'), 'verified archive restores to an explicitly confirmed isolated database');
    if ($restoreExit !== 0) throw new RuntimeException('Restore command failed in the test environment.');

    $targetPdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', env_value('DB_HOST', '127.0.0.1'), env_value('DB_PORT', '5432'), $targetName, env_value('DB_SSLMODE', 'prefer')),
        env_value('DB_USERNAME', 'postgres'), env_value('DB_PASSWORD', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $migrationCount = (int) $targetPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $restoreRecord = $targetPdo->prepare("SELECT status, checksum_sha256 FROM backup_records WHERE storage_key=:key");
    $restoreRecord->execute(['key' => $backupKey]);
    $restored = $restoreRecord->fetch();
    $assert($migrationCount >= 9, 'restored database contains its applied migration history');
    $assert($restored !== false && $restored['status'] === 'RESTORED' && hash_equals(trim($metadata['checksum_sha256']), trim($restored['checksum_sha256'])),
        'restored database records the backup as RESTORED with matching SHA-256');
    $targetPdo = null;
} catch (Throwable $error) {
    $failures[] = 'unexpected exception: ' . $error->getMessage();
    fwrite(STDERR, "ERROR " . $error->getMessage() . "\n");
} finally {
    if ($targetCreated) {
        try { $pdo->exec('DROP DATABASE IF EXISTS "' . $targetName . '" WITH (FORCE)'); }
        catch (Throwable $error) { fwrite(STDERR, "Could not drop isolated restore-test database.\n"); }
    }
    if ($backupKey !== null) {
        $pdo->prepare('DELETE FROM backup_records WHERE storage_key=:key')->execute(['key' => $backupKey]);
        if ($archivePath !== null && is_file($archivePath)) @unlink($archivePath);
    }
}

printf("%d assertions, %d failures\n", $assertions, count($failures));
exit($failures ? 1 : 0);
