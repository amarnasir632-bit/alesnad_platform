<?php
declare(strict_types=1);

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self' data:; script-src 'self'; connect-src 'self' https://upload.videodelivery.net https://storage.googleapis.com; frame-src https://*.cloudflarestream.com https://player.mux.com; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    if (is_production()) header('Strict-Transport-Security: max-age=31536000');
}

function dispatch_request(): void
{
    send_security_headers();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' && $path === '/health') {
        try {
            database_connection()->query('SELECT 1')->fetchColumn();
            json_response(200, ['status' => 'ok']);
        } catch (Throwable $error) {
            app_log_error('health_database_check_failed', $error);
            json_response(503, ['status' => 'unavailable']);
        }
    }
    try {
        refresh_authenticated_user();
    } catch (Throwable $error) {
        app_log_error('session_refresh_failed', $error);
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
        $isSessionUpload = preg_match('#^/api/v1/teacher/sessions/[1-9][0-9]*/recordings$#', $path) === 1
            && $method === 'POST' && $contentType === 'multipart/form-data';
        $isWebhook = str_starts_with($path, '/api/v1/webhooks/');
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? (($isContentUpload || $isSessionUpload) ? $_POST : read_json_body())
            : [];
        if (!$isWebhook && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) require_api_csrf($body);
        $route = $method . ' ' . rtrim($path, '/');
        if ($route === 'GET /api/v1/csrf') api_csrf();
        if ($route === 'GET /api/v1/subscription-plans') api_list_subscription_plans();
        if ($route === 'GET /api/v1/subscriptions/me') api_current_subscriptions();
        if ($route === 'GET /api/v1/admin/subscription-plans') api_admin_subscription_plans();
        if ($route === 'POST /api/v1/admin/subscription-plans') api_admin_create_subscription_plan($body);
        if (preg_match('#^PATCH /api/v1/admin/subscription-plans/([1-9][0-9]*)$#', $route, $match)) api_admin_update_subscription_plan((int) $match[1], $body);
        if ($route === 'GET /api/v1/admin/subscriptions') api_admin_subscriptions();
        if ($route === 'POST /api/v1/admin/subscriptions') api_admin_create_subscription($body);
        if (preg_match('#^PATCH /api/v1/admin/subscriptions/([1-9][0-9]*)$#', $route, $match)) api_admin_update_subscription((int) $match[1], $body);
        if ($route === 'POST /api/v1/admin/subscriptions/expire') api_admin_expire_subscriptions();
        if ($route === 'GET /api/v1/sessions') api_list_sessions();
        if ($route === 'POST /api/v1/teacher/sessions') api_create_session($body);
        if (preg_match('#^PATCH /api/v1/teacher/sessions/([1-9][0-9]*)$#', $route, $match)) api_update_session((int) $match[1], $body);
        if (preg_match('#^POST /api/v1/teacher/sessions/([1-9][0-9]*)/stream$#', $route, $match)) api_provision_session_stream((int) $match[1]);
        if (preg_match('#^POST /api/v1/teacher/sessions/([1-9][0-9]*)/recordings/sync$#', $route, $match)) api_sync_session_recordings((int) $match[1]);
        if (preg_match('#^POST /api/v1/sessions/([1-9][0-9]*)/join$#', $route, $match)) api_join_session((int) $match[1]);
        if (preg_match('#^GET /api/v1/sessions/([1-9][0-9]*)/recordings$#', $route, $match)) api_list_session_recordings((int) $match[1]);
        if (preg_match('#^POST /api/v1/teacher/sessions/([1-9][0-9]*)/recordings$#', $route, $match)) api_add_session_recording((int) $match[1], $_FILES['file'] ?? [], $body);
        if (preg_match('#^GET /api/v1/session-recordings/([1-9][0-9]*)/download$#', $route, $match)) api_download_session_recording((int) $match[1]);
        if (preg_match('#^POST /api/v1/sessions/([1-9][0-9]*)/voice-requests$#', $route, $match)) api_request_voice_participation((int) $match[1]);
        if (preg_match('#^GET /api/v1/sessions/([1-9][0-9]*)/voice-requests/me$#', $route, $match)) api_my_voice_request((int) $match[1]);
        if (preg_match('#^GET /api/v1/teacher/sessions/([1-9][0-9]*)/voice-requests$#', $route, $match)) api_list_voice_requests((int) $match[1]);
        if (preg_match('#^PATCH /api/v1/teacher/sessions/([1-9][0-9]*)/voice-requests/([1-9][0-9]*)$#', $route, $match)) api_update_voice_request((int) $match[1], (int) $match[2], $body);
        if ($route === 'GET /api/v1/messages') api_list_messages();
        if ($route === 'POST /api/v1/messages') api_send_message($body);
        if (preg_match('#^PATCH /api/v1/messages/([1-9][0-9]*)/read$#', $route, $match)) api_read_message((int) $match[1]);
        if (preg_match('#^DELETE /api/v1/messages/([1-9][0-9]*)$#', $route, $match)) api_delete_message((int) $match[1]);
        if ($route === 'GET /api/v1/auth/me') api_current_user();
        if ($route === 'POST /api/v1/auth/login') api_login($body);
        if ($route === 'POST /api/v1/auth/register') api_register_student($body);
        if ($route === 'POST /api/v1/auth/logout') api_logout();
        if ($route === 'GET /api/v1/admin/users') api_list_accounts();
        if ($route === 'GET /api/v1/admin/roles') api_list_roles();
        if ($route === 'GET /api/v1/admin/academics/class-subjects') api_admin_class_subjects();
        if ($route === 'GET /api/v1/academics/stages') api_academic_stages();
        if ($route === 'GET /api/v1/academics/classes') api_academic_classes();
        if ($route === 'GET /api/v1/academics/subjects') api_academic_subjects();
        if ($route === 'GET /api/v1/academics/lessons') api_academic_lessons();
        if ($route === 'GET /api/v1/academics/my-teaching-assignments') api_my_teaching_assignments();
        if ($route === 'GET /api/v1/content/search') api_search_academic_content();
        if ($route === 'GET /api/v1/teacher/questions') api_teacher_questions();
        if ($route === 'GET /api/v1/quizzes') api_student_quizzes();
        if ($route === 'POST /api/v1/teacher/quizzes') api_teacher_create_quiz($body);
        if (preg_match('#^PATCH /api/v1/teacher/quizzes/([1-9][0-9]*)$#', $route, $match)) api_teacher_update_quiz((int) $match[1], $body);
        if (preg_match('#^GET /api/v1/teacher/quizzes/([1-9][0-9]*)/results$#', $route, $match)) api_teacher_quiz_results((int) $match[1]);
        if (preg_match('#^GET /api/v1/quizzes/([1-9][0-9]*)$#', $route, $match)) api_student_quiz((int) $match[1]);
        if (preg_match('#^POST /api/v1/quizzes/([1-9][0-9]*)/attempts$#', $route, $match)) api_student_start_attempt((int) $match[1]);
        if (preg_match('#^PUT /api/v1/attempts/([1-9][0-9]*)/answers/([1-9][0-9]*)$#', $route, $match)) api_student_save_answer((int) $match[1], (int) $match[2], $body);
        if (preg_match('#^POST /api/v1/attempts/([1-9][0-9]*)/submit$#', $route, $match)) api_student_submit_attempt((int) $match[1]);
        if (preg_match('#^GET /api/v1/attempts/([1-9][0-9]*)/result$#', $route, $match)) api_attempt_result((int) $match[1]);
        if (preg_match('#^POST /api/v1/teacher/attempts/([1-9][0-9]*)/questions/([1-9][0-9]*)/grade$#', $route, $match)) api_teacher_grade_essay((int) $match[1], (int) $match[2], $body);
        if (preg_match('#^POST /api/v1/admin/attempts/([1-9][0-9]*)/review$#', $route, $match)) api_admin_review_quiz_attempt((int) $match[1], $body);
        if (preg_match('#^GET /api/v1/content/resources/([1-9][0-9]*)/download$#', $route, $match)) api_download_content('resource', (int) $match[1]);
        if (preg_match('#^GET /api/v1/content/resources/([1-9][0-9]*)/variants/([1-9][0-9]*)/download$#', $route, $match)) api_download_content('resource', (int) $match[1], (int) $match[2]);
        if (preg_match('#^GET /api/v1/content/past-exams/([1-9][0-9]*)/download$#', $route, $match)) api_download_content('past_exam', (int) $match[1]);
        if ($route === 'POST /api/v1/content/resources') api_upload_resource($body);
        if ($route === 'POST /api/v1/content/video-uploads') api_create_video_upload($body);
        if ($route === 'POST /api/v1/content/video-uploads/complete') api_complete_video_upload($body);
        if (preg_match('#^GET /api/v1/content/video-uploads/([a-f0-9]{48})/status$#', $route, $match)) api_video_upload_status($match[1]);
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
        if ($route === 'POST /api/v1/webhooks/cloudflare-stream') {
            $result = handle_cloudflare_stream_webhook();
            json_response(200, ['data' => $result]);
        }
        throw new ApiException(404, 'not_found', 'المسار غير موجود.');
    } catch (ApiException $error) {
        json_response($error->status, ['error' => ['code' => $error->apiCode, 'message' => $error->getMessage()]]);
    } catch (InvalidArgumentException $error) {
        json_response(422, ['error' => ['code' => 'validation_failed', 'message' => $error->getMessage()]]);
    } catch (Throwable $error) {
        app_log_error('api_request_failed', $error);
        json_response(500, ['error' => ['code' => 'internal_error', 'message' => 'تعذر تنفيذ الطلب الآن.']]);
    }
}
