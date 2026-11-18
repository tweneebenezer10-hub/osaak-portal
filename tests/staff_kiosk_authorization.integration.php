<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/functions.php';
require_once dirname(__DIR__) . '/app/school_staff_attendance.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This isolated authorization test requires the PDO SQLite driver.\n");
    exit(2);
}

$config = ['auth_pepper' => str_repeat('synthetic-test-pepper-', 3)];
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE schools (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        short_name TEXT NOT NULL,
        status TEXT NOT NULL,
        deleted_at TEXT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE school_staff_attendance_kiosk_devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER NOT NULL,
        device_name TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        deactivated_at TEXT NULL,
        UNIQUE (school_id, token_hash)
    )'
);

$same = static function (mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . '.');
    }
};

$schoolId = 71;
$activeToken = str_repeat('a', 64);
$secondDeviceToken = str_repeat('b', 64);
$pdo->prepare(
    'INSERT INTO schools (id, name, short_name, status, deleted_at)
     VALUES (?, ?, ?, "active", NULL)'
)->execute([$schoolId, 'Synthetic School', 'synthetic-school']);
$insertDevice = $pdo->prepare(
    'INSERT INTO school_staff_attendance_kiosk_devices (school_id, device_name, token_hash)
     VALUES (?, ?, ?)'
);
$insertDevice->execute([$schoolId, 'Synthetic authorised tablet', keyed_hash($activeToken)]);
$activeDeviceId = (int) $pdo->lastInsertId();
$insertDevice->execute([$schoolId, 'Synthetic authorised phone', keyed_hash($secondDeviceToken)]);
$same(2, (int) $pdo->query('SELECT COUNT(*) FROM school_staff_attendance_kiosk_devices')->fetchColumn(), 'multiple devices per school');

$previousCookies = $_COOKIE;
try {
    $_COOKIE = ['staff_attendance_kiosk_device_' . $schoolId => $activeToken];
    $authorized = school_staff_attendance_authorized_kiosk_devices($pdo);
    $same(1, count($authorized), 'active device authorization count');
    $same($activeDeviceId, (int) $authorized[0]['id'], 'authorized device identity');
    $same('synthetic-school', $authorized[0]['short_name'], 'authorized school scope');

    $_COOKIE = ['staff_attendance_kiosk_device_' . $schoolId => str_repeat('c', 64)];
    $same([], school_staff_attendance_authorized_kiosk_devices($pdo), 'unknown credential denied');

    $_COOKIE = ['staff_attendance_kiosk_device_' . $schoolId => $activeToken];
    $pdo->prepare(
        'UPDATE school_staff_attendance_kiosk_devices
         SET deactivated_at = "2026-11-16 12:00:00" WHERE id = ?'
    )->execute([$activeDeviceId]);
    $same([], school_staff_attendance_authorized_kiosk_devices($pdo), 'revocation blocks the next request');
    $same(null, school_staff_attendance_kiosk_device($pdo, $schoolId), 'revoked explicit school credential denied');

    $pdo->exec('UPDATE schools SET status = "suspended" WHERE id = ' . $schoolId);
    $_COOKIE = ['staff_attendance_kiosk_device_' . $schoolId => $secondDeviceToken];
    $same([], school_staff_attendance_authorized_kiosk_devices($pdo), 'inactive school kiosk denied');
} finally {
    $_COOKIE = $previousCookies;
}

fwrite(STDOUT, "Staff kiosk authorization integration checks passed.\n");
