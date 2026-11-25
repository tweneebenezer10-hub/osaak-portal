<?php
declare(strict_types=1);

/**
 * Integration tests for School-Specific Multi-PC Staff Attendance Configuration (Part 8).
 * Covers all 10 required scenarios using an isolated in-memory SQLite database.
 */
$config = [
    'auth_pepper' => str_repeat('a', 64),
    'app_key' => str_repeat('b', 64),
    'app_url' => 'https://connezer-edutech-system.vercel.app',
];
require_once dirname(__DIR__) . '/app/functions.php';
require_once dirname(__DIR__) . '/app/school_tenant.php';
require_once dirname(__DIR__) . '/app/school_staff_attendance.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Override db() global cache via reflection/static if needed, or test functions that accept PDO directly
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, email TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", email_verified_at TEXT NULL)');
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE schools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, short_name TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", deleted_at TEXT NULL)');
$pdo->exec('CREATE TABLE school_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT, school_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role_name TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active")');
$pdo->exec('CREATE TABLE security_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, event_type TEXT NOT NULL, ip_address TEXT NULL, details TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE school_staff_attendance_kiosk_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    school_id INTEGER NOT NULL,
    device_name TEXT NOT NULL,
    device_location TEXT NOT NULL DEFAULT "Main Office",
    token_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "active",
    device_uid TEXT NULL,
    agent_secret_hash TEXT NULL,
    auth_mode TEXT NOT NULL DEFAULT "browser_credential",
    enrollment_code_hash TEXT NULL,
    enrollment_expires_at TEXT NULL,
    registered_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TEXT NULL,
    deactivated_by INTEGER NULL,
    deactivated_at TEXT NULL,
    revoked_by INTEGER NULL,
    revoked_at TEXT NULL,
    replaced_by_device_id INTEGER NULL,
    UNIQUE (school_id, token_hash),
    UNIQUE (school_id, device_uid)
)');
$pdo->exec('CREATE TABLE school_staff_attendance_security_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    school_id INTEGER NOT NULL,
    device_id INTEGER NULL,
    staff_id INTEGER NULL,
    actor_user_id INTEGER NULL,
    event_type TEXT NOT NULL,
    client_key TEXT NULL,
    reason TEXT NULL,
    occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$assertTrue = static function (bool $cond, string $label): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
};
$assertSame = static function (mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n");
        exit(1);
    }
};

$tz = new DateTimeZone('Africa/Accra');

