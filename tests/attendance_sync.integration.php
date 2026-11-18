<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/school_attendance.php';

$dsn = getenv('ATTENDANCE_SYNC_TEST_DSN');
if (!is_string($dsn)
    || preg_match('/^mysql:host=(?:127\.0\.0\.1|localhost);port=3317;dbname=[^;]+$/', $dsn) !== 1) {
    fwrite(STDERR, "Set ATTENDANCE_SYNC_TEST_DSN to a disposable local MariaDB on port 3317.\n");
    exit(2);
}

$pdo = new PDO($dsn, getenv('ATTENDANCE_SYNC_TEST_USER') ?: 'root', getenv('ATTENDANCE_SYNC_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec(
    'CREATE TEMPORARY TABLE student_attendance (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        school_id BIGINT UNSIGNED NOT NULL,
        student_id BIGINT UNSIGNED NOT NULL,
        class_id BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NULL,
        updated_by BIGINT UNSIGNED NULL,
        attendance_date DATE NOT NULL,
        status ENUM("present", "absent", "late", "excused") NOT NULL,
        row_version INT UNSIGNED NOT NULL DEFAULT 1,
        last_sync_token CHAR(32) NULL,
        legacy_status ENUM("late", "excused") NULL,
        recorded_by BIGINT UNSIGNED NULL,
        recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_student_attendance_date (school_id, student_id, attendance_date)
    ) ENGINE=InnoDB'
);

$same = static function (mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . '.');
    }
};

$schoolId = 7;
$classId = 11;
$teacherId = 19;
$date = '2026-11-16';
$firstToken = str_repeat('a', 32);
$nextToken = str_repeat('b', 32);
$staleToken = str_repeat('c', 32);

$created = school_attendance_save_sync_batch(
    $pdo,
    $schoolId,
    $classId,
    $date,
    $teacherId,
    $firstToken,
    [['student_id' => 101, 'status' => 'present', 'expected_version' => 0]]
);
$same(1, $created['records'][0]['row_version'], 'initial row version');
$same([], $created['conflicts'], 'initial insert conflicts');

$retry = school_attendance_save_sync_batch(
    $pdo,
    $schoolId,
    $classId,
    $date,
    $teacherId,
    $firstToken,
    [['student_id' => 101, 'status' => 'present', 'expected_version' => 0]]
);
$same(1, $retry['records'][0]['row_version'], 'retry preserves row version');
$same(1, (int) $pdo->query('SELECT COUNT(*) FROM student_attendance')->fetchColumn(), 'retry does not duplicate rows');

$updated = school_attendance_save_sync_batch(
    $pdo,
    $schoolId,
    $classId,
    $date,
    $teacherId,
    $nextToken,
    [['student_id' => 101, 'status' => 'absent', 'expected_version' => 1]]
);
$same(2, $updated['records'][0]['row_version'], 'updated row version');

$conflict = school_attendance_save_sync_batch(
    $pdo,
    $schoolId,
    $classId,
    $date,
    $teacherId,
    $staleToken,
    [
        ['student_id' => 101, 'status' => 'late', 'expected_version' => 1],
        ['student_id' => 102, 'status' => 'present', 'expected_version' => 0],
    ]
);
$same(1, count($conflict['conflicts']), 'stale batch conflict count');
$same('absent', $conflict['conflicts'][0]['current_status'], 'conflict returns current server status');
$same(1, (int) $pdo->query('SELECT COUNT(*) FROM student_attendance')->fetchColumn(), 'conflicting batch is atomic');
$same('absent', (string) $pdo->query('SELECT status FROM student_attendance WHERE student_id = 101')->fetchColumn(), 'conflict preserves server value');

$completed = school_attendance_save_sync_batch(
    $pdo,
    $schoolId,
    $classId,
    $date,
    $teacherId,
    $staleToken,
    [
        ['student_id' => 101, 'status' => 'late', 'expected_version' => 2],
        ['student_id' => 102, 'status' => 'present', 'expected_version' => 0],
    ]
);
$same(3, $completed['records'][0]['row_version'], 'reviewed update version');
$same(1, $completed['records'][1]['row_version'], 'new batch row version');
$same(2, (int) $pdo->query('SELECT COUNT(*) FROM student_attendance')->fetchColumn(), 'reviewed batch writes both rows');

$pdo->exec(
    "INSERT INTO student_attendance
     (school_id, student_id, class_id, attendance_date, status, row_version)
     VALUES (8, 202, 22, '2026-11-16', 'absent', 4)"
);
$isolated = school_attendance_save_sync_batch(
    $pdo,
    $schoolId,
    $classId,
    $date,
    $teacherId,
    str_repeat('d', 32),
    [['student_id' => 202, 'status' => 'present', 'expected_version' => 0]]
);
$same([], $isolated['conflicts'], 'other-school row does not conflict');
$same(2, (int) $pdo->query('SELECT COUNT(*) FROM student_attendance WHERE student_id = 202')->fetchColumn(), 'school-scoped insert preserves other-school row');

fwrite(STDOUT, "Attendance synchronization integration checks passed.\n");
