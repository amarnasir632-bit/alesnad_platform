<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/storage/(?:private|backups)(?:/|$)#', $path) === 1) {
    http_response_code(404);
    exit;
}
$candidate = realpath(__DIR__ . $path);
$publicRoot = realpath(__DIR__);
if ($path !== '/' && $candidate !== false && $publicRoot !== false
    && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)) {
    return false;
}
require __DIR__ . '/index.php';