// Seed 2 schools and users
$pdo->exec("INSERT INTO schools (id, name, short_name) VALUES (1, 'School A', 'SCHA'), (2, 'School B', 'SCHB')");
$pdo->exec("INSERT INTO users (id, full_name, email, email_verified_at) VALUES
    (10, 'Headmaster A', 'hm@a.test', '2026-01-01 00:00:00'),
    (11, 'Proprietor A', 'prop@a.test', '2026-01-01 00:00:00'),
    (12, 'Headmaster B', 'hm@b.test', '2026-01-01 00:00:00'),
    (13, 'Teacher A', 'teach@a.test', '2026-01-01 00:00:00')");

// Test 1 & 2: Two schools registering computers independently & one school registering multiple computers
$schoolADevices = [];
for ($i = 1; $i <= 5; $i++) {
    $schoolADevices[] = school_staff_attendance_register_device($pdo, 1, 10, "School A PC {$i}", "Gate {$i}", $tz, false);
}
$schoolBDevices = [];
for ($i = 1; $i <= 3; $i++) {
    $schoolBDevices[] = school_staff_attendance_register_device($pdo, 2, 12, "School B PC {$i}", "Office {$i}", $tz, false);
}
$assertSame(5, count(school_staff_attendance_list_devices($pdo, 1)), 'School A has 5 independent devices');
$assertSame(3, count(school_staff_attendance_list_devices($pdo, 2)), 'School B has 3 independent devices');

// Test 3: Unauthorised computer attempting attendance is denied
$_COOKIE = [];
$_SESSION = [];
$assertSame(null, school_staff_attendance_kiosk_device($pdo, 1), 'Unauthorised PC denied for School A');
$assertSame([], school_staff_attendance_authorized_kiosk_devices($pdo), 'Unauthorised PC has no authorized schools');

// Test 4: Deactivate, reactivate, and permanent revocation
$devA1Id = (int) $schoolADevices[0]['device_id'];
$devA1Token = (string) $schoolADevices[0]['device_token'];
$_COOKIE = ['staff_attendance_kiosk_device_1' => $devA1Token];
$assertTrue(school_staff_attendance_kiosk_device($pdo, 1) !== null, 'Active device authorized');

school_staff_attendance_set_device_status($pdo, 1, $devA1Id, 10, 'inactive', $tz);
$assertSame(null, school_staff_attendance_kiosk_device($pdo, 1), 'Deactivated device blocked immediately');

school_staff_attendance_set_device_status($pdo, 1, $devA1Id, 10, 'active', $tz);
$assertTrue(school_staff_attendance_kiosk_device($pdo, 1) !== null, 'Reactivated device authorized again');

school_staff_attendance_set_device_status($pdo, 1, $devA1Id, 11, 'revoked', $tz);
$assertSame(null, school_staff_attendance_kiosk_device($pdo, 1), 'Revoked device blocked immediately');

// Test 8: Device belonging to School A cannot access School B
$_COOKIE = ['staff_attendance_kiosk_device_1' => (string) $schoolADevices[1]['device_token']];
$assertSame(null, school_staff_attendance_kiosk_device($pdo, 2), 'School A device denied on School B');

// Test 9: Access through another supported browser on an authorised physical PC (via Local Device Agent challenge-response)
$devA2 = $schoolADevices[1];
$agentUid = str_repeat('9', 64);
$agentSecret = str_repeat('f', 64);
$enrolled = school_staff_attendance_enroll_agent($pdo, 1, $devA2['enrollment_code'], $agentUid, $agentSecret);
$assertSame((int) $devA2['device_id'], (int) $enrolled['device_id'], 'Physical PC agent enrolled');

// Also verify another school (School B) can enroll the same device_uid independently without collision!
$devB1 = $schoolBDevices[0];
$enrolledB = school_staff_attendance_enroll_agent($pdo, 2, $devB1['enrollment_code'], $agentUid, str_repeat('e', 64));
$assertSame((int) $devB1['device_id'], (int) $enrolledB['device_id'], 'Same physical PC UID can enroll for School B independently');

// Simulate a completely different browser (empty $_COOKIE) proving physical-PC identity via HMAC challenge-response
$_COOKIE = [];
$nonce = bin2hex(random_bytes(16));
$signingKey = hash('sha256', $agentSecret);
$signature = hash_hmac('sha256', $nonce . '|1', $signingKey);
$verifiedDev = school_staff_attendance_verify_agent_challenge($pdo, 1, $agentUid, $nonce, $signature);
$assertTrue($verifiedDev !== null && (int) $verifiedDev['id'] === (int) $devA2['device_id'], 'Second browser verified via physical PC agent');

// Test 10: Device replacement + audit/history integrity
$replaced = school_staff_attendance_replace_device($pdo, 1, (int) $devA2['device_id'], 11, 'School A Replacement PC', 'Reception', $tz, false);
$assertTrue((int) $replaced['device_id'] > (int) $devA2['device_id'], 'Replacement device created');
$assertSame(null, school_staff_attendance_verify_agent_challenge($pdo, 1, $agentUid, $nonce, $signature), 'Replaced/revoked old physical PC immediately loses agent access');

$history = school_staff_attendance_device_history($pdo, 1, 50);
$events = array_column($history, 'event_type');
foreach (['device_registered', 'device_deactivated', 'device_activated', 'device_revoked', 'device_agent_enrolled', 'device_replaced'] as $expectedEv) {
    $assertTrue(in_array($expectedEv, $events, true), "Audit history contains {$expectedEv}");
}

fwrite(STDOUT, "All multi-PC attendance device integration checks passed.\n");
