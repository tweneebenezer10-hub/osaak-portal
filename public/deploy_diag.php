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
    // 1. Existing tables
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    // 2. Users in database
    $users = [];
    if (in_array('users', $tables, true)) {
        $userStmt = $pdo->query('SELECT id, full_name, username, email, status, email_verified_at, created_at FROM users LIMIT 50');
        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. Schools in database
    $schools = [];
    if (in_array('schools', $tables, true)) {
        $schoolStmt = $pdo->query('SELECT id, name, short_name, status, subscription_status FROM schools LIMIT 50');
        $schools = $schoolStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4. Check migrations
    $migrationsDir = APP_ROOT . '/database/migrations';
    $migrationFiles = glob($migrationsDir . '/*.sql');
    sort($migrationFiles);

    // Check specific milestone tables
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

if ($action === 'migrate') {
    $migrationsDir = APP_ROOT . '/database/migrations';
    $migrationFiles = glob($migrationsDir . '/*.sql');
    sort($migrationFiles);

    $results = [];

    // Optional limit or range
    $startFrom = (int) ($_GET['from'] ?? 1);
    $upTo = (int) ($_GET['to'] ?? 109);

    foreach ($migrationFiles as $file) {
        $filename = basename($file);
        if (preg_match('/^(\d+)_/', $filename, $m)) {
            $num = (int) $m[1];
            if ($num < $startFrom || $num > $upTo) {
                continue;
            }
        }

        $sqlContent = file_get_contents($file);
        if ($sqlContent === false || trim($sqlContent) === '') {
            continue;
        }

        // Split SQL into individual statements
        // Remove comments
        $lines = explode("\n", $sqlContent);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $cleanSql = implode("\n", $cleanLines);

        // Split statements by semicolon followed by newline or EOF
        $statements = preg_split('/;\s*(\r?\n|$)/', $cleanSql);

        $executed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($statements as $stmtSql) {
            $stmtSql = trim($stmtSql);
            if ($stmtSql === '') {
                continue;
            }

            try {
                $pdo->exec($stmtSql);
                $executed++;
            } catch (PDOException $e) {
                $code = (int) $e->errorInfo[1] ?? 0;
                // Safe ignore codes:
                // 1050: Table already exists
                // 1060: Duplicate column name
                // 1061: Duplicate key name
                // 1062: Duplicate entry
                // 1068: Multiple primary key defined
                // 1091: Can't DROP (doesn't exist)
                // 1022: Can't write; duplicate key in table
                if (in_array($code, [1050, 1060, 1061, 1062, 1068, 1091, 1022], true)) {
                    $skipped++;
                } else {
                    $errors[] = [
                        'code' => $code,
                        'message' => $e->getMessage(),
                        'sql' => substr($stmtSql, 0, 120),
                    ];
                }
            }
        }

        $results[$filename] = [
            'executed' => $executed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    echo json_encode([
        'status' => 'complete',
        'migrations_processed' => count($results),
        'results' => $results,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'seed_defaults') {
    // If no schools exist, seed the standard default school record so registration works immediately
    $seeded = [];
    $schoolCount = (int) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn();
    if ($schoolCount === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO schools (id, name, short_name, school_type, status, subscription_status, created_at, updated_at)
             VALUES (1, 'Bright Future Academy', 'BFA', 'basic_school', 'active', 'active', NOW(), NOW())"
        );
        $stmt->execute();
        $seeded[] = 'Bright Future Academy';
    }

    echo json_encode([
        'status' => 'complete',
        'seeded' => $seeded,
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
