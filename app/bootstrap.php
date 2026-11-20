<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e): void {
    $errInfo = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log('Uncaught ' . $errInfo);
    try {
        if (function_exists('db')) {
            $pdo = db();
            $userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : null;
            $pdo->prepare("INSERT INTO security_logs (user_id, event_type, ip_address, details) VALUES (?, 'system_exception', ?, ?)")
                ->execute([$userId, $ip, substr($errInfo, 0, 1000)]);
        }
    } catch (Throwable $ignore) {}

    if (isset($_GET['debug']) || isset($_COOKIE['debug']) || isset($_SERVER['HTTP_X_DEBUG'])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo "UNCAUGHT EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n";
        echo "LOCATION: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "TRACE:\n" . $e->getTraceAsString() . "\n";
        exit;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Notice | CONNEZER EDUTECH SYSTEMS</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#f5f7f9;color:#1c344b;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box}.card{background:#fff;border-radius:12px;padding:32px 24px;max-width:480px;width:100%;box-shadow:0 4px 16px rgba(0,0,0,.08);text-align:center;box-sizing:border-box}h1{font-size:1.35rem;color:#061D3D;margin:0 0 12px}p{color:#55697d;font-size:.95rem;line-height:1.5;margin:0 0 20px}a{display:inline-block;background:#061D3D;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-weight:600;font-size:.92rem}</style></head><body><div class="card"><h1>System Notice</h1><p>We encountered an unexpected error processing your request. The technical team has been notified. Please try again shortly or contact IT support.</p><a href="index.php">Return to Sign In</a></div></body></html>';
    exit;
});

define('APP_ROOT', dirname(__DIR__));
$isRender = getenv('RENDER') !== false;
$isVercel = getenv('VERCEL') !== false;
$isProduction = strtolower((string) getenv('APP_ENV')) === 'production'
    || $isVercel
    || $isRender;

$configFile = APP_ROOT . '/config/local.php';
if ($isProduction || !is_file($configFile)) {
    if ($isRender && is_file(APP_ROOT . '/config/render.php')) {
        $configFile = APP_ROOT . '/config/render.php';
    } elseif ($isVercel && is_file(APP_ROOT . '/config/vercel.php')) {
        $configFile = APP_ROOT . '/config/vercel.php';
    } elseif (is_file(APP_ROOT . '/config/render.php')) {
        $configFile = APP_ROOT . '/config/render.php';
    } elseif (is_file(APP_ROOT . '/config/vercel.php')) {
        $configFile = APP_ROOT . '/config/vercel.php';
    }
}
if (!is_file($configFile)) {
    http_response_code(503);
    exit($isProduction
        ? 'Production configuration is missing.'
        : 'Local configuration is missing. Copy config/local.example.php to config/local.php and follow README.md.');
}
$config = require $configFile;
if (!is_array($config)
    || empty($config['auth_pepper'])
    || empty($config['app_key'])
    || strlen((string) $config['auth_pepper']) < 32
    || strlen((string) $config['app_key']) < 32
    || hash_equals((string) $config['auth_pepper'], (string) $config['app_key'])
    || str_starts_with((string) $config['auth_pepper'], 'REPLACE_')
    || str_starts_with((string) $config['app_key'], 'REPLACE_')) {
    http_response_code(503);
    exit($isProduction
        ? 'Production secrets are not configured. Set unique AUTH_PEPPER and APP_KEY environment values.'
        : 'Local secrets are not configured. Set unique auth_pepper and app_key values in config/local.php.');
}
$productionUrl = $isProduction ? parse_url((string) ($config['app_url'] ?? '')) : false;
if ($isProduction && (
    empty($config['app_url'])
    || !is_array($productionUrl)
    || strtolower((string) ($productionUrl['scheme'] ?? '')) !== 'https'
    || empty($productionUrl['host'])
    || empty($config['db']['host'])
    || empty($config['db']['name'])
    || empty($config['db']['user'])
    || empty($config['db']['password'])
    || filter_var((string) $config['db']['port'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]) === false
)) {
    http_response_code(503);
    exit('Production application URL and database settings are not configured.');
}

$timezone = (string) ($config['timezone'] ?? 'Africa/Accra');
try {
    date_default_timezone_set($timezone);
} catch (ValueError $exception) {
    http_response_code(503);
    error_log('Secure Auth timezone configuration is invalid: ' . $timezone);
    exit('The local timezone configuration is invalid. Check config/local.php.');
}
require_once APP_ROOT . '/app/functions.php';
require_once APP_ROOT . '/app/session_handler.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('secure_auth_session');
$forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$secureSession = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($isProduction && $forwardedProto === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim((string) parse_url((string) ($config['app_url'] ?? '/'), PHP_URL_PATH), '/') . '/',
    'secure' => $secureSession,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if ($isProduction) {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS app_sessions (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            session_data MEDIUMTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_app_sessions_expires (expires_at)
        ) ENGINE=InnoDB");
        session_set_save_handler(new DatabaseSessionHandler(db()), true);
    } catch (Throwable $e) {
        error_log('Database session handler initialization: ' . $e->getMessage());
        $tempSession = sys_get_temp_dir() . '/connezer_sessions';
        if (!is_dir($tempSession)) {
            @mkdir($tempSession, 0700, true);
        }
        if (is_dir($tempSession) && is_writable($tempSession)) {
            session_save_path($tempSession);
        }
    }
}
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(self), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

require_once APP_ROOT . '/app/school_tenant.php';
require_once APP_ROOT . '/app/school_parent_links.php';
require_once APP_ROOT . '/app/school_academic_calendar.php';
require_once APP_ROOT . '/app/school_timetable.php';
require_once APP_ROOT . '/app/school_admissions.php';
require_once APP_ROOT . '/app/school_results.php';
require_once APP_ROOT . '/app/school_notifications.php';
require_once APP_ROOT . '/app/school_calendar.php';
require_once APP_ROOT . '/app/school_learning.php';
require_once APP_ROOT . '/app/school_ai.php';
require_once APP_ROOT . '/app/views.php';

if (!isset($_SESSION['user_id'])) {
    restore_remembered_session();
}

$requestedRoute = basename((string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod === 'POST'
    && !in_array($requestedRoute, ['exit_it_support.php', 'logout.php'], true)
    && !empty($_SESSION['it_support_session_id'])) {
    $supportUser = current_user();
    $supportContext = $supportUser !== null ? active_it_support_context($supportUser) : null;
    if ($supportContext !== null) {
        if (!it_support_write_allowed($supportContext, $requestedRoute)) {
            audit((int) $supportUser['id'], 'it_support_write_denied', 'route=' . $requestedRoute);
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('This IT Support session does not authorize changes through this route.');
        }
        audit(
            (int) $supportUser['id'],
            'it_support_school_write_requested',
            'route=' . $requestedRoute . ';access_mode=' . (string) $supportContext['access_mode']
        );
    }
}

$retiredRoutes = [
    'admin.php',
    'school_services.php',
    'branding.php',
    'branding_logo.php',
    'community.php',
    'config_restore.php',
    'directory.php',
    'general.php',
    'job_attachment.php',
    'jobs.php',
    'member_import.php',
    'membership_approvals.php',
    'mentorship.php',
    'messages.php',
    'messages_api.php',
    'news-photo.php',
    'news.php',
    'payment_receipt.php',
    'roles.php',
    'sidebar_badges.php',
    'student.php',
    'support.php',
    'system_health.php',
    'users.php',
];
if (in_array($requestedRoute, $retiredRoutes, true)) {
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This legacy feature is no longer part of CONNEZER EDUTECH SYSTEMS.');
}
