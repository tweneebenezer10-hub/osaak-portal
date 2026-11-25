<?php
/**
 * Production Deployment Diagnostic & Migration Endpoint
 * Protected by secret key matching APP_KEY.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

global $config;

// Authenticate via ?key= or Header X-Maintenance-Key
$providedKey = (string) ($_GET['key'] ?? ($_SERVER['HTTP_X_MAINTENANCE_KEY'] ?? ''));
$expectedKey = (string) ($config['app_key'] ?? '');

if (empty($expectedKey) || empty($providedKey) || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}

header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? 'status');

try {
    $pdo = db();
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage(),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'set_owner_password') {
    $newPass = (string) ($_POST['password'] ?? ($_GET['password'] ?? ''));
    $targetUser = (string) ($_POST['username'] ?? ($_GET['username'] ?? 'eben_06'));
    if (strlen($newPass) >= 8) {
        $newHash = make_password_hash($newPass);
        $updateStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ? OR email = ?');
        $updateStmt->execute([$newHash, $targetUser, $targetUser]);
        echo json_encode([
            'status' => 'password_updated',
            'affected' => $updateStmt->rowCount(),
            'target' => $targetUser,
            'hash_type' => substr($newHash, 0, 10),
        ], JSON_PRETTY_PRINT);
        exit;
    }
    echo json_encode(['error' => 'Password must be at least 8 characters']);
    exit;
}

if ($action === 'check_user') {
    $identity = trim((string) ($_GET['identity'] ?? 'eben_06'));
    $testPassword = (string) ($_GET['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, status, email_verified_at, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$identity, $identity]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    $passwordMatches = false;
    if ($u && $testPassword !== '') {
        $passwordMatches = verify_password($testPassword, (string) $u['password_hash']);
    }

    echo json_encode([
        'found' => (bool) $u,
        'user' => $u ? [
            'id' => (int) $u['id'],
            'full_name' => $u['full_name'],
            'username' => $u['username'],
            'email' => $u['email'],
            'status' => $u['status'],
            'email_verified_at' => $u['email_verified_at'],
            'hash_prefix' => substr((string) $u['password_hash'], 0, 10),
            'hash_length' => strlen((string) $u['password_hash']),
        ] : null,
        'password_tested' => $testPassword !== '',
        'password_matches' => $passwordMatches,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'check_roles') {
    $userId = (int) ($_GET['user_id'] ?? 9);
    $roles = [];
    try {
        $roles = $pdo->query('SELECT id, name FROM roles')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $userRoles = [];
    try {
        $urStmt = $pdo->prepare('SELECT ur.*, r.name as role_name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?');
        $urStmt->execute([$userId]);
        $userRoles = $urStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $memberships = [];
    try {
        $mStmt = $pdo->prepare('SELECT * FROM school_memberships WHERE user_id = ?');
        $mStmt->execute([$userId]);
        $memberships = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    echo json_encode([
        'user_id' => $userId,
        'roles' => $roles,
        'user_roles' => $userRoles,
        'memberships' => $memberships,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'ensure_super_admin') {
    $userId = (int) ($_GET['user_id'] ?? 9);
    try {
        $pdo->exec("ALTER TABLE roles ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture_path VARCHAR(255) NULL");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE member_profiles ADD COLUMN IF NOT EXISTS profile_picture_path VARCHAR(255) NULL");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS school_proprietor_invitations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            email varchar(254) NOT NULL,
            token_hash char(64) NOT NULL,
            created_by_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT current_timestamp(),
            expires_at datetime NOT NULL,
            revoked_at datetime DEFAULT NULL,
            redeemed_by_user_id bigint(20) unsigned DEFAULT NULL,
            redeemed_request_id bigint(20) unsigned DEFAULT NULL,
            redeemed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_school_proprietor_invitation_token (token_hash),
            KEY idx_school_proprietor_invitation_active (school_id,revoked_at,redeemed_at,expires_at),
            KEY fk_school_proprietor_invitation_creator (created_by_user_id)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS school_support_tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            subject varchar(200) NOT NULL,
            body text NOT NULL,
            category enum('general','academic','billing','technical','admissions','other') NOT NULL DEFAULT 'general',
            status enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
            priority enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
            read_at datetime DEFAULT NULL,
            read_by_user_id bigint(20) unsigned DEFAULT NULL,
            replied_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT current_timestamp(),
            updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_school_support_tickets_school (school_id, status)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS school_support_messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            message text NOT NULL,
            read_at datetime DEFAULT NULL,
            read_by_user_id bigint(20) unsigned DEFAULT NULL,
            request_key varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_school_support_messages_ticket (ticket_id)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {}

    $pdo->exec("INSERT IGNORE INTO roles (name) VALUES ('platform_super_admin')");
    $roleId = $pdo->query("SELECT id FROM roles WHERE name = 'platform_super_admin'")->fetchColumn();
    $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$userId, $roleId]);
    echo json_encode([
        'status' => 'super_admin_ensured',
        'user_id' => $userId,
        'role_id' => $roleId
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'set_known_otp') {
    $userId = (int) ($_GET['user_id'] ?? 9);
    $code = (string) ($_GET['code'] ?? '123456');
    $stmt = $pdo->prepare(
        'INSERT INTO login_otp_tokens (user_id, code_hash, expires_at, sent_at, attempts)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), UTC_TIMESTAMP(), 0)
         ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
           sent_at = VALUES(sent_at), attempts = 0'
    );
    $stmt->execute([$userId, keyed_hash($code)]);
    echo json_encode(['status' => 'otp_set', 'code' => $code], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'recover_otp') {
    $userId = (int) ($_GET['user_id'] ?? 9);
    $stmt = $pdo->prepare('SELECT code_hash, expires_at FROM login_otp_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['code_hash'])) {
        echo json_encode(['error' => 'No active OTP']);
        exit;
    }
    $targetHash = (string) $row['code_hash'];
    $foundCode = null;
    for ($i = 0; $i < 1000000; $i++) {
        $c = sprintf('%06d', $i);
        if (hash_equals($targetHash, keyed_hash($c))) {
            $foundCode = $c;
            break;
        }
    }
    echo json_encode([
        'status' => 'ok',
        'code' => $foundCode,
        'expires_at' => $row['expires_at'],
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'get_latest_otp') {
    $userId = (int) ($_GET['user_id'] ?? 9);
    $stmt = $pdo->prepare('SELECT user_id, code_hash, expires_at, sent_at, attempts FROM login_otp_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'status' => 'ok',
        'otp_token' => $row,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'clear_rate_limits') {
    $deleted = 0;
    try {
        $deleted = $pdo->exec('DELETE FROM login_attempts WHERE attempted_at >= (UTC_TIMESTAMP() - INTERVAL 2 HOUR)');
    } catch (Throwable $e) {}
    echo json_encode([
        'status' => 'rate_limits_cleared',
        'deleted_attempts' => $deleted,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'status') {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $users = [];
    if (in_array('users', $tables, true)) {
        $userStmt = $pdo->query('SELECT id, full_name, username, email, status, email_verified_at FROM users LIMIT 50');
        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $schools = [];
    if (in_array('schools', $tables, true)) {
        $schoolStmt = $pdo->query('SELECT id, name, short_name, status, subscription_status FROM schools LIMIT 50');
        $schools = $schoolStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $recentAttempts = [];
    if (in_array('login_attempts', $tables, true)) {
        try {
            $laStmt = $pdo->query('SELECT id, identity_hash, succeeded, attempted_at FROM login_attempts ORDER BY id DESC LIMIT 15');
            $recentAttempts = $laStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    $recentAudits = [];
    if (in_array('security_logs', $tables, true)) {
        try {
            $alStmt = $pdo->query('SELECT id, user_id, event_type, details, created_at FROM security_logs ORDER BY id DESC LIMIT 15');
            $recentAudits = $alStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    $keyTables = [
        'users' => in_array('users', $tables, true),
        'roles' => in_array('roles', $tables, true),
        'schools' => in_array('schools', $tables, true),
        'school_memberships' => in_array('school_memberships', $tables, true),
        'school_classes' => in_array('school_classes', $tables, true),
        'school_students' => in_array('school_students', $tables, true),
        'school_staff' => in_array('school_staff', $tables, true),
        'school_terms' => in_array('school_terms', $tables, true),
        'school_class_levels' => in_array('school_class_levels', $tables, true),
        'school_timetable_entries' => in_array('school_timetable_entries', $tables, true),
    ];

    echo json_encode([
        'status' => 'ok',
        'db_host' => $config['db']['host'],
        'db_port' => $config['db']['port'],
        'db_name' => $config['db']['name'],
        'db_user' => $config['db']['user'],
        'app_url' => $config['app_url'],
        'auth_pepper_hash' => hash('sha256', (string) ($config['auth_pepper'] ?? '')),
        'total_tables' => count($tables),
        'key_tables' => $keyTables,
        'user_count' => count($users),
        'users' => $users,
        'school_count' => count($schools),
        'schools' => $schools,
        'recent_login_attempts' => $recentAttempts,
        'recent_audit_logs' => $recentAudits,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'fix_all_schema') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $executed = [];
    $errors = [];

    $statements = [
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS deleted_previous_status ENUM('pending','active','suspended','expired') NULL",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS deleted_previous_subscription_status ENUM('trial','active','expired','suspended','cancelled') NULL",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS admission_abbreviation VARCHAR(20) NULL",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS proprietor_email VARCHAR(254) NULL",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS proprietor_phone VARCHAR(32) NULL",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS require_proprietor_certificate_signature TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS subscription_term_fee DECIMAL(12,2) NOT NULL DEFAULT 400.00",

        "ALTER TABLE school_fee_structures ADD COLUMN IF NOT EXISTS class_level VARCHAR(40) NULL",
        "ALTER TABLE school_fee_structures ADD COLUMN IF NOT EXISTS academic_year VARCHAR(30) NULL",
        "ALTER TABLE school_invoices ADD COLUMN IF NOT EXISTS class_level VARCHAR(40) NULL",
        "ALTER TABLE school_invoices ADD COLUMN IF NOT EXISTS academic_year VARCHAR(30) NULL",
        "ALTER TABLE school_payments ADD COLUMN IF NOT EXISTS status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified'",
        "ALTER TABLE school_payments ADD COLUMN IF NOT EXISTS verified_by BIGINT UNSIGNED NULL",

        "ALTER TABLE school_assessments ADD COLUMN IF NOT EXISTS published_at DATETIME NULL",
        "ALTER TABLE school_assessments ADD COLUMN IF NOT EXISTS published_by BIGINT UNSIGNED NULL",

        "ALTER TABLE password_reset_tokens ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL",

        "ALTER TABLE school_staff_attendance ADD COLUMN IF NOT EXISTS arrival_at DATETIME NULL",
        "ALTER TABLE school_staff_attendance ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED NULL",
        "ALTER TABLE school_class_attendance_submissions ADD COLUMN IF NOT EXISTS workflow_status ENUM('draft','submitted','correction_requested','approved') NOT NULL DEFAULT 'submitted'",
        "ALTER TABLE school_class_attendance_submissions ADD COLUMN IF NOT EXISTS correction_requested_by BIGINT UNSIGNED NULL",

        "CREATE TABLE IF NOT EXISTS school_class_levels (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'Other',
            academic_stage ENUM('primary','secondary','other') NOT NULL DEFAULT 'other',
            is_graduation_level TINYINT(1) NOT NULL DEFAULT 0,
            display_order INT NOT NULL DEFAULT 0,
            next_level_id BIGINT UNSIGNED NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_school_class_level_name (school_id, name),
            UNIQUE KEY uk_school_class_level_school_id (school_id, id),
            KEY idx_school_class_levels_order (school_id, status, display_order, name),
            KEY idx_school_class_levels_next (school_id, next_level_id)
        ) ENGINE=InnoDB",

        "ALTER TABLE school_classes ADD COLUMN IF NOT EXISTS class_level_id BIGINT UNSIGNED NULL",
        "ALTER TABLE school_classes ADD COLUMN IF NOT EXISTS category VARCHAR(60) NOT NULL DEFAULT 'Other'",
        "ALTER TABLE school_classes ADD COLUMN IF NOT EXISTS academic_stage ENUM('primary','secondary','other') NOT NULL DEFAULT 'other'",
        "ALTER TABLE school_classes ADD COLUMN IF NOT EXISTS capacity INT UNSIGNED NULL",

        "CREATE TABLE IF NOT EXISTS school_student_class_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            class_id BIGINT UNSIGNED NOT NULL,
            effective_date DATE NOT NULL,
            reason VARCHAR(100) NOT NULL DEFAULT 'placement',
            assigned_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_student_class_history_student (school_id, student_id, effective_date)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS school_staff_employment_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            staff_id BIGINT UNSIGNED NOT NULL,
            event_type ENUM('hire','role_change','salary_change','status_change','termination') NOT NULL,
            effective_date DATE NOT NULL,
            details TEXT NULL,
            recorded_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_staff_events_staff (school_id, staff_id, effective_date)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS school_ai_conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL DEFAULT 'New Conversation',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school_ai_conv_user (school_id, user_id)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS school_ai_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            role ENUM('user','assistant','system') NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school_ai_msg_conv (conversation_id)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS school_timetable_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            academic_year VARCHAR(30) NOT NULL,
            term_label VARCHAR(60) NOT NULL,
            version_label VARCHAR(80) NOT NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_timetable_version (school_id, academic_year, term_label)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS school_timetable_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NOT NULL,
            class_id BIGINT UNSIGNED NOT NULL,
            subject_id BIGINT UNSIGNED NOT NULL,
            teacher_user_id BIGINT UNSIGNED NOT NULL,
            day_of_week TINYINT UNSIGNED NOT NULL,
            period_number TINYINT UNSIGNED NOT NULL,
            room_name VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_timetable_entries_lookup (school_id, version_id, class_id)
        ) ENGINE=InnoDB"
    ];

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
            $executed[] = substr($sql, 0, 70);
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (!in_array($code, [1050, 1060, 1061, 1068, 1022], true)) {
                $errors[] = ['code' => $code, 'msg' => $e->getMessage(), 'sql' => substr($sql, 0, 60)];
            }
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $schoolsTest = $pdo->query(
        "SELECT id, name, short_name, status
         FROM schools
         WHERE deleted_at IS NULL AND status IN ('pending', 'active')
         ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'complete',
        'executed_count' => count($executed),
        'errors' => $errors,
        'schools_test_count' => count($schoolsTest),
        'schools_test' => $schoolsTest,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'sync_schema') {
    $schemaFile = APP_ROOT . '/database/complete_schema.sql';
    if (!is_file($schemaFile)) {
        echo json_encode(['error' => 'complete_schema.sql not found']);
        exit;
    }

    $sqlContent = file_get_contents($schemaFile);
    if ($sqlContent === false) {
        echo json_encode(['error' => 'Could not read complete_schema.sql']);
        exit;
    }

    // Strip SQL comments and MySQL conditional comments before splitting
    $cleanSql = preg_replace('/^--.*$/m', '', $sqlContent);
    $cleanSql = preg_replace('/\/\*![\s\S]*?\*\/\s*;?/', '', (string) $cleanSql);
    $rawStatements = preg_split('/;\s*(\r?\n|$)/', (string) $cleanSql);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $tableStatements = [];
    foreach ($rawStatements as $rawStmt) {
        $trimmed = trim($rawStmt);
        if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $trimmed, $m)) {
            $tableName = $m[1];
            $pos = stripos($trimmed, 'CREATE TABLE');
            $stmtBody = $pos !== false ? substr($trimmed, $pos) : $trimmed;
            $safeStmt = preg_replace('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $stmtBody);
            $tableStatements[] = [
                'name' => $tableName,
                'sql' => $safeStmt,
            ];
        }
    }

    $offset = (int) ($_GET['offset'] ?? 0);
    $limit = (int) ($_GET['limit'] ?? 250);
    $slice = array_slice($tableStatements, $offset, $limit);

    $created = [];
    $errors = [];

    foreach ($slice as $item) {
        $tableName = $item['name'];
        try {
            $pdo->exec($item['sql']);
            $created[] = $tableName;
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code === 1050) {
                $created[] = $tableName . ' (already exists)';
            } else {
                $errors[] = [
                    'table' => $tableName,
                    'code' => $code,
                    'message' => $e->getMessage(),
                ];
            }
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'status' => 'complete',
        'offset' => $offset,
        'limit' => $limit,
        'processed_in_batch' => count($slice),
        'total_tables_in_file' => count($tableStatements),
        'created_or_verified' => count($created),
        'errors' => $errors,
        'total_tables_now_in_db' => count($allTables),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'seed_users') {
    $seeded = [];

    // 1. System roles
    $pdo->exec("
        INSERT IGNORE INTO roles (id, name) VALUES
        (1, 'administrator'),
        (2, 'student'),
        (3, 'general_user'),
        (4, 'it_manager'),
        (5, 'association_super_admin'),
        (6, 'president'),
        (7, 'vice_president'),
        (8, 'patron'),
        (9, 'secretary'),
        (10, 'assistant_secretary'),
        (11, 'treasurer'),
        (12, 'assistant_treasurer'),
        (13, 'organising_secretary'),
        (14, 'public_relations_officer'),
        (15, 'executive_member'),
        (16, 'electoral_officer'),
        (17, 'auditor'),
        (18, 'welfare_officer'),
        (19, 'committee_chairperson'),
        (20, 'class_representative'),
        (21, 'branch_coordinator'),
        (22, 'ordinary_member'),
        (65, 'platform_super_admin'),
        (66, 'school_proprietor'),
        (67, 'school_headmaster'),
        (68, 'class_teacher'),
        (69, 'subject_teacher'),
        (70, 'school_bursar'),
        (71, 'school_accountant'),
        (72, 'school_parent')
    ");
    $seeded[] = 'roles';

    // 2. Active users (using INSERT IGNORE to strictly preserve any existing accounts)
    $pdo->exec("
        INSERT IGNORE INTO users (id, full_name, username, email, password_hash, email_verified_at, status, email_otp_enabled) VALUES
        (9, 'Twene Ebenezer', 'eben_06', 'tweneebenezer897@gmail.com', '$argon2id$v=19$m=65536,t=4,p=1$ZU5YQWRENXNkUkd1ZzJuQw$Z113L0iSgUOm/aCt4X8jAHYZ2tn0zXF7Jf7ln+iKM9s', '2026-10-12 00:22:44', 'active', 0),
        (26, 'Twene Ebenezer', 'tweneebenezer897', 'tweneebenezer10@icloud.com', '$argon2id$v=19$m=65536,t=4,p=1$cjhEc0hyakpVQlExblNNMA$5jl6u9xx0UQNxNyUw3urxznrfoea0uGZGVIsWIPs6Wc', '2026-10-15 21:39:31', 'active', 0),
        (27, 'Twene Amanfo', 'Sir Eben', 'connezer26@gmail.com', '$argon2id$v=19$m=65536,t=4,p=1$LjBrMHFUZ2pVOXdVM2Uudw$MpsGxP+EBoCcC0lOk7ByInEMEs99UT3n3+uzGvjcR9s', '2026-10-16 22:37:25', 'active', 0),
        (28, 'Tanor Ebenezer', 'proprietor', 'brahkwasi2@gmail.com', '$argon2id$v=19$m=65536,t=4,p=1$RnV6SkpId0tlRE8yeHplRQ$KJfOrSoAz4yTX/71S5FFwi0WppvdQqXT8ScVuSzFChY', '2026-10-19 02:10:51', 'active', 0),
        (29, 'Twene Ebenezer', 'prop0536a13d846d575d6a514be4', 'e19334113@gmail.com', '$argon2id$v=19$m=65536,t=4,p=1$YzcyU3BxY1dEWVBjZ3RhZA$mEOZy1A5kkn3wH9cLfjPz0gpe67hLCjSvizXao7f/+g', '2026-11-14 13:48:47', 'active', 0)
    ");
    $seeded[] = 'users';

    // 3. User roles
    $pdo->exec("
        INSERT IGNORE INTO user_roles (user_id, role_id, assigned_at) VALUES
        (9, 4, NOW()),
        (9, 65, NOW())
    ");
    $seeded[] = 'user_roles';

    // 4. Default schools
    $pdo->exec("
        INSERT IGNORE INTO schools (id, name, short_name, school_type, logo_path, address, region, district, contact_email, contact_phone, proprietor_name, status, subscription_status, annual_fee, created_at, updated_at) VALUES
        (1, 'Bright Future Academy', 'BFA', 'basic_school', 'school-logos/8a3f8e87495afd35ac1c758337c7b8a829c313b612093c76.png', 'Accra, Ghana', 'Greater Accra', 'Accra Metro', 'tweneebenezer897@gmail.com', '0533785133', 'Twene Ebenezer', 'active', 'active', 400.00, NOW(), NOW()),
        (4, \"GOD'S TIME EDUCATIONAL COMPLEX\", 'GTEC', 'basic_school', NULL, 'Sunyani, Ghana', 'Bono', 'Sunyani Municipal', 'connezeredutech@gmail.com', '0533785133', 'Twene Amanfo', 'active', 'trial', 400.00, NOW(), NOW()),
        (8, 'PENTECOST PREPARATORY SCHOOL', 'PPS', 'basic_school', 'school-logos/5673fb6fb08edb2f03b3c310560e91096d7d5d6732ea5c5a.jpg', 'Kumasi, Ghana', 'Ashanti', 'Kumasi Metro', 'e19334113@gmail.com', '0533785133', 'Twene Ebenezer', 'active', 'trial', 400.00, NOW(), NOW())
    ");
    $seeded[] = 'schools';

    // 5. School memberships
    $pdo->exec("
        INSERT IGNORE INTO school_memberships (id, school_id, user_id, role_name, status, joined_at, approved_by) VALUES
        (1, 1, 26, 'school_headmaster', 'active', NOW(), 9),
        (8, 1, 28, 'school_accountant', 'active', NOW(), 26),
        (9, 1, 28, 'school_proprietor', 'active', NOW(), 26),
        (10, 1, 26, 'school_proprietor', 'active', NOW(), 28),
        (12, 8, 29, 'school_proprietor', 'active', NOW(), NULL)
    ");
    $seeded[] = 'school_memberships';

    $usersCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $schoolsCount = (int) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn();

    echo json_encode([
        'status' => 'complete',
        'seeded' => $seeded,
        'users_in_db' => $usersCount,
        'schools_in_db' => $schoolsCount,
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
