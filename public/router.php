<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$candidate = realpath(__DIR__ . $path);
$publicRoot = realpath(__DIR__);
if ($path !== '/' && $candidate !== false && $publicRoot !== false
    && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)) {
    return false;
}
require __DIR__ . '/index.php';
