<?php
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/app/Support/env.php';
load_environment(PROJECT_ROOT . '/.env');
require_once PROJECT_ROOT . '/app/Support/config.php';
require_once PROJECT_ROOT . '/app/Support/logger.php';

date_default_timezone_set(env_value('APP_TIMEZONE', 'Africa/Khartoum'));
ini_set('log_errors', '1');
if (is_production()) ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('alesnad_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => is_production() || env_bool('SESSION_SECURE', false),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
$sessionIdleTimeout = required_positive_int('SESSION_IDLE_TIMEOUT_SECONDS');
if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $sessionIdleTimeout) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

require_once PROJECT_ROOT . '/app/Database/connection.php';
require_once PROJECT_ROOT . '/app/Support/http.php';
require_once PROJECT_ROOT . '/app/Auth/auth.php';
require_once PROJECT_ROOT . '/app/Database/audit.php';
require_once PROJECT_ROOT . '/app/Database/catalog.php';
require_once PROJECT_ROOT . '/app/Database/academics.php';
require_once PROJECT_ROOT . '/app/Database/access.php';
require_once PROJECT_ROOT . '/app/Database/staff.php';
require_once PROJECT_ROOT . '/app/Database/questions.php';
require_once PROJECT_ROOT . '/app/Database/messages.php';
require_once PROJECT_ROOT . '/app/Database/library.php';
require_once PROJECT_ROOT . '/app/Database/quizzes.php';
require_once PROJECT_ROOT . '/app/Security/authorization.php';
require_once PROJECT_ROOT . '/app/Security/audit.php';
require_once PROJECT_ROOT . '/app/Security/login_throttle.php';
require_once PROJECT_ROOT . '/app/Security/passwords.php';
require_once PROJECT_ROOT . '/app/Services/AcademicService.php';
