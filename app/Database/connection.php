<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '5432');
    $database = env_value('DB_DATABASE', 'alesnad_platform');
    $username = env_value('DB_USERNAME', 'alesnad_app');
    $password = env_value('DB_PASSWORD', '');
    $sslmode = env_value('DB_SSLMODE', 'prefer');

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $database, $sslmode);
    $connection = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET TIME ZONE 'Africa/Khartoum'");

    return $connection;
}

function database_boolean(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}
