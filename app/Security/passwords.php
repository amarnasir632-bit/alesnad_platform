<?php
declare(strict_types=1);

function password_validation_error(string $password): ?string
{
    if (!preg_match('/\A[\x21-\x7E]{8,12}\z/', $password)) {
        return 'يجب أن تتكون كلمة المرور من 8 إلى 12 محرفًا باستخدام أحرف إنجليزية أو أرقام أو رموز فقط.';
    }
    return null;
}

function validate_password_or_throw(string $password): void
{
    $error = password_validation_error($password);
    if ($error !== null) throw new InvalidArgumentException($error);
}
