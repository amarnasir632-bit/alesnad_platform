<?php
declare(strict_types=1);

/** Log only stable error metadata; exception messages may contain SQL or user data. */
function app_log_error(string $event, Throwable $error): void
{
    $entry = [
        'event' => $event,
        'exception' => basename(str_replace('\\', '/', get_class($error))),
        'code' => (string) $error->getCode(),
    ];
    error_log(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"event":"logging_failed"}');
}
