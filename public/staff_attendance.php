<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    redirect('schools.php');
}
$schoolId = (int) $school['id'];
$userId = (int) $user['id'];
$role = school_user_role_for_school($userId, $schoolId) ?? '';
$managerRole = in_array($role, ['school_headmaster', 'school_proprietor'], true);
$supportContext = active_it_support_context($user);
$supportReadOnly = $supportContext !== null && $supportContext['access_mode'] === 'read_only';
$supportCanWrite = $supportContext !== null
    && it_support_write_allowed($supportContext, 'staff_attendance.php')
    && in_array((string) $supportContext['preview_role'], ['school_headmaster', 'school_proprietor'], true);
if (!$managerRole || ($supportContext !== null && !$supportCanWrite && !$supportReadOnly)) {
    audit($userId, 'school_staff_attendance_access_denied', 'school_id=' . $schoolId . ';role=' . $role);
    http_response_code(403);
    render_header('Staff attendance access denied');
    echo '<section class="panel"><h1>Staff attendance access denied</h1><p>Staff attendance management is available to authorized Headmasters and Proprietors in this school.</p></section>';
    render_footer();
    exit;
}
$attendanceSchemaStmt = db()->query(
    'SELECT table_name, column_name, column_type FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name IN (
           "school_staff_attendance", "school_staff_attendance_schedules",
           "school_staff_attendance_credentials", "school_staff_attendance_kiosk_limits",
           "school_staff_attendance_audit",            "school_staff_attendance_disputes", "school_staff_attendance_pin_resets",
           "school_staff_attendance_kiosk_devices", "school_staff_attendance_kiosk_reopenings",
           "school_staff_attendance_security_events",
           "school_staff_attendance_makeup_obligations", "school_staff_attendance_makeup_credits",
           "school_payroll_entries"
       )'
);
$attendanceSchema = [];
$attendanceColumnTypes = [];
foreach ($attendanceSchemaStmt->fetchAll() as $schemaColumn) {
    $attendanceSchema[(string) $schemaColumn['table_name']][(string) $schemaColumn['column_name']] = true;
    $attendanceColumnTypes[(string) $schemaColumn['table_name']][(string) $schemaColumn['column_name']]
        = (string) $schemaColumn['column_type'];
}
$migrationRequirements = [
    '095' => [
        'tables' => [
            'school_staff_attendance_schedules', 'school_staff_attendance_credentials',
            'school_staff_attendance_kiosk_limits', 'school_staff_attendance_audit',
            'school_staff_attendance_disputes',
        ],
        'columns' => [
            'school_staff_attendance' => [
                'punctuality_status', 'minutes_late', 'verification_method', 'departure_at',
                'working_minutes', 'early_departure', 'departure_verification_method',
            ],
        ],
    ],
    '096' => [
        'tables' => ['school_staff_attendance_pin_resets'],
        'columns' => [],
    ],
    '097' => [
        'tables' => [
            'school_staff_attendance_kiosk_devices', 'school_staff_attendance_kiosk_reopenings',
            'school_staff_attendance_security_events',
        ],
        'columns' => [
            'school_staff_attendance_schedules' => [
                'morning_open_time', 'morning_close_time', 'afternoon_open_time',
                'afternoon_close_time', 'failed_pin_attempt_limit', 'lockout_minutes',
            ],
        ],
    ],
    '098' => [
        'tables' => [
            'school_staff_attendance_makeup_obligations',
            'school_staff_attendance_makeup_credits',
        ],
        'columns' => [
            'school_payroll_entries' => [
                'attendance_required_days', 'attendance_elapsed_days',
                'attendance_completed_days', 'attendance_excused_days',
                'attendance_makeup_days', 'attendance_pending_days',
                'attendance_outstanding_days', 'attendance_eligible',
            ],
        ],
    ],
];
$missingAttendanceMigrations = [];
foreach ($migrationRequirements as $migrationNumber => $requirements) {
    foreach ($requirements['tables'] as $requiredTable) {
        if (!isset($attendanceSchema[$requiredTable])) {
            $missingAttendanceMigrations[] = $migrationNumber;
            break;
        }
    }
    if (in_array($migrationNumber, $missingAttendanceMigrations, true)) {
        continue;
    }
    foreach ($requirements['columns'] as $requiredTable => $requiredColumns) {
        foreach ($requiredColumns as $requiredColumn) {
            if (!isset($attendanceSchema[$requiredTable][$requiredColumn])) {
                $missingAttendanceMigrations[] = $migrationNumber;
                break 2;
            }
        }
    }
}
foreach ([
    'school_staff_attendance' => ['verification_method' => 'system_generated'],
    'school_staff_attendance_audit' => [
        'verification_method' => 'system_generated',
        'change_type' => 'system_absence_recorded',
    ],
] as $tableName => $enumValues) {
    foreach ($enumValues as $columnName => $requiredValue) {
        if (!isset($attendanceColumnTypes[$tableName][$columnName])
            || stripos($attendanceColumnTypes[$tableName][$columnName], $requiredValue) === false) {
            $missingAttendanceMigrations[] = '100';
            break 2;
        }
    }
}
if ($missingAttendanceMigrations !== []) {
    $missingAttendanceMigrations = array_values(array_unique($missingAttendanceMigrations));
    http_response_code(503);
    render_header('Staff attendance update required');
    echo '<section class="panel"><h1>Staff attendance update required</h1><p>The attendance database is missing migration '
        . e(implode(', ', $missingAttendanceMigrations))
        . '. No attendance setup controls can be shown until a technical administrator applies only the missing migration files in numeric order, after backing up the database.</p><p>Existing attendance records are preserved. Reload this page after the update is complete.</p></section>';
    render_footer();
    exit;
}
$readOnly = $supportReadOnly || ($supportContext !== null && !$supportCanWrite);
if ($supportContext !== null) {
    audit($userId, 'school_staff_attendance_support_accessed', 'school_id=' . $schoolId . ';preview_role=' . $supportContext['preview_role']);
}

$rosterStmt = db()->prepare(
    'SELECT staff.id, staff.employee_number, staff.role_name, staff.created_at, account.full_name
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id AND account.status = "active"
       AND account.email_verified_at IS NOT NULL
     WHERE staff.school_id = ? AND staff.status = "active"
       AND staff.role_name NOT IN ("school_parent", "school_student")
       AND EXISTS (
           SELECT 1 FROM school_memberships membership
           WHERE membership.school_id = staff.school_id
             AND membership.user_id = staff.user_id
             AND membership.status = "active"
       )
     ORDER BY account.full_name, staff.id'
);
$rosterStmt->execute([$schoolId]);
$staff = $rosterStmt->fetchAll();
$staffById = [];
$staffRoles = [];
foreach ($staff as $member) {
    $staffById[(int) $member['id']] = $member;
    $staffRoles[(string) $member['role_name']] = true;
}
$staffRoles = array_keys($staffRoles);
sort($staffRoles, SORT_NATURAL | SORT_FLAG_CASE);
$attendanceTimezone = school_staff_attendance_timezone(db(), $schoolId);
$attendanceTimezoneName = $attendanceTimezone->getName();
$configuredStmt = db()->prepare(
    'SELECT setting_value FROM school_settings
     WHERE school_id = ? AND setting_key = "staff_attendance_kiosk_configured" LIMIT 1'
);
$configuredStmt->execute([$schoolId]);
$kioskConfigured = $configuredStmt->fetchColumn() === '1';
if ($kioskConfigured && $staffRoles !== []) {
    $rolePlaceholders = implode(',', array_fill(0, count($staffRoles), '?'));
    $configuredRoleCount = db()->prepare(
        'SELECT COUNT(*) FROM school_staff_attendance_schedules
         WHERE school_id = ? AND role_name IN (' . $rolePlaceholders . ')'
    );
    $configuredRoleCount->execute(array_merge([$schoolId], $staffRoles));
    $kioskConfigured = (int) $configuredRoleCount->fetchColumn() === count($staffRoles);
}

$validDate = static function (mixed $value, string $fallback): string {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return $fallback;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        ? $value
        : $fallback;
};
$today = (new DateTimeImmutable('now', $attendanceTimezone))->format('Y-m-d');
$selectedDate = $validDate($_GET['date'] ?? $today, $today);
$filterStaffId = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT);
$filterStaffId = $filterStaffId !== false && isset($staffById[$filterStaffId]) ? (int) $filterStaffId : 0;
$filterRole = (string) ($_GET['staff_group'] ?? '');
if (!in_array($filterRole, $staffRoles, true)) {
    $filterRole = '';
}
$filterStatus = (string) ($_GET['status'] ?? '');
$validStatuses = ['not_checked_in', 'early', 'punctual', 'late', 'very_late', 'absent', 'excused', 'checked_out'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}
$managementActions = [
    'reset_pin' => 'Reset attendance PIN',
    'verified_arrival' => 'Record verified arrival now',
    'attendance' => 'Record or correct attendance',
];
$managementActionDescriptions = [
    'reset_pin' => 'Choose a staff member and issue a one-time PIN setup code.',
    'verified_arrival' => 'Verify a staff member who is physically present and record the current server time.',
    'attendance' => 'Create a daily record or correct an existing entry with an audit reason.',
];
$managementAction = (string) ($_GET['manage_action'] ?? '');
if (!isset($managementActions[$managementAction])) {
    $managementAction = '';
}
$managementStaffId = filter_var($_GET['manage_staff_id'] ?? null, FILTER_VALIDATE_INT);
$managementStaffId = $managementStaffId !== false && isset($staffById[$managementStaffId])
    ? (int) $managementStaffId
    : 0;
$managementStaff = $staffById[$managementStaffId] ?? null;

