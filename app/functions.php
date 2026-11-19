<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo;
    global $config;
    if (!$pdo) {
        $db = $config['db'];
        $port = isset($db['port']) ? ';port=' . (int) $db['port'] : '';
        $dsn = sprintf(
            'mysql:host=%s%s;dbname=%s;charset=%s',
            $db['host'],
            $port,
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (!empty($db['ssl_ca'])) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = (string) $db['ssl_ca'];
            $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } elseif (!empty($db['ssl'])) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = '';
        }
        try {
            $pdo = new PDO($dsn, $db['user'], $db['password'], $pdoOptions);
        } catch (PDOException $exception) {
            $secrets = array_values(array_filter([
                (string) ($db['user'] ?? ''),
                (string) ($db['password'] ?? ''),
            ], static fn(string $value): bool => $value !== ''));
            $details = str_replace($secrets, '[redacted]', $exception->getMessage());
            error_log('Secure Auth database connection failed: ' . $details);
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            exit('The service is temporarily unavailable. Please try again later.');
        }
    }
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    global $config;
    $basePath = rtrim((string) parse_url((string) ($config['app_url'] ?? '/'), PHP_URL_PATH), '/');
    header('Location: ' . $basePath . '/' . ltrim($path, '/'));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('This form expired or could not be verified. Go back, reload the page, and try again.');
    }
}

