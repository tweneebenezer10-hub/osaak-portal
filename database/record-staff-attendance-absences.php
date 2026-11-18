<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

$pdo = db();
$schools = $pdo->query('SELECT id FROM schools WHERE deleted_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$startStmt = $pdo->prepare(
    'SELECT setting_value FROM school_settings
     WHERE school_id = ? AND setting_key = "staff_attendance_register_start_date" LIMIT 1'
);
$initializeStartStmt = $pdo->prepare(
    'INSERT INTO school_settings (school_id, setting_key, setting_value)
     VALUES (?, "staff_attendance_register_start_date", ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
);
$roleStmt = $pdo->prepare(
    'SELECT DISTINCT staff.role_name
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id
       AND account.status = "active" AND account.email_verified_at IS NOT NULL
     WHERE staff.school_id = ? AND staff.status = "active"
       AND staff.role_name NOT IN ("school_parent", "school_student")
       AND EXISTS (
           SELECT 1 FROM school_memberships membership
           WHERE membership.school_id = staff.school_id
             AND membership.user_id = staff.user_id
             AND membership.status = "active"
             AND membership.role_name NOT IN ("school_parent", "school_student")
       )
     ORDER BY staff.role_name'
);
$staffStmt = $pdo->prepare(
    'SELECT staff.id, DATE(staff.created_at) AS active_since
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id
       AND account.status = "active" AND account.email_verified_at IS NOT NULL
     WHERE staff.school_id = ? AND staff.status = "active"
       AND staff.role_name = ?
       AND EXISTS (
           SELECT 1 FROM school_memberships membership
           WHERE membership.school_id = staff.school_id
             AND membership.user_id = staff.user_id
             AND membership.status = "active"
             AND membership.role_name NOT IN ("school_parent", "school_student")
       )
     ORDER BY staff.id'
);
$firstStaffStmt = $pdo->prepare(
    'SELECT MIN(DATE(staff.created_at))
       FROM school_staff staff
       WHERE staff.school_id = ? AND staff.status = "active"
         AND staff.role_name NOT IN ("school_parent", "school_student")
         AND EXISTS (
             SELECT 1 FROM school_memberships membership
             WHERE membership.school_id = staff.school_id
               AND membership.user_id = staff.user_id
               AND membership.status = "active"
               AND membership.role_name NOT IN ("school_parent", "school_student")
         )'
);
$insertStmt = $pdo->prepare(
    'INSERT INTO school_staff_attendance
        (school_id, staff_id, attendance_date, expected_reporting_time, status,
         punctuality_status, minutes_late, verification_method, remarks, recorded_by, recorded_at)
     VALUES (?, ?, ?, ?, "absent", NULL, 0, "system_generated",
             "Automatically recorded after the scheduled working day ended.", NULL, ?)
     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
);
$recordStmt = $pdo->prepare(
    'SELECT * FROM school_staff_attendance
     WHERE school_id = ? AND staff_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE'
);
$resolvePendingStmt = $pdo->prepare(
    'UPDATE school_staff_attendance
     SET expected_reporting_time = ?, status = "absent", punctuality_status = NULL,
         minutes_late = 0, verification_method = "system_generated",
         remarks = "Automatically recorded after the scheduled working day ended.",
         recorded_by = NULL, recorded_at = ?
     WHERE id = ? AND school_id = ? AND staff_id = ? AND attendance_date = ?
       AND status = "pending" AND arrival_at IS NULL'
);

$recorded = 0;
foreach ($schools as $schoolValue) {
    $schoolId = (int) $schoolValue;
    $timezone = school_staff_attendance_timezone($pdo, $schoolId);
    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $startStmt->execute([$schoolId]);
    $registerStart = $startStmt->fetchColumn();
    if ($registerStart === false) {
        $firstStaffStmt->execute([$schoolId]);
        $registerStart = $firstStaffStmt->fetchColumn() ?: $today;
        $initializeStartStmt->execute([$schoolId, $registerStart]);
    }
    $parsedRegisterStart = is_string($registerStart)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $registerStart, $timezone)
        : false;
    if (!is_string($registerStart)
        || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $registerStart) !== 1
        || !$parsedRegisterStart
        || $parsedRegisterStart->format('Y-m-d') !== $registerStart) {
        throw new RuntimeException('Invalid staff attendance register start date for school ' . $schoolId . '.');
    }
    if ($registerStart > $today) {
        $registerStart = $today;
        $initializeStartStmt->execute([$schoolId, $registerStart]);
    }

    $roleStmt->execute([$schoolId]);
    $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
    $start = new DateTimeImmutable($registerStart, $timezone);
    $end = new DateTimeImmutable($today, $timezone);
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $attendanceDate = $date->format('Y-m-d');
        foreach ($roles as $roleValue) {
            $roleName = (string) $roleValue;
            $schedule = school_staff_attendance_schedule($pdo, $schoolId, $roleName);
            if ($attendanceDate === $today
                && $now->format('H:i') < school_staff_attendance_absence_cutoff($schedule['closing_time'])) {
                continue;
            }
            if (!school_staff_attendance_is_working_day($pdo, $schoolId, $attendanceDate, $schedule)) {
                continue;
            }

            $staffStmt->execute([$schoolId, $roleName]);
            $pdo->beginTransaction();
            try {
                foreach ($staffStmt->fetchAll() as $staffRecord) {
                    if ($attendanceDate < (string) $staffRecord['active_since']) {
                        continue;
                    }
                    $staffId = (int) $staffRecord['id'];
                    $recordedAt = $now->format('Y-m-d H:i:s');
                    $insertStmt->execute([
                        $schoolId,
                        $staffId,
                        $attendanceDate,
                        $schedule['reporting_time'],
                        $recordedAt,
                    ]);
                    $wasInserted = $insertStmt->rowCount() === 1;
                    $attendanceId = (int) $pdo->lastInsertId();
                    $recordStmt->execute([$schoolId, $staffId, $attendanceDate]);
                    $record = $recordStmt->fetch();
                    if (!$record) {
                        throw new RuntimeException('Staff absence could not be reloaded for audit.');
                    }
                    $attendanceId = (int) $record['id'];
                    $original = null;
                    if (!$wasInserted) {
                        if ((string) $record['status'] !== 'pending' || $record['arrival_at'] !== null) {
                            continue;
                        }
                        $original = school_staff_attendance_snapshot($record);
                        $resolvePendingStmt->execute([
                            $schedule['reporting_time'],
                            $recordedAt,
                            (int) $record['id'],
                            $schoolId,
                            $staffId,
                            $attendanceDate,
                        ]);
                        if ($resolvePendingStmt->rowCount() !== 1) {
                            continue;
                        }
                        $recordStmt->execute([$schoolId, $staffId, $attendanceDate]);
                        $record = $recordStmt->fetch();
                        if (!$record) {
                            throw new RuntimeException('Resolved staff absence could not be reloaded for audit.');
                        }
                    }
                    school_staff_attendance_write_audit(
                        $pdo,
                        $schoolId,
                        $attendanceId,
                        $staffId,
                        null,
                        'system_absence_recorded',
                        $original,
                        school_staff_attendance_snapshot($record),
                        'No attendance was recorded by the scheduled closing time.',
                        'system_generated'
                    );
                    $recorded++;
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }
}

fwrite(STDOUT, 'System-generated staff absence records created: ' . $recorded . PHP_EOL);