$redirectAfterPost = static function (): never {
    $query = http_build_query($_GET);
    $returnView = (string) ($_POST['return_view'] ?? '');
    $returnFragment = in_array($returnView, [
        'kiosk-setup',
        'makeup-obligations',
        'reporting-schedules',
        'attendance-disputes',
    ], true) ? '#' . rawurlencode($returnView) : '';
    redirect('staff_attendance.php' . ($query !== '' ? '?' . $query : '') . $returnFragment);
};
$formatDateTime = static function (?string $value) use ($attendanceTimezone): string {
    if (!$value) {
        return '—';
    }
    return (new DateTimeImmutable($value, $attendanceTimezone))->format('j M Y, g:i A');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($readOnly) {
        http_response_code(403);
        exit('This attendance view is read-only.');
    }
    $pdo = db();
    $action = (string) ($_POST['action'] ?? '');
    $returnToKiosk = false;
    try {
        if ($action === 'initiate_pin_reset') {
            $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($staffId === false || !isset($staffById[$staffId])
                || (string) ($_POST['identity_verified'] ?? '') !== '1'
                || $reason === '' || mb_strlen($reason) > 255) {
                throw new RuntimeException('Select a staff member, confirm in-person identity verification, and enter a reason of up to 255 characters.');
            }
            if (trim((string) $staffById[(int) $staffId]['employee_number']) === '') {
                throw new RuntimeException('Assign this staff member a Staff ID before initiating PIN recovery.');
            }

            $resetCode = (string) random_int(10000000, 99999999);
            $pdo->beginTransaction();
            $lockStaff = $pdo->prepare(
                'SELECT id FROM school_staff
                 WHERE id = ? AND school_id = ? AND status = "active"
                 FOR UPDATE'
            );
            $lockStaff->execute([(int) $staffId, $schoolId]);
            if (!$lockStaff->fetchColumn()) {
                throw new RuntimeException('The selected staff record is no longer active in this school.');
            }
            $staffIdCount = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM school_staff staff
                 WHERE staff.school_id = ? AND staff.status = "active"
                   AND staff.employee_number IS NOT NULL
                   AND LOWER(TRIM(staff.employee_number)) = LOWER(TRIM(?))
                   AND staff.role_name NOT IN ("school_parent", "school_student")
                   AND EXISTS (
                       SELECT 1 FROM school_memberships membership
                       WHERE membership.school_id = staff.school_id
                         AND membership.user_id = staff.user_id
                         AND membership.status = "active"
                         AND membership.role_name NOT IN ("school_parent", "school_student")
                   )'
            );
            $staffIdCount->execute([$schoolId, (string) $staffById[(int) $staffId]['employee_number']]);
            if ((int) $staffIdCount->fetchColumn() !== 1) {
                throw new RuntimeException('This Staff ID is not unique. Correct the staff records before initiating PIN recovery.');
            }
            $invalidPinHash = make_password_hash(bin2hex(random_bytes(32)));
            $pdo->prepare(
                'INSERT INTO school_staff_attendance_credentials
                    (school_id, staff_id, pin_hash, failed_attempts, locked_until, last_failed_at, pin_changed_at)
                 VALUES (?, ?, ?, 0, NULL, NULL, NOW())
                 ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), failed_attempts = 0,
                    locked_until = NULL, last_failed_at = NULL, pin_changed_at = NOW()'
            )->execute([(int) $schoolId, (int) $staffId, $invalidPinHash]);
            $pdo->prepare(
                'UPDATE school_staff_attendance_pin_resets
                 SET revoked_at = NOW()
                 WHERE school_id = ? AND staff_id = ?
                   AND completed_at IS NULL AND revoked_at IS NULL'
            )->execute([$schoolId, (int) $staffId]);
            $expiresAt = (new DateTimeImmutable('now', $attendanceTimezone))->modify('+15 minutes')->format('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO school_staff_attendance_pin_resets
                    (school_id, staff_id, token_hash, initiated_by, reason, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $schoolId,
                (int) $staffId,
                keyed_hash($resetCode),
                $userId,
                $reason,
                $expiresAt,
            ]);
            $resetId = (int) $pdo->lastInsertId();
            audit(
                $userId,
                'school_staff_attendance_pin_reset_initiated',
                'school_id=' . $schoolId . ';staff_id=' . (int) $staffId
                    . ';reset_id=' . $resetId . ';reason=' . $reason
            );
            $pdo->commit();
            $_SESSION['staff_attendance_pin_reset_notice'] = [
                'staff_id' => (string) ($staffById[(int) $staffId]['employee_number'] ?: 'Not assigned'),
                'staff_name' => (string) $staffById[(int) $staffId]['full_name'],
                'code' => $resetCode,
                'expires_at' => $expiresAt,
            ];
            flash('success', 'PIN reset initiated. The previous PIN is invalid immediately.');
        } elseif ($action === 'record_verified_arrival_now') {
            $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($staffId === false || !isset($staffById[$staffId])
                || (string) ($_POST['physically_verified'] ?? '') !== '1'
                || $reason === '' || mb_strlen($reason) > 255) {
                throw new RuntimeException('Confirm that you verified the staff member is physically present and provide a reason of up to 255 characters.');
            }
            $arrival = new DateTimeImmutable('now', $attendanceTimezone);
            $attendanceDate = $arrival->format('Y-m-d');
            $schedule = school_staff_attendance_schedule($pdo, $schoolId, (string) $staffById[$staffId]['role_name']);
            if (!school_staff_attendance_is_working_day($pdo, $schoolId, $attendanceDate, $schedule)) {
                throw new RuntimeException('Staff attendance is not scheduled for today. No record was created.');
            }
            $punctuality = school_staff_attendance_classify_arrival($arrival, $attendanceDate, $schedule);

            $pdo->beginTransaction();
            $existing = $pdo->prepare(
                'SELECT id, arrival_at FROM school_staff_attendance
                 WHERE school_id = ? AND staff_id = ? AND attendance_date = ?
                 FOR UPDATE'
            );
            $existing->execute([$schoolId, (int) $staffId, $attendanceDate]);
            $existingRecord = $existing->fetch();
            if ($existingRecord) {
                school_staff_attendance_security_event(
                    $pdo,
                    $schoolId,
                    'duplicate_administrator_check_in',
                    null,
                    (int) $staffId,
                    $userId,
                    null,
                    $reason,
                    $arrival->format('Y-m-d H:i:s')
                );
                $pdo->commit();
                $recordedArrival = $existingRecord['arrival_at'] !== null
                    ? (new DateTimeImmutable((string) $existingRecord['arrival_at'], $attendanceTimezone))->format('g:i A')
                    : null;
                throw new RuntimeException($recordedArrival !== null
                    ? 'Arrival was already recorded today at ' . $recordedArrival . '. No second check-in was created.'
                    : 'An attendance record already exists for today. Use the correction workflow if it needs review.');
            }
            $arrivalAt = $arrival->format('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO school_staff_attendance
                    (school_id, staff_id, attendance_date, arrival_at, expected_reporting_time,
                     status, punctuality_status, minutes_late, verification_method, remarks,
                     recorded_by, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "administrator_recorded", ?, ?, ?)'
            )->execute([
                $schoolId,
                (int) $staffId,
                $attendanceDate,
                $arrivalAt,
                $schedule['reporting_time'],
                $punctuality['legacy_status'],
                $punctuality['status'],
                $punctuality['minutes_late'],
                $reason,
                $userId,
                $arrivalAt,
            ]);
            $attendanceId = (int) $pdo->lastInsertId();
            $record = [
                'attendance_date' => $attendanceDate,
                'arrival_at' => $arrivalAt,
                'departure_at' => null,
                'status' => $punctuality['legacy_status'],
                'punctuality_status' => $punctuality['status'],
                'minutes_late' => $punctuality['minutes_late'],
                'working_minutes' => null,
                'early_departure' => false,
                'verification_method' => 'administrator_recorded',
                'departure_verification_method' => null,
                'expected_reporting_time' => $schedule['reporting_time'],
                'remarks' => $reason,
            ];
            school_staff_attendance_write_audit(
                $pdo,
                $schoolId,
                $attendanceId,
                (int) $staffId,
                $userId,
                'administrator_recorded',
                null,
                school_staff_attendance_snapshot($record),
                $reason,
                'administrator_recorded'
            );
            school_staff_attendance_security_event(
                $pdo,
                $schoolId,
                'administrator_recorded_arrival',
                null,
                (int) $staffId,
                $userId,
                null,
                $reason,
                $arrivalAt
            );
            audit(
                $userId,
                'school_staff_attendance_physically_verified_check_in',
                'school_id=' . $schoolId . ';staff_id=' . (int) $staffId
                    . ';attendance_id=' . $attendanceId . ';reason=' . $reason
            );
            $pdo->commit();
            flash('success', 'Server-time check-in recorded as ADMINISTRATOR RECORDED.');
        } elseif ($action === 'save_schedule') {
            $scheduleRole = (string) ($_POST['role_name'] ?? '');
            $reportingTime = trim((string) ($_POST['reporting_time'] ?? ''));
            $morningOpenTime = trim((string) ($_POST['morning_open_time'] ?? ''));
            $morningCloseTime = trim((string) ($_POST['morning_close_time'] ?? ''));
            $afternoonOpenTime = trim((string) ($_POST['afternoon_open_time'] ?? ''));
            $afternoonCloseTime = trim((string) ($_POST['afternoon_close_time'] ?? ''));
            $closingTime = trim((string) ($_POST['closing_time'] ?? ''));
            $grace = filter_var($_POST['grace_minutes'] ?? null, FILTER_VALIDATE_INT);
            $lateThreshold = filter_var($_POST['very_late_after_minutes'] ?? null, FILTER_VALIDATE_INT);
            $earlyDepartureThreshold = filter_var($_POST['early_departure_threshold_minutes'] ?? null, FILTER_VALIDATE_INT);
            $failedPinLimit = filter_var($_POST['failed_pin_attempt_limit'] ?? null, FILTER_VALIDATE_INT);
            $lockoutMinutes = filter_var($_POST['lockout_minutes'] ?? null, FILTER_VALIDATE_INT);
            $weekdays = $_POST['working_weekdays'] ?? [];
            $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';
            if (!in_array($scheduleRole, $staffRoles, true)
                || preg_match($timePattern, $reportingTime) !== 1
                || preg_match($timePattern, $morningOpenTime) !== 1
                || preg_match($timePattern, $morningCloseTime) !== 1
                || preg_match($timePattern, $afternoonOpenTime) !== 1
                || preg_match($timePattern, $afternoonCloseTime) !== 1
                || $morningOpenTime > $morningCloseTime
                || $afternoonOpenTime > $afternoonCloseTime
                || preg_match($timePattern, $closingTime) !== 1
                || $grace === false || $grace < 0 || $grace > 180
                || $lateThreshold === false || $lateThreshold < 1 || $lateThreshold > 360
                || $earlyDepartureThreshold === false || $earlyDepartureThreshold < 0 || $earlyDepartureThreshold > 360
                || $failedPinLimit === false || $failedPinLimit < 3 || $failedPinLimit > 10
                || $lockoutMinutes === false || $lockoutMinutes < 1 || $lockoutMinutes > 120
                || !is_array($weekdays)) {
                throw new RuntimeException('Enter valid attendance schedule values for an active staff category.');
            }
            $weekdays = array_values(array_unique(array_filter(
                array_map('intval', $weekdays),
                static fn (int $day): bool => $day >= 1 && $day <= 7
            )));
            if ($weekdays === []) {
                throw new RuntimeException('Select at least one working day.');
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO school_staff_attendance_schedules
                    (school_id, role_name, reporting_time, morning_open_time, morning_close_time,
                     afternoon_open_time, afternoon_close_time, grace_minutes, very_late_after_minutes,
                     closing_time, early_departure_threshold_minutes, working_weekdays,
                     clock_out_enabled, failed_pin_attempt_limit, lockout_minutes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reporting_time = VALUES(reporting_time),
                    morning_open_time = VALUES(morning_open_time),
                    morning_close_time = VALUES(morning_close_time),
                    afternoon_open_time = VALUES(afternoon_open_time),
                    afternoon_close_time = VALUES(afternoon_close_time),
                    grace_minutes = VALUES(grace_minutes),
                    very_late_after_minutes = VALUES(very_late_after_minutes),
                    closing_time = VALUES(closing_time),
                    early_departure_threshold_minutes = VALUES(early_departure_threshold_minutes),
                    working_weekdays = VALUES(working_weekdays),
                    clock_out_enabled = VALUES(clock_out_enabled),
                    failed_pin_attempt_limit = VALUES(failed_pin_attempt_limit),
                    lockout_minutes = VALUES(lockout_minutes), updated_by = VALUES(updated_by)'
            )->execute([
                $schoolId,
                $scheduleRole,
                $reportingTime,
                $morningOpenTime,
                $morningCloseTime,
                $afternoonOpenTime,
                $afternoonCloseTime,
                $grace,
                $lateThreshold,
                $closingTime,
                $earlyDepartureThreshold,
                json_encode($weekdays, JSON_THROW_ON_ERROR),
                isset($_POST['clock_out_enabled']) ? 1 : 0,
                $failedPinLimit,
                $lockoutMinutes,
                $userId,
            ]);
            $allStaffRolesConfigured = false;
            if ($staffRoles !== []) {
                $rolePlaceholders = implode(',', array_fill(0, count($staffRoles), '?'));
                $configuredRoleCount = $pdo->prepare(
                    'SELECT COUNT(*) FROM school_staff_attendance_schedules
                     WHERE school_id = ? AND role_name IN (' . $rolePlaceholders . ')'
                );
                $configuredRoleCount->execute(array_merge([$schoolId], $staffRoles));
                $allStaffRolesConfigured = (int) $configuredRoleCount->fetchColumn() === count($staffRoles);
            }
            $pdo->prepare(
                'INSERT INTO school_settings (school_id, setting_key, setting_value, updated_by)
                 VALUES (?, "staff_attendance_kiosk_configured", ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
            )->execute([$schoolId, $allStaffRolesConfigured ? '1' : '0', $userId]);
            audit($userId, 'school_staff_attendance_schedule_changed', 'school_id=' . $schoolId . ';role=' . $scheduleRole);
            $pdo->commit();
            flash('success', 'Staff attendance schedule saved.');
        } elseif ($action === 'save_attendance_timezone') {
            $timezoneName = trim((string) ($_POST['timezone'] ?? ''));
            if (!in_array($timezoneName, DateTimeZone::listIdentifiers(), true)) {
                throw new RuntimeException('Choose a valid school timezone.');
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO school_settings (school_id, setting_key, setting_value, updated_by)
                 VALUES (?, "staff_attendance_timezone", ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
            )->execute([$schoolId, $timezoneName, $userId]);
            audit($userId, 'school_staff_attendance_timezone_changed', 'school_id=' . $schoolId . ';timezone=' . $timezoneName);
            $pdo->commit();
            flash('success', 'School attendance timezone saved. Attendance timestamps and schedule classifications use this timezone.');
        } elseif ($action === 'register_kiosk_device') {
            $deviceName = trim((string) ($_POST['device_name'] ?? ''));
            if (!$kioskConfigured) {
                throw new RuntimeException('Save a schedule for every active staff category before registering an attendance kiosk.');
            }
            if ($deviceName === '' || mb_strlen($deviceName) > 100) {
                throw new RuntimeException('Enter a device name of up to 100 characters.');
            }
            $deviceToken = bin2hex(random_bytes(32));
            $deviceRegisteredAt = (new DateTimeImmutable('now', $attendanceTimezone))->format('Y-m-d H:i:s');
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO school_staff_attendance_kiosk_devices
                    (school_id, device_name, token_hash, registered_by, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$schoolId, $deviceName, keyed_hash($deviceToken), $userId, $deviceRegisteredAt]);
            $deviceId = (int) $pdo->lastInsertId();
            $cookieOptions = [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https',
                'httponly' => true,
                'samesite' => 'Strict',
            ];
            if (!setcookie('staff_attendance_kiosk_device_' . $schoolId, $deviceToken, $cookieOptions)) {
                throw new RuntimeException('The device token could not be installed in this browser. No device was registered.');
            }
            school_staff_attendance_security_event(
                $pdo,
                $schoolId,
                'device_registered',
                $deviceId,
                null,
                $userId,
                null,
                $deviceName,
                $deviceRegisteredAt
            );
            audit($userId, 'school_staff_attendance_kiosk_device_registered', 'school_id=' . $schoolId . ';device_id=' . $deviceId);
            $pdo->commit();
            flash('success', 'This device is now authorised for staff attendance. Keep its browser profile under school control.');
            $returnToKiosk = true;
        } elseif ($action === 'rename_kiosk_device') {
            $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
            $deviceName = trim((string) ($_POST['device_name'] ?? ''));
            if ($deviceId === false || $deviceId < 1 || $deviceName === '' || mb_strlen($deviceName) > 100) {
                throw new RuntimeException('Enter a device name of up to 100 characters.');
            }
            $renamedAt = (new DateTimeImmutable('now', $attendanceTimezone))->format('Y-m-d H:i:s');
            $pdo->beginTransaction();
            $deviceToRename = $pdo->prepare(
                'SELECT device_name FROM school_staff_attendance_kiosk_devices
                 WHERE id = ? AND school_id = ? AND deactivated_at IS NULL FOR UPDATE'
            );
            $deviceToRename->execute([(int) $deviceId, $schoolId]);
            $currentDeviceName = $deviceToRename->fetchColumn();
            if ($currentDeviceName === false) {
                throw new RuntimeException('The kiosk device is not active or does not belong to this school.');
            }
            if ((string) $currentDeviceName !== $deviceName) {
                $pdo->prepare(
                    'UPDATE school_staff_attendance_kiosk_devices
                     SET device_name = ? WHERE id = ? AND school_id = ? AND deactivated_at IS NULL'
                )->execute([$deviceName, (int) $deviceId, $schoolId]);
                school_staff_attendance_security_event(
                    $pdo,
                    $schoolId,
                    'device_renamed',
                    (int) $deviceId,
                    null,
                    $userId,
                    null,
                    $deviceName,
                    $renamedAt
                );
                audit($userId, 'school_staff_attendance_kiosk_device_renamed', 'school_id=' . $schoolId . ';device_id=' . (int) $deviceId);
            }
            $pdo->commit();
            flash('success', 'The attendance device name was saved.');
        } elseif ($action === 'deactivate_kiosk_device') {
            $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
            if ($deviceId === false || $deviceId < 1) {
                throw new RuntimeException('Select a valid registered kiosk device.');
            }
            $currentDeviceForRequest = school_staff_attendance_kiosk_device($pdo, $schoolId);
            $deviceDeactivatedAt = (new DateTimeImmutable('now', $attendanceTimezone))->format('Y-m-d H:i:s');
            $pdo->beginTransaction();
            $deactivate = $pdo->prepare(
                'UPDATE school_staff_attendance_kiosk_devices
                 SET deactivated_by = ?, deactivated_at = ?
                 WHERE id = ? AND school_id = ? AND deactivated_at IS NULL'
            );
            $deactivate->execute([$userId, $deviceDeactivatedAt, (int) $deviceId, $schoolId]);
            if ($deactivate->rowCount() !== 1) {
                throw new RuntimeException('The kiosk device was already deactivated or does not belong to this school.');
            }
            school_staff_attendance_security_event(
                $pdo,
                $schoolId,
                'device_deactivated',
                (int) $deviceId,
                null,
                $userId,
                null,
                'Deactivated by administrator',
                $deviceDeactivatedAt
            );
            audit($userId, 'school_staff_attendance_kiosk_device_deactivated', 'school_id=' . $schoolId . ';device_id=' . (int) $deviceId);
            $pdo->commit();
            if ($currentDeviceForRequest !== null && (int) $currentDeviceForRequest['id'] === (int) $deviceId) {
                setcookie('staff_attendance_kiosk_device_' . $schoolId, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https',
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            }
            flash('success', 'The kiosk device access has been revoked.');
        } elseif ($action === 'reopen_kiosk_window') {
            throw new RuntimeException('Kiosk check-ins are limited to 5:30 AM until before 2:00 PM. Window reopenings are no longer available.');
        } elseif (in_array($action, ['manual_record', 'correct_record'], true)) {
            $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
            $date = $validDate($_POST['attendance_date'] ?? '', '');
            $status = (string) ($_POST['attendance_status'] ?? '');
            $arrivalTime = trim((string) ($_POST['arrival_time'] ?? ''));
            $departureTime = trim((string) ($_POST['departure_time'] ?? ''));
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($staffId === false || !isset($staffById[$staffId])
                || $date === '' || $date > $today
                || !in_array($status, ['present', 'absent', 'excused'], true)
                || $reason === '' || mb_strlen($reason) > 255) {
                throw new RuntimeException('Choose an active staff member, a valid past or current date, a status, and a reason of up to 255 characters.');
            }
            if ($status === 'present' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $arrivalTime) !== 1) {
                throw new RuntimeException('Enter an arrival time for Present attendance.');
            }
            if ($status !== 'present') {
                $arrivalTime = '';
                $departureTime = '';
            }
            if ($departureTime !== '' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $departureTime) !== 1) {
                throw new RuntimeException('Enter a valid departure time.');
            }
            $schedule = school_staff_attendance_schedule($pdo, $schoolId, (string) $staffById[$staffId]['role_name']);
            if ($status === 'absent') {
                if (!school_staff_attendance_is_working_day($pdo, $schoolId, $date, $schedule)) {
                    throw new RuntimeException('An absent record cannot be created for a non-working day or school holiday.');
                }
                if ($date === $today && !school_staff_attendance_day_is_elapsed(
                    $date,
                    new DateTimeImmutable('now', $attendanceTimezone),
                    $schedule['closing_time']
                )) {
                    throw new RuntimeException('An absence can only be recorded after the scheduled closing time and 4:00 PM absence cutoff.');
                }
            }
            $arrival = $arrivalTime !== ''
                ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $arrivalTime, $attendanceTimezone)
                : null;
            $departure = $departureTime !== ''
                ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $departureTime, $attendanceTimezone)
                : null;
            if ($departure !== null && (!$arrival || $departure <= $arrival)) {
                throw new RuntimeException('Departure must be later than arrival.');
            }
            $departureAtInput = $departure?->format('Y-m-d H:i:s');
            $workingMinutesInput = $departure && $arrival
                ? min(65535, (int) floor(($departure->getTimestamp() - $arrival->getTimestamp()) / 60))
                : null;
            $closingAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $schedule['closing_time'], $attendanceTimezone);
            if (!$closingAt) {
                throw new RuntimeException('The staff closing-time schedule is invalid.');
            }
            $earlyDepartureInput = $departure !== null
                && $departure < $closingAt->modify('-' . (int) $schedule['early_departure_threshold_minutes'] . ' minutes');
            $punctuality = $arrival ? school_staff_attendance_classify_arrival($arrival, $date, $schedule) : null;
            $punctualityStatus = $punctuality['status'] ?? null;
            $legacyStatus = $status === 'present' ? (string) $punctuality['legacy_status'] : $status;
            $minutesLate = (int) ($punctuality['minutes_late'] ?? 0);

            $pdo->beginTransaction();
            if ($action === 'manual_record') {
                $existing = $pdo->prepare(
                    'SELECT id FROM school_staff_attendance
                     WHERE school_id = ? AND staff_id = ? AND attendance_date = ? FOR UPDATE'
                );
                $existing->execute([$schoolId, (int) $staffId, $date]);
                if ($existing->fetchColumn()) {
                    throw new RuntimeException('An attendance record already exists for this staff member and date. Use the correction form so the original is preserved.');
                }
                $arrivalAt = $arrival ? $arrival->format('Y-m-d H:i:s') : null;
                $insert = $pdo->prepare(
                    'INSERT INTO school_staff_attendance
                        (school_id, staff_id, attendance_date, arrival_at, departure_at, working_minutes,
                         early_departure, departure_verification_method, expected_reporting_time,
                         status, punctuality_status, minutes_late, verification_method, remarks,
                         recorded_by, recorded_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "administrator_recorded", ?, ?, NOW())'
                );
                $insert->execute([
                    $schoolId, (int) $staffId, $date, $arrivalAt, $departureAtInput,
                    $workingMinutesInput, $earlyDepartureInput ? 1 : 0,
                    $departure ? 'administrator_recorded' : null,
                    $schedule['reporting_time'], $legacyStatus, $punctualityStatus,
                    $minutesLate, $reason, $userId,
                ]);
                $attendanceId = (int) $pdo->lastInsertId();
                $newRecord = [
                    'attendance_date' => $date,
                    'arrival_at' => $arrivalAt,
                    'departure_at' => $departureAtInput,
                    'status' => $legacyStatus,
                    'punctuality_status' => $punctualityStatus,
                    'minutes_late' => $minutesLate,
                    'working_minutes' => $workingMinutesInput,
                    'early_departure' => $earlyDepartureInput,
                    'verification_method' => 'administrator_recorded',
                    'departure_verification_method' => $departure ? 'administrator_recorded' : null,
                    'expected_reporting_time' => $schedule['reporting_time'],
                    'remarks' => $reason,
                ];
                school_staff_attendance_write_audit(
                    $pdo, $schoolId, $attendanceId, (int) $staffId, $userId,
                    $status === 'excused' ? 'excuse_approved' : 'administrator_recorded',
                    null, school_staff_attendance_snapshot($newRecord), $reason, 'administrator_recorded'
                );
                school_staff_attendance_security_event(
                    $pdo,
                    $schoolId,
                    'administrator_attendance_recorded',
                    null,
                    (int) $staffId,
                    $userId,
                    null,
                    $reason,
                    (new DateTimeImmutable('now', $attendanceTimezone))->format('Y-m-d H:i:s')
                );
                audit($userId, 'school_staff_attendance_administrator_recorded', 'school_id=' . $schoolId . ';staff_id=' . (int) $staffId . ';attendance_id=' . $attendanceId);
                $pdo->commit();
                flash('success', 'Attendance saved and labelled ADMINISTRATOR RECORDED.');
            } else {
                $attendanceId = filter_var($_POST['attendance_id'] ?? null, FILTER_VALIDATE_INT);
                if ($attendanceId === false || $attendanceId < 1) {
                    throw new RuntimeException('Select a valid attendance record to correct.');
                }
                $recordStmt = $pdo->prepare(
                    'SELECT * FROM school_staff_attendance
                     WHERE id = ? AND school_id = ? AND staff_id = ? FOR UPDATE'
                );
                $recordStmt->execute([(int) $attendanceId, $schoolId, (int) $staffId]);
                $oldRecord = $recordStmt->fetch();
                if (!$oldRecord) {
                    throw new RuntimeException('The attendance record could not be found in this school.');
                }
                $arrivalAt = $arrival ? $arrival->format('Y-m-d H:i:s') : null;
                $departureAt = array_key_exists('departure_time', $_POST)
                    ? $departureAtInput
                    : $oldRecord['departure_at'];
                $workingMinutes = $oldRecord['working_minutes'];
                $earlyDeparture = (int) $oldRecord['early_departure'];
                $departureVerification = array_key_exists('departure_time', $_POST)
                    ? ($departureAt === null ? null : 'administrator_recorded')
                    : $oldRecord['departure_verification_method'];
                if ($arrivalAt === null) {
                    $departureAt = null;
                    $workingMinutes = null;
                    $earlyDeparture = 0;
                    $departureVerification = null;
                } elseif ($departureAt !== null) {
                    $departure = new DateTimeImmutable((string) $departureAt, $attendanceTimezone);
                    if ($departure <= $arrival) {
                        throw new RuntimeException('The corrected arrival time must be earlier than the recorded departure.');
                    }
                    $workingMinutes = min(65535, (int) floor(($departure->getTimestamp() - $arrival->getTimestamp()) / 60));
                    $earlyDeparture = $departure < $closingAt->modify('-' . (int) $schedule['early_departure_threshold_minutes'] . ' minutes') ? 1 : 0;
                } else {
                    $workingMinutes = null;
                    $earlyDeparture = 0;
                    $departureVerification = null;
                }
                $update = $pdo->prepare(
                    'UPDATE school_staff_attendance
                     SET arrival_at = ?, expected_reporting_time = ?, status = ?,
                         punctuality_status = ?, minutes_late = ?, remarks = ?,
                         verification_method = "administrator_recorded",
                         departure_at = ?, working_minutes = ?, early_departure = ?,
                         departure_verification_method = ?
                     WHERE id = ? AND school_id = ?'
                );
                $update->execute([
                    $arrivalAt, $schedule['reporting_time'], $legacyStatus,
                    $punctualityStatus, $minutesLate, $reason, $departureAt,
                    $workingMinutes, $earlyDeparture, $departureVerification,
                    (int) $attendanceId, $schoolId,
                ]);
                $newRecord = $oldRecord;
                $newRecord['arrival_at'] = $arrivalAt;
                $newRecord['expected_reporting_time'] = $schedule['reporting_time'];
                $newRecord['status'] = $legacyStatus;
                $newRecord['punctuality_status'] = $punctualityStatus;
                $newRecord['minutes_late'] = $minutesLate;
                $newRecord['remarks'] = $reason;
                $newRecord['verification_method'] = 'administrator_recorded';
                $newRecord['departure_at'] = $departureAt;
                $newRecord['working_minutes'] = $workingMinutes;
                $newRecord['early_departure'] = $earlyDeparture;
                $newRecord['departure_verification_method'] = $departureVerification;
                school_staff_attendance_write_audit(
                    $pdo, $schoolId, (int) $attendanceId, (int) $staffId, $userId,
                    'corrected', school_staff_attendance_snapshot($oldRecord),
                    school_staff_attendance_snapshot($newRecord), $reason, 'administrator_recorded'
                );
                school_staff_attendance_security_event(
                    $pdo,
                    $schoolId,
                    'administrator_attendance_corrected',
                    null,
                    (int) $staffId,
                    $userId,
                    null,
                    $reason,
                    (new DateTimeImmutable('now', $attendanceTimezone))->format('Y-m-d H:i:s')
                );
                audit($userId, 'school_staff_attendance_corrected', 'school_id=' . $schoolId . ';staff_id=' . (int) $staffId . ';attendance_id=' . (int) $attendanceId);
                $pdo->commit();
                flash('success', 'Attendance correction saved. The original values and reason are preserved in the audit history.');
            }
        } elseif ($action === 'review_dispute') {
            $disputeId = filter_var($_POST['dispute_id'] ?? null, FILTER_VALIDATE_INT);
            $disputeStatus = (string) ($_POST['dispute_status'] ?? '');
            $response = trim((string) ($_POST['response'] ?? ''));
            if ($disputeId === false || $disputeId < 1
                || !in_array($disputeStatus, ['in_review', 'resolved'], true)
                || ($disputeStatus === 'resolved' && $response === '')
                || mb_strlen($response) > 1000) {
                throw new RuntimeException('Choose a valid dispute status and provide a response when resolving a dispute.');
            }
            $pdo->beginTransaction();
            $disputeStmt = $pdo->prepare(
                'SELECT * FROM school_staff_attendance_disputes
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $disputeStmt->execute([(int) $disputeId, $schoolId]);
            $dispute = $disputeStmt->fetch();
            if (!$dispute) {
                throw new RuntimeException('The attendance dispute could not be found in this school.');
            }
            $pdo->prepare(
                'UPDATE school_staff_attendance_disputes
                 SET status = ?, response = ?, reviewed_by = ?, reviewed_at = NOW()
                 WHERE id = ? AND school_id = ?'
            )->execute([$disputeStatus, $response !== '' ? $response : null, $userId, (int) $disputeId, $schoolId]);
            if ($dispute['attendance_id'] !== null) {
                $recordStmt = $pdo->prepare(
                    'SELECT * FROM school_staff_attendance
                     WHERE id = ? AND school_id = ? AND staff_id = ?'
                );
                $recordStmt->execute([(int) $dispute['attendance_id'], $schoolId, (int) $dispute['staff_id']]);
                $record = $recordStmt->fetch();
                if ($record) {
                    school_staff_attendance_write_audit(
                        $pdo, $schoolId, (int) $record['id'], (int) $dispute['staff_id'], $userId,
                        'dispute_reviewed', school_staff_attendance_snapshot($record),
                        school_staff_attendance_snapshot($record),
                        $response !== '' ? $response : 'Dispute moved to in review',
                        (string) $record['verification_method']
                    );
                }
            }
            audit($userId, 'school_staff_attendance_dispute_reviewed', 'school_id=' . $schoolId . ';dispute_id=' . (int) $disputeId . ';status=' . $disputeStatus);
            $pdo->commit();
            flash('success', 'Attendance dispute updated.');
        } else {
            throw new RuntimeException('Choose a valid attendance action.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'The attendance change could not be saved. No uncommitted changes were retained.');
        if (!$exception instanceof RuntimeException) {
            error_log('Staff attendance administration failed: ' . $exception->getMessage());
        }
    }
    if ($returnToKiosk) {
        redirect('attendance_kiosk.php?school=' . rawurlencode((string) $school['short_name']));
    }
    $redirectAfterPost();
        } elseif ($action === 'apply_makeup_credit') {
    $obligationId = filter_var($_POST['obligation_id'] ?? null, FILTER_VALIDATE_INT);
    $attendanceId = filter_var($_POST['attendance_id'] ?? null, FILTER_VALIDATE_INT);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($obligationId === false || $obligationId < 1
        || $attendanceId === false || $attendanceId < 1
        || $reason === '' || mb_strlen($reason) > 255) {
        throw new RuntimeException('Choose an outstanding attendance obligation and verified make-up attendance, and enter a reason of up to 255 characters.');
    }

    $now = new DateTimeImmutable('now', $attendanceTimezone);
    $pdo->beginTransaction();
    $obligationStmt = $pdo->prepare(
        'SELECT obligation.id, obligation.staff_id, obligation.obligation_month,
                obligation.pending_days, obligation.outstanding_days,
                staff.role_name
         FROM school_staff_attendance_makeup_obligations obligation
         JOIN school_staff staff
           ON staff.id = obligation.staff_id AND staff.school_id = obligation.school_id
         WHERE obligation.id = ? AND obligation.school_id = ?
         FOR UPDATE'
    );
    $obligationStmt->execute([(int) $obligationId, $schoolId]);
    $obligation = $obligationStmt->fetch();
    if (!$obligation) {
        throw new RuntimeException('The attendance obligation does not belong to this school.');
    }
    $obligationMonth = new DateTimeImmutable(
        (string) $obligation['obligation_month'],
        $attendanceTimezone
    );
    $currentObligation = school_staff_attendance_refresh_makeup_obligation(
        $pdo,
        $schoolId,
        (int) $obligation['staff_id'],
        (string) $obligation['role_name'],
        (string) $obligation['obligation_month'],
        $now
    );
    if ($obligationMonth->format('Y-m') >= $now->format('Y-m')
        || (int) $currentObligation['pending_days'] > 0
        || (int) $currentObligation['outstanding_days'] < 1) {
        throw new RuntimeException('Only a completed month with an outstanding make-up obligation can receive a credit.');
    }
    $attendanceStmt = $pdo->prepare(
        'SELECT attendance.*
         FROM school_staff_attendance attendance
         WHERE attendance.id = ? AND attendance.school_id = ?
           AND attendance.staff_id = ?
           AND attendance.attendance_date > ?
           AND attendance.attendance_date <= ?
           AND attendance.status IN ("present", "late")
           AND attendance.verification_method IN ("administrator_recorded", "kiosk_verified", "fingerprint_verified")
         FOR UPDATE'
    );
    $attendanceStmt->execute([
        (int) $attendanceId,
        $schoolId,
        (int) $obligation['staff_id'],
        $obligationMonth->modify('last day of this month')->format('Y-m-d'),
        $today,
    ]);
    $attendanceRecord = $attendanceStmt->fetch();
    if (!$attendanceRecord) {
        throw new RuntimeException('Select a verified attendance record for this staff member after the obligation month.');
    }
    $creditInsert = $pdo->prepare(
        'INSERT INTO school_staff_attendance_makeup_credits
            (school_id, staff_id, obligation_id, attendance_id, credited_by, reason)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    try {
        $creditInsert->execute([
            $schoolId,
            (int) $obligation['staff_id'],
            (int) $obligationId,
            (int) $attendanceId,
            $userId,
            $reason,
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            throw new RuntimeException('That attendance record has already been applied to a make-up obligation.', 0, $exception);
        }
        throw $exception;
    }
    $updatedObligation = school_staff_attendance_refresh_makeup_obligation(
        $pdo,
        $schoolId,
        (int) $obligation['staff_id'],
        (string) $obligation['role_name'],
        (string) $obligation['obligation_month'],
        $now
    );
    school_staff_attendance_write_audit(
        $pdo,
        $schoolId,
        (int) $attendanceId,
        (int) $obligation['staff_id'],
        $userId,
        'makeup_credit',
        school_staff_attendance_snapshot($attendanceRecord),
        school_staff_attendance_snapshot($attendanceRecord),
        $reason . ' · credited to ' . $obligationMonth->format('F Y'),
        (string) $attendanceRecord['verification_method']
    );
    audit(
        $userId,
        'school_staff_attendance_makeup_credited',
        'school_id=' . $schoolId . ';obligation_id=' . (int) $obligationId
            . ';attendance_id=' . (int) $attendanceId
            . ';outstanding_days=' . (int) $updatedObligation['outstanding_days']
    );
    $pdo->commit();
    flash('success', 'Verified make-up attendance was applied to the outstanding month. It is excluded from ordinary attendance totals for its attendance date.');
    $redirectAfterPost();
}

$scheduleByRole = [];
foreach ($staffRoles as $staffRole) {
    $scheduleByRole[$staffRole] = school_staff_attendance_schedule(db(), $schoolId, $staffRole);
}
$dailyStmt = db()->prepare(
    'SELECT attendance.*, staff.employee_number, staff.role_name, account.full_name
     FROM school_staff_attendance attendance
     JOIN school_staff staff ON staff.id = attendance.staff_id AND staff.school_id = attendance.school_id
     JOIN users account ON account.id = staff.user_id
     WHERE attendance.school_id = ? AND attendance.attendance_date = ?'
);
$dailyStmt->execute([$schoolId, $selectedDate]);
$dailyRecords = [];
foreach ($dailyStmt->fetchAll() as $record) {
    $dailyRecords[(int) $record['staff_id']] = $record;
}
$dailyCounts = [
    'early' => 0, 'punctual' => 0, 'late' => 0, 'very_late' => 0,
    'absent' => 0, 'excused' => 0, 'not_checked_in' => 0,
];
foreach ($staff as $member) {
    $id = (int) $member['id'];
    if ($selectedDate < substr((string) $member['created_at'], 0, 10)) {
        continue;
    }
    $record = $dailyRecords[$id] ?? null;
    if ($record === null) {
        if (school_staff_attendance_is_working_day(db(), $schoolId, $selectedDate, $scheduleByRole[(string) $member['role_name']])) {
            $dailyCounts['not_checked_in']++;
        }
        continue;
    }
    $punctualityStatus = (string) ($record['punctuality_status'] ?? '');
    if (isset($dailyCounts[$punctualityStatus])) {
        $dailyCounts[$punctualityStatus]++;
    } elseif (isset($dailyCounts[(string) $record['status']])) {
        $dailyCounts[(string) $record['status']]++;
    }
}
$checkIns = $dailyCounts['early'] + $dailyCounts['punctual'] + $dailyCounts['late'] + $dailyCounts['very_late'];
$onTime = $dailyCounts['early'] + $dailyCounts['punctual'];
$punctualityPercent = $checkIns > 0 ? round($onTime / $checkIns * 100, 1) : null;

$dashboardStaff = array_values(array_filter($staff, static function (array $member) use (
    $filterStaffId,
    $filterRole,
    $filterStatus,
    $dailyRecords,
    $schoolId,
    $selectedDate,
    $scheduleByRole
): bool {
    $staffId = (int) $member['id'];
    if ($selectedDate < substr((string) $member['created_at'], 0, 10)) {
        return false;
    }
    if ($filterStaffId > 0 && $filterStaffId !== $staffId) {
        return false;
    }
    if ($filterRole !== '' && $filterRole !== (string) $member['role_name']) {
        return false;
    }
    if ($filterStatus === '') {
        return true;
    }
    $record = $dailyRecords[$staffId] ?? null;
    if ($filterStatus === 'not_checked_in') {
        return $record === null
            && school_staff_attendance_is_working_day(db(), $schoolId, $selectedDate, $scheduleByRole[(string) $member['role_name']]);
    }
    if (!$record) {
        return false;
    }
    if ($filterStatus === 'checked_out') {
        return !empty($record['departure_at']);
    }
    return (string) ($record['punctuality_status'] ?: $record['status']) === $filterStatus;
}));
$liveRows = [];
foreach ($dashboardStaff as $member) {
    $memberId = (int) $member['id'];
    $record = $dailyRecords[$memberId] ?? null;
    $liveRows[] = [
        'id' => $memberId,
        'attendance_id' => $record ? (int) $record['id'] : null,
        'status' => $record
            ? (string) ($record['punctuality_status'] ?: $record['status'])
            : (school_staff_attendance_is_working_day(
                db(),
                $schoolId,
                $selectedDate,
                $scheduleByRole[(string) $member['role_name']]
            ) ? 'not_checked_in' : 'not_scheduled'),
        'arrival_at' => $record['arrival_at'] ?? null,
        'departure_at' => $record['departure_at'] ?? null,
        'minutes_late' => (int) ($record['minutes_late'] ?? 0),
        'verification_method' => $record['verification_method'] ?? null,
        'early_departure' => (bool) ($record['early_departure'] ?? false),
    ];
}
$liveFeedQuery = http_build_query([
    'date' => $selectedDate,
    'staff_id' => $filterStaffId ?: '',
    'staff_group' => $filterRole,
    'status' => $filterStatus,
]);

$formatVerification = static fn(string $method): string => match ($method) {
    'kiosk_verified' => 'KIOSK VERIFIED',
    'fingerprint_verified' => 'FINGERPRINT VERIFIED',
    'system_generated' => 'SYSTEM GENERATED',
    default => 'ADMINISTRATOR RECORDED',
};
$disputeStmt = db()->prepare(
    'SELECT dispute.id, dispute.staff_id, dispute.attendance_id, dispute.message,
            dispute.status, dispute.response, dispute.created_at, account.full_name,
            staff.employee_number
     FROM school_staff_attendance_disputes dispute
     JOIN school_staff staff ON staff.id = dispute.staff_id AND staff.school_id = dispute.school_id
     JOIN users account ON account.id = dispute.submitted_by
     WHERE dispute.school_id = ? AND dispute.status <> "resolved"
     ORDER BY dispute.created_at'
);
$disputeStmt->execute([$schoolId]);
$disputes = $disputeStmt->fetchAll();
$auditStmt = db()->prepare(
    'SELECT event.changed_at, event.change_type, event.original_snapshot, event.updated_snapshot,
            event.reason, event.verification_method, staff.employee_number, account.full_name,
            actor.full_name AS actor_name
     FROM school_staff_attendance_audit event
     JOIN school_staff staff ON staff.id = event.staff_id AND staff.school_id = event.school_id
     JOIN users account ON account.id = staff.user_id
     LEFT JOIN users actor ON actor.id = event.changed_by
     WHERE event.school_id = ? AND event.attendance_id IN (
         SELECT id FROM school_staff_attendance WHERE school_id = ?
     )
     ORDER BY event.changed_at DESC LIMIT 100'
);
$auditStmt->execute([$schoolId, $schoolId]);
$auditEvents = $auditStmt->fetchAll();
$pinResetHistoryStmt = db()->prepare(
    'SELECT pin_reset.id, pin_reset.reason, pin_reset.created_at, pin_reset.expires_at,
            pin_reset.completed_at, pin_reset.revoked_at, pin_reset.failed_attempts,
            staff.employee_number, staff.user_id, staffAccount.full_name,
            initiator.full_name AS initiator_name
     FROM school_staff_attendance_pin_resets pin_reset
     JOIN school_staff staff ON staff.id = pin_reset.staff_id AND staff.school_id = pin_reset.school_id
     JOIN users staffAccount ON staffAccount.id = staff.user_id
     JOIN users initiator ON initiator.id = pin_reset.initiated_by
     WHERE pin_reset.school_id = ?
     ORDER BY pin_reset.id DESC LIMIT 50'
);
$pinResetHistoryStmt->execute([$schoolId]);
$pinResetHistory = $pinResetHistoryStmt->fetchAll();
$deviceStmt = db()->prepare(
    'SELECT device.id, device.device_name, device.created_at, device.last_used_at,
            device.deactivated_at, registrar.full_name AS registered_by_name,
            deactivator.full_name AS deactivated_by_name
     FROM school_staff_attendance_kiosk_devices device
     JOIN users registrar ON registrar.id = device.registered_by
     LEFT JOIN users deactivator ON deactivator.id = device.deactivated_by
     WHERE device.school_id = ?
     ORDER BY device.deactivated_at IS NULL DESC, device.created_at DESC LIMIT 100'
);
$deviceStmt->execute([$schoolId]);
$kioskDevices = $deviceStmt->fetchAll();
$activeKioskDeviceCount = count(array_filter(
    $kioskDevices,
    static fn(array $device): bool => $device['deactivated_at'] === null
));
$currentKioskDevice = school_staff_attendance_kiosk_device(db(), $schoolId);
$securityEventsStmt = db()->prepare(
    'SELECT event.event_type, event.reason, event.occurred_at,
            device.device_name, staff.employee_number, staffUser.full_name AS staff_name,
            actor.full_name AS actor_name
     FROM school_staff_attendance_security_events event
     LEFT JOIN school_staff_attendance_kiosk_devices device
       ON device.id = event.device_id AND device.school_id = event.school_id
     LEFT JOIN school_staff staff ON staff.id = event.staff_id AND staff.school_id = event.school_id
     LEFT JOIN users staffUser ON staffUser.id = staff.user_id
     LEFT JOIN users actor ON actor.id = event.actor_user_id
     WHERE event.school_id = ?
     ORDER BY event.id DESC LIMIT 100'
);
$securityEventsStmt->execute([$schoolId]);
$securityEvents = $securityEventsStmt->fetchAll();
$makeupObligationsStmt = db()->prepare(
    'SELECT obligation.id, obligation.staff_id, obligation.obligation_month,
            obligation.required_days, obligation.elapsed_days,
            obligation.completed_days, obligation.excused_days,
            obligation.credited_days, obligation.outstanding_days,
            staff.employee_number, staff.role_name, account.full_name
     FROM school_staff_attendance_makeup_obligations obligation
     JOIN school_staff staff
       ON staff.id = obligation.staff_id AND staff.school_id = obligation.school_id
     JOIN users account ON account.id = staff.user_id
     WHERE obligation.school_id = ? AND obligation.status = "open"
       AND obligation.pending_days = 0 AND obligation.outstanding_days > 0
     ORDER BY obligation.obligation_month, account.full_name
     LIMIT 200'
);
$makeupObligationsStmt->execute([$schoolId]);
$makeupObligations = $makeupObligationsStmt->fetchAll();
$makeupCandidatesStmt = db()->prepare(
    'SELECT attendance.id, attendance.staff_id, attendance.attendance_date,
            attendance.status, attendance.verification_method,
            staff.employee_number, account.full_name
     FROM school_staff_attendance attendance
     JOIN school_staff staff
       ON staff.id = attendance.staff_id AND staff.school_id = attendance.school_id
     JOIN users account ON account.id = staff.user_id
     WHERE attendance.school_id = ? AND attendance.attendance_date <= ?
       AND attendance.status IN ("present", "late")
       AND attendance.verification_method IN ("administrator_recorded", "kiosk_verified", "fingerprint_verified")
       AND NOT EXISTS (
           SELECT 1 FROM school_staff_attendance_makeup_credits credit
           WHERE credit.school_id = attendance.school_id
             AND credit.attendance_id = attendance.id
       )
     ORDER BY attendance.attendance_date DESC, attendance.id DESC
     LIMIT 5000'
);
$makeupCandidatesStmt->execute([$schoolId, $today]);
$makeupCandidatesByStaff = [];
foreach ($makeupCandidatesStmt->fetchAll() as $candidate) {
    $makeupCandidatesByStaff[(int) $candidate['staff_id']][] = $candidate;
}
$missingAttendanceAlerts = [];
if ($selectedDate === $today) {
    $alertNow = new DateTimeImmutable('now', $attendanceTimezone);
    foreach ($staff as $member) {
        $staffIdForAlert = (int) $member['id'];
        if ($today < substr((string) $member['created_at'], 0, 10)) {
            continue;
        }
        $scheduleForAlert = $scheduleByRole[(string) $member['role_name']];
        if (($dailyRecords[$staffIdForAlert]['arrival_at'] ?? null) === null
            && school_staff_attendance_is_working_day(db(), $schoolId, $today, $scheduleForAlert)
            && $alertNow->format('H:i') >= '14:00') {
            $missingAttendanceAlerts[] = $member;
        }
    }
}
$pinResetNotice = $_SESSION['staff_attendance_pin_reset_notice'] ?? null;
unset($_SESSION['staff_attendance_pin_reset_notice']);
$flash = take_flash();
$managementReturnFilters = [
    'date' => $selectedDate,
    'staff_id' => $filterStaffId > 0 ? (string) $filterStaffId : '',
    'staff_group' => $filterRole,
    'status' => $filterStatus,
];
$managementActionViews = [
    'reset_pin' => 'staff-action-reset-pin',
    'verified_arrival' => 'staff-action-verified-arrival',
    'attendance' => 'staff-action-attendance',
];
$showAttendanceActions = (string) ($_GET['actions'] ?? '') === '1';
$attendanceWorkspaceStart = '';
if (!$showAttendanceActions) {
    $attendanceWorkspaceStart = !$readOnly && $managementAction !== ''
        ? $managementActionViews[$managementAction]
        : 'daily-attendance';
}
$attendanceActionMenuQuery = ['actions' => '1']
    + array_filter($managementReturnFilters, static fn(string $value): bool => $value !== '');
$attendanceActionMenuUrl = 'staff_attendance.php?' . http_build_query($attendanceActionMenuQuery);
$managementActionUrls = [];
foreach ($managementActions as $actionKey => $actionLabel) {
    $actionQuery = array_filter($managementReturnFilters, static fn(string $value): bool => $value !== '');
    $actionQuery['manage_action'] = $actionKey;
    $managementActionUrls[$actionKey] = 'staff_attendance.php?' . http_build_query($actionQuery);
}
render_header('Staff attendance management');
?>
<section class="page-heading">
    <p class="eyebrow">STAFF OPERATIONS</p>
    <h1>Staff attendance &amp; punctuality</h1>
    <p class="muted">Attendance times are server recorded. Kiosk check-ins cannot be edited by staff; all administrator entries and corrections retain a reason and audit history.</p>
    <p><a class="button button-quiet" href="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>" target="_blank" rel="noopener">Open school kiosk</a></p>
</section>
<?php if ($flash): ?><p class="notice notice-<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p><?php endif; ?>
<?php if (!$readOnly): ?>
<section class="panel" id="kiosk-setup" data-action-view="kiosk-setup" hidden>
    <h2>Office attendance kiosk setup</h2>
    <p class="muted">Register the first school-managed device during initial setup (computer, tablet, or laptop). Kiosk use stays unavailable until at least one device is active; administrators may authorize additional devices later. Each device uses an HttpOnly, SameSite=Strict credential. Protect its operating-system account and browser profile, and use HTTPS in production.</p>
    <p><strong>Kiosk setup:</strong> <?= $kioskConfigured ? 'Configured' : 'Not complete — save a schedule for every active staff category below first.' ?>
        · <strong>Active devices:</strong> <?= $activeKioskDeviceCount ?>
        · <strong>This device:</strong> <?= $currentKioskDevice ? e((string) $currentKioskDevice['device_name']) . ' (authorised)' : 'Not registered for this school' ?></p>
    <form method="post" class="form-stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_attendance_timezone"><input type="hidden" name="return_view" value="kiosk-setup">
        <label for="attendance-timezone">School attendance timezone
            <select id="attendance-timezone" name="timezone" required>
                <?php foreach (DateTimeZone::listIdentifiers() as $timezoneOption): ?>
                    <option value="<?= e($timezoneOption) ?>" <?= $timezoneOption === $attendanceTimezoneName ? 'selected' : '' ?>><?= e($timezoneOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button" type="submit">Save school timezone</button>
    </form>
    <?php if ($kioskConfigured): ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="register_kiosk_device"><input type="hidden" name="return_view" value="kiosk-setup">
            <label for="kiosk-device-name">Device name<input id="kiosk-device-name" name="device_name" maxlength="100" required placeholder="Main office tablet"></label>
            <button class="button button-primary" type="submit"><?= $activeKioskDeviceCount > 0 ? 'Authorise another device' : 'Authorise first device' ?></button>
        </form>
        <p class="muted">To re-authorise a revoked device, register it again from this device. A new random credential will be issued.</p>
    <?php else: ?><p class="muted">Device authorisation becomes available after every active staff category has a saved schedule.</p><?php endif; ?>
    <?php if ($kioskDevices !== []): ?>
        <h3>Authorised attendance devices</h3>
        <div class="table-scroll"><table><thead><tr><th>Device</th><th>Registered</th><th>Last used</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($kioskDevices as $device): ?>
            <tr>
                <td><?= e((string) $device['device_name']) ?></td>
                <td><?= e((string) $device['created_at']) ?> · <?= e((string) $device['registered_by_name']) ?></td>
                <td><?= e((string) ($device['last_used_at'] ?? 'Never')) ?></td>
                <td><?= $device['deactivated_at'] === null ? 'Active' : 'Revoked · ' . e((string) $device['deactivated_at']) ?></td>
                <td>
                    <?php if ($device['deactivated_at'] === null): ?>
                        <div class="staff-kiosk-device-actions">
                            <form method="post" class="staff-kiosk-rename-form">
                                <?= csrf_field() ?><input type="hidden" name="action" value="rename_kiosk_device"><input type="hidden" name="return_view" value="kiosk-setup"><input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                <label class="sr-only" for="kiosk-device-name-<?= (int) $device['id'] ?>">New name for <?= e((string) $device['device_name']) ?></label>
                                <input id="kiosk-device-name-<?= (int) $device['id'] ?>" type="text" name="device_name" maxlength="100" value="<?= e((string) $device['device_name']) ?>" required>
                                <button class="button button-small" type="submit">Rename</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Revoke attendance access for this device?');">
                                <?= csrf_field() ?><input type="hidden" name="action" value="deactivate_kiosk_device"><input type="hidden" name="return_view" value="kiosk-setup"><input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                <button class="button button-small" type="submit">Revoke access</button>
                            </form>
                        </div>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($readOnly): ?><p class="notice">IT Support preview is read-only. Staff attendance controls are disabled.</p><?php endif; ?>

<section class="report-action-grid" aria-label="Staff attendance actions" data-action-return="<?= e($attendanceActionMenuUrl) ?>" data-action-start="<?= e($attendanceWorkspaceStart) ?>">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('attendance') ?></span>
        <div><h2>Daily attendance</h2><p>Review staff check-ins, status, and departures for a selected date.</p></div>
        <a class="button button-primary" href="#daily-attendance" data-action-open="daily-attendance">Open daily register</a>
    </article>
    <?php if (!$readOnly): ?>
        <?php foreach ($managementActions as $actionKey => $actionLabel): ?>
            <article class="report-action-card">
                <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('person-check') ?></span>
                <div><h2><?= e($actionLabel) ?></h2><p><?= e($managementActionDescriptions[$actionKey]) ?></p></div>
                <a class="button" href="<?= e($managementActionUrls[$actionKey]) ?>"><?= e($actionLabel) ?></a>
            </article>
        <?php endforeach; ?>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('settings') ?></span>
            <div><h2>Kiosk setup</h2><p>Register, rename, and revoke authorised attendance devices.</p></div>
            <a class="button" href="#kiosk-setup" data-action-open="kiosk-setup">Open kiosk setup</a>
        </article>
        <article class="report-action-card">
            <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('settings') ?></span>
            <div><h2>Reporting schedules</h2><p>Set staff reporting times, working days, and closing rules.</p></div>
            <a class="button" href="#reporting-schedules" data-action-open="reporting-schedules">Manage schedules</a>
        </article>
    <?php endif; ?>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('notifications') ?></span>
        <div><h2>Alerts and security</h2><p>Review late check-in alerts and attendance kiosk security events.</p></div>
        <a class="button" href="#attendance-alerts" data-action-open="attendance-alerts">View alerts</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('documents') ?></span>
        <div><h2>Attendance disputes</h2><p>Review staff requests about attendance records.</p></div>
        <a class="button" href="#attendance-disputes" data-action-open="attendance-disputes">Review disputes</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('lock') ?></span>
        <div><h2>PIN reset history</h2><p>View staff attendance PIN recovery activity.</p></div>
        <a class="button" href="#pin-reset-history" data-action-open="pin-reset-history">View reset history</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('documents') ?></span>
        <div><h2>Attendance audit trail</h2><p>Review recorded attendance changes and reasons.</p></div>
        <a class="button" href="#attendance-audit" data-action-open="attendance-audit">View audit trail</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('attendance') ?></span>
        <div><h2>Make-up attendance</h2><p>Apply verified attendance to outstanding make-up obligations.</p></div>
        <a class="button" href="#makeup-obligations" data-action-open="makeup-obligations">Manage make-up days</a>
    </article>
</section>

<section class="attendance-register-workspace" id="daily-attendance" aria-label="Daily attendance register" data-action-view="daily-attendance" hidden>
<section class="attendance-summary-cards" aria-label="Daily attendance summary">
    <?php foreach ([
        'early' => 'Early arrivals',
        'punctual' => 'Punctual',
        'late' => 'Late',
        'very_late' => 'Very late',
        'absent' => 'Recorded absent',
        'not_checked_in' => 'Not checked in',
    ] as $statusKey => $label): ?>
        <article class="attendance-summary-card"><span><?= e($label) ?></span><strong><?= (int) $dailyCounts[$statusKey] ?></strong></article>
    <?php endforeach; ?>
    <article class="attendance-summary-card"><span>Punctuality rate</span><strong><?= $punctualityPercent === null ? '—' : e(number_format($punctualityPercent, 1)) . '%' ?></strong></article>
</section>

<section class="panel attendance-filter-panel">
    <form method="get" class="attendance-dashboard-filters">
        <label for="attendance-date">Date<input id="attendance-date" type="date" name="date" value="<?= e($selectedDate) ?>"></label>
        <label for="attendance-staff-filter">Staff member<select id="attendance-staff-filter" name="staff_id"><option value="">All staff</option><?php foreach ($staff as $member): ?><option value="<?= (int) $member['id'] ?>" <?= $filterStaffId === (int) $member['id'] ? 'selected' : '' ?>><?= e((string) $member['full_name']) ?></option><?php endforeach; ?></select></label>
        <label for="attendance-category-filter">Staff category<select id="attendance-category-filter" name="staff_group"><option value="">All categories</option><?php foreach ($staffRoles as $staffRole): ?><option value="<?= e($staffRole) ?>" <?= $filterRole === $staffRole ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $staffRole))) ?></option><?php endforeach; ?></select></label>
        <label for="attendance-status-filter">Attendance status<select id="attendance-status-filter" name="status"><option value="">All</option><?php foreach ($validStatuses as $status): ?><option value="<?= e($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></label>
        <button class="button button-primary" type="submit">Apply filters</button>
    </form>
</section>

 </section>

<?php if (!$readOnly && $managementAction !== ''): ?>
<section class="panel attendance-actions-panel" id="<?= e($managementActionViews[$managementAction]) ?>" data-action-view="<?= e($managementActionViews[$managementAction]) ?>" hidden>
    <h2><?= e($managementActions[$managementAction]) ?></h2>
    <?php if ($managementAction === 'reset_pin' && is_array($pinResetNotice)): ?>
        <section class="notice notice-success attendance-pin-reset-code" role="status">
            <h3>One-time PIN setup code — show only to the verified staff member</h3>
            <p>Staff: <strong><?= e((string) $pinResetNotice['staff_name']) ?></strong>
                · Staff ID: <strong><?= e((string) $pinResetNotice['staff_id']) ?></strong></p>
            <p>Code: <strong class="attendance-recovery-code"><?= e((string) $pinResetNotice['code']) ?></strong>
                · Expires <?= e((new DateTimeImmutable((string) $pinResetNotice['expires_at'], $attendanceTimezone))->format('g:i A')) ?></p>
            <p>Give the code directly to the staff member. It is shown only once; it cannot be used to view or recover their former PIN.</p>
            <a class="button button-small" href="attendance_kiosk.php?school=<?= rawurlencode((string) $school['short_name']) ?>&amp;mode=reset-pin" target="_blank" rel="noopener">Open PIN setup page</a>
        </section>
    <?php endif; ?>

    <?php if ($managementStaff === null): ?>
        <div class="attendance-action-workspace">
            <form method="get" action="staff_attendance.php" class="form-stack attendance-staff-picker">
                <?php foreach ($managementReturnFilters as $queryName => $queryValue): ?>
                    <?php if ($queryValue !== ''): ?><input type="hidden" name="<?= e($queryName) ?>" value="<?= e($queryValue) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <input type="hidden" name="manage_action" value="<?= e($managementAction) ?>">
                <label for="attendance-manage-staff">Select a staff member
                    <select id="attendance-manage-staff" name="manage_staff_id" required>
                        <option value="">Choose staff member</option>
                        <?php foreach ($staff as $member): ?>
                            <option value="<?= (int) $member['id'] ?>">
                                <?= e((string) $member['full_name']) ?> · <?= e((string) ($member['employee_number'] ?: 'No Staff ID')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button-primary" type="submit">Continue with selected staff</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($managementStaff !== null && $managementAction !== ''):
        $managementRecord = $dailyRecords[$managementStaffId] ?? null;
    ?>
        <div class="attendance-action-workspace">
            <div class="attendance-selected-staff">
                <span>Selected staff member</span>
                <strong><?= e((string) $managementStaff['full_name']) ?></strong>
                <span>Staff ID: <?= e((string) ($managementStaff['employee_number'] ?: 'Not assigned')) ?></span>
                <span><?= e(ucwords(str_replace('_', ' ', (string) $managementStaff['role_name']))) ?></span>
            </div>

            <?php if ($managementAction === 'reset_pin'): ?>
                <h3>Reset attendance PIN</h3>
                <?php if (is_array($pinResetNotice)): ?>
                    <p class="muted">The one-time setup code above is ready to give directly to the selected staff member.</p>
                <?php elseif (trim((string) $managementStaff['employee_number']) === ''): ?>
                    <p class="notice notice-error">Assign this staff member a unique Staff ID before initiating PIN recovery.</p>
                <?php else: ?>
                    <p class="muted">Verify this staff member in person. Their current PIN will stop working immediately; the one-time setup code is shown only once.</p>
                    <form method="post" class="form-stack attendance-action-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="initiate_pin_reset">
                        <input type="hidden" name="staff_id" value="<?= $managementStaffId ?>">
                        <label class="checkbox-label"><input type="checkbox" name="identity_verified" value="1" required> I verified this staff member's identity in person.</label>
                        <label>Reason for reset<textarea name="reason" maxlength="255" required></textarea></label>
                        <button class="button button-primary" type="submit">Invalidate old PIN and issue one-time code</button>
                    </form>
                <?php endif; ?>
            <?php elseif ($managementAction === 'verified_arrival'): ?>
                <h3>Record verified arrival now</h3>
                <?php if ($selectedDate !== $today): ?>
                    <p class="notice">Verified arrivals can only be recorded for today. Choose today in the date filter, then continue here.</p>
                <?php else: ?>
                    <p class="muted">This records the current server time; it does not let you choose an arrival time.</p>
                    <form method="post" class="form-stack attendance-action-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="record_verified_arrival_now">
                        <input type="hidden" name="staff_id" value="<?= $managementStaffId ?>">
                        <label class="checkbox-label"><input type="checkbox" name="physically_verified" value="1" required> I confirm this staff member is physically present now.</label>
                        <label>Reason for assistance<textarea name="reason" maxlength="255" required></textarea></label>
                        <button class="button button-primary" type="submit">Record server-time arrival</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <h3><?= $managementRecord ? 'Correct attendance record' : 'Record attendance' ?></h3>
                <?php if ($selectedDate > $today): ?>
                    <p class="notice">Attendance cannot be recorded for a future date.</p>
                <?php elseif ($selectedDate < substr((string) $managementStaff['created_at'], 0, 10)): ?>
                    <p class="notice">This staff member was not yet on the school roster for the selected date.</p>
                <?php elseif ($managementRecord): ?>
                    <p class="muted">Editing <?= e((new DateTimeImmutable($selectedDate, $attendanceTimezone))->format('l, j F Y')) ?>. Enter a reason; the original values and change remain in the audit history.</p>
                    <form method="post" class="form-stack attendance-action-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="correct_record">
                        <input type="hidden" name="staff_id" value="<?= $managementStaffId ?>">
                        <input type="hidden" name="attendance_id" value="<?= (int) $managementRecord['id'] ?>">
                        <input type="hidden" name="attendance_date" value="<?= e((string) $managementRecord['attendance_date']) ?>">
                        <label>Status
                            <select name="attendance_status">
                                <?php foreach (['present', 'absent', 'excused'] as $entryStatus): ?>
                                    <option value="<?= e($entryStatus) ?>" <?= $managementRecord['status'] === $entryStatus || ($entryStatus === 'present' && in_array($managementRecord['punctuality_status'], ['early', 'punctual', 'late', 'very_late'], true)) ? 'selected' : '' ?>><?= e(ucfirst($entryStatus)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Correct arrival (for Present)
                            <input type="time" name="arrival_time" value="<?= $managementRecord['arrival_at'] ? e((new DateTimeImmutable((string) $managementRecord['arrival_at'], $attendanceTimezone))->format('H:i')) : '' ?>">
                        </label>
                        <label>Departure time (optional)
                            <input type="time" name="departure_time" value="<?= $managementRecord['departure_at'] ? e((new DateTimeImmutable((string) $managementRecord['departure_at'], $attendanceTimezone))->format('H:i')) : '' ?>">
                        </label>
                        <label>Reason for correction<textarea name="reason" maxlength="255" required></textarea></label>
                        <button class="button button-primary" type="submit">Save correction</button>
                    </form>
                <?php else: ?>
                    <p class="muted">Record for <?= e((new DateTimeImmutable($selectedDate, $attendanceTimezone))->format('l, j F Y')) ?>. Enter a reason for every administrator-recorded entry.</p>
                    <form method="post" class="form-stack attendance-action-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="manual_record">
                        <input type="hidden" name="staff_id" value="<?= $managementStaffId ?>">
                        <input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">
                        <label>Status
                            <select name="attendance_status" required>
                                <option value="">Select status</option>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="excused">Excused / official duty</option>
                            </select>
                        </label>
                        <label>Arrival time (for Present)<input type="time" name="arrival_time"></label>
                        <label>Departure time (optional)<input type="time" name="departure_time"></label>
                        <label>Reason / duty explanation<textarea name="reason" maxlength="255" required></textarea></label>
                        <button class="button button-primary" type="submit">Save attendance record</button>
                    </form>
                    <?php if ($selectedDate === $today): ?>
                        <p class="muted">An absence can only be saved after the scheduled closing time and 4:00 PM absence cutoff.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel attendance-live-dashboard" data-action-view="daily-attendance" hidden data-attendance-live data-feed-url="staff_attendance_feed.php?<?= e($liveFeedQuery) ?>" data-snapshot="<?= e(json_encode($liveRows, JSON_THROW_ON_ERROR)) ?>">
    <div class="attendance-register-heading"><div><h2>Daily attendance · <?= e((new DateTimeImmutable($selectedDate, $attendanceTimezone))->format('l, j F Y')) ?></h2><p class="muted">Refreshes automatically every 30 seconds · <?= count($dashboardStaff) ?> staff shown</p></div><button class="button button-quiet" type="button" data-attendance-refresh>Refresh now</button></div>
    <?php if ($staff === []): ?><p class="muted">No active staff records are configured for this school.</p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Staff member</th><th>Staff ID</th><th>Arrival</th><th>Status</th><th>Minutes late</th><th>Verification</th><th>Departure</th></tr></thead><tbody data-attendance-live-body>
        <?php foreach ($dashboardStaff as $member):
            $staffId = (int) $member['id'];
            $record = $dailyRecords[$staffId] ?? null;
            $status = $record
                ? (string) ($record['punctuality_status'] ?: $record['status'])
                : (school_staff_attendance_is_working_day(
                    db(),
                    $schoolId,
                    $selectedDate,
                    $scheduleByRole[(string) $member['role_name']]
                ) ? 'not_checked_in' : 'not_scheduled');
        ?>
            <tr data-staff-id="<?= $staffId ?>">
                <td><?= e((string) $member['full_name']) ?></td>
                <td><?= e((string) ($member['employee_number'] ?: '—')) ?></td>
                <td><?= e($formatDateTime($record['arrival_at'] ?? null)) ?></td>
                <td><span class="attendance-status-badge attendance-status-<?= e($status) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                <td><?= (int) ($record['minutes_late'] ?? 0) ?></td>
                <td><?= $record ? e($formatVerification((string) $record['verification_method'])) : '—' ?></td>
                <td><?= e($formatDateTime($record['departure_at'] ?? null)) ?><?= !empty($record['early_departure']) ? ' · Early' : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>

<section class="panel attendance-makeup-obligations-panel" id="makeup-obligations" data-action-view="makeup-obligations" hidden>
    <h2>Outstanding attendance make-up obligations</h2>
    <p class="muted">Only verified attendance after a completed obligation month can be credited. Each daily attendance record can satisfy at most one outstanding day and is excluded from that month’s ordinary attendance totals after credit.</p>
    <?php if ($makeupObligations === []): ?>
        <p class="muted">There are no completed-month make-up obligations awaiting verified attendance.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Staff member</th><th>Obligation month</th><th>Required days</th><th>Attended / excused</th><th>Previously credited</th><th>Days outstanding</th><th>Apply verified make-up attendance</th></tr></thead>
            <tbody>
            <?php foreach ($makeupObligations as $obligation):
                $obligationEnd = (new DateTimeImmutable((string) $obligation['obligation_month'], $attendanceTimezone))
                    ->modify('last day of this month')->format('Y-m-d');
                $eligibleCandidates = array_values(array_filter(
                    $makeupCandidatesByStaff[(int) $obligation['staff_id']] ?? [],
                    static fn(array $candidate): bool => (string) $candidate['attendance_date'] > $obligationEnd
                ));
            ?>
                <tr>
                    <td><?= e((string) $obligation['full_name']) ?><br><small><?= e((string) ($obligation['employee_number'] ?: 'No Staff ID')) ?></small></td>
                    <td><?= e((new DateTimeImmutable((string) $obligation['obligation_month']))->format('F Y')) ?></td>
                    <td><?= (int) $obligation['required_days'] ?></td>
                    <td><?= (int) $obligation['completed_days'] ?> present · <?= (int) $obligation['excused_days'] ?> excused</td>
                    <td><?= (int) $obligation['credited_days'] ?></td>
                    <td><strong><?= (int) $obligation['outstanding_days'] ?></strong></td>
                    <td>
                        <?php if (!$readOnly && $eligibleCandidates !== []): ?>
                            <form method="post" class="form-stack">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="apply_makeup_credit">
                                <input type="hidden" name="return_view" value="makeup-obligations">
                                <input type="hidden" name="obligation_id" value="<?= (int) $obligation['id'] ?>">
                                <label>Verified attendance
                                    <select name="attendance_id" required>
                                        <option value="">Choose a later attendance day</option>
                                        <?php foreach ($eligibleCandidates as $candidate): ?>
                                            <option value="<?= (int) $candidate['id'] ?>">
                                                <?= e((string) $candidate['attendance_date']) ?> ·
                                                <?= e((string) $candidate['full_name']) ?> ·
                                                <?= e(ucwords(str_replace('_', ' ', (string) $candidate['verification_method']))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Reason<input name="reason" maxlength="255" required></label>
                                <button class="button button-small button-primary" type="submit">Credit one make-up day</button>
                            </form>
                        <?php else: ?>
                            <span class="muted"><?= $readOnly ? 'Read-only view' : 'No eligible verified attendance found' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>



<?php if (!$readOnly): ?>
<section class="panel attendance-policy-panel" id="reporting-schedules" data-action-view="reporting-schedules" hidden>
    <h2>Reporting schedules and optional controls</h2>
    <p class="muted">Schedules are configured separately by staff category. Kiosk check-ins are accepted from 5:30 AM until before 2:00 PM; check-outs are available any time on a configured working day. System absences are recorded after both the scheduled closing time and the 4:00 PM absence cutoff. Reporting time and grace settings classify arrivals as Early, Punctual, Late, or Very Late.</p>
    <?php foreach ($staffRoles as $staffRole):
        $schedule = $scheduleByRole[$staffRole];
    ?>
        <details class="attendance-role-schedule">
        <summary><?= e(ucwords(str_replace('_', ' ', $staffRole))) ?></summary>
        <form method="post" class="attendance-schedule-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="return_view" value="reporting-schedules"><input type="hidden" name="role_name" value="<?= e($staffRole) ?>">
            <label>Official reporting time<input type="time" name="reporting_time" value="<?= e($schedule['reporting_time']) ?>" required></label>
            <input type="hidden" name="morning_open_time" value="<?= e($schedule['morning_open_time']) ?>">
            <input type="hidden" name="morning_close_time" value="<?= e($schedule['morning_close_time']) ?>">
            <input type="hidden" name="afternoon_open_time" value="<?= e($schedule['afternoon_open_time']) ?>">
            <input type="hidden" name="afternoon_close_time" value="<?= e($schedule['afternoon_close_time']) ?>">
            <label>Grace period (minutes)<input type="number" name="grace_minutes" min="0" max="180" value="<?= (int) $schedule['grace_minutes'] ?>" required></label>
            <label>Very late after grace (minutes)<input type="number" name="very_late_after_minutes" min="1" max="360" value="<?= (int) $schedule['very_late_after_minutes'] ?>" required></label>
            <label>School closing time<input type="time" name="closing_time" value="<?= e($schedule['closing_time']) ?>" required></label>
            <label>Early departure flag threshold (minutes)<input type="number" name="early_departure_threshold_minutes" min="0" max="360" value="<?= (int) $schedule['early_departure_threshold_minutes'] ?>" required></label>
            <label>Failed PIN attempts before lockout<input type="number" name="failed_pin_attempt_limit" min="3" max="10" value="<?= (int) $schedule['failed_pin_attempt_limit'] ?>" required></label>
            <label>PIN lockout duration (minutes)<input type="number" name="lockout_minutes" min="1" max="120" value="<?= (int) $schedule['lockout_minutes'] ?>" required></label>
            <fieldset><legend>Working days</legend><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $dayLabel): ?><label class="checkbox-label"><input type="checkbox" name="working_weekdays[]" value="<?= $day ?>" <?= in_array($day, $schedule['working_weekdays'], true) ? 'checked' : '' ?>> <?= e($dayLabel) ?></label><?php endforeach; ?></fieldset>
            <label class="checkbox-label"><input type="checkbox" name="clock_out_enabled" value="1" <?= $schedule['clock_out_enabled'] ? 'checked' : '' ?>> Calculate working duration and early-departure status at check-out</label>
            <button class="button button-primary" type="submit">Save category schedule</button>
        </form>
        </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="panel" id="attendance-alerts" data-action-view="attendance-alerts" hidden>
    <h2>Attendance alerts and security events</h2>
    <?php if ($missingAttendanceAlerts !== []): ?>
        <h3>Not checked in by 2:00 PM</h3>
        <ul><?php foreach ($missingAttendanceAlerts as $alertStaff): ?><li><?= e((string) $alertStaff['full_name']) ?> · <?= e((string) ($alertStaff['employee_number'] ?: 'No Staff ID')) ?> · <?= e(ucwords(str_replace('_', ' ', (string) $alertStaff['role_name']))) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($securityEvents === []): ?>
        <?php if ($missingAttendanceAlerts === []): ?><p class="muted">No suspicious kiosk activity, device changes, or late-arrival alerts have been recorded.</p><?php endif; ?>
    <?php else: ?>
        <div class="table-scroll"><table><thead><tr><th>Time</th><th>Event</th><th>Staff</th><th>Office computer</th><th>Administrator</th><th>Details</th></tr></thead><tbody>
        <?php foreach ($securityEvents as $event): ?>
            <tr>
                <td><?= e((string) $event['occurred_at']) ?></td>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $event['event_type']))) ?></td>
                <td><?= $event['staff_name'] !== null ? e((string) $event['staff_name'] . ' · ' . ($event['employee_number'] ?: '—')) : '—' ?></td>
                <td><?= e((string) ($event['device_name'] ?? 'Unregistered / not applicable')) ?></td>
                <td><?= e((string) ($event['actor_name'] ?? 'Kiosk / system')) ?></td>
                <td><?= e((string) ($event['reason'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="panel" id="attendance-disputes" data-action-view="attendance-disputes" hidden>
    <h2>Attendance disputes</h2>
    <?php if ($disputes === []): ?><p class="muted">There are no open staff attendance disputes.</p><?php else: ?>
        <?php foreach ($disputes as $dispute): ?><article class="attendance-dispute">
            <h3><?= e((string) $dispute['full_name']) ?> · <?= e((string) ($dispute['employee_number'] ?: '—')) ?> · <?= e((string) $dispute['created_at']) ?></h3>
            <p><?= e((string) $dispute['message']) ?></p>
            <?php if (!$readOnly): ?><form method="post" class="attendance-dispute-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="review_dispute"><input type="hidden" name="return_view" value="attendance-disputes"><input type="hidden" name="dispute_id" value="<?= (int) $dispute['id'] ?>">
                <label>Status<select name="dispute_status"><option value="in_review" <?= $dispute['status'] === 'in_review' ? 'selected' : '' ?>>In review</option><option value="resolved">Resolved</option></select></label>
                <label>Response<textarea name="response" maxlength="1000" <?= $dispute['status'] === 'resolved' ? '' : '' ?>></textarea></label>
                <button class="button button-small" type="submit">Update dispute</button>
            </form><?php else: ?><p class="muted"><?= e(ucfirst((string) $dispute['status'])) ?></p><?php endif; ?>
        </article><?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel" id="pin-reset-history" data-action-view="pin-reset-history" hidden>
    <h2>Attendance PIN reset audit history</h2>
    <?php if ($pinResetHistory === []): ?><p class="muted">No PIN resets have been initiated.</p><?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Requested at</th><th>Staff member</th><th>Staff ID</th><th>Authorized by</th><th>Result</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($pinResetHistory as $resetEvent):
                $resetStatus = $resetEvent['completed_at'] !== null
                    ? 'Completed'
                    : ($resetEvent['revoked_at'] !== null
                        ? 'Revoked / attempts exceeded'
                        : (new DateTimeImmutable((string) $resetEvent['expires_at'], $attendanceTimezone) < new DateTimeImmutable('now', $attendanceTimezone)
                            ? 'Expired'
                            : 'Pending teacher setup'));
            ?>
                <tr>
                    <td><?= e((string) $resetEvent['created_at']) ?></td>
                    <td><?= e((string) $resetEvent['full_name']) ?></td>
                    <td><?= e((string) ($resetEvent['employee_number'] ?: '—')) ?></td>
                    <td><?= e((string) $resetEvent['initiator_name']) ?></td>
                    <td><?= e($resetStatus) ?></td>
                    <td><?= e((string) $resetEvent['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="panel" id="attendance-audit" data-action-view="attendance-audit" hidden>
    <h2>Recent attendance audit trail</h2>
    <?php if ($auditEvents === []): ?><p class="muted">No attendance changes have been recorded.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Changed at</th><th>Staff member</th><th>Action</th><th>Administrator</th><th>Reason</th><th>Original</th><th>Updated</th><th>Verification</th></tr></thead><tbody>
        <?php foreach ($auditEvents as $event): ?>
            <tr><td><?= e((string) $event['changed_at']) ?></td><td><?= e((string) $event['full_name']) ?> · <?= e((string) ($event['employee_number'] ?: '—')) ?></td><td><?= e(ucwords(str_replace('_', ' ', (string) $event['change_type']))) ?></td><td><?= e((string) ($event['actor_name'] ?? 'Kiosk / system')) ?></td><td><?= e((string) ($event['reason'] ?? '')) ?></td><td><code><?= e((string) ($event['original_snapshot'] ?? '—')) ?></code></td><td><code><?= e((string) $event['updated_snapshot']) ?></code></td><td><?= e($formatVerification((string) $event['verification_method'])) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