function flash(string $kind, string $message): void
{
    $_SESSION['flash'] = ['kind' => $kind, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function keyed_hash(string $value): string
{
    global $config;
    return hash_hmac('sha256', $value, (string) $config['auth_pepper']);
}

function password_input(string $password): string
{
    global $config;
    return hash_hmac('sha256', "secure-auth-password\0" . $password, (string) $config['auth_pepper']);
}

function make_password_hash(string $password): string
{
    $algorithm = in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash(password_input($password), $algorithm);
    if ($hash === false) {
        throw new RuntimeException('Password hashing failed.');
    }
    return $hash;
}

function verify_password(string $password, string $hash): bool
{
    return password_verify(password_input($password), $hash);
}

function valid_password(string $password): bool
{
    return strlen($password) >= 12 && strlen($password) <= 200;
}

function audit(?int $userId, string $event, ?string $details = null): void
{
    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : null;
    if ($userId !== null && function_exists('active_it_support_context')) {
        $supportContext = active_it_support_context(['id' => $userId]);
        if ($supportContext !== null) {
            $supportDetails = 'support_session=' . (int) $supportContext['id']
                . ';school_id=' . (int) $supportContext['school_id']
                . ';role=' . (string) $supportContext['preview_role']
                . ';mode=' . (string) $supportContext['access_mode'];
            $details = ($details === null || $details === '')
                ? $supportDetails
                : $supportDetails . ';' . $details;
        }
    }
    $stmt = db()->prepare('INSERT INTO security_logs (user_id, event_type, ip_address, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, substr($event, 0, 64), $ip, $details === null ? null : substr($details, 0, 255)]);
}

function config_restore_allowed_keys(): array
{
    return [
        'association_name',
        'association_tagline',
        'site_logo_path',
        'theme_mode',
        'primary_brand_color',
        'monthly_dues_amount',
        'monthly_dues_currency',
        'monthly_dues_email_day',
        'directory_page_size',
        'default_event_visibility',
        'announcement_email_digest',
    ];
}

function ensure_config_restore_table(): void
{
    $stmt = db()->prepare(
        'CREATE TABLE IF NOT EXISTS config_restore_points (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            snapshot_json JSON NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_config_restore_points_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
            INDEX idx_config_restore_points_created_at (created_at)
        ) ENGINE=InnoDB'
    );
    $stmt->execute();
}

function config_restore_snapshot(): array
{
    $allowed = config_restore_allowed_keys();
    if ($allowed === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $stmt = db()->prepare('SELECT setting_key, setting_value FROM association_settings WHERE setting_key IN (' . $placeholders . ')');
    $stmt->execute($allowed);
    $values = [];
    foreach ($stmt->fetchAll() as $row) {
        $values[(string) $row['setting_key']] = $row['setting_value'];
    }
    return $values;
}

function create_config_restore_point(string $name, ?string $description, int $actorId): int
{
    ensure_config_restore_table();
    $cleanName = trim($name);
    $cleanDescription = trim((string) $description);
    if ($cleanName === '' || strlen($cleanName) > 120 || strlen($cleanDescription) > 500) {
        throw new InvalidArgumentException('Enter a restore-point name and keep the description within the supported length.');
    }
    $snapshot = config_restore_snapshot();
    $stmt = db()->prepare('INSERT INTO config_restore_points (name, description, created_by, snapshot_json) VALUES (?, ?, ?, ?)');
    $stmt->execute([$cleanName, $cleanDescription !== '' ? $cleanDescription : null, $actorId, json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    return (int) db()->lastInsertId();
}

function restore_config_point(int $pointId, int $actorId): array
{
    ensure_config_restore_table();
    $stmt = db()->prepare('SELECT name, snapshot_json, created_by FROM config_restore_points WHERE id = ?');
    $stmt->execute([$pointId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('The selected configuration restore point does not exist.');
    }
    $snapshot = json_decode((string) $row['snapshot_json'], true);
    if (!is_array($snapshot)) {
        throw new RuntimeException('The selected configuration restore point is unreadable.');
    }
    $allowedKeys = array_fill_keys(config_restore_allowed_keys(), true);
    $changes = [];
    foreach ($snapshot as $key => $value) {
        if (!isset($allowedKeys[(string) $key])) {
            continue;
        }
        $current = db()->prepare('SELECT setting_value FROM association_settings WHERE setting_key = ? LIMIT 1');
        $current->execute([$key]);
        $currentValue = $current->fetchColumn();
        if ((string) ($currentValue ?? '') !== (string) $value) {
            $changes[] = (string) $key;
        }
    }
    if ($changes === []) {
        return ['changed' => [], 'name' => (string) $row['name']];
    }
    db()->beginTransaction();
    try {
        foreach ($snapshot as $key => $value) {
            if (!isset($allowedKeys[(string) $key])) {
                continue;
            }
            $stmt = db()->prepare('INSERT INTO association_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()');
            $stmt->execute([(string) $key, $value, $actorId]);
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
    audit($actorId, 'config_restore_applied', 'restore_point_id=' . $pointId . '; keys=' . implode(',', $changes));
    return ['changed' => $changes, 'name' => (string) $row['name']];
}

function create_user_notification(int $userId, string $key, string $category, string $title, string $body, ?string $link): void
{
    $preferenceColumns = [
        'announcement' => 'announcements_in_app',
        'event' => 'events_in_app',
        'dues' => 'dues_in_app',
    ];
    if (isset($preferenceColumns[$category])) {
        $column = $preferenceColumns[$category];
        $stmt = db()->prepare("SELECT {$column} FROM user_notification_preferences WHERE user_id = ?");
        $stmt->execute([$userId]);
        $enabled = $stmt->fetchColumn();
        if ($enabled !== false && !(bool) $enabled) {
            return;
        }
    }
    if (strlen($key) > 120 || strlen($title) > 180 || strlen($body) > 1000
        || !in_array($category, ['announcement', 'event', 'dues', 'security', 'support', 'other'], true)
        || ($link !== null && strlen($link) > 500)) {
        throw new InvalidArgumentException('Notification data exceeds the supported format.');
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO association_notifications
         (user_id, notification_key, category, title, body, link_url)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $key, $category, $title, $body, $link]);
}

function notify_announcement_recipients(int $announcementId, string $title, string $body, string $audience): void
{
    if (!in_array($audience, ['all', 'members', 'officials'], true)) {
        throw new InvalidArgumentException('Invalid announcement audience.');
    }
    $where = "u.status = 'active' AND u.email_verified_at IS NOT NULL";
    if ($audience === 'members') {
        $where .= " AND mp.membership_status = 'active'";
    } elseif ($audience === 'officials') {
        $where .= " AND EXISTS (
            SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = u.id
              AND r.name IN (
                  'administrator','association_super_admin','president','vice_president',
                  'patron','secretary','assistant_secretary','treasurer','assistant_treasurer',
                  'organising_secretary','public_relations_officer','executive_member',
                  'electoral_officer','auditor','welfare_officer','committee_chairperson',
                  'class_representative','branch_coordinator'
              )
        )";
    }
    $stmt = db()->prepare(
        "INSERT IGNORE INTO association_notifications
         (user_id, notification_key, category, title, body, link_url)
         SELECT u.id, ?, 'announcement', ?, ?, 'school_services.php?module=announcements'
         FROM users u
         LEFT JOIN member_profiles mp ON mp.user_id = u.id
         LEFT JOIN user_notification_preferences pref ON pref.user_id = u.id
         WHERE {$where} AND COALESCE(pref.announcements_in_app, 1) = 1"
    );
    $stmt->execute([
        'announcement:' . $announcementId,
        substr($title, 0, 180),
        substr($body, 0, 1000),
    ]);
}

function set_pending_user(int $userId): void
{
    $_SESSION['pending_user_id'] = $userId;
}

function pending_user_id(): ?int
{
    return isset($_SESSION['pending_user_id']) ? (int) $_SESSION['pending_user_id'] : null;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name, u.username, u.email, u.status, u.email_verified_at,
                u.profile_picture_path AS account_profile_picture_path,
                COALESCE(u.profile_picture_path, mp.profile_picture_path) AS profile_picture_path
         FROM users u
         LEFT JOIN member_profiles mp ON mp.user_id = u.id
         WHERE u.id = ?'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active' || $user['email_verified_at'] === null) {
        clear_auth_session();
        return null;
    }
    $stmt = db()->prepare(
        'SELECT r.name
         FROM user_roles ur JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ?
         ORDER BY r.name'
    );
    $stmt->execute([(int) $user['id']]);
    $user['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $activeRole = $_SESSION['active_role'] ?? null;
    if (!is_string($activeRole) || !in_array($activeRole, $user['roles'], true)) {
        unset($_SESSION['active_role']);
        $activeRole = null;
    }
    if ($activeRole === null && count($user['roles']) === 1) {
        $activeRole = $user['roles'][0];
        $_SESSION['active_role'] = $activeRole;
    }
    $user['active_role'] = $activeRole;
    return $user;
}

function enforce_session_idle_timeout(): void
{
    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? 0);
    if ($lastActivity > 0 && $lastActivity < time() - (8 * 60 * 60)) {
        clear_remember_cookie();
        clear_auth_session();
        flash('error', 'Your session expired after a period of inactivity. Please sign in again.');
        return;
    }
    $_SESSION['last_activity_at'] = time();
}

function require_login(): array
{
    enforce_session_idle_timeout();
    $user = current_user();
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect('/');
    }
    $route = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $supportContext = active_it_support_context($user);
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
        && $supportContext !== null
        && $route !== 'exit_it_support.php'
        && !it_support_write_allowed($supportContext, $route)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('This IT Support session does not authorize changes through this route.');
    }
    if (count($user['roles']) > 1 && $user['active_role'] === null
        && !platform_super_admin_user($user)) {
        redirect('dashboard.php');
    }
    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    $isPlatformAdmin = platform_super_admin_user($user);
    $itManagerDenied = $role === 'it_manager' && !$isPlatformAdmin;
    if (!$isPlatformAdmin && ($itManagerDenied || ($user['active_role'] ?? null) !== $role)) {
        http_response_code(403);
        render_header('Access denied');
        echo '<section class="panel"><h1>Access denied</h1><p>Your account does not have permission to view this page.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
        render_footer();
        exit;
    }
    return $user;
}

function user_has_permission(array $user, string $permission): bool
{
    if (platform_super_admin_user($user)) {
        return true;
    }

    $activeRole = $user['active_role'] ?? null;
    if (empty($user['id']) || !is_string($activeRole)
        || !in_array($activeRole, $user['roles'] ?? [], true)) {
        return false;
    }
    if ($activeRole === 'it_manager' && !platform_super_admin_user($user)) {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT 1
         FROM user_roles AS ur
         JOIN roles AS r ON r.id = ur.role_id
         JOIN role_permissions AS rp ON rp.role_id = ur.role_id
         WHERE ur.user_id = ? AND r.name = ? AND rp.permission_name = ?
         LIMIT 1'
    );
    $stmt->execute([(int) $user['id'], $activeRole, $permission]);
    return (bool) $stmt->fetchColumn();
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!user_has_permission($user, $permission)) {
        http_response_code(403);
        render_header('Access denied');
        echo '<section class="panel"><h1>Access denied</h1><p>Your account does not have permission to view this page.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
        render_footer();
        exit;
    }
    return $user;
}

function log_login_attempt(string $identity, bool $succeeded): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $stmt = db()->prepare('INSERT INTO login_attempts (identity_hash, ip_hash, succeeded) VALUES (?, ?, ?)');
    $stmt->execute([keyed_hash(strtolower($identity)), keyed_hash($ip), $succeeded ? 1 : 0]);
}

function login_is_limited(string $identity): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE succeeded = 0 AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
           AND (identity_hash = ? OR ip_hash = ?)'
    );
    $stmt->execute([keyed_hash(strtolower($identity)), keyed_hash($ip)]);
    return (int) $stmt->fetchColumn() >= 10;
}

