<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';

load_environment(dirname(__DIR__) . '/.env');

$pdo = database_connection();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

$migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($migrationFiles, SORT_STRING);

foreach ($migrationFiles as $file) {
    $version = basename($file);
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
    $check->execute(['version' => $version]);
    if ($check->fetchColumn()) {
        printf("Already applied: %s\n", $version);
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Migration is empty or unreadable: {$version}");
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $record->execute(['version' => $version]);
        $pdo->commit();
        printf("Applied: %s\n", $version);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Migration failed: {$version}\n");
        throw $error;
    }
}
