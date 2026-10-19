<?php
declare(strict_types=1);

function send_cloudflare_transactional_email(string $to, string $subject, string $html, string $text): void
{
    $accountId = required_env('CLOUDFLARE_ACCOUNT_ID');
    $token = required_env('CLOUDFLARE_EMAIL_API_TOKEN');
    $from = required_env('CLOUDFLARE_EMAIL_FROM');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('CLOUDFLARE_EMAIL_FROM must be a valid email address.');
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Recipient email is invalid.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The cURL extension is required for Cloudflare Email Sending.');
    }

    $payload = json_encode([
        'to' => $to,
        'from' => ['address' => $from, 'name' => 'منصة الإسناد الأكاديمي'],
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $handle = curl_init('https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($accountId) . '/email/sending/send');
    if ($handle === false) {
        throw new RuntimeException('Could not initialize the Cloudflare email request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $responseBody = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if (!is_string($responseBody)) {
        throw new RuntimeException('Cloudflare Email Sending request failed: ' . $curlError);
    }
    try {
        $response = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Cloudflare Email Sending returned an invalid response.', 0, $error);
    }
    if ($status < 200 || $status >= 300 || !is_array($response) || ($response['success'] ?? false) !== true) {
        $codes = array_map(
            static fn(array $item): string => (string) ($item['code'] ?? 'unknown'),
            is_array($response['errors'] ?? null) ? $response['errors'] : []
        );
        throw new RuntimeException('Cloudflare Email Sending rejected the message (HTTP ' . $status . ', codes: ' . implode(',', $codes) . ').');
    }
    $result = $response['result'] ?? [];
    $accepted = array_merge(
        is_array($result['delivered'] ?? null) ? $result['delivered'] : [],
        is_array($result['queued'] ?? null) ? $result['queued'] : []
    );
    if (!in_array($to, $accepted, true)) {
        throw new RuntimeException('Cloudflare Email Sending did not accept the recipient.');
    }
}

function request_password_reset(string $email): void
{
    $email = strtolower(trim($email));
    $settings = login_throttle_settings();
    required_env('CLOUDFLARE_ACCOUNT_ID');
    required_env('CLOUDFLARE_EMAIL_API_TOKEN');
    $sender = required_env('CLOUDFLARE_EMAIL_FROM');
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || !function_exists('curl_init')) {
        throw new RuntimeException('Cloudflare Email Sending is not configured correctly.');
    }
    $emailKey = login_email_key($email);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipKey = hash_hmac('sha256', is_string($ip) ? $ip : '', $settings['key']);
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:ip_key))')
            ->execute(['ip_key' => $ipKey]);
        $pdo->exec("DELETE FROM password_reset_requests WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '30 days'");
        $rate = $pdo->prepare(
            'SELECT COUNT(*) FROM password_reset_requests
             WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL \'1 hour\'
               AND (email_key = :email_key OR ip_key = :ip_key)'
        );
        $rate->execute(['email_key' => $emailKey, 'ip_key' => $ipKey]);
        if ((int) $rate->fetchColumn() >= 5) {
            $pdo->commit();
            return;
        }

        $userQuery = $pdo->prepare('SELECT id, full_name, email FROM users WHERE lower(email) = :email AND is_active');
        $userQuery->execute(['email' => $email]);
        $user = $userQuery->fetch();
        $token = $user ? bin2hex(random_bytes(32)) : null;
        $tokenHash = $token === null ? null : hash('sha256', $token);
        $insert = $pdo->prepare(
            'INSERT INTO password_reset_requests (user_id, email_key, ip_key, token_hash, expires_at)
             VALUES (:user_id, :email_key, :ip_key, :token_hash, CASE WHEN :has_token THEN CURRENT_TIMESTAMP + INTERVAL \'45 minutes\' ELSE NULL END)'
        );
        $insert->execute([
            'user_id' => $user ? (int) $user['id'] : null,
            'email_key' => $emailKey,
            'ip_key' => $ipKey,
            'token_hash' => $tokenHash,
            'has_token' => $token !== null,
        ]);
        if ($user) {
            $pdo->prepare('UPDATE password_reset_requests SET used_at = CURRENT_TIMESTAMP WHERE user_id = :id AND token_hash IS NOT NULL AND used_at IS NULL AND token_hash <> :token_hash')
                ->execute(['id' => $user['id'], 'token_hash' => $tokenHash]);
            audit_log('auth.password_reset.requested', 'user', (int) $user['id']);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    if (!$user || $token === null) return;
    $link = absolute_url('reset_password', ['token' => $token]);
    $safeLink = e($link);
    $html = '<div dir="rtl" lang="ar"><h1>إعادة تعيين كلمة المرور</h1><p>استخدم الرابط التالي لتعيين كلمة مرور جديدة. الرابط صالح لمدة 45 دقيقة ولمرة واحدة.</p><p><a href="' . $safeLink . '">إعادة تعيين كلمة المرور</a></p><p>إذا لم تطلب ذلك، تجاهل هذه الرسالة.</p></div>';
    $text = "إعادة تعيين كلمة المرور\nالرابط صالح لمدة 45 دقيقة ولمرة واحدة:\n{$link}\nإذا لم تطلب ذلك، تجاهل هذه الرسالة.";
    try {
        send_cloudflare_transactional_email($user['email'], 'إعادة تعيين كلمة المرور | الإسناد الأكاديمي', $html, $text);
    } catch (Throwable $error) {
        $pdo->prepare('UPDATE password_reset_requests SET used_at = CURRENT_TIMESTAMP WHERE token_hash = :token_hash AND used_at IS NULL')
            ->execute(['token_hash' => $tokenHash]);
        throw $error;
    }
}

function password_reset_token_is_valid(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $statement = database_connection()->prepare(
        'SELECT EXISTS (
            SELECT 1 FROM password_reset_requests pr
            JOIN users u ON u.id = pr.user_id AND u.is_active
            WHERE pr.token_hash = :token_hash AND pr.used_at IS NULL AND pr.expires_at > CURRENT_TIMESTAMP
        )'
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    return database_boolean($statement->fetchColumn());
}

function consume_password_reset(string $token, string $password): void
{
    validate_password_or_throw($password);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new InvalidArgumentException('رابط الاستعادة غير صالح أو منتهي الصلاحية.');

    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT pr.id, pr.user_id FROM password_reset_requests pr
             JOIN users u ON u.id = pr.user_id AND u.is_active
             WHERE pr.token_hash = :token_hash AND pr.used_at IS NULL AND pr.expires_at > CURRENT_TIMESTAMP
             FOR UPDATE OF pr, u'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $request = $statement->fetch();
        if (!$request || $request['user_id'] === null) throw new InvalidArgumentException('رابط الاستعادة غير صالح أو منتهي الصلاحية.');

        $userId = (int) $request['user_id'];
        $passwordUpdate = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND is_active');
        $passwordUpdate->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
        if ($passwordUpdate->rowCount() !== 1) throw new InvalidArgumentException('رابط الاستعادة غير صالح أو منتهي الصلاحية.');
        $pdo->prepare('UPDATE password_reset_requests SET used_at = CURRENT_TIMESTAMP WHERE user_id = :id AND token_hash IS NOT NULL AND used_at IS NULL')
            ->execute(['id' => $userId]);
        audit_log('auth.password_reset.completed', 'user', $userId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