function factor_is_limited(int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM two_factor_attempts
         WHERE user_id = ? AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() >= 10;
}

function record_factor_failure(int $userId): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $stmt = db()->prepare('INSERT INTO two_factor_attempts (user_id, ip_hash) VALUES (?, ?)');
    $stmt->execute([$userId, keyed_hash($ip)]);
}

function send_mail(string $to, string $subject, string $html, string $text, bool $includeStandardLayout = true): bool
{
    global $config;
    $mail = $config['mail'] ?? [];
    if (empty($mail['host']) || empty($mail['username']) || empty($mail['password']) || empty($mail['from_email'])) {
        error_log('Secure Auth SMTP delivery unavailable: configure host, username, password, and from_email in config/local.php.');
        return false;
    }
    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('Secure Auth SMTP delivery unavailable: PHPMailer dependencies are missing; run composer install in the project root.');
        return false;
    }
    require_once $autoload;
    try {
        $supportContact = platform_super_admin_contact_details();
        if ($includeStandardLayout) {
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>'
                . '<div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto;color:#172b4d">'
                . '<header style="padding:20px 24px;background:#061D3D;color:#fff;font-weight:700">CONNEZER EDUTECH SYSTEMS</header>'
                . '<main style="padding:24px">' . $html . '</main>'
                . '<footer style="padding:18px 24px;border-top:1px solid #d8dee8;font-size:13px">'
                . 'CONNEZER EDUTECH SYSTEMS · IT Support: <a href="mailto:connezeredutech@gmail.com">connezeredutech@gmail.com</a>'
                . '</footer></div></body></html>';
            $text .= "\n\nCONNEZER EDUTECH SYSTEMS\nIT Support: connezeredutech@gmail.com";
        }
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) $mail['host'];
        $mailer->Port = (int) ($mail['port'] ?? 587);
        $mailer->Timeout = 15;
        $mailer->Timelimit = 15;
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) $mail['username'];
        $password = (string) $mail['password'];
        if (strtolower(rtrim((string) $mail['host'], '.')) === 'smtp.gmail.com') {
            $password = str_replace(' ', '', $password);
        }
        $mailer->Password = $password;
        $mailer->SMTPSecure = ($mail['encryption'] ?? 'tls') === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom((string) $mail['from_email'], 'CONNEZER EDUTECH SYSTEMS');
        $mailer->addReplyTo((string) $supportContact['email'], 'CONNEZER EDUTECH SYSTEMS IT Support');
        $mailer->addAddress($to);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $html;
        $mailer->AltBody = $text;
        return $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $exception) {
        $error = str_replace(
            [(string) $mail['username'], (string) $mail['password']],
            ['[redacted]', '[redacted]'],
            $exception->getMessage()
        );
        error_log('Secure Auth SMTP delivery failed: ' . $error);
        return false;
    }
}

