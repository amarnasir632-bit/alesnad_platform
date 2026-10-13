<?php
declare(strict_types=1);

function required_env(string $name): string
{
    $value = env_value($name);
    if ($value === null || trim($value) === '') {
        throw new RuntimeException("Required configuration is missing: {$name}");
    }
    return trim($value);
}

function required_positive_int(string $name): int
{
    $value = required_env($name);
    if (!ctype_digit($value) || (int) $value < 1) {
        throw new RuntimeException("Configuration must be a positive integer: {$name}");
    }
    return (int) $value;
}

function is_production(): bool
{
    return strtolower(env_value('APP_ENV', 'local') ?? 'local') === 'production';
}
