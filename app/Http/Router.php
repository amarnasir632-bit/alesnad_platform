<?php
declare(strict_types=1);

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self' data:; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    if (is_production()) header('Strict-Transport-Security: max-age=31536000');
}

function dispatch_request(): void
{
    send_security_headers();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    try {
        refresh_authenticated_user();
    } catch (Throwable $error) {
        error_log('Session refresh failed: ' . $error->getMessage());
        if (str_starts_with($path, '/api/v1/')) {
            json_response(503, ['error' => ['code' => 'service_unavailable', 'message' => 'الخدمة غير متاحة الآن.']]);
        }
        http_response_code(503);
        require PROJECT_ROOT . '/app/Views/unavailable.php';
        return;
    }
    if (!str_starts_with($path, '/api/v1/')) {
        handle_web_request();
        return;
    }

    try {
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? read_json_body() : [];
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) require_api_csrf($body);
        $route = $method . ' ' . rtrim($path, '/');
        if ($route === 'GET /api/v1/csrf') api_csrf();
        if ($route === 'GET /api/v1/auth/me') api_current_user();
        if ($route === 'POST /api/v1/auth/login') api_login($body);
        if ($route === 'POST /api/v1/auth/register') api_register_student($body);
        if ($route === 'POST /api/v1/auth/logout') api_logout();
        if ($route === 'GET /api/v1/admin/users') api_list_accounts();
        if ($route === 'GET /api/v1/admin/roles') api_list_roles();
        if ($route === 'POST /api/v1/admin/users') api_create_account($body);
        if (preg_match('#^PATCH /api/v1/admin/users/([1-9][0-9]*)$#', $route, $match)) api_update_account((int) $match[1], $body);
        if (preg_match('#^PUT /api/v1/admin/users/([1-9][0-9]*)/roles$#', $route, $match)) api_assign_account_roles((int) $match[1], $body);
        throw new ApiException(404, 'not_found', 'المسار غير موجود.');
    } catch (ApiException $error) {
        json_response($error->status, ['error' => ['code' => $error->apiCode, 'message' => $error->getMessage()]]);
    } catch (InvalidArgumentException $error) {
        json_response(422, ['error' => ['code' => 'validation_failed', 'message' => $error->getMessage()]]);
    } catch (Throwable $error) {
        error_log('API request failed: ' . $error->getMessage());
        json_response(500, ['error' => ['code' => 'internal_error', 'message' => 'تعذر تنفيذ الطلب الآن.']]);
    }
}
