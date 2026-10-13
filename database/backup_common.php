<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Support/logger.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';

function backup_environment(): array
{
    load_environment(dirname(__DIR__) . '/.env');
    $root = dirname(__DIR__);
    $directory = $root . '/storage/backups';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Backup directory is unavailable.');
    }
    if (is_link($directory)) throw new RuntimeException('Backup directory must not be a symbolic link.');
    chmod($directory, 0700);
    return [$directory, database_connection()];
}

function backup_pgpass_escape(string $value): string
{
    return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
}

function backup_pgpass_file(?string $additionalDatabase = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'alesnad-pgpass-');
    if ($path === false) throw new RuntimeException('Could not create protected PostgreSQL credentials file.');
    chmod($path, 0600);
    $host = env_value('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
    $port = env_value('DB_PORT', '5432') ?? '5432';
    $sourceDatabase = env_value('DB_DATABASE', 'alesnad_platform') ?? 'alesnad_platform';
    $username = env_value('DB_USERNAME', 'alesnad_app') ?? 'alesnad_app';
    $password = env_value('DB_PASSWORD', '') ?? '';
    $databases = array_unique(array_filter([$sourceDatabase, $additionalDatabase], static fn ($database) => is_string($database) && $database !== ''));
    $lines = [];
    foreach ($databases as $database) $lines[] = implode(':', array_map('backup_pgpass_escape', [$host, $port, $database, $username, $password]));
    $line = implode("\n", $lines) . "\n";
    if (file_put_contents($path, $line, LOCK_EX) === false) {
        @unlink($path);
        throw new RuntimeException('Could not write protected PostgreSQL credentials file.');
    }
    chmod($path, 0600);
    return $path;
}

function backup_run_pg_tool(array $arguments, string $pgpassFile): void
{
    $environment = array_merge(getenv() ?: [], [
        'PGPASSFILE' => $pgpassFile,
        'PGSSLMODE' => env_value('DB_SSLMODE', 'prefer') ?? 'prefer',
        'LC_ALL' => 'C',
    ]);
    $process = proc_open($arguments, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['file', '/dev/null', 'a'],
    ], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start PostgreSQL backup utility.');
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('PostgreSQL backup utility failed.');
}

function backup_pg_connection_arguments(?string $database = null): array
{
    return [
        '--host=' . (env_value('DB_HOST', '127.0.0.1') ?? '127.0.0.1'),
        '--port=' . (env_value('DB_PORT', '5432') ?? '5432'),
        '--username=' . (env_value('DB_USERNAME', 'alesnad_app') ?? 'alesnad_app'),
        '--dbname=' . ($database ?? (env_value('DB_DATABASE', 'alesnad_platform') ?? 'alesnad_platform')),
        '--no-password',
    ];
}

function backup_target_database_connection(string $database): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        env_value('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
        env_value('DB_PORT', '5432') ?? '5432',
        $database,
        env_value('DB_SSLMODE', 'prefer') ?? 'prefer'
    );
    $connection = new PDO($dsn, env_value('DB_USERNAME', 'alesnad_app') ?? 'alesnad_app', env_value('DB_PASSWORD', '') ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET TIME ZONE 'Africa/Khartoum'");
    return $connection;
}

function backup_record_failed(PDO $pdo, int $recordId, Throwable $error): void
{
    try {
        $statement = $pdo->prepare("UPDATE backup_records SET status = 'FAILED', notes = :notes WHERE id = :id");
        $statement->execute(['notes' => 'Backup command failed (' . basename(str_replace('\\', '/', get_class($error))) . ').', 'id' => $recordId]);
    } catch (Throwable $recordError) {
        app_log_error('backup_failure_record_failed', $recordError);
    }
}
