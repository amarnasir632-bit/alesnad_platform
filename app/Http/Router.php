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
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        $isContentUpload = str_starts_with($path, '/api/v1/content/')
            && in_array($method, ['POST', 'PATCH'], true)
            && $contentType === 'multipart/form-data';
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? ($isContentUpload ? $_POST : read_json_body())
            : [];
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) require_api_csrf($body);
        $route = $method . ' ' . rtrim($path, '/');
        if ($route === 'GET /api/v1/csrf') api_csrf();
        if ($route === 'GET /api/v1/auth/me') api_current_user();
        if ($route === 'POST /api/v1/auth/login') api_login($body);
        if ($route === 'POST /api/v1/auth/register') api_register_student($body);
        if ($route === 'POST /api/v1/auth/logout') api_logout();
        if ($route === 'GET /api/v1/admin/users') api_list_accounts();
        if ($route === 'GET /api/v1/admin/roles') api_list_roles();
        if ($route === 'GET /api/v1/academics/stages') api_academic_stages();
        if ($route === 'GET /api/v1/academics/classes') api_academic_classes();
        if ($route === 'GET /api/v1/academics/subjects') api_academic_subjects();
        if ($route === 'GET /api/v1/academics/lessons') api_academic_lessons();
        if ($route === 'GET /api/v1/academics/my-teaching-assignments') api_my_teaching_assignments();
        if ($route === 'GET /api/v1/content/search') api_search_academic_content();
        if (preg_match('#^GET /api/v1/content/resources/([1-9][0-9]*)/download$#', $route, $match)) api_download_content('resource', (int) $match[1]);
        if (preg_match('#^GET /api/v1/content/resources/([1-9][0-9]*)/variants/([1-9][0-9]*)/download$#', $route, $match)) api_download_content('resource', (int) $match[1], (int) $match[2]);
        if (preg_match('#^GET /api/v1/content/past-exams/([1-9][0-9]*)/download$#', $route, $match)) api_download_content('past_exam', (int) $match[1]);
        if ($route === 'POST /api/v1/content/resources') api_upload_resource($body);
        if ($route === 'POST /api/v1/content/past-exams') api_upload_past_exam($body);
        if ($route === 'POST /api/v1/content/questions') api_create_question($body);
        if (preg_match('#^PATCH /api/v1/content/resources/([1-9][0-9]*)$#', $route, $match)) api_update_content('resource', (int) $match[1], $body);
        if (preg_match('#^PATCH /api/v1/content/past-exams/([1-9][0-9]*)$#', $route, $match)) api_update_content('past_exam', (int) $match[1], $body);
        if (preg_match('#^PATCH /api/v1/content/questions/([1-9][0-9]*)$#', $route, $match)) api_update_content('question', (int) $match[1], $body);
        if (preg_match('#^POST /api/v1/content/resources/([1-9][0-9]*)/replace-file$#', $route, $match)) api_update_content('resource', (int) $match[1], $body, true);
        if (preg_match('#^POST /api/v1/content/past-exams/([1-9][0-9]*)/replace-file$#', $route, $match)) api_update_content('past_exam', (int) $match[1], $body, true);
        if (preg_match('#^POST /api/v1/content/resources/([1-9][0-9]*)/submit$#', $route, $match)) api_submit_content('resource', (int) $match[1]);
        if (preg_match('#^POST /api/v1/content/past-exams/([1-9][0-9]*)/submit$#', $route, $match)) api_submit_content('past_exam', (int) $match[1]);
        if (preg_match('#^POST /api/v1/content/questions/([1-9][0-9]*)/submit$#', $route, $match)) api_submit_content('question', (int) $match[1]);
        if (preg_match('#^POST /api/v1/admin/content/resources/([1-9][0-9]*)/review$#', $route, $match)) api_review_content('resource', (int) $match[1], $body);
        if (preg_match('#^POST /api/v1/admin/content/past-exams/([1-9][0-9]*)/review$#', $route, $match)) api_review_content('past_exam', (int) $match[1], $body);
        if (preg_match('#^POST /api/v1/admin/content/questions/([1-9][0-9]*)/review$#', $route, $match)) api_review_content('question', (int) $match[1], $body);
        if ($route === 'POST /api/v1/admin/users') api_create_account($body);
        if (preg_match('#^POST /api/v1/admin/academics/(stages|classes|subjects|class-subjects|lessons)$#', $route, $match)) api_admin_academic_create($match[1], $body);
        if (preg_match('#^PATCH /api/v1/admin/academics/(stages|classes|subjects|class-subjects|lessons)/([1-9][0-9]*)$#', $route, $match)) api_admin_academic_update($match[1], (int) $match[2], $body);
        if ($route === 'POST /api/v1/admin/teaching-assignments') api_admin_assign_teacher($body);
        if (preg_match('#^DELETE /api/v1/admin/teaching-assignments/([1-9][0-9]*)/([1-9][0-9]*)$#', $route, $match)) api_admin_unassign_teacher((int) $match[1], (int) $match[2]);
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
