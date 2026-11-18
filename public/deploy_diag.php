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

    // Split statements by semicolon at the end of line
    $rawStatements = preg_split('/;\s*(\r?\n|$)/', $sqlContent);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $tableStatements = [];
    foreach ($rawStatements as $rawStmt) {
        $trimmed = trim($rawStmt);
        if (preg_match('/^CREATE TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $trimmed, $m)) {
            $tableName = $m[1];
            // Replace CREATE TABLE with CREATE TABLE IF NOT EXISTS
            $safeStmt = preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $trimmed);
            $tableStatements[] = [
                'name' => $tableName,
                'sql' => $safeStmt,
            ];
        }
    }

    $offset = (int) ($_GET['offset'] ?? 0);
    $limit = (int) ($_GET['limit'] ?? 100);
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
