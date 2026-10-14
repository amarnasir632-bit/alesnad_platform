<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

putenv('DATABASE_URL=postgresql://demo%40user:p%40ss%3Aword@ep-example.neon.tech:5432/alesnad?sslmode=require');
$settings = database_connection_parameters();
$assert($settings['host'] === 'ep-example.neon.tech' && $settings['port'] === '5432', 'Neon host and port are parsed');
$assert($settings['database'] === 'alesnad' && $settings['username'] === 'demo@user', 'database and encoded username are parsed');
$assert($settings['password'] === 'p@ss:word' && $settings['sslmode'] === 'require', 'encoded password and SSL mode are parsed');

putenv('DATABASE_URL=not-a-postgresql-url');
$thrown = false;
try {
    database_connection_parameters();
} catch (RuntimeException) {
    $thrown = true;
}
$assert($thrown, 'invalid database URLs are rejected');
putenv('DATABASE_URL');
echo "Database URL configuration unit checks passed.\n";