function six_digit_code(): string
{
    return (string) random_int(100000, 999999);
}

function login_otp_email_template(string $code): array
{
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;padding:0;background-color:#f3f6fa;font-family:Arial,Helvetica,sans-serif;color:#263548">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f6fa" style="width:100%;border-collapse:collapse;background-color:#f3f6fa">'
        . '<tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;border-collapse:separate;border-spacing:0;background-color:#ffffff;border:1px solid #e1e8f0;border-radius:12px">'
        . '<tr><td align="center" bgcolor="#0b2d4f" style="padding:20px 24px;background-color:#0b2d4f;color:#ffffff;font-size:15px;line-height:22px;font-weight:700;letter-spacing:0.3px">CONNEZER EDUTECH SYSTEMS</td></tr>'
        . '<tr><td style="padding:30px 28px 24px">'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:32px;font-weight:700;color:#172b4d">Verify your sign-in</h1>'
        . '<p style="margin:0 0 22px;font-size:16px;line-height:24px;color:#4b5c72">Use this six-digit code to complete your login.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse">'
        . '<tr><td align="center" bgcolor="#f0f5fb" style="padding:18px 12px;background-color:#f0f5fb;border:1px solid #e1e8f0;border-radius:8px">'
        . '<span style="font-family:Arial,Helvetica,sans-serif;font-size:32px;line-height:40px;font-weight:700;letter-spacing:8px;color:#0b2d4f">' . e($code) . '</span>'
        . '</td></tr></table>'
        . '<p style="margin:20px 0 12px;font-size:14px;line-height:22px;color:#4b5c72">This code expires in <strong>5 minutes</strong>.</p>'
        . '<p style="margin:0 0 12px;font-size:14px;line-height:22px;color:#4b5c72"><strong>Security notice:</strong> Never share this code. Our team will never ask you to share it.</p>'
        . '<p style="margin:0;font-size:14px;line-height:22px;color:#4b5c72">If you did not request this sign-in, you can ignore this email.</p>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:16px 20px;border-top:1px solid #e1e8f0;background-color:#fafbfd;font-size:13px;line-height:20px;color:#607087">'
        . 'IT Support: <a href="mailto:connezeredutech@gmail.com" style="color:#315f91;text-decoration:underline">connezeredutech@gmail.com</a>'
        . '</td></tr></table>'
        . '</td></tr></table></body></html>';
    $text = "CONNEZER EDUTECH SYSTEMS\n\n"
        . "Verify your sign-in\n"
        . "Use this six-digit code to complete your login.\n\n"
        . $code . "\n\n"
        . "This code expires in 5 minutes.\n"
        . "Security notice: Never share this code. Our team will never ask you to share it.\n"
        . "If you did not request this sign-in, you can ignore this email.\n\n"
        . "IT Support: connezeredutech@gmail.com";

    return [
        'subject' => 'Verify your sign-in',
        'html' => $html,
        'text' => $text,
    ];
}

