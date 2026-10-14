<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $params = database_connection_parameters();
    $host = $params['host'];
    $port = $params['port'];
    $database = $params['database'];
    $username = $params['username'];
    $password = $params['password'];
    $sslmode = $params['sslmode'];

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $database, $sslmode);
    $connection = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET TIME ZONE 'Africa/Khartoum'");

    return $connection;
}

/** @return array{host:string,port:string,database:string,username:string,password:string,sslmode:string} */
function database_connection_parameters(): array
{
    $url = trim((string) env_value('DATABASE_URL', ''));
    if ($url !== '') {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)
            || empty($parts['host']) || empty($parts['path']) || !isset($parts['user'], $parts['pass'])) {
            throw new RuntimeException('DATABASE_URL must be a valid PostgreSQL connection URL.');
        }
        $options = [];
        parse_str((string) ($parts['query'] ?? ''), $options);
        $sslmode = (string) ($options['sslmode'] ?? 'require');
        $database = rawurldecode(ltrim((string) $parts['path'], '/'));
        $host = (string) $parts['host'];
        $port = (string) ($parts['port'] ?? 5432);
        $username = rawurldecode((string) $parts['user']);
        $password = rawurldecode((string) $parts['pass']);
    } else {
        $host = (string) env_value('DB_HOST', '127.0.0.1');
        $port = (string) env_value('DB_PORT', '5432');
        $database = (string) env_value('DB_DATABASE', 'alesnad_platform');
        $username = (string) env_value('DB_USERNAME', 'alesnad_app');
        $password = (string) env_value('DB_PASSWORD', '');
        $sslmode = (string) env_value('DB_SSLMODE', 'prefer');
    }
    if ($host === '' || str_contains($host, ';') || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
        || $database === '' || str_contains($database, ';') || !in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
        throw new RuntimeException('PostgreSQL connection settings are invalid.');
    }
    return compact('host', 'port', 'database', 'username', 'password', 'sslmode');
}

function database_boolean(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}
