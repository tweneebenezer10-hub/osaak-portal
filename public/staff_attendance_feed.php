<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_attendance.php';
require_once APP_ROOT . '/app/school_staff_attendance.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) ($school['id'] ?? 0);
if ($schoolId < 1 || !school_staff_attendance_can_manage($user, $schoolId)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Attendance feed access denied.']);
    exit;
}

$attendanceTimezone = school_staff_attendance_timezone(db(), $schoolId);
$dateInput = (string) ($_GET['date'] ?? (new DateTimeImmutable('now', $attendanceTimezone))->format('Y-m-d'));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput, $attendanceTimezone);
if (!$date || $date->format('Y-m-d') !== $dateInput) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid attendance date.']);
    exit;
}
$staffId = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT);
$staffId = $staffId !== false && $staffId > 0 ? (int) $staffId : 0;
$staffGroup = trim((string) ($_GET['staff_group'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$allowedStatuses = ['not_checked_in', 'early', 'punctual', 'late', 'very_late', 'absent', 'excused', 'checked_out'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$query = db()->prepare(
    'SELECT staff.id, staff.employee_number, staff.role_name, account.full_name,
            attendance.id AS attendance_id, attendance.arrival_at, attendance.departure_at,
            attendance.status, attendance.punctuality_status, attendance.minutes_late,
            attendance.verification_method, attendance.early_departure
     FROM school_staff staff
     JOIN users account ON account.id = staff.user_id AND account.status = "active"
       AND account.email_verified_at IS NOT NULL
     LEFT JOIN school_staff_attendance attendance
       ON attendance.school_id = staff.school_id AND attendance.staff_id = staff.id
       AND attendance.attendance_date = ?
     WHERE staff.school_id = ? AND staff.status = "active"
       AND staff.role_name NOT IN ("school_parent", "school_student")
       AND EXISTS (
           SELECT 1 FROM school_memberships membership
           WHERE membership.school_id = staff.school_id
             AND membership.user_id = staff.user_id
             AND membership.status = "active"
       )
     ORDER BY account.full_name'
);
$query->execute([$dateInput, $schoolId]);
$rows = [];
$counts = ['early' => 0, 'punctual' => 0, 'late' => 0, 'very_late' => 0, 'absent' => 0, 'excused' => 0, 'not_checked_in' => 0];
foreach ($query->fetchAll() as $row) {
    $schedule = school_staff_attendance_schedule(db(), $schoolId, (string) $row['role_name']);
    $currentStatus = $row['attendance_id'] === null
        ? (school_staff_attendance_is_working_day(db(), $schoolId, $dateInput, $schedule) ? 'not_checked_in' : 'not_scheduled')
        : (string) ($row['punctuality_status'] ?: $row['status']);
    if (isset($counts[$currentStatus])) {
        $counts[$currentStatus]++;
    }
    if ($staffId > 0 && (int) $row['id'] !== $staffId) {
        continue;
    }
    if ($staffGroup !== '' && (string) $row['role_name'] !== $staffGroup) {
        continue;
    }
    if ($statusFilter === 'checked_out' && empty($row['departure_at'])) {
        continue;
    }
    if ($statusFilter !== '' && $statusFilter !== 'checked_out' && $currentStatus !== $statusFilter) {
        continue;
    }
    $rows[] = [
        'id' => (int) $row['id'],
        'attendance_id' => $row['attendance_id'] === null ? null : (int) $row['attendance_id'],
        'status' => $currentStatus,
        'arrival_at' => $row['arrival_at'],
        'departure_at' => $row['departure_at'],
        'minutes_late' => (int) ($row['minutes_late'] ?? 0),
        'verification_method' => $row['verification_method'],
        'early_departure' => (bool) ($row['early_departure'] ?? false),
    ];
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['rows' => $rows, 'summary' => $counts], JSON_THROW_ON_ERROR);
