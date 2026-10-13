<?php
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/app/Support/env.php';
load_environment(PROJECT_ROOT . '/.env');

date_default_timezone_set(env_value('APP_TIMEZONE', 'Africa/Khartoum'));
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('alesnad_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => env_bool('SESSION_SECURE', env_value('APP_ENV', 'local') === 'production'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once PROJECT_ROOT . '/app/Database/connection.php';
require_once PROJECT_ROOT . '/app/Support/http.php';
require_once PROJECT_ROOT . '/app/Auth/auth.php';
require_once PROJECT_ROOT . '/app/Database/catalog.php';
