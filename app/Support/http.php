<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function database_bool(mixed $value): bool
{
    if (is_bool($value)) { return $value; }
    if (is_int($value) || is_float($value)) { return $value !== 0 && $value !== 0.0; }
    return is_string($value) && in_array(strtolower(trim($value)), ['1', 't', 'true', 'yes', 'on'], true);
}

function url(string $page = 'home', array $parameters = []): string
{
    $query = ['page' => $page] + $parameters;
    return '/?' . http_build_query($query);
}

function absolute_url(string $page = 'home', array $parameters = []): string
{
    return public_origin() . url($page, $parameters);
}

function public_origin(): string
{
    $baseUrl = rtrim((string) env_value('APP_URL', env_value('RENDER_EXTERNAL_URL', '')), '/');
    $parts = parse_url($baseUrl);
    if ($baseUrl === '' || !is_array($parts)
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        throw new RuntimeException('APP_URL must contain the public HTTP or HTTPS origin.');
    }
    return $baseUrl;
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($stored) || $stored === '' || !hash_equals($stored, $sent)) {
        http_response_code(419);
        exit('انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.');
    }
}

function post_string(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'سجّل الدخول أولاً.');
        redirect(url('login'));
    }
    return $user;
}