function mail_code(string $email, string $code, string $purpose): bool
{
    if ($purpose === 'login') {
        $message = login_otp_email_template($code);
        return send_mail($email, $message['subject'], $message['html'], $message['text'], false);
    }

    $title = match ($purpose) {
        'proprietor_setup' => 'Set up your school Proprietor account',
        default => 'Verify your email address',
    };
    $html = '<p>' . e($title) . '</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . e($code) . '</p>'
        . '<p>This code expires in five minutes. Keep it private; CONNEZER EDUTECH SYSTEMS staff will never ask you to share it.</p>'
        . '<p>If you did not request this code, you can ignore this message.</p>';
    $text = $title . "\n\n" . $code . "\n\nThis code expires in five minutes. Keep it private; our staff will never ask you to share it.";
    return send_mail($email, $title, $html, $text);
}

function issue_email_verification_code(int $userId, string $email, string $purpose = 'registration'): string
{
    $stmt = db()->prepare('SELECT sent_at FROM email_verification_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    $old = $stmt->fetch();
    $lastSentAt = $old['sent_at'] ?? null;
    if ($lastSentAt !== null && strtotime((string) $lastSentAt . ' UTC') > time() - 60) {
        return 'rate_limited';
    }
    $code = six_digit_code();
    $codeHash = keyed_hash($code);
    $stmt = db()->prepare(
        'INSERT INTO email_verification_tokens (user_id, code_hash, expires_at, sent_at, attempts, used_at)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), UTC_TIMESTAMP(), 0, NULL)
         ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
           sent_at = VALUES(sent_at), attempts = 0, used_at = NULL'
    );
    $stmt->execute([$userId, $codeHash]);
    if (!mail_code($email, $code, $purpose)) {
        $stmt = db()->prepare(
            'UPDATE email_verification_tokens
             SET code_hash = NULL, expires_at = NULL, sent_at = NULL
             WHERE user_id = ? AND code_hash = ?'
        );
        $stmt->execute([$userId, $codeHash]);
        return 'delivery_failed';
    }
    return 'sent';
}

function issue_login_code(int $userId, string $email): bool
{
    $stmt = db()->prepare(
        "SELECT sent_at FROM login_otp_tokens WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $lastSentAt = $stmt->fetchColumn();
    if ($lastSentAt && strtotime((string) $lastSentAt . ' UTC') > time() - 60) {
        return false;
    }
    $code = six_digit_code();
    $stmt = db()->prepare(
        'INSERT INTO login_otp_tokens (user_id, code_hash, expires_at, sent_at, attempts)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), UTC_TIMESTAMP(), 0)
         ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
           sent_at = VALUES(sent_at), attempts = 0'
    );
    $stmt->execute([$userId, keyed_hash($code)]);
    if (!mail_code($email, $code, 'login')) {
        $_SESSION['pending_login_otp_fallback'] = $code;
        audit($userId, 'login_otp_mail_failed');
        return false;
    }
    unset($_SESSION['pending_login_otp_fallback']);
    audit($userId, 'login_otp_sent');
    return true;
}

function totp_secret(int $bytes = 20): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = random_bytes($bytes);
    $bits = '';
    for ($i = 0; $i < strlen($binary); $i++) {
        $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0');
        }
        $encoded .= $alphabet[bindec($chunk)];
    }
    return $encoded;
}

