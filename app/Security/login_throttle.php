<?php
declare(strict_types=1);

function login_throttle_settings(): array
{
    $key = required_env('AUTH_THROTTLE_KEY');
    if (strlen($key) < 32) {
        throw new RuntimeException('AUTH_THROTTLE_KEY must contain at least 32 bytes.');
    }
    return [
        'max' => required_positive_int('LOGIN_MAX_ATTEMPTS'),
        'window' => required_positive_int('LOGIN_WINDOW_SECONDS'),
        'lockout' => required_positive_int('LOGIN_LOCKOUT_SECONDS'),
        'key' => $key,
    ];
}

function login_email_key(string $email): string
{
    $settings = login_throttle_settings();
    return hash_hmac('sha256', strtolower($email), $settings['key']);
}

function login_is_locked(string $email): bool
{
    $statement = database_connection()->prepare(
        'SELECT locked_until IS NOT NULL AND locked_until > CURRENT_TIMESTAMP
         FROM auth_login_attempts WHERE email_key = :email_key'
    );
    $statement->execute(['email_key' => login_email_key($email)]);
    return database_boolean($statement->fetchColumn());
}

function record_login_failure(string $email): bool
{
    $settings = login_throttle_settings();
    $statement = database_connection()->prepare(
        'WITH params AS (
            SELECT CAST(:window_seconds AS INTEGER) AS window_seconds,
                   CAST(:max_attempts AS INTEGER) AS max_attempts,
                   CAST(:lockout_seconds AS INTEGER) AS lockout_seconds,
                   CAST(:last_ip AS INET) AS last_ip
         )
         INSERT INTO auth_login_attempts
            (email_key, failed_attempts, window_started_at, locked_until, last_attempt_at, last_ip)
         SELECT :email_key, 1, CURRENT_TIMESTAMP, NULL, CURRENT_TIMESTAMP, params.last_ip FROM params
         ON CONFLICT (email_key) DO UPDATE SET
            failed_attempts = CASE
                WHEN auth_login_attempts.window_started_at <= CURRENT_TIMESTAMP - ((SELECT window_seconds FROM params) * INTERVAL \'1 second\') THEN 1
                ELSE auth_login_attempts.failed_attempts + 1
            END,
            window_started_at = CASE
                WHEN auth_login_attempts.window_started_at <= CURRENT_TIMESTAMP - ((SELECT window_seconds FROM params) * INTERVAL \'1 second\') THEN CURRENT_TIMESTAMP
                ELSE auth_login_attempts.window_started_at
            END,
            locked_until = CASE
                WHEN (auth_login_attempts.window_started_at <= CURRENT_TIMESTAMP - ((SELECT window_seconds FROM params) * INTERVAL \'1 second\') AND 1 >= (SELECT max_attempts FROM params))
                  OR (auth_login_attempts.window_started_at > CURRENT_TIMESTAMP - ((SELECT window_seconds FROM params) * INTERVAL \'1 second\') AND auth_login_attempts.failed_attempts + 1 >= (SELECT max_attempts FROM params))
                THEN CURRENT_TIMESTAMP + ((SELECT lockout_seconds FROM params) * INTERVAL \'1 second\')
                ELSE NULL
            END,
            last_attempt_at = CURRENT_TIMESTAMP,
            last_ip = (SELECT last_ip FROM params),
            updated_at = CURRENT_TIMESTAMP
         RETURNING locked_until IS NOT NULL AND locked_until > CURRENT_TIMESTAMP'
    );
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ip = null;
    }
    $statement->execute([
        'email_key' => login_email_key($email),
        'window_seconds' => $settings['window'],
        'max_attempts' => $settings['max'],
        'lockout_seconds' => $settings['lockout'],
        'last_ip' => $ip,
    ]);
    return database_boolean($statement->fetchColumn());
}

function clear_login_failures(string $email): void
{
    $statement = database_connection()->prepare('DELETE FROM auth_login_attempts WHERE email_key = :email_key');
    $statement->execute(['email_key' => login_email_key($email)]);
}
