<?php
declare(strict_types=1);

function password_validation_error(string $password): ?string
{
    if (strlen($password) > 72) return 'كلمة المرور تتجاوز الحد الأعلى المسموح وهو 72 بايت.';
    $length = preg_match_all('/./us', $password);
    if ($length === false || $length < 12) return 'يجب ألا تقل كلمة المرور عن 12 محرفًا.';
    return null;
}

function validate_password_or_throw(string $password): void
{
    $error = password_validation_error($password);
    if ($error !== null) throw new InvalidArgumentException($error);
}