function base32_decode(string $value): string
{
    $normalized = strtoupper((string) $value);
    $normalized = preg_replace('/[\s\-]+|=+$/', '', $normalized) ?? '';
    if ($normalized === '') {
        throw new InvalidArgumentException('Invalid authenticator secret.');
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($normalized) as $character) {
        $index = strpos($alphabet, $character);
        if ($index === false) {
            throw new InvalidArgumentException('Invalid authenticator secret.');
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }
    $binary = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $binary .= chr(bindec($chunk));
        }
    }
    return $binary;
}

function totp_code(string $secret, ?int $time = null): string
{
    $counter = intdiv($time ?? time(), 30);
    $counterBytes = pack('N2', intdiv($counter, 0x100000000), $counter & 0xffffffff);
    $hash = hash_hmac('sha1', $counterBytes, base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function verify_totp(string $secret, string $candidate): bool
{
    $candidate = trim($candidate);
    if (!preg_match('/^\d{6}$/', $candidate)) {
        return false;
    }
    foreach ([-30, 0, 30] as $offset) {
        if (hash_equals(totp_code($secret, time() + $offset), $candidate)) {
            return true;
        }
    }
    return false;
}

function encrypt_totp_secret(string $secret): string
{
    global $config;
    $key = hash('sha256', (string) $config['app_key'], true);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($secret, $nonce, $key);
}

function decrypt_totp_secret(string $ciphertext): string
{
    global $config;
    $key = hash('sha256', (string) $config['app_key'], true);
    $nonce = substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    if ($plain === false) {
        throw new RuntimeException('Authenticator secret could not be decrypted. Check the configured app_key.');
    }
    return $plain;
}

function login_factors_complete(bool $emailOtpVerified, bool $totpRequired, bool $totpVerified): bool
{
    return $emailOtpVerified && (!$totpRequired || $totpVerified);
}

function account_email_correction_allowed(string $currentEmail, string $replacementEmail): bool
{
    return filter_var($currentEmail, FILTER_VALIDATE_EMAIL) === false
        && filter_var($replacementEmail, FILTER_VALIDATE_EMAIL) !== false
        && strlen($replacementEmail) <= 254;
}

function complete_login(int $userId): void
{
    $stmt = db()->prepare(
        'SELECT r.name
         FROM user_roles ur JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ?
         ORDER BY r.name'
    );
    $stmt->execute([$userId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $userId, 'csrf_token' => bin2hex(random_bytes(32))];
    if (count($roles) === 1) {
        $_SESSION['active_role'] = (string) $roles[0];
    }
    audit($userId, 'login_success');
    redirect('dashboard.php');
}

function restore_remembered_session(): void
{
    if (!array_key_exists('remember_auth', $_COOKIE)) {
        return;
    }
    setcookie('remember_auth', '', [
        'expires' => 1,
        'path' => rtrim((string) parse_url((string) ($GLOBALS['config']['app_url'] ?? '/'), PHP_URL_PATH), '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    unset($_COOKIE['remember_auth']);
}

function clear_auth_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION = [];
}

function clear_remember_cookie(): void
{
    $cookie = $_COOKIE['remember_auth'] ?? '';
    if (is_string($cookie) && preg_match('/^([a-f0-9]{24}):[a-f0-9]{64}$/', $cookie, $matches)) {
        $stmt = db()->prepare('DELETE FROM remember_tokens WHERE selector = ?');
        $stmt->execute([$matches[1]]);
    }
    setcookie('remember_auth', '', [
        'expires' => time() - 3600,
        'path' => rtrim((string) parse_url((string) ($GLOBALS['config']['app_url'] ?? '/'), PHP_URL_PATH), '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function role_home(array $user): string
{
    if (platform_super_admin_user($user)) {
        return 'platform_admin.php';
    }
    $stmt = db()->prepare(
        'SELECT DISTINCT school_id
         FROM school_memberships
         WHERE user_id = ? AND status = ?
         ORDER BY id'
    );
    $stmt->execute([(int) ($user['id'] ?? 0), 'active']);
    $activeMemberships = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($activeMemberships) === 1) {
        set_active_school((int) $activeMemberships[0]);
        $roleStmt = db()->prepare(
            'SELECT role_name FROM school_memberships
             WHERE user_id = ? AND school_id = ? AND status = "active"
             ORDER BY (role_name = "school_student") ASC, id LIMIT 1'
        );
        $roleStmt->execute([(int) ($user['id'] ?? 0), (int) $activeMemberships[0]]);
        if ($roleStmt->fetchColumn() === 'school_student') {
            return 'student_dashboard.php';
        }
        return 'school_dashboard.php';
    }
    return 'schools.php';
}

function role_display_name(string $role): string
{
    static $labels = [
        'platform_super_admin' => 'Platform Super Admin',
        'school_proprietor' => 'Proprietor',
        'school_headmaster' => 'Headmaster',
        'school_assistant_headmaster' => 'Assistant Headmaster',
        'school_secretary' => 'Secretary / Administrator',
        'class_teacher' => 'Class Teacher',
        'subject_teacher' => 'Subject Teacher',
        'school_accountant' => 'Accountant',
        'school_parent' => 'Parent / Guardian',
        'school_student' => 'Student',
        'student' => 'Student',
        'school_user' => 'School User',
    ];
    if (isset($labels[$role])) {
        return $labels[$role];
    }
    return str_starts_with($role, 'custom_')
        ? ucwords(str_replace('_', ' ', substr($role, 7)))
        : ucwords(str_replace('_', ' ', $role));
}

function role_description(string $role): string
{
    return match ($role) {
        'platform_super_admin' => 'Oversee platform operations and registered schools.',
        'school_proprietor' => 'Manage your school and its administration.',
        'school_headmaster' => 'Manage permitted academic operations at your school.',
        'school_assistant_headmaster' => 'Support school leadership with delegated academic and student administration.',
        'school_secretary' => 'Manage permitted student and administrative records without finance or school-setting access.',
        'class_teacher', 'subject_teacher' => 'Access assigned teaching and academic records.',
        'school_accountant' => 'Manage permitted school finance records.',
        'school_parent' => 'View records for your linked children.',
        'school_student' => 'View your own school, academic, attendance, and fee records.',
        'student', 'school_user' => 'Use the school services authorised for your account.',
        default => 'Use the school services authorised for this role.',
    };
}

function role_icon_name(string $role): string
{
    if (in_array($role, ['it_manager', 'administrator'], true)) {
        return 'security';
    }
    if (in_array($role, ['ordinary_member', 'general_user', 'student'], true)) {
        return 'members';
    }
    return 'roles';
}

function allocate_membership_number(int $graduationYear): string
{
    if ($graduationYear < 1900 || $graduationYear > (int) gmdate('Y')) {
        throw new InvalidArgumentException('A valid graduation year is required to generate a membership number.');
    }
    if (!db()->inTransaction()) {
        throw new LogicException('Membership numbers must be allocated inside a database transaction.');
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO membership_id_sequences (graduation_year, last_sequence)
         VALUES (?, 0)'
    );
    $stmt->execute([$graduationYear]);
    $stmt = db()->prepare(
        'SELECT last_sequence
         FROM membership_id_sequences
         WHERE graduation_year = ?
         FOR UPDATE'
    );
    $stmt->execute([$graduationYear]);
    $lastSequence = $stmt->fetchColumn();
    if ($lastSequence === false) {
        throw new RuntimeException('The graduation-year membership sequence could not be locked.');
    }
    $nextSequence = (int) $lastSequence;
    $membershipExists = db()->prepare(
        'SELECT 1 FROM member_profiles WHERE membership_number = ? LIMIT 1'
    );
    do {
        $nextSequence++;
        if ($nextSequence > 999) {
            throw new OverflowException('This graduation year has reached the 999 membership-number limit.');
        }
        $membershipNumber = 'OSAAK/' . substr((string) $graduationYear, -2)
            . str_pad((string) $nextSequence, 3, '0', STR_PAD_LEFT);
        $membershipExists->execute([$membershipNumber]);
    } while ($membershipExists->fetchColumn() !== false);
    $stmt = db()->prepare(
        'UPDATE membership_id_sequences
         SET last_sequence = ?
         WHERE graduation_year = ?'
    );
    $stmt->execute([$nextSequence, $graduationYear]);
    return $membershipNumber;
}

function destroy_auth_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => 1,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}
